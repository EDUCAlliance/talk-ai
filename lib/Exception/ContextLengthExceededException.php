<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;

final class ContextLengthExceededException extends RuntimeException {
	public const REASON = 'context_length_exceeded';
	public const MESSAGE = 'The request exceeds the model context limit';

	public function __construct(int $httpStatus = 0) {
		parent::__construct(self::MESSAGE, $httpStatus);
	}
}
