<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Db\QueuedRequestMapper;
use OCA\EducAI\Db\RateLimitState;
use OCA\EducAI\Db\RateLimitStateMapper;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RateLimitServiceTest extends TestCase {
    public function testProcessingLeaseExceedsTheAgentHardWallClockByItsDocumentedMargin(): void {
        $this->assertSame(
            AgentExecutor::HARD_MAX_WALL_CLOCK_SECONDS + RateLimitService::PROCESSING_LEASE_MARGIN_SECONDS,
            RateLimitService::PROCESSING_LEASE_SECONDS
        );
        $this->assertGreaterThan(
            AgentExecutor::HARD_MAX_WALL_CLOCK_SECONDS,
            RateLimitService::PROCESSING_LEASE_SECONDS
        );

        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('resetStaleProcessing')
            ->with(RateLimitService::PROCESSING_LEASE_SECONDS, QueuedRequest::MAX_ATTEMPTS)
            ->willReturn(0);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $service->cleanup();
    }

    public function testGetStatusReturnsSeparateChatAndEmbeddingStates(): void {
        $settings = new Settings();
        $settings->setRateLimitEnabled(true);
        $settings->setRateLimitSecond(2);
        $settings->setRateLimitMinute(30);
        $settings->setRateLimitHour(500);
        $settings->setRateLimitDay(1000);

        $chatState = $this->buildState(
            RateLimitService::ENDPOINT_CHAT,
            2,
            30,
            500,
            1000,
            2,
            29,
            197,
            990
        );
        $embeddingState = $this->buildState(
            RateLimitService::ENDPOINT_EMBEDDINGS,
            null,
            100,
            2000,
            4000,
            null,
            99,
            1994,
            3994
        );

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')
            ->willReturn($settings);

        $rateLimitMapper = $this->createMock(RateLimitStateMapper::class);
        $rateLimitMapper->expects($this->once())
            ->method('getOrCreate')
            ->with(RateLimitService::ENDPOINT_CHAT, 2, 30, 500, 1000)
            ->willReturn($chatState);
        $rateLimitMapper->expects($this->once())
            ->method('findByEndpoint')
            ->with(RateLimitService::ENDPOINT_EMBEDDINGS)
            ->willReturn($embeddingState);

        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->method('getQueueStats')
            ->willReturn([
                'pending' => 3,
                'processing' => 1,
                'completed' => 0,
                'failed' => 0,
                'total' => 4,
            ]);

        $service = new RateLimitService(
            $rateLimitMapper,
            $queueMapper,
            $settingsService,
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $status = $service->getStatus();

        $this->assertTrue($status['enabled']);
        $this->assertSame($chatState->jsonSerialize(), $status['state']);
        $this->assertSame($chatState->jsonSerialize(), $status['chat_status']['state']);
        $this->assertTrue($status['chat_status']['can_process']);
        $this->assertSame($embeddingState->jsonSerialize(), $status['embedding_status']['state']);
        $this->assertTrue($status['embedding_status']['observed']);
        $this->assertTrue($status['embedding_status']['can_process']);
        $this->assertSame(3, $status['queue_stats']['pending']);
    }

    public function testGetStatusLeavesEmbeddingStatusEmptyWhenNotObserved(): void {
        $settings = new Settings();
        $settings->setRateLimitEnabled(true);
        $settings->setRateLimitMinute(30);
        $settings->setRateLimitHour(500);
        $settings->setRateLimitDay(1000);
        $settings->setEmbeddingRateLimitMode('disabled');

        $chatState = $this->buildState(
            RateLimitService::ENDPOINT_CHAT,
            null,
            30,
            500,
            1000,
            null,
            30,
            500,
            1000
        );

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')
            ->willReturn($settings);

        $rateLimitMapper = $this->createMock(RateLimitStateMapper::class);
        $rateLimitMapper->expects($this->once())
            ->method('getOrCreate')
            ->willReturn($chatState);
        $rateLimitMapper->expects($this->once())
            ->method('findByEndpoint')
            ->with(RateLimitService::ENDPOINT_EMBEDDINGS)
            ->willThrowException(new DoesNotExistException('Embedding endpoint has not been observed'));

        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->method('getQueueStats')
            ->willReturn([
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'total' => 0,
            ]);

        $service = new RateLimitService(
            $rateLimitMapper,
            $queueMapper,
            $settingsService,
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $status = $service->getStatus();

        $this->assertFalse($status['embedding_status']['observed']);
        $this->assertSame('disabled', $status['embedding_status']['mode']);
        $this->assertNull($status['embedding_status']['state']);
        $this->assertNull($status['embedding_status']['can_process']);
    }

    public function testGetStatusBuildsConfiguredEmbeddingStateWhenModeIsCustom(): void {
        $settings = new Settings();
        $settings->setRateLimitEnabled(true);
        $settings->setRateLimitMinute(30);
        $settings->setRateLimitHour(500);
        $settings->setRateLimitDay(1000);
        $settings->setEmbeddingRateLimitMode('custom');
        $settings->setEmbeddingRateLimitSecond(4);
        $settings->setEmbeddingRateLimitMinute(120);
        $settings->setEmbeddingRateLimitHour(2400);
        $settings->setEmbeddingRateLimitDay(4800);

        $chatState = $this->buildState(
            RateLimitService::ENDPOINT_CHAT,
            null,
            30,
            500,
            1000,
            null,
            30,
            500,
            1000
        );
        $configuredEmbeddingState = $this->buildState(
            RateLimitService::ENDPOINT_EMBEDDINGS,
            4,
            120,
            2400,
            4800,
            4,
            120,
            2400,
            4800
        );

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')
            ->willReturn($settings);

        $rateLimitMapper = $this->createMock(RateLimitStateMapper::class);
        $rateLimitMapper->expects($this->exactly(2))
            ->method('getOrCreate')
            ->willReturnCallback(function (string $endpoint, ?int $second, ?int $minute, ?int $hour, ?int $day) use ($chatState, $configuredEmbeddingState): RateLimitState {
                if ($endpoint === RateLimitService::ENDPOINT_CHAT) {
                    $this->assertNull($second);
                    $this->assertSame(30, $minute);
                    $this->assertSame(500, $hour);
                    $this->assertSame(1000, $day);
                    return $chatState;
                }

                $this->assertSame(RateLimitService::ENDPOINT_EMBEDDINGS, $endpoint);
                $this->assertSame(4, $second);
                $this->assertSame(120, $minute);
                $this->assertSame(2400, $hour);
                $this->assertSame(4800, $day);
                return $configuredEmbeddingState;
            });
        $rateLimitMapper->expects($this->once())
            ->method('findByEndpoint')
            ->with(RateLimitService::ENDPOINT_EMBEDDINGS)
            ->willThrowException(new DoesNotExistException('Embedding endpoint has not been observed'));

        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->method('getQueueStats')
            ->willReturn([
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'total' => 0,
            ]);

        $service = new RateLimitService(
            $rateLimitMapper,
            $queueMapper,
            $settingsService,
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $status = $service->getStatus();

        $this->assertSame('custom', $status['embedding_status']['mode']);
        $this->assertSame('configured', $status['embedding_status']['source']);
        $this->assertFalse($status['embedding_status']['observed']);
        $this->assertTrue($status['embedding_status']['can_process']);
        $this->assertSame($configuredEmbeddingState->jsonSerialize(), $status['embedding_status']['state']);
    }

    public function testGetQueuedRequestReturnsNullWhenRequestDoesNotExist(): void {
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('findById')
            ->with(42)
            ->willThrowException(new DoesNotExistException('not found'));

        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertNull($service->getQueuedRequest(42));
    }

    public function testQueueRequestStoresTalkReplyAndThreadContext(): void {
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (QueuedRequest $request): QueuedRequest => $request);
        $queueMapper->method('countPending')
            ->willReturn(1);

        $service = new class(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        ) extends RateLimitService {
            public function scheduleProcessingJob(): void {}
        };

        $queued = $service->queueRequest(
            7,
            'room-token',
            'owner',
            'continue',
            '@personal-bot continue',
            100,
            42,
            42
        );

        $this->assertSame(42, $queued->getReplyToMessageId());
        $this->assertSame(42, $queued->getThreadRootMessageId());
    }

    public function testGetQueuedRequestDoesNotSwallowMapperFailures(): void {
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('findById')
            ->with(42)
            ->willThrowException(new \RuntimeException('database unavailable'));

        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        $service->getQueuedRequest(42);
    }

    public function testMarkResponseReadyPersistsAStoredNonRetryableResponse(): void {
        $request = new QueuedRequest();
        $request->setId(99);
        $request->setStatus(QueuedRequest::STATUS_PROCESSING);
        $request->setAttempts(2);
        $request->setError('previous retry');

        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('storeResponseReady')
            ->with(99, 2, 'Queued answer.')
            ->willReturn(true);

        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $stored = $service->markResponseReady($request, 'Queued answer.');

        $this->assertSame($request, $stored);
        $this->assertFalse($stored->canRetry());
        $this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $stored->getStatus());
        $this->assertSame('Queued answer.', $stored->getResult());
        $this->assertSame(0, $stored->getAttempts());
        $this->assertNull($stored->getError());
        $this->assertNull($stored->getProcessedAt());
    }

    public function testAtomicProcessingClaimAllowsOnlyOneOwnerAndMutatesOnlyWinner(): void {
        $first = $this->pendingRequestIdentityFixture();
        $second = $this->pendingRequestIdentityFixture();
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->exactly(2))
            ->method('claimProcessingAttempt')
            ->with(99, 0, QueuedRequest::MAX_ATTEMPTS, $this->isType('int'))
            ->willReturnOnConsecutiveCalls(true, false);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertSame($first, $service->markProcessing($first));
        $this->assertSame(QueuedRequest::STATUS_PROCESSING, $first->getStatus());
        $this->assertSame(1, $first->getAttempts());
        $this->assertNotNull($first->getProcessedAt());

        try {
            $service->markProcessing($second);
            $this->fail('The second processing owner was expected to lose the claim');
        } catch (\LogicException $e) {
            $this->assertSame(RateLimitService::PROCESSING_CLAIM_LOST_MESSAGE, $e->getMessage());
        }
        $this->assertSame(QueuedRequest::STATUS_PENDING, $second->getStatus());
        $this->assertSame(0, $second->getAttempts());
        $this->assertNull($second->getProcessedAt());
    }

    public function testProcessingClaimRejectsSnapshotFromBeforeOwnedRetryRelease(): void {
        $current = $this->pendingRequestIdentityFixture();
        $current->setAttempts(1);
        $stale = $this->pendingRequestIdentityFixture();
        $stale->setAttempts(1);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->exactly(2))
            ->method('claimProcessingAttempt')
            ->with(99, 1, QueuedRequest::MAX_ATTEMPTS, $this->isType('int'))
            ->willReturnOnConsecutiveCalls(true, false);
        $queueMapper->expects($this->once())
            ->method('releaseProcessingForRetry')
            ->with(99, 2, 'retry', QueuedRequest::MAX_ATTEMPTS)
            ->willReturn(true);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $service->markProcessing($current);
        $service->markProcessingRetry($current, 'retry');
        $this->assertSame(QueuedRequest::STATUS_PENDING, $current->getStatus());
        $this->assertSame(2, $current->getAttempts());

        try {
            $service->markProcessing($stale);
            $this->fail('The stale pre-retry snapshot was expected to lose the claim');
        } catch (\LogicException $e) {
            $this->assertSame(RateLimitService::PROCESSING_CLAIM_LOST_MESSAGE, $e->getMessage());
        }
        $this->assertSame(QueuedRequest::STATUS_PENDING, $stale->getStatus());
        $this->assertSame(1, $stale->getAttempts());
        $this->assertNull($stale->getProcessedAt());
    }

    public function testProcessingRetryIsBoundToTheOwnedAttempt(): void {
        $request = $this->pendingRequestIdentityFixture();
        $request->setStatus(QueuedRequest::STATUS_PROCESSING);
        $request->setAttempts(2);
        $request->setProcessedAt(time());
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('releaseProcessingForRetry')
            ->with(99, 2, 'temporary lookup failure', QueuedRequest::MAX_ATTEMPTS)
            ->willReturn(true);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $stored = $service->markProcessingRetry($request, 'temporary lookup failure');

        $this->assertSame(QueuedRequest::STATUS_PENDING, $stored->getStatus());
        $this->assertSame(2, $stored->getAttempts());
        $this->assertSame('temporary lookup failure', $stored->getError());
        $this->assertNull($stored->getProcessedAt());
    }

    public function testFailedAtomicDeliveryClaimLeavesStaleAttemptCountUntouched(): void {
        $request = $this->readyRequestIdentityFixture();
        $request->setAttempts(1);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('claimResponseDeliveryAttempt')
            ->with(99, 1, QueuedRequest::MAX_ATTEMPTS, $this->isType('int'))
            ->willThrowException(new \RuntimeException('database unavailable'));
        $queueMapper->expects($this->never())
            ->method('findById');
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        try {
            $service->markResponseDeliveryAttempt($request);
            $this->fail('Delivery-attempt persistence failure was expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('database unavailable', $e->getMessage());
        }

        $this->assertSame(1, $request->getAttempts());
        $this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $request->getStatus());
    }

    public function testAtomicDeliveryClaimAllowsOnlyOneOwner(): void {
        $stored = $this->readyRequestIdentityFixture();
        $stored->setAttempts(1);
        $stored->setStatus(QueuedRequest::STATUS_DELIVERING);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->exactly(2))
            ->method('claimResponseDeliveryAttempt')
            ->with(99, 0, QueuedRequest::MAX_ATTEMPTS, $this->isType('int'))
            ->willReturnOnConsecutiveCalls(true, false);
        $queueMapper->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn($stored);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );


        $firstWorkerRequest = $this->readyRequestIdentityFixture();
        $secondWorkerRequest = $this->readyRequestIdentityFixture();
        $this->assertSame($stored, $service->markResponseDeliveryAttempt($firstWorkerRequest));
        try {
            $service->markResponseDeliveryAttempt($secondWorkerRequest);
            $this->fail('A concurrent delivery claim was expected to be rejected');
        } catch (\LogicException $e) {
            $this->assertSame(RateLimitService::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE, $e->getMessage());
        }

        $this->assertSame(QueuedRequest::STATUS_DELIVERING, $stored->getStatus());
        $this->assertSame(1, $stored->getAttempts());
        $this->assertSame(0, $secondWorkerRequest->getAttempts());
    }

    public function testDeliveryClaimRejectsRehydratedLeaseOwnedByANewerAttempt(): void {
        $request = $this->readyRequestIdentityFixture();
        $request->setAttempts(0);
        $newerLease = $this->readyRequestIdentityFixture();
        $newerLease->setStatus(QueuedRequest::STATUS_DELIVERING);
        $newerLease->setAttempts(2);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('claimResponseDeliveryAttempt')
            ->with(99, 0, QueuedRequest::MAX_ATTEMPTS, $this->isType('int'))
            ->willReturn(true);
        $queueMapper->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn($newerLease);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        try {
            $service->markResponseDeliveryAttempt($request);
            $this->fail('A delivery lease from a newer attempt must not be adopted');
        } catch (\LogicException $e) {
            $this->assertSame(RateLimitService::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE, $e->getMessage());
        }
        $this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $request->getStatus());
        $this->assertSame(0, $request->getAttempts());
    }

    public function testDeliveryClaimReadFailureAfterSuccessfulCasLeavesLeaseForRecovery(): void {
        $request = $this->readyRequestIdentityFixture();
        $request->setAttempts(0);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('claimResponseDeliveryAttempt')
            ->with(99, 0, QueuedRequest::MAX_ATTEMPTS, $this->isType('int'))
            ->willReturn(true);
        $queueMapper->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willThrowException(new \RuntimeException('database unavailable'));
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        try {
            $service->markResponseDeliveryAttempt($request);
            $this->fail('Post-claim read failure was expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('database unavailable', $e->getMessage());
        }
        $this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $request->getStatus());
        $this->assertSame(0, $request->getAttempts());
    }

    public function testRetryFromOldDeliveryOwnerIsTypedAsOwnershipTransfer(): void {
        $request = $this->readyRequestIdentityFixture();
        $request->setStatus(QueuedRequest::STATUS_DELIVERING);
        $request->setAttempts(1);
        $newerOwner = $this->readyRequestIdentityFixture();
        $newerOwner->setStatus(QueuedRequest::STATUS_DELIVERING);
        $newerOwner->setAttempts(2);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('releaseResponseDeliveryForRetry')
            ->with(99, 1, 'HTTP 503', QueuedRequest::MAX_ATTEMPTS)
            ->willReturn(false);
        $queueMapper->expects($this->once())->method('findById')->with(99)->willReturn($newerOwner);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        try {
            $service->markResponseDeliveryRetry($request, 'HTTP 503');
            $this->fail('The old delivery owner was expected to lose retry persistence');
        } catch (\LogicException $e) {
            $this->assertSame(RateLimitService::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE, $e->getMessage());
        }
        $this->assertSame(QueuedRequest::STATUS_DELIVERING, $request->getStatus());
        $this->assertSame(1, $request->getAttempts());
    }

    public function testPermanentFailureFromOldDeliveryOwnerIsTypedAsOwnershipTransfer(): void {
        $request = $this->readyRequestIdentityFixture();
        $request->setStatus(QueuedRequest::STATUS_DELIVERING);
        $request->setAttempts(1);
        $newerOwner = $this->readyRequestIdentityFixture();
        $newerOwner->setStatus(QueuedRequest::STATUS_DELIVERING);
        $newerOwner->setAttempts(2);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('failResponseDelivery')
            ->with(99, 1, 'HTTP 400', $this->isType('int'), QueuedRequest::MAX_ATTEMPTS)
            ->willReturn(false);
        $queueMapper->expects($this->once())->method('findById')->with(99)->willReturn($newerOwner);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        try {
            $service->markResponseDeliveryFailed($request, 'HTTP 400');
            $this->fail('The old delivery owner was expected to lose failure persistence');
        } catch (\LogicException $e) {
            $this->assertSame(RateLimitService::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE, $e->getMessage());
        }
        $this->assertSame(QueuedRequest::STATUS_DELIVERING, $request->getStatus());
        $this->assertSame(1, $request->getAttempts());
    }

    public function testExhaustedPendingRequestsAreTerminalizedBeforeSelection(): void {
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('failExhaustedPending')
            ->with(QueuedRequest::MAX_ATTEMPTS, $this->isType('int'))
            ->willReturn(2);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Reconciled exhausted pending queue requests', ['count' => 2]);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $logger
        );

        $this->assertSame(2, $service->failExhaustedPendingRequests());
    }

    public function testStateTransitionRejectsAStaleResponseReadyDeliveryClaim(): void {
        $staleWorkerRequest = $this->readyRequestIdentityFixture();
        $staleWorkerRequest->setAttempts(0);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('claimResponseDeliveryAttempt')
            ->with(99, 0, QueuedRequest::MAX_ATTEMPTS, $this->isType('int'))
            ->willReturn(false);
        $queueMapper->expects($this->never())
            ->method('findById');
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        try {
            $service->markResponseDeliveryAttempt($staleWorkerRequest);
            $this->fail('A stale response-ready delivery claim was expected to be rejected');
        } catch (\LogicException $e) {
            $this->assertSame(RateLimitService::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE, $e->getMessage());
        }

        $this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $staleWorkerRequest->getStatus());
        $this->assertSame(0, $staleWorkerRequest->getAttempts());
    }

    public function testMarkFailedCreatesANonRetryableTerminalState(): void {
        $request = $this->readyRequestIdentityFixture();
        $request->setAttempts(1);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $failed = $service->markFailed($request, 'Delivery failed');

        $this->assertSame(QueuedRequest::STATUS_FAILED, $failed->getStatus());
        $this->assertSame(QueuedRequest::MAX_ATTEMPTS, $failed->getAttempts());
        $this->assertSame('Delivery failed', $failed->getError());
        $this->assertFalse($failed->canRetry());
    }

    public function testRetryableDeliveryReturnsOwnedResponseToDeliveryLaneWithoutResettingAttemptCount(): void {
        $request = $this->readyRequestIdentityFixture();
        $request->setStatus(QueuedRequest::STATUS_DELIVERING);
        $request->setAttempts(1);
        $request->setProcessedAt(time());
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('releaseResponseDeliveryForRetry')
            ->with(99, 1, 'Talk API returned retryable HTTP 503', QueuedRequest::MAX_ATTEMPTS)
            ->willReturn(true);

        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $stored = $service->markResponseDeliveryRetry($request, 'Talk API returned retryable HTTP 503');

        $this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $stored->getStatus());
        $this->assertSame(1, $stored->getAttempts());
        $this->assertSame('Talk API returned retryable HTTP 503', $stored->getError());
        $this->assertNull($stored->getProcessedAt());
    }

    public function testRetryableDeliveryBecomesTerminalWhenAttemptBudgetIsExhausted(): void {
        $request = $this->readyRequestIdentityFixture();
        $request->setStatus(QueuedRequest::STATUS_DELIVERING);
        $request->setAttempts(QueuedRequest::MAX_ATTEMPTS);
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->never())->method('releaseResponseDeliveryForRetry');
        $queueMapper->expects($this->once())
            ->method('failResponseDelivery')
            ->with(
                99,
                QueuedRequest::MAX_ATTEMPTS,
                'Talk delivery attempts exhausted after: Talk API returned retryable HTTP 503',
                $this->isType('int'),
                QueuedRequest::MAX_ATTEMPTS
            )
            ->willReturn(true);

        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $stored = $service->markResponseDeliveryRetry($request, 'Talk API returned retryable HTTP 503');

        $this->assertSame(QueuedRequest::STATUS_FAILED, $stored->getStatus());
        $this->assertSame(QueuedRequest::MAX_ATTEMPTS, $stored->getAttempts());
        $this->assertStringStartsWith('Talk delivery attempts exhausted', (string)$stored->getError());
    }

    public function testStaleDeliveryRecoveryUsesLeaseCutoffAndReportsRecoveredRows(): void {
        $before = time();
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('recoverStaleResponseDeliveries')
            ->willReturnCallback(function (int $cutoff, int $recoveredAt, int $maxAttempts) use ($before): int {
                $this->assertGreaterThanOrEqual($before, $recoveredAt);
                $this->assertSame(120, $recoveredAt - $cutoff);
                $this->assertSame(QueuedRequest::MAX_ATTEMPTS, $maxAttempts);
                return 2;
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Recovered stale queued Talk deliveries', ['count' => 2, 'lease_seconds' => 120]);
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $logger
        );

        $this->assertSame(2, $service->recoverStaleResponseDeliveries());
    }

    public function testLateDeliveryFailureReturnsCompletedStateWithoutOverwritingIt(): void {
        $staleFailure = $this->readyRequestIdentityFixture();
        $completed = $this->readyRequestIdentityFixture();
        $completed->setStatus(QueuedRequest::STATUS_COMPLETED);
        $completed->setAttempts(2);

        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('failResponseDelivery')
            ->with(99, 0, 'Late Talk failure', $this->isType('int'), QueuedRequest::MAX_ATTEMPTS)
            ->willReturn(false);
        $queueMapper->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn($completed);
        $queueMapper->expects($this->never())
            ->method('update');
        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $stored = $service->markResponseDeliveryFailed($staleFailure, 'Late Talk failure');

        $this->assertSame($completed, $stored);
        $this->assertSame(QueuedRequest::STATUS_COMPLETED, $stored->getStatus());
        $this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $staleFailure->getStatus());
    }

    public function testResponseReadySelectionDelegatesToDedicatedMapperLane(): void {
        $ready = new QueuedRequest();
        $ready->setStatus(QueuedRequest::STATUS_RESPONSE_READY);
        $ready->setResult('Queued answer.');
        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('findResponseReady')
            ->with(7)
            ->willReturn([$ready]);

        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertSame([$ready], $service->getResponseReadyRequests(7));
    }

    public function testDeliveryReferenceIdSurvivesEntityRehydration(): void {
        $first = $this->readyRequestIdentityFixture();
        $rehydrated = $this->readyRequestIdentityFixture();
        $different = $this->readyRequestIdentityFixture();
        $different->setId(100);

        $this->assertNotSame($first, $rehydrated);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $first->getDeliveryReferenceId());
        $this->assertSame($first->getDeliveryReferenceId(), $rehydrated->getDeliveryReferenceId());
        $this->assertNotSame($first->getDeliveryReferenceId(), $different->getDeliveryReferenceId());
    }

    public function testFailedCompletionPersistenceLeavesOwnedDeliveryForLeaseRecovery(): void {
        $request = new QueuedRequest();
        $request->setId(99);
        $request->setStatus(QueuedRequest::STATUS_DELIVERING);
        $request->setAttempts(1);
        $request->setResult('Queued answer.');

        $queueMapper = $this->createMock(QueuedRequestMapper::class);
        $queueMapper->expects($this->once())
            ->method('completeResponseDelivery')
            ->with(99, 1, 'Queued answer.', $this->isType('int'))
            ->willThrowException(new \RuntimeException('database unavailable'));
        $queueMapper->expects($this->never())
            ->method('findById');

        $service = new RateLimitService(
            $this->createMock(RateLimitStateMapper::class),
            $queueMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(LoggerInterface::class)
        );

        try {
            $service->markCompleted($request, 'Queued answer.');
            $this->fail('Completion persistence failure was expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('database unavailable', $e->getMessage());
        }

        $this->assertSame(QueuedRequest::STATUS_DELIVERING, $request->getStatus());
        $this->assertSame('Queued answer.', $request->getResult());
        $this->assertNull($request->getProcessedAt());
    }

    private function buildState(
        string $endpointKey,
        ?int $limitSecond,
        ?int $limitMinute,
        ?int $limitHour,
        ?int $limitDay,
        ?int $remainingSecond,
        ?int $remainingMinute,
        ?int $remainingHour,
        ?int $remainingDay
    ): RateLimitState {
        $now = time();
        $state = new RateLimitState();
        $state->setEndpointKey($endpointKey);
        $state->setLimitSecond($limitSecond);
        $state->setLimitMinute($limitMinute);
        $state->setLimitHour($limitHour);
        $state->setLimitDay($limitDay);
        $state->setRemainingSecond($remainingSecond);
        $state->setRemainingMinute($remainingMinute);
        $state->setRemainingHour($remainingHour);
        $state->setRemainingDay($remainingDay);
        $state->setResetSecond($limitSecond !== null ? $now + 1 : null);
        $state->setResetMinuteAt($limitMinute !== null ? $now + 60 : null);
        $state->setResetHour($limitHour !== null ? $now + 3600 : null);
        $state->setUpdatedAt($now);

        return $state;
    }

    private function readyRequestIdentityFixture(): QueuedRequest {
        $request = new QueuedRequest();
        $request->setId(99);
        $request->setRoomToken('room-token');
        $request->setCreatedAt(1770000000);
        $request->setStatus(QueuedRequest::STATUS_RESPONSE_READY);
        $request->setResult('Queued answer.');
        return $request;
    }

    private function pendingRequestIdentityFixture(): QueuedRequest {
        $request = new QueuedRequest();
        $request->setId(99);
        $request->setStatus(QueuedRequest::STATUS_PENDING);
        $request->setAttempts(0);
        $request->setCreatedAt(1770000000);
        return $request;
    }
}
