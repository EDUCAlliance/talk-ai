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
		$embeddings->expects($this->once())->method('deleteBySource')->with(1);
		$embeddings->expects($this->once())->method('insert')->willReturnCallback(static fn (Embedding $item): Embedding => $item);
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
		$this->assertSame('previous-complete-index', $source->getChecksum());
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

	public function testScheduledRetryIsSkippedWhenTheSourceWasIndexedMeanwhile(): void {
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

		$service->ingestSourceById(1, true, 2);

		$this->assertSame('ready', $source->getStatus());
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
	private function job(int $id, array $argument): IJob {
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
	): RagIngestionService {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		if ($docling === null) {
			$docling = $this->createMock(DoclingClient::class);
			$docling->method('isEnabled')->willReturn(true);
			$docling->method('isSupported')->willReturn(false);
		}
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('update')->willReturnCallback(static fn (BotSource $item): BotSource => $item);

		return new RagIngestionService(
			$mapper,
			$embeddings ?? $this->createMock(EmbeddingMapper::class),
			$embeddingClient ?? $this->createMock(EmbeddingClient::class),
			$docling,
			$this->createMock(UrlContentFetcher::class),
			$settings,
			$root,
			$jobs ?? $this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class)
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
