<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Webhook;

use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\OnboardingService;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCA\EducAI\Webhook\IncomingTalkMessage;
use OCA\EducAI\Webhook\TalkHandler;
use OCA\EducAI\Webhook\TalkMessageParser;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkHandlerTest extends TestCase {
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
				callable $onProgress
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
			$this->createMock(IURLGenerator::class),
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
