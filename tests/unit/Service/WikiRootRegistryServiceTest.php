<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotToolMapper;
use OCA\EducAI\Db\WikiRoot;
use OCA\EducAI\Db\WikiRootBot;
use OCA\EducAI\Db\WikiRootBotMapper;
use OCA\EducAI\Db\WikiRootMapper;
use OCA\EducAI\Service\WikiLocationService;
use OCA\EducAI\Service\WikiRootRegistryService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WikiRootRegistryServiceTest extends TestCase {
	public function testRefreshBotCreatesPersonalFilesRootAndAssignment(): void {
		$bot = $this->createBot(42, 'alice');
		$wikiRootFolder = $this->createMock(Folder::class);
		$wikiRootFolder->method('getId')->willReturn(1234);
		$wikiRootFolder->method('getPath')->willReturn('/alice/files/' . Application::WIKI_ROOT_FOLDER . '/Project Wikis/studybot');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('get')
			->with(Application::WIKI_ROOT_FOLDER . '/Project Wikis/studybot')
			->willReturn($wikiRootFolder);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		$rootMapper = $this->createMock(WikiRootMapper::class);
		$rootMapper->expects($this->once())
			->method('findOneByRootNodeId')
			->with(1234)
			->willReturn(null);
		$rootMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (WikiRoot $root): WikiRoot {
				$this->assertSame(1234, $root->getRootNodeId());
				$this->assertSame('/alice/files/' . Application::WIKI_ROOT_FOLDER . '/Project Wikis/studybot', $root->getRootPath());
				$this->assertSame('personal_files', $root->getLocation());
				$this->assertTrue($root->getActive());
				$root->setId(77);
				return $root;
			});

		$rootBotMapper = $this->createMock(WikiRootBotMapper::class);
		$rootBotMapper->expects($this->once())
			->method('findByBotId')
			->with(42)
			->willReturn(null);
		$rootBotMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (WikiRootBot $assignment): WikiRootBot {
				$this->assertSame(77, $assignment->getRootId());
				$this->assertSame(42, $assignment->getBotId());
				$this->assertTrue($assignment->getActive());
				$this->assertNotSame('', $assignment->getConfigHash());
				return $assignment;
			});

		$service = $this->createService($rootMapper, $rootBotMapper, $rootFolder);
		$service->refreshBot($bot, [
			'wiki_location' => 'personal_files',
			'wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Project Wikis/studybot',
		]);
	}

	public function testRefreshBotCreatesCollectiveRootAndAssignment(): void {
		$bot = $this->createBot(42, 'alice');
		$wikiRootFolder = $this->createMock(Folder::class);
		$wikiRootFolder->method('getId')->willReturn(9876);
		$wikiRootFolder->method('getPath')->willReturn('/alice/files/Kollektive/Bot-TEST');

		$locationService = $this->createMock(WikiLocationService::class);
		$locationService->expects($this->once())
			->method('resolveCollectiveWikiRoot')
			->with(99, 'alice')
			->willReturn(['folder' => $wikiRootFolder]);

		$rootMapper = $this->createMock(WikiRootMapper::class);
		$rootMapper->method('findOneByRootNodeId')->willReturn(null);
		$rootMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (WikiRoot $root): WikiRoot {
				$this->assertSame(9876, $root->getRootNodeId());
				$this->assertSame('collective', $root->getLocation());
				$this->assertSame(99, $root->getCollectiveId());
				$root->setId(88);
				return $root;
			});

		$rootBotMapper = $this->createMock(WikiRootBotMapper::class);
		$rootBotMapper->method('findByBotId')->willReturn(null);
		$rootBotMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (WikiRootBot $assignment): WikiRootBot {
				$this->assertSame(88, $assignment->getRootId());
				return $assignment;
			});

		$service = $this->createService($rootMapper, $rootBotMapper, $this->createMock(IRootFolder::class), $locationService);
		$service->refreshBot($bot, [
			'wiki_location' => 'collective',
			'wiki_collective_id' => 99,
		]);
	}

	public function testRefreshTeamBotCreatesCollectiveRootWhenTeamMatches(): void {
		$bot = $this->createBot(42, 'alice', 'teams');
		$bot->setAllowedTeams(json_encode(['team-a']) ?: '[]');
		$wikiRootFolder = $this->createMock(Folder::class);
		$wikiRootFolder->method('getId')->willReturn(9876);
		$wikiRootFolder->method('getPath')->willReturn('/alice/files/Kollektive/Bot-TEST');

		$locationService = $this->createMock(WikiLocationService::class);
		$locationService->expects($this->once())
			->method('collectiveMatchesAnyTeam')
			->with(99, 'alice', ['team-a'])
			->willReturn(true);
		$locationService->expects($this->once())
			->method('resolveCollectiveWikiRoot')
			->with(99, 'alice')
			->willReturn(['folder' => $wikiRootFolder]);

		$rootMapper = $this->createMock(WikiRootMapper::class);
		$rootMapper->method('findOneByRootNodeId')->willReturn(null);
		$rootMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (WikiRoot $root): WikiRoot {
				$this->assertSame('collective', $root->getLocation());
				$this->assertSame(99, $root->getCollectiveId());
				$root->setId(88);
				return $root;
			});

		$rootBotMapper = $this->createMock(WikiRootBotMapper::class);
		$rootBotMapper->method('findByBotId')->willReturn(null);
		$rootBotMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (WikiRootBot $assignment): WikiRootBot => $assignment);

		$service = $this->createService($rootMapper, $rootBotMapper, $this->createMock(IRootFolder::class), $locationService);
		$service->refreshBot($bot, [
			'wiki_location' => 'collective',
			'wiki_collective_id' => 99,
		]);
	}

	public function testRefreshTeamBotRejectsPersonalFilesWiki(): void {
		$bot = $this->createBot(42, 'alice', 'teams');
		$bot->setAllowedTeams(json_encode(['team-a']) ?: '[]');
		$rootBotMapper = $this->createMock(WikiRootBotMapper::class);

		$service = $this->createService($this->createMock(WikiRootMapper::class), $rootBotMapper, $this->createMock(IRootFolder::class));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Team bots can use LLM Wiki only with a collective');

		$service->refreshBot($bot, [
			'wiki_location' => 'personal_files',
			'wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Project Wikis/studybot',
		]);
	}

	public function testRefreshBotDeactivatesNonPersonalBot(): void {
		$bot = $this->createBot(42, 'alice', 'groups');
		$rootBotMapper = $this->createMock(WikiRootBotMapper::class);
		$rootBotMapper->expects($this->once())
			->method('deactivateByBotId')
			->with(42);

		$service = $this->createService($this->createMock(WikiRootMapper::class), $rootBotMapper, $this->createMock(IRootFolder::class));
		$service->refreshBot($bot, []);
	}

	public function testDisablingTheLastBotRetiresItsRootAndStopsFileEvents(): void {
		foreach ([false, true] as $anotherBotRemains) {
			$root = new WikiRoot();
			$root->setId(77);
			$root->setRootNodeId(1234);
			$assignment = new WikiRootBot();
			$assignment->setRootId(77);
			$assignment->setBotId(42);
			$rootBotMapper = $this->createMock(WikiRootBotMapper::class);
			$rootBotMapper->method('findByBotId')->with(42)->willReturn($assignment);
			$rootBotMapper->expects($this->once())->method('deactivateByBotId')->with(42);
			$rootMapper = $this->createMock(WikiRootMapper::class);
			$rootMapper->method('hasActiveAssignments')->with(77)->willReturn($anotherBotRemains);
			$rootMapper->method('findById')->with(77)->willReturn($root);
			$rootMapper->method('findActiveByRootNodeIds')->with([1234])->willReturn([$root]);
			$rootMapper->expects($anotherBotRemains ? $this->never() : $this->once())->method('update')->with($root);
			$service = $this->createService($rootMapper, $rootBotMapper, $this->createMock(IRootFolder::class));

			$service->deactivateBot(42);

			$this->assertSame($anotherBotRemains, $root->getActive());
			$this->assertSame($anotherBotRemains ? [$root] : [], $service->findRootsForNodeAncestors([1234]));
		}
	}

	public function testSwitchingWikiRootRetiresOnlyTheUnassignedOldRoot(): void {
		foreach ([false, true] as $anotherBotRemains) {
			$old = new WikiRoot();
			$old->setId(77);
			$new = new WikiRoot();
			$new->setId(88);
			$new->setRootNodeId(1234);
			$assignment = new WikiRootBot();
			$assignment->setId(5);
			$assignment->setBotId(42);
			$assignment->setRootId(77);
			$folder = $this->createMock(Folder::class);
			$folder->method('getId')->willReturn(1234);
			$folder->method('getPath')->willReturn('/alice/files/new-wiki');
			$userFolder = $this->createMock(Folder::class);
			$userFolder->method('get')->with(Application::WIKI_ROOT_FOLDER . '/new-wiki')->willReturn($folder);
			$rootFolder = $this->createMock(IRootFolder::class);
			$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
			$rootBotMapper = $this->createMock(WikiRootBotMapper::class);
			$rootBotMapper->method('findByBotId')->with(42)->willReturn($assignment);
			$rootBotMapper->expects($this->once())->method('update')->willReturnCallback(function (WikiRootBot $updated): WikiRootBot {
				$this->assertSame(88, $updated->getRootId());
				$this->assertTrue($updated->getActive());
				return $updated;
			});
			$rootMapper = $this->createMock(WikiRootMapper::class);
			$rootMapper->method('findOneByRootNodeId')->with(1234)->willReturn($new);
			$rootMapper->method('hasActiveAssignments')->with(77)->willReturn($anotherBotRemains);
			$rootMapper->method('findById')->with(77)->willReturn($old);
			$rootMapper->expects($this->exactly($anotherBotRemains ? 1 : 2))->method('update')->willReturnArgument(0);
			$service = $this->createService($rootMapper, $rootBotMapper, $rootFolder);

			$service->refreshBot($this->createBot(42, 'alice'), ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/new-wiki']);

			$this->assertSame($anotherBotRemains, $old->getActive());
			$this->assertTrue($new->getActive());
		}
	}

	public function testFileEventLookupIgnoresOrphanRootsEvenBeforeRetirement(): void {
		$root = new WikiRoot();
		$root->setId(77);
		$rootMapper = $this->createMock(WikiRootMapper::class);
		$rootMapper->method('findActiveByRootNodeIds')->with([1234])->willReturn([$root]);
		$rootMapper->expects($this->once())->method('hasActiveAssignments')->with(77)->willReturn(false);
		$service = $this->createService($rootMapper, $this->createMock(WikiRootBotMapper::class), $this->createMock(IRootFolder::class));

		$this->assertSame([], $service->findRootsForNodeAncestors([1234]));
		$this->assertTrue($root->getActive(), 'The lookup must also stop maintenance for stale active flags');
	}

	public function testRegistryRebuildRetiresPreviouslyOrphanedRootsAndDisabledBots(): void {
		$assignment = new WikiRootBot();
		$assignment->setBotId(42);
		$assignment->setRootId(77);
		$root = new WikiRoot();
		$root->setId(77);
		$rootBotMapper = $this->createMock(WikiRootBotMapper::class);
		$rootBotMapper->method('findAllActive')->willReturn([$assignment]);
		$rootBotMapper->method('findByBotId')->with(42)->willReturn($assignment);
		$rootBotMapper->expects($this->once())->method('deactivateByBotId')->with(42);
		$rootMapper = $this->createMock(WikiRootMapper::class);
		$rootMapper->method('hasActiveAssignments')->with(77)->willReturn(false);
		$rootMapper->method('findById')->with(77)->willReturn($root);
		$rootMapper->method('findAllActive')->willReturn([]);
		$rootMapper->expects($this->once())->method('update')->with($root);
		$service = $this->createService($rootMapper, $rootBotMapper, $this->createMock(IRootFolder::class));

		$this->assertSame(['refreshed' => 0, 'failed' => 0], $service->rebuildAll());
		$this->assertFalse($root->getActive());
	}

	private function createService(
		WikiRootMapper $rootMapper,
		WikiRootBotMapper $rootBotMapper,
		IRootFolder $rootFolder,
		?WikiLocationService $locationService = null,
	): WikiRootRegistryService {
		return new WikiRootRegistryService(
			$this->createMock(BotMapper::class),
			$this->createMock(BotToolMapper::class),
			$rootMapper,
			$rootBotMapper,
			$rootFolder,
			$locationService ?? $this->createMock(WikiLocationService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function createBot(int $id, string $owner, string $visibility = 'personal'): Bot {
		$bot = new Bot();
		$bot->setId($id);
		$bot->setUserId($owner);
		$bot->setBotName('studybot');
		$bot->setMentionName('@studybot');
		$bot->setVisibility($visibility);
		return $bot;
	}
}
