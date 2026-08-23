<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;

final class IncompleteProviderStreamException extends RuntimeException {
	public const MESSAGE = 'Provider response ended without a terminal finish reason';

	public function __construct() {
		parent::__construct(self::MESSAGE);
	}
}
