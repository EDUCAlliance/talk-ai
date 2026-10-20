<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\RoomDocumentEmbedding;
use OCA\EducAI\Db\RoomDocumentEmbeddingMapper;
use OCA\EducAI\Db\RoomDocumentSource;
use OCA\EducAI\Db\RoomDocumentSourceMapper;
use OCA\EducAI\Exception\EmbeddingRateLimitedException;
use OCA\EducAI\Service\AttachmentResolver;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RoomDocumentIngestionServiceTest extends TestCase {
	#[DataProvider('cachedVectors')]
	public function testUnchangedDocumentRequiresCompleteCurrentVectors(?string $model, string $text, string $vector, bool $rebuild): void {
		[$service, $source, $vectors, $client] = $this->fixture();
		$cached = [];
		if ($model !== null) {
			$embedding = new RoomDocumentEmbedding();
			$embedding->setEmbeddingModel($model);
			$embedding->setChunkId('11:0');
			$embedding->setChunkText($text);
			$embedding->setEmbedding($vector);
			$cached[] = $embedding;
		}
		$vectors->method('findBySource')->willReturn($cached);
		$client->expects($rebuild ? $this->once() : $this->never())->method('embedTexts')
			->with(['cached text'], 'new-model')->willReturn([[0, 1]]);
		$vectors->expects($rebuild ? $this->once() : $this->never())->method('deleteBySource')->with(11);
		$vectors->expects($rebuild ? $this->once() : $this->never())->method('insert')
			->with($this->callback(static fn (RoomDocumentEmbedding $embedding): bool => $embedding->getEmbeddingModel() === 'new-model'));

		$this->assertSame('ready', $this->ingest($service)->getStatus());
		$this->assertSame(sha1('cached text'), $source->getChecksum());
	}

	public static function cachedVectors(): array {
		return [
			'missing' => [null, '', '', true],
			'old model' => ['old-model', 'cached text', '[1,0]', true],
			'stale text' => ['new-model', 'old text', '[1,0]', true],
			'empty vector' => ['new-model', 'cached text', '[]', true],
			'valid' => ['new-model', 'cached text', '[0,1]', false],
		];
	}

	public function testProviderPausePersistsDeferredStateAndClearsStaleVectors(): void {
		[$service, $source, $vectors, $client] = $this->fixture();
		$client->method('embedTexts')->willThrowException(new EmbeddingRateLimitedException(120));
		$vectors->expects($this->once())->method('deleteBySource')->with(11);
		try {
			$this->ingest($service);
			$this->fail('Expected embedding deferral');
		} catch (EmbeddingRateLimitedException $e) {
			$this->assertSame(120, $e->getRetryAfterSeconds());
			$this->assertSame('deferred', $source->getStatus());
			$this->assertSame('Embedding provider rate limited', $source->getErrorMessage());
			$this->assertGreaterThan(0, $source->getLastIndexedAt());
			$this->assertGreaterThan(0, $source->getUpdatedAt());
		}
	}

	public function testResolvedMetadataPathIsSkippedBeforeConversion(): void {
		[$service, $source, $vectors, $client, $docling] = $this->fixture(path: '/alice/files/__MACOSX/document.pdf');
		$docling->expects($this->never())->method('convertToMarkdown');
		$client->expects($this->never())->method('embedTexts');
		$vectors->expects($this->once())->method('deleteBySource');
		$this->assertSame('skipped', $this->ingest($service)->getStatus());
		$this->assertGreaterThan(0, $source->getLastIndexedAt());
	}

	public function testEmptyTextHasTerminalEmptyStateAndNoVectors(): void {
		[$service, $source, $vectors, $client] = $this->fixture(content: '   ');
		$client->expects($this->never())->method('embedTexts');
		$vectors->expects($this->once())->method('deleteBySource')->with(11);
		$this->assertSame('empty', $this->ingest($service)->getStatus());
		$this->assertNull($source->getChecksum());
		$this->assertGreaterThan(0, $source->getLastIndexedAt());
	}

	public function testConversionFailureHasTerminalErrorState(): void {
		[$service, $source, $vectors, $client, $docling] = $this->fixture(mime: 'application/pdf');
		$failure = new \RuntimeException('Cannot convert /srv/private/document.pdf');
		$docling->method('convertToMarkdown')->willThrowException($failure);
		$client->expects($this->never())->method('embedTexts');
		$vectors->expects($this->once())->method('deleteBySource')->with(11);
		try {
			$this->ingest($service);
			$this->fail('Expected conversion failure');
		} catch (\RuntimeException $e) {
			$this->assertSame($failure, $e);
			$this->assertSame('error', $source->getStatus());
			$this->assertSame('Document indexing failed', $source->getErrorMessage());
			$this->assertGreaterThan(0, $source->getLastIndexedAt());
		}
	}

	public function testMissingEmbeddingResultsCannotBeMarkedReady(): void {
		[$service, $source, $vectors, $client] = $this->fixture();
		$client->method('embedTexts')->willReturn([]);
		$vectors->expects($this->once())->method('deleteBySource')->with(11);
		try {
			$this->ingest($service);
			$this->fail('Expected invalid embedding result to fail');
		} catch (\Exception $e) {
			$this->assertSame('error', $source->getStatus());
			$this->assertGreaterThan(0, $source->getLastIndexedAt());
		}
	}

	#[DataProvider('overlaps')]
	public function testChunkOverlapIsClamped(int $size, int $overlap, array $expected): void {
		$service = (new \ReflectionClass(RoomDocumentIngestionService::class))->newInstanceWithoutConstructor();
		$chunks = (new \ReflectionMethod($service, 'chunkText'))->invoke($service, 'abcd', $size, $overlap);
		$this->assertSame($expected, $chunks);
	}

	public static function overlaps(): array {
		return [
			'negative' => [2, -10, ['ab', 'cd']],
			'zero' => [2, 0, ['ab', 'cd']],
			'equal' => [2, 2, ['ab', 'bc', 'cd', 'd']],
			'oversized' => [2, 100, ['ab', 'bc', 'cd', 'd']],
			'invalid size' => [0, 50, ['a', 'b', 'c', 'd']],
		];
	}

	private function fixture(string $content = 'cached text', string $path = '/alice/files/document.pdf', string $mime = 'text/plain'): array {
		$source = new RoomDocumentSource();
		$source->setId(11);
		$source->setChecksum(sha1('cached text'));
		$sources = $this->createMock(RoomDocumentSourceMapper::class);
		$sources->method('findOneByBotRoomAndNode')->willReturn($source);
		$sources->expects($this->exactly(2))->method('update')->willReturnArgument(0);
		$vectors = $this->createMock(RoomDocumentEmbeddingMapper::class);
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('new-model');
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('document.pdf');
		$file->method('getPath')->willReturn($path);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getContent')->willReturn($content);
		$resolver = $this->createMock(AttachmentResolver::class);
		$resolver->method('resolveSourceFile')->willReturn($file);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn($mime === 'application/pdf');
		$docling->method('isSupported')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRagConfig')->willReturn(['rag_enabled' => true, 'embedding_model' => 'new-model']);
		$settings->method('getDoclingConfig')->willReturn(['docling_enabled' => true]);
		$service = new RoomDocumentIngestionService($sources, $vectors, $resolver, $client, $docling, $settings, $this->createMock(LoggerInterface::class));
		return [$service, $source, $vectors, $client, $docling];
	}

	private function ingest(RoomDocumentIngestionService $service): RoomDocumentSource {
		return $service->ingestAttachment(7, 'room', 'alice', 5, new IncomingTalkAttachment('document', 'file', 'application/pdf', 'document.pdf', 'file', ['fileId' => 42]));
	}
}
