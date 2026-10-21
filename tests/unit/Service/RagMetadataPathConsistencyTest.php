<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\Embedding;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Jobs\SyncRagSourceJob;
use OCA\EducAI\Listener\RagFileEventListener;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\RagFileEventSyncService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

class RagMetadataPathConsistencyTest extends TestCase {
	#[DataProvider('attachedPaths')]
	public function testIngestionAndLaterEditsUseTheSameOwnerPathRule(string $type, string $path, bool $metadata): void {
		$f = $this->fixture($type, $path);
		$f->ingestion->ingestSourceById(1, true);
		$this->assertSame($metadata ? [] : [$f->state->content], $f->state->activeTexts);
		$this->assertSame($metadata ? [0] : [1], $f->state->publishedCounts);
		$this->assertSame($metadata ? [] : [1], $f->sync->affectedSourceIds([$f->file]));
		if ($metadata) {
			$this->assertSame([], $f->state->embeddingCalls, 'Metadata must not reach the embedding provider');
			$this->assertNull($f->source->getChecksum());
			$this->assertSame(KnowledgeSourceStatus::METADATA_ONLY, KnowledgeSourceStatus::decode($f->source->getErrorMessage())['code']);
		} else {
			$this->assertSame('ready', $f->source->getStatus());
		}
	}

	public static function attachedPaths(): array {
		return [
			'normal direct file' => ['file', '/alice/files/docs/manual.txt', false],
			'normal folder' => ['folder', '/alice/files/docs/manual.txt', false],
			'direct file below metadata' => ['file', '/alice/files/__MACOSX/manual.txt', true],
			'attached metadata root' => ['folder', '/alice/files/__MACOSX/manual.txt', true],
			'attached descendant folder' => ['folder', '/alice/files/__MACOSX/docs/manual.txt', true],
			'mixed-case metadata ancestor' => ['file', '/alice/files/__mAcOsX/docs/manual.txt', true],
			'other metadata ancestor' => ['folder', '/alice/files/.Trashes/docs/manual.txt', true],
			'similar ordinary folder' => ['folder', '/alice/files/__MACOSX-notes/manual.txt', false],
		];
	}

	#[DataProvider('sharedViews')]
	public function testForeignShareAliasCannotChangeTheSourceOwnersIndexingRule(string $type, bool $ownerMetadata): void {
		$ownerPath = $ownerMetadata ? '/alice/files/__MACOSX/manual.txt' : '/alice/files/docs/manual.txt';
		$eventPath = $ownerMetadata ? '/bob/files/shared/manual.txt' : '/bob/files/__MACOSX/manual.txt';
		$f = $this->fixture($type, $ownerPath);
		$foreignEvent = $this->snapshotNode('file', 11, $eventPath);
		$f->ingestion->ingestSourceById(1, true);
		$this->assertSame($ownerMetadata ? [] : [$f->state->content], $f->state->activeTexts);
		$this->assertSame($ownerMetadata ? [] : [1], $f->sync->affectedSourceIds([$foreignEvent]));
	}

	public static function sharedViews(): array {
		return [
			'normal owner file, metadata recipient' => ['file', false],
			'metadata owner file, normal recipient' => ['file', true],
			'normal owner folder, metadata recipient' => ['folder', false],
			'metadata owner folder, normal recipient' => ['folder', true],
		];
	}

	#[DataProvider('movingSources')]
	public function testMovesClearAndRestoreTheIndexAndLaterWritesStayConsistent(string $type, string $metadataPath): void {
		$f = $this->fixture($type, '/alice/files/docs/manual.txt');
		$f->ingestion->ingestSourceById(1, true);
		$this->assertSame(['Original document.'], $f->state->activeTexts);

		$this->moveSource($f, $type, $metadataPath);
		$this->assertSame([], $f->state->activeTexts, 'Moving into metadata must remove the previously published text');
		$this->assertNull($f->source->getChecksum());
		$this->assertSame(KnowledgeSourceStatus::METADATA_ONLY, KnowledgeSourceStatus::decode($f->source->getErrorMessage())['code']);
		$f->state->content = 'Edited while excluded.';
		$f->listener->handle(new NodeWrittenEvent($f->file));
		$this->assertSame([], $f->state->queued, 'An excluded source does not need to ingest later writes');
		$this->assertSame([], $f->state->activeTexts);

		$this->moveSource($f, $type, '/alice/files/restored/manual.txt');
		$this->assertSame(['Edited while excluded.'], $f->state->activeTexts);
		$this->assertSame('ready', $f->source->getStatus());
		$f->state->content = 'Edited after restoration.';
		$f->listener->handle(new NodeWrittenEvent($f->file));
		$this->runScheduledSync($f);
		$this->assertSame(['Edited after restoration.'], $f->state->activeTexts);
		$this->assertSame([1, 0, 1, 1], $f->state->publishedCounts);
	}

	public static function movingSources(): array {
		return [
			'file into metadata root' => ['file', '/alice/files/__MACOSX/manual.txt'],
			'file into metadata descendant' => ['file', '/alice/files/__MACOSX/docs/manual.txt'],
			'folder becomes metadata root' => ['folder', '/alice/files/__MACOSX/manual.txt'],
			'folder into metadata descendant' => ['folder', '/alice/files/__MACOSX/docs/manual.txt'],
		];
	}

	private function moveSource(stdClass $f, string $type, string $newFilePath): void {
		$oldPath = $type === 'file' ? $f->state->path : dirname($f->state->path);
		$newPath = $type === 'file' ? $newFilePath : dirname($newFilePath);
		$old = $this->snapshotNode($type, $type === 'file' ? 11 : 10, $oldPath);
		$target = $this->snapshotNode($type, 0, $newPath);
		$f->listener->handle(new BeforeNodeRenamedEvent($old, $target));
		$this->assertSame([], $f->state->queued, 'A move is not scheduled before it succeeds');
		$f->state->path = $newFilePath;
		$f->listener->handle(new NodeRenamedEvent($this->snapshotNode($type, 0, $oldPath), $type === 'file' ? $f->file : $f->folder));
		$this->runScheduledSync($f);
	}

	private function runScheduledSync(stdClass $f): void {
		$this->assertSame([['sourceId' => 1]], $f->state->queued);
		$arguments = array_shift($f->state->queued);
		$f->job->run($arguments);
	}

	private function snapshotNode(string $type, int $id, string $path): Node {
		$node = $this->createMock($type === 'file' ? File::class : Folder::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn(basename($path));
		$node->method('getPath')->willReturn($path);
		return $node;
	}

	private function fixture(string $type, string $path): stdClass {
		$state = (object)[
			'path' => $path, 'content' => 'Original document.', 'activeTexts' => ['Previously indexed text.'],
			'staged' => [], 'publishedCounts' => [], 'embeddingCalls' => [], 'queued' => [],
		];
		$source = new BotSource();
		$source->setId(1);
		$source->setBotId(7);
		$source->setOwnerUid('alice');
		$source->setNodeType($type);
		$source->setNodeId($type === 'file' ? 11 : 10);
		$source->setChecksum('previous-index');
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(11);
		$file->method('getName')->willReturnCallback(static fn (): string => basename($state->path));
		$file->method('getPath')->willReturnCallback(static fn (): string => $state->path);
		$file->method('getMimeType')->willReturn('text/plain');
		$file->method('getContent')->willReturnCallback(static fn (): string => $state->content);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(10);
		$folder->method('getName')->willReturnCallback(static fn (): string => basename(dirname($state->path)));
		$folder->method('getPath')->willReturnCallback(static fn (): string => dirname($state->path));
		$folder->method('getDirectoryListing')->willReturn([$file]);
		$file->method('getParent')->willReturn($folder);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getPath')->willReturn('/alice/files');
		$userFolder->method('getById')->willReturnCallback(static fn (int $id): array => match ($id) {
			11 => [$file], 10 => [$folder], default => [],
		});
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findById')->with(1)->willReturn($source);
		$sources->method('update')->willReturnArgument(0);
		$sources->method('findByNodeIds')->willReturnCallback(static fn (array $ids): array => in_array($source->getNodeId(), $ids, true) ? [$source] : []);
		$sources->method('findFileSources')->willReturn([$source]);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturn([]);
		$embeddings->method('countBySourceAndModel')->willReturnCallback(static fn (): int => count($state->activeTexts));
		$embeddings->method('insertStaged')->willReturnCallback(static function (Embedding $embedding, int $index, string $generation) use ($state): void {
			$state->staged[$generation][$index] = $embedding->getChunkText();
		});
		$embeddings->method('publishStaged')->willReturnCallback(static function (int $sourceId, string $model, string $generation, int $count) use ($state): void {
			$state->publishedCounts[] = $count;
			$state->activeTexts = array_slice($state->staged[$generation] ?? [], 0, $count);
		});
		$client = $this->createMock(EmbeddingClient::class);
		$client->method('getActiveModel')->willReturn('model');
		$client->method('embedTexts')->willReturnCallback(static function (array $texts) use ($state): array {
			$state->embeddingCalls[] = $texts;
			return array_fill(0, count($texts), [0.1, 0.2]);
		});
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(static function ($class, $when, $arguments) use ($state): void {
			$state->queued[] = $arguments;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$ingestion = new RagIngestionService($sources, $embeddings, $client, $this->createMock(DoclingClient::class), $this->createMock(UrlContentFetcher::class), $settings, $root, $jobs, $logger);
		$sync = new RagFileEventSyncService($sources, $root, $jobs, $logger);
		return (object)[
			'state' => $state, 'source' => $source, 'file' => $file, 'folder' => $folder,
			'ingestion' => $ingestion, 'sync' => $sync, 'listener' => new RagFileEventListener($sync),
			'job' => new SyncRagSourceJob($this->createMock(ITimeFactory::class), $sources, $ingestion, $jobs),
		];
	}
}
