<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;

/** A rejected request, before any bot processing has started. */
class InvalidWebhookException extends RuntimeException {
	public function __construct(string $message, private int $statusCode = 400) {
		parent::__construct($message);
	}

	public function getStatusCode(): int {
		return $this->statusCode;
	}
}
