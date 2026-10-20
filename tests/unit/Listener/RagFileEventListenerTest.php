<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Listener;

use OCA\EducAI\Listener\RagFileEventListener;
use OCA\EducAI\Service\RagFileEventSyncService;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

class RagFileEventListenerTest extends TestCase {
	public function testSuccessfulDeleteUsesBeforeSnapshotWhenPostNodeHasNoIdentity(): void {
		$node = $this->node('/alice/files/deleted');
		$sync = $this->createMock(RagFileEventSyncService::class);
		$sync->expects($this->exactly(2))->method('affectedSourceIds')->willReturnOnConsecutiveCalls([4, 5], []);
		$sync->expects($this->once())->method('schedule')->with([4, 5]);
		$listener = new RagFileEventListener($sync);
		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle(new NodeDeletedEvent($node));
	}

	public function testAbortedDeletionDoesNotScheduleWork(): void {
		$sync = $this->createMock(RagFileEventSyncService::class);
		$sync->method('affectedSourceIds')->willReturn([4]);
		$sync->expects($this->never())->method('schedule');
		(new RagFileEventListener($sync))->handle(new BeforeNodeDeletedEvent($this->node('/alice/files/kept')));
	}

	public function testRenamePreservesOldAndNewAffectedSourcesIncludingMetadataRenames(): void {
		$old = $this->node('/alice/files/old/a.txt');
		$new = $this->node('/alice/files/new/._a.txt');
		$sync = $this->createMock(RagFileEventSyncService::class);
		$sync->expects($this->exactly(2))->method('affectedSourceIds')->with([$old, $new], $this->anything())->willReturnOnConsecutiveCalls([1, 2], [3]);
		$sync->expects($this->once())->method('schedule')->with([1, 2, 3]);
		$listener = new RagFileEventListener($sync);
		$listener->handle(new BeforeNodeRenamedEvent($old, $new));
		$listener->handle(new NodeRenamedEvent($old, $new));
	}

	public function testCopyOnlySchedulesDestination(): void {
		$old = $this->node('/alice/files/a.txt');
		$new = $this->node('/alice/files/target/a.txt');
		$sync = $this->createMock(RagFileEventSyncService::class);
		$sync->expects($this->once())->method('affectedSourceIds')->with([$new])->willReturn([3]);
		$sync->expects($this->once())->method('schedule')->with([3]);
		(new RagFileEventListener($sync))->handle(new NodeCopiedEvent($old, $new));
	}

	public function testCreateAndWriteUsePostEventsAndIgnoreNestedFolderInitialization(): void {
		$node = $this->node('/alice/files/new.txt');
		$sync = $this->createMock(RagFileEventSyncService::class);
		$listener = new RagFileEventListener($sync);
		$sync->expects($this->exactly(2))->method('affectedSourceIds')->willReturnCallback(static function () use ($listener, $node): array {
			$listener->handle(new NodeCreatedEvent($node));
			return [1];
		});
		$sync->expects($this->exactly(2))->method('schedule')->with([1]);
		$listener->handle(new NodeCreatedEvent($node));
		$listener->handle(new NodeWrittenEvent($node));
	}

	private function node(string $path): Node {
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn($path);
		return $node;
	}
}
