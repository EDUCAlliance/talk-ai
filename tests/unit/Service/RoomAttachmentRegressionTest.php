<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use ReflectionMethod;
use OCA\EducAI\Db\{EmbeddingMapper, RoomDocumentEmbedding, RoomDocumentEmbeddingMapper, RoomDocumentSource, RoomDocumentSourceMapper, RoomImageEmbedding, RoomImageEmbeddingMapper, RoomImageSource, RoomImageSourceMapper, Settings};
use OCA\EducAI\Exception\{EmbeddingRateLimitedException, DoclingEmptyResultException};
use OCA\EducAI\Service\{AgentExecutor, AgentRunControl, AttachmentResolver, BuiltInToolProvider, DoclingClient, EmbeddingClient, LLMClient, McpClient, ResolvedAttachment, RoomDocumentIngestionService, RoomImageIngestionService, SettingsService, SpeechToTextClient, ToolRegistry, VisionClient, WikiService};
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RoomAttachmentRegressionTest extends TestCase {
	private function settings(): SettingsService {
		$service = $this->createMock(SettingsService::class);
		$settings = new Settings();
		$settings->setApiEndpoint('https://chat.invalid/v1/chat/completions');
		$service->method('getSettings')->willReturn($settings);
		$service->method('getRagConfig')->willReturn([
			'rag_enabled' => true, 'embedding_model' => 'new-model',
			'embedding_api_endpoint' => 'https://embed.invalid/v1/embeddings',
			'embedding_api_key' => 'test-placeholder', 'rag_chunk_size' => 750, 'rag_chunk_overlap' => 50,
		]);
		$service->method('getDoclingConfig')->willReturn(['docling_enabled' => true]);
		$service->method('getVisionConfig')->willReturn(['enabled' => true, 'model' => 'vision']);
		return $service;
	}

	private function attachment(bool $image = false, string $name = ''): IncomingTalkAttachment {
		return new IncomingTalkAttachment($image ? 'image' : 'document', 'file', $image ? 'image/png' : 'application/pdf', $name ?: ($image ? 'picture.png' : 'document.pdf'), 'file', ['fileId' => 42]);
	}

	private function provider(EmbeddingClient $client, ?RoomDocumentEmbeddingMapper $docs = null, ?RoomImageEmbeddingMapper $images = null, ?AttachmentResolver $resolver = null): BuiltInToolProvider {
		$vision = $this->createMock(VisionClient::class);
		$vision->method('isEnabled')->willReturn(true);
		$speech = $this->createMock(SpeechToTextClient::class);
		$speech->method('isEnabled')->willReturn(true);
		$provider = new BuiltInToolProvider(
			$this->settings(), $this->createMock(EmbeddingMapper::class), $client,
			$docs ?? $this->createMock(RoomDocumentEmbeddingMapper::class), $this->createMock(RoomDocumentSourceMapper::class),
			$images ?? $this->createMock(RoomImageEmbeddingMapper::class), $this->createMock(RoomImageSourceMapper::class),
			$resolver ?? $this->createMock(AttachmentResolver::class), $vision, $speech, $this->createMock(WikiService::class), $this->createMock(LoggerInterface::class)
		);
		$provider->setInvocationContext(['bot_id' => 7, 'room_token' => 'room', 'attachments' => [], 'document_source_ids' => [], 'image_source_ids' => []]);
		return $provider;
	}

	private function executeThroughAgent(BuiltInToolProvider $provider, string $name, array $arguments): array {
		$registry = new ToolProviderRegistry($provider, $this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class));
		$executor = new AgentExecutor($this->createMock(LLMClient::class), $this->createMock(McpClient::class), $this->createMock(ToolRegistry::class), $registry, $this->createMock(LoggerInterface::class));
		$method = new ReflectionMethod(AgentExecutor::class, 'executePreparedToolCall');
		return $method->invoke($executor, [
			'toolCall' => ['id' => 'probe', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]],
			'toolName' => $name, 'toolContext' => ['config' => []], 'arguments' => $arguments, 'rejection' => null,
		], [$name => []], null, new AgentRunControl(300));
	}

	public function testRoomProviderPauseKeepsSafeRetryDetailsThroughAgent(): void {
		foreach ([BuiltInToolProvider::TOOL_ROOM_SEARCH, BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH] as $name) {
			$client = $this->createMock(EmbeddingClient::class);
			$client->method('getActiveModel')->willReturn('new-model');
			$client->method('embedQuery')->willThrowException(new EmbeddingRateLimitedException(120));
			$docs = $this->createMock(RoomDocumentEmbeddingMapper::class);
			$images = $this->createMock(RoomImageEmbeddingMapper::class);
			$docs->method('findByBotRoomAndModel')->willReturn([new \OCA\EducAI\Db\RoomDocumentEmbedding()]);
			$images->method('findByBotRoomAndModel')->willReturn([new RoomImageEmbedding()]);
			$provider = $this->provider($client, $docs, $images);
			$direct = $provider->executeTool($name, ['query' => 'deadline']);
			$this->assertTrue($direct['isError']);
			$this->assertSame('rate_limited', $direct['error']['code']);
			$this->assertSame(120, $direct['error']['details']['retry_after_seconds']);
			$model = $this->executeThroughAgent($provider, $name, ['query' => 'deadline']);
			$error = json_decode($model['message']['content'], true)['error'];
			$this->assertSame('rate_limited', $error['code']);
			$this->assertSame(120, $error['details']['retry_after_seconds']);
		}
	}

	public function testMissingAttachmentKeepsSafeGuidanceThroughAgent(): void {
		$provider = $this->provider($this->createMock(EmbeddingClient::class));
		$direct = $provider->executeTool(BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE, []);
		$this->assertStringContainsString('No image attachment', $direct['content'][0]['text']);
		$model = $this->executeThroughAgent($provider, BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE, []);
		$this->assertSame('attachment_not_found', json_decode($model['message']['content'], true)['error']['code']);
		$this->assertStringContainsString('No image attachment', $model['message']['content']);
	}

	public function testUnchangedDocumentRebuildsForActiveModel(): void {
		$source = new RoomDocumentSource();
		$source->setId(11);
		$source->setChecksum(sha1('same extracted text'));
		$sources = $this->createMock(RoomDocumentSourceMapper::class);
		$sources->method('findOneByBotRoomAndNode')->willReturn($source);
		$sources->method('update')->willReturnArgument(0);
		$vectors = $this->createMock(RoomDocumentEmbeddingMapper::class);
		$vectors->expects($this->once())->method('deleteBySource')->with(11);
		$vectors->expects($this->once())->method('insert')->with($this->callback(static fn ($embedding): bool => $embedding->getEmbeddingModel() === 'new-model' && $embedding->getChunkText() === 'same extracted text'));
		$resolver = $this->createMock(AttachmentResolver::class);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('document.pdf');
		$file->method('getPath')->willReturn('/alice/files/document.pdf');
		$file->method('getId')->willReturn(42);
		$file->method('getMimeType')->willReturn('application/pdf');
		$resolver->method('resolveSourceFile')->willReturn($file);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(true);
		$docling->method('isSupported')->willReturn(true);
		$docling->method('convertToMarkdown')->willReturn('same extracted text');
		$client = $this->createMock(EmbeddingClient::class);
		$old = new RoomDocumentEmbedding();
		$old->setEmbeddingModel('old-model');
		$old->setChunkId('11:0');
		$old->setChunkText('same extracted text');
		$old->setEmbedding('[1,0]');
		$vectors->method('findBySource')->willReturn([$old]);
		$client->expects($this->once())->method('getActiveModel')->willReturn('new-model');
		$client->expects($this->once())->method('embedTexts')->with(['same extracted text'], 'new-model')->willReturn([[0,1]]);
		$service = new RoomDocumentIngestionService($sources, $vectors, $resolver, $client, $docling, $this->settings(), $this->createMock(LoggerInterface::class));
		$result = $service->ingestAttachment(7, 'room', 'alice', 3, $this->attachment());
		$this->assertSame('ready', $result->getStatus());
	}

	public function testUnchangedImageRebuildsFromCachedAnalysisForActiveModel(): void {
		$source = new RoomImageSource();
		$source->setId(22);
		$source->setChecksum(sha1('image bytes'));
		$source->setAnalysisText('old analysis');
		$sources = $this->createMock(RoomImageSourceMapper::class);
		$sources->method('findOneByBotRoomAndNode')->willReturn($source);
		$sources->method('update')->willReturnArgument(0);
		$old = new RoomImageEmbedding();
		$old->setEmbeddingModel('old-model');
		$vectors = $this->createMock(RoomImageEmbeddingMapper::class);
		$vectors->method('findBySource')->willReturn([$old]);
		$vectors->expects($this->once())->method('deleteBySource')->with(22);
		$vectors->expects($this->once())->method('insert')->with($this->callback(static fn (RoomImageEmbedding $embedding): bool => $embedding->getEmbeddingModel() === 'new-model' && $embedding->getChunkText() === 'old analysis'));
		$resolver = $this->createMock(AttachmentResolver::class);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('document.pdf');
		$file->method('getPath')->willReturn('/alice/files/document.pdf');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('image bytes');
		$resolver->method('resolveSourceFile')->willReturn($file);
		$vision = $this->createMock(VisionClient::class);
		$vision->method('isEnabled')->willReturn(true);
		$vision->expects($this->never())->method('analyzeImage');
		$client = $this->createMock(EmbeddingClient::class);
		$client->expects($this->once())->method('getActiveModel')->willReturn('new-model');
		$client->expects($this->once())->method('embedTexts')->with(['old analysis'], 'new-model')->willReturn([[0,1]]);
		$service = new RoomImageIngestionService($sources, $vectors, $resolver, $vision, $client, $this->settings(), $this->createMock(LoggerInterface::class));
		$result = $service->ingestAttachment(7, 'room', 'alice', 3, $this->attachment(true));
		$this->assertSame('ready', $result->getStatus());
	}

	public function testFailedImageReembeddingClearsStaleVectorsAndRetryUsesCachedAnalysis(): void {
		$source = new RoomImageSource();
		$source->setId(22);
		$source->setChecksum(sha1('old image bytes'));
		$source->setAnalysisText('old screenshot says deadline April');
		$sources = $this->createMock(RoomImageSourceMapper::class);
		$sources->method('findOneByBotRoomAndNode')->willReturn($source);
		$sources->method('update')->willReturnArgument(0);
		$old = new RoomImageEmbedding();
		$old->setChunkText('old screenshot says deadline April');
		$vectors = $this->createMock(RoomImageEmbeddingMapper::class);
		$stored = [$old];
		$vectors->method('findBySource')->willReturnCallback(static function () use (&$stored): array { return $stored; });
		$vectors->expects($this->exactly(2))->method('deleteBySource')->willReturnCallback(static function () use (&$stored): void { $stored = []; });
		$vectors->expects($this->once())->method('insert')->willReturnCallback(static function (RoomImageEmbedding $embedding) use (&$stored): RoomImageEmbedding { $stored[] = $embedding; return $embedding; });
		$resolver = $this->createMock(AttachmentResolver::class);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('document.pdf');
		$file->method('getPath')->willReturn('/alice/files/document.pdf');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('new image bytes');
		$resolver->method('resolveSourceFile')->willReturn($file);
		$temp = tempnam(sys_get_temp_dir(), 'educai-room-test-');
		file_put_contents($temp, 'new image bytes');
		$resolver->expects($this->once())->method('resolveToTempFile')->willReturn(new ResolvedAttachment($temp, 'picture.png', 'image/png'));
		$vision = $this->createMock(VisionClient::class);
		$vision->method('isEnabled')->willReturn(true);
		$vision->expects($this->once())->method('analyzeImage')->willReturn('new screenshot says deadline June');
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('new-model');
		$attempts = 0;
		$client->expects($this->exactly(2))->method('embedTexts')->with(['new screenshot says deadline June'], 'new-model')->willReturnCallback(static function () use (&$attempts): array {
			if ($attempts++ === 0) { throw new EmbeddingRateLimitedException(120); }
			return [[0,1]];
		});
		$service = new RoomImageIngestionService($sources, $vectors, $resolver, $vision, $client, $this->settings(), $this->createMock(LoggerInterface::class));
		try {
			$service->ingestAttachment(7, 'room', 'alice', 3, $this->attachment(true));
			$this->fail('Expected provider pause');
		} catch (EmbeddingRateLimitedException $e) {
			$this->assertSame('error', $source->getStatus());
			$this->assertSame(sha1('new image bytes'), $source->getChecksum());
			$this->assertSame('new screenshot says deadline June', $source->getAnalysisText());
			$this->assertSame([], $stored);
		}
		$retry = $service->ingestAttachment(7, 'room', 'alice', 4, $this->attachment(true));
		$this->assertSame('ready', $retry->getStatus());
		$this->assertCount(1, $stored);
		$this->assertSame('new screenshot says deadline June', $stored[0]->getChunkText());
		$this->assertSame('new-model', $stored[0]->getEmbeddingModel());
		$this->assertFileDoesNotExist($temp);
	}

	private function failingDocumentService(string $name, Exception $failure): array {
		$sources = $this->createMock(RoomDocumentSourceMapper::class);
		$sources->method('findOneByBotRoomAndNode')->willReturn(null);
		$sources->method('findOneByBotRoomAndAttachment')->willReturn(null);
		$saved = null;
		$sources->expects($this->once())->method('insert')->willReturnCallback(static function (RoomDocumentSource $source) use (&$saved): RoomDocumentSource {
			$source->setId(33);
			$saved = $source;
			return $source;
		});
		$sources->expects($this->once())->method('update')->willReturnArgument(0);
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/' . $name);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn('application/pdf');
		$resolver = $this->createMock(AttachmentResolver::class);
		$resolver->expects($this->once())->method('resolveSourceFile')->willReturn($file);
		$docling = $this->getMockBuilder(DoclingClient::class)->disableOriginalConstructor()->onlyMethods(['isEnabled', 'convertToMarkdown'])->getMock();
		$docling->method('isEnabled')->willReturn(true);
		$docling->expects($this->once())->method('convertToMarkdown')->willThrowException($failure);
		$client = $this->createMock(EmbeddingClient::class);
		$client->expects($this->never())->method('embedTexts');
		$service = new RoomDocumentIngestionService($sources, $this->createMock(RoomDocumentEmbeddingMapper::class), $resolver, $client, $docling, $this->settings(), $this->createMock(LoggerInterface::class));
		return [$service, static function () use (&$saved): ?RoomDocumentSource { return $saved; }];
	}

	private function messageContext(RoomDocumentIngestionService $docs, array $attachments): array {
		$bot = new \OCA\EducAI\Db\Bot();
		$bot->setId(7);
		$bots = $this->createMock(\OCA\EducAI\Service\BotService::class);
		$bots->method('getEffectiveBuiltInToolNames')->willReturn([BuiltInToolProvider::TOOL_ROOM_SEARCH]);
		$handler = new \OCA\EducAI\Webhook\TalkHandler(
			$bots, $this->settings(), $this->createMock(\OCA\EducAI\Service\OnboardingService::class),
			$this->createMock(\OCA\EducAI\Webhook\TalkMessageParser::class), $docs,
			$this->createMock(RoomImageIngestionService::class), $this->createMock(IClientService::class),
			$this->createMock(\OCP\IDBConnection::class), $this->createMock(\OCP\IURLGenerator::class), $this->createMock(LoggerInterface::class)
		);
		return (new ReflectionMethod($handler, 'buildMessageContext'))->invoke($handler, $bot, 'room', 'alice', 5,
			new \OCA\EducAI\Webhook\IncomingTalkMessage('answer from my document', 'answer from my document', 'room', 'alice', 5, null, $attachments));
	}

	public function testTypedEmptyDoclingResultHasTerminalEmptyState(): void {
		[$service, $saved] = $this->failingDocumentService('no-text.pdf', new DoclingEmptyResultException('No Markdown content'));
		$context = $this->messageContext($service, [$this->attachment(false, 'no-text.pdf')]);
		$this->assertNull($context['capability_error']);
		$this->assertSame(['no-text.pdf'], $context['empty_document_names']);
		$this->assertSame([], $context['context']['document_source_ids']);
		$this->assertSame([], $context['context']['attachments']);
		$this->assertSame('empty', $saved()->getStatus());
		$this->assertNull($saved()->getErrorMessage());
		$this->assertGreaterThan(0, $saved()->getLastIndexedAt());
		$this->assertGreaterThan(0, $saved()->getUpdatedAt());
	}

	public function testStorageFailureHasTerminalStateAndSafePublicMessage(): void {
		$sources = $this->createMock(RoomDocumentSourceMapper::class);
		$sources->method('findOneByBotRoomAndNode')->willReturn(null);
		$sources->method('findOneByBotRoomAndAttachment')->willReturn(null);
		$saved = null;
		$sources->method('insert')->willReturnCallback(static function (RoomDocumentSource $source) use (&$saved): RoomDocumentSource { $source->setId(44); $saved = $source; return $source; });
		$sources->expects($this->once())->method('update')->willReturnArgument(0);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('document.pdf');
		$file->method('getPath')->willReturn('/alice/files/document.pdf');
		$file->method('getId')->willReturn(42);
		$file->method('getMimeType')->willReturn('text/plain');
		$raw = 'fopen(/srv/nextcloud/data/alice/files/private/finance.txt): Permission denied';
		$file->method('getContent')->willThrowException(new \RuntimeException($raw));
		$resolver = $this->createMock(AttachmentResolver::class);
		$resolver->method('resolveSourceFile')->willReturn($file);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(false);
		$service = new RoomDocumentIngestionService($sources, $this->createMock(RoomDocumentEmbeddingMapper::class), $resolver, $this->createMock(EmbeddingClient::class), $docling, $this->settings(), $this->createMock(LoggerInterface::class));
		$context = $this->messageContext($service, [$this->attachment()]);
		$this->assertSame('The uploaded document could not be indexed. Please try uploading it again later.', $context['capability_error']);
		$this->assertStringNotContainsString('/srv/', $context['capability_error']);
		$this->assertSame('error', $saved->getStatus());
		$this->assertSame('Document indexing failed', $saved->getErrorMessage());
		$this->assertGreaterThan(0, $saved->getLastIndexedAt());
	}

	public function testRoomSearchUnexpectedErrorRemainsSafeThroughAgent(): void {
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('new-model');
		$client->method('embedQuery')->willThrowException(new \RuntimeException('storage failed at /srv/private/secret.txt'));
		$docs = $this->createMock(RoomDocumentEmbeddingMapper::class);
		$docs->method('findByBotRoomAndModel')->willReturn([new \OCA\EducAI\Db\RoomDocumentEmbedding()]);
		$provider = $this->provider($client, $docs);
		$direct = $provider->executeTool(BuiltInToolProvider::TOOL_ROOM_SEARCH, ['query' => 'deadline']);
		$this->assertStringNotContainsString('/srv/private/secret.txt', json_encode($direct));
		$this->assertSame('search_failed', $direct['error']['code']);
		$model = $this->executeThroughAgent($provider, BuiltInToolProvider::TOOL_ROOM_SEARCH, ['query' => 'deadline']);
		$this->assertStringNotContainsString('/srv/private', $model['message']['content']);
		$this->assertSame('search_failed', json_decode($model['message']['content'], true)['error']['code']);
	}

	public function testImageAndAudioFailuresKeepSafeStructuredErrorsThroughAgent(): void {
		foreach ([BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE => 'image', BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO => 'audio'] as $tool => $kind) {
			$resolver = $this->createMock(AttachmentResolver::class);
			$resolver->method('resolveToTempFile')->willThrowException(new \RuntimeException('Cannot read /srv/private/attachment'));
			$provider = $this->provider($this->createMock(EmbeddingClient::class), resolver: $resolver);
			$missing = $this->executeThroughAgent($provider, $tool, []);
			$this->assertSame('attachment_not_found', json_decode($missing['message']['content'], true)['error']['code']);
			$provider->setInvocationContext(['bot_id' => 7, 'room_token' => 'room', 'attachments' => [
				new IncomingTalkAttachment($kind, 'file', $kind . '/test', 'attachment', 'file'),
			]]);
			$result = $this->executeThroughAgent($provider, $tool, []);
			$this->assertSame('attachment_processing_failed', json_decode($result['message']['content'], true)['error']['code']);
			$this->assertStringNotContainsString('/srv/private', $result['message']['content']);
		}
	}

	public function testMetadataSidecarDoesNotAbortUsefulDocumentInSameTurn(): void {
		$attachments = (new \OCA\EducAI\Webhook\TalkAttachmentNormalizer())->normalize([
			'sidecar' => ['type' => 'file', 'mimetype' => 'application/pdf', 'name' => '._document.pdf', 'fileId' => 42],
			'document' => ['type' => 'file', 'mimetype' => 'application/pdf', 'name' => 'document.pdf', 'fileId' => 43],
		]);
		$this->assertCount(1, $attachments);
		$docs = $this->createMock(RoomDocumentIngestionService::class);
		$source = new RoomDocumentSource();
		$source->setId(33);
		$source->setStatus('ready');
		$docs->expects($this->once())->method('ingestAttachment')->with(7, 'room', 'alice', 5, $attachments[0])->willReturn($source);
		$context = $this->messageContext($docs, $attachments);
		$this->assertNull($context['capability_error']);
		$this->assertSame([33], $context['context']['document_source_ids']);
	}


	public function testAttachmentMetadataIsUnsupportedBeforeResolvingContent(): void {
		foreach ([BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE => 'image', BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO => 'audio'] as $tool => $kind) {
			$resolver = $this->createMock(AttachmentResolver::class);
			$resolver->expects($this->never())->method('resolveToTempFile');
			$provider = $this->provider($this->createMock(EmbeddingClient::class), resolver: $resolver);
			$provider->setInvocationContext(['bot_id' => 7, 'room_token' => 'room', 'attachments' => [
				new IncomingTalkAttachment($kind, 'file', $kind . '/test', '._attachment', 'file'),
			]]);
			$result = $this->executeThroughAgent($provider, $tool, []);
			$this->assertSame('attachment_unsupported', json_decode($result['message']['content'], true)['error']['code']);
		}
	}

}
