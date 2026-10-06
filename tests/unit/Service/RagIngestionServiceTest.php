<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RagIngestionServiceTest extends TestCase {
	public function testFailedDocumentDoesNotReplaceAnExistingIndexWithAnIncompleteFolder(): void {
		$source = new BotSource();
		$source->setId(1);
		$source->setNodeId(10);
		$source->setOwnerUid('alice');
		$source->setChecksum('previous-complete-index');
		$good = $this->createMock(File::class);
		$good->method('getMimeType')->willReturn('text/plain');
		$good->method('getPath')->willReturn('/alice/files/good.txt');
		$good->method('getContent')->willReturn('A readable document.');
		$bad = $this->createMock(File::class);
		$bad->method('getMimeType')->willReturn('application/pdf');
		$bad->method('getPath')->willReturn('/alice/files/bad.pdf');
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getById')->with(10)->willReturn([$folder]);
		$folder->method('getDirectoryListing')->willReturn([$good, $bad]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($folder);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(true);
		$docling->method('isSupported')->willReturnCallback(static fn (File $file): bool => $file === $bad);
		$docling->expects($this->once())->method('convertToMarkdown')->with($bad)
			->willThrowException(new Exception('Docling conversion was incomplete'));
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$mapper = $this->createMock(BotSourceMapper::class);
		$mapper->method('update')->willReturnCallback(static fn (BotSource $item): BotSource => $item);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->expects($this->never())->method('deleteBySource');
		$embeddings->expects($this->never())->method('insert');
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$embeddingClient->expects($this->never())->method('embedTexts');
		$service = new RagIngestionService($mapper, $embeddings, $embeddingClient, $docling,
			$this->createMock(UrlContentFetcher::class), $settings, $root,
			$this->createMock(IJobList::class), $this->createMock(LoggerInterface::class));

		$service->ingestSource($source, true);

		$this->assertSame('error', $source->getStatus());
		$this->assertStringContainsString('bad.pdf: Docling conversion was incomplete', $source->getErrorMessage());
		$this->assertSame('previous-complete-index', $source->getChecksum());
	}
}
