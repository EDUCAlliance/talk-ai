<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Jobs;

use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Jobs\SyncRagSourceJob;
use OCA\EducAI\Service\RagIngestionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

class SyncRagSourceJobTest extends TestCase {
	public function testConcurrentSchedulersWaitBrieflyThenRetainFollowupEvenUnderContention(): void {
		foreach ([1, 4] as $failures) {
			$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
			$calls = 0;
			$locking->method('acquireLock')->willReturnCallback(static function (string $key) use (&$calls, $failures): void {
				TestCase::assertSame('educai:rag-sync-queue:1', $key);
				if ($calls++ < $failures) { throw new \OCP\Lock\LockedException(); }
			});
			$locking->expects($failures === 4 ? $this->never() : $this->once())->method('releaseLock');
			$jobs = $this->createMock(IJobList::class);
			$jobs->method('has')->willReturn(false);
			$jobs->expects($this->once())->method('scheduleAfter')->with(SyncRagSourceJob::class, $this->anything(), ['sourceId' => 1]);
			SyncRagSourceJob::schedule($jobs, $locking, 1);
			$this->assertSame($failures === 4 ? 4 : 2, $calls);
		}
	}

	public function testQueueLockIsReleasedWhenQueueWriteFails(): void {
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->expects($this->once())->method('releaseLock')->with('educai:rag-sync-queue:1', 2);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willThrowException(new \RuntimeException('database unavailable'));
		$this->expectException(\RuntimeException::class);
		SyncRagSourceJob::schedule($jobs, $locking, 1);
	}

	public function testWriteDuringIndexingRetainsOneFollowupUntilOldRunFinishes(): void {
		$source = new BotSource();
		$source->setId(1);
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findById')->with(1)->willReturn($source);
		$ingestion = $this->createMock(RagIngestionService::class);
		$ingestion->method('isBusy')->willReturnOnConsecutiveCalls(true, true, false);
		$ingestion->expects($this->once())->method('ingestSourceById')->with(1, false, 0, true);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturnOnConsecutiveCalls(false, true);
		$jobs->expects($this->once())->method('scheduleAfter')->with(SyncRagSourceJob::class, $this->callback(static fn (int $at): bool => $at >= time() + 29), ['sourceId' => 1]);
		$job = new SyncRagSourceJob($this->createMock(ITimeFactory::class), $sources, $ingestion, $jobs);
		$job->run(['sourceId' => 1]);
		$job->run(['sourceId' => 1]);
		$job->run(['sourceId' => 1]);
	}

	public function testRemovedSourceAndInvalidArgumentsAreHarmless(): void {
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->expects($this->once())->method('findById')->willThrowException(new DoesNotExistException('removed'));
		$ingestion = $this->createMock(RagIngestionService::class);
		$ingestion->expects($this->never())->method('ingestSourceById');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('scheduleAfter');
		$job = new SyncRagSourceJob($this->createMock(ITimeFactory::class), $sources, $ingestion, $jobs);
		foreach ([null, [], ['sourceId' => '1'], ['sourceId' => 0], ['sourceId' => 1]] as $args) {
			$job->run($args);
		}
	}

	public function testUrlSourceIsNotRefreshedByFileSyncJob(): void {
		$source = new BotSource();
		$source->setNodeType('url');
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findById')->willReturn($source);
		$ingestion = $this->createMock(RagIngestionService::class);
		$ingestion->expects($this->never())->method('ingestSourceById');
		(new SyncRagSourceJob($this->createMock(ITimeFactory::class), $sources, $ingestion, $this->createMock(IJobList::class)))->run(['sourceId' => 1]);
	}
}
