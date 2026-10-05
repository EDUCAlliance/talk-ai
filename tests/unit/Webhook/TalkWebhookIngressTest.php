<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Webhook;

use OCA\EducAI\Exception\InvalidWebhookException;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\OnboardingService;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Webhook\TalkHandler;
use OCA\EducAI\Webhook\TalkMessageParser;
use OCA\EducAI\Webhook\TalkWebhookPayload;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkWebhookIngressTest extends TestCase {
	#[DataProvider('invalidSignedBodies')]
	public function testSignedInvalidPayloadIsRejectedBeforeParserAndBotCalls(string $body): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getWebhookSecret')->willReturn('test-webhook-secret');
		$handler = $this->handler($settings);
		try {
			$handler->handleIncoming([
				'body' => $body,
				'random' => 'test-nonce',
				'signature' => hash_hmac('sha256', 'test-nonce' . $body, 'test-webhook-secret'),
			]);
			$this->fail('Expected the signed malformed payload to be rejected');
		} catch (InvalidWebhookException $e) {
			$this->assertSame(400, $e->getStatusCode());
		}
	}

	public static function invalidSignedBodies(): array {
		return [
			'scalar root formerly TypeError' => ['true'],
			'scalar object formerly TypeError' => ['{"object":"hello"}'],
			'malformed JSON' => ['{"object":'],
		];
	}

	public function testInvalidSignatureIsRejectedBeforeParsingMalformedJson(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getWebhookSecret')->willReturn('test-webhook-secret');
		try {
			$this->handler($settings)->handleIncoming(['body' => '{', 'signature' => 'wrong', 'random' => 'test-nonce']);
			$this->fail('Expected an invalid signature to be rejected');
		} catch (InvalidWebhookException $e) {
			$this->assertSame(401, $e->getStatusCode());
		}
	}

	public function testOversizedDirectCallIsRejectedBeforeSignatureWork(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getWebhookSecret');
		try {
			$this->handler($settings)->handleIncoming(['body' => str_repeat('x', TalkWebhookPayload::MAX_BODY_BYTES + 1)]);
			$this->fail('Expected oversized direct input to be rejected');
		} catch (InvalidWebhookException $e) {
			$this->assertSame(413, $e->getStatusCode());
		}
	}

	private function handler(SettingsService $settings): TalkHandler {
		$parser = $this->createMock(TalkMessageParser::class);
		$parser->expects($this->never())->method('parse');
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->never())->method('processMessage');
		return new TalkHandler(
			$botService,
			$settings,
			$this->createMock(OnboardingService::class),
			$parser,
			$this->createMock(RoomDocumentIngestionService::class),
			$this->createMock(RoomImageIngestionService::class),
			$this->createMock(IClientService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
