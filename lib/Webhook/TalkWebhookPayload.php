<?php

declare(strict_types=1);

namespace OCA\EducAI\Webhook;

use JsonException;
use OCA\EducAI\Exception\InvalidWebhookException;

/** Bounded transport input, separate from the conversation's model token budget. */
class TalkWebhookPayload {
	// Talk sends one message and optionally its quoted parent, not the full history.
	// This also fits two maximum-length (32,000 character) emoji messages after
	// both JSON-encoding layers, with room for their surrounding metadata.
	public const MAX_BODY_BYTES = 1048576;

	public static function assertBodySize(string $body): void {
		if (strlen($body) > self::MAX_BODY_BYTES) {
			throw new InvalidWebhookException('Talk webhook body exceeds the 1 MiB limit', 413);
		}
	}

	/** @return array<string,mixed> */
	public static function decode(string $body): array {
		self::assertBodySize($body);
		try {
			$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new InvalidWebhookException('Invalid JSON webhook payload');
		}

		if (!is_array($payload) || !is_array($payload['object'] ?? null)) {
			throw new InvalidWebhookException('Talk webhook payload must contain an object');
		}
		foreach (['actor', 'target'] as $key) {
			// Join/Leave notifications have no target. Missing fields remain inert,
			// but a supplied scalar must not reach array access in the parser.
			if (isset($payload[$key]) && !is_array($payload[$key])) {
				throw new InvalidWebhookException('Invalid Talk webhook ' . $key);
			}
		}
		foreach (['object', 'actor', 'target'] as $key) {
			$id = $payload[$key]['id'] ?? null;
			if ($id !== null && !is_string($id) && !is_int($id)) {
				throw new InvalidWebhookException('Invalid Talk webhook ' . $key . ' ID');
			}
		}
		foreach (['name', 'message'] as $key) {
			if (isset($payload['object'][$key]) && !is_string($payload['object'][$key])) {
				throw new InvalidWebhookException('Invalid Talk webhook object ' . $key);
			}
		}
		$content = $payload['object']['content'] ?? null;
		if ($content !== null && !is_string($content) && !is_array($content)) {
			throw new InvalidWebhookException('Invalid Talk webhook object content');
		}

		return $payload;
	}
}
