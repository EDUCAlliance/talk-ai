<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use OCA\EducAI\Db\TraceEvent;
use OCA\EducAI\Db\TraceEventMapper;
use OCA\EducAI\Db\TraceRun;
use OCA\EducAI\Db\TraceRunMapper;
use OCA\EducAI\Service\TraceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TraceServiceTest extends TestCase {
	public function testStartRunCreatesPreviewAndReturnsId(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);
		$capturedRun = null;

		$runMapper->expects($this->once())
			->method('insertRun')
			->willReturnCallback(function (TraceRun $run) use (&$capturedRun): TraceRun {
				$run->setId(123);
				$capturedRun = $run;
				return $run;
			});

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));

		$id = $service->startRun([
			'user_id' => 'users/alice',
			'bot_id' => 42,
			'bot_mention_name' => '@catalogue',
			'room_token' => 'room-a',
			'user_message' => 'Find course token=secret-token-value',
		]);

		$this->assertSame(123, $id);
		$this->assertInstanceOf(TraceRun::class, $capturedRun);
		$this->assertSame('alice', $capturedRun->getUserId());
		$this->assertSame(42, $capturedRun->getBotId());
		$this->assertStringContainsString('token=[redacted]', (string)$capturedRun->getUserMessagePreview());
	}

	public function testListRunsNormalizesTalkActorUserId(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);

		$runMapper->expects($this->once())
			->method('findForUser')
			->with('alice', $this->anything(), 25, 0)
			->willReturn([]);
		$runMapper->expects($this->once())
			->method('countForUser')
			->with('alice', $this->anything())
			->willReturn(0);

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));
		$result = $service->listRunsForUser('users/alice', []);

		$this->assertSame(0, $result['total']);
	}

	public function testListRunsNormalizesBotMentionNameFilter(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);

		$runMapper->expects($this->once())
			->method('findForUser')
			->with('alice', $this->callback(static fn(array $filters): bool => $filters['botMentionName'] === '@cute-hajs'), 25, 0)
			->willReturn([]);
		$runMapper->expects($this->once())
			->method('countForUser')
			->with('alice', $this->callback(static fn(array $filters): bool => $filters['botMentionName'] === '@cute-hajs'))
			->willReturn(0);

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));
		$result = $service->listRunsForUser('alice', ['bot_mention_name' => '@cute-hajs']);

		$this->assertSame(0, $result['total']);
	}

	public function testEventsForStartedRunUseInMemorySequence(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);
		$sequences = [];

		$runMapper->expects($this->once())
			->method('insertRun')
			->willReturnCallback(static function (TraceRun $run): TraceRun {
				$run->setId(7);
				return $run;
			});
		$eventMapper->expects($this->never())->method('countByRunId');
		$eventMapper->expects($this->exactly(2))
			->method('insertEvent')
			->willReturnCallback(static function (TraceEvent $event) use (&$sequences): TraceEvent {
				$sequences[] = $event->getSequence();
				return $event;
			});
		$runMapper->expects($this->exactly(2))->method('incrementCounters')->with(7, false);

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));
		$runId = $service->startRun(['user_id' => 'alice']);

		$service->recordEvent($runId, 'user_message', ['payload' => ['content' => 'first']]);
		$service->recordEvent($runId, 'assistant_response', ['result' => 'second']);

		$this->assertSame([1, 2], $sequences);
	}

	public function testRecordToolCallRedactsSensitiveArguments(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);
		$capturedEvent = null;

		$eventMapper->expects($this->once())->method('countByRunId')->with(7)->willReturn(0);
		$eventMapper->expects($this->once())
			->method('insertEvent')
			->willReturnCallback(function (TraceEvent $event) use (&$capturedEvent): TraceEvent {
				$capturedEvent = $event;
				return $event;
			});
		$runMapper->expects($this->once())->method('incrementCounters')->with(7, true);

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));
		$service->recordToolCall(7, 'search_test', [
			'query' => 'Berlin',
			'api_key' => 'secret-value',
			'nested' => ['authorization' => 'Bearer abcdefghijklmnop'],
		], 'call-1');

		$this->assertInstanceOf(TraceEvent::class, $capturedEvent);
		$this->assertSame(1, $capturedEvent->getSequence());
		$this->assertSame('tool_call', $capturedEvent->getEventType());
		$this->assertSame('search_test', $capturedEvent->getToolName());

		$payload = json_decode((string)$capturedEvent->getPayloadJson(), true);
		$this->assertSame('[redacted]', $payload['arguments']['api_key']);
		$this->assertSame('[redacted]', $payload['arguments']['nested']['authorization']);
	}

	public function testRecordToolResultTruncatesLargeResults(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);
		$capturedEvent = null;

		$eventMapper->method('countByRunId')->willReturn(3);
		$eventMapper->expects($this->once())
			->method('insertEvent')
			->willReturnCallback(function (TraceEvent $event) use (&$capturedEvent): TraceEvent {
				$capturedEvent = $event;
				return $event;
			});
		$runMapper->expects($this->once())->method('incrementCounters')->with(7, false);

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));
		$service->recordToolResult(7, 'search_test', 'ok', str_repeat('x', 70000), 25);

		$result = json_decode((string)$capturedEvent->getResultJson(), true);
		$this->assertSame(4, $capturedEvent->getSequence());
		$this->assertTrue($result['truncated']);
		$this->assertGreaterThan(65536, $result['originalLength']);
		$this->assertStringEndsWith('...', $capturedEvent->getResultPreview());
	}

	public function testRecordLlmRequestStoresFullPayloadAndKeepsTokenParameters(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);
		$capturedEvent = null;
		$largeContent = str_repeat('large payload ', 7000);

		$eventMapper->method('countByRunId')->willReturn(0);
		$eventMapper->expects($this->once())
			->method('insertEvent')
			->willReturnCallback(function (TraceEvent $event) use (&$capturedEvent): TraceEvent {
				$capturedEvent = $event;
				return $event;
			});
		$runMapper->expects($this->once())->method('incrementCounters')->with(7, false);

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));
		$service->recordEvent(7, 'llm_request', [
			'payload' => [
				'request_payload' => [
					'model' => 'test-model',
					'messages' => [
						['role' => 'system', 'content' => $largeContent],
						['role' => 'user', 'content' => 'hello'],
					],
					'max_tokens' => 800,
					'access_token' => 'secret-value',
				],
			],
		]);

		$this->assertInstanceOf(TraceEvent::class, $capturedEvent);
		$payloadJson = (string)$capturedEvent->getPayloadJson();
		$this->assertGreaterThan(65536, strlen($payloadJson));
		$this->assertStringNotContainsString('"truncated":true', $payloadJson);

		$payload = json_decode($payloadJson, true);
		$this->assertSame($largeContent, $payload['request_payload']['messages'][0]['content']);
		$this->assertSame(800, $payload['request_payload']['max_tokens']);
		$this->assertSame('[redacted]', $payload['request_payload']['access_token']);
	}

	public function testRecordLlmResponseAggregatesTokenUsage(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);

		$eventMapper->method('countByRunId')->willReturn(0);
		$eventMapper->expects($this->once())->method('insertEvent');
		$runMapper->expects($this->once())->method('incrementCounters')->with(7, false);
		$runMapper->expects($this->once())
			->method('addLlmTokenUsage')
			->with(7, 12, 8, 20);

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));
		$service->recordEvent(7, 'llm_response', [
			'payload' => [
				'usage' => [
					'prompt_tokens' => 12,
					'completion_tokens' => 8,
					'total_tokens' => 20,
				],
			],
		]);
	}

	public function testWriteFailuresDoNotEscape(): void {
		$runMapper = $this->createMock(TraceRunMapper::class);
		$eventMapper = $this->createMock(TraceEventMapper::class);
		$runMapper->method('insertRun')->willThrowException(new Exception('db down'));
		$eventMapper->method('countByRunId')->willThrowException(new Exception('db down'));

		$service = new TraceService($runMapper, $eventMapper, $this->createMock(LoggerInterface::class));

		$this->assertNull($service->startRun(['user_id' => 'alice']));
		$service->recordEvent(7, 'user_message', ['payload' => ['content' => 'hello']]);
		$this->addToAssertionCount(1);
	}
}
