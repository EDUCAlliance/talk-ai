<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\{BotSource, BotSourceMapper, Embedding, EmbeddingMapper, RoomDocumentEmbeddingMapper, RoomDocumentSourceMapper, RoomImageEmbeddingMapper, RoomImageSourceMapper};
use OCA\EducAI\Jobs\SyncRagSourceJob;
use OCA\EducAI\Service\{AttachmentResolver, BuiltInToolProvider, EmbeddingClient, RagRetriever, RagSourceLocator, SettingsService, SpeechToTextClient, VisionClient, WikiService};
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\{File, Folder, IRootFolder};
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Real retrieval/mapper/locator composition; only storage, queue and HTTP are doubles. */
class RagRetrievalPermissionsTest extends TestCase {
	private array $nodes = [];
	private array $scheduled = [];
	private array $lookups = [];

	public function testRevocationIsImmediateForBothCallersAndRegrantIsNotCached(): void {
		$source = $this->source();
		$this->nodes = [10 => [$this->node(Folder::class, 10, 'knowledge')], 20 => [$this->node(File::class, 20, 'knowledge/shared.txt')]];
		$mapper = $this->mapper([$source], [$this->embedding(1, ['node_id' => 20])]);
		[$retriever, $tool] = $this->callers($mapper);
		$this->assertCount(1, $retriever->retrieve(7, 'needle'));
		$this->assertStringContainsString('protected text', $tool->executeTool('rag_search_documents', ['query' => 'needle'])['content'][0]['text']);
		$this->nodes[20] = []; // Native share unmount; source remains READY and reachable.
		$this->assertSame([], $retriever->retrieve(7, 'needle'));
		$result = $tool->executeTool('rag_search_documents', ['query' => 'needle']);
		$this->assertSame('sources_unavailable', $result['error']['code']);
		$this->assertStringNotContainsString('protected text', json_encode($result));
		$this->nodes[20] = [$this->node(File::class, 20, 'knowledge/restored.txt')];
		$restored = $retriever->retrieve(7, 'needle');
		$this->assertCount(1, $restored);
		$this->assertSame('knowledge/restored.txt', $restored[0]['metadata']['path']);
	}

	public function testAnotherAccessibleAliasOutsideSourceCannotAuthorizeRevokedOrMovedChunk(): void {
		$source = $this->source();
		$this->nodes = [10 => [$this->node(Folder::class, 10, 'knowledge')], 20 => [$this->node(File::class, 20, 'elsewhere/shared.txt')]];
		$mapper = $this->mapper([$source], [$this->embedding(1, ['node_id' => 20])]);
		$this->assertSame([], $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->nodes[20][] = $this->node(File::class, 20, 'knowledge-two/shared.txt');
		$this->assertSame([], $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->nodes[20][] = $this->node(File::class, 20, 'knowledge/renamed.txt');
		$this->assertCount(1, $mapper->findRetrievableByBotAndModel(7, 'model'));
	}

	public function testLegacyFolderPathsFailClosedAndQueueOneReindexAcrossRequests(): void {
		$source = $this->source();
		$this->nodes = [10 => [$this->node(Folder::class, 10, 'knowledge')], 999 => [$this->node(File::class, 999, 'knowledge/shared.txt')]];
		$mapper = $this->mapper([$source], [$this->embedding(1, ['path' => 'knowledge/shared.txt'])]);
		for ($i = 0; $i < 3; $i++) {
			$this->assertSame([], $mapper->findRetrievableByBotAndModel(7, 'model'));
		}
		$this->assertSame([['sourceId' => 1]], array_values($this->scheduled));
	}

	public function testSamePathReplacementCannotAuthorizeIndexedFileIdentity(): void {
		$this->nodes = [10 => [$this->node(Folder::class, 10, 'knowledge')], 20 => [], 999 => [$this->node(File::class, 999, 'knowledge/shared.txt')]];
		$mapper = $this->mapper([$this->source()], [$this->embedding(1, ['node_id' => 20, 'path' => 'knowledge/shared.txt'])]);
		$this->assertSame([], $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->assertSame([], $this->scheduled);
	}

	public function testLegacyDirectFileUsesSourceIdentityButStillRequiresReadPermission(): void {
		$source = $this->source('file', 20);
		$this->nodes[20] = [$this->node(File::class, 20, 'renamed.txt')];
		$mapper = $this->mapper([$source], [$this->embedding(1, ['path' => 'old.txt'])]);
		$this->assertCount(1, $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->nodes[20] = [$this->node(File::class, 20, 'renamed.txt', false)];
		$this->assertSame([], $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->assertSame([], $this->scheduled);
	}

	public function testOnlyAuthorizedChunksRemainAndLookupsAreBoundedByFilesNotChunks(): void {
		$this->nodes = [10 => [$this->node(Folder::class, 10, 'knowledge')], 20 => [$this->node(File::class, 20, 'knowledge/allowed.txt')], 21 => []];
		$rows = [];
		for ($i = 0; $i < 100; $i++) {
			$rows[] = $this->embedding(1, ['node_id' => 20]);
		}
		$rows[] = $this->embedding(1, ['node_id' => 21]);
		$mapper = $this->mapper([$this->source()], $rows);
		$this->assertCount(100, $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->assertSame([10 => 1, 20 => 1, 21 => 1], $this->lookups);
	}

	public function testFolderAliasesAndMetadataExclusionUseCurrentOwnerView(): void {
		$this->nodes = [
			10 => [$this->node(Folder::class, 10, 'first-alias'), $this->node(Folder::class, 10, 'second-alias')],
			20 => [$this->node(File::class, 20, 'second-alias/readable.txt')],
		];
		$mapper = $this->mapper([$this->source()], [$this->embedding(1, ['node_id' => 20])]);
		$this->assertCount(1, $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->nodes[20] = [$this->node(File::class, 20, 'second-alias/__MACOSX/readable.txt')];
		$this->assertSame([], $mapper->findRetrievableByBotAndModel(7, 'model'));
	}

	public function testStorageErrorIsFailClosedForOneFileNotItsReadableSibling(): void {
		$this->nodes = [10 => [$this->node(Folder::class, 10, 'knowledge')], 20 => new \RuntimeException('storage gone'), 21 => [$this->node(File::class, 21, 'knowledge/readable.txt')]];
		$mapper = $this->mapper([$this->source()], [$this->embedding(1, ['node_id' => 20]), $this->embedding(1, ['node_id' => 21])]);
		$this->assertCount(1, $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->nodes[10] = new \RuntimeException('source storage gone');
		$this->assertSame([], $mapper->findRetrievableByBotAndModel(7, 'model'));
	}

	public function testUrlSourcesDoNotNeedFilesystemIdentity(): void {
		$source = $this->source('url', 0);
		$mapper = $this->mapper([$source], [$this->embedding(1, ['path' => 'https://example.invalid/article'])]);
		$this->assertCount(1, $mapper->findRetrievableByBotAndModel(7, 'model'));
		$this->assertSame([], $this->lookups);
		$this->assertSame([], $this->scheduled);
	}

	private function source(string $type = 'folder', int $nodeId = 10): BotSource {
		$source = new BotSource();
		$source->setId(1);
		$source->setBotId(7);
		$source->setOwnerUid('bob');
		$source->setNodeId($nodeId);
		$source->setNodeType($type);
		$source->setStatus('ready');
		return $source;
	}

	private function node(string $class, int $id, string $path, bool $readable = true): object {
		$node = $this->getMockBuilder($class)->onlyMethods(get_class_methods($class))->addMethods(['isReadable'])->getMock();
		$node->method('getId')->willReturn($id);
		$node->method('getPath')->willReturn('/bob/files/' . $path);
		$node->method('isReadable')->willReturn($readable);
		return $node;
	}

	private function embedding(int $sourceId, array $metadata): Embedding {
		$embedding = new Embedding();
		$embedding->setSourceId($sourceId);
		$embedding->setBotId(7);
		$embedding->setEmbedding('[1,0]');
		$embedding->setEmbeddingModel('model');
		$embedding->setChunkText('protected text');
		$embedding->setMetadata(json_encode($metadata));
		return $embedding;
	}

	private function mapper(array $sources, array $rows): EmbeddingMapper {
		$owner = $this->createMock(Folder::class);
		$owner->method('getPath')->willReturn('/bob/files');
		$owner->method('getById')->willReturnCallback(function (int $id): array {
			$this->lookups[$id] = ($this->lookups[$id] ?? 0) + 1;
			$result = $this->nodes[$id] ?? [];
			if ($result instanceof \Throwable) { throw $result; }
			return $result;
		});
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('bob')->willReturn($owner);
		$sourceMapper = $this->createMock(BotSourceMapper::class);
		$sourceMapper->method('findById')->willReturnCallback(static fn (int $id): BotSource => $sources[$id - 1]);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturnCallback(fn ($job, array $args): bool => isset($this->scheduled[json_encode($args)]));
		$jobs->method('scheduleAfter')->willReturnCallback(function ($job, int $at, array $args): void {
			self::assertSame(SyncRagSourceJob::class, $job);
			$this->scheduled[json_encode($args)] = $args;
		});
		$query = new class(count($rows)) implements IQueryBuilder {
			public function __construct(private int $count) {}
			public function __call(string $name, array $args): self { return $this; }
			public function executeQuery(): object {
				return new class($this->count) {
					public function __construct(private int $count) {}
					public function fetchOne(): int { return $this->count; }
					public function closeCursor(): void {}
				};
			}
		};
		$db = new class($query) implements IDBConnection {
			public function __construct(private object $query) {}
			public function getQueryBuilder(): object { return $this->query; }
		};
		return new class($rows, $db, $sourceMapper, new RagSourceLocator($root), $jobs) extends EmbeddingMapper {
			public function __construct(private array $rows, ...$args) { parent::__construct(...$args); }
			protected function findEntities($query): array { return $this->rows; }
		};
	}

	private function callers(EmbeddingMapper $mapper): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('model');
		$client->method('embedQuery')->willReturn([1, 0]);
		$provider = new BuiltInToolProvider(
			$settings, $mapper, $client,
			$this->createMock(RoomDocumentEmbeddingMapper::class), $this->createMock(RoomDocumentSourceMapper::class),
			$this->createMock(RoomImageEmbeddingMapper::class), $this->createMock(RoomImageSourceMapper::class),
			$this->createMock(AttachmentResolver::class), $this->createMock(VisionClient::class),
			$this->createMock(SpeechToTextClient::class), $this->createMock(WikiService::class), $this->createMock(LoggerInterface::class),
		);
		$provider->setBotContext(7);
		return [new RagRetriever($mapper, $client, $settings), $provider];
	}
}
