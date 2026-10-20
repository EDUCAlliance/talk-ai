<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Jobs;

use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Jobs\CleanupOrphanedSourcesJob;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\RagSourceLocator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupOrphanedSourcesJobTest extends TestCase {
	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function unreachableStates(): array {
		return [
			'missing' => [RagSourceLocator::MISSING, KnowledgeSourceStatus::SOURCE_MISSING],
			'denied' => [RagSourceLocator::DENIED, KnowledgeSourceStatus::SOURCE_ACCESS_DENIED],
		];
	}

	#[DataProvider('unreachableStates')]
	public function testUnreachableSourceIsExcludedButNotDeleted(string $state, string $code): void {
		$source = $this->source(KnowledgeSourceStatus::READY);
		$mapper = $this->mapper([$source]);
		$mapper->expects($this->once())->method('update')->with($source);
		$mapper->expects($this->never())->method('delete');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$this->runJob($mapper, $this->locator($state), $jobs);

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame($code, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
		$this->assertSame('index-checksum', $source->getChecksum());
	}

	public function testSourceThatIsReachableAgainIsReindexed(): void {
		$source = $this->source(KnowledgeSourceStatus::UNAVAILABLE);
		$mapper = $this->mapper([$source]);
		$mapper->expects($this->never())->method('update');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(ReindexBotSourceJob::class, ['sourceId' => 3, 'force' => false]);

		$this->runJob($mapper, $this->locator(RagSourceLocator::FOUND), $jobs);
	}

	public function testTemporaryLookupFailureChangesNothing(): void {
		$source = $this->source(KnowledgeSourceStatus::READY);
		$mapper = $this->mapper([$source]);
		$mapper->expects($this->never())->method('update');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$this->runJob($mapper, $this->locator(RagSourceLocator::UNAVAILABLE, new \RuntimeException('database is locked')), $jobs);

		$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
	}

	public function testReachableSourceAndAlreadyExcludedSourceAreLeftAlone(): void {
		$ready = $this->source(KnowledgeSourceStatus::READY);
		$mapper = $this->mapper([$ready]);
		$mapper->expects($this->never())->method('update');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');
		$this->runJob($mapper, $this->locator(RagSourceLocator::FOUND), $jobs);

		$excluded = $this->source(KnowledgeSourceStatus::UNAVAILABLE);
		$mapper = $this->mapper([$excluded]);
		$mapper->expects($this->never())->method('update');
		$this->runJob($mapper, $this->locator(RagSourceLocator::MISSING), $jobs);
	}

	public function testSourcesWithoutAFileToCheckAreSkipped(): void {
		$url = $this->source(KnowledgeSourceStatus::READY);
		$url->setNodeType('url');
		$legacy = $this->source(KnowledgeSourceStatus::ERROR);
		$legacy->setErrorMessage('Source file or folder no longer exists');
		$ownerless = $this->source(KnowledgeSourceStatus::READY);
		$ownerless->setOwnerUid('');
		$mapper = $this->mapper([$url, $legacy, $ownerless]);
		$mapper->expects($this->never())->method('update');
		$locator = $this->createMock(RagSourceLocator::class);
		$locator->expects($this->never())->method('locate');

		$this->runJob($mapper, $locator, $this->createMock(IJobList::class));
	}

	public function testWaitingSourceWhoseFolderWasDeletedIsExcludedAtOnce(): void {
		// A provider pause must not keep a deleted folder answerable until the retry runs.
		$source = $this->source(KnowledgeSourceStatus::PENDING);
		$source->setProgressStage(KnowledgeSourceStatus::STAGE_WAITING);
		$source->setUpdatedAt(time() - 7200);
		$mapper = $this->mapper([$source]);
		$mapper->expects($this->once())->method('update')->with($source);

		$this->runJob($mapper, $this->locator(RagSourceLocator::MISSING), $this->jobs([3]));

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::SOURCE_MISSING, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public function testPendingSourceWithoutJobOrProgressIsMarkedInterrupted(): void {
		$source = $this->source(KnowledgeSourceStatus::PENDING);
		$source->setProgressStage('embedding');
		$source->setUpdatedAt(time() - 4 * 3600);
		$mapper = $this->mapper([$source]);
		$mapper->expects($this->once())->method('update')->with($source);
		$locator = $this->createMock(RagSourceLocator::class);
		$locator->expects($this->never())->method('locate');

		$this->runJob($mapper, $locator, $this->jobs([]));

		$this->assertSame(KnowledgeSourceStatus::ERROR, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::INDEXING_INTERRUPTED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
		$this->assertSame('index-checksum', $source->getChecksum());
	}

	public function testRunningOrQueuedSourcesAreLeftToTheirJob(): void {
		$running = $this->source(KnowledgeSourceStatus::PENDING);
		$running->setProgressStage('embedding');
		$running->setUpdatedAt(time() - 60);
		$queued = $this->source(KnowledgeSourceStatus::PENDING);
		$queued->setId(4);
		$queued->setUpdatedAt(time() - 4 * 3600);
		$mapper = $this->mapper([$running, $queued]);
		$mapper->expects($this->never())->method('update');

		$this->runJob($mapper, $this->locator(RagSourceLocator::FOUND), $this->jobs([4]));

		$this->assertSame(KnowledgeSourceStatus::PENDING, $running->getStatus());
		$this->assertSame(KnowledgeSourceStatus::PENDING, $queued->getStatus());
	}

	public function testReachableAgainSourceWithAQueuedJobIsNotQueuedTwice(): void {
		$source = $this->source(KnowledgeSourceStatus::UNAVAILABLE);
		$mapper = $this->mapper([$source]);
		$jobs = $this->jobs([3]);
		$jobs->expects($this->never())->method('add');

		$this->runJob($mapper, $this->locator(RagSourceLocator::FOUND), $jobs);
	}

	/**
	 * @param list<int> $sourceIds Sources with a queued indexing job
	 */
	private function jobs(array $sourceIds): IJobList&\PHPUnit\Framework\MockObject\MockObject {
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->willReturnCallback(function () use ($sourceIds): \Generator {
			foreach ($sourceIds as $id) {
				$job = $this->createMock(\OCP\BackgroundJob\IJob::class);
				$job->method('getArgument')->willReturn(['sourceId' => $id, 'force' => true, 'attempt' => 1]);
				yield $job;
			}
		});
		return $jobs;
	}

	private function source(string $status): BotSource {
		$source = new BotSource();
		$source->setId(3);
		$source->setBotId(7);
		$source->setNodeId(42);
		$source->setNodeType('folder');
		$source->setOwnerUid('alice');
		$source->setStatus($status);
		$source->setChecksum('index-checksum');
		return $source;
	}

	/**
	 * @param list<BotSource> $sources
	 */
	private function mapper(array $sources): BotSourceMapper&\PHPUnit\Framework\MockObject\MockObject {
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findAll')->willReturn($sources);
		return $mapper;
	}

	private function locator(string $state, ?\Throwable $exception = null): RagSourceLocator {
		$locator = $this->createMock(RagSourceLocator::class);
		$locator->method('locate')->willReturn([
			'state' => $state,
			'node' => $state === RagSourceLocator::FOUND ? $this->createMock(Folder::class) : null,
			'folder' => $state === RagSourceLocator::FOUND ? $this->createMock(Folder::class) : null,
			'exception' => $exception,
		]);
		return $locator;
	}

	private function runJob(BotSourceMapper $mapper, RagSourceLocator $locator, IJobList $jobs): void {
		$job = new CleanupOrphanedSourcesJob(
			$this->createMock(ITimeFactory::class),
			$mapper,
			$locator,
			$jobs,
			$this->createMock(LoggerInterface::class)
		);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
