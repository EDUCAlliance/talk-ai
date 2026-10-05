<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Webhook;

use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\OnboardingService;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\TraceService;
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

class TalkHandlerLargeReplyTest extends TestCase {
	#[DataProvider('largeReplies')]
	public function testLargeReplyIsDeliveredInLosslessSignedUtf8Chunks(string $message): void {
		$database = $this->database();
		$sent = [];
		$handler = $this->handler($database, function (array $body, array $options) use (&$sent): int {
			$this->assertTrue(mb_check_encoding($body['message'], 'UTF-8'));
			$this->assertSame(123, $body['replyTo']);
			$this->assertLessThanOrEqual(64, strlen($body['referenceId']));
			$this->assertSame(
				hash_hmac('sha256', $options['headers']['X-Nextcloud-Talk-Bot-Random'] . $body['message'], 'talk-secret'),
				$options['headers']['X-Nextcloud-Talk-Bot-Signature'],
			);
			$sent[] = $body;
			return mb_strlen($body['message'], 'UTF-8') > 32000 ? 413 : 201;
		});

		$outcome = $handler->sendReplyToTalkWithOutcome('room-token', $message, 123, 'queue-result-42');

		foreach ($sent as $body) {
			$this->assertLessThanOrEqual(32000, mb_strlen($body['message'], 'UTF-8'));
		}
		$this->assertSame(TalkHandler::DELIVERY_SUCCESS, $outcome['status']);
		$this->assertSame($message, implode('', array_column($sent, 'message')));
		$this->assertCount(count($sent), array_unique(array_column($sent, 'referenceId')));
	}

	public static function largeReplies(): array {
		return [
			'ascii just over boundary' => [str_repeat('a', 32001)],
			'emoji character boundary, not bytes' => [str_repeat('🙂', 32001)],
			'multiple chunks, mixed unicode' => [str_repeat('文🙂éx', 17000)],
			'whitespace and code retained in raw payload' => [str_repeat("text\n  code();\n", 3000)],
		];
	}

	public function testQueueRetryResumesAfterDeliveredPrefixWithoutDuplicatingIt(): void {
		$database = $this->database();
		$message = str_repeat('A', 32000) . str_repeat('B', 32000) . 'C';
		$sent = [];
		$statuses = [201, 503, 201, 201];
		$dispatch = function (array $body) use ($database, &$sent, &$statuses): int {
			$sent[] = $body;
			$status = array_shift($statuses);
			if ($status === 201) {
				$this->storeMessage($database, $body);
			}
			return $status;
		};

		$first = $this->handler($database, $dispatch)->sendReplyToTalkWithOutcome('room-token', $message, 123, 'queue-result-42');
		$this->assertSame(TalkHandler::DELIVERY_RETRYABLE, $first['status']);
		$this->assertCount(2, $sent, 'Do not dispatch later chunks after the first failure');

		// A new handler models a later queue worker, with no in-memory delivery state.
		$second = $this->handler($database, $dispatch)->sendReplyToTalkWithOutcome('room-token', $message, 123, 'queue-result-42');
		$this->assertSame(TalkHandler::DELIVERY_SUCCESS, $second['status']);
		$this->assertSame(['A', 'B', 'B', 'C'], array_map(static fn (array $body): string => $body['message'][0], $sent));
		$this->assertSame($sent[1]['referenceId'], $sent[2]['referenceId']);
		$this->assertSame($message, implode('', array_column($database->tables['comments'], 'message')));
	}

	public function testAlreadyDeliveredLongReplyIsReconciledAfterWorkerRestart(): void {
		$database = $this->database();
		$message = str_repeat('文', 32001);
		$sent = [];
		$dispatch = function (array $body) use ($database, &$sent): int {
			$sent[] = $body;
			$this->storeMessage($database, $body);
			return 201;
		};
		$this->assertTrue($this->handler($database, $dispatch)->sendReplyToTalk('room-token', $message, 123, 'queue-result-42'));
		$this->assertTrue($this->handler($database, $dispatch)->sendReplyToTalk('room-token', $message, 123, 'queue-result-42'));
		$this->assertCount(2, $sent);
	}

	public function testFailedReconciliationDoesNotBlindlyResend(): void {
		$database = $this->database();
		$database->failQueries = true;
		$sent = [];
		$handler = $this->handler($database, function (array $body) use (&$sent): int {
			$sent[] = $body;
			return 201;
		});
		$outcome = $handler->sendReplyToTalkWithOutcome('room-token', str_repeat('a', 32001), 123, 'queue-result-42');
		$this->assertNotSame(TalkHandler::DELIVERY_SUCCESS, $outcome['status']);
		$this->assertSame([], $sent);
	}

	public function testShortRepliesRetainOriginalPayloadAndReferenceAndDoNotRequireDatabase(): void {
		$database = $this->database();
		$database->failQueries = true;
		$sent = [];
		$handler = $this->handler($database, function (array $body) use (&$sent): int {
			$sent[] = $body;
			return 201;
		});
		$message = ' ' . str_repeat('🙂', 31998) . ' ';
		$this->assertTrue($handler->sendReplyToTalk('room-token', $message, 123, 'original-reference'));
		$this->assertSame([['message' => $message, 'replyTo' => 123, 'referenceId' => 'original-reference']], $sent);
	}

	public function testAcceptedChunkIsReconciledWhenHttpResponseIsLost(): void {
		$database = $this->database();
		$sent = [];
		$handler = $this->handler($database, function (array $body) use ($database, &$sent): int {
			$sent[] = $body;
			$this->storeMessage($database, $body);
			if (count($sent) === 1) {
				throw new \RuntimeException('HTTP response lost after Talk accepted the chunk');
			}
			return 201;
		});
		$message = str_repeat('a', 32001);
		$this->assertTrue($handler->sendReplyToTalk('room-token', $message, 123, 'queue-result-42'));
		$this->assertCount(2, $sent);
		$this->assertSame($message, implode('', array_column($database->tables['comments'], 'message')));
	}

	public function testUnconfirmedTimeoutStopsQueueRetriesAndImmediateFallback(): void {
		$database = $this->database();
		$sent = [];
		$handler = $this->handler($database, function (array $body) use (&$sent): int {
			$sent[] = $body;
			throw new \RuntimeException('Talk may still be processing the request');
		});
		$message = str_repeat('a', 32001);
		$first = $handler->sendReplyToTalkWithOutcome('room-token', $message, 123, 'queue-result-42');
		$second = $handler->sendReplyToTalkWithOutcome('room-token', $message, 123, 'queue-result-42');
		$this->assertSame(TalkHandler::DELIVERY_PERMANENT, $first['status']);
		$this->assertSame(TalkHandler::DELIVERY_PERMANENT, $second['status']);
		$this->assertStringContainsString('unconfirmed', $second['error']);
		$this->assertCount(1, $sent, 'Neither later chunks nor fallback may race a possibly still-running request');
	}

	public function testUnconfirmedTimeoutCannotTurnIntoResendAfterLookupRecovery(): void {
		$database = $this->database();
		$sent = [];
		$handler = $this->handler($database, function (array $body) use ($database, &$sent): int {
			$sent[] = $body;
			$database->failQueries = true;
			throw new \RuntimeException('Transport failed and reconciliation is unavailable');
		});
		$message = str_repeat('a', 32001);
		$first = $handler->sendReplyToTalkWithOutcome('room-token', $message, 123, 'queue-result-42');
		$this->assertSame(TalkHandler::DELIVERY_PERMANENT, $first['status']);
		$database->failQueries = false;
		$second = $handler->sendReplyToTalkWithOutcome('room-token', $message, 123, 'queue-result-42');
		$this->assertSame(TalkHandler::DELIVERY_PERMANENT, $second['status']);
		$this->assertCount(1, $sent);
	}

	#[DataProvider('unrelatedComments')]
	public function testOtherRoomOrActorCannotSuppressDelivery(string $column, string $value): void {
		$database = $this->database();
		$sent = [];
		$message = str_repeat('a', 32001);
		$dispatch = function (array $body) use ($database, &$sent): int {
			$sent[] = $body;
			$this->storeMessage($database, $body);
			return 201;
		};
		$this->assertTrue($this->handler($database, $dispatch)->sendReplyToTalk('room-token', $message, 123, 'queue-result-42'));
		foreach ($database->tables['comments'] as &$row) {
			$row[$column] = $value;
		}
		unset($row);
		$this->assertTrue($this->handler($database, $dispatch)->sendReplyToTalk('room-token', $message, 123, 'queue-result-42'));
		$this->assertCount(4, $sent);
	}

	public static function unrelatedComments(): array {
		return [
			'other room' => ['object_id', '42'],
			'other object type' => ['object_type', 'files'],
			'user impersonating bot id' => ['actor_type', 'users'],
			'other bot' => ['actor_id', 'bot-' . str_repeat('b', 40)],
		];
	}

	public function testChangedChunkContentFailsClosedInsteadOfClaimingSuccess(): void {
		$database = $this->database();
		$sent = [];
		$message = str_repeat('a', 32001);
		$dispatch = function (array $body) use ($database, &$sent): int {
			$sent[] = $body;
			$this->storeMessage($database, $body);
			return 201;
		};
		$this->assertTrue($this->handler($database, $dispatch)->sendReplyToTalk('room-token', $message, 123, 'queue-result-42'));
		$database->tables['comments'][0]['message'] = 'Some other content';
		$outcome = $this->handler($database, $dispatch)->sendReplyToTalkWithOutcome('room-token', $message, 123, 'queue-result-42');
		$this->assertNotSame(TalkHandler::DELIVERY_SUCCESS, $outcome['status']);
		$this->assertCount(2, $sent);
	}

	public function testNormalMessageFallbackReusesLongStreamDeliveryReferencesAndReplyTarget(): void {
		$database = $this->database();
		$sent = [];
		$statuses = [201, 503, 201, 201];
		$message = str_repeat('A', 32000) . 'B';
		$botService = $this->createMock(BotService::class);
		$botService->method('processMessage')->willReturnCallback(function (...$args) use ($message): string {
			$args[5]($message);
			$args[14]('Tool progress after the interrupted long response');
			return $message;
		});
		$handler = $this->handler($database, function (array $body) use ($database, &$sent, &$statuses): int {
			$sent[] = $body;
			$status = array_shift($statuses);
			if ($status === 201) {
				$this->storeMessage($database, $body);
			}
			return $status;
		}, $botService);
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$method = new \ReflectionMethod($handler, 'processNormalMessage');
		$method->setAccessible(true);
		$method->invoke($handler, $bot, $room, 'room-token', 'alice', 'Hello', 123);

		$this->assertCount(4, $sent);
		$this->assertSame(['A', 'B', 'T', 'B'], array_map(static fn (array $body): string => $body['message'][0], $sent));
		$this->assertSame($sent[1]['referenceId'], $sent[3]['referenceId']);
		$this->assertSame([123, 123, 123, 123], array_column($sent, 'replyTo'));
	}

	public function testUnconfirmedLongStreamFinishesTraceAsPartialWithoutSendingAgain(): void {
		$database = $this->database();
		$sent = [];
		$message = str_repeat('a', 32001);
		$botService = $this->createMock(BotService::class);
		$botService->method('processMessage')->willReturnCallback(static function (...$args) use ($message): string {
			$args[5]($message);
			return $message;
		});
		$trace = $this->createMock(TraceService::class);
		$trace->method('startRun')->willReturn(41);
		$trace->expects($this->once())->method('finishRun')->with(41, 'partial', $this->stringContains('unconfirmed'));
		$handler = $this->handler($database, function (array $body) use (&$sent): int {
			$sent[] = $body;
			throw new \RuntimeException('Talk request timed out');
		}, $botService, $trace);
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$room = new \OCA\EducAI\Db\ChatRoom();
		$room->setOnboardingStatus('completed');
		$method = new \ReflectionMethod($handler, 'processNormalMessage');
		$method->setAccessible(true);
		$method->invoke($handler, $bot, $room, 'room-token', 'alice', 'Hello', 123);
		$this->assertCount(1, $sent);
	}

	private function storeMessage(object $database, array $body): void {
		$database->tables['comments'][] = [
			'object_type' => 'chat',
			'object_id' => '9',
			'actor_type' => 'bots',
			'actor_id' => 'bot-' . str_repeat('a', 40),
			'reference_id' => $body['referenceId'],
			'message' => trim($body['message']),
		];
	}

	private function database(): object {
		return (object)[
			'failQueries' => false,
			'tables' => [
				'talk_rooms' => [['id' => 9, 'token' => 'room-token']],
				'talk_bots_server' => [['url_hash' => str_repeat('a', 40), 'secret' => 'talk-secret']],
				'comments' => [],
			],
		];
	}

	private function handler(object $database, callable $dispatch, ?BotService $botService = null, ?TraceService $traceService = null): TalkHandler {
		$db = new class($database) implements IDBConnection {
			public function __construct(private object $database) { }
			public function getQueryBuilder(): object { return new TalkDeliveryTestQuery($this->database); }
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getWebhookSecret')->willReturn('talk-secret');
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('http://nextcloud.local/');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use ($dispatch): IResponse {
			$body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
			$status = $dispatch($body, $options);
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$onboarding = $this->createMock(OnboardingService::class);
		$onboarding->method('buildOnboardingContext')->willReturn('');
		return new TalkHandler(
			$botService ?? $this->createMock(BotService::class), $settings, $onboarding,
			$this->createMock(TalkMessageParser::class),
			$this->createMock(RoomDocumentIngestionService::class),
			$this->createMock(RoomImageIngestionService::class),
			$clients, $db, $urlGenerator, $this->createMock(LoggerInterface::class), $traceService,
		);
	}
}

/** Small relational double: query predicates really filter persisted Talk rows. */
class TalkDeliveryTestQuery {
	private string $table = '';
	private array $conditions = [];

	public function __construct(private object $database) {
	}

	public function select(string ...$columns): self { return $this; }
	public function from(string $table): self { $this->table = $table; return $this; }
	public function where(array $condition): self { $this->conditions = [$condition]; return $this; }
	public function andWhere(array $condition): self { $this->conditions[] = $condition; return $this; }
	public function setMaxResults(int $limit): self { return $this; }
	public function createNamedParameter(mixed $value, mixed $type = null): mixed { return $value; }
	public function expr(): self { return $this; }
	public function eq(string $column, mixed $value): array { return [$column, $value]; }
	public function in(string $column, array $values): array { return [$column, $values]; }

	public function executeQuery(): object {
		if ($this->database->failQueries) {
			throw new \RuntimeException('Local Talk database unavailable');
		}
		$rows = array_values(array_filter($this->database->tables[$this->table], function (array $row): bool {
			foreach ($this->conditions as [$column, $expected]) {
				if (is_array($expected) && in_array($row[$column] ?? null, $expected, true)) {
					continue;
				}
				if (($row[$column] ?? null) !== $expected) {
					return false;
				}
			}
			return true;
		}));
		return new class($rows) {
			public function __construct(private array $rows) { }
			public function fetch(): mixed { return array_shift($this->rows) ?? false; }
			public function fetchAll(): array { return $this->rows; }
			public function closeCursor(): bool { return true; }
		};
	}
}
