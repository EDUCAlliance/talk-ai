<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Db\RoomDocumentEmbedding;
use OCA\EducAI\Db\RoomDocumentEmbeddingMapper;
use OCA\EducAI\Db\RoomDocumentSourceMapper;
use OCA\EducAI\Db\RoomImageEmbedding;
use OCA\EducAI\Db\RoomImageEmbeddingMapper;
use OCA\EducAI\Db\RoomImageSourceMapper;
use OCA\EducAI\Service\AttachmentResolver;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\ToolExecutionPolicyService;
use OCA\EducAI\Service\VisionClient;
use OCA\EducAI\Service\WikiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BuiltInToolProviderTest extends TestCase {
	public function testWikiWriteToolDoesNotExposeProposalMode(): void {
		$provider = $this->createProvider();

		$tools = $provider->getAvailableTools();
		$writeTool = null;
		foreach ($tools as $tool) {
			if (($tool['name'] ?? null) === BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE) {
				$writeTool = $tool;
				break;
			}
		}

		$this->assertNotNull($writeTool);
		$this->assertSame(['create', 'overwrite', 'append'], $writeTool['schema']['properties']['mode']['enum']);
		$this->assertStringNotContainsString('propose', $writeTool['description']);
	}

	public function testWikiReadToolExposesPaginationParameters(): void {
		$provider = $this->createProvider();

		$tools = $provider->getAvailableTools();
		$readTool = null;
		foreach ($tools as $tool) {
			if (($tool['name'] ?? null) === BuiltInToolProvider::TOOL_WIKI_READ_PAGE) {
				$readTool = $tool;
				break;
			}
		}

		$this->assertNotNull($readTool);
		$this->assertStringContainsString('has_more=true', $readTool['description']);
		$this->assertStringContainsString('offset=next_offset', $readTool['description']);
		$this->assertStringContainsString('path and next_offset', $readTool['description']);
		$this->assertSame(['path'], $readTool['schema']['required']);
		$this->assertArrayHasKey('offset', $readTool['schema']['properties']);
		$this->assertArrayHasKey('limit', $readTool['schema']['properties']);
		$this->assertSame(0, $readTool['schema']['properties']['offset']['default']);
		$this->assertSame(3000, $readTool['schema']['properties']['limit']['default']);
	}

	public function testBuiltInToolsExposeInternalPolicyMetadata(): void {
		$provider = $this->createProvider();

		$tools = $provider->getAvailableTools();
		$byName = [];
		foreach ($tools as $tool) {
			$byName[$tool['name']] = $tool;
		}

		$this->assertSame(ToolExecutionPolicyService::KIND_SEARCH, $byName[BuiltInToolProvider::TOOL_RAG_SEARCH]['policy']['kind']);
		$this->assertSame(10, $byName[BuiltInToolProvider::TOOL_RAG_SEARCH]['policy']['loop_threshold']);
		$this->assertSame(ToolExecutionPolicyService::KIND_WRITE, $byName[BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE]['policy']['kind']);
		$this->assertSame(4, $byName[BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE]['policy']['loop_threshold']);
	}

	public function testWikiReadPassesPaginationArgumentsToWikiService(): void {
		$wikiService = $this->createMock(WikiService::class);
		$provider = $this->createProvider(wikiService: $wikiService);
		$provider->setInvocationContext([
			'bot_id' => 7,
			'room_token' => 'room-a',
			'attachments' => [],
			'document_source_ids' => [],
		]);

		$wikiService->expects($this->once())
			->method('readPage')
			->with(7, 'log.md', 3000, 1200, ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom'])
			->willReturn([
				'success' => true,
				'action' => 'read',
				'path' => 'log.md',
				'wiki_root' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom',
				'offset' => 3000,
				'limit' => 1200,
				'returned_length' => 10,
				'total_length' => 3010,
				'size' => 3010,
				'has_more' => false,
				'next_offset' => null,
				'content' => 'tail chunk',
			]);

		$result = $provider->executeTool(
			BuiltInToolProvider::TOOL_WIKI_READ_PAGE,
			['path' => 'log.md', 'offset' => '3000', 'limit' => '1200'],
			['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom']
		);

		$this->assertFalse($result['isError']);
		$this->assertStringContainsString('"offset": 3000', $result['content'][0]['text']);
		$this->assertStringContainsString('"limit": 1200', $result['content'][0]['text']);
		$this->assertStringContainsString('tail chunk', $result['content'][0]['text']);
	}

	public function testExecuteAttachmentImageAnalysisWithoutAttachmentContextReturnsError(): void {
		$provider = $this->createProvider();

		$result = $provider->executeTool(BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE, []);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('No image attachment', $result['content'][0]['text']);
	}

	public function testRoomSearchUsesRoomScopedMapperAndReturnsResults(): void {
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$roomEmbeddingMapper = $this->createMock(RoomDocumentEmbeddingMapper::class);
		$provider = $this->createProvider(embeddingClient: $embeddingClient, roomDocumentEmbeddingMapper: $roomEmbeddingMapper);

		$provider->setInvocationContext([
			'bot_id' => 7,
			'room_token' => 'room-a',
			'attachments' => [],
			'document_source_ids' => [11],
		]);

		$embedding = new RoomDocumentEmbedding();
		$embedding->setEmbedding(json_encode([1.0, 0.0], JSON_THROW_ON_ERROR));
		$embedding->setChunkText('The uploaded PDF says the deadline is 30 April.');
		$embedding->setMetadata(json_encode([
			'display_name' => 'handout.pdf',
			'chunk_index' => 0,
		], JSON_THROW_ON_ERROR));

		$embeddingClient->expects($this->once())
			->method('getActiveModel')
			->willReturn('embed-model');
		$roomEmbeddingMapper->expects($this->once())
			->method('findByBotRoomAndModel')
			->with(7, 'room-a', 'embed-model')
			->willReturn([$embedding]);
		$embeddingClient->expects($this->once())
			->method('embedTexts')
			->with(['deadline'], 'embed-model')
			->willReturn([[1.0, 0.0]]);

		$result = $provider->executeTool(BuiltInToolProvider::TOOL_ROOM_SEARCH, [
			'query' => 'deadline',
		]);

		$this->assertFalse($result['isError']);
		$this->assertStringContainsString('handout.pdf', $result['content'][0]['text']);
		$this->assertStringContainsString('30 April', $result['content'][0]['text']);
	}

	public function testRoomSearchFallsBackToCurrentDocumentAtLowerThreshold(): void {
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$roomEmbeddingMapper = $this->createMock(RoomDocumentEmbeddingMapper::class);
		$provider = $this->createProvider(embeddingClient: $embeddingClient, roomDocumentEmbeddingMapper: $roomEmbeddingMapper);

		$provider->setInvocationContext([
			'bot_id' => 7,
			'room_token' => 'room-a',
			'attachments' => [],
			'document_source_ids' => [11],
		]);

		$currentDocumentEmbedding = new RoomDocumentEmbedding();
		$currentDocumentEmbedding->setSourceId(11);
		$currentDocumentEmbedding->setEmbedding(json_encode([0.25, 0.9682458365518543], JSON_THROW_ON_ERROR));
		$currentDocumentEmbedding->setChunkText('This PDF explains the module deadline on page 2.');
		$currentDocumentEmbedding->setMetadata(json_encode([
			'display_name' => 'current.pdf',
			'chunk_index' => 0,
		], JSON_THROW_ON_ERROR));

		$otherDocumentEmbedding = new RoomDocumentEmbedding();
		$otherDocumentEmbedding->setSourceId(12);
		$otherDocumentEmbedding->setEmbedding(json_encode([0.29, 0.9570266453970862], JSON_THROW_ON_ERROR));
		$otherDocumentEmbedding->setChunkText('Another room document with a slightly better generic match.');
		$otherDocumentEmbedding->setMetadata(json_encode([
			'display_name' => 'other.pdf',
			'chunk_index' => 0,
		], JSON_THROW_ON_ERROR));

		$embeddingClient->expects($this->once())
			->method('getActiveModel')
			->willReturn('embed-model');
		$roomEmbeddingMapper->expects($this->once())
			->method('findByBotRoomAndModel')
			->with(7, 'room-a', 'embed-model')
			->willReturn([$currentDocumentEmbedding, $otherDocumentEmbedding]);
		$embeddingClient->expects($this->once())
			->method('embedTexts')
			->with(['deadline'], 'embed-model')
			->willReturn([[1.0, 0.0]]);

		$result = $provider->executeTool(BuiltInToolProvider::TOOL_ROOM_SEARCH, [
			'query' => 'deadline',
		]);

		$this->assertFalse($result['isError']);
		$this->assertStringContainsString('current.pdf', $result['content'][0]['text']);
		$this->assertStringContainsString('module deadline', $result['content'][0]['text']);
		$this->assertStringNotContainsString('other.pdf', $result['content'][0]['text']);
	}

	public function testRoomSearchFallsBackToRoomWideLowerThresholdWhenNeeded(): void {
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$roomEmbeddingMapper = $this->createMock(RoomDocumentEmbeddingMapper::class);
		$provider = $this->createProvider(embeddingClient: $embeddingClient, roomDocumentEmbeddingMapper: $roomEmbeddingMapper);

		$provider->setInvocationContext([
			'bot_id' => 7,
			'room_token' => 'room-a',
			'attachments' => [],
			'document_source_ids' => [],
		]);

		$embedding = new RoomDocumentEmbedding();
		$embedding->setSourceId(12);
		$embedding->setEmbedding(json_encode([0.25, 0.9682458365518543], JSON_THROW_ON_ERROR));
		$embedding->setChunkText('The uploaded room PDF contains the meeting notes.');
		$embedding->setMetadata(json_encode([
			'display_name' => 'notes.pdf',
			'chunk_index' => 0,
		], JSON_THROW_ON_ERROR));

		$embeddingClient->expects($this->once())
			->method('getActiveModel')
			->willReturn('embed-model');
		$roomEmbeddingMapper->expects($this->once())
			->method('findByBotRoomAndModel')
			->with(7, 'room-a', 'embed-model')
			->willReturn([$embedding]);
		$embeddingClient->expects($this->once())
			->method('embedTexts')
			->with(['meeting notes'], 'embed-model')
			->willReturn([[1.0, 0.0]]);

		$result = $provider->executeTool(BuiltInToolProvider::TOOL_ROOM_SEARCH, [
			'query' => 'meeting notes',
		]);

		$this->assertFalse($result['isError']);
		$this->assertStringContainsString('notes.pdf', $result['content'][0]['text']);
		$this->assertStringContainsString('meeting notes', $result['content'][0]['text']);
	}

	public function testRoomImageSearchUsesRoomScopedMapperAndReturnsResults(): void {
		$embeddingClient = $this->createMock(EmbeddingClient::class);
		$roomImageEmbeddingMapper = $this->createMock(RoomImageEmbeddingMapper::class);
		$provider = $this->createProvider(embeddingClient: $embeddingClient, roomImageEmbeddingMapper: $roomImageEmbeddingMapper);

		$provider->setInvocationContext([
			'bot_id' => 7,
			'room_token' => 'room-a',
			'attachments' => [],
			'document_source_ids' => [],
			'image_source_ids' => [21],
		]);

		$embedding = new RoomImageEmbedding();
		$embedding->setSourceId(21);
		$embedding->setEmbedding(json_encode([1.0, 0.0], JSON_THROW_ON_ERROR));
		$embedding->setChunkText('Screenshot shows a red login error banner: invalid credentials.');
		$embedding->setMetadata(json_encode([
			'display_name' => 'login-error.png',
			'message_id' => 1234,
			'actor_id' => 'alice',
			'created_at' => 1710000000,
		], JSON_THROW_ON_ERROR));

		$embeddingClient->expects($this->once())
			->method('getActiveModel')
			->willReturn('embed-model');
		$roomImageEmbeddingMapper->expects($this->once())
			->method('findByBotRoomAndModel')
			->with(7, 'room-a', 'embed-model')
			->willReturn([$embedding]);
		$embeddingClient->expects($this->once())
			->method('embedTexts')
			->with(['login error'], 'embed-model')
			->willReturn([[1.0, 0.0]]);

		$result = $provider->executeTool(BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH, [
			'query' => 'login error',
		]);

		$this->assertFalse($result['isError']);
		$this->assertStringContainsString('login-error.png', $result['content'][0]['text']);
		$this->assertStringContainsString('Talk message ID: 1234', $result['content'][0]['text']);
		$this->assertStringContainsString('invalid credentials', $result['content'][0]['text']);
	}

	public function testRoomImageSearchRequiresRoomContext(): void {
		$provider = $this->createProvider();

		$result = $provider->executeTool(BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH, [
			'query' => 'login error',
		]);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('No room context', $result['content'][0]['text']);
	}

	private function createProvider(
		?EmbeddingClient $embeddingClient = null,
		?RoomDocumentEmbeddingMapper $roomDocumentEmbeddingMapper = null,
		?RoomImageEmbeddingMapper $roomImageEmbeddingMapper = null,
		?RoomImageSourceMapper $roomImageSourceMapper = null,
		?WikiService $wikiService = null,
	): BuiltInToolProvider {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getRagConfig')
			->willReturn([
				'rag_enabled' => true,
				'embedding_api_endpoint' => null,
				'embedding_api_key' => null,
				'embedding_model' => 'embed-model',
				'embedding_rate_limit_mode' => 'inherit',
				'embedding_rate_limit_second' => null,
				'embedding_rate_limit_minute' => 100,
				'embedding_rate_limit_hour' => 2000,
				'embedding_rate_limit_day' => 4000,
				'rag_chunk_size' => 750,
				'rag_chunk_overlap' => 50,
			]);
		$settingsService->method('getDoclingConfig')
			->willReturn([
				'docling_enabled' => true,
				'docling_api_endpoint' => null,
				'api_key' => 'token',
			]);

		$visionClient = $this->createMock(VisionClient::class);
		$visionClient->method('isEnabled')->willReturn(true);
		$speechClient = $this->createMock(SpeechToTextClient::class);
		$speechClient->method('isEnabled')->willReturn(true);

		return new BuiltInToolProvider(
			$settingsService,
			$this->createMock(EmbeddingMapper::class),
			$embeddingClient ?? $this->createMock(EmbeddingClient::class),
			$roomDocumentEmbeddingMapper ?? $this->createMock(RoomDocumentEmbeddingMapper::class),
			$this->createMock(RoomDocumentSourceMapper::class),
			$roomImageEmbeddingMapper ?? $this->createMock(RoomImageEmbeddingMapper::class),
			$roomImageSourceMapper ?? $this->createMock(RoomImageSourceMapper::class),
			$this->createMock(AttachmentResolver::class),
			$visionClient,
			$speechClient,
			$wikiService ?? $this->createMock(WikiService::class),
			$this->createMock(LoggerInterface::class)
		);
	}
}
