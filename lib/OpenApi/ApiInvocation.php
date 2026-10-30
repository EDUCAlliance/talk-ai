<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Db\ApiConnection;

/**
 * Server-verified context of one agent run that may call API tools. Built
 * only from the invocation context BotService sets; never from model input.
 */
final class ApiInvocation {
	public const MAX_CALLS_PER_RUN = 8;
	/** Time kept free for the model to answer after the last API call. */
	public const DEADLINE_RESERVE_SECONDS = 5.0;
	public const MIN_CALL_SECONDS = 1.0;

	private int $calls = 0;

	public function __construct(
		public readonly string $actorId,
		public readonly int $botId,
		public readonly string $botVisibility,
		public readonly string $roomToken,
		public readonly float $deadline,
		public readonly ?int $traceRunId = null,
	) {
	}

	/** @param array<string,mixed>|null $context */
	public static function fromContext(?array $context): ?self {
		$actor = $context['actor_id'] ?? null;
		$bot = $context['bot_id'] ?? null;
		$visibility = $context['bot_visibility'] ?? null;
		$room = $context['room_token'] ?? null;
		$deadline = $context['deadline'] ?? null;
		$trace = $context['trace_run_id'] ?? null;
		if (!is_string($actor) || trim($actor) === '' || !is_int($bot) || $bot < 1
			|| !is_string($visibility) || !in_array($visibility, ApiConnection::BOT_VISIBILITIES, true)
			|| !is_string($room) || trim($room) === ''
			|| !(is_float($deadline) || is_int($deadline))) {
			return null;
		}
		return new self($actor, $bot, $visibility, $room, (float)$deadline, is_int($trace) ? $trace : null);
	}

	/**
	 * Reserves one call of the run budget and returns its timeout.
	 *
	 * @throws ApiExecutionException
	 */
	public function beginCall(float $maxTimeout, float $now): float {
		if ($this->calls >= self::MAX_CALLS_PER_RUN) {
			throw new ApiExecutionException('api_call_budget_exhausted', 'The API call limit for this answer is reached. Answer with the results you already have.', ['limit' => self::MAX_CALLS_PER_RUN]);
		}
		$available = $this->deadline - $now - self::DEADLINE_RESERVE_SECONDS;
		if ($available < self::MIN_CALL_SECONDS) {
			throw new ApiExecutionException('deadline_exceeded', 'There is no time left for another API call. Answer with the results you already have.');
		}
		$this->calls++;
		return min($maxTimeout, $available);
	}

	/** @return array<string,int> */
	public function logContext(): array {
		return array_filter(['bot_id' => $this->botId, 'trace_run_id' => $this->traceRunId], static fn ($value): bool => $value !== null);
	}
}
