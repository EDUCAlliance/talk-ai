<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Webhook;

use OCA\EducAI\Exception\InvalidWebhookException;
use OCA\EducAI\Webhook\TalkAttachmentNormalizer;
use OCA\EducAI\Webhook\TalkMessageParser;
use OCA\EducAI\Webhook\TalkWebhookPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TalkWebhookPayloadTest extends TestCase {
	public function testOversizedStreamIsRejectedWithoutReadingTheRemainingBody(): void {
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, str_repeat('x', TalkWebhookPayload::MAX_BODY_BYTES + 65536));
		rewind($stream);
		try {
			TalkWebhookPayload::readBody($stream);
			$this->fail('Expected an oversized webhook to be rejected');
		} catch (InvalidWebhookException $e) {
			$this->assertSame(413, $e->getStatusCode());
			$this->assertSame(TalkWebhookPayload::MAX_BODY_BYTES + 1, ftell($stream));
		} finally {
			fclose($stream);
		}
	}

	public function testExactBodyLimitIsAllowedAndPreserved(): void {
		$body = '{"object":{},"padding":"' . str_repeat('x', TalkWebhookPayload::MAX_BODY_BYTES - 26) . '"}';
		$this->assertSame(TalkWebhookPayload::MAX_BODY_BYTES, strlen($body));
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $body);
		rewind($stream);
		try {
			$this->assertSame($body, TalkWebhookPayload::readBody($stream));
			$this->assertSame([], TalkWebhookPayload::decode($body)['object']);
		} finally {
			fclose($stream);
		}
	}

	#[DataProvider('invalidPayloads')]
	public function testMalformedOrWronglyTypedPayloadIsRejectedAsBadRequest(string $body): void {
		try {
			TalkWebhookPayload::decode($body);
			$this->fail('Expected an invalid webhook payload to be rejected');
		} catch (InvalidWebhookException $e) {
			$this->assertSame(400, $e->getStatusCode());
		}
	}

	public static function invalidPayloads(): array {
		return [
			'invalid JSON' => ['{"object":'],
			'invalid UTF-8' => ['{"object":{"name":"' . "\xff" . '"}}'],
			'boolean root' => ['true'],
			'number root' => ['1'],
			'string root' => ['"hello"'],
			'null root' => ['null'],
			'list root' => ['[]'],
			'missing object' => ['{}'],
			'string object' => ['{"object":"hello"}'],
			'array actor ID' => ['{"object":{},"actor":{"id":[]}}'],
			'array target ID' => ['{"object":{},"target":{"id":[]}}'],
			'array message ID' => ['{"object":{"id":[]}}'],
			'string actor' => ['{"object":{},"actor":"hello"}'],
			'string target' => ['{"object":{},"target":"hello"}'],
			'array event name' => ['{"object":{"name":[]}}'],
			'array message text' => ['{"object":{"message":[]}}'],
			'boolean content' => ['{"object":{"content":true}}'],
		];
	}

	public function testMaximumEmojiMessageAndQuotedParentFitTheTransportBudget(): void {
		$text = str_repeat('😀', 32000);
		$note = [
			'id' => '42',
			'type' => 'Note',
			'name' => 'message',
			'content' => json_encode(['message' => $text, 'parameters' => []], JSON_THROW_ON_ERROR),
			'mediaType' => 'text/markdown',
		];
		$body = json_encode([
			'type' => 'Create',
			'actor' => ['id' => 'users/alice'],
			'target' => ['id' => 'room-token'],
			'object' => $note + [
				'inReplyTo' => ['type' => 'Note', 'actor' => ['id' => 'users/bob'], 'object' => $note],
			],
		], JSON_THROW_ON_ERROR);
		$this->assertLessThan(TalkWebhookPayload::MAX_BODY_BYTES, strlen($body));
		$this->assertGreaterThan(800000, strlen($body));
		$payload = TalkWebhookPayload::decode($body);
		$message = (new TalkMessageParser(new TalkAttachmentNormalizer()))->parse($payload);
		$this->assertSame($text, $message->getText());
		$this->assertSame(42, $message->getInReplyTo());
	}

	public function testStructuredContentPreservesRawDataWithoutArrayToStringConversion(): void {
		$payload = TalkWebhookPayload::decode('{"object":{"content":{"message":"hello","parameters":[]}}}');
		$message = (new TalkMessageParser(new TalkAttachmentNormalizer()))->parse($payload);
		$this->assertSame('hello', $message->getText());
		$this->assertSame($payload['object']['content'], json_decode($message->getRawText(), true, 512, JSON_THROW_ON_ERROR));
	}

	public function testJoinNotificationWithoutTargetRemainsSupported(): void {
		$payload = TalkWebhookPayload::decode('{"type":"Join","actor":{"id":"bot"},"object":{"id":"room-token","name":"Room"}}');
		$this->assertSame('Join', $payload['type']);
	}
}
