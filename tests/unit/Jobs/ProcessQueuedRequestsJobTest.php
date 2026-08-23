<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Jobs;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Jobs\ProcessQueuedRequestsJob;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\TraceService;
use OCA\EducAI\Webhook\TalkHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TestableProcessQueuedRequestsJob extends ProcessQueuedRequestsJob {
	public function runNow(): void {
		$this->run([]);
	}
}

class ProcessQueuedRequestsJobTest extends TestCase {
	public function testTypedAgentFailureOnFirstAttemptUsesSafeNotificationAndDoesNotRetry(): void {
		$request = new QueuedRequest();
		$request->setId(99);
		$request->setBotId(7);
		$request->setRoomToken('room-token');
		$request->setUserId('owner');
		$request->setMessage('Hello');
		$request->setOriginalMessage('@bot Hello');
		$request->setReplyToMessageId(123);
		$request->setThreadRootMessageId(42);
		$request->setAttempts(1);
		$request->setCreatedAt(time());

		$bot = new Bot();
		$bot->setId(7);
		$bot->setIsActive(true);
		$bot->setMentionName('@bot');

		$rateLimitService = $this->createMock(RateLimitService::class);
		$this->expectSinglePendingRequest($rateLimitService, $request);
		$rateLimitService->expects($this->once())
			->method('markProcessing')
			->with($request);
		$rateLimitService->expects($this->once())
			->method('recordUsage');
		$rateLimitService->expects($this->once())
			->method('markFailed')
			->with(
				$request,
				'Agent execution failed: Agent execution terminated: max_turns'
			);
		$rateLimitService->expects($this->never())
			->method('markForRetry');
		$rateLimitService->expects($this->never())
			->method('markResponseReady');
		$rateLimitService->expects($this->never())
			->method('markCompleted');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments): string {
				$this->assertSame(91, $arguments[11] ?? null);
				$onExecutionError = $arguments[12] ?? null;
				$this->assertIsCallable($onExecutionError);
				$onExecutionError('Agent execution terminated: max_turns');

				return "Sorry, I'm having trouble connecting to the AI service right now. Please try again later.";
			});

		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with(
				'room-token',
				$this->callback(function (string $message): bool {
					$this->assertSame("Sorry, I'm having trouble connecting to the AI service right now. Please try again later.", $message);
					$this->assertStringNotContainsString('max_turns', $message);
					$this->assertStringNotContainsString('Error:', $message);
					return true;
				}),
				123
			)
			->willReturn(true);
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())
			->method('startRun')
			->with($this->callback(function (array $context): bool {
				$this->assertSame('queue', $context['source']);
				$this->assertSame('owner', $context['user_id']);
				$this->assertSame(7, $context['bot_id']);
				$this->assertSame('@bot', $context['bot_mention_name']);
				$this->assertSame('room-token', $context['room_token']);
				$this->assertSame(123, $context['talk_message_id']);
				$this->assertSame(123, $context['reply_target_message_id']);
				$this->assertSame(42, $context['thread_root_message_id']);
				$this->assertSame('@bot Hello', $context['user_message']);
				return true;
			}))
			->willReturn(91);
		$traceService->expects($this->once())
			->method('finishRun')
			->with(91, 'error', 'Agent execution terminated: max_turns');

		$job = $this->createJob($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);

		$job->runNow();
	}

	public function testSuccessfulQueuedExecutionPassesTraceIdAndFinishesTraceAsSuccess(): void {
		$request = $this->createRequest(1);
		$bot = $this->createActiveBot();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$this->expectSinglePendingRequest($rateLimitService, $request);
		$rateLimitService->expects($this->once())->method('markProcessing')->with($request);
		$rateLimitService->expects($this->once())->method('recordUsage');
		$this->expectResponseReady($rateLimitService, $request, 'Queued answer.');
		$this->expectDeliveryAttempt($rateLimitService, $request);
		$rateLimitService->expects($this->once())->method('markCompleted')->with($request, 'Queued answer.');
		$rateLimitService->expects($this->never())->method('markForRetry');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())->method('findById')->with(7)->willReturn($bot);
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments): string {
				$this->assertSame(92, $arguments[11] ?? null);
				return 'Queued answer.';
			});

		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with('room-token', 'Queued answer.', 123, $request->getDeliveryReferenceId())
			->willReturn(true);
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())
			->method('startRun')
			->with($this->callback(static fn (array $context): bool => $context['source'] === 'queue'))
			->willReturn(92);
		$traceService->expects($this->once())->method('finishRun')->with(92, 'success', null);

		$this->createJob($rateLimitService, $botService, $botMapper, $talkHandler, $traceService)->runNow();
	}

	public function testFailedTalkDeliveryTerminatesRequestAndFinishesTraceAsPartial(): void {
		$request = $this->createRequest(1);
		$bot = $this->createActiveBot();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$this->expectSinglePendingRequest($rateLimitService, $request);
		$rateLimitService->expects($this->once())->method('markProcessing')->with($request);
		$rateLimitService->expects($this->once())->method('recordUsage');
		$this->expectResponseReady($rateLimitService, $request, 'Queued answer.');
		$this->expectDeliveryAttempt($rateLimitService, $request);
		$rateLimitService->expects($this->never())->method('markCompleted');
		$rateLimitService->expects($this->never())->method('markForRetry');
		$rateLimitService->expects($this->once())
			->method('markResponseDeliveryFailed')
			->with($request, 'Delivery failed: Failed to deliver queued response to Talk');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())->method('findById')->with(7)->willReturn($bot);
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (...$arguments): string {
				$this->assertSame(93, $arguments[11] ?? null);
				return 'Queued answer.';
			});

		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with('room-token', 'Queued answer.', 123, $request->getDeliveryReferenceId())
			->willReturn(false);
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(93);
		$traceService->expects($this->once())
			->method('finishRun')
			->with(93, 'partial', 'Failed to deliver queued response to Talk');

		$this->createJob($rateLimitService, $botService, $botMapper, $talkHandler, $traceService)->runNow();
		$this->assertSame('Queued answer.', $request->getResult());
	}

	public function testFreshResponseReadyRowsUseStableBoundedDeliveryWithoutAgentOrProviderCapacity(): void {
		$firstReload = $this->createReadyRequest(1);
		$secondReload = $this->createReadyRequest(2);
		$terminalReload = $this->createReadyRequest(3);
		$this->assertNotSame($firstReload, $secondReload);
		$this->assertSame($firstReload->getDeliveryReferenceId(), $secondReload->getDeliveryReferenceId());

		$rateLimitService = $this->createMock(RateLimitService::class);
		$rateLimitService->expects($this->exactly(3))
			->method('getResponseReadyRequests')
			->with(10)
			->willReturnOnConsecutiveCalls([$firstReload], [$secondReload], [$terminalReload]);
		$rateLimitService->expects($this->exactly(3))
			->method('isEnabled')
			->willReturn(false);
		$rateLimitService->expects($this->never())->method('getQueueStats');
		$rateLimitService->expects($this->never())->method('cleanup');
		$rateLimitService->expects($this->never())->method('canProcess');
		$rateLimitService->expects($this->never())->method('getNextPending');
		$rateLimitService->expects($this->never())->method('markProcessing');
		$rateLimitService->expects($this->never())->method('recordUsage');
		$rateLimitService->expects($this->never())->method('markResponseReady');
		$rateLimitService->expects($this->exactly(2))
			->method('markResponseDeliveryAttempt')
			->willReturnCallback(static function (QueuedRequest $request): QueuedRequest {
				$request->incrementAttempts();
				return $request;
			});
		$rateLimitService->expects($this->exactly(2))
			->method('markCompleted')
			->willReturnCallback(function (QueuedRequest $request, string $result): QueuedRequest {
				$this->assertSame('Queued answer.', $result);
				throw new \RuntimeException('database unavailable');
			});
		$rateLimitService->expects($this->once())
			->method('markResponseDeliveryFailed')
			->with($terminalReload, 'Queued response delivery attempts exhausted')
			->willReturnCallback(static function (QueuedRequest $request): QueuedRequest {
				$request->setStatus(QueuedRequest::STATUS_FAILED);
				return $request;
			});
		$rateLimitService->expects($this->never())->method('markForRetry');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->never())->method('findById');
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->never())->method('processMessage');

		$referenceIds = [];
		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->exactly(2))
			->method('sendReplyToTalk')
			->willReturnCallback(function (string $roomToken, string $result, int $replyTo, string $referenceId) use (&$referenceIds): bool {
				$this->assertSame('room-token', $roomToken);
				$this->assertSame('Queued answer.', $result);
				$this->assertSame(123, $replyTo);
				$referenceIds[] = $referenceId;
				return true;
			});

		$error = 'Queued response delivered, but completion persistence failed: database unavailable';
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->exactly(3))
			->method('startRun')
			->with($this->callback(static fn (array $context): bool => $context['source'] === 'queue_delivery'))
			->willReturnOnConsecutiveCalls(94, 95, 96);
		$traceService->expects($this->exactly(5))
			->method('recordEvent')
			->willReturnCallback(function (?int $runId, string $eventType, array $event) use ($error): void {
				$this->assertContains($runId, [94, 95, 96]);
				$this->assertContains($eventType, ['queue_delivery', 'queue_persistence']);
				if ($eventType === 'queue_persistence') {
					$this->assertSame($error, $event['error_message']);
				}
			});
		$traceService->expects($this->exactly(3))
			->method('finishRun')
			->willReturnCallback(function (?int $runId, string $status, ?string $summary) use ($error): void {
				if ($runId === 94 || $runId === 95) {
					$this->assertSame('partial', $status);
					$this->assertSame($error, $summary);
					return;
				}
				$this->assertSame(96, $runId);
				$this->assertSame('partial', $status);
				$this->assertSame('Queued response delivery attempts exhausted', $summary);
			});

		$job = $this->createJob($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);
		$job->runNow();
		$job->runNow();
		$job->runNow();

		$this->assertSame([$firstReload->getDeliveryReferenceId(), $secondReload->getDeliveryReferenceId()], $referenceIds);
		$this->assertSame(QueuedRequest::STATUS_FAILED, $terminalReload->getStatus());
	}

	public function testRejectedAtomicDeliveryClaimNeverSendsOrRunsAgent(): void {
		$request = $this->createReadyRequest(0);
		$error = 'Queued response delivery reconciliation failed: Queued response delivery attempt was not claimed';
		$rateLimitService = $this->createMock(RateLimitService::class);
		$rateLimitService->expects($this->once())
			->method('getResponseReadyRequests')
			->with(10)
			->willReturn([$request]);
		$rateLimitService->expects($this->once())->method('isEnabled')->willReturn(false);
		$rateLimitService->expects($this->once())
			->method('markResponseDeliveryAttempt')
			->with($request)
			->willThrowException(new \LogicException('Queued response delivery attempt was not claimed'));
		$rateLimitService->expects($this->never())->method('markCompleted');
		$rateLimitService->expects($this->never())->method('markFailed');
		$rateLimitService->expects($this->never())->method('markForRetry');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->never())->method('findById');
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->never())->method('processMessage');
		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->never())->method('sendReplyToTalk');
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(97);
		$traceService->expects($this->never())->method('recordEvent');
		$traceService->expects($this->once())->method('finishRun')->with(97, 'partial', $error);

		$this->createJob($rateLimitService, $botService, $botMapper, $talkHandler, $traceService)->runNow();
	}

	public function testResponseReadyPersistenceFailureIsNotRelabeledAsAgentFailure(): void {
		$request = $this->createRequest(1);
		$bot = $this->createActiveBot();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$this->expectSinglePendingRequest($rateLimitService, $request);
		$rateLimitService->expects($this->once())->method('markProcessing')->with($request);
		$rateLimitService->expects($this->once())->method('recordUsage');
		$rateLimitService->expects($this->once())
			->method('markResponseReady')
			->with($request, 'Queued answer.')
			->willThrowException(new \RuntimeException('database unavailable'));
		$error = 'Queued response persistence failed: database unavailable';
		$rateLimitService->expects($this->once())->method('markFailed')->with($request, $error);
		$rateLimitService->expects($this->never())->method('markForRetry');
		$rateLimitService->expects($this->never())->method('markCompleted');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findById')->with(7)->willReturn($bot);
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())->method('processMessage')->willReturn('Queued answer.');
		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with(
				'room-token',
				'⚠️ The AI response was generated but could not be queued for delivery. Please try again.',
				123
			)
			->willReturn(true);
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(96);
		$traceService->expects($this->once())
			->method('recordEvent')
			->with(96, 'queue_persistence', ['status' => 'error', 'error_message' => $error]);
		$traceService->expects($this->once())->method('finishRun')->with(96, 'partial', $error);

		$this->createJob($rateLimitService, $botService, $botMapper, $talkHandler, $traceService)->runNow();
	}

	private function expectResponseReady(RateLimitService $rateLimitService, QueuedRequest $request, string $response): void {
		$rateLimitService->expects($this->once())
			->method('markResponseReady')
			->with($request, $response)
			->willReturnCallback(static function (QueuedRequest $queuedRequest, string $result): QueuedRequest {
				$queuedRequest->setStatus(QueuedRequest::STATUS_RESPONSE_READY);
				$queuedRequest->setResult($result);
				$queuedRequest->setAttempts(0);
				return $queuedRequest;
			});
	}

	private function expectDeliveryAttempt(RateLimitService $rateLimitService, QueuedRequest $request): void {
		$rateLimitService->expects($this->once())
			->method('markResponseDeliveryAttempt')
			->with($request)
			->willReturnCallback(static function (QueuedRequest $queuedRequest): QueuedRequest {
				$queuedRequest->incrementAttempts();
				return $queuedRequest;
			});
	}

	private function expectSinglePendingRequest(RateLimitService $rateLimitService, QueuedRequest $request): void {
		$rateLimitService->expects($this->once())->method('getResponseReadyRequests')->with(10)->willReturn([]);
		$rateLimitService->expects($this->once())->method('isEnabled')->willReturn(true);
		$rateLimitService->expects($this->once())
			->method('getQueueStats')
			->willReturn(['pending' => 1, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 1]);
		$rateLimitService->expects($this->once())->method('cleanup')->with(3600);
		$rateLimitService->expects($this->once())->method('canProcess')->willReturn(true);
		$rateLimitService->expects($this->once())->method('getNextPending')->willReturn($request);
	}

	private function createReadyRequest(int $attempts): QueuedRequest {
		$request = $this->createRequest($attempts);
		$request->setCreatedAt(1770000000);
		$request->setStatus(QueuedRequest::STATUS_RESPONSE_READY);
		$request->setResult('Queued answer.');
		return $request;
	}

	private function createRequest(int $attempts): QueuedRequest {
		$request = new QueuedRequest();
		$request->setId(99);
		$request->setBotId(7);
		$request->setRoomToken('room-token');
		$request->setUserId('owner');
		$request->setMessage('Hello');
		$request->setOriginalMessage('@bot Hello');
		$request->setReplyToMessageId(123);
		$request->setThreadRootMessageId(42);
		$request->setAttempts($attempts);
		$request->setCreatedAt(time());
		return $request;
	}

	private function createActiveBot(): Bot {
		$bot = new Bot();
		$bot->setId(7);
		$bot->setIsActive(true);
		$bot->setMentionName('@bot');
		return $bot;
	}

	private function createJob(
		RateLimitService $rateLimitService,
		BotService $botService,
		BotMapper $botMapper,
		TalkHandler $talkHandler,
		TraceService $traceService,
	): TestableProcessQueuedRequestsJob {
		return new TestableProcessQueuedRequestsJob(
			$this->createMock(ITimeFactory::class),
			$rateLimitService,
			$botService,
			$botMapper,
			$talkHandler,
			$this->createMock(LoggerInterface::class),
			$traceService
		);
	}
}
