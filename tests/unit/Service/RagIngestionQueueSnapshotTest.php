<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Exception\EmbeddingRateLimitedException;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RagIngestionQueueSnapshotTest extends TestCase {
	public static function forceFlags(): array {
		return [
			'foreign forced job must not swallow our retry' => [false, true, true],
			'foreign ordinary job must not duplicate our forced retry' => [true, false, false],
		];
	}

	#[DataProvider('forceFlags')]
	public function testProviderRetryKeepsEachSourcesForceFlagWhenQueueReusesAJob(bool $ownForce, bool $otherForce, bool $expectRetry): void {
		$source = $this->source();
		$jobs = $this->queue([
			[81, ['sourceId' => 1, 'force' => $ownForce]],
			[82, ['sourceId' => 99, 'force' => $otherForce]],
		]);
		if ($expectRetry) {
			$jobs->expects($this->once())->method('scheduleAfter')->with(
				ReindexBotSourceJob::class,
				$this->greaterThanOrEqual(time() + 120),
				['sourceId' => 1, 'force' => true, 'attempt' => 1]
			);
		} else {
			$jobs->expects($this->never())->method('scheduleAfter');
		}
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('model');
		$client->expects($this->once())->method('embedTexts')->willThrowException(new EmbeddingRateLimitedException(120));

		$this->service($source, $jobs, $client)->ingestSourceById(1, true);

		$this->assertSame(KnowledgeSourceStatus::PENDING, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::STAGE_WAITING, $source->getProgressStage());
		$this->assertSame(KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public static function retryRows(): array {
		return [
			'old integer IDs and unrelated retry' => [81, 82, 1],
			'new string IDs and unrelated retry' => ['141125627021864960', '141125627021864961', 1],
			'old integer IDs and unrelated ordinary job' => [81, 82, 0],
			'new string IDs and unrelated ordinary job' => ['141125627021864960', '141125627021864961', 0],
		];
	}

	#[DataProvider('retryRows')]
	public function testExplicitReindexRemovesOnlyItsOriginalRetryIdsWhenQueueReusesAJob(int|string $firstId, int|string $secondId, int $otherAttempt): void {
		$jobs = $this->queue([
			[$firstId, ['sourceId' => 1, 'force' => true, 'attempt' => 2]],
			[$secondId, ['sourceId' => 1, 'force' => false, 'attempt' => 1]],
			[83, ['sourceId' => 1, 'force' => false]],
			[84, ['sourceId' => 99, 'force' => true, 'attempt' => $otherAttempt]],
		]);
		$removed = [];
		$jobs->expects($this->exactly(2))->method('removeById')->willReturnCallback(static function (int|string $id) use (&$removed): void {
			$removed[] = $id;
		});
		$jobs->expects($this->once())->method('add')->with(ReindexBotSourceJob::class, ['sourceId' => 1, 'force' => true]);
		$service = $this->service($this->source(), $jobs);

		$service->enqueueSource(1, true);

		$this->assertSame([$firstId, $secondId], $removed);
		$this->assertTrue($service->isQueued(1));
		$this->assertTrue($service->isQueued(99));
		$this->assertFalse($service->isQueued(100));
	}

	/**
	 * Nextcloud's JobList::buildJob can return one DI singleton, overwritten for each row.
	 * Store arguments and ID on the object itself so a clone really captures that row.
	 *
	 * @param list<array{0:int|string,1:array<string,mixed>}> $rows
	 */
	private function queue(array $rows): IJobList {
		$job = new class implements IJob {
			public int|string $id = 0;
			public array $argument = [];

			public function getId() {
				return $this->id;
			}

			public function getArgument() {
				return $this->argument;
			}
		};
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->with(ReindexBotSourceJob::class, null, 0)
			->willReturnCallback(static function () use ($job, $rows): \Generator {
				foreach ($rows as [$id, $argument]) {
					$job->id = $id;
					$job->argument = $argument;
					yield $job;
				}
			});
		return $jobs;
	}

	private function source(): BotSource {
		$source = new BotSource();
		$source->setId(1);
		$source->setBotId(7);
		$source->setOwnerUid('alice');
		$source->setNodeType('url');
		$source->setSourceUrl('https://example.com/document');
		$source->setChecksum('previous-complete-index');
		return $source;
	}

	private function service(BotSource $source, IJobList $jobs, ?EmbeddingClient $client = null): RagIngestionService {
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->with(1)->willReturn($source);
		$mapper->method('update')->willReturnArgument(0);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->never())->method('publishStaged');
		$fetcher = $this->createMock(UrlContentFetcher::class);
		$fetcher->method('fetchAndExtract')->willReturn(['content' => 'Some readable text.', 'content_type' => 'text/plain']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		return new RagIngestionService(
			$mapper,
			$embeddings,
			$client ?? $this->createMock(EmbeddingClient::class),
			$this->createMock(DoclingClient::class),
			$fetcher,
			$settings,
			$this->createMock(IRootFolder::class),
			$jobs,
			$this->createMock(LoggerInterface::class)
		);
	}
}
