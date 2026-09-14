<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Jobs;

use OCA\EducAI\Jobs\RefreshCatalogueEmbeddingsJob;
use OCA\EducAI\Jobs\ReindexCatalogueEmbeddingsJob;
use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\EmbeddingConfigurationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatalogueJobsTest extends TestCase {
	public function testJobsQueuedBeforeDisablingAndHourlyRefreshAreBothNoOps(): void {
		$service = $this->createMock(CatalogueEmbeddingService::class);
		$service->method('isEnabled')->willReturn(false);
		$service->expects($this->never())->method('reindex');
		$service->expects($this->never())->method('getStats');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');
		$jobs->expects($this->never())->method('has');
		$time = $this->createMock(ITimeFactory::class);
		$logger = $this->createMock(LoggerInterface::class);
		$configuration = $this->createMock(EmbeddingConfigurationService::class);
		$configuration->expects($this->never())->method('isRequired');
		$configuration->expects($this->never())->method('markQueued');

		(new ReindexCatalogueEmbeddingsJob($time, $service, $logger))->run(['trigger' => 'admin_manual']);
		$refresh = new RefreshCatalogueEmbeddingsJob($time, $service, $jobs, $logger, $configuration);
		(new \ReflectionMethod($refresh, 'run'))->invoke($refresh, null);
	}

	public function testEnabledManualJobPerformsIndexing(): void {
		$service = $this->createMock(CatalogueEmbeddingService::class);
		$service->method('isEnabled')->willReturn(true);
		$service->expects($this->once())->method('reindex')->willReturn(['success' => true, 'count' => 2]);
		$job = new ReindexCatalogueEmbeddingsJob(
			$this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class),
		);

		$job->run(null);
	}

	public function testHourlyRefreshQueuesTheSameIdentityAsManualReindexing(): void {
		$service = $this->createMock(CatalogueEmbeddingService::class);
		$service->method('isEnabled')->willReturn(true);
		$service->method('getStats')->willReturn(['needs_reindex' => true, 'last_indexed' => 100, 'count' => 2]);
		$service->expects($this->never())->method('reindex');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('has')->with(ReindexCatalogueEmbeddingsJob::class, null)->willReturn(false);
		$jobs->expects($this->once())->method('add')->with(ReindexCatalogueEmbeddingsJob::class);
		$configuration = $this->createMock(EmbeddingConfigurationService::class);
		$configuration->expects($this->once())->method('markQueued')->with('catalogue');
		$refresh = new RefreshCatalogueEmbeddingsJob(
			$this->createMock(ITimeFactory::class), $service, $jobs, $this->createMock(LoggerInterface::class),
			$configuration,
		);

		(new \ReflectionMethod($refresh, 'run'))->invoke($refresh, null);
	}

	public function testHourlyRefreshDoesNotDuplicatePendingManualWork(): void {
		$service = $this->createMock(CatalogueEmbeddingService::class);
		$service->method('isEnabled')->willReturn(true);
		$service->method('getStats')->willReturn(['needs_reindex' => true, 'last_indexed' => 100, 'count' => 2]);
		$service->expects($this->never())->method('reindex');
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->with(ReindexCatalogueEmbeddingsJob::class, null)->willReturn(true);
		$jobs->expects($this->never())->method('add');
		$configuration = $this->createMock(EmbeddingConfigurationService::class);
		$configuration->expects($this->never())->method('markQueued');
		$refresh = new RefreshCatalogueEmbeddingsJob(
			$this->createMock(ITimeFactory::class), $service, $jobs, $this->createMock(LoggerInterface::class),
			$configuration,
		);

		(new \ReflectionMethod($refresh, 'run'))->invoke($refresh, null);
	}

	public function testConfigurationChangeQueuesDespiteRecentSameModelIndex(): void {
		$service = $this->createMock(CatalogueEmbeddingService::class);
		$service->method('isEnabled')->willReturn(true);
		$service->method('getStats')->willReturn(['needs_reindex' => false, 'last_indexed' => time(), 'count' => 2]);
		$service->expects($this->never())->method('reindex');
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturn(false);
		$jobs->expects($this->once())->method('add')->with(ReindexCatalogueEmbeddingsJob::class);
		$configuration = $this->createMock(EmbeddingConfigurationService::class);
		$configuration->expects($this->once())->method('isRequired')->with('catalogue')->willReturn(true);
		$configuration->expects($this->once())->method('markQueued')->with('catalogue');
		$refresh = new RefreshCatalogueEmbeddingsJob(
			$this->createMock(ITimeFactory::class), $service, $jobs, $this->createMock(LoggerInterface::class), $configuration,
		);

		(new \ReflectionMethod($refresh, 'run'))->invoke($refresh, null);
	}

	public function testFailedEnqueueDoesNotClearConfigurationReminder(): void {
		$service = $this->createMock(CatalogueEmbeddingService::class);
		$service->method('isEnabled')->willReturn(true);
		$service->method('getStats')->willReturn(['needs_reindex' => true, 'last_indexed' => 100, 'count' => 2]);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturn(false);
		$jobs->method('add')->willThrowException(new \RuntimeException('Queue unavailable'));
		$configuration = $this->createMock(EmbeddingConfigurationService::class);
		$configuration->expects($this->never())->method('markQueued');
		$refresh = new RefreshCatalogueEmbeddingsJob(
			$this->createMock(ITimeFactory::class), $service, $jobs, $this->createMock(LoggerInterface::class), $configuration,
		);

		$this->expectException(\RuntimeException::class);
		(new \ReflectionMethod($refresh, 'run'))->invoke($refresh, null);
	}
}
