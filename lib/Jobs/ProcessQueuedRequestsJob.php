<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\TraceService;
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
    private const MAX_RETRY_ATTEMPTS = QueuedRequest::MAX_ATTEMPTS;

    private RateLimitService $rateLimitService;
    private BotService $botService;
    private BotMapper $botMapper;
    private TalkHandler $talkHandler;
    private LoggerInterface $logger;
    private TraceService $traceService;

    public function __construct(
        ITimeFactory $time,
        RateLimitService $rateLimitService,
        BotService $botService,
        BotMapper $botMapper,
        TalkHandler $talkHandler,
        LoggerInterface $logger,
        TraceService $traceService
    ) {
        parent::__construct($time);
        $this->rateLimitService = $rateLimitService;
        $this->botService = $botService;
        $this->botMapper = $botMapper;
        $this->talkHandler = $talkHandler;
        $this->logger = $logger;
        $this->traceService = $traceService;

        // Run every minute (60 seconds)
        $this->setInterval(60);
        
        // Allow some flexibility in timing
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    protected function run($arguments): void {
        $processedCount = 0;
        $readyRequests = $this->rateLimitService->getResponseReadyRequests(self::MAX_REQUESTS_PER_RUN);
        foreach ($readyRequests as $request) {
            if ($this->processResponseReadyRequest($request)) {
                $processedCount++;
            }
        }

        $remainingLimit = self::MAX_REQUESTS_PER_RUN - count($readyRequests);
        if (!$this->rateLimitService->isEnabled()) {
            $this->logger->debug('EducAI: Rate limiting disabled, skipping pending queue processing', [
                'delivery_only_processed' => $processedCount,
            ]);
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
        $maxToProcess = min($remainingLimit, $stats['pending']);
        $pendingProcessedCount = 0;

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
            $pendingProcessedCount++;

            // Small delay between requests to respect rate limits
            usleep(100000); // 100ms
        }

        $this->logger->info('EducAI: Queue processing completed', [
            'processed_count' => $processedCount,
            'remaining_pending' => $stats['pending'] - $pendingProcessedCount,
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
        $traceRunId = null;
        $traceStatus = null;
        $traceErrorSummary = null;
        $agentExecutionStarted = false;
        $executionFailed = false;
        $terminalNotification = null;
        $deliveryFailed = false;
        $deliverySucceeded = false;
        $agentExecutionCompleted = false;

        try {
            // Get the bot
            $bot = $this->botMapper->findById($request->getBotId());

            if (!$bot->getIsActive()) {
                throw new \Exception('Bot is no longer active');
            }

            // Process the message (with isFromQueue=true to skip rate limit check)
            $traceRunId = $this->startQueuedTrace($request, $bot);
            $executionError = null;
            $agentExecutionStarted = true;
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
                $request->getReplyToMessageId(),
                traceRunId: $traceRunId,
                onExecutionError: static function (?string $errorSummary = null) use (&$executionFailed, &$executionError): void {
                    $executionFailed = true;
                    $executionError = $errorSummary;
                }
            );
            if ($executionFailed) {
                $terminalNotification = $response;
                throw new \Exception($executionError ?? 'Agent execution failed');
            }
            $agentExecutionCompleted = true;

            $this->rateLimitService->markResponseReady($request, $response);
            $request = $this->rateLimitService->markResponseDeliveryAttempt($request);
            // Send the response to Talk
            $deliveryFailed = true;
            $delivered = $this->talkHandler->sendReplyToTalk(
                $request->getRoomToken(),
                $response,
                $request->getReplyToMessageId() ?? 0,
                $request->getDeliveryReferenceId()
            );
            if (!$delivered) {
                $traceStatus = 'partial';
                throw new \Exception('Failed to deliver queued response to Talk');
            }
            $deliveryFailed = false;
            $deliverySucceeded = true;

            // Mark as completed. A failure here happens after the user already received
            // the response, so it must never trigger another provider run or Talk reply.
            try {
                $this->rateLimitService->markCompleted($request, $response);
            } catch (\Throwable $e) {
                $traceStatus = 'partial';
                $traceErrorSummary = 'Queued response delivered, but completion persistence failed: ' . $e->getMessage();
                $this->traceService->recordEvent($traceRunId, 'queue_persistence', [
                    'status' => 'error',
                    'error_message' => $traceErrorSummary,
                ]);
                $this->logger->error('EducAI: Queued response delivered, but completion persistence failed', [
                    'request_id' => $requestId,
                    'exception' => $e->getMessage(),
                ]);
                return;
            }

            $this->logger->info('EducAI: Successfully processed queued request', [
                'request_id' => $requestId,
                'response_length' => strlen($response),
            ]);
            $traceStatus = 'success';

        } catch (DoesNotExistException $e) {
            $error = 'Bot no longer exists';
            $traceStatus = 'error';
            $traceErrorSummary = $error;
            $this->rateLimitService->markFailed($request, $error);
            $this->sendFailureNotification($request, 'The bot is no longer available.');
            
            $this->logger->warning('EducAI: Queued request failed - bot not found', [
                'request_id' => $requestId,
                'bot_id' => $request->getBotId(),
            ]);

        } catch (\Exception $e) {
            $error = $e->getMessage();
            if ($deliverySucceeded) {
                if ($traceStatus !== 'partial') {
                    $traceStatus = 'partial';
                    $traceErrorSummary = 'Queued response was delivered, but post-delivery bookkeeping failed: ' . $error;
                }
                $this->logger->error('EducAI: Post-delivery queue bookkeeping failed', [
                    'request_id' => $requestId,
                    'exception' => $error,
                ]);
                return;
            }
            if ($deliveryFailed) {
                $traceStatus = 'partial';
                $traceErrorSummary = $error;
                $this->rateLimitService->markResponseDeliveryFailed($request, 'Delivery failed: ' . $error);
                return;
            }
            if ($agentExecutionCompleted) {
                $traceStatus = 'partial';
                $traceErrorSummary = 'Queued response persistence failed: ' . $error;
                if ($request->getStatus() === QueuedRequest::STATUS_RESPONSE_READY) {
                    $this->rateLimitService->markResponseDeliveryFailed($request, $traceErrorSummary);
                } else {
                    $this->rateLimitService->markFailed($request, $traceErrorSummary);
                }
                $this->traceService->recordEvent($traceRunId, 'queue_persistence', [
                    'status' => 'error',
                    'error_message' => $traceErrorSummary,
                ]);
                $this->sendFailureNotification(
                    $request,
                    'The AI response was generated but could not be queued for delivery. Please try again.'
                );
                return;
            }
            if ($traceStatus !== 'partial') {
                $traceStatus = 'error';
            }
            $traceErrorSummary = $error;

            if ($agentExecutionStarted) {
                $this->rateLimitService->markFailed($request, 'Agent execution failed: ' . $error);
                if ($terminalNotification !== null) {
                    $this->talkHandler->sendReplyToTalk(
                        $request->getRoomToken(),
                        $terminalNotification,
                        $request->getReplyToMessageId() ?? 0
                    );
                } else {
                    $this->sendFailureNotification($request, self::GENERIC_FAILURE_NOTIFICATION);
                }
            } elseif ($request->getAttempts() < self::MAX_RETRY_ATTEMPTS) {
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
        } finally {
            if ($traceRunId !== null && $traceStatus !== null) {
                $this->traceService->finishRun($traceRunId, $traceStatus, $traceErrorSummary);
            }
        }
    }

    private function processResponseReadyRequest(QueuedRequest $request): bool {
        $traceRunId = $this->startDeliveryTrace($request);
        $traceStatus = 'partial';
        $traceErrorSummary = null;
        $result = $request->getResult();
        $referenceId = $request->getDeliveryReferenceId();

        try {
            if ($result === null || trim($result) === '') {
                $traceStatus = 'error';
                $traceErrorSummary = 'Stored queued response is empty';
                $this->rateLimitService->markResponseDeliveryFailed($request, $traceErrorSummary);
                return false;
            }

            if ($request->getAttempts() >= QueuedRequest::MAX_ATTEMPTS) {
                $traceErrorSummary = 'Queued response delivery attempts exhausted';
                $this->rateLimitService->markResponseDeliveryFailed($request, $traceErrorSummary);
                $this->traceService->recordEvent($traceRunId, 'queue_delivery', [
                    'status' => 'error',
                    'error_message' => $traceErrorSummary,
                ]);
                return false;
            }

            $request = $this->rateLimitService->markResponseDeliveryAttempt($request);

            $this->traceService->recordEvent($traceRunId, 'queue_delivery', [
                'status' => 'started',
                'payload' => ['reference_id' => $referenceId],
            ]);
            $delivered = $this->talkHandler->sendReplyToTalk(
                $request->getRoomToken(),
                $result,
                $request->getReplyToMessageId() ?? 0,
                $referenceId
            );
            if (!$delivered) {
                $traceErrorSummary = 'Failed to reconcile stored queued response delivery';
                $this->rateLimitService->markResponseDeliveryFailed($request, $traceErrorSummary);
                $this->traceService->recordEvent($traceRunId, 'queue_delivery', [
                    'status' => 'error',
                    'error_message' => $traceErrorSummary,
                ]);
                return false;
            }

            try {
                $this->rateLimitService->markCompleted($request, $result);
            } catch (\Throwable $e) {
                $traceErrorSummary = 'Queued response delivered, but completion persistence failed: ' . $e->getMessage();
                $this->traceService->recordEvent($traceRunId, 'queue_persistence', [
                    'status' => 'error',
                    'error_message' => $traceErrorSummary,
                ]);
                return false;
            }

            $traceStatus = 'success';
            $this->traceService->recordEvent($traceRunId, 'queue_delivery', [
                'status' => 'success',
                'payload' => ['reference_id' => $referenceId],
            ]);
            return true;
        } catch (\Throwable $e) {
            $traceErrorSummary ??= 'Queued response delivery reconciliation failed: ' . $e->getMessage();
            $this->logger->error('EducAI: Queued response delivery reconciliation failed', [
                'request_id' => $request->getId(),
                'exception' => $e->getMessage(),
            ]);
            return false;
        } finally {
            $this->traceService->finishRun($traceRunId, $traceStatus, $traceErrorSummary);
        }
    }

    private function startQueuedTrace(QueuedRequest $request, Bot $bot): ?int {
        return $this->traceService->startRun([
            'user_id' => $request->getUserId(),
            'bot_id' => $request->getBotId(),
            'bot_mention_name' => $bot->getMentionName(),
            'room_token' => $request->getRoomToken(),
            'talk_message_id' => $request->getReplyToMessageId(),
            'reply_target_message_id' => $request->getReplyToMessageId(),
            'thread_root_message_id' => $request->getThreadRootMessageId(),
            'source' => 'queue',
            'user_message' => $request->getOriginalMessage() ?? $request->getMessage(),
        ]);
    }

    private function startDeliveryTrace(QueuedRequest $request): ?int {
        return $this->traceService->startRun([
            'user_id' => $request->getUserId(),
            'bot_id' => $request->getBotId(),
            'room_token' => $request->getRoomToken(),
            'talk_message_id' => $request->getReplyToMessageId(),
            'reply_target_message_id' => $request->getReplyToMessageId(),
            'thread_root_message_id' => $request->getThreadRootMessageId(),
            'source' => 'queue_delivery',
            'user_message' => $request->getOriginalMessage() ?? $request->getMessage(),
        ]);
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
