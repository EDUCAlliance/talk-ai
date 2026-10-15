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
		$pending = $this->source(KnowledgeSourceStatus::PENDING);
		$legacy = $this->source(KnowledgeSourceStatus::ERROR);
		$legacy->setErrorMessage('Source file or folder no longer exists');
		$ownerless = $this->source(KnowledgeSourceStatus::READY);
		$ownerless->setOwnerUid('');
		$mapper = $this->mapper([$url, $pending, $legacy, $ownerless]);
		$mapper->expects($this->never())->method('update');
		$locator = $this->createMock(RagSourceLocator::class);
		$locator->expects($this->never())->method('locate');

		$this->runJob($mapper, $locator, $this->createMock(IJobList::class));
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
