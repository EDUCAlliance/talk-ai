<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;
use Throwable;

/**
 * A wiki operation failed for a reason the model can act on. The code and
 * message are passed to the model as a tool error, so they must not contain
 * secrets or content from other users.
 */
final class WikiException extends RuntimeException {
	public const INVALID_PATH = 'invalid_path';
	public const INVALID_ARGUMENT = 'invalid_argument';
	public const NOT_FOUND = 'not_found';
	public const ALREADY_EXISTS = 'already_exists';
	/** Another page differs only in case, spaces, hyphens or underscores. */
	public const SIMILAR_PAGE_EXISTS = 'similar_page_exists';
	public const PATH_CONFLICT = 'path_conflict';
	public const TOO_LARGE = 'too_large';
	public const FORBIDDEN = 'forbidden';
	public const LOCKED = 'locked';
	public const WIKI_UNAVAILABLE = 'wiki_unavailable';
	public const VERIFICATION_FAILED = 'verification_failed';
	public const OPERATION_FAILED = 'operation_failed';

	/**
	 * @param array<string,mixed> $details
	 */
	public function __construct(
		private string $errorCode,
		string $message,
		private array $details = [],
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getDetails(): array {
		return $this->details;
	}
}
