<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;

final class ProviderAttemptBudgetExceededException extends RuntimeException {
	public function __construct(
		private int $limit,
		private int $consumed,
	) {
		parent::__construct('Provider attempt budget exhausted');
	}

	public function getLimit(): int {
		return $this->limit;
	}

	public function getConsumed(): int {
		return $this->consumed;
	}
}
