<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\Embedding;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Exception\DoclingEmptyResultException;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

class RagEmptyFolderRefreshTest extends TestCase {
	public static function companions(): array {
		return ['text alone' => [false], 'always-unsupported companion' => [true]];
	}

	#[DataProvider('companions')]
	public function testBlankingTheOnlyReadableTextClearsThePublishedIndex(bool $includeUnsupported): void {
		[$service, $source, $state] = $this->indexedFolder($includeUnsupported);
		$state->text = " \n";

		$service->ingestSourceById(1, false, 0, true);

		$this->assertSame([1, 0], $state->publishedCounts);
		$this->assertSame([], $state->publishedTexts);
		$this->assertNull($source->getChecksum());
		$this->assertSame(KnowledgeSourceStatus::ERROR, $source->getStatus());
		$error = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::NO_TEXT, $error['code']);
		$this->assertSame(['empty' => 1, 'unsupported' => (int)$includeUnsupported], $error['params']);
		$this->assertSame(1, $state->embeddingCalls);
	}

	public function testConfirmedEmptyConversionClearsTextDespiteUnsupportedCompanion(): void {
		[$service, $source, $state] = $this->indexedFolder(true, true);
		$state->text = '';
		$state->conversionError = new DoclingEmptyResultException('No text remains');

		$service->ingestSourceById(1, false, 0, true);

		$this->assertSame([2, 0], $state->publishedCounts);
		$this->assertSame([], $state->publishedTexts);
		$this->assertNull($source->getChecksum());
		$error = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::NO_TEXT, $error['code']);
		$this->assertSame(['empty' => 2, 'unsupported' => 1], $error['params']);
		$this->assertSame(1, $state->embeddingCalls);
	}

	public function testUnsupportedOnlyInputKeepsThePreviousIndex(): void {
		[$service, $source, $state] = $this->indexedFolder(true);
		$checksum = $source->getChecksum();
		$state->children = [$state->unsupported];

		$service->ingestSourceById(1, false, 0, true);

		$this->assertSame([1], $state->publishedCounts);
		$this->assertSame(['Old readable text.'], $state->publishedTexts);
		$this->assertSame($checksum, $source->getChecksum());
		$this->assertSame(KnowledgeSourceStatus::NO_SUPPORTED_FILES, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public function testDisabledConverterDoesNotConfirmTheWholeFolderIsEmpty(): void {
		[$service, $source, $state] = $this->indexedFolder(true, true);
		$checksum = $source->getChecksum();
		$state->text = '';
		$state->converterEnabled = false;

		$service->ingestSourceById(1, false, 0, true);

		$this->assertSame([2], $state->publishedCounts);
		$this->assertSame(['Old readable text.', 'Still-readable converted text.'], $state->publishedTexts);
		$this->assertSame($checksum, $source->getChecksum());
		$this->assertSame(KnowledgeSourceStatus::NO_TEXT, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public function testReadFailureBesideUnsupportedFileKeepsThePreviousIndex(): void {
		[$service, $source, $state] = $this->indexedFolder(true);
		$checksum = $source->getChecksum();
		$state->readError = new Exception('Storage read failed');

		$service->ingestSourceById(1, false, 0, true);

		$this->assertSame([1], $state->publishedCounts);
		$this->assertSame(['Old readable text.'], $state->publishedTexts);
		$this->assertSame($checksum, $source->getChecksum());
		$this->assertSame(KnowledgeSourceStatus::EXTRACTION_FAILED, KnowledgeSourceStatus::decode($source->getErrorMessage())['code']);
	}

	public static function conversionFailures(): array {
		return [
			'conversion failed' => ['Docling conversion failed'],
			'conversion size limit' => ['Docling rejected the document size (HTTP 413). Check the server upload limit.'],
		];
	}

	#[DataProvider('conversionFailures')]
	public function testConversionFailureBesideBlankTextKeepsThePreviousIndex(string $message): void {
		[$service, $source, $state] = $this->indexedFolder(true, true);
		$checksum = $source->getChecksum();
		$state->text = '';
		$state->conversionError = new Exception($message);

		$service->ingestSourceById(1, false, 0, true);

		$this->assertSame([2], $state->publishedCounts);
		$this->assertSame(['Old readable text.', 'Still-readable converted text.'], $state->publishedTexts);
		$this->assertSame($checksum, $source->getChecksum());
		$error = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$this->assertSame(KnowledgeSourceStatus::EXTRACTION_FAILED, $error['code']);
		$this->assertSame(['failed' => 1, 'total' => 3], $error['params']);
		$this->assertStringContainsString($message, $error['detail']);
	}

	/**
	 * Run real ingestion once, keeping published chunks in memory for the second pass.
	 *
	 * @return array{RagIngestionService, BotSource, stdClass}
	 */
	private function indexedFolder(bool $includeUnsupported, bool $includePdf = false): array {
		$state = (object)[
			'text' => 'Old readable text.',
			'readError' => null,
			'conversionError' => null,
			'converterEnabled' => true,
			'embeddingCalls' => 0,
			'stagedTexts' => [],
			'publishedTexts' => [],
			'publishedCounts' => [],
		];
		$text = $this->file('a.txt', 'text/plain');
		$text->method('getContent')->willReturnCallback(static function () use ($state): string {
			if ($state->readError !== null) {
				throw $state->readError;
			}
			return $state->text;
		});
		$state->unsupported = $this->file('b.pages', 'application/octet-stream');
		$state->unsupported->expects($this->never())->method('getContent');
		$pdf = $this->file('c.pdf', 'application/pdf');
		$state->children = [$text];
		if ($includeUnsupported) {
			$state->children[] = $state->unsupported;
		}
		if ($includePdf) {
			$state->children[] = $pdf;
		}
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files/docs');
		$folder->method('getDirectoryListing')->willReturnCallback(static fn (): array => $state->children);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getPath')->willReturn('/alice/files');
		$userFolder->method('getById')->with(10)->willReturn([$folder]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$source = new BotSource();
		$source->setId(1);
		$source->setBotId(7);
		$source->setNodeId(10);
		$source->setOwnerUid('alice');
		$source->setNodeType('folder');
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findById')->with(1)->willReturn($source);
		$sources->method('update')->willReturnArgument(0);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([]);
		$embeddings->method('insertStaged')->willReturnCallback(static function (Embedding $embedding, int $index, string $generation) use ($state): void {
			$state->stagedTexts[$generation][$index] = $embedding->getChunkText();
		});
		$embeddings->method('publishStaged')->willReturnCallback(static function (int $id, string $model, string $generation, int $count) use ($state): void {
			$state->publishedCounts[] = $count;
			$state->publishedTexts = array_slice($state->stagedTexts[$generation] ?? [], 0, $count);
		});
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('model');
		$client->method('embedTexts')->willReturnCallback(static function (array $texts) use ($state): array {
			$state->embeddingCalls++;
			return array_fill(0, count($texts), [0.1]);
		});
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isSupported')->willReturnCallback(static fn (File $file): bool => $file === $pdf);
		$docling->method('isEnabled')->willReturnCallback(static fn (): bool => $state->converterEnabled);
		$docling->method('convertToMarkdown')->willReturnCallback(static function () use ($state): string {
			if ($state->conversionError !== null) {
				throw $state->conversionError;
			}
			return 'Still-readable converted text.';
		});
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$service = new RagIngestionService($sources, $embeddings, $client, $docling,
			$this->createMock(UrlContentFetcher::class), $settings, $root,
			$this->createMock(IJobList::class), $this->createMock(LoggerInterface::class));

		$service->ingestSourceById(1, false, 0, true);
		$this->assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
		$this->assertSame([$includePdf ? 2 : 1], $state->publishedCounts);
		$this->assertSame($includePdf ? ['Old readable text.', 'Still-readable converted text.'] : ['Old readable text.'], $state->publishedTexts);
		$this->assertNotEmpty($source->getChecksum());
		return [$service, $source, $state];
	}

	private function file(string $name, string $mime): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/alice/files/docs/' . $name);
		$file->method('getMimeType')->willReturn($mime);
		return $file;
	}
}
