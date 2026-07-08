<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Db\QueuedRequestMapper;
use OCA\EducAI\Db\RateLimitState;
use OCA\EducAI\Db\RateLimitStateMapper;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RateLimitServiceTest extends TestCase {
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
}
