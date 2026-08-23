<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Closure;
use OCA\EducAI\Exception\AgentRunInterruptedException;
use Throwable;

final class AgentRunControl {
	/** @var Closure():float */
	private Closure $clock;

	/** @var (Closure():bool)|null */
	private ?Closure $abortCallback;

	private float $startedAt;

	public function __construct(
		private int $maxWallClockSeconds,
		?callable $abortCallback = null,
		?callable $clock = null,
	) {
		$this->maxWallClockSeconds = max(1, $this->maxWallClockSeconds);
		$this->abortCallback = $abortCallback !== null ? Closure::fromCallable($abortCallback) : null;
		$this->clock = $clock !== null
			? Closure::fromCallable($clock)
			: static fn (): float => hrtime(true) / 1_000_000_000;
		$this->startedAt = ($this->clock)();
	}

	/** @throws AgentRunInterruptedException */
	public function assertCanContinue(): void {
		if ($this->abortCallback !== null) {
			try {
				$aborted = (bool)($this->abortCallback)();
			} catch (Throwable $e) {
				throw new AgentRunInterruptedException(
					AgentRunInterruptedException::REASON_ABORTED,
					$e,
				);
			}

			if ($aborted) {
				throw new AgentRunInterruptedException(AgentRunInterruptedException::REASON_ABORTED);
			}
		}

		if ($this->getRemainingSeconds() <= 0.0) {
			throw new AgentRunInterruptedException(AgentRunInterruptedException::REASON_WALL_CLOCK);
		}
	}

	public function getRemainingSeconds(): float {
		$elapsed = max(0.0, ($this->clock)() - $this->startedAt);
		return max(0.0, $this->maxWallClockSeconds - $elapsed);
	}

	/** @throws AgentRunInterruptedException */
	public function clampTimeout(int|float $requestedSeconds): float {
		$this->assertCanContinue();
		$remainingSeconds = $this->getRemainingSeconds();
		if ($remainingSeconds <= 0.0) {
			throw new AgentRunInterruptedException(AgentRunInterruptedException::REASON_WALL_CLOCK);
		}

		return min(max(0.001, (float)$requestedSeconds), $remainingSeconds);
	}

	public function getMaxWallClockSeconds(): int {
		return $this->maxWallClockSeconds;
	}
}
