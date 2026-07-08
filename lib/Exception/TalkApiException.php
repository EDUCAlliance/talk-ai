<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;

class TalkApiException extends RuntimeException {
	private int $statusCode;
	private string $responseBody;

	public function __construct(string $message, int $statusCode = 0, string $responseBody = '') {
		parent::__construct($message);
		$this->statusCode = $statusCode;
		$this->responseBody = $responseBody;
	}

	public function getStatusCode(): int {
		return $this->statusCode;
	}

	public function getResponseBody(): string {
		return $this->responseBody;
	}
}
