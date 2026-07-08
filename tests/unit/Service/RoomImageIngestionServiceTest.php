<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\RoomImageEmbedding;
use OCA\EducAI\Db\RoomImageEmbeddingMapper;
use OCA\EducAI\Db\RoomImageSource;
use OCA\EducAI\Db\RoomImageSourceMapper;
use OCA\EducAI\Service\AttachmentResolver;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\ResolvedAttachment;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\VisionClient;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RoomImageIngestionServiceTest extends TestCase {
	public function testIngestsImageAnalysisAndEmbedding(): void {
		$sourceMapper = $this->createMock(RoomImageSourceMapper::class);
		$embeddingMapper = $this->createMock(RoomImageEmbeddingMapper::class);
		$attachmentResolver = $this->createMock(AttachmentResolver::class);
		$visionClient = $this->createMock(VisionClient::class);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$settingsService = $this->createSettingsService();
		$file = $this->createMock(File::class);
		$attachment = $this->createImageAttachment();
		$tempPath = tempnam(sys_get_temp_dir(), 'educai-img-');
		file_put_contents($tempPath, 'image-bytes');

		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('image-bytes');
		$attachmentResolver->method('resolveSourceFile')->with($attachment)->willReturn($file);
		$attachmentResolver->method('resolveToTempFile')->with($attachment)->willReturn(new ResolvedAttachment($tempPath, 'login-error.png', 'image/png'));
		$sourceMapper->method('findOneByBotRoomAndNode')->willReturn(null);
		$sourceMapper->method('findOneByBotRoomAndAttachment')->willReturn(null);
		$sourceMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (RoomImageSource $source): RoomImageSource {
				$source->setId(77);
				return $source;
			});
		$sourceMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (RoomImageSource $source): RoomImageSource => $source);

		$visionClient->method('isEnabled')->willReturn(true);
		$visionClient->expects($this->once())
			->method('analyzeImage')
			->willReturn('Screenshot shows a red login error banner.');
		$embeddingClient->expects($this->once())
			->method('getActiveModel')
			->willReturn('embed-model');
		$embeddingClient->expects($this->once())
			->method('embedTexts')
			->with(['Screenshot shows a red login error banner.'], 'embed-model')
			->willReturn([[1.0, 0.0]]);
		$embeddingMapper->expects($this->once())
			->method('deleteBySource')
			->with(77);
		$embeddingMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static function (RoomImageEmbedding $embedding): bool {
				return $embedding->getSourceId() === 77
					&& $embedding->getChunkText() === 'Screenshot shows a red login error banner.'
					&& str_contains((string)$embedding->getMetadata(), 'login-error.png');
			}));

		$service = new RoomImageIngestionService(
			$sourceMapper,
			$embeddingMapper,
			$attachmentResolver,
			$visionClient,
			$embeddingClient,
			$settingsService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->ingestAttachment(7, 'room-a', 'alice', 1234, $attachment);

		$this->assertSame(77, $result->getId());
		$this->assertSame('ready', $result->getStatus());
		$this->assertSame('login-error.png', $result->getDisplayName());
		$this->assertSame('Screenshot shows a red login error banner.', $result->getAnalysisText());
	}

	public function testReingestingUnchangedImageDoesNotCallVisionAgain(): void {
		$sourceMapper = $this->createMock(RoomImageSourceMapper::class);
		$embeddingMapper = $this->createMock(RoomImageEmbeddingMapper::class);
		$attachmentResolver = $this->createMock(AttachmentResolver::class);
		$visionClient = $this->createMock(VisionClient::class);
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$settingsService = $this->createSettingsService();
		$file = $this->createMock(File::class);
		$attachment = $this->createImageAttachment();
		$existing = new RoomImageSource();
		$existing->setId(77);
		$existing->setChecksum(sha1('image-bytes'));
		$existing->setAnalysisText('Existing analysis');

		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('image-bytes');
		$attachmentResolver->method('resolveSourceFile')->with($attachment)->willReturn($file);
		$sourceMapper->method('findOneByBotRoomAndNode')->willReturn($existing);
		$sourceMapper->expects($this->exactly(2))
			->method('update')
			->willReturnCallback(static fn (RoomImageSource $source): RoomImageSource => $source);
		$visionClient->method('isEnabled')->willReturn(true);
		$visionClient->expects($this->never())->method('analyzeImage');
		$embeddingMapper->expects($this->once())
			->method('findBySource')
			->with(77)
			->willReturn([new RoomImageEmbedding()]);
		$embeddingClient->expects($this->never())->method('embedTexts');

		$service = new RoomImageIngestionService(
			$sourceMapper,
			$embeddingMapper,
			$attachmentResolver,
			$visionClient,
			$embeddingClient,
			$settingsService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->ingestAttachment(7, 'room-a', 'alice', 1234, $attachment);

		$this->assertSame('ready', $result->getStatus());
		$this->assertSame('Existing analysis', $result->getAnalysisText());
	}

	public function testDisabledVisionReturnsClearError(): void {
		$visionClient = $this->createMock(VisionClient::class);
		$visionClient->method('isEnabled')->willReturn(false);
		$service = new RoomImageIngestionService(
			$this->createMock(RoomImageSourceMapper::class),
			$this->createMock(RoomImageEmbeddingMapper::class),
			$this->createMock(AttachmentResolver::class),
			$visionClient,
			$this->createMock(EmbeddingClient::class),
			$this->createSettingsService(),
			$this->createMock(LoggerInterface::class)
		);

		$this->expectExceptionMessage('Room image ingestion is not enabled');
		$service->ingestAttachment(7, 'room-a', 'alice', 1234, $this->createImageAttachment());
	}

	private function createSettingsService(): SettingsService {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getRagConfig')->willReturn([
			'rag_enabled' => true,
			'embedding_model' => 'embed-model',
		]);
		$settingsService->method('getVisionConfig')->willReturn([
			'enabled' => true,
			'model' => 'vision-model',
		]);
		return $settingsService;
	}

	private function createImageAttachment(): IncomingTalkAttachment {
		return new IncomingTalkAttachment(
			IncomingTalkAttachment::KIND_IMAGE,
			'file',
			'image/png',
			'login-error.png',
			'file',
			[
				'fileId' => 42,
			]
		);
	}
}
