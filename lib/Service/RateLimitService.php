<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Db\QueuedRequestMapper;
use OCA\EducAI\Db\RateLimitState;
use OCA\EducAI\Db\RateLimitStateMapper;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Service for managing LLM API rate limits and request queuing.
 */
class RateLimitService {
    public const ENDPOINT_CHAT = 'chat_completions';
    public const ENDPOINT_EMBEDDINGS = 'embeddings';
    public const RESPONSE_DELIVERY_LEASE_SECONDS = 120;
    /** Five minutes for queue setup, context preparation, and response persistence. */
    public const PROCESSING_LEASE_MARGIN_SECONDS = 300;
    public const PROCESSING_LEASE_SECONDS = AgentExecutor::HARD_MAX_WALL_CLOCK_SECONDS + self::PROCESSING_LEASE_MARGIN_SECONDS;
    public const PROCESSING_CLAIM_LOST_MESSAGE = 'Queued processing attempt no longer owns request';
    public const RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE = 'Queued response delivery attempt was not claimed';

    private RateLimitStateMapper $rateLimitMapper;
    private QueuedRequestMapper $queuedRequestMapper;
    private SettingsService $settingsService;
    private IJobList $jobList;
    private LoggerInterface $logger;

    public function __construct(
        RateLimitStateMapper $rateLimitMapper,
        QueuedRequestMapper $queuedRequestMapper,
        SettingsService $settingsService,
        IJobList $jobList,
        LoggerInterface $logger
    ) {
        $this->rateLimitMapper = $rateLimitMapper;
        $this->queuedRequestMapper = $queuedRequestMapper;
        $this->settingsService = $settingsService;
        $this->jobList = $jobList;
        $this->logger = $logger;
    }

    /**
     * Check if rate limiting is enabled in settings
     */
    public function isEnabled(): bool {
        $settings = $this->settingsService->getSettings();
        return (bool)$settings->getRateLimitEnabled();
    }

	/**
	 * Get configured rate limits from settings.
	 * 
	 * @return array{
	 *     mode: string,
	 *     managed: bool,
	 *     second: ?int,
	 *     minute: ?int,
	 *     hour: ?int,
	 *     day: ?int
	 * }
	 */
	public function getConfiguredLimits(string $endpoint = self::ENDPOINT_CHAT): array {
		$settings = $this->settingsService->getSettings();
		$second = $settings->getRateLimitSecond();
		$minute = $settings->getRateLimitMinute();
		$hour = $settings->getRateLimitHour();
		$day = $settings->getRateLimitDay();

		if ($endpoint === self::ENDPOINT_EMBEDDINGS) {
			$mode = $this->normalizeEmbeddingRateLimitMode($settings->getEmbeddingRateLimitMode());
			if ($mode === 'disabled') {
				return [
					'mode' => $mode,
					'managed' => false,
					'second' => null,
					'minute' => null,
					'hour' => null,
					'day' => null,
				];
			}

			if ($mode === 'custom') {
				$embeddingSecond = $settings->getEmbeddingRateLimitSecond();
				$embeddingMinute = $settings->getEmbeddingRateLimitMinute();
				$embeddingHour = $settings->getEmbeddingRateLimitHour();
				$embeddingDay = $settings->getEmbeddingRateLimitDay();

				return [
					'mode' => $mode,
					'managed' => true,
					'second' => $embeddingSecond !== null && $embeddingSecond > 0 ? $embeddingSecond : null,
					'minute' => $embeddingMinute !== null && $embeddingMinute > 0 ? $embeddingMinute : 100,
					'hour' => $embeddingHour !== null && $embeddingHour > 0 ? $embeddingHour : 2000,
					'day' => $embeddingDay !== null && $embeddingDay > 0 ? $embeddingDay : 4000,
				];
			}
		}

		return [
			'mode' => $endpoint === self::ENDPOINT_EMBEDDINGS ? 'inherit' : 'chat',
			'managed' => true,
			'second' => $second !== null && $second > 0 ? $second : null,
			'minute' => $minute !== null && $minute > 0 ? $minute : 30,
			'hour' => $hour !== null && $hour > 0 ? $hour : 200,
			'day' => $day !== null && $day > 0 ? $day : 1000,
		];
	}

	public function isRateLimitingEnabledForEndpoint(string $endpoint = self::ENDPOINT_CHAT): bool {
		if (!$this->isEnabled()) {
			return false;
		}

		$limits = $this->getConfiguredLimits($endpoint);
		return $limits['managed'];
	}

    /**
     * Check if a request can be processed immediately (has available rate limit capacity)
     * 
     * @param string $endpoint Endpoint key (default: chat_completions)
     */
	public function canProcess(string $endpoint = self::ENDPOINT_CHAT): bool {
		if (!$this->isEnabled()) {
			return true; // Rate limiting disabled, always allow
		}

		$limits = $this->getConfiguredLimits($endpoint);
		if (!$limits['managed']) {
			return true;
		}

		$state = $this->rateLimitMapper->getOrCreate(
			$endpoint,
			$limits['second'],
            $limits['minute'],
            $limits['hour'],
            $limits['day']
        );

        return $state->canProcess();
    }

	/**
	 * Get current rate limit state for an endpoint
	 */
	public function getState(string $endpoint = self::ENDPOINT_CHAT): RateLimitState {
		$limits = $this->getConfiguredLimits($endpoint);
		if (!$limits['managed']) {
			$state = new RateLimitState();
			$state->setEndpointKey($endpoint);
			return $state;
		}

		return $this->rateLimitMapper->getOrCreate(
			$endpoint,
			$limits['second'],
            $limits['minute'],
            $limits['hour'],
            $limits['day']
        );
    }

    /**
     * Get an already-observed rate limit state for an endpoint without creating it.
     */
    public function getObservedState(string $endpoint): ?RateLimitState {
        try {
            return $this->rateLimitMapper->findByEndpoint($endpoint);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Record that a request was made (decrement available capacity)
     */
	public function recordUsage(string $endpoint = self::ENDPOINT_CHAT): void {
		$limits = $this->getManagedLimits($endpoint);
		if ($limits === null) {
			return;
		}

		$this->rateLimitMapper->decrementRemaining(
			$endpoint,
			$limits['second'],
            $limits['minute'],
            $limits['hour'],
            $limits['day']
        );
    }

    /**
     * Update rate limit state from API response headers
     * 
     * @param array<string,string|int> $headers Response headers from LLM API
     */
	public function updateFromHeaders(array $headers, string $endpoint = self::ENDPOINT_CHAT): void {
		$limits = $this->getManagedLimits($endpoint);
		if ($limits === null) {
			return;
		}

		$this->rateLimitMapper->updateFromHeaders(
			$endpoint,
			$headers,
            $limits['second'],
            $limits['minute'],
            $limits['hour'],
            $limits['day']
        );
        
        $this->logger->debug('Rate limit state updated from headers', [
            'endpoint' => $endpoint,
            'remaining_second' => $headers['x-ratelimit-remaining-second'] ?? 'n/a',
            'remaining_minute' => $headers['x-ratelimit-remaining-minute'] ?? $headers['ratelimit-remaining'] ?? 'n/a',
            'remaining_hour' => $headers['x-ratelimit-remaining-hour'] ?? 'n/a',
        ]);
    }

    /**
     * Queue a request for later processing
     */
    public function queueRequest(
        int $botId,
        string $roomToken,
        string $userId,
        string $message,
        ?string $originalMessage = null,
        int $priority = 100,
        ?int $replyToMessageId = null,
        ?int $threadRootMessageId = null
    ): QueuedRequest {
        $request = new QueuedRequest();
        $request->setBotId($botId);
        $request->setRoomToken($roomToken);
        $request->setUserId($userId);
        $request->setMessage($message);
        $request->setOriginalMessage($originalMessage);
        $request->setReplyToMessageId($replyToMessageId !== null && $replyToMessageId > 0 ? $replyToMessageId : null);
        $request->setThreadRootMessageId($threadRootMessageId !== null && $threadRootMessageId > 0 ? $threadRootMessageId : null);
        $request->setStatus(QueuedRequest::STATUS_PENDING);
        $request->setPriority($priority);
        $request->setCreatedAt(time());

        $queued = $this->queuedRequestMapper->insert($request);
        
        $this->logger->info('Request queued due to rate limiting', [
            'request_id' => $queued->getId(),
            'bot_id' => $botId,
            'room_token' => $roomToken,
            'queue_depth' => $this->queuedRequestMapper->countPending(),
        ]);

        // Ensure background job is scheduled
        $this->scheduleProcessingJob();

        return $queued;
    }

    /**
     * Get the next pending request to process
     */
    public function getNextPending(): ?QueuedRequest {
        $pending = $this->queuedRequestMapper->findPending(1);
        return $pending[0] ?? null;
    }

    /**
     * Get multiple pending requests
     * 
     * @return QueuedRequest[]
     */
    public function getPendingRequests(int $limit = 10): array {
        return $this->queuedRequestMapper->findPending($limit);
    }

    /**
     * @return QueuedRequest[]
     */
    public function getResponseReadyRequests(int $limit = 10): array {
        return $this->queuedRequestMapper->findResponseReady($limit);
    }

    /**
     * Mark a request as processing
     */
    public function markProcessing(QueuedRequest $request): QueuedRequest {
        if ($request->getStatus() !== QueuedRequest::STATUS_PENDING) {
            throw new \LogicException(self::PROCESSING_CLAIM_LOST_MESSAGE);
        }

        $claimedAt = time();
        $claimed = $this->queuedRequestMapper->claimProcessingAttempt(
            $request->getId(),
            $request->getAttempts(),
            QueuedRequest::MAX_ATTEMPTS,
            $claimedAt
        );
        if (!$claimed) {
            throw new \LogicException(self::PROCESSING_CLAIM_LOST_MESSAGE);
        }

        $request->setStatus(QueuedRequest::STATUS_PROCESSING);
        $request->incrementAttempts();
        $request->setError(null);
        $request->setProcessedAt($claimedAt);
        return $request;
    }

    /**
     * Persist a completed agent response before attempting external delivery.
     * Recovery retries only this stored response and never re-runs the agent.
     */
    public function markResponseReady(QueuedRequest $request, string $result): QueuedRequest {
        if ($request->getStatus() !== QueuedRequest::STATUS_PROCESSING) {
            throw new \LogicException(self::PROCESSING_CLAIM_LOST_MESSAGE);
        }
        $stored = $this->queuedRequestMapper->storeResponseReady(
            $request->getId(),
            $request->getAttempts(),
            $result
        );
        if (!$stored) {
            throw new \LogicException(self::PROCESSING_CLAIM_LOST_MESSAGE);
        }

        $request->setStatus(QueuedRequest::STATUS_RESPONSE_READY);
        $request->setResult($result);
        $request->setError(null);
        $request->setAttempts(0);
        $request->setProcessedAt(null);
        return $request;
    }

    public function markResponseDeliveryAttempt(QueuedRequest $request): QueuedRequest {
        if ($request->getStatus() !== QueuedRequest::STATUS_RESPONSE_READY) {
            throw new \LogicException('Only response-ready requests can enter delivery');
        }

        $expectedAttempts = $request->getAttempts();
        $claimed = $this->queuedRequestMapper->claimResponseDeliveryAttempt(
            $request->getId(),
            $expectedAttempts,
            QueuedRequest::MAX_ATTEMPTS,
            time()
        );
        if (!$claimed) {
            throw new \LogicException(self::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE);
        }

        $stored = $this->queuedRequestMapper->findById($request->getId());
        if ($stored->getStatus() !== QueuedRequest::STATUS_DELIVERING
            || $stored->getAttempts() !== $expectedAttempts + 1
        ) {
            throw new \LogicException(self::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE);
        }

        return $stored;
    }

    /**
     * Mark a request as completed with result
     */
    public function markCompleted(QueuedRequest $request, string $result): QueuedRequest {
        $processedAt = time();
        $completed = $this->queuedRequestMapper->completeResponseDelivery(
            $request->getId(),
            $request->getAttempts(),
            $result,
            $processedAt
        );
        if (!$completed) {
            $stored = $this->queuedRequestMapper->findById($request->getId());
            if ($stored->getStatus() === QueuedRequest::STATUS_COMPLETED) {
                return $stored;
            }
            if ($stored->getStatus() === QueuedRequest::STATUS_FAILED
                || $this->responseDeliveryOwnershipTransferred($request, $stored)
            ) {
                throw new \LogicException(self::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE);
            }
            throw new \RuntimeException('Queued response completion was not persisted');
        }

        $request->setStatus(QueuedRequest::STATUS_COMPLETED);
        $request->setResult($result);
        $request->setError(null);
        $request->setProcessedAt($processedAt);
        return $request;
    }

    public function markResponseDeliveryFailed(QueuedRequest $request, string $error): QueuedRequest {
        $processedAt = time();
        $failed = $this->queuedRequestMapper->failResponseDelivery(
            $request->getId(),
            $request->getAttempts(),
            $error,
            $processedAt,
            QueuedRequest::MAX_ATTEMPTS
        );
        if (!$failed) {
            $stored = $this->queuedRequestMapper->findById($request->getId());
            if ($stored->getStatus() === QueuedRequest::STATUS_COMPLETED
                || $stored->getStatus() === QueuedRequest::STATUS_FAILED
            ) {
                return $stored;
            }
            if ($this->responseDeliveryOwnershipTransferred($request, $stored)) {
                throw new \LogicException(self::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE);
            }
            throw new \RuntimeException('Queued response delivery failure was not persisted');
        }

        $request->setStatus(QueuedRequest::STATUS_FAILED);
        $request->setError($error);
        $request->setAttempts(QueuedRequest::MAX_ATTEMPTS);
        $request->setProcessedAt($processedAt);
        return $request;
    }

    public function markResponseDeliveryRetry(QueuedRequest $request, string $error): QueuedRequest {
        if ($request->getStatus() !== QueuedRequest::STATUS_DELIVERING) {
            throw new \LogicException('Only an owned delivery can be scheduled for retry');
        }
        if ($request->getAttempts() >= QueuedRequest::MAX_ATTEMPTS) {
            return $this->markResponseDeliveryFailed(
                $request,
                'Talk delivery attempts exhausted after: ' . $error
            );
        }

        $released = $this->queuedRequestMapper->releaseResponseDeliveryForRetry(
            $request->getId(),
            $request->getAttempts(),
            $error,
            QueuedRequest::MAX_ATTEMPTS
        );
        if (!$released) {
            $stored = $this->queuedRequestMapper->findById($request->getId());
            if ($stored->getStatus() === QueuedRequest::STATUS_COMPLETED
                || $stored->getStatus() === QueuedRequest::STATUS_FAILED
            ) {
                return $stored;
            }
            if ($this->responseDeliveryOwnershipTransferred($request, $stored)) {
                throw new \LogicException(self::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE);
            }
            if ($stored->getStatus() === QueuedRequest::STATUS_RESPONSE_READY) {
                return $stored;
            }
            throw new \RuntimeException('Queued response delivery retry was not persisted');
        }

        $request->setStatus(QueuedRequest::STATUS_RESPONSE_READY);
        $request->setError($error);
        $request->setProcessedAt(null);
        return $request;
    }

    public function recoverStaleResponseDeliveries(int $leaseSeconds = self::RESPONSE_DELIVERY_LEASE_SECONDS): int {
        $now = time();
        $recovered = $this->queuedRequestMapper->recoverStaleResponseDeliveries(
            $now - $leaseSeconds,
            $now,
            QueuedRequest::MAX_ATTEMPTS
        );
        if ($recovered > 0) {
            $this->logger->warning('Recovered stale queued Talk deliveries', [
                'count' => $recovered,
                'lease_seconds' => $leaseSeconds,
            ]);
        }
        return $recovered;
    }

    public function failExhaustedPendingRequests(): int {
        $failed = $this->queuedRequestMapper->failExhaustedPending(
            QueuedRequest::MAX_ATTEMPTS,
            time()
        );
        if ($failed > 0) {
            $this->logger->warning('Reconciled exhausted pending queue requests', [
                'count' => $failed,
            ]);
        }
        return $failed;
    }

    public function markProcessingFailed(QueuedRequest $request, string $error): QueuedRequest {
        if ($request->getStatus() !== QueuedRequest::STATUS_PROCESSING) {
            throw new \LogicException(self::PROCESSING_CLAIM_LOST_MESSAGE);
        }
        $processedAt = time();
        $failed = $this->queuedRequestMapper->failProcessingAttempt(
            $request->getId(),
            $request->getAttempts(),
            $error,
            $processedAt,
            QueuedRequest::MAX_ATTEMPTS
        );
        if (!$failed) {
            throw new \LogicException(self::PROCESSING_CLAIM_LOST_MESSAGE);
        }

        $request->setStatus(QueuedRequest::STATUS_FAILED);
        $request->setError($error);
        $request->setAttempts(QueuedRequest::MAX_ATTEMPTS);
        $request->setProcessedAt($processedAt);
        return $request;
    }

    public function markProcessingRetry(QueuedRequest $request, string $error): QueuedRequest {
        if ($request->getStatus() !== QueuedRequest::STATUS_PROCESSING) {
            throw new \LogicException(self::PROCESSING_CLAIM_LOST_MESSAGE);
        }
        if ($request->getAttempts() >= QueuedRequest::MAX_ATTEMPTS) {
            return $this->markProcessingFailed($request, 'Max retries exceeded: ' . $error);
        }
        $released = $this->queuedRequestMapper->releaseProcessingForRetry(
            $request->getId(),
            $request->getAttempts(),
            $error,
            QueuedRequest::MAX_ATTEMPTS
        );
        if (!$released) {
            throw new \LogicException(self::PROCESSING_CLAIM_LOST_MESSAGE);
        }

        $request->setStatus(QueuedRequest::STATUS_PENDING);
        $request->setError($error);
        $request->setProcessedAt(null);
        return $request;
    }

    private function responseDeliveryOwnershipTransferred(QueuedRequest $request, QueuedRequest $stored): bool {
        return ($stored->getStatus() === QueuedRequest::STATUS_DELIVERING
            || $stored->getStatus() === QueuedRequest::STATUS_RESPONSE_READY)
            && $stored->getAttempts() !== $request->getAttempts();
    }

    /**
     * Mark a request as failed with error
     */
    public function markFailed(QueuedRequest $request, string $error): QueuedRequest {
        $request->setStatus(QueuedRequest::STATUS_FAILED);
        $request->setError($error);
        $request->setAttempts(max(QueuedRequest::MAX_ATTEMPTS, $request->getAttempts()));
        $request->setProcessedAt(time());
        return $this->queuedRequestMapper->update($request);
    }

    /**
     * Mark a request for retry by resetting to pending status
     * The attempt counter should already have been incremented by markProcessing()
     */
    public function markForRetry(QueuedRequest $request, string $error): QueuedRequest {
        $request->setStatus(QueuedRequest::STATUS_PENDING);
        $request->setError($error);
        // Don't set processedAt - it's still pending
        return $this->queuedRequestMapper->update($request);
    }

    /**
     * Get queue statistics
     * 
     * @return array{pending: int, processing: int, response_ready: int, completed: int, failed: int, total: int}
     */
    public function getQueueStats(): array {
        return $this->queuedRequestMapper->getQueueStats();
    }

    /**
     * Get the custom queue message or return null for default
     */
    public function getQueueMessage(): ?string {
        $settings = $this->settingsService->getSettings();
        return $settings->getRateLimitQueueMessage();
    }

    /**
     * Get current rate limit status for display
     * 
     * @return array{
     *     enabled: bool,
     *     can_process: bool,
     *     state: ?array,
     *     queue_stats: array,
     *     chat_status: array{can_process: bool, state: ?array},
	 *     embedding_status: array{mode: string, can_process: ?bool, state: ?array, observed: bool, source: string}
	 * }
	 */
	public function getStatus(): array {
        $enabled = $this->isEnabled();
        
        if (!$enabled) {
            return [
                'enabled' => false,
                'can_process' => true,
                'state' => null,
                'queue_stats' => ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 0],
				'chat_status' => [
					'can_process' => true,
					'state' => null,
				],
				'embedding_status' => [
					'mode' => 'inherit',
					'can_process' => null,
					'state' => null,
					'observed' => false,
					'source' => 'disabled',
				],
			];
		}

		$chatState = $this->getState(self::ENDPOINT_CHAT);
		$embeddingLimits = $this->getConfiguredLimits(self::ENDPOINT_EMBEDDINGS);
		$embeddingObservedState = $this->getObservedState(self::ENDPOINT_EMBEDDINGS);
		$embeddingState = null;
		$embeddingCanProcess = null;
		$embeddingObserved = false;
		$embeddingSource = 'disabled';

		if ($embeddingLimits['managed']) {
			$embeddingState = $embeddingObservedState ?? $this->getState(self::ENDPOINT_EMBEDDINGS);
			$embeddingCanProcess = $embeddingState->canProcess();
			$embeddingObserved = $embeddingObservedState !== null;
			$embeddingSource = $embeddingObserved ? 'observed' : 'configured';
		}

		$chatStateJson = $chatState->jsonSerialize();
		$embeddingStateJson = $embeddingState?->jsonSerialize();
		
        return [
            'enabled' => true,
            'can_process' => $chatState->canProcess(),
            'state' => $chatStateJson,
            'queue_stats' => $this->getQueueStats(),
            'chat_status' => [
                'can_process' => $chatState->canProcess(),
                'state' => $chatStateJson,
            ],
			'embedding_status' => [
				'mode' => $embeddingLimits['mode'],
				'can_process' => $embeddingCanProcess,
				'state' => $embeddingStateJson,
				'observed' => $embeddingObserved,
				'source' => $embeddingSource,
			],
		];
	}

    /**
     * Calculate seconds until a request can be processed
     */
	public function getSecondsUntilAvailable(string $endpoint = self::ENDPOINT_CHAT): int {
		if (!$this->isRateLimitingEnabledForEndpoint($endpoint)) {
			return 0;
		}

		$state = $this->getState($endpoint);
		return $state->getSecondsUntilAvailable();
    }

    /**
     * Schedule the background job to process queued requests
     */
    public function scheduleProcessingJob(): void {
        $jobClass = \OCA\EducAI\Jobs\ProcessQueuedRequestsJob::class;
        
        // Check if job is already scheduled
        if (!$this->jobList->has($jobClass, null)) {
            $this->jobList->add($jobClass);
            $this->logger->debug('Scheduled ProcessQueuedRequestsJob');
        }
    }

    /**
     * Cleanup old completed and failed requests
     */
    public function cleanup(int $maxAgeSeconds = 86400): array {
        $completedDeleted = $this->queuedRequestMapper->cleanupCompleted($maxAgeSeconds);
        $failedDeleted = $this->queuedRequestMapper->cleanupFailed(3, $maxAgeSeconds);
        $staleReset = $this->queuedRequestMapper->resetStaleProcessing(self::PROCESSING_LEASE_SECONDS);

        $this->logger->info('Queue cleanup completed', [
            'completed_deleted' => $completedDeleted,
            'failed_deleted' => $failedDeleted,
            'stale_reset' => $staleReset,
        ]);

        return [
            'completed_deleted' => $completedDeleted,
            'failed_deleted' => $failedDeleted,
            'stale_reset' => $staleReset,
        ];
    }

    /**
     * Reset rate limit state (for testing or manual reset)
     */
	public function resetState(): void {
		$this->rateLimitMapper->resetAll();
		$this->logger->info('Rate limit state reset');
	}

	/**
	 * @return array{
	 *     mode: string,
	 *     managed: bool,
	 *     second: ?int,
	 *     minute: ?int,
	 *     hour: ?int,
	 *     day: ?int
	 * }|null
	 */
	private function getManagedLimits(string $endpoint): ?array {
		if (!$this->isEnabled()) {
			return null;
		}

		$limits = $this->getConfiguredLimits($endpoint);
		return $limits['managed'] ? $limits : null;
	}

    /**
     * Get a queued request by ID
     */
	public function getQueuedRequest(int $id): ?QueuedRequest {
		try {
			return $this->queuedRequestMapper->findById($id);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	private function normalizeEmbeddingRateLimitMode(?string $mode): string {
		return match ($mode) {
			'disabled', 'custom' => $mode,
			default => 'inherit',
		};
	}
}
