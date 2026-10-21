<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Webhook;

use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\OnboardingService;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\TraceService;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCA\EducAI\Webhook\IncomingTalkMessage;
use OCA\EducAI\Webhook\TalkHandler;
use OCA\EducAI\Webhook\TalkMessageParser;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkHandlerTest extends TestCase {
	public function testIncompleteResponsesKeepStatusWithoutDuplicateDelivery(): void {
		foreach ([true, false] as $streamed) {
			$bot = new \OCA\EducAI\Db\Bot();
			$bot->setId(7);
			$room = new \OCA\EducAI\Db\ChatRoom();
			$room->setOnboardingStatus('completed');
			$answer = $streamed ? 'Partial answer. Output limit reached.' : 'No usable response. Output limit reached.';
			$botService = $this->createMock(BotService::class);
			$botService->expects($this->once())->method('processMessage')->willReturnCallback(function (...$arguments) use ($streamed, $answer): string {
				$this->assertIsCallable($arguments[15]);
				$arguments[15]('length');
				if ($streamed) {
					$arguments[5]($answer);
				}
				return $answer;
			});
			$onboarding = $this->createMock(OnboardingService::class);
			$onboarding->method('buildOnboardingContext')->willReturn('');
			$trace = $this->createMock(TraceService::class);
			$trace->method('startRun')->willReturn(90);
			$trace->expects($this->once())->method('finishRun')->with(90, 'incomplete', 'Output limit reached (length)');
			$handler = $this->getMockBuilder(TalkHandler::class)->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboarding,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
				$trace,
			])->onlyMethods(['sendReplyToTalkWithOutcome'])->getMock();
			$handler->expects($this->once())->method('sendReplyToTalkWithOutcome')->with('room-a', $answer, 1234)
				->willReturn(['status' => TalkHandler::DELIVERY_SUCCESS, 'error' => null, 'http_status' => 201]);
			$this->invokePrivateMethod($handler, 'processNormalMessage', [$bot, $room, 'room-a', 'alice', 'Question', 1234]);
		}
	}

	public function testCallerSuppliedReferenceIdIsStableWhileSigningNonceRemainsValid(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getWebhookSecret')->willReturn('talk-secret');
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->with('')->willReturn('http://nextcloud.local/');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(201);
		$referenceIds = [];
		$nonces = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function (string $endpoint, array $options) use ($response, &$referenceIds, &$nonces): IResponse {
				$this->assertSame('http://nextcloud.local/ocs/v2.php/apps/spreed/api/v1/bot/room-token/message', $endpoint);
				$body = json_decode((string)$options['body'], true, 512, JSON_THROW_ON_ERROR);
				$referenceIds[] = $body['referenceId'] ?? null;
				$this->assertSame('Queued answer.', $body['message'] ?? null);
				$this->assertSame(123, $body['replyTo'] ?? null);
				$nonce = (string)$options['headers']['X-Nextcloud-Talk-Bot-Random'];
				$nonces[] = $nonce;
				$this->assertSame(
					hash_hmac('sha256', $nonce . 'Queued answer.', 'talk-secret'),
					$options['headers']['X-Nextcloud-Talk-Bot-Signature']
				);
				return $response;
			});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$handler = $this->createTalkHandler(
			settingsService: $settingsService,
			clientService: $clientService,
			urlGenerator: $urlGenerator,
		);

		$this->assertTrue($handler->sendReplyToTalk('room-token', 'Queued answer.', 123, 'stable-reference'));
		$this->assertTrue($handler->sendReplyToTalk('room-token', 'Queued answer.', 123, 'stable-reference'));
		$this->assertSame(['stable-reference', 'stable-reference'], $referenceIds);
		$this->assertCount(2, array_unique($nonces));
	}

	#[DataProvider('talkHttpOutcomeProvider')]
	public function testTalkHttpStatusIsClassifiedForQueueDelivery(int $httpStatus, string $expectedOutcome): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getWebhookSecret')->willReturn('talk-secret');
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($httpStatus);
		$response->method('getBody')->willReturn('');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('http://nextcloud.local/');

		$outcome = $this->createTalkHandler(
			settingsService: $settingsService,
			clientService: $clientService,
			urlGenerator: $urlGenerator,
		)->sendReplyToTalkWithOutcome('room-token', 'Queued answer.');

		$this->assertSame($expectedOutcome, $outcome['status']);
		$this->assertSame($httpStatus, $outcome['http_status']);
		$this->assertNotNull($outcome['error']);
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function talkHttpOutcomeProvider(): array {
		return [
			'request timeout' => [408, TalkHandler::DELIVERY_RETRYABLE],
			'too early' => [425, TalkHandler::DELIVERY_RETRYABLE],
			'rate limit' => [429, TalkHandler::DELIVERY_RETRYABLE],
			'server failure' => [503, TalkHandler::DELIVERY_RETRYABLE],
			'bad request' => [400, TalkHandler::DELIVERY_PERMANENT],
		];
	}

	#[DataProvider('talkHttpOutcomeProvider')]
	public function testThrownTalkHttpResponseIsClassifiedFromExceptionChain(int $httpStatus, string $expectedOutcome): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getWebhookSecret')->willReturn('talk-secret');
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($httpStatus);
		$httpException = new class($response) extends \RuntimeException {
			public function __construct(
				private IResponse $response,
			) {
				parent::__construct('HTTP request failed');
			}

			public function hasResponse(): bool {
				return true;
			}

			public function getResponse(): IResponse {
				return $this->response;
			}
		};
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \RuntimeException('wrapped HTTP failure', 0, $httpException));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('http://nextcloud.local/');

		$outcome = $this->createTalkHandler(
			settingsService: $settingsService,
			clientService: $clientService,
			urlGenerator: $urlGenerator,
		)->sendReplyToTalkWithOutcome('room-token', 'Queued answer.');

		$this->assertSame($expectedOutcome, $outcome['status']);
		$this->assertSame($httpStatus, $outcome['http_status']);
		$this->assertNotNull($outcome['error']);
	}

	public function testTalkTransportExceptionHasExplicitAmbiguousOutcome(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getWebhookSecret')->willReturn('talk-secret');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \RuntimeException('timeout'));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('http://nextcloud.local/');

		$outcome = $this->createTalkHandler(
			settingsService: $settingsService,
			clientService: $clientService,
			urlGenerator: $urlGenerator,
		)->sendReplyToTalkWithOutcome('room-token', 'Queued answer.');

		$this->assertSame(TalkHandler::DELIVERY_AMBIGUOUS, $outcome['status']);
		$this->assertNull($outcome['http_status']);
		$this->assertStringContainsString('unknown', (string)$outcome['error']);
	}

	public function testToolProgressPartialBypassesOpenThinkingBlock(): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$sentMessages = [];

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments): string {
				$assistantProgress = $arguments[5] ?? null;
				$toolProgress = $arguments[14] ?? null;
				$this->assertIsCallable($assistantProgress);
				$this->assertIsCallable($toolProgress);
				$assistantProgress('<think>I should transcribe the voice note');
				$toolProgress('🔧 _Using tool: attachment_transcribe_audio..._');

				return 'Final audio answer.';
			});

		$onboardingService = $this->createMock(OnboardingService::class);
		$onboardingService->method('buildOnboardingContext')->willReturn('');

		$handler = $this->getMockBuilder(TalkHandler::class)
			->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboardingService,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
			])
			->onlyMethods(['sendReplyToTalkWithOutcome'])
			->getMock();
		$handler->method('sendReplyToTalkWithOutcome')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$sentMessages): array {
				$sentMessages[] = $message;
				return ['status' => TalkHandler::DELIVERY_SUCCESS, 'error' => null, 'http_status' => 201];
			});

		$this->invokePrivateMethod($handler, 'processNormalMessage', [
			$bot,
			$room,
			'room-a',
			'alice',
			'Please transcribe the uploaded audio attachment and respond based on its spoken content.',
			1234,
			null,
			null,
			[
				'attachments' => [],
				'document_source_ids' => [],
				'image_source_ids' => [],
				'attachment_only' => true,
			],
			null,
		]);

		$this->assertContains('🔧 _Using tool: attachment_transcribe_audio..._', $sentMessages);
		$this->assertContains('Final audio answer.', $sentMessages);
	}

	public function testStreamedTerminalContentIsNotDuplicatedByFinalReturn(): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$sentMessages = [];

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments): string {
				$progressCallback = $arguments[5] ?? null;
				$this->assertIsCallable($progressCallback);
				$progressCallback('Final coalesced answer.');

				return 'Final coalesced answer.';
			});

		$onboardingService = $this->createMock(OnboardingService::class);
		$onboardingService->method('buildOnboardingContext')->willReturn('');
		$handler = $this->getMockBuilder(TalkHandler::class)
			->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboardingService,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
			])
			->onlyMethods(['sendReplyToTalkWithOutcome'])
			->getMock();
		$handler->expects($this->once())
			->method('sendReplyToTalkWithOutcome')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$sentMessages): array {
				$sentMessages[] = $message;
				return ['status' => TalkHandler::DELIVERY_SUCCESS, 'error' => null, 'http_status' => 201];
			});

		$this->invokePrivateMethod($handler, 'processNormalMessage', [
			$bot,
			$room,
			'room-a',
			'alice',
			'Hello',
			1234,
		]);

		$this->assertSame(['Final coalesced answer.'], $sentMessages);
	}

	public function testAssistantFinalMatchingToolProgressPrefixIsSentExactlyOnce(): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$assistantAnswer = '🔧 _Using tool: this_is_the_final_answer..._';
		$sentMessages = [];

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments) use ($assistantAnswer): string {
				$assistantProgress = $arguments[5] ?? null;
				$toolProgress = $arguments[14] ?? null;
				$this->assertIsCallable($assistantProgress);
				$this->assertIsCallable($toolProgress);
				$assistantProgress($assistantAnswer);

				return $assistantAnswer;
			});

		$onboardingService = $this->createMock(OnboardingService::class);
		$onboardingService->method('buildOnboardingContext')->willReturn('');
		$handler = $this->getMockBuilder(TalkHandler::class)
			->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboardingService,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
			])
			->onlyMethods(['sendReplyToTalkWithOutcome'])
			->getMock();
		$handler->expects($this->once())
			->method('sendReplyToTalkWithOutcome')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$sentMessages): array {
				$sentMessages[] = $message;
				return ['status' => TalkHandler::DELIVERY_SUCCESS, 'error' => null, 'http_status' => 201];
			});

		$this->invokePrivateMethod($handler, 'processNormalMessage', [
			$bot,
			$room,
			'room-a',
			'alice',
			'Hello',
			1234,
		]);

		$this->assertSame([$assistantAnswer], $sentMessages);
	}

	#[DataProvider('canonicalRetryOutcomes')]
	public function testFailedFirstAssistantPartialRetriesCanonicalFinalWithTruthfulTrace(bool $retryDelivered, string $expectedTraceStatus): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$attempts = [];
		$deliveryResults = [false, $retryDelivered];

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments): string {
				$progressCallback = $arguments[5] ?? null;
				$this->assertIsCallable($progressCallback);
				$progressCallback('First streamed chunk.');

				return 'Canonical full answer.';
			});

		$onboardingService = $this->createMock(OnboardingService::class);
		$onboardingService->method('buildOnboardingContext')->willReturn('');
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(72);
		$traceService->expects($this->once())->method('finishRun')->with(72, $expectedTraceStatus, null);

		$handler = $this->getMockBuilder(TalkHandler::class)
			->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboardingService,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
				$traceService,
			])
			->onlyMethods(['sendReplyToTalkWithOutcome'])
			->getMock();
		$handler->expects($this->exactly(2))
			->method('sendReplyToTalkWithOutcome')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$attempts, &$deliveryResults): array {
				$attempts[] = ['message' => $message, 'reply_to' => $replyToId];
				$delivered = array_shift($deliveryResults);
				return ['status' => $delivered ? TalkHandler::DELIVERY_SUCCESS : TalkHandler::DELIVERY_RETRYABLE, 'error' => $delivered ? null : 'Delivery failed', 'http_status' => null];
			});

		$this->invokePrivateMethod($handler, 'processNormalMessage', [
			$bot,
			$room,
			'room-a',
			'alice',
			'Hello',
			1234,
		]);

		$this->assertSame([
			['message' => 'First streamed chunk.', 'reply_to' => 1234],
			['message' => 'Canonical full answer.', 'reply_to' => 1234],
		], $attempts);
	}

	/**
	 * @return array<string,array{bool,string}>
	 */
	public static function canonicalRetryOutcomes(): array {
		return [
			'successful recovery' => [true, 'success'],
			'failed recovery' => [false, 'partial'],
		];
	}

	public function testLaterFailedAssistantPartialForcesCanonicalRetryAfterEarlierSuccess(): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$attempts = [];
		$deliveryResults = [true, false, true];
		$canonicalAnswer = 'First delivered chunk. Second recovered chunk.';

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments) use ($canonicalAnswer): string {
				$progressCallback = $arguments[5] ?? null;
				$this->assertIsCallable($progressCallback);
				$progressCallback('First delivered chunk.');
				$progressCallback('Second lost chunk.');

				return $canonicalAnswer;
			});

		$onboardingService = $this->createMock(OnboardingService::class);
		$onboardingService->method('buildOnboardingContext')->willReturn('');
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(73);
		$traceService->expects($this->once())->method('finishRun')->with(73, 'success', null);

		$handler = $this->getMockBuilder(TalkHandler::class)
			->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboardingService,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
				$traceService,
			])
			->onlyMethods(['sendReplyToTalkWithOutcome'])
			->getMock();
		$handler->expects($this->exactly(3))
			->method('sendReplyToTalkWithOutcome')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$attempts, &$deliveryResults): array {
				$attempts[] = ['message' => $message, 'reply_to' => $replyToId];
				$delivered = array_shift($deliveryResults);
				return ['status' => $delivered ? TalkHandler::DELIVERY_SUCCESS : TalkHandler::DELIVERY_RETRYABLE, 'error' => $delivered ? null : 'Delivery failed', 'http_status' => null];
			});

		$this->invokePrivateMethod($handler, 'processNormalMessage', [
			$bot,
			$room,
			'room-a',
			'alice',
			'Hello',
			1234,
		]);

		$this->assertSame([
			['message' => 'First delivered chunk.', 'reply_to' => 1234],
			['message' => 'Second lost chunk.', 'reply_to' => 0],
			['message' => $canonicalAnswer, 'reply_to' => 0],
		], $attempts);
	}

	public function testCanonicalMismatchFinishesTraceAsPartialAfterCanonicalContentIsDelivered(): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$attempts = [];
		$deliveryResults = [true, true];

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments): string {
				$progressCallback = $arguments[5] ?? null;
				$mismatchCallback = $arguments[13] ?? null;
				$this->assertIsCallable($progressCallback);
				$this->assertIsCallable($mismatchCallback);
				$progressCallback('Draft');
				$mismatchCallback();
				$progressCallback('Canonical');

				return 'Canonical';
			});

		$onboardingService = $this->createMock(OnboardingService::class);
		$onboardingService->method('buildOnboardingContext')->willReturn('');
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(75);
		$traceService->expects($this->once())
			->method('finishRun')
			->with(75, 'partial', 'Streamed response differed from terminal content');

		$handler = $this->getMockBuilder(TalkHandler::class)
			->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboardingService,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
				$traceService,
			])
			->onlyMethods(['sendReplyToTalkWithOutcome'])
			->getMock();
		$handler->expects($this->exactly(2))
			->method('sendReplyToTalkWithOutcome')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$attempts, &$deliveryResults): array {
				$attempts[] = ['message' => $message, 'reply_to' => $replyToId];
				$delivered = array_shift($deliveryResults);
				return ['status' => $delivered ? TalkHandler::DELIVERY_SUCCESS : TalkHandler::DELIVERY_RETRYABLE, 'error' => $delivered ? null : 'Delivery failed', 'http_status' => null];
			});

		$this->invokePrivateMethod($handler, 'processNormalMessage', [
			$bot,
			$room,
			'room-a',
			'alice',
			'Hello',
			1234,
		]);

		$this->assertSame([
			['message' => 'Draft', 'reply_to' => 1234],
			['message' => 'Canonical', 'reply_to' => 0],
		], $attempts);
	}

	public function testFailedToolProgressDoesNotMoveFirstReplyTargetOrDuplicateCanonicalAnswer(): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$attempts = [];
		$deliveryResults = [false, true];

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments): string {
				$assistantProgress = $arguments[5] ?? null;
				$toolProgress = $arguments[14] ?? null;
				$this->assertIsCallable($assistantProgress);
				$this->assertIsCallable($toolProgress);
				$toolProgress('🔧 _Using tool: search_test..._');
				$assistantProgress('Canonical answer.');

				return 'Canonical answer.';
			});

		$onboardingService = $this->createMock(OnboardingService::class);
		$onboardingService->method('buildOnboardingContext')->willReturn('');
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(74);
		$traceService->expects($this->once())->method('finishRun')->with(74, 'success', null);

		$handler = $this->getMockBuilder(TalkHandler::class)
			->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboardingService,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
				$traceService,
			])
			->onlyMethods(['sendReplyToTalkWithOutcome'])
			->getMock();
		$handler->expects($this->exactly(2))
			->method('sendReplyToTalkWithOutcome')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$attempts, &$deliveryResults): array {
				$attempts[] = ['message' => $message, 'reply_to' => $replyToId];
				$delivered = array_shift($deliveryResults);
				return ['status' => $delivered ? TalkHandler::DELIVERY_SUCCESS : TalkHandler::DELIVERY_RETRYABLE, 'error' => $delivered ? null : 'Delivery failed', 'http_status' => null];
			});

		$this->invokePrivateMethod($handler, 'processNormalMessage', [
			$bot,
			$room,
			'room-a',
			'alice',
			'Hello',
			1234,
		]);

		$this->assertSame([
			['message' => '🔧 _Using tool: search_test..._', 'reply_to' => 1234],
			['message' => 'Canonical answer.', 'reply_to' => 1234],
		], $attempts);
	}

	public function testTypedAgentFailureAfterProgressSendsOneSafeTerminalAndFinishesTraceAsError(): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$bot->setMentionName('@safe-bot');
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$sentMessages = [];
		$safeResponse = "Sorry, I'm having trouble connecting to the AI service right now. Please try again later.";

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments) use ($safeResponse): string {
				$assistantProgress = $arguments[5] ?? null;
				$errorCallback = $arguments[12] ?? null;
				$mismatchCallback = $arguments[13] ?? null;
				$toolProgress = $arguments[14] ?? null;
				$this->assertIsCallable($assistantProgress);
				$this->assertIsCallable($errorCallback);
				$this->assertIsCallable($mismatchCallback);
				$this->assertIsCallable($toolProgress);
				$assistantProgress('Partial answer already visible.');
				$toolProgress('🔧 _Using tool: search_test..._');
				$mismatchCallback();
				$errorCallback('Agent execution terminated: max_turns');

				return $safeResponse;
			});

		$onboardingService = $this->createMock(OnboardingService::class);
		$onboardingService->method('buildOnboardingContext')->willReturn('');
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())
			->method('startRun')
			->willReturn(71);
		$traceService->expects($this->once())
			->method('finishRun')
			->with(71, 'error', 'Agent execution terminated: max_turns');

		$handler = $this->getMockBuilder(TalkHandler::class)
			->setConstructorArgs([
				$botService,
				$this->createMock(SettingsService::class),
				$onboardingService,
				$this->createMock(TalkMessageParser::class),
				$this->createMock(RoomDocumentIngestionService::class),
				$this->createMock(RoomImageIngestionService::class),
				$this->createMock(IClientService::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
				$traceService,
			])
			->onlyMethods(['sendReplyToTalkWithOutcome'])
			->getMock();
		$handler->expects($this->exactly(3))
			->method('sendReplyToTalkWithOutcome')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$sentMessages): array {
				$sentMessages[] = $message;
				return ['status' => TalkHandler::DELIVERY_SUCCESS, 'error' => null, 'http_status' => 201];
			});

		$this->invokePrivateMethod($handler, 'processNormalMessage', [
			$bot,
			$room,
			'room-a',
			'alice',
			'Hello',
			1234,
		]);

		$this->assertSame([
			'Partial answer already visible.',
			'🔧 _Using tool: search_test..._',
			$safeResponse,
		], $sentMessages);
		$this->assertSame(1, count(array_filter(
			$sentMessages,
			static fn (string $message): bool => $message === $safeResponse
		)));
		$this->assertStringNotContainsString('max_turns', implode("\n", $sentMessages));
	}

	public function testBuildMessageContextIngestsImageForRoomImageMemory(): void {
		$botService = $this->createMock(BotService::class);
		$roomImageIngestionService = $this->createMock(RoomImageIngestionService::class);
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$attachment = new IncomingTalkAttachment(
			IncomingTalkAttachment::KIND_IMAGE,
			'file',
			'image/png',
			'screenshot.png',
			'file',
			['fileId' => 42]
		);
		$message = new IncomingTalkMessage('', '', 'room-a', 'alice', 1234, null, [$attachment]);
		$source = new \OCA\EducAI\Db\RoomImageSource();
		$source->setId(21);

		$botService->expects($this->once())
			->method('getEffectiveBuiltInToolNames')
			->with($bot, 'alice')
			->willReturn(['attachment_analyze_image', 'room_search_images']);
		$roomImageIngestionService->expects($this->once())
			->method('ingestAttachment')
			->with(7, 'room-a', 'alice', 1234, $attachment)
			->willReturn($source);

		$handler = $this->createTalkHandler(
			botService: $botService,
			roomImageIngestionService: $roomImageIngestionService
		);

		$result = $this->invokePrivateMethod($handler, 'buildMessageContext', [
			$bot,
			'room-a',
			'alice',
			1234,
			$message,
		]);

		$this->assertNull($result['capability_error']);
		$this->assertSame([21], $result['context']['image_source_ids']);
		$this->assertSame('screenshot.png', $result['context']['attachments'][0]->getDisplayName());
	}

	public function testResetCommandDeletesRoomImageMemory(): void {
		$botService = $this->createMock(BotService::class);
		$onboardingService = $this->createMock(OnboardingService::class);
		$roomDocumentIngestionService = $this->createMock(RoomDocumentIngestionService::class);
		$roomImageIngestionService = $this->createMock(RoomImageIngestionService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$bot->setMentionName('@visualbot');

		$botService->expects($this->once())
			->method('findByMentionName')
			->with('@visualbot')
			->willReturn($bot);
		$botService->expects($this->once())
			->method('userCanAccessBot')
			->with($bot, 'alice')
			->willReturn(true);
		$onboardingService->expects($this->once())
			->method('resetRoom')
			->with(7, 'room-a')
			->willReturn(true);
		$roomDocumentIngestionService->expects($this->once())
			->method('deleteRoomDocuments')
			->with(7, 'room-a');
		$roomImageIngestionService->expects($this->once())
			->method('deleteRoomImages')
			->with(7, 'room-a');
		$settingsService->method('getWebhookSecret')->willReturn('');

		$handler = $this->createTalkHandler(
			botService: $botService,
			settingsService: $settingsService,
			onboardingService: $onboardingService,
			roomDocumentIngestionService: $roomDocumentIngestionService,
			roomImageIngestionService: $roomImageIngestionService
		);

		$this->invokePrivateMethod($handler, 'handleResetCommand', [
			'room-a',
			'alice',
			1234,
			'((RESET)) @visualbot',
		]);
	}

	public function testReplyTargetUsesThreadParentWhenPresent(): void {
		$handler = $this->createTalkHandler();
		$message = new IncomingTalkMessage('Hi', 'Hi', 'room-a', 'alice', 100, 42);

		$result = $this->invokePrivateMethod($handler, 'resolveReplyTargetId', [$message]);

		$this->assertSame(42, $result);
	}

	public function testReplyTargetFallsBackToCurrentMessage(): void {
		$handler = $this->createTalkHandler();
		$message = new IncomingTalkMessage('Hi', 'Hi', 'room-a', 'alice', 100);

		$result = $this->invokePrivateMethod($handler, 'resolveReplyTargetId', [$message]);

		$this->assertSame(100, $result);
	}

	public function testThreadRootUsesExplicitThreadRoot(): void {
		$handler = $this->createTalkHandler();
		$threadMessage = new IncomingTalkMessage('Hi', 'Hi', 'room-a', 'alice', 100, 42, [], 42);
		$roomMessage = new IncomingTalkMessage('Hi', 'Hi', 'room-a', 'alice', 101);

		$this->assertSame(42, $this->invokePrivateMethod($handler, 'resolveThreadRootMessageId', [$threadMessage]));
		$this->assertNull($this->invokePrivateMethod($handler, 'resolveThreadRootMessageId', [$roomMessage]));
	}

	public function testThreadContextRepliesToCurrentMessageAndScopesHistory(): void {
		$handler = $this->createTalkHandler();
		$message = new IncomingTalkMessage('Hi', 'Hi', 'room-a', 'alice', 100, 42, [], 42);

		$result = $this->invokePrivateMethod($handler, 'resolveTalkThreadContext', [$message]);

		$this->assertSame([
			'reply_target_id' => 100,
			'thread_root_message_id' => 42,
		], $result);
	}

	public function testThreadStreamingKeepsReplyTargetAfterFirstChunk(): void {
		$handler = $this->createTalkHandler();

		$this->assertSame(100, $this->invokePrivateMethod($handler, 'resolveStreamingReplyTarget', [true, 100, 42]));
		$this->assertSame(100, $this->invokePrivateMethod($handler, 'resolveStreamingReplyTarget', [false, 100, 42]));
		$this->assertSame(100, $this->invokePrivateMethod($handler, 'resolveStreamingReplyTarget', [true, 100, null]));
		$this->assertSame(0, $this->invokePrivateMethod($handler, 'resolveStreamingReplyTarget', [false, 100, null]));
	}

	public function testHandleIncomingIgnoresThreadCreatedSystemEvent(): void {
		$secret = 'test-webhook-secret';
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getWebhookSecret')->willReturn($secret);

		$parser = $this->createMock(TalkMessageParser::class);
		$parser->expects($this->never())->method('parse');

		$handler = $this->createTalkHandler(settingsService: $settingsService, talkMessageParser: $parser);
		$payload = [
			'type' => 'Activity',
			'actor' => ['id' => 'users/admin'],
			'object' => [
				'id' => '1399',
				'name' => 'thread_created',
				'content' => json_encode([
					'message' => '{actor} created thread {title}',
					'parameters' => [],
				], JSON_THROW_ON_ERROR),
			],
			'target' => ['id' => 'h2snwe6a'],
		];

		$body = json_encode($payload, JSON_THROW_ON_ERROR);
		$random = 'random-nonce-1234567890';
		$handler->handleIncoming([
			'body' => $body,
			'signature' => hash_hmac('sha256', $random . $body, $secret),
			'random' => $random,
		]);
	}

	#[DataProvider('attachmentMessageModes')]
	public function testEmptyDocumentNoticeReachesModelWhileOtherDocumentsAreIndexed(bool $always, bool $attachmentOnly): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$bot->setMentionName('@bot');
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$bots = $this->createMock(BotService::class);
		$bots->method('userCanAccessBot')->willReturn(true);
		$bots->method('getEffectiveBotForUser')->willReturn($bot);
		$bots->method('getEffectiveBuiltInToolNames')->willReturn(['room_search_documents']);
		$bots->expects($this->once())->method('processMessage')->willReturnCallback(function (...$arguments) use ($attachmentOnly): string {
			$this->assertStringContainsString('contained no readable text', $arguments[1]);
			$this->assertStringContainsString('no-text.pdf', $arguments[1]);
			$this->assertStringNotContainsString('/srv/private', $arguments[1]);
			$this->assertSame([34], $arguments[8]['document_source_ids']);
			$this->assertSame($attachmentOnly, $arguments[8]['attachment_only']);
			$this->assertCount(1, $arguments[8]['attachments']);
			$this->assertSame('useful.pdf', $arguments[8]['attachments'][0]->getDisplayName());
			return 'The first document has no readable text.';
		});
		$onboarding = $this->createMock(OnboardingService::class);
		$onboarding->method('getRoomState')->willReturn($room);
		$onboarding->method('getOnboardingInProgressBotsForRoom')->willReturn([]);
		$onboarding->method('getAlwaysModeBotsForRoom')->willReturn([['bot' => $bot, 'room' => $room]]);
		$onboarding->method('buildOnboardingContext')->willReturn('');
		$docs = $this->createMock(RoomDocumentIngestionService::class);
		$docs->expects($this->exactly(2))->method('ingestAttachment')->willReturnCallback(static function (...$arguments): \OCA\EducAI\Db\RoomDocumentSource {
			$source = new \OCA\EducAI\Db\RoomDocumentSource();
			$empty = $arguments[4]->getDisplayName() === 'no-text.pdf';
			$source->setId($empty ? 33 : 34);
			$source->setStatus($empty ? 'empty' : 'ready');
			return $source;
		});
		$handler = $this->getMockBuilder(TalkHandler::class)->setConstructorArgs([
			$bots, $this->createMock(SettingsService::class), $onboarding,
			$this->createMock(TalkMessageParser::class), $docs,
			$this->createMock(RoomImageIngestionService::class), $this->createMock(IClientService::class),
			$this->createMock(IDBConnection::class), $this->createMock(IURLGenerator::class), $this->createMock(LoggerInterface::class),
		])->onlyMethods(['sendReplyToTalkWithOutcome'])->getMock();
		$handler->method('sendReplyToTalkWithOutcome')->willReturn(['status' => TalkHandler::DELIVERY_SUCCESS, 'error' => null, 'http_status' => 201]);
		$attachments = [];
		foreach (['._document.pdf', 'no-text.pdf', 'useful.pdf'] as $name) {
			$attachments[] = new IncomingTalkAttachment('document', 'file', 'application/pdf', $name, 'file');
		}
		$text = $attachmentOnly ? '' : 'Summarize these documents.';
		$message = new IncomingTalkMessage($text, $text, 'room', 'alice', 5, null, $attachments);
		$arguments = ['room', 'alice', $text, 5, 5, null, $message];
		if (!$always) {
			array_unshift($arguments, $bot);
		}
		$this->invokePrivateMethod($handler, $always ? 'handleAlwaysModeMessage' : 'handleMentionedBot', $arguments);
	}

	public static function attachmentMessageModes(): array {
		return [[true, true], [true, false], [false, true], [false, false]];
	}

	public function testRoomIndexingErrorsAreLocalizedWithoutRawExceptionText(): void {
		foreach ([false, true] as $image) {
			foreach ([false, true] as $rateLimited) {
				$builder = $this->getMockBuilder(IL10N::class);
				if (!method_exists(IL10N::class, 't')) {
					$builder->addMethods(['t']);
				}
				$l10n = $builder->getMock();
				$expected = $rateLimited
					? 'Attachment indexing is temporarily rate limited. Please try again in %s seconds.'
					: ($image ? 'The uploaded image could not be indexed. Please try uploading it again later.' : 'The uploaded document could not be indexed. Please try uploading it again later.');
				$translation = $l10n->expects($this->once())->method('t');
				if ($rateLimited) {
					$translation->with($expected, [120]);
				} else {
					$translation->with($expected);
				}
				$translation->willReturn('Sichere übersetzte Fehlermeldung');
				$handler = $this->createTalkHandler(l10n: $l10n);
				$failure = $rateLimited ? new \OCA\EducAI\Exception\EmbeddingRateLimitedException(120) : new \RuntimeException('/srv/private/secret');
				$this->assertSame('Sichere übersetzte Fehlermeldung', $this->invokePrivateMethod($handler, 'roomIngestionError', [$failure, $image]));
			}
		}
	}

	#[DataProvider('standaloneMentionMessages')]
	public function testDetectBotAcceptsStandaloneInvocations(string $message, string $mentionName): void {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setIsActive(true);
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())->method('findByMentionName')->with($mentionName)->willReturn($bot);
		$this->assertSame($bot, $this->invokePrivateMethod($this->createTalkHandler(botService: $botService), 'detectBot', [$message]));
	}

	public static function standaloneMentionMessages(): array {
		return [
			'at at start' => ['@docs hello', '@docs'],
			'slash at start' => ['/docs hello', '@docs'],
			'middle' => ['Hello @docs please help', '@docs'],
			'end' => ['Hello @docs', '@docs'],
			'only' => ['@docs', '@docs'],
			'newline' => ["Hello\n/docs\tplease help", '@docs'],
			'Unicode whitespace' => ["Hallo\u{00a0}@docs", '@docs'],
			'parentheses' => ['Hello (@docs), please help', '@docs'],
			'quotes' => ['“@docs”: hello', '@docs'],
			'smart picker name' => ['/my_bot-2 hello', '@my_bot-2'],
			'markdown strong' => ['**@docs** hello', '@docs'],
			'punctuation' => ['Please @docs!?', '@docs'],
			'period' => ['Ask @docs.', '@docs'],
			'preserve registered case' => ['/Docs hello', '@Docs'],
			'reset' => ['((RESET)) @docs', '@docs'],
		];
	}

	#[DataProvider('nonMentionMessages')]
	public function testLinksEmailsAndPathsNeitherActivateNorLoseText(string $message): void {
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->never())->method('findByMentionName');
		$handler = $this->createTalkHandler(botService: $botService);
		$this->assertNull($this->invokePrivateMethod($handler, 'detectBot', [$message]));
		$this->assertSame($message, $this->invokePrivateMethod($handler, 'removeBotMention', [$message, '@docs']));
	}

	public static function nonMentionMessages(): array {
		return [
			'URL path' => ['Read https://example.invalid/docs/quickstart'],
			'URL trailing path' => ['Read https://example.invalid/docs'],
			'URL prefix' => ['Read https://example.invalid/docs-other'],
			'URL userinfo' => ['https://user@docs.example/path'],
			'URL query' => ['https://example.invalid/?q=/docs'],
			'URL query mention' => ['https://example.invalid/?q=@docs'],
			'URL fragment' => ['https://example.invalid/#@docs'],
			'URL punctuation' => ['https://example.invalid/(@docs)'],
			'protocol relative' => ['//docs.example/path'],
			'autolink' => ['<https://example.invalid/docs>'],
			'markdown link' => ['[manual](https://example.invalid/docs)'],
			'markdown relative link' => ['[manual](/docs)'],
			'bare domain and path' => ['example.invalid/docs'],
			'email' => ['person@docs.example'],
			'plus email' => ['first.last+tag@docs.example'],
			'email local part' => ['@docs@example.invalid'],
			'domain after at' => ['@docs.example'],
			'local path' => ['/docs/manual.txt'],
			'relative local path' => ['./docs'],
			'home local path' => ['~/docs'],
			'absolute double slash' => ['//docs'],
			'identifier' => ['hello@docs'],
			'Unicode prefix' => ['ä@docs'],
			'Unicode suffix' => ['@docsä'],
			'Unicode suffix after underscore' => ['@docs_ä'],
			'escaped invocation' => ['\\@docs'],
		];
	}

	public function testOnlyMatchingInvocationSpansAreRemoved(): void {
		$handler = $this->createTalkHandler();
		$message = '@docs Vergleiche https://example.invalid/docs mit https://example.invalid/docs-other und person@docs.example. @docs-other /docs_extra bleiben. /docs Ende.';
		$this->assertSame(
			'Vergleiche https://example.invalid/docs mit https://example.invalid/docs-other und person@docs.example. @docs-other /docs_extra bleiben.  Ende.',
			$this->invokePrivateMethod($handler, 'removeBotMention', [$message, '@docs'])
		);
		$this->assertSame('Grüße 🦝 () und ** **', $this->invokePrivateMethod($handler, 'removeBotMention', ['Grüße 🦝 (@docs) und **@docs **', '@docs']));
		$this->assertSame('Frage', $this->invokePrivateMethod($handler, 'removeBotMention', ['/Docs Frage', '@Docs']));
		$this->assertSame('@docs-other /docs_extra', $this->invokePrivateMethod($handler, 'removeBotMention', ['@docs-other /docs_extra', '@docs']));
	}

	#[DataProvider('caseSensitiveMentionMessages')]
	public function testMentionCleanupPreservesDifferentlyCasedBotNames(string $message, string $selectedName, string $expected): void {
		$bots = [];
		foreach (['@docs', '@Docs'] as $name) {
			$bot = new \OCA\EducAI\Db\Bot();
			$bot->setMentionName($name);
			$bot->setIsActive(true);
			$bots[$name] = $bot;
		}
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())->method('findByMentionName')->with($selectedName)->willReturn($bots[$selectedName]);
		$handler = $this->createTalkHandler(botService: $botService);
		$selected = $this->invokePrivateMethod($handler, 'detectBot', [$message]);

		$this->assertSame($bots[$selectedName], $selected);
		$this->assertSame($expected, $this->invokePrivateMethod($handler, 'removeBotMention', [$message, $selected->getMentionName()]));
	}

	public static function caseSensitiveMentionMessages(): array {
		return [
			'lowercase at' => ['@docs Please compare @Docs output.', '@docs', 'Please compare @Docs output.'],
			'uppercase at' => ['@Docs Please compare @docs output.', '@Docs', 'Please compare @docs output.'],
			'lowercase slash' => ['/docs Please compare /Docs output.', '@docs', 'Please compare /Docs output.'],
			'uppercase slash' => ['/Docs Please compare /docs output.', '@Docs', 'Please compare /docs output.'],
			'mixed repeated' => ['@docs Grüße 🦝 (@Docs), /docs /Docs @docs', '@docs', 'Grüße 🦝 (@Docs),  /Docs'],
			'mixed repeated reverse' => ['/Docs Grüße 🦝 (@docs), @Docs /docs /Docs', '@Docs', 'Grüße 🦝 (@docs),  /docs'],
		];
	}

	public function testUnknownAndInactiveMentionsDoNotHideNextActiveBot(): void {
		$inactive = new \OCA\EducAI\Db\Bot();
		$inactive->setIsActive(false);
		$active = new \OCA\EducAI\Db\Bot();
		$active->setIsActive(true);
		$lookups = [];
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->exactly(3))->method('findByMentionName')->willReturnCallback(function (string $name) use ($inactive, $active, &$lookups) {
			$lookups[] = $name;
			if ($name === '@missing') {
				throw new \OCP\AppFramework\Db\DoesNotExistException('No bot');
			}
			return $name === '@inactive' ? $inactive : $active;
		});
		$this->assertSame($active, $this->invokePrivateMethod($this->createTalkHandler(botService: $botService), 'detectBot', ['https://example.invalid/docs @missing /inactive @docs']));
		$this->assertSame(['@missing', '@inactive', '@docs'], $lookups);
	}

	public function testStructuredTalkContentKeepsMentionAndOtherUserPlaceholder(): void {
		$parser = new TalkMessageParser(new \OCA\EducAI\Webhook\TalkAttachmentNormalizer());
		$incoming = $parser->parse([
			'actor' => ['id' => 'alice'],
			'target' => ['id' => 'room-a'],
			'object' => [
				'id' => 999,
				'name' => 'message',
				'content' => json_encode([
					'message' => '@docs Ask {mention-user1} about https://example.invalid/docs',
					'parameters' => ['mention-user1' => ['type' => 'user', 'id' => 'bob', 'name' => 'Bob']],
				], JSON_THROW_ON_ERROR),
			],
		]);
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setIsActive(true);
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())->method('findByMentionName')->with('@docs')->willReturn($bot);
		$handler = $this->createTalkHandler(botService: $botService);
		$this->assertSame($bot, $this->invokePrivateMethod($handler, 'detectBot', [$incoming->getText()]));
		$this->assertSame('Ask {mention-user1} about https://example.invalid/docs', $this->invokePrivateMethod($handler, 'removeBotMention', [$incoming->getText(), '@docs']));
	}

	private function createTalkHandler(
		?BotService $botService = null,
		?SettingsService $settingsService = null,
		?OnboardingService $onboardingService = null,
		?TalkMessageParser $talkMessageParser = null,
		?RoomDocumentIngestionService $roomDocumentIngestionService = null,
		?RoomImageIngestionService $roomImageIngestionService = null,
		?IClientService $clientService = null,
		?IDBConnection $db = null,
		?IURLGenerator $urlGenerator = null,
		?LoggerInterface $logger = null,
		?IL10N $l10n = null,
	): TalkHandler {
		return new TalkHandler(
			$botService ?? $this->createMock(BotService::class),
			$settingsService ?? $this->createMock(SettingsService::class),
			$onboardingService ?? $this->createMock(OnboardingService::class),
			$talkMessageParser ?? $this->createMock(TalkMessageParser::class),
			$roomDocumentIngestionService ?? $this->createMock(RoomDocumentIngestionService::class),
			$roomImageIngestionService ?? $this->createMock(RoomImageIngestionService::class),
			$clientService ?? $this->createMock(IClientService::class),
			$db ?? $this->createMock(IDBConnection::class),
			$urlGenerator ?? $this->createMock(IURLGenerator::class),
			$logger ?? $this->createMock(LoggerInterface::class),
			l10n: $l10n
		);
	}

	/**
	 * @param array<int,mixed> $arguments
	 * @return mixed
	 */
	private function invokePrivateMethod(TalkHandler $handler, string $method, array $arguments) {
		$reflection = new \ReflectionMethod($handler, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs($handler, $arguments);
	}
}
