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
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkHandlerTest extends TestCase {
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

	public function testToolProgressPartialIsProgressOnly(): void {
		$handler = $this->createTalkHandler();

		$result = $this->invokePrivateMethod($handler, 'isProgressOnlyPartial', ['🔧 _Using tool: tavily_search..._']);

		$this->assertTrue($result);
	}

	public function testMultipleToolProgressPartialIsProgressOnly(): void {
		$handler = $this->createTalkHandler();

		$result = $this->invokePrivateMethod($handler, 'isProgressOnlyPartial', ['🔧 _Using tools: tavily_search, tavily_extract..._']);

		$this->assertTrue($result);
	}

	public function testVisibleAssistantPartialIsNotProgressOnly(): void {
		$handler = $this->createTalkHandler();

		$result = $this->invokePrivateMethod($handler, 'isProgressOnlyPartial', ['Hier ist die Antwort aus den Suchergebnissen.']);

		$this->assertFalse($result);
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
			->willReturnCallback(function (
				$bot,
				string $message,
				string $roomToken,
				string $userId,
				?string $originalMessage,
				callable $onProgress,
			): string {
				$onProgress('<think>I should transcribe the voice note');
				$onProgress('🔧 _Using tool: attachment_transcribe_audio..._');

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
			->onlyMethods(['sendReplyToTalk'])
			->getMock();
		$handler->method('sendReplyToTalk')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$sentMessages): bool {
				$sentMessages[] = $message;
				return true;
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
			->onlyMethods(['sendReplyToTalk'])
			->getMock();
		$handler->expects($this->once())
			->method('sendReplyToTalk')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$sentMessages): bool {
				$sentMessages[] = $message;
				return true;
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
			->onlyMethods(['sendReplyToTalk'])
			->getMock();
		$handler->expects($this->exactly(2))
			->method('sendReplyToTalk')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$attempts, &$deliveryResults): bool {
				$attempts[] = ['message' => $message, 'reply_to' => $replyToId];
				return array_shift($deliveryResults);
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
			->onlyMethods(['sendReplyToTalk'])
			->getMock();
		$handler->expects($this->exactly(3))
			->method('sendReplyToTalk')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$attempts, &$deliveryResults): bool {
				$attempts[] = ['message' => $message, 'reply_to' => $replyToId];
				return array_shift($deliveryResults);
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
			->onlyMethods(['sendReplyToTalk'])
			->getMock();
		$handler->expects($this->exactly(2))
			->method('sendReplyToTalk')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$attempts, &$deliveryResults): bool {
				$attempts[] = ['message' => $message, 'reply_to' => $replyToId];
				return array_shift($deliveryResults);
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
				$progressCallback = $arguments[5] ?? null;
				$this->assertIsCallable($progressCallback);
				$progressCallback('🔧 _Using tool: search_test..._');
				$progressCallback('Canonical answer.');

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
			->onlyMethods(['sendReplyToTalk'])
			->getMock();
		$handler->expects($this->exactly(2))
			->method('sendReplyToTalk')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$attempts, &$deliveryResults): bool {
				$attempts[] = ['message' => $message, 'reply_to' => $replyToId];
				return array_shift($deliveryResults);
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
				$progressCallback = $arguments[5] ?? null;
				$errorCallback = $arguments[12] ?? null;
				$mismatchCallback = $arguments[13] ?? null;
				$this->assertIsCallable($progressCallback);
				$this->assertIsCallable($errorCallback);
				$this->assertIsCallable($mismatchCallback);
				$progressCallback('Partial answer already visible.');
				$progressCallback('🔧 _Using tool: search_test..._');
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
			->onlyMethods(['sendReplyToTalk'])
			->getMock();
		$handler->expects($this->exactly(3))
			->method('sendReplyToTalk')
			->willReturnCallback(function (string $roomToken, string $message, int $replyToId = 0) use (&$sentMessages): bool {
				$sentMessages[] = $message;
				return true;
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
			$logger ?? $this->createMock(LoggerInterface::class)
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
