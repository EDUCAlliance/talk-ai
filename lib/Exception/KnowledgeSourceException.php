<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;
use Throwable;

/**
 * A knowledge source could not be indexed for a reason that can be shown to the
 * bot owner as a category with safe parameters (counts, file extensions).
 * The detail text is for logs only.
 */
final class KnowledgeSourceException extends RuntimeException {
	/**
	 * @param array<string,int|bool|string|list<string>> $parameters
	 */
	public function __construct(
		private string $errorCode,
		private array $parameters = [],
		string $detail = '',
		?Throwable $previous = null,
	) {
		parent::__construct($detail !== '' ? $detail : $errorCode, 0, $previous);
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	/**
	 * @return array<string,int|bool|string|list<string>>
	 */
	public function getParameters(): array {
		return $this->parameters;
	}
}
