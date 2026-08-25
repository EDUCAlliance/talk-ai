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
        $this->rateLimitService->failExhaustedPendingRequests();
        $this->rateLimitService->recoverStaleResponseDeliveries();
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

            // Process the request
            if ($this->processQueuedRequest($request)) {
                $processedCount++;
                $pendingProcessedCount++;
            }

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
    private function processQueuedRequest(QueuedRequest $request): bool {
        $requestId = $request->getId();
        
        $this->logger->info('EducAI: Processing queued request', [
            'request_id' => $requestId,
            'bot_id' => $request->getBotId(),
            'attempts' => $request->getAttempts(),
        ]);

        // Claim before consuming rate-limit capacity or invoking the agent. Both the
        // background job and the manual admin endpoint can observe the same pending
        // row, but only the winner of this conditional transition may execute it.
        try {
            $this->rateLimitService->markProcessing($request);
        } catch (\LogicException $e) {
            if (!$this->isProcessingClaimLost($e)) {
                throw $e;
            }
            $this->logger->debug('EducAI: Skipping queued request claimed by another worker', [
                'request_id' => $requestId,
            ]);
            return false;
        }

        if ($request->isStale(self::MAX_REQUEST_AGE_SECONDS)) {
            if ($this->failProcessingIfOwned($request, 'Request expired (too old)')) {
                $this->sendFailureNotification($request, 'Your request has expired. Please try again.');
            }
            return true;
        }

        // Record rate limit usage
        $this->rateLimitService->recordUsage();
        $traceRunId = null;
        $traceStatus = null;
        $traceErrorSummary = null;
        $agentExecutionStarted = false;
        $executionFailed = false;
        $terminalNotification = null;
        $deliverySucceeded = false;
        $agentExecutionCompleted = false;
        $responsePersisted = false;

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
            $responsePersisted = true;
            $request = $this->rateLimitService->markResponseDeliveryAttempt($request);
            $deliveryOutcome = $this->talkHandler->sendReplyToTalkWithOutcome(
                $request->getRoomToken(),
                $response,
                $request->getReplyToMessageId() ?? 0,
                $request->getDeliveryReferenceId()
            );
            if ($deliveryOutcome['status'] !== TalkHandler::DELIVERY_SUCCESS) {
                $traceStatus = 'partial';
                $traceErrorSummary = $this->persistDeliveryFailure($request, $deliveryOutcome, $traceRunId);
                return true;
            }
            $deliverySucceeded = true;

            // A persistence failure after Talk accepted the response stays in the
            // delivery-only lane: the agent/provider/tools are never re-run, but lease
            // recovery may resend the stored response up to the bounded attempt budget.
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
                return true;
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
            if (!$this->failProcessingIfOwned($request, $error)) {
                return true;
            }
            $this->sendFailureNotification($request, 'The bot is no longer available.');
            
            $this->logger->warning('EducAI: Queued request failed - bot not found', [
                'request_id' => $requestId,
                'bot_id' => $request->getBotId(),
            ]);

        } catch (\Exception $e) {
            $error = $e->getMessage();
            if ($this->isProcessingClaimLost($e)) {
                $traceStatus = 'partial';
                $traceErrorSummary = 'Queued processing ownership was lost; the current owner was left unchanged';
                $this->logger->warning('EducAI: Queued processing ownership was lost', [
                    'request_id' => $requestId,
                ]);
                return true;
            }
            if ($responsePersisted && $this->isResponseDeliveryClaimLost($e)) {
                $traceStatus = 'partial';
                $traceErrorSummary = 'Queued response delivery ownership transferred to another worker';
                $this->logger->debug('EducAI: Queued response delivery ownership transferred', [
                    'request_id' => $requestId,
                ]);
                return true;
            }
            if ($deliverySucceeded) {
                if ($traceStatus !== 'partial') {
                    $traceStatus = 'partial';
                    $traceErrorSummary = 'Queued response was delivered, but post-delivery bookkeeping failed: ' . $error;
                }
                $this->logger->error('EducAI: Post-delivery queue bookkeeping failed', [
                    'request_id' => $requestId,
                    'exception' => $error,
                ]);
                return true;
            }
            if ($responsePersisted) {
                $traceStatus = 'partial';
                $traceErrorSummary = 'Queued response persisted, but delivery reconciliation was deferred: ' . $error;
                $this->traceService->recordEvent($traceRunId, 'queue_delivery', [
                    'status' => 'reconciliation_deferred',
                    'error_message' => $traceErrorSummary,
                ]);
                $this->logger->error('EducAI: Queued delivery reconciliation deferred after response persistence', [
                    'request_id' => $requestId,
                    'exception' => $error,
                ]);
                return true;
            }
            if ($agentExecutionCompleted) {
                $traceStatus = 'partial';
                $traceErrorSummary = 'Queued response persistence failed: ' . $error;
                if (!$this->failProcessingIfOwned($request, $traceErrorSummary)) {
                    return true;
                }
                $this->traceService->recordEvent($traceRunId, 'queue_persistence', [
                    'status' => 'error',
                    'error_message' => $traceErrorSummary,
                ]);
                $this->sendFailureNotification(
                    $request,
                    'The AI response was generated but could not be queued for delivery. Please try again.'
                );
                return true;
            }
            if ($traceStatus !== 'partial') {
                $traceStatus = 'error';
            }
            $traceErrorSummary = $error;

            if ($agentExecutionStarted) {
                if (!$this->failProcessingIfOwned($request, 'Agent execution failed: ' . $error)) {
                    return true;
                }
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
                if (!$this->retryProcessingIfOwned($request, $error)) {
                    return true;
                }
                
                $this->logger->warning('EducAI: Queued request failed, will retry', [
                    'request_id' => $requestId,
                    'attempts' => $request->getAttempts(),
                    'max_attempts' => self::MAX_RETRY_ATTEMPTS,
                    'error' => $error,
                ]);
            } else {
                // Max retries exceeded
                if (!$this->failProcessingIfOwned($request, 'Max retries exceeded: ' . $error)) {
                    return true;
                }
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

        return true;
    }

    private function processResponseReadyRequest(QueuedRequest $request): bool {
        $traceRunId = $this->startDeliveryTrace($request);
        $traceStatus = 'partial';
        $traceErrorSummary = null;
        $result = $request->getResult();
        $referenceId = $request->getDeliveryReferenceId();

        try {
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

            if ($result === null || trim($result) === '') {
                $traceStatus = 'error';
                $traceErrorSummary = 'Stored queued response is empty';
                $this->rateLimitService->markResponseDeliveryFailed($request, $traceErrorSummary);
                return false;
            }

            $this->traceService->recordEvent($traceRunId, 'queue_delivery', [
                'status' => 'started',
                'payload' => ['reference_id' => $referenceId],
            ]);
            $deliveryOutcome = $this->talkHandler->sendReplyToTalkWithOutcome(
                $request->getRoomToken(),
                $result,
                $request->getReplyToMessageId() ?? 0,
                $referenceId
            );
            if ($deliveryOutcome['status'] !== TalkHandler::DELIVERY_SUCCESS) {
                $traceErrorSummary = $this->persistDeliveryFailure($request, $deliveryOutcome, $traceRunId);
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
            if ($this->isResponseDeliveryClaimLost($e)) {
                $traceErrorSummary = 'Queued response delivery ownership transferred to another worker';
                $this->logger->debug('EducAI: Queued response delivery ownership transferred', [
                    'request_id' => $request->getId(),
                ]);
                return false;
            }
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

    /**
     * @param array{status: string, error: ?string, http_status: ?int} $outcome
     */
    private function persistDeliveryFailure(QueuedRequest $request, array $outcome, ?int $traceRunId): string {
        $error = $outcome['error'] ?? 'Talk delivery failed without an error detail';
        $retryable = $outcome['status'] === TalkHandler::DELIVERY_RETRYABLE
            || $outcome['status'] === TalkHandler::DELIVERY_AMBIGUOUS;
        $stored = $retryable
            ? $this->rateLimitService->markResponseDeliveryRetry($request, $error)
            : $this->rateLimitService->markResponseDeliveryFailed($request, $error);
        $retryScheduled = $stored->getStatus() === QueuedRequest::STATUS_RESPONSE_READY;

        $this->traceService->recordEvent($traceRunId, 'queue_delivery', [
            'status' => $retryScheduled ? 'retry_scheduled' : 'error',
            'error_message' => $stored->getError() ?? $error,
            'payload' => [
                'delivery_outcome' => $outcome['status'],
                'http_status' => $outcome['http_status'],
                'attempts' => $stored->getAttempts(),
            ],
        ]);

        return $stored->getError() ?? $error;
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

    private function failProcessingIfOwned(QueuedRequest $request, string $error): bool {
        try {
            $this->rateLimitService->markProcessingFailed($request, $error);
            return true;
        } catch (\LogicException $e) {
            if (!$this->isProcessingClaimLost($e)) {
                throw $e;
            }
            $this->logger->warning('EducAI: Ignoring stale processing failure from a former queue owner', [
                'request_id' => $request->getId(),
            ]);
            return false;
        }
    }

    private function retryProcessingIfOwned(QueuedRequest $request, string $error): bool {
        try {
            $this->rateLimitService->markProcessingRetry($request, $error);
            return true;
        } catch (\LogicException $e) {
            if (!$this->isProcessingClaimLost($e)) {
                throw $e;
            }
            $this->logger->warning('EducAI: Ignoring stale processing retry from a former queue owner', [
                'request_id' => $request->getId(),
            ]);
            return false;
        }
    }

    private function isProcessingClaimLost(\Throwable $e): bool {
        return $e instanceof \LogicException
            && $e->getMessage() === RateLimitService::PROCESSING_CLAIM_LOST_MESSAGE;
    }

    private function isResponseDeliveryClaimLost(\Throwable $e): bool {
        return $e instanceof \LogicException
            && $e->getMessage() === RateLimitService::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE;
    }
}
