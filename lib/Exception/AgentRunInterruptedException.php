<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;
use Throwable;

final class AgentRunInterruptedException extends RuntimeException {
	public const REASON_ABORTED = 'aborted';
	public const REASON_WALL_CLOCK = 'wall_clock';

	public function __construct(
		private string $reason,
		?Throwable $previous = null,
	) {
		parent::__construct('Agent run interrupted: ' . $reason, 0, $previous);
	}

	public function getReason(): string {
		return $this->reason;
	}
}
