<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Exception\ProviderAttemptBudgetExceededException;

final class ProviderAttemptBudget {
	public const DEFAULT_LIMIT = 24;
	public const HARD_LIMIT = 48;

	private int $limit;
	private int $consumed = 0;

	public function __construct(int $limit = self::DEFAULT_LIMIT) {
		$this->limit = max(1, min(self::HARD_LIMIT, $limit));
	}

	public function consume(): int {
		if ($this->consumed >= $this->limit) {
			throw new ProviderAttemptBudgetExceededException($this->limit, $this->consumed);
		}

		$this->consumed++;
		return $this->consumed;
	}

	public function getLimit(): int {
		return $this->limit;
	}

	public function getConsumed(): int {
		return $this->consumed;
	}

	public function getRemaining(): int {
		return $this->limit - $this->consumed;
	}
}
