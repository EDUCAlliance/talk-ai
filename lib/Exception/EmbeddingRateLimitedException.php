<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;
use Throwable;

/**
 * The embedding provider asked for a pause that is too long to wait for inline.
 * Callers should retry after the given delay instead of failing permanently.
 */
final class EmbeddingRateLimitedException extends RuntimeException {
	public function __construct(private int $retryAfterSeconds, ?Throwable $previous = null) {
		parent::__construct('The embedding provider asked to retry after ' . $retryAfterSeconds . ' seconds.', 429, $previous);
	}

	public function getRetryAfterSeconds(): int {
		return $this->retryAfterSeconds;
	}
}
