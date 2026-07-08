<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Webhook\TalkHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that processes queued LLM requests.
 * 
 * When rate limits are hit, requests are queued instead of failing.
 * This job runs every minute to process pending requests as
 * rate limit capacity becomes available.
 */
class ProcessQueuedRequestsJob extends TimedJob {
    private const GENERIC_FAILURE_NOTIFICATION = 'Your request could not be processed after multiple attempts. Please try again later.';

    /**
     * Maximum requests to process per job run
     */
    private const MAX_REQUESTS_PER_RUN = 10;

    /**
     * Maximum age of a queued request before it's considered stale (1 hour)
     */
    private const MAX_REQUEST_AGE_SECONDS = 3600;

    /**
     * Maximum retry attempts for failed requests
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    private RateLimitService $rateLimitService;
    private BotService $botService;
    private BotMapper $botMapper;
    private TalkHandler $talkHandler;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        RateLimitService $rateLimitService,
        BotService $botService,
        BotMapper $botMapper,
        TalkHandler $talkHandler,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->rateLimitService = $rateLimitService;
        $this->botService = $botService;
        $this->botMapper = $botMapper;
        $this->talkHandler = $talkHandler;
        $this->logger = $logger;

        // Run every minute (60 seconds)
        $this->setInterval(60);
        
        // Allow some flexibility in timing
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    protected function run($arguments): void {
        // Check if rate limiting is enabled
        if (!$this->rateLimitService->isEnabled()) {
            $this->logger->debug('EducAI: Rate limiting disabled, skipping queue processing');
            return;
        }

        // Get queue stats
        $stats = $this->rateLimitService->getQueueStats();
        
        if ($stats['pending'] === 0 && $stats['processing'] === 0) {
            $this->logger->debug('EducAI: No queued requests to process');
            return;
        }

        $this->logger->info('EducAI: Starting queue processing job', [
            'pending' => $stats['pending'],
            'processing' => $stats['processing'],
        ]);

        // First, handle stale processing requests (reset them)
        $this->rateLimitService->cleanup(self::MAX_REQUEST_AGE_SECONDS);

        // Process pending requests
        $processedCount = 0;
        $maxToProcess = min(self::MAX_REQUESTS_PER_RUN, $stats['pending']);

        for ($i = 0; $i < $maxToProcess; $i++) {
            // Check if we have rate limit capacity
            if (!$this->rateLimitService->canProcess()) {
                $waitSeconds = $this->rateLimitService->getSecondsUntilAvailable();
                $this->logger->info('EducAI: Rate limit reached, stopping queue processing', [
                    'processed' => $processedCount,
                    'wait_seconds' => $waitSeconds,
                ]);
                break;
            }

            // Get next pending request
            $request = $this->rateLimitService->getNextPending();
            if ($request === null) {
                break;
            }

            // Skip stale requests
            if ($request->isStale(self::MAX_REQUEST_AGE_SECONDS)) {
                $this->rateLimitService->markFailed($request, 'Request expired (too old)');
                $this->sendFailureNotification($request, 'Your request has expired. Please try again.');
                continue;
            }

            // Process the request
            $this->processQueuedRequest($request);
            $processedCount++;

            // Small delay between requests to respect rate limits
            usleep(100000); // 100ms
        }

        $this->logger->info('EducAI: Queue processing completed', [
            'processed_count' => $processedCount,
            'remaining_pending' => $stats['pending'] - $processedCount,
        ]);
    }

    /**
     * Process a single queued request
     */
    private function processQueuedRequest(QueuedRequest $request): void {
        $requestId = $request->getId();
        
        $this->logger->info('EducAI: Processing queued request', [
            'request_id' => $requestId,
            'bot_id' => $request->getBotId(),
            'attempts' => $request->getAttempts(),
        ]);

        // Mark as processing
        $this->rateLimitService->markProcessing($request);

        // Record rate limit usage
        $this->rateLimitService->recordUsage();

        try {
            // Get the bot
            $bot = $this->botMapper->findById($request->getBotId());
            
            if (!$bot->getIsActive()) {
                throw new \Exception('Bot is no longer active');
            }

            // Process the message (with isFromQueue=true to skip rate limit check)
            $response = $this->botService->processMessage(
                $bot,
                $request->getMessage(),
                $request->getRoomToken(),
                $request->getUserId(),
                $request->getOriginalMessage(),
                null, // No streaming for queued requests
                true, // isFromQueue = true
                null,
                null,
                $request->getThreadRootMessageId(),
                $request->getReplyToMessageId()
            );

            // Mark as completed
            $this->rateLimitService->markCompleted($request, $response);

            // Send the response to Talk
            $this->talkHandler->sendReplyToTalk(
                $request->getRoomToken(),
                $response,
                $request->getReplyToMessageId() ?? 0
            );

            $this->logger->info('EducAI: Successfully processed queued request', [
                'request_id' => $requestId,
                'response_length' => strlen($response),
            ]);

        } catch (DoesNotExistException $e) {
            $error = 'Bot no longer exists';
            $this->rateLimitService->markFailed($request, $error);
            $this->sendFailureNotification($request, 'The bot is no longer available.');
            
            $this->logger->warning('EducAI: Queued request failed - bot not found', [
                'request_id' => $requestId,
                'bot_id' => $request->getBotId(),
            ]);

        } catch (\Exception $e) {
            $error = $e->getMessage();
            
            // Check if we should retry (note: attempts already incremented in markProcessing)
            if ($request->getAttempts() < self::MAX_RETRY_ATTEMPTS) {
                // Reset to pending so it will be picked up again
                $this->rateLimitService->markForRetry($request, $error);
                
                $this->logger->warning('EducAI: Queued request failed, will retry', [
                    'request_id' => $requestId,
                    'attempts' => $request->getAttempts(),
                    'max_attempts' => self::MAX_RETRY_ATTEMPTS,
                    'error' => $error,
                ]);
            } else {
                // Max retries exceeded
                $this->rateLimitService->markFailed($request, 'Max retries exceeded: ' . $error);
                $this->sendFailureNotification(
                    $request,
                    self::GENERIC_FAILURE_NOTIFICATION
                );
                
                $this->logger->error('EducAI: Queued request permanently failed', [
                    'request_id' => $requestId,
                    'attempts' => $request->getAttempts(),
                    'error' => $error,
                ]);
            }
        }
    }

    /**
     * Send a failure notification to the Talk room
     */
    private function sendFailureNotification(QueuedRequest $request, string $message): void {
        try {
            $this->talkHandler->sendReplyToTalk(
                $request->getRoomToken(),
                '⚠️ ' . $message,
                $request->getReplyToMessageId() ?? 0
            );
        } catch (\Exception $e) {
            $this->logger->error('EducAI: Failed to send failure notification', [
                'request_id' => $request->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
