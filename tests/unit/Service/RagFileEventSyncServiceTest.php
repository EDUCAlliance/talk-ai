<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Jobs\SyncRagSourceJob;
use OCA\EducAI\Listener\RagFileEventListener;
use OCA\EducAI\Service\RagFileEventSyncService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RagFileEventSyncServiceTest extends TestCase {
	public function testMatchesFileAndAncestorSourcesAcrossBotsWithoutChangingTheirState(): void {
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->expects($this->once())->method('findByNodeIds')->with([11, 10, 1])->willReturn([$this->source(2, 11), $this->source(3, 10)]);
		$sources->expects($this->never())->method('update');
		$node = $this->node(11, '/alice/files/docs/a.txt', $this->node(10, '/alice/files/docs', $this->node(1, '/alice/files')));
		$service = $this->service($sources);
		$this->assertSame([2, 3], $service->affectedSourceIds([$node]));
	}

	public function testMetadataAndUnrelatedChangesDoNotQueueSources(): void {
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->expects($this->exactly(2))->method('findByNodeIds')->willReturnCallback(function (array $ids): array {
			return array_map(fn (int $id): BotSource => $this->source($id, $id), array_diff($ids, [20]));
		});
		$service = $this->service($sources);
		$this->assertSame([], $service->affectedSourceIds([
			$this->node(11, '/alice/files/docs/._a.pdf'),
			$this->node(12, '/alice/files/docs/.DS_Store'),
			$this->node(13, '/alice/files/docs/__MACOSX/data.txt'),
		]));
		$this->assertSame([], $service->affectedSourceIds([$this->node(20, '/alice/files/unrelated.txt')]));
	}

	/** @dataProvider sharedMetadataViews */
	public function testMetadataFilteringUsesEachSourceOwnersView(string $sourceType, string $aliceName, string $bobName, array $expected): void {
		$aliceFolder = $this->folder(10, '/alice/files/knowledge');
		$bobFolder = $this->folder(100, '/bob/files/knowledge');
		$original = $this->node(11, '/alice/files/knowledge/' . $aliceName, $aliceFolder);
		$shared = $this->node(11, '/bob/files/knowledge/' . $bobName, $bobFolder);
		$shared->method('getOwner')->willReturn(new class { public function getUID(): string { return 'alice'; } });
		$aliceSource = $this->source(1, $sourceType === 'file' ? 11 : 10);
		$bobSource = $this->source(2, $sourceType === 'file' ? 11 : 100);
		$bobSource->setOwnerUid('bob');
		foreach ([$aliceSource, $bobSource] as $source) {
			$source->setNodeType($sourceType);
		}
		$allSources = [$aliceSource, $bobSource];
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findByNodeIds')->willReturnCallback(static fn (array $ids): array => array_values(array_filter($allSources, static fn (BotSource $source): bool => in_array($source->getNodeId(), $ids, true))));
		$sources->method('findFileSources')->willReturn($allSources);
		$alice = $this->createMock(Folder::class);
		$alice->method('getById')->willReturnMap([[10, [$aliceFolder]], [11, [$original]]]);
		$bob = $this->createMock(Folder::class);
		$bob->method('getById')->willReturnMap([[100, [$bobFolder]], [11, [$shared]]]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([['alice', $alice], ['bob', $bob]]);
		$this->assertSame($expected, $this->service($sources, $root)->affectedSourceIds([$shared]));
	}

	public static function sharedMetadataViews(): array {
		$cases = [];
		foreach (['file', 'folder'] as $type) {
			$cases[$type . ': both normal'] = [$type, 'original.txt', 'shared.txt', [1, 2]];
			foreach (['._shared.txt', '__MACOSX/shared.txt'] as $metadata) {
				$cases[$type . ': recipient metadata ' . $metadata] = [$type, 'original.txt', $metadata, [1]];
				$cases[$type . ': original metadata ' . $metadata] = [$type, $metadata, 'shared.txt', [2]];
				$cases[$type . ': both metadata ' . $metadata] = [$type, $metadata, $metadata, []];
			}
		}
		return $cases;
	}

	/** @dataProvider sharedMetadataRenames */
	public function testSharedAliasRenamesPreserveNormalOwnerAndChangedRecipientSources(string $sourceType, string $oldName, string $newName): void {
		$aliceFolder = $this->folder(10, '/alice/files/knowledge');
		$bobFolder = $this->folder(100, '/bob/files/knowledge');
		$original = $this->node(11, '/alice/files/knowledge/original.txt', $aliceFolder);
		$old = $this->node(11, '/bob/files/knowledge/' . $oldName, $bobFolder);
		$old->method('getOwner')->willReturn(new class { public function getUID(): string { return 'alice'; } });
		$target = $this->node(0, '/bob/files/knowledge/' . $newName, $bobFolder);
		$renamed = $this->node(11, $target->getPath(), $bobFolder);
		$renamed->method('getOwner')->willReturn(new class { public function getUID(): string { return 'alice'; } });
		$recipientView = $old;
		$aliceSource = $this->source(1, $sourceType === 'file' ? 11 : 10);
		$bobSource = $this->source(2, $sourceType === 'file' ? 11 : 100);
		$bobSource->setOwnerUid('bob');
		foreach ([$aliceSource, $bobSource] as $source) {
			$source->setNodeType($sourceType);
		}
		$allSources = [$aliceSource, $bobSource];
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findByNodeIds')->willReturnCallback(static fn (array $ids): array => array_values(array_filter($allSources, static fn (BotSource $source): bool => in_array($source->getNodeId(), $ids, true))));
		$sources->method('findFileSources')->willReturn($allSources);
		$alice = $this->createMock(Folder::class);
		$alice->method('getById')->willReturnMap([[10, [$aliceFolder]], [11, [$original]]]);
		$bob = $this->createMock(Folder::class);
		$bob->method('getById')->willReturnCallback(static function (int $id) use (&$recipientView, $bobFolder): array { return $id === 11 ? [$recipientView] : ($id === 100 ? [$bobFolder] : []); });
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([['alice', $alice], ['bob', $bob]]);
		$queued = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(static function ($class, $when, $args) use (&$queued): void { $queued[] = $args['sourceId']; });
		$listener = new RagFileEventListener($this->service($sources, $root, $jobs));
		$listener->handle(new BeforeNodeRenamedEvent($old, $target));
		$this->assertSame([], $queued, 'A failed rename must not queue any work');
		$recipientView = $renamed;
		$listener->handle(new NodeRenamedEvent($this->node(0, $old->getPath(), $bobFolder), $renamed));
		sort($queued);
		$this->assertSame([1, 2], $queued, 'Capture the old normal view and refresh the new normal view without losing the original owner');
	}

	public static function sharedMetadataRenames(): array {
		$cases = [];
		foreach (['file', 'folder'] as $type) {
			foreach (['._shared.txt', '__MACOSX/shared.txt'] as $metadata) {
				$cases[$type . ': metadata to normal ' . $metadata] = [$type, $metadata, 'shared.txt'];
				$cases[$type . ': normal to metadata ' . $metadata] = [$type, 'shared.txt', $metadata];
			}
		}
		return $cases;
	}

	public function testNonExistingOldRenameNodeStillMatchesItsParent(): void {
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->expects($this->once())->method('findByNodeIds')->with([10, 21, 20])->willReturn([$this->source(1, 10), $this->source(2, 20)]);
		$old = $this->createMock(Node::class);
		$old->method('getId')->willThrowException(new \OCP\Files\NotFoundException());
		$old->method('getPath')->willReturn('/alice/files/old/a.txt');
		$old->method('getParent')->willReturn($this->node(10, '/alice/files/old'));
		$new = $this->node(21, '/alice/files/new/a.txt', $this->node(20, '/alice/files/new'));
		$this->assertSame([1, 2], $this->service($sources)->affectedSourceIds([$old, $new]));
	}

	public function testBeforeRenameMissingTargetFolderIdentityStillMatchesDestinationParent(): void {
		$target = $this->createMock(Folder::class);
		$target->method('getId')->willThrowException(new \OCP\Files\NotFoundException());
		$target->method('getPath')->willReturn('/alice/files/destination/new-folder');
		$target->method('getParent')->willReturn($this->node(20, '/alice/files/destination'));
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->expects($this->once())->method('findByNodeIds')->with([20])->willReturn([$this->source(2, 20)]);
		$this->assertSame([2], $this->service($sources)->affectedSourceIds([$target], true));
	}

	public function testWriteThroughSharedMountIncludesOriginalOwnerAncestors(): void {
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->expects($this->once())->method('findByNodeIds')
			->with($this->callback(static fn (array $ids): bool => in_array(10, $ids, true) && in_array(100, $ids, true)))
			->willReturn([$this->source(1, 10)]);
		$node = $this->node(11, '/bob/files/shared.txt', $this->node(100, '/bob/files'));
		$node->method('getOwner')->willReturn(new class {
			public function getUID(): string { return 'alice'; }
		});
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('getById')->with(11)
			->willReturn([$this->node(11, '/alice/files/docs/a.txt', $this->node(10, '/alice/files/docs'))]);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($folder);
		$this->assertSame([1], $this->service($sources, $root)->affectedSourceIds([$node]));
	}

	public function testWholeFolderDeleteCapturesContainedSourcesInTheirOwnOwnerContexts(): void {
		$source = $this->source(1, 11);
		$source->setOwnerUid('bob');
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findByNodeIds')->willReturn([]);
		$sources->method('findFileSources')->willReturn([$source]);
		$deleted = $this->createMock(Folder::class);
		$deleted->method('getId')->willReturn(10);
		$deleted->method('getPath')->willReturn('/alice/files/parent');
		$deleted->expects($this->never())->method('getDirectoryListing');
		$ownerFolder = $this->createMock(Folder::class);
		$ownerFolder->method('getById')->with(11)->willReturn([$this->node(11, '/bob/files/share/a.txt', $this->node(10, '/bob/files/share'))]);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getUserFolder')->with('bob')->willReturn($ownerFolder);
		$this->assertSame([1], $this->service($sources, $root)->affectedSourceIds([$deleted], true));
	}

	public function testDeletingOwnerFolderAlsoMatchesAChildSharedDirectlyToSourceOwner(): void {
		$source = $this->source(1, 11);
		$source->setOwnerUid('bob');
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findByNodeIds')->willReturn([]);
		$sources->method('findFileSources')->willReturn([$source]);
		$deleted = $this->createMock(Folder::class);
		$deleted->method('getId')->willReturn(10);
		$deleted->method('getPath')->willReturn('/alice/files/parent');
		$shared = $this->node(11, '/bob/files/shared.txt', $this->node(100, '/bob/files'));
		$shared->method('getOwner')->willReturn(new class { public function getUID(): string { return 'alice'; } });
		$bob = $this->createMock(Folder::class);
		$bob->method('getById')->with(11)->willReturn([$shared]);
		$alice = $this->createMock(Folder::class);
		$alice->method('getById')->with(11)->willReturn([$this->node(11, '/alice/files/parent/child.txt', $deleted)]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([['bob', $bob], ['alice', $alice]]);
		$this->assertSame([1], $this->service($sources, $root)->affectedSourceIds([$deleted], true));
	}

	/** @dataProvider metadataDirectoryRenames */
	public function testMetadataDirectoryRenamesCaptureDirectFileAndFolderDescendants(string $oldName, string $newName): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(10);
		$folder->method('getPath')->willReturn('/alice/files/' . $oldName);
		$folder->expects($this->never())->method('getDirectoryListing');
		$target = $this->node(0, '/alice/files/' . $newName);
		$fileSource = $this->source(1, 11);
		$fileSource->setOwnerUid('alice');
		$nestedSource = $this->folderSource(2, 12, 'alice');
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findByNodeIds')->willReturn([]);
		$sources->method('findFileSources')->willReturn([$fileSource, $nestedSource]);
		$owner = $this->createMock(Folder::class);
		$owner->method('getById')->willReturnMap([
			[10, [$folder]],
			[11, [$this->node(11, '/alice/files/' . $oldName . '/a.txt', $folder)]],
			[12, [$this->node(12, '/alice/files/' . $oldName . '/nested', $folder)]],
		]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($owner);
		$queued = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(static function ($class, $when, $args) use (&$queued): void { $queued[] = $args['sourceId']; });
		$listener = new RagFileEventListener($this->service($sources, $root, $jobs));
		$listener->handle(new BeforeNodeRenamedEvent($folder, $target));
		$this->assertSame([], $queued, 'Pre-events only capture; failed renames must not queue work');
		$listener->handle(new NodeRenamedEvent($folder, $target));
		$this->assertSame([1, 2], $queued);
	}

	public static function metadataDirectoryRenames(): array {
		return [['__MACOSX', 'documents'], ['documents', '__MACOSX']];
	}

	/** @dataProvider sharedWriteActors */
	public function testSharedWritesMatchOwnerViewsDespiteStorageParentIdOnLazyMountParent(bool $recipientWrites): void {
		$original = $this->node(11, '/alice/files/original/a.txt', $this->node(10, '/alice/files/original'));
		// NC35's shared-file LazyFolder parent has the recipient path but the
		// original storage parent's ID (10), not the recipient folder's ID (100).
		$shared = $this->node(11, '/bob/files/knowledge/shared.txt', $this->node(10, '/bob/files/knowledge'));
		foreach ([$original, $shared] as $node) {
			$node->method('getOwner')->willReturn(new class { public function getUID(): string { return 'alice'; } });
		}
		$fileSources = [
			$this->folderSource(1, 10, 'alice'), $this->folderSource(2, 100, 'bob'),
			$this->folderSource(3, 200, 'charlie'), $this->folderSource(4, 201, 'charlie'),
		];
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findByNodeIds')->willReturnCallback(static fn (array $ids): array => array_values(array_filter($fileSources, static fn (BotSource $source): bool => in_array($source->getNodeId(), $ids, true))));
		$sources->expects($this->once())->method('findFileSources')->willReturn($fileSources);
		$views = ['alice' => $original, 'bob' => $shared, 'charlie' => $this->node(11, '/charlie/files/knowledge/shared.txt', $this->node(200, '/charlie/files/knowledge'))];
		$folders = [];
		foreach ($views as $uid => $view) {
			$folders[$uid] = $this->createMock(Folder::class);
			$folderNodes = [];
			foreach ($fileSources as $source) {
				if ($source->getOwnerUid() === $uid) {
					$path = $source->getId() === 4 ? '/charlie/files/knowledge-extra' : dirname($view->getPath());
					$folderNodes[$source->getNodeId()] = $this->folder($source->getNodeId(), $path);
				}
			}
			$folders[$uid]->expects($this->exactly(1 + count($folderNodes)))->method('getById')->willReturnCallback(static fn (int $id): array => $id === 11 ? [$view] : [$folderNodes[$id]]);
			$folders[$uid]->expects($this->never())->method('getDirectoryListing');
		}
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->exactly(3))->method('getUserFolder')->willReturnCallback(static fn (string $uid): Folder => $folders[$uid]);
		$actual = $this->service($sources, $root)->affectedSourceIds([$recipientWrites ? $shared : $original]);
		sort($actual);
		$this->assertSame([1, 2, 3], $actual, 'An unrelated folder in an otherwise relevant owner context must not be indexed');
	}

	public static function sharedWriteActors(): array {
		return [[false], [true]];
	}

	/** @dataProvider disappearingSharedMountOperations */
	public function testOwnerDeleteAndRenameUseCapturedRecipientMountAncestry(string $operation): void {
		$visible = true;
		$old = $this->node(11, '/alice/files/shared.txt');
		$target = $this->node(0, '/alice/files/renamed.txt');
		$recipient = $this->node(11, '/bob/files/knowledge/shared.txt', $this->node(100, '/bob/files/knowledge'));
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findFileSources')->willReturn([$this->folderSource(9, 100, 'bob')]);
		$folder = $this->createMock(Folder::class);
		$sourceFolder = $this->folder(100, '/bob/files/knowledge');
		$folder->method('getById')->willReturnCallback(static function (int $id) use (&$visible, $recipient, $sourceFolder): array { return $id === 100 ? [$sourceFolder] : ($visible ? [$recipient] : []); });
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->exactly(2))->method('getUserFolder')->with('bob')->willReturn($folder);
		$queued = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(static function ($class, $when, $args) use (&$queued): void { $queued[] = $args['sourceId']; });
		$listener = new RagFileEventListener($this->service($sources, $root, $jobs));
		$listener->handle($operation === 'delete' ? new BeforeNodeDeletedEvent($old) : new BeforeNodeRenamedEvent($old, $target));
		$this->assertSame([], $queued);
		$visible = false;
		$listener->handle($operation === 'delete' ? new NodeDeletedEvent($old) : new NodeRenamedEvent($old, $target));
		$this->assertSame([9], $queued, 'Post-event lookup cannot find the old recipient mount; use the pre-event snapshot');
	}

	public static function disappearingSharedMountOperations(): array {
		return [['delete'], ['rename']];
	}

	/** @dataProvider disappearingSharedMountOperations */
	public function testOwnerParentOperationCapturesIndividuallySharedChildrenMountedInsideSource(string $operation): void {
		$deleted = $this->folder(10, '/alice/files/unshared-parent');
		$target = $this->node(0, '/alice/files/renamed-parent');
		$sourceFolder = $this->folder(100, '/bob/files/knowledge');
		$sourceFolder->expects($this->never())->method('getDirectoryListing');
		$unrelatedFolder = $this->folder(200, '/bob/files/unrelated');
		$shared = $this->node(11, '/bob/files/knowledge/shared.txt', $this->node(10, '/bob/files/knowledge'));
		$otherShared = $this->node(12, '/bob/files/knowledge/unrelated.txt');
		$metadataShared = $this->node(13, '/bob/files/knowledge/._ignored.txt');
		foreach ([$shared, $otherShared, $metadataShared] as $node) {
			$node->method('getOwner')->willReturn(new class { public function getUID(): string { return 'alice'; } });
		}
		$bob = $this->createMock(Folder::class);
		$bob->method('getById')->willReturnMap([[10, []], [100, [$sourceFolder]], [200, [$unrelatedFolder]], [11, [$shared]], [12, [$otherShared]], [13, [$metadataShared]]]);
		$alice = $this->createMock(Folder::class);
		$alice->method('getById')->willReturnMap([
			[11, [$this->node(11, '/alice/files/unshared-parent/child.txt', $deleted)]],
			[12, [$this->node(12, '/alice/files/other/child.txt', $this->node(20, '/alice/files/other'))]],
		]);
		$badMount = $this->createMock(IMountPoint::class);
		$badMount->method('getStorageRootId')->willThrowException(new \RuntimeException('broken mount'));
		$mounts = [$badMount];
		foreach ([12, 13, 11] as $id) {
			$mount = $this->createMock(IMountPoint::class);
			$mount->method('getStorageRootId')->willReturn($id);
			$mounts[] = $mount;
		}
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([['bob', $bob], ['alice', $alice]]);
		$root->expects($this->exactly(2))->method('getMountsIn')->willReturnMap([
			['/bob/files/knowledge', $mounts],
			// getById may expose several aliases; none lies inside this source.
			['/bob/files/unrelated', [$mounts[3]]],
		]);
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findFileSources')->willReturn([$this->folderSource(9, 100, 'bob'), $this->folderSource(10, 200, 'bob')]);
		$queued = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(static function ($class, $when, $args) use (&$queued): void { $queued[] = $args['sourceId']; });
		$listener = new RagFileEventListener($this->service($sources, $root, $jobs));
		$listener->handle($operation === 'delete' ? new BeforeNodeDeletedEvent($deleted) : new BeforeNodeRenamedEvent($deleted, $target));
		$this->assertSame([], $queued);
		$post = $this->node(0, $deleted->getPath());
		$listener->handle($operation === 'delete' ? new NodeDeletedEvent($post) : new NodeRenamedEvent($post, $target));
		$this->assertSame([9], $queued, 'One parent event must cover individually shared descendants without scanning the recursive file tree');
	}

	public function testRecipientMetadataPathsAndUnreachableViewsDoNotMatchKnowledgeFolders(): void {
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findFileSources')->willReturn([$this->folderSource(1, 100, 'bob'), $this->folderSource(2, 200, 'charlie')]);
		$bob = $this->createMock(Folder::class);
		$bob->method('getById')->with(11)->willReturn([$this->node(11, '/bob/files/knowledge/._shared.txt', $this->node(100, '/bob/files/knowledge'))]);
		$charlie = $this->createMock(Folder::class);
		$charlie->method('getById')->with(11)->willReturn([]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([['bob', $bob], ['charlie', $charlie]]);
		$this->assertSame([], $this->service($sources, $root)->affectedSourceIds([$this->node(11, '/alice/files/original.txt')]));
	}

	public function testResolutionFailuresAreIsolatedPerOwnerAndPerChangedNode(): void {
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findFileSources')->willReturn([
			$this->folderSource(1, 100, 'broken'), $this->folderSource(2, 200, 'bob'),
			$this->folderSource(3, 300, 'charlie'), $this->folderSource(4, 201, 'bob'),
		]);
		$bob = $this->createMock(Folder::class);
		$bob->expects($this->exactly(4))->method('getById')->willReturnCallback(function (int $id): array {
			if ($id === 11) { throw new \RuntimeException('private mount failure'); }
			if ($id === 200) { return [$this->folder(200, '/bob/files/knowledge')]; }
			if ($id === 201) { return [$this->folder(201, '/bob/files/knowledge-extra')]; }
			return [$this->node(12, '/bob/files/knowledge/b.txt', $this->node(200, '/bob/files/knowledge'))];
		});
		$charlie = $this->createMock(Folder::class);
		$charlie->method('getById')->willReturnMap([[11, [$this->node(11, '/charlie/files/knowledge/a.txt', $this->node(300, '/charlie/files/knowledge'))]], [12, []], [300, [$this->folder(300, '/charlie/files/knowledge')]]]);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->exactly(3))->method('getUserFolder')->willReturnCallback(static function (string $uid) use ($bob, $charlie): Folder {
			if ($uid === 'broken') { throw new \RuntimeException('private user folder failure'); }
			return $uid === 'bob' ? $bob : $charlie;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('debug')->with($this->anything(), ['exception_class' => \RuntimeException::class]);
		$service = new RagFileEventSyncService($sources, $root, $this->createMock(IJobList::class), $logger);
		$this->assertSame([2, 3], $service->affectedSourceIds([$this->node(11, '/alice/files/a.txt'), $this->node(12, '/alice/files/b.txt')]));
	}

	public function testBurstEventsKeepOneDelayedJobAndDoNotResetItsDeadline(): void {
		$jobs = $this->createMock(IJobList::class);
		$queued = [];
		// Capture by reference so later has() calls see jobs added by the first write.
		$jobs->method('has')->willReturnCallback(static function ($class, $args) use (&$queued): bool { return isset($queued[$args['sourceId']]); });
		$jobs->expects($this->exactly(2))->method('scheduleAfter')->willReturnCallback(static function ($class, $when, $args) use (&$queued): void {
			TestCase::assertSame(SyncRagSourceJob::class, $class);
			TestCase::assertGreaterThanOrEqual(time() + 4, $when);
			$queued[$args['sourceId']] = $when;
		});
		$service = $this->service($this->createMock(BotSourceMapper::class), null, $jobs);
		$service->schedule([1, 1, 2]);
		$service->schedule([1, 2]);
	}

	public function testQueueFailureDoesNotAbortSuccessfulFileOperationOrOtherSources(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturn(false);
		$jobs->expects($this->exactly(2))->method('scheduleAfter')->willReturnCallback(static function ($class, $when, $args): void {
			if ($args['sourceId'] === 1) { throw new \RuntimeException('database busy'); }
		});
		$this->service($this->createMock(BotSourceMapper::class), null, $jobs)->schedule([1, 2]);
	}

	private function source(int $id, int $nodeId): BotSource {
		$source = new BotSource();
		$source->setId($id);
		$source->setNodeId($nodeId);
		$source->setOwnerUid('alice');
		return $source;
	}

	private function folderSource(int $id, int $nodeId, string $ownerUid): BotSource {
		$source = $this->source($id, $nodeId);
		$source->setNodeType('folder');
		$source->setOwnerUid($ownerUid);
		return $source;
	}

	private function folder(int $id, string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn($path);
		return $folder;
	}

	private function node(int $id, string $path, ?Node $parent = null): Node {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($id);
		$node->method('getPath')->willReturn($path);
		$node->method('getParent')->willReturn($parent);
		return $node;
	}

	private function service(BotSourceMapper $sources, ?IRootFolder $root = null, ?IJobList $jobs = null): RagFileEventSyncService {
		return new RagFileEventSyncService($sources, $root ?? $this->createMock(IRootFolder::class), $jobs ?? $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class));
	}
}
