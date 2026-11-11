<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use Exception;

/**
 * The preview chat of a draft could not answer right now. The reason is one of the constants;
 * details of the model provider are never part of it.
 */
class PreviewUnavailableException extends Exception {
	public const RATE_LIMITED = 'rate_limited';
	public const NO_ANSWER = 'no_answer';

	public function __construct(
		public readonly string $reason,
	) {
		parent::__construct('The bot preview is unavailable: ' . $reason);
	}
}
