<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Jobs;

use OCA\EducAI\Db\WikiRoot;
use OCA\EducAI\Db\WikiRootMapper;
use OCA\EducAI\Jobs\SyncWikiRootIndexJob;
use OCA\EducAI\Service\WikiService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SyncWikiRootIndexJobTest extends TestCase {
	public function testRunSyncsRootAndMarksSuccess(): void {
		$root = $this->createRoot();
		$folder = $this->createMock(Folder::class);

		$rootMapper = $this->createMock(WikiRootMapper::class);
		$rootMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($root);
		$rootMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function (WikiRoot $updated): WikiRoot {
				$this->assertNotNull($updated->getLastSyncedAt());
				$this->assertNull($updated->getLastError());
				return $updated;
			});

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getById')
			->with(1234)
			->willReturn([$folder]);

		$wikiService = $this->createMock(WikiService::class);
		$wikiService->expects($this->once())
			->method('syncIndexForRoot')
			->with($folder)
			->willReturn(['count' => 2]);

		$job = new SyncWikiRootIndexJob(
			$this->createMock(ITimeFactory::class),
			$rootMapper,
			$rootFolder,
			$wikiService,
			$this->createMock(LoggerInterface::class)
		);

		$job->run(['root_id' => 7]);
	}

	public function testRunStoresFailureWhenRootFolderCannotBeLoaded(): void {
		$root = $this->createRoot();

		$rootMapper = $this->createMock(WikiRootMapper::class);
		$rootMapper->method('findById')->willReturn($root);
		$rootMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function (WikiRoot $updated): WikiRoot {
				$this->assertNotSame('', $updated->getLastError());
				$this->assertNull($updated->getLastSyncedAt());
				return $updated;
			});

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(1234)->willReturn([]);

		$wikiService = $this->createMock(WikiService::class);
		$wikiService->expects($this->never())
			->method('syncIndexForRoot');

		$job = new SyncWikiRootIndexJob(
			$this->createMock(ITimeFactory::class),
			$rootMapper,
			$rootFolder,
			$wikiService,
			$this->createMock(LoggerInterface::class)
		);

		$job->run(['root_id' => 7]);
	}

	private function createRoot(): WikiRoot {
		$root = new WikiRoot();
		$root->setId(7);
		$root->setRootNodeId(1234);
		$root->setRootPath('/alice/files/wiki');
		$root->setActive(true);
		return $root;
	}
}
