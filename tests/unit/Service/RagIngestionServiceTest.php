<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\Embedding;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Exception\DoclingEmptyResultException;
use OCA\EducAI\Exception\EmbeddingRateLimitedException;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RagIngestionServiceTest extends TestCase {
	public function testFailedDocumentDoesNotReplaceAnExistingIndexWithAnIncompleteFolder(): void {
		$source = $this->source();
		$good = $this->file('good.txt', 'text/plain', 'A readable document.');
		$bad = $this->file('bad.pdf', 'application/pdf');
		$folder = $this->folder([$good, $bad]);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(true);
		$docling->method('isSupported')->willReturnCallback(static fn (File $file): bool => $file === $bad);
		$docling->expects($this->once())->method('convertToMarkdown')->with($bad)
			->willThrowException(new Exception('Docling conversion was incomplete'));
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->never())->method('deleteBySource');
		$embeddings->expects($this->never())->method('insert');
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->expects($this->never())->method('embedTexts');

		$this->service($folder, $docling, $embeddings, $embeddingClient)->ingestSource($source, true);

		$this->assertSame('error', $source->getStatus());
		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::EXTRACTION_FAILED, $stored['code']);
		$this->assertSame(['failed' => 1, 'total' => 2], $stored['params']);
		$this->assertStringContainsString('bad.pdf: Docling conversion was incomplete', $stored['detail']);
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testMetadataCompanionsAreSkippedBeforeConversion(): void {
		$source = $this->source();
		$text = $this->file('news.md', 'text/markdown', 'The real announcement.');
		$appleDouble = $this->file('._news.jpg', 'image/jpeg', str_repeat("\0", 220));
		$finder = $this->file('.DS_Store', 'application/octet-stream');
		$macosx = $this->createMock(Folder::class);
		$macosx->method('getName')->willReturn('__MACOSX');
		$macosx->expects($this->never())->method('getDirectoryListing');
		$folder = $this->folder([$text, $appleDouble, $finder, $macosx]);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(true);
		$docling->method('isSupported')->willReturnCallback(static fn (File $file): bool => str_ends_with((string)$file->getName(), '.jpg'));
		$docling->expects($this->never())->method('convertToMarkdown');
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([]);
		$embeddings->expects($this->once())->method('insertStaged');
		$embeddings->expects($this->once())->method('publishStaged')->with(1, 'model', $this->anything(), 1);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->expects($this->once())->method('embedTexts')
			->with(['The real announcement.'], 'model', ['purpose' => 'rag_source', 'source_id' => 1, 'bot_id' => 7])
			->willReturn([[0.1, 0.2]]);

		$this->service($folder, $docling, $embeddings, $embeddingClient)->ingestSource($source, true);

		$this->assertSame('ready', $source->getStatus());
		$this->assertNull($source->getErrorMessage());
		$this->assertNotSame('previous-complete-index', $source->getChecksum());
	}

	public function testFolderWithOnlyMetadataReportsThatCategory(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('._a.pdf', 'application/pdf'), $this->file('Thumbs.db', 'application/octet-stream')]);

		$this->service($folder)->ingestSource($source, true);

		$this->assertSame('error', $source->getStatus());
		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::METADATA_ONLY, $stored['code']);
		$this->assertSame(['metadata' => 2], $stored['params']);
		$this->assertNull($source->getChecksum());
	}

	public function testUnsupportedAndEmptyFilesAreToldApart(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('notes.odt', 'application/vnd.oasis.opendocument.text'), $this->file('plan.pages', 'application/octet-stream')]);

		$this->service($folder)->ingestSource($source, true);

		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::NO_SUPPORTED_FILES, $stored['code']);
		$this->assertSame(2, $stored['params']['unsupported']);
		$this->assertSame(['odt', 'pages'], $stored['params']['extensions']);
		$this->assertFalse($stored['params']['converter_disabled']);

		$source = $this->source();
		$folder = $this->folder([$this->file('empty.txt', 'text/plain', "  \n")]);
		$this->service($folder)->ingestSource($source, true);
		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::NO_TEXT, $stored['code']);
		$this->assertSame(['empty' => 1, 'unsupported' => 0], $stored['params']);
	}

	public function testDocumentsNeedingTheDisabledConverterAreNamed(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('report.pdf', 'application/pdf')]);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(false);
		$docling->method('isSupported')->willReturn(true);

		$this->service($folder, $docling)->ingestSource($source, true);

		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::NO_SUPPORTED_FILES, $stored['code']);
		$this->assertTrue($stored['params']['converter_disabled']);
	}

	public function testDocumentWithoutTextIsSkippedWithANoticeInsteadOfFailingTheSource(): void {
		$source = $this->source();
		$photo = $this->file('team-photo.jpg', 'image/jpeg');
		$text = $this->file('readme.txt', 'text/plain', 'Team overview.');
		$folder = $this->folder([$photo, $text, $this->file('legacy.odt', 'application/vnd.oasis.opendocument.text')]);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(true);
		$docling->method('isSupported')->willReturnCallback(static fn (File $file): bool => $file === $photo);
		$docling->method('convertToMarkdown')->willThrowException(new DoclingEmptyResultException('no text'));
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willReturn([[0.3]]);

		$this->service($folder, $docling, null, $embeddingClient)->ingestSource($source, true);

		$this->assertSame('ready', $source->getStatus());
		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::SKIPPED_FILES, $stored['code']);
		$this->assertSame(1, $stored['params']['indexed']);
		$this->assertSame(1, $stored['params']['unsupported']);
		$this->assertSame(1, $stored['params']['empty']);
		$this->assertSame(['odt'], $stored['params']['extensions']);
	}

	/**
	 * @return array<string,array{0:\Closure(Folder):void,1:string}>
	 */
	public static function unreachableSources(): array {
		return [
			'empty owner lookup' => [static function (Folder $folder): void {
				$folder->method('getById')->willReturn([]);
			}, KnowledgeSourceStatus::SOURCE_MISSING],
			'access denied' => [static function (Folder $folder): void {
				$folder->method('getById')->willThrowException(new NotPermittedException('denied'));
			}, KnowledgeSourceStatus::SOURCE_ACCESS_DENIED],
		];
	}

	#[DataProvider('unreachableSources')]
	public function testUnreachableSourceKeepsItsIndexButIsExcludedFromAnswers(\Closure $lookup, string $code): void {
		$source = $this->source();
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$lookup($folder);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->never())->method('deleteBySource');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('scheduleAfter');

		$this->service($folder, null, $embeddings, null, $jobs)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame($code, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testQueuedReindexKeepsAnUnreachableSourceOutOfAnswers(): void {
		$source = $this->source();
		$source->setStatus(KnowledgeSourceStatus::UNAVAILABLE);
		$source->setErrorMessage(KnowledgeSourceStatus::encode(KnowledgeSourceStatus::SOURCE_ACCESS_DENIED));
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->with(1)->willReturn($source);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(ReindexBotSourceJob::class, ['sourceId' => 1, 'force' => true]);
		$service = new RagIngestionService(
			$mapper,
			$this->createMock(EmbeddingMapper::class),
			$this->createMock(EmbeddingClient::class),
			$this->createMock(DoclingClient::class),
			$this->createMock(UrlContentFetcher::class),
			$this->createMock(SettingsService::class),
			$this->createMock(IRootFolder::class),
			$jobs,
			$this->createMock(LoggerInterface::class)
		);

		$service->enqueueSource(1, true);

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::SOURCE_ACCESS_DENIED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public function testUnreachableSourceStaysExcludedWhenTheLookupFailsTemporarily(): void {
		$source = $this->source();
		$source->setStatus(KnowledgeSourceStatus::UNAVAILABLE);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willThrowException(new \RuntimeException('storage not available'));

		$this->service($folder)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::SOURCE_TEMPORARILY_UNAVAILABLE, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public function testUnreachableSourceIsIndexedAgainOnceItIsFound(): void {
		$source = $this->source();
		$source->setStatus(KnowledgeSourceStatus::UNAVAILABLE);
		$source->setErrorMessage(KnowledgeSourceStatus::encode(KnowledgeSourceStatus::SOURCE_MISSING));
		$folder = $this->folder([$this->file('a.txt', 'text/plain', 'Some text.')]);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willReturn([[0.1, 0.2]]);

		$this->service($folder, null, null, $embeddingClient)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
		$this->assertNull($source->getErrorMessage());
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function previousStatuses(): array {
		return ['ready source' => ['ready'], 'source found again' => [KnowledgeSourceStatus::UNAVAILABLE]];
	}

	#[DataProvider('previousStatuses')]
	public function testFileTheOwnerMayNoLongerReadExcludesTheSourceFromAnswers(string $previousStatus): void {
		$source = $this->source();
		$source->setStatus($previousStatus);
		$readable = $this->file('a.txt', 'text/plain', 'Readable text.');
		$denied = $this->file('b.txt', 'text/plain');
		$denied->method('getContent')->willThrowException(new NotPermittedException('access control rule'));
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->never())->method('deleteBySource');
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->expects($this->never())->method('embedTexts');

		$this->service($this->folder([$readable, $denied]), null, $embeddings, $embeddingClient)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::SOURCE_ACCESS_DENIED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testConverterInputTheOwnerMayNoLongerReadExcludesTheSourceFromAnswers(): void {
		$source = $this->source();
		$pdf = $this->file('a.pdf', 'application/pdf');
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(true);
		$docling->method('isSupported')->willReturn(true);
		$docling->method('convertToMarkdown')->willThrowException(new \OCP\Files\ForbiddenException('blocked', false));

		$this->service($this->folder([$pdf]), $docling)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::SOURCE_ACCESS_DENIED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public function testExplicitReindexReplacesScheduledRetriesOfThatSourceOnly(): void {
		$source = $this->source();
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->willReturn($source);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->with(ReindexBotSourceJob::class, null, 0)->willReturn([
			$this->job(81, ['sourceId' => 1, 'force' => true, 'attempt' => 2]),
			$this->job(82, ['sourceId' => 1, 'force' => false]),
			$this->job(83, ['sourceId' => 2, 'force' => true, 'attempt' => 1]),
		]);
		$removed = [];
		$jobs->method('removeById')->willReturnCallback(static function (int $id) use (&$removed): void {
			$removed[] = $id;
		});
		$jobs->expects($this->once())->method('add')->with(ReindexBotSourceJob::class, ['sourceId' => 1, 'force' => true]);
		$service = new RagIngestionService(
			$mapper,
			$this->createMock(EmbeddingMapper::class),
			$this->createMock(EmbeddingClient::class),
			$this->createMock(DoclingClient::class),
			$this->createMock(UrlContentFetcher::class),
			$this->createMock(SettingsService::class),
			$this->createMock(IRootFolder::class),
			$jobs,
			$this->createMock(LoggerInterface::class)
		);

		$service->enqueueSource(1, true);

		$this->assertSame([81], $removed);
		$this->assertTrue($service->isQueued(2));
		$this->assertFalse($service->isQueued(3));
	}

	public static function jobIds(): array {
		return [
			'Nextcloud 30 to 32' => [81],
			'Nextcloud 33 and later' => ['141125627021864960'],
		];
	}

	#[DataProvider('jobIds')]
	public function testExplicitReindexPassesTheIdOfAScheduledRetryOnUnchanged(int|string $jobId): void {
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->willReturn($this->source());
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->willReturn([$this->job($jobId, ['sourceId' => 1, 'force' => false, 'attempt' => 1])]);
		$jobs->expects($this->once())->method('removeById')->with($this->identicalTo($jobId));
		$service = new RagIngestionService(
			$mapper,
			$this->createMock(EmbeddingMapper::class),
			$this->createMock(EmbeddingClient::class),
			$this->createMock(DoclingClient::class),
			$this->createMock(UrlContentFetcher::class),
			$this->createMock(SettingsService::class),
			$this->createMock(IRootFolder::class),
			$jobs,
			$this->createMock(LoggerInterface::class)
		);

		$service->enqueueSource(1, true);
	}

	public function testBackendFailureDuringLookupKeepsTheIndexAndSchedulesARetry(): void {
		$source = $this->source();
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willThrowException(new \RuntimeException('database is locked'));
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->never())->method('deleteBySource');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')->with(
			ReindexBotSourceJob::class,
			$this->greaterThan(time() + 500),
			['sourceId' => 1, 'force' => true, 'attempt' => 1]
		);

		$this->service($folder, null, $embeddings, null, $jobs)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::ERROR, $source->getStatus());
		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::SOURCE_TEMPORARILY_UNAVAILABLE, $stored['code']);
		$this->assertTrue($stored['params']['retry_scheduled']);
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testBackendFailureStopsRetryingAfterTheLastScheduledAttempt(): void {
		$source = $this->source();
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willThrowException(new \RuntimeException('storage not available'));
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('scheduleAfter');

		$this->service($folder, null, null, null, $jobs)->ingestSource($source, true, 3);

		$this->assertFalse(KnowledgeSourceStatus::decode($source->getErrorMessage())['params']['retry_scheduled']);
	}

	public function testRateLimitDefersIndexingWithoutTouchingThePreviousIndex(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('a.txt', 'text/plain', 'Some text.')]);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->never())->method('deleteBySource');
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willThrowException(new EmbeddingRateLimitedException(120));
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')->with(
			ReindexBotSourceJob::class,
			$this->greaterThanOrEqual(time() + 120),
			['sourceId' => 1, 'force' => true, 'attempt' => 1]
		);

		$this->service($folder, null, $embeddings, $embeddingClient, $jobs)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::PENDING, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::STAGE_WAITING, $source->getProgressStage());
		$this->assertSame(KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testRejectedEmbeddingRequestNamesTheHttpStatus(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('a.txt', 'text/plain', 'Some text.')]);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willThrowException($this->httpFailure(400));

		$this->service($folder, null, null, $embeddingClient)->ingestSource($source, true);

		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::EMBEDDING_FAILED, $stored['code']);
		$this->assertSame(['http_status' => 400], $stored['params']);
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testUnforcedScheduledRetryIsSkippedWhenTheSourceWasIndexedMeanwhile(): void {
		$source = $this->source();
		$source->setStatus('ready');
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->with(1)->willReturn($source);
		$mapper->expects($this->never())->method('update');
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');
		$service = new RagIngestionService(
			$mapper,
			$this->createMock(EmbeddingMapper::class),
			$this->createMock(EmbeddingClient::class),
			$this->createMock(DoclingClient::class),
			$this->createMock(UrlContentFetcher::class),
			$this->createMock(SettingsService::class),
			$root,
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class)
		);

		$service->ingestSourceById(1, false, 2);

		$this->assertSame('ready', $source->getStatus());
	}

	public function testRetryContinuesWithTheStagedChunksInsteadOfStartingOver(): void {
		$source = $this->source();
		// 750-character chunks with 50 overlap: three chunks.
		$folder = $this->folder([$this->file('long.txt', 'text/plain', str_repeat('a', 1500))]);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([0 => true, 1 => true]);
		$staged = [];
		$embeddings->method('insertStaged')->willReturnCallback(static function (Embedding $item, int $index) use (&$staged): void {
			$staged[] = $index;
		});
		$embeddings->expects($this->once())->method('publishStaged')->with(1, 'model', $this->anything(), 3);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->expects($this->once())->method('embedTexts')
			->with([str_repeat('a', 100)], 'model', $this->anything())
			->willReturn([[0.1, 0.2]]);

		$this->service($folder, null, $embeddings, $embeddingClient)->ingestSource($source, true, 2);

		$this->assertSame([2], $staged);
		$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
	}

	public function testPauseAfterProgressKeepsRetryingWithAFreshBudget(): void {
		$source = $this->source();
		// The first HTTP batch is embedded, the second one is paused.
		$folder = $this->folder([$this->file('big.txt', 'text/plain', str_repeat('b', 50 * 700 + 750))]);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([]);
		$embeddings->expects($this->exactly(EmbeddingClient::BATCH_SIZE))->method('insertStaged');
		$embeddings->expects($this->never())->method('publishStaged');
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$calls = 0;
		$embeddingClient->method('embedTexts')->willReturnCallback(static function (array $texts) use (&$calls): array {
			if (++$calls === 2) {
				throw new EmbeddingRateLimitedException(120);
			}
			return array_fill(0, count($texts), [0.1]);
		});
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->willReturn([]);
		$jobs->expects($this->once())->method('scheduleAfter')->with(
			ReindexBotSourceJob::class,
			$this->greaterThanOrEqual(time() + 120),
			['sourceId' => 1, 'force' => true, 'attempt' => 1]
		);

		// The last attempt of the chain: without progress this would end in an error.
		$this->service($folder, null, $embeddings, $embeddingClient, $jobs)->ingestSource($source, true, 5);

		$this->assertSame(KnowledgeSourceStatus::PENDING, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::STAGE_WAITING, $source->getProgressStage());
	}

	public function testExplicitReindexQueuedMeanwhileReplacesTheRetry(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('a.txt', 'text/plain', 'Some text.')]);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willThrowException(new EmbeddingRateLimitedException(120));
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->willReturn([$this->job(5, ['sourceId' => 1, 'force' => true])]);
		$jobs->expects($this->never())->method('scheduleAfter');

		$this->service($folder, null, null, $embeddingClient, $jobs)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::STAGE_WAITING, $source->getProgressStage());
	}

	public function testSourceRemovedWhileItWasEmbeddedPublishesNothing(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('a.txt', 'text/plain', 'Some text.')]);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([]);
		$embeddings->expects($this->never())->method('publishStaged');
		$embeddings->expects($this->once())->method('deleteBySource')->with(1);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willReturn([[0.1]]);
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('removed'));

		$this->service($folder, null, $embeddings, $embeddingClient, null, $mapper)->ingestSource($source, true);
	}

	public function testSourceUnreachableBeforePublishingIsExcludedInsteadOfPublished(): void {
		$source = $this->source();
		$text = $this->file('a.txt', 'text/plain', 'Some text.');
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getDirectoryListing')->willReturn([$text]);
		// Found for collecting, gone when the finished index would be published.
		$folder->method('getById')->with(10)->willReturnOnConsecutiveCalls([$folder], []);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([]);
		$embeddings->expects($this->never())->method('publishStaged');
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willReturn([[0.1]]);

		$this->service($folder, null, $embeddings, $embeddingClient)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testDeniedFolderListingExcludesTheSource(): void {
		$source = $this->source();
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getById')->with(10)->willReturn([$folder]);
		$folder->method('getDirectoryListing')->willThrowException(new NotPermittedException('denied'));

		$this->service($folder)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::SOURCE_ACCESS_DENIED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public function testExcludedSourceStaysExcludedUntilItsNewIndexIsPublished(): void {
		$source = $this->source();
		$source->setStatus(KnowledgeSourceStatus::UNAVAILABLE);
		$folder = $this->folder([$this->file('a.txt', 'text/plain', 'Some text.')]);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willThrowException(new EmbeddingRateLimitedException(120));
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->willReturn([]);
		$jobs->expects($this->once())->method('scheduleAfter');

		$this->service($folder, null, null, $embeddingClient, $jobs)->ingestSource($source, true);

		// Found again, but its old index must not answer before the new one is complete.
		$this->assertSame(KnowledgeSourceStatus::UNAVAILABLE, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::STAGE_WAITING, $source->getProgressStage());
	}

	public function testUnchangedContentIsReembeddedWhenTheModelHasNoVectors(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('a.txt', 'text/plain', 'Some text.')]);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([]);
		$embeddings->method('countBySourceAndModel')->with(1, 'model-b')->willReturn(0);
		$embeddings->expects($this->once())->method('publishStaged');
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model-b');
		$embeddingClient->expects($this->once())->method('embedTexts')->willReturn([[0.1]]);
		$service = $this->service($folder, null, $embeddings, $embeddingClient);
		$fingerprint = (new \ReflectionMethod($service, 'indexFingerprint'))->invoke($service, sha1('Some text.'), 750, 50);
		$source->setChecksum($fingerprint);

		$service->ingestSource($source, false);

		$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
	}

	public function testPhpErrorDoesNotLeaveTheSourcePending(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('a.txt', 'text/plain', 'Some text.')]);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$embeddingClient->method('embedTexts')->willThrowException(new \TypeError('unexpected vector'));

		$this->service($folder, null, null, $embeddingClient)->ingestSource($source, true);

		$this->assertSame(KnowledgeSourceStatus::ERROR, $source->getStatus());
		$this->assertSame(KnowledgeSourceStatus::INDEXING_FAILED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public function testOverlapOfTheChunkSizeStillAdvancesThroughTheText(): void {
		$source = $this->source();
		$folder = $this->folder([$this->file('a.txt', 'text/plain', str_repeat('c', 250))]);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([]);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->method('getActiveModel')->willReturn('model');
		$sent = [];
		$embeddingClient->method('embedTexts')->willReturnCallback(static function (array $texts) use (&$sent): array {
			array_push($sent, ...$texts);
			return array_fill(0, count($texts), [0.1]);
		});

		// A stored overlap equal to the chunk size used to loop until the worker ran out of memory.
		$this->service($folder, null, $embeddings, $embeddingClient, null, null, ['rag_enabled' => true, 'rag_chunk_size' => 100, 'rag_chunk_overlap' => 100])
			->ingestSource($source, true);

		$this->assertCount(250, $sent);
		$this->assertSame(str_repeat('c', 100), $sent[0]);
		$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
	}

	public function testDeletingLastContentFileAtomicallyClearsOldVectorsIncludingMetadataOnlyRemainder(): void {
		foreach ([[], [$this->file('.DS_Store', 'application/octet-stream')]] as $files) {
			$source = $this->source();
			$embeddings = $this->createMock(EmbeddingMapper::class);
			$embeddings->expects($this->once())->method('publishStaged')->with(1, 'model', $this->anything(), 0);
			$client = $this->createMock(EmbeddingClient::class);
			$client->method('getActiveModel')->willReturn('model');
			$client->expects($this->never())->method('embedTexts');
			$this->service($this->folder($files), null, $embeddings, $client)->ingestSource($source);
			$this->assertNull($source->getChecksum());
			$this->assertNotNull($source->getLastIndexedAt());
			$this->assertSame(KnowledgeSourceStatus::ERROR, $source->getStatus());
		}
	}

	public function testBlankingLastDocumentClearsVectorsButUnsupportedAndFailedConversionDoNot(): void {
		$source = $this->source();
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->once())->method('publishStaged')->with(1, $this->anything(), $this->anything(), 0);
		$client = $this->createMock(EmbeddingClient::class);
		$client->expects($this->never())->method('embedTexts');
		$this->service($this->folder([$this->file('a.txt', 'text/plain', " \n")]), null, $embeddings, $client)->ingestSource($source);
		$this->assertNull($source->getChecksum());

		$source = $this->source();
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->never())->method('publishStaged');
		$this->service($this->folder([$this->file('a.pages', 'application/octet-stream')]), null, $embeddings, $client)->ingestSource($source);
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testRenameUpdatesStoredCitationsWithoutReembeddingOnUnchangedFollowup(): void {
		$source = $this->source();
		$files = [$this->file('old.txt', 'text/plain', 'Same content.')];
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getById')->willReturn([$folder]);
		$folder->method('getDirectoryListing')->willReturnCallback(static function () use (&$files): array { return $files; });
		$stored = [];
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('countBySourceAndModel')->willReturn(1);
		$embeddings->method('insertStaged')->willReturnCallback(static function (Embedding $row) use (&$stored): void {
			$stored[] = json_decode($row->getMetadata(), true)['path'];
		});
		$embeddings->expects($this->exactly(2))->method('publishStaged');
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('model');
		$client->expects($this->exactly(2))->method('embedTexts')->willReturn([[0.2]]);
		$service = $this->service($folder, null, $embeddings, $client);
		$service->ingestSource($source);
		$oldChecksum = $source->getChecksum();
		$files = [$this->file('new.txt', 'text/plain', 'Same content.')];
		$service->ingestSource($source);
		$this->assertNotSame($oldChecksum, $source->getChecksum());
		$service->ingestSource($source);
		$this->assertSame(['docs/old.txt', 'docs/new.txt'], $stored);
	}

	public static function fileSourceTypes(): array {
		return [
			'file' => ['file', false], 'folder' => ['folder', false],
			'file with provider retry' => ['file', true], 'folder with provider retry' => ['folder', true],
		];
	}

	#[DataProvider('fileSourceTypes')]
	public function testContendedForcedReindexStillEmbedsUnchangedContentAfterAnUnforcedRun(string $nodeType, bool $rateLimited): void {
		$source = $this->source();
		$source->setNodeType($nodeType);
		$source->setStatus(KnowledgeSourceStatus::READY);
		$checksum = sha1(sha1('') . sha1('docs/a.txt') . sha1('Same text.') . "\nmodel\n750\n50");
		$source->setChecksum($checksum);
		$file = $this->file('a.txt', 'text/plain', 'Same text.');
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getDirectoryListing')->willReturn([$file]);
		$folder->method('getById')->willReturn([$nodeType === 'file' ? $file : $folder]);
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->willReturn($source);
		$mapper->method('update')->willReturnArgument(0);
		$contended = true;
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(static function (string $key) use (&$contended): void {
			if ($contended && $key === 'educai:rag-source:1') { throw new \OCP\Lock\LockedException(); }
		});
		$queued = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->willReturn([$this->job(5, ['sourceId' => 1, 'force' => false])]);
		$jobs->method('has')->willReturnCallback(static fn (string $class, array $args): bool => $class === ReindexBotSourceJob::class && !($args['force'] ?? false));
		$jobs->method('scheduleAfter')->willReturnCallback(static function (string $class, int $at, array $args) use (&$queued): void {
			$queued[] = [$class, $at, $args];
		});
		$jobs->expects($this->never())->method('removeById');
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('countBySourceAndModel')->willReturn(1);
		$embeddings->expects($this->once())->method('insertStaged');
		$embeddings->expects($this->once())->method('publishStaged')->with(1, 'model', $checksum, 1);
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('model');
		$requests = [];
		$client->method('embedTexts')->willReturnCallback(static function (array $texts) use (&$requests, $rateLimited): array {
			$requests[] = $texts;
			if ($rateLimited && count($requests) === 1) {
				throw new EmbeddingRateLimitedException(60);
			}
			return [[0.1]];
		});
		$service = $this->service($folder, null, $embeddings, $client, $jobs, $mapper, ['rag_enabled' => true], $locking);
		$job = new ReindexBotSourceJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class));

		$job->run(['sourceId' => 1, 'force' => true]);

		$this->assertCount(1, $queued);
		$this->assertSame(ReindexBotSourceJob::class, $queued[0][0]);
		$this->assertGreaterThanOrEqual(time() + 29, $queued[0][1]);
		$this->assertSame(['sourceId' => 1, 'force' => true, 'attempt' => 0], $queued[0][2]);
		$this->assertSame([], $requests);
		$contended = false;
		$job->run(['sourceId' => 1, 'force' => false]);
		$this->assertSame([], $requests, 'The ordinary pass sees an unchanged, already indexed file.');
		$job->run($queued[0][2]);
		if ($rateLimited) {
			$this->assertCount(2, $queued, 'An ordinary queued job must not absorb the forced provider retry.');
			$this->assertSame(ReindexBotSourceJob::class, $queued[1][0]);
			$this->assertSame(['sourceId' => 1, 'force' => true, 'attempt' => 1], $queued[1][2]);
			$this->assertGreaterThanOrEqual(time() + 64, $queued[1][1]);
			// Advance only the stored provider deadline; no real provider call or wait.
			$source->setErrorMessage(KnowledgeSourceStatus::encode(
				KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED, ['retry_at' => time() - 1]
			));
			$job->run(['sourceId' => 1, 'force' => false]);
			$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
			$this->assertCount(1, $requests, 'The ordinary pass does not create replacement embeddings.');
			$job->run($queued[1][2]);
		}
		$this->assertSame(array_fill(0, $rateLimited ? 2 : 1, ['Same text.']), $requests, 'The deferred explicit rebuild still creates fresh embeddings.');
		$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
		$this->assertSame($checksum, $source->getChecksum());
	}

	public static function contendedRequests(): array {
		return [
			'forced file provider retry' => ['file', true, 3],
			'ordinary folder provider retry' => ['folder', false, 2],
			'forced URL provider retry' => ['url', true, 4],
		];
	}

	#[DataProvider('contendedRequests')]
	public function testLockDeferralPreservesAttemptAndProviderPause(string $nodeType, bool $force, int $attempt): void {
		$source = $this->source();
		$source->setNodeType($nodeType);
		$source->setProgressStage(KnowledgeSourceStatus::STAGE_WAITING);
		$retryAt = time() + 300;
		$message = KnowledgeSourceStatus::encode(KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED, ['retry_at' => $retryAt]);
		$source->setErrorMessage($message);
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->willReturn($source);
		$mapper->expects($this->never())->method('update');
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new \OCP\Lock\LockedException());
		$locking->expects($this->never())->method('releaseLock');
		$jobs = $this->createMock(IJobList::class);
		$args = ['sourceId' => 1, 'force' => $force, 'attempt' => $attempt];
		$jobs->method('has')->with(ReindexBotSourceJob::class, $args)->willReturnOnConsecutiveCalls(false, true);
		$jobs->expects($this->once())->method('scheduleAfter')->with(ReindexBotSourceJob::class, $retryAt, $args);
		$jobs->expects($this->never())->method('removeById');
		$service = $this->service($this->folder([]), null, null, null, $jobs, $mapper, ['rag_enabled' => true], $locking);

		$service->ingestSourceById(1, $force, $attempt);
		$service->ingestSourceById(1, $force, $attempt);

		$this->assertSame($message, $source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::STAGE_WAITING, $source->getProgressStage());
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}

	public function testDeferredReindexObservesAProviderPauseSetAfterTheLockConflict(): void {
		$source = $this->source();
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->willReturn($source);
		$mapper->expects($this->never())->method('update');
		$contended = true;
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(static function () use (&$contended): void {
			if ($contended) { throw new \OCP\Lock\LockedException(); }
		});
		$locking->expects($this->once())->method('releaseLock')->with('educai:rag-source:1', 2);
		$scheduled = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(static function (string $class, int $at, array $args) use (&$scheduled): void {
			$scheduled[] = [$class, $at, $args];
		});
		$client = $this->createMock(EmbeddingClient::class);
		$client->expects($this->never())->method('embedTexts');
		$service = $this->service($this->folder([]), null, null, $client, $jobs, $mapper, ['rag_enabled' => true], $locking);
		$service->ingestSourceById(1, true);
		$this->assertCount(1, $scheduled);
		$retryAt = time() + 300;
		$source->setProgressStage(KnowledgeSourceStatus::STAGE_WAITING);
		$message = KnowledgeSourceStatus::encode(KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED, ['retry_at' => $retryAt]);
		$source->setErrorMessage($message);
		$contended = false;

		(new ReindexBotSourceJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class)))->run($scheduled[0][2]);

		$this->assertCount(2, $scheduled);
		$this->assertSame([ReindexBotSourceJob::class, $retryAt, ['sourceId' => 1, 'force' => true, 'attempt' => 0]], $scheduled[1]);
		$this->assertSame($message, $source->getErrorMessage());
	}

	public function testSourceLockContentionKeepsFileSyncFollowupWithoutTouchingIndexOrRetryChain(): void {
		$source = $this->source();
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->willReturn($source);
		$mapper->expects($this->never())->method('update');
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->expects($this->exactly(2))->method('acquireLock')->willReturnCallback(static function (string $key): void {
			if ($key === 'educai:rag-source:1') { throw new \OCP\Lock\LockedException(); }
			TestCase::assertSame('educai:rag-sync-queue:1', $key);
		});
		$locking->expects($this->once())->method('releaseLock')->with('educai:rag-sync-queue:1', 2);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturn(false);
		$jobs->expects($this->never())->method('removeById');
		$jobs->expects($this->once())->method('scheduleAfter')->with(\OCA\EducAI\Jobs\SyncRagSourceJob::class, $this->anything(), ['sourceId' => 1]);
		$this->service($this->folder([]), null, null, null, $jobs, $mapper, ['rag_enabled' => true], $locking)->ingestSourceById(1, false, 0, true);
	}

	public function testSourceLockIsReleasedWhenRemovedSourceCannotBeRead(): void {
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('findById')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('removed'));
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')->with('educai:rag-source:1', 2);
		$locking->expects($this->once())->method('releaseLock')->with('educai:rag-source:1', 2);
		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service($this->folder([]), null, null, null, null, $mapper, ['rag_enabled' => true], $locking)->ingestSourceById(1);
	}

	public function testWriteDuringEmbeddingRetainsChangedContentForFollowup(): void {
		$source = $this->source();
		$current = 'Old content.';
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/docs/a.txt');
		$file->method('getName')->willReturn('a.txt');
		$file->method('getMimeType')->willReturn('text/plain');
		$file->method('getContent')->willReturnCallback(static function () use (&$current): string { return $current; });
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturn(false);
		$jobs->expects($this->once())->method('scheduleAfter')->with(\OCA\EducAI\Jobs\SyncRagSourceJob::class, $this->anything(), ['sourceId' => 1]);
		$sync = new \OCA\EducAI\Service\RagFileEventSyncService($this->createMock(BotSourceMapper::class), $this->createMock(IRootFolder::class), $jobs, $this->createMock(LoggerInterface::class));
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('model');
		$requests = [];
		$client->expects($this->exactly(2))->method('embedTexts')->willReturnCallback(static function (array $texts) use (&$requests, &$current, $sync): array {
			$requests[] = $texts;
			if ($current === 'Old content.') {
				$current = 'New content.';
				$sync->schedule([1]);
			}
			return [[0.1]];
		});
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('countBySourceAndModel')->willReturn(1);
		$service = $this->service($this->folder([$file]), null, $embeddings, $client, $jobs);
		$service->ingestSource($source);
		$service->ingestSource($source);
		$this->assertSame([['Old content.'], ['New content.']], $requests);
		$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
	}

	private function source(): BotSource {
		$source = new BotSource();
		$source->setId(1);
		$source->setBotId(7);
		$source->setNodeId(10);
		$source->setOwnerUid('alice');
		$source->setChecksum('previous-complete-index');
		return $source;
	}

	/**
	 * @param array<string,mixed> $argument
	 */
	private function job(int|string $id, array $argument): IJob {
		$job = $this->createMock(IJob::class);
		$job->method('getId')->willReturn($id);
		$job->method('getArgument')->willReturn($argument);
		return $job;
	}

	private function file(string $name, string $mime, ?string $content = null): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getPath')->willReturn('/alice/files/docs/' . $name);
		if ($content !== null) {
			$file->method('getContent')->willReturn($content);
		}
		return $file;
	}

	/**
	 * @param list<File|Folder> $children
	 */
	private function folder(array $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getById')->with(10)->willReturn([$folder]);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	private function service(
		Folder $userFolder,
		?DoclingClient $docling = null,
		?EmbeddingMapper $embeddings = null,
		?EmbeddingClient $embeddingClient = null,
		?IJobList $jobs = null,
		?BotSourceMapper $mapper = null,
		array $ragConfig = ['rag_enabled' => true],
		?\OCP\Lock\ILockingProvider $locking = null,
	): RagIngestionService {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		if ($docling === null) {
			$docling = $this->createMock(DoclingClient::class);
			$docling->method('isEnabled')->willReturn(true);
			$docling->method('isSupported')->willReturn(false);
		}
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRagConfig')->willReturn($ragConfig);
		if ($mapper === null) {
			$mapper = $this->createMock(BotSourceMapper::class);
			$mapper->method('update')->willReturnCallback(static fn (BotSource $item): BotSource => $item);
		}

		return new RagIngestionService(
			$mapper,
			$embeddings ?? $this->createMock(EmbeddingMapper::class),
			$embeddingClient ?? $this->createMock(EmbeddingClient::class),
			$docling,
			$this->createMock(UrlContentFetcher::class),
			$settings,
			$root,
			$jobs ?? $this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class),
			null,
			$locking
		);
	}

	private function httpFailure(int $status): \Exception {
		$response = new class($status) {
			public function __construct(private int $status) {
			}

			public function getStatusCode(): int {
				return $this->status;
			}
		};
		return new class($response) extends \Exception {
			public function __construct(private object $response) {
				parent::__construct('Provider request failed');
			}

			public function hasResponse(): bool {
				return true;
			}

			public function getResponse(): object {
				return $this->response;
			}
		};
	}
}
