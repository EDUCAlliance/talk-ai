<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;

final class ModelTestException extends RuntimeException {
	public function __construct(private bool $outputLimitReached) {
		parent::__construct('The provider returned no visible test text.');
	}

	public function isOutputLimitReached(): bool {
		return $this->outputLimitReached;
	}
}
