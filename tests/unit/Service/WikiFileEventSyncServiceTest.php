<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\WikiRoot;
use OCA\EducAI\Jobs\SyncWikiRootIndexJob;
use OCA\EducAI\Service\WikiFileEventSyncService;
use OCA\EducAI\Service\WikiRootRegistryService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WikiFileEventSyncServiceTest extends TestCase {
	public function testEventUnderPersonalWikiEnqueuesOneJob(): void {
		$root = $this->createRoot(7, 100);
		$registry = $this->createMock(WikiRootRegistryService::class);
		$registry->expects($this->once())
			->method('findRootsForNodeAncestors')
			->with($this->callback(static fn (array $ids): bool => in_array(100, $ids, true) && in_array(101, $ids, true)))
			->willReturn([$root]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())
			->method('has')
			->with(SyncWikiRootIndexJob::class, ['root_id' => 7])
			->willReturn(false);
		$jobList->expects($this->once())
			->method('scheduleAfter')
			->with(
				SyncWikiRootIndexJob::class,
				$this->callback(static fn ($timestamp): bool => is_int($timestamp) && $timestamp >= time()),
				['root_id' => 7]
			);

		$service = new WikiFileEventSyncService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->scheduleForChangedNodes([
			$this->createNode(101, 'manual.md', '/alice/files/' . Application::WIKI_ROOT_FOLDER . '/Personal Wikis/studybot/pages/manual.md', $this->createNode(100, 'studybot', '/alice/files/' . Application::WIKI_ROOT_FOLDER . '/Personal Wikis/studybot')),
		]);
	}

	public function testEventUnderCollectiveWikiEnqueuesByNodeIdWithoutPathFilter(): void {
		$root = $this->createRoot(9, 200);
		$registry = $this->createMock(WikiRootRegistryService::class);
		$registry->expects($this->once())
			->method('findRootsForNodeAncestors')
			->with($this->callback(static fn (array $ids): bool => in_array(200, $ids, true)))
			->willReturn([$root]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())
			->method('has')
			->with(SyncWikiRootIndexJob::class, ['root_id' => 9])
			->willReturn(false);
		$jobList->expects($this->once())
			->method('scheduleAfter')
			->with(SyncWikiRootIndexJob::class, $this->anything(), ['root_id' => 9]);

		$service = new WikiFileEventSyncService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->scheduleForChangedNodes([
			$this->createNode(201, 'manual.md', '/alice/files/Kollektive/Bot-TEST/pages/manual.md', $this->createNode(200, 'Bot-TEST', '/alice/files/Kollektive/Bot-TEST')),
		]);
	}

	public function testEventOutsideRegisteredRootsEnqueuesNothing(): void {
		$registry = $this->createMock(WikiRootRegistryService::class);
		$registry->expects($this->once())
			->method('findRootsForNodeAncestors')
			->willReturn([]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())
			->method('scheduleAfter');

		$service = new WikiFileEventSyncService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->scheduleForChangedNodes([$this->createNode(301, 'note.md', '/alice/files/notes/note.md')]);
	}

	public function testManagedRootFilesCreateNoJob(): void {
		$registry = $this->createMock(WikiRootRegistryService::class);
		$registry->expects($this->never())
			->method('findRootsForNodeAncestors');

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())
			->method('scheduleAfter');

		$service = new WikiFileEventSyncService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->scheduleForChangedNodes([
			$this->createNode(101, 'index.md', '/alice/files/' . Application::WIKI_ROOT_FOLDER . '/Personal Wikis/studybot/index.md'),
			$this->createNode(102, 'log.md', '/alice/files/' . Application::WIKI_ROOT_FOLDER . '/Personal Wikis/studybot/log.md'),
			$this->createNode(103, 'schema.md', '/alice/files/' . Application::WIKI_ROOT_FOLDER . '/Personal Wikis/studybot/schema.md'),
		]);
	}

	public function testDuplicateEventsForSameRootDoNotCreateJobSpam(): void {
		$root = $this->createRoot(7, 100);
		$registry = $this->createMock(WikiRootRegistryService::class);
		$registry->expects($this->once())
			->method('findRootsForNodeAncestors')
			->willReturn([$root, $root]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())
			->method('has')
			->with(SyncWikiRootIndexJob::class, ['root_id' => 7])
			->willReturn(true);
		$jobList->expects($this->never())
			->method('scheduleAfter');

		$service = new WikiFileEventSyncService($registry, $jobList, $this->createMock(LoggerInterface::class));
		$service->scheduleForChangedNodes([
			$this->createNode(101, 'a.md', '/alice/files/wiki/a.md'),
			$this->createNode(102, 'b.md', '/alice/files/wiki/b.md'),
		]);
	}

	private function createRoot(int $id, int $rootNodeId): WikiRoot {
		$root = new WikiRoot();
		$root->setId($id);
		$root->setRootNodeId($rootNodeId);
		$root->setActive(true);
		return $root;
	}

	private function createNode(int $id, string $name, string $path, ?Node $parent = null): Node {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		$node->method('getPath')->willReturn($path);
		$node->method('getParent')->willReturn($parent);
		return $node;
	}
}
