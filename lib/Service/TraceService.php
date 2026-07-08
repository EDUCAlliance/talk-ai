<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use JsonSerializable;
use OCA\EducAI\Db\TraceEvent;
use OCA\EducAI\Db\TraceEventMapper;
use OCA\EducAI\Db\TraceRun;
use OCA\EducAI\Db\TraceRunMapper;
use Psr\Log\LoggerInterface;
use Throwable;

class TraceService {
	private const PREVIEW_LIMIT = 1000;
	private const USER_MESSAGE_PREVIEW_LIMIT = 500;
	private const ARGUMENT_JSON_LIMIT = 16384;
	private const RESULT_JSON_LIMIT = 65536;
	private const UNLIMITED_JSON_EVENTS = ['llm_request'];

	/** @var array<int,int> */
	private array $sequenceCounters = [];

	/** @var array<int,string> */
	private const SENSITIVE_KEYS = [
		'api_key',
		'apikey',
		'token',
		'access_token',
		'refresh_token',
		'authorization',
		'cookie',
		'password',
		'secret',
		'signature',
		'webhook_secret',
	];

	public function __construct(
		private TraceRunMapper $traceRunMapper,
		private TraceEventMapper $traceEventMapper,
		private LoggerInterface $logger,
	) {}

	/**
	 * @param array<string,mixed> $context
	 */
	public function startRun(array $context): ?int {
		try {
			$now = time();
			$run = new TraceRun();
			$run->setUserId($this->normalizeUserId((string)($context['user_id'] ?? '')));
			$run->setBotId($this->nullableInt($context['bot_id'] ?? null));
			$run->setBotMentionName($this->nullableString($context['bot_mention_name'] ?? null, 128));
			$run->setRoomToken($this->nullableString($context['room_token'] ?? null, 64));
			$run->setTalkMessageId($this->nullableInt($context['talk_message_id'] ?? null));
			$run->setReplyTargetMessageId($this->nullableInt($context['reply_target_message_id'] ?? null));
			$run->setThreadRootMessageId($this->nullableInt($context['thread_root_message_id'] ?? null));
			$run->setSource($this->nullableString($context['source'] ?? 'talk', 32) ?? 'talk');
			$run->setStatus('running');
			$run->setUserMessagePreview($this->preview($context['user_message'] ?? null, self::USER_MESSAGE_PREVIEW_LIMIT));
			$run->setErrorSummary(null);
			$run->setStartedAt($now);
			$run->setFinishedAt(null);
			$run->setDurationMs(null);
			$run->setToolCallCount(0);
			$run->setEventCount(0);
			$run->setCreatedAt($now);
			$run->setUpdatedAt($now);

			$inserted = $this->traceRunMapper->insertRun($run);
			$id = $inserted->getId();

			if (!is_numeric($id)) {
				return null;
			}

			$runId = (int)$id;
			$this->sequenceCounters[$runId] = 0;

			return $runId;
		} catch (Throwable $e) {
			$this->logger->warning('EducAI trace run start failed', [
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function recordEvent(?int $runId, string $eventType, array $payload = []): void {
		if ($runId === null || $runId <= 0) {
			return;
		}

		try {
			$payloadData = array_key_exists('payload', $payload) ? $payload['payload'] : $this->stripReservedEventKeys($payload);
			$resultData = $payload['result'] ?? $payload['response'] ?? null;
			$payloadJson = $payloadData === [] || $payloadData === null
				? null
				: $this->jsonEncodeForStorage($payloadData, $this->jsonLimitForEvent($eventType));
			$resultJson = $resultData === null
				? null
				: $this->jsonEncodeForStorage($resultData, self::RESULT_JSON_LIMIT);
			$createdAt = time();
			$event = new TraceEvent();
			$event->setRunId($runId);
			$event->setSequence($this->nextSequence($runId));
			$event->setEventType(substr($eventType, 0, 64));
			$event->setStatus($this->nullableString($payload['status'] ?? null, 32));
			$event->setToolName($this->nullableString($payload['tool_name'] ?? ($payload['tool'] ?? null), 128));
			$event->setDurationMs($this->nullableInt($payload['duration_ms'] ?? null));
			$event->setPayloadJson($payloadJson);
			$event->setPayloadPreview($this->preview($payloadData, self::PREVIEW_LIMIT));
			$event->setResultJson($resultJson);
			$event->setResultPreview($this->preview($resultData, self::PREVIEW_LIMIT));
			$event->setErrorMessage($this->nullableString($payload['error_message'] ?? ($payload['error'] ?? null), 4096));
			$event->setCreatedAt($createdAt);

			$this->traceEventMapper->insertEvent($event);
			$this->traceRunMapper->incrementCounters($runId, $eventType === 'tool_call');
			if ($eventType === 'llm_response') {
				$usage = $this->extractTokenUsage($payloadData);
				if ($usage !== null) {
					$this->traceRunMapper->addLlmTokenUsage(
						$runId,
						$usage['prompt_tokens'],
						$usage['completion_tokens'],
						$usage['total_tokens']
					);
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug('EducAI trace event write failed', [
				'run_id' => $runId,
				'event_type' => $eventType,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * @param array<string,mixed> $arguments
	 */
	public function recordToolCall(?int $runId, string $toolName, array $arguments, ?string $callId = null): void {
		$this->recordEvent($runId, 'tool_call', [
			'status' => 'started',
			'tool_name' => $toolName,
			'payload' => [
				'call_id' => $callId,
				'arguments' => $arguments,
			],
		]);
	}

	/**
	 * @param mixed $result
	 */
	public function recordToolResult(?int $runId, string $toolName, string $status, $result, int $durationMs, ?string $error = null): void {
		$this->recordEvent($runId, 'tool_result', [
			'status' => $status,
			'tool_name' => $toolName,
			'duration_ms' => $durationMs,
			'result' => $result,
			'error_message' => $error,
		]);
	}

	public function finishRun(?int $runId, string $status, ?string $errorSummary = null): void {
		if ($runId === null || $runId <= 0) {
			return;
		}

		try {
			$run = $this->traceRunMapper->findById($runId);
			$finishedAt = time();
			$durationMs = max(0, (int)round(($finishedAt - $run->getStartedAt()) * 1000));
			$errorSummary = $errorSummary === null ? null : $this->preview($errorSummary, self::PREVIEW_LIMIT);

			$this->traceRunMapper->markFinished($runId, $status, $errorSummary, $finishedAt, $durationMs);
		} catch (Throwable $e) {
			$this->logger->debug('EducAI trace run finish failed', [
				'run_id' => $runId,
				'status' => $status,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{traces:array<int,array<string,mixed>>,total:int,limit:int,offset:int}
	 */
	public function listRunsForUser(string $userId, array $filters): array {
		$limit = $this->normalizeLimit($filters['limit'] ?? null);
		$offset = $this->normalizeOffset($filters['offset'] ?? null);
		$normalizedFilters = $this->normalizeFilters($filters);
		$normalizedUserId = $this->normalizeUserId($userId);
		$runs = $this->traceRunMapper->findForUser($normalizedUserId, $normalizedFilters, $limit, $offset);
		$total = $this->traceRunMapper->countForUser($normalizedUserId, $normalizedFilters);

		return [
			'traces' => array_map(static fn(TraceRun $run): array => $run->jsonSerialize(), $runs),
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * @return array{trace:array<string,mixed>,events:array<int,array<string,mixed>>}
	 */
	public function getRunForUser(int $runId, string $userId): array {
		$run = $this->traceRunMapper->findByIdForUser($runId, $this->normalizeUserId($userId));
		$events = $this->traceEventMapper->findByRunId($runId);

		return [
			'trace' => $run->jsonSerialize(),
			'events' => array_map(static fn(TraceEvent $event): array => $event->jsonSerialize(), $events),
		];
	}

	public function deleteRunForUser(int $runId, string $userId): void {
		$normalizedUserId = $this->normalizeUserId($userId);
		$this->traceRunMapper->findByIdForUser($runId, $normalizedUserId);
		$this->traceEventMapper->deleteByRunId($runId);
		$this->traceRunMapper->deleteForUser($runId, $normalizedUserId);
	}

	/**
	 * @return array{runs:int,events:int}
	 */
	public function deleteAllForUser(string $userId): array {
		$normalizedUserId = $this->normalizeUserId($userId);
		$deletedEvents = $this->traceEventMapper->deleteForUser($normalizedUserId);
		$deletedRuns = $this->traceRunMapper->deleteAllForUser($normalizedUserId);

		return [
			'runs' => $deletedRuns,
			'events' => $deletedEvents,
		];
	}

	public function deleteOlderThan(int $cutoff): void {
		$this->traceEventMapper->deleteOlderThan($cutoff);
		$this->traceRunMapper->deleteOlderThan($cutoff);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	private function normalizeFilters(array $filters): array {
		return [
			'botId' => $filters['botId'] ?? $filters['bot_id'] ?? null,
			'botMentionName' => $filters['botMentionName'] ?? $filters['bot_mention_name'] ?? null,
			'status' => $filters['status'] ?? null,
			'from' => $filters['from'] ?? null,
			'to' => $filters['to'] ?? null,
			'q' => $filters['q'] ?? null,
			'onlyErrors' => $this->normalizeBool($filters['onlyErrors'] ?? $filters['only_errors'] ?? false),
			'onlyWithTools' => $this->normalizeBool($filters['onlyWithTools'] ?? $filters['only_with_tools'] ?? false),
		];
	}

	private function normalizeLimit($value): int {
		return is_numeric($value) ? max(1, min(100, (int)$value)) : 25;
	}

	private function normalizeOffset($value): int {
		return is_numeric($value) ? max(0, (int)$value) : 0;
	}

	private function normalizeBool($value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_string($value)) {
			return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
		}
		return (bool)$value;
	}

	/**
	 * @param mixed $payloadData
	 * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}|null
	 */
	private function extractTokenUsage($payloadData): ?array {
		if (!is_array($payloadData)) {
			return null;
		}

		$usage = $payloadData['usage'] ?? null;
		if (!is_array($usage)) {
			return null;
		}

		$promptTokens = $this->nullableUsageInt($usage, ['prompt_tokens', 'promptTokens', 'input_tokens', 'inputTokens']) ?? 0;
		$completionTokens = $this->nullableUsageInt($usage, ['completion_tokens', 'completionTokens', 'output_tokens', 'outputTokens']) ?? 0;
		$totalTokens = $this->nullableUsageInt($usage, ['total_tokens', 'totalTokens']);
		if ($totalTokens === null && ($promptTokens > 0 || $completionTokens > 0)) {
			$totalTokens = $promptTokens + $completionTokens;
		}

		$totalTokens ??= 0;
		if ($promptTokens === 0 && $completionTokens === 0 && $totalTokens === 0) {
			return null;
		}

		return [
			'prompt_tokens' => $promptTokens,
			'completion_tokens' => $completionTokens,
			'total_tokens' => $totalTokens,
		];
	}

	/**
	 * @param array<string,mixed> $usage
	 * @param list<string> $keys
	 */
	private function nullableUsageInt(array $usage, array $keys): ?int {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $usage) || !is_numeric($usage[$key])) {
				continue;
			}
			return max(0, (int)$usage[$key]);
		}

		return null;
	}

	private function nextSequence(int $runId): int {
		if (!array_key_exists($runId, $this->sequenceCounters)) {
			$this->sequenceCounters[$runId] = $this->traceEventMapper->countByRunId($runId);
		}

		$this->sequenceCounters[$runId]++;
		return $this->sequenceCounters[$runId];
	}

	private function normalizeUserId(string $userId): string {
		$normalized = trim($userId);
		if (str_starts_with($normalized, 'users/')) {
			$normalized = substr($normalized, strlen('users/'));
		}

		return $normalized;
	}

	/**
	 * @param mixed $value
	 */
	private function jsonEncodeForStorage($value, ?int $limit): ?string {
		$normalized = $this->redactSensitiveData($this->normalizeValue($value));
		$json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			return null;
		}

		if ($limit === null || strlen($json) <= $limit) {
			return $json;
		}

		$truncated = [
			'truncated' => true,
			'originalLength' => strlen($json),
			'preview' => $this->truncateText($json, min(self::PREVIEW_LIMIT, $limit)),
		];

		return json_encode($truncated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
	}

	/**
	 * @param mixed $value
	 */
	private function preview($value, int $limit): ?string {
		if ($value === null) {
			return null;
		}

		$normalized = $this->redactSensitiveData($this->normalizeValue($value));
		if (is_string($normalized)) {
			return $this->truncateText($normalized, $limit);
		}

		$json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			return null;
		}

		return $this->truncateText($json, $limit);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function redactSensitiveData($value) {
		if (is_array($value)) {
			$redacted = [];
			foreach ($value as $key => $item) {
				$keyString = is_string($key) ? $key : (string)$key;
				if ($this->isSensitiveKey($keyString)) {
					$redacted[$key] = '[redacted]';
					continue;
				}
				$redacted[$key] = $this->redactSensitiveData($item);
			}
			return $redacted;
		}

		if (is_string($value)) {
			return $this->redactSensitiveText($value);
		}

		return $value;
	}

	private function isSensitiveKey(string $key): bool {
		$normalized = strtolower(str_replace(['-', ' '], '_', $key));
		if (in_array($normalized, ['max_tokens', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cached_tokens', 'token_limit'], true)) {
			return false;
		}

		foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
			if ($normalized === $sensitiveKey || str_contains($normalized, $sensitiveKey)) {
				return true;
			}
		}

		return false;
	}

	private function redactSensitiveText(string $text): string {
		$text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 [redacted]', $text) ?? $text;
		$text = preg_replace('/\b(api[_-]?key|token|password|secret)=([^\s&]{6,})/i', '$1=[redacted]', $text) ?? $text;

		return $text;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function normalizeValue($value) {
		if ($value instanceof Throwable) {
			return [
				'class' => get_class($value),
				'message' => $value->getMessage(),
			];
		}

		if ($value instanceof JsonSerializable) {
			return $value->jsonSerialize();
		}

		if (is_array($value)) {
			$normalized = [];
			foreach ($value as $key => $item) {
				$normalized[$key] = $this->normalizeValue($item);
			}
			return $normalized;
		}

		if (is_object($value)) {
			return [
				'class' => get_class($value),
			];
		}

		if (is_resource($value)) {
			return '[resource]';
		}

		return $value;
	}

	private function truncateText(string $text, int $limit): string {
		if (strlen($text) <= $limit) {
			return $text;
		}

		return substr($text, 0, max(0, $limit - 3)) . '...';
	}

	private function nullableString($value, int $limit): ?string {
		if ($value === null) {
			return null;
		}

		$string = trim((string)$value);
		if ($string === '') {
			return null;
		}

		return $this->truncateText($this->redactSensitiveText($string), $limit);
	}

	private function nullableInt($value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		return is_numeric($value) ? (int)$value : null;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function stripReservedEventKeys(array $payload): array {
		unset(
			$payload['status'],
			$payload['tool_name'],
			$payload['tool'],
			$payload['duration_ms'],
			$payload['result'],
			$payload['response'],
			$payload['error'],
			$payload['error_message']
		);

		return $payload;
	}

	private function jsonLimitForEvent(string $eventType): ?int {
		if (in_array($eventType, self::UNLIMITED_JSON_EVENTS, true)) {
			return null;
		}

		return $eventType === 'tool_call' ? self::ARGUMENT_JSON_LIMIT : self::RESULT_JSON_LIMIT;
	}
}
