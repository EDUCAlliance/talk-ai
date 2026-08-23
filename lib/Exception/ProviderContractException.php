<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;

final class ProviderContractException extends RuntimeException {
	public const MESSAGE = 'Provider response violated the native response contract';

	public function __construct() {
		parent::__construct(self::MESSAGE);
	}
}
