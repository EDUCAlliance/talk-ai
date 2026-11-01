<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/**
 * A controlled, model-safe failure of an API tool call. Messages never contain
 * credentials, request URLs with values or response bodies.
 */
final class ApiExecutionException extends \RuntimeException {
	/** @param array<string,scalar|null> $details */
	public function __construct(
		private string $errorCode,
		string $message,
		private array $details = [],
		private bool $requestSent = false,
	) {
		parent::__construct($message);
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	/** @return array<string,scalar|null> */
	public function getDetails(): array {
		return $this->details;
	}

	public function wasRequestSent(): bool {
		return $this->requestSent;
	}
}
