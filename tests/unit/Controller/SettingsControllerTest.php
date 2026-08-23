<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\SettingsController;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Service\AppIconService;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\TraceService;
use OCA\EducAI\Webhook\TalkHandler;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsControllerTest extends TestCase {
	public function testTypedAgentFailureTerminatesQueueAndSendsOnlySafeReply(): void {
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
		$rateLimitService->expects($this->once())->method('getResponseReadyRequests')->with(10)->willReturn([]);
		$rateLimitService->expects($this->once())->method('isEnabled')->willReturn(true);
		$rateLimitService->expects($this->exactly(2))
			->method('getQueueStats')
			->willReturnOnConsecutiveCalls(
				['pending' => 1, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 1],
				['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 1, 'total' => 1]
			);
		$rateLimitService->expects($this->once())->method('canProcess')->willReturn(true);
		$rateLimitService->expects($this->once())->method('getNextPending')->willReturn($request);
		$rateLimitService->expects($this->once())->method('markProcessing')->with($request);
		$rateLimitService->expects($this->once())->method('recordUsage');
		$rateLimitService->expects($this->never())->method('markForRetry');
		$rateLimitService->expects($this->never())->method('markResponseReady');
		$rateLimitService->expects($this->once())
			->method('markFailed')
			->with($request, 'Agent execution failed: Agent execution terminated: max_turns');
		$rateLimitService->expects($this->never())->method('markCompleted');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())->method('findById')->with(7)->willReturn($bot);

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willReturnCallback(function (
				Bot $calledBot,
				string $message,
				string $roomToken,
				string $userId,
				?string $originalMessage = null,
				?callable $onProgress = null,
				bool $isFromQueue = false,
				?string $onboardingContext = null,
				?array $messageContext = null,
				?int $threadRootMessageId = null,
				?int $replyToMessageId = null,
				?int $traceRunId = null,
				?callable $onExecutionError = null,
			): string {
				$this->assertSame(42, $threadRootMessageId);
				$this->assertSame(123, $replyToMessageId);
				$this->assertSame(81, $traceRunId);
				$this->assertIsCallable($onExecutionError);
				$onExecutionError('Agent execution terminated: max_turns');

				return "Sorry, I'm having trouble connecting to the AI service right now. Please try again later.";
			});

		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with(
				'room-token',
				"Sorry, I'm having trouble connecting to the AI service right now. Please try again later.",
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
			->willReturn(81);
		$traceService->expects($this->once())
			->method('finishRun')
			->with(81, 'error', 'Agent execution terminated: max_turns');

		$controller = $this->createController($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);

		$this->assertSame([
			'success' => true,
			'processed' => 0,
			'remaining' => 0,
			'errors' => ['Agent execution terminated: max_turns'],
		], $controller->processQueue()->getData());
	}

	public function testSuccessfulQueuedRequestPassesTraceIdAndFinishesTraceAsSuccess(): void {
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
		$rateLimitService->expects($this->once())->method('getResponseReadyRequests')->with(10)->willReturn([]);
		$rateLimitService->expects($this->once())->method('isEnabled')->willReturn(true);
		$rateLimitService->expects($this->exactly(2))
			->method('getQueueStats')
			->willReturnOnConsecutiveCalls(
				['pending' => 1, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 1],
				['pending' => 0, 'processing' => 0, 'completed' => 1, 'failed' => 0, 'total' => 1]
			);
		$rateLimitService->expects($this->once())->method('canProcess')->willReturn(true);
		$rateLimitService->expects($this->once())->method('getNextPending')->willReturn($request);
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
				$this->assertSame(42, $arguments[9] ?? null);
				$this->assertSame(123, $arguments[10] ?? null);
				$this->assertSame(82, $arguments[11] ?? null);
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
			->willReturn(82);
		$traceService->expects($this->once())->method('finishRun')->with(82, 'success', null);

		$controller = $this->createController($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);

		$this->assertSame([
			'success' => true,
			'processed' => 1,
			'remaining' => 0,
			'errors' => [],
		], $controller->processQueue()->getData());
	}

	public function testFailedTalkDeliveryTerminatesQueueAndFinishesTraceAsPartial(): void {
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
		$rateLimitService->expects($this->once())->method('getResponseReadyRequests')->with(10)->willReturn([]);
		$rateLimitService->expects($this->once())->method('isEnabled')->willReturn(true);
		$rateLimitService->expects($this->exactly(2))
			->method('getQueueStats')
			->willReturnOnConsecutiveCalls(
				['pending' => 1, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 1],
				['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 1, 'total' => 1]
			);
		$rateLimitService->expects($this->once())->method('canProcess')->willReturn(true);
		$rateLimitService->expects($this->once())->method('getNextPending')->willReturn($request);
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
				$this->assertSame(42, $arguments[9] ?? null);
				$this->assertSame(123, $arguments[10] ?? null);
				$this->assertSame(83, $arguments[11] ?? null);
				return 'Queued answer.';
			});

		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with('room-token', 'Queued answer.', 123, $request->getDeliveryReferenceId())
			->willReturn(false);
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(83);
		$traceService->expects($this->once())
			->method('finishRun')
			->with(83, 'partial', 'Failed to deliver queued response to Talk');

		$controller = $this->createController($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);

		$this->assertSame([
			'success' => true,
			'processed' => 0,
			'remaining' => 0,
			'errors' => ['Failed to deliver queued response to Talk'],
		], $controller->processQueue()->getData());
		$this->assertSame('Queued answer.', $request->getResult());
	}

	public function testCompletionPersistenceFailureAfterDeliveryDoesNotRelabelOrNotify(): void {
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
		$rateLimitService->expects($this->once())->method('getResponseReadyRequests')->with(10)->willReturn([]);
		$rateLimitService->expects($this->once())->method('isEnabled')->willReturn(true);
		$rateLimitService->expects($this->exactly(2))
			->method('getQueueStats')
			->willReturnOnConsecutiveCalls(
				['pending' => 1, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 1],
				['pending' => 0, 'processing' => 1, 'completed' => 0, 'failed' => 0, 'total' => 1]
			);
		$rateLimitService->expects($this->once())->method('canProcess')->willReturn(true);
		$rateLimitService->expects($this->once())->method('getNextPending')->willReturn($request);
		$rateLimitService->expects($this->once())->method('markProcessing')->with($request);
		$rateLimitService->expects($this->once())->method('recordUsage');
		$this->expectResponseReady($rateLimitService, $request, 'Queued answer.');
		$this->expectDeliveryAttempt($rateLimitService, $request);
		$rateLimitService->expects($this->once())
			->method('markCompleted')
			->with($request, 'Queued answer.')
			->willThrowException(new \RuntimeException('database unavailable'));
		$rateLimitService->expects($this->never())->method('markFailed');
		$rateLimitService->expects($this->never())->method('markForRetry');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())->method('findById')->with(7)->willReturn($bot);
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())->method('processMessage')->willReturn('Queued answer.');

		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with('room-token', 'Queued answer.', 123, $request->getDeliveryReferenceId())
			->willReturn(true);

		$error = 'Queued response delivered, but completion persistence failed: database unavailable';
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(84);
		$traceService->expects($this->once())
			->method('recordEvent')
			->with(84, 'queue_persistence', [
				'status' => 'error',
				'error_message' => $error,
			])
			->willThrowException(new \RuntimeException('trace storage unavailable'));
		$traceService->expects($this->once())->method('finishRun')->with(84, 'partial', $error);

		$controller = $this->createController($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);

		$this->assertSame([
			'success' => true,
			'processed' => 0,
			'remaining' => 0,
			'errors' => [$error],
		], $controller->processQueue()->getData());
		$this->assertSame('Queued answer.', $request->getResult());
		$this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $request->getStatus());
	}

	public function testFreshResponseReadyRowUsesDeliveryOnlyLaneWhenRateLimitingIsDisabled(): void {
		$request = $this->createReadyRequest();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$rateLimitService->expects($this->once())
			->method('getResponseReadyRequests')
			->with(10)
			->willReturn([$request]);
		$rateLimitService->expects($this->once())->method('isEnabled')->willReturn(false);
		$rateLimitService->expects($this->once())
			->method('getQueueStats')
			->willReturn(['pending' => 0, 'processing' => 0, 'response_ready' => 0, 'completed' => 1, 'failed' => 0, 'total' => 1]);
		$rateLimitService->expects($this->never())->method('canProcess');
		$rateLimitService->expects($this->never())->method('getNextPending');
		$rateLimitService->expects($this->never())->method('markProcessing');
		$rateLimitService->expects($this->never())->method('recordUsage');
		$rateLimitService->expects($this->never())->method('markResponseReady');
		$rateLimitService->expects($this->once())
			->method('markResponseDeliveryAttempt')
			->with($request)
			->willReturnCallback(static function (QueuedRequest $queued): QueuedRequest {
				$queued->incrementAttempts();
				return $queued;
			});
		$rateLimitService->expects($this->once())
			->method('markCompleted')
			->with($request, 'Queued answer.')
			->willReturnCallback(static function (QueuedRequest $completed): QueuedRequest {
				$completed->setStatus(QueuedRequest::STATUS_COMPLETED);
				return $completed;
			});

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->never())->method('findById');
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->never())->method('processMessage');
		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with('room-token', 'Queued answer.', 123, $request->getDeliveryReferenceId())
			->willReturn(true);
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())
			->method('startRun')
			->with($this->callback(static fn (array $context): bool => $context['source'] === 'queue_delivery'))
			->willReturn(85);
		$traceService->expects($this->exactly(2))->method('recordEvent');
		$traceService->expects($this->once())->method('finishRun')->with(85, 'success', null);

		$controller = $this->createController($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);
		$this->assertSame([
			'success' => true,
			'processed' => 1,
			'remaining' => 0,
			'errors' => [],
		], $controller->processQueue()->getData());
		$this->assertSame(QueuedRequest::STATUS_COMPLETED, $request->getStatus());
	}

	public function testDeliveryOnlyReconciliationIsDurablyBoundedAcrossControllerPasses(): void {
		$firstReload = $this->createReadyRequest(1);
		$secondReload = $this->createReadyRequest(2);
		$terminalReload = $this->createReadyRequest(3);
		$referenceId = $firstReload->getDeliveryReferenceId();
		$this->assertSame($referenceId, $secondReload->getDeliveryReferenceId());
		$this->assertSame($referenceId, $terminalReload->getDeliveryReferenceId());

		$rateLimitService = $this->createMock(RateLimitService::class);
		$rateLimitService->expects($this->exactly(3))
			->method('getResponseReadyRequests')
			->with(10)
			->willReturnOnConsecutiveCalls([$firstReload], [$secondReload], [$terminalReload]);
		$rateLimitService->expects($this->exactly(3))->method('isEnabled')->willReturn(false);
		$rateLimitService->expects($this->exactly(3))
			->method('getQueueStats')
			->willReturnOnConsecutiveCalls(
				['pending' => 0, 'processing' => 1, 'response_ready' => 1, 'completed' => 0, 'failed' => 0, 'total' => 1],
				['pending' => 0, 'processing' => 1, 'response_ready' => 1, 'completed' => 0, 'failed' => 0, 'total' => 1],
				['pending' => 0, 'processing' => 0, 'response_ready' => 0, 'completed' => 0, 'failed' => 1, 'total' => 1]
			);
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
			->willThrowException(new \RuntimeException('database unavailable'));
		$rateLimitService->expects($this->once())
			->method('markResponseDeliveryFailed')
			->with($terminalReload, 'Queued response delivery attempts exhausted')
			->willReturnCallback(static function (QueuedRequest $request): QueuedRequest {
				$request->setStatus(QueuedRequest::STATUS_FAILED);
				return $request;
			});

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->never())->method('findById');
		$botService = $this->createMock(BotService::class);
		$botService->expects($this->never())->method('processMessage');
		$referenceIds = [];
		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->exactly(2))
			->method('sendReplyToTalk')
			->willReturnCallback(function (string $room, string $message, int $replyTo, string $calledReference) use (&$referenceIds): bool {
				$this->assertSame('room-token', $room);
				$this->assertSame('Queued answer.', $message);
				$this->assertSame(123, $replyTo);
				$referenceIds[] = $calledReference;
				return true;
			});
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->exactly(3))
			->method('startRun')
			->willReturnOnConsecutiveCalls(87, 88, 89);
		$traceService->expects($this->exactly(5))->method('recordEvent');
		$traceService->expects($this->exactly(3))->method('finishRun');

		$controller = $this->createController($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);
		$first = $controller->processQueue()->getData();
		$second = $controller->processQueue()->getData();
		$terminal = $controller->processQueue()->getData();

		$this->assertSame(['Queued response delivered, but completion persistence failed: database unavailable'], $first['errors']);
		$this->assertSame($first['errors'], $second['errors']);
		$this->assertSame(['Queued response delivery attempts exhausted'], $terminal['errors']);
		$this->assertSame([$referenceId, $referenceId], $referenceIds);
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
			->method('getQueueStats')
			->willReturn(['pending' => 0, 'processing' => 1, 'response_ready' => 1, 'completed' => 0, 'failed' => 0, 'total' => 1]);
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
		$traceService->expects($this->once())->method('startRun')->willReturn(90);
		$traceService->expects($this->never())->method('recordEvent');
		$traceService->expects($this->once())->method('finishRun')->with(90, 'partial', $error);

		$controller = $this->createController($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);
		$this->assertSame([
			'success' => true,
			'processed' => 0,
			'remaining' => 0,
			'errors' => [$error],
		], $controller->processQueue()->getData());
	}

	public function testResponseReadyPersistenceFailureIsNotRelabeledAsAgentFailure(): void {
		$request = $this->createRequest();
		$bot = new Bot();
		$bot->setId(7);
		$bot->setIsActive(true);
		$bot->setMentionName('@bot');
		$rateLimitService = $this->createMock(RateLimitService::class);
		$rateLimitService->expects($this->once())->method('getResponseReadyRequests')->with(10)->willReturn([]);
		$rateLimitService->expects($this->once())->method('isEnabled')->willReturn(true);
		$rateLimitService->expects($this->exactly(2))
			->method('getQueueStats')
			->willReturnOnConsecutiveCalls(
				['pending' => 1, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 1],
				['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 1, 'total' => 1]
			);
		$rateLimitService->expects($this->once())->method('canProcess')->willReturn(true);
		$rateLimitService->expects($this->once())->method('getNextPending')->willReturn($request);
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
		$talkHandler->expects($this->never())->method('sendReplyToTalk');
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())->method('startRun')->willReturn(86);
		$traceService->expects($this->once())
			->method('recordEvent')
			->with(86, 'queue_persistence', ['status' => 'error', 'error_message' => $error]);
		$traceService->expects($this->once())->method('finishRun')->with(86, 'partial', $error);

		$controller = $this->createController($rateLimitService, $botService, $botMapper, $talkHandler, $traceService);
		$this->assertSame([
			'success' => true,
			'processed' => 0,
			'remaining' => 0,
			'errors' => [$error],
		], $controller->processQueue()->getData());
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

	private function createRequest(): QueuedRequest {
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
		return $request;
	}

	private function createReadyRequest(int $attempts = 1): QueuedRequest {
		$request = $this->createRequest();
		$request->setCreatedAt(1770000000);
		$request->setAttempts($attempts);
		$request->setStatus(QueuedRequest::STATUS_RESPONSE_READY);
		$request->setResult('Queued answer.');
		return $request;
	}

	private function createController(
		RateLimitService $rateLimitService,
		BotService $botService,
		BotMapper $botMapper,
		TalkHandler $talkHandler,
		TraceService $traceService,
	): SettingsController {
		return new SettingsController(
			'educai',
			$this->createMock(IRequest::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LLMClient::class),
			$rateLimitService,
			$this->createMock(RagIngestionService::class),
			$this->createMock(BotSourceMapper::class),
			$this->createMock(IJobList::class),
			$botService,
			$botMapper,
			$talkHandler,
			$this->createMock(AppIconService::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$traceService
		);
	}
}
