<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/**
 * Rejected administrative change to an API connection, with a stable code
 * for the UI and the HTTP status the controller should answer with.
 */
final class ApiConnectionException extends \RuntimeException {
	/** @param array<string,scalar|list<scalar>|null> $details */
	public function __construct(
		private string $errorCode,
		string $message,
		private int $status = 400,
		private array $details = [],
	) {
		parent::__construct($message);
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	public function getStatus(): int {
		return $this->status;
	}

	/** @return array<string,scalar|list<scalar>|null> */
	public function getDetails(): array {
		return $this->details;
	}
}
