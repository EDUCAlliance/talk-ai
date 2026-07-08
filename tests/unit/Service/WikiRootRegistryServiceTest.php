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
