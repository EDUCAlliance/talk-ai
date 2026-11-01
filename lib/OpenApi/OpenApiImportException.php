<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/**
 * A document that cannot become a revision at all. Per-operation problems are
 * diagnostics on the manifest instead.
 */
final class OpenApiImportException extends \RuntimeException {
	/** @param array<string,scalar|null> $details */
	public function __construct(
		private string $errorCode,
		string $message,
		private array $details = [],
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
}
