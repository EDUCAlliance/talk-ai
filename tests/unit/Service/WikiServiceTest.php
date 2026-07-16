<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Service\TextSessionResetService;
use OCA\EducAI\Service\WikiLocationService;
use OCA\EducAI\Service\WikiService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WikiServiceTest extends TestCase {
	public function testPersonalBotWritesPageDirectly(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);

		$result = $service->writePage(42, 'pages/topics/rag.md', "# RAG\n\nDurable notes", 'create', 'test');

		$this->assertTrue($result['success']);
		$this->assertSame('written', $result['action']);
		$this->assertSame(Application::WIKI_ROOT_FOLDER . '/Personal Wikis/studybot', $result['wiki_root']);
		$read = $service->readPage(42, 'pages/topics/rag.md');
		$this->assertSame("# RAG\n\nDurable notes", $read['content']);
	}

	public function testPersonalBotCanUseCustomWikiRootPath(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);

		$result = $service->writePage(
			42,
			'pages/topics/custom.md',
			"# Custom\n",
			'create',
			null,
			['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study']
		);

		$this->assertSame(Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study', $result['wiki_root']);
		$read = $service->readPage(42, 'pages/topics/custom.md', ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study']);
		$this->assertSame("# Custom\n", $read['content']);
	}

	public function testPersonalBotCanUseCollectiveWikiLocation(): void {
		$root = new InMemoryRootFolder();
		$collectiveRoot = new InMemoryFolder('collective');
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$locationService = $this->createMock(WikiLocationService::class);
		$locationService->expects($this->exactly(2))
			->method('resolveCollectiveWikiRoot')
			->with(99, 'alice')
			->willReturn([
				'folder' => $collectiveRoot,
				'label' => 'Collective: Shark Notes',
			]);
		$service = $this->createService($root, $bot, $locationService);

		$result = $service->writePage(
			42,
			'pages/friends/hajdi.md',
			"# Hajdi\n",
			'create',
			null,
			['wiki_location' => 'collective', 'wiki_collective_id' => 99]
		);

		$this->assertSame('Collective #99', $result['wiki_root']);
		$read = $service->readPage(42, 'pages/friends/hajdi.md', ['wiki_location' => 'collective', 'wiki_collective_id' => 99]);
		$this->assertSame("# Hajdi\n", $read['content']);
	}

	public function testInitializeCollectiveWikiIndexesExistingFiles(): void {
		$root = new InMemoryRootFolder();
		$collectiveRoot = new InMemoryFolder('collective');
		$existing = $collectiveRoot->newFile('existing-topic.md');
		$existing->putContent("# Existing Topic\n\nAlready here.");
		$pages = $collectiveRoot->newFolder('pages');
		$nested = $pages->newFile('nested-note.md');
		$nested->putContent("# Nested Note\n\nAlready nested.");

		$bot = $this->createBot('alice', '@studybot', 'personal');
		$locationService = $this->createMock(WikiLocationService::class);
		$locationService->expects($this->once())
			->method('resolveCollectiveWikiRoot')
			->with(99, 'alice')
			->willReturn([
				'folder' => $collectiveRoot,
				'label' => 'Collective: Shark Notes',
			]);
		$service = $this->createService($root, $bot, $locationService);

		$result = $service->initializeWiki(42, ['wiki_location' => 'collective', 'wiki_collective_id' => 99]);

		$this->assertSame('initialized', $result['action']);
		$this->assertSame(2, $result['indexed_files']);
		$index = $collectiveRoot->get('index.md');
		$this->assertInstanceOf(File::class, $index);
		$content = (string)$index->getContent();
		$this->assertStringNotContainsString('EDUC-AI-WIKI-FILE-INDEX', $content);
		$this->assertStringContainsString('## Existing Files', $content);
		$this->assertStringContainsString('[Existing Topic](existing-topic.md) - `existing-topic.md`', $content);
		$this->assertStringContainsString('[Nested Note](pages/nested-note.md) - `pages/nested-note.md`', $content);
		$this->assertStringNotContainsString('schema.md', $content);
		$this->assertStringNotContainsString('log.md', $content);
	}

	public function testInitializeWikiRemovesLegacyVisibleIndexMarkers(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$userRoot = $root->getUserFolder('alice');
		$wikiRoot = $userRoot->newFolder(Application::APP_DISPLAY_NAME);
		$personalWikis = $wikiRoot->newFolder('Personal Wikis');
		$studybot = $personalWikis->newFolder('studybot');
		$index = $studybot->newFile('index.md');
		$index->putContent("# Index\n\n## Pages\n\n<!-- EDUC-AI-WIKI-FILE-INDEX:START -->\n## Existing Files\n\nOld visible block.\n<!-- EDUC-AI-WIKI-FILE-INDEX:END -->\n");
		$page = $studybot->newFile('notes.md');
		$page->putContent("# Notes\n\nExisting note.");
		$service = $this->createService($root, $bot);

		$service->initializeWiki(42);

		$content = (string)$index->getContent();
		$this->assertStringNotContainsString('EDUC-AI-WIKI-FILE-INDEX', $content);
		$this->assertStringNotContainsString('Old visible block', $content);
		$this->assertStringContainsString('[Notes](notes.md) - `notes.md`', $content);
	}

	public function testOverwriteExistingPageResetsTextSession(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$textSessionResetService = $this->getMockBuilder(TextSessionResetService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resetFileSession'])
			->getMock();
		$textSessionResetService->expects($this->atLeastOnce())
			->method('resetFileSession')
			->with($this->isInstanceOf(File::class));
		$service = $this->createService($root, $bot, null, $textSessionResetService);

		$service->writePage(42, 'pages/topics/rag.md', "# RAG\n", 'create');
		$service->writePage(42, 'pages/topics/rag.md', "# Updated RAG\n", 'overwrite');

		$read = $service->readPage(42, 'pages/topics/rag.md');
		$this->assertSame("# Updated RAG\n", $read['content']);
	}

	public function testReadPageReturnsDefaultPaginationMetadata(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);
		$content = str_repeat('a', 3600);

		$service->writePage(42, 'pages/long.md', $content, 'create');
		$read = $service->readPage(42, 'pages/long.md');

		$this->assertSame(0, $read['offset']);
		$this->assertSame(3000, $read['limit']);
		$this->assertSame(3000, $read['returned_length']);
		$this->assertSame(3600, $read['total_length']);
		$this->assertTrue($read['has_more']);
		$this->assertSame(3000, $read['next_offset']);
		$this->assertSame(str_repeat('a', 3000), $read['content']);
	}

	public function testReadPageSupportsOffsetAndLimit(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);

		$service->writePage(42, 'pages/alphabet.md', 'abcdefghijklmnopqrstuvwxyz', 'create');
		$read = $service->readPage(42, 'pages/alphabet.md', 5, 10);

		$this->assertSame('fghijklmno', $read['content']);
		$this->assertSame(5, $read['offset']);
		$this->assertSame(10, $read['limit']);
		$this->assertSame(10, $read['returned_length']);
		$this->assertSame(26, $read['total_length']);
		$this->assertTrue($read['has_more']);
		$this->assertSame(15, $read['next_offset']);
	}

	public function testReadPageLastChunkHasNoNextOffset(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);

		$service->writePage(42, 'pages/alphabet.md', 'abcdefghijklmnopqrstuvwxyz', 'create');
		$read = $service->readPage(42, 'pages/alphabet.md', 20, 20);

		$this->assertSame('uvwxyz', $read['content']);
		$this->assertSame(6, $read['returned_length']);
		$this->assertFalse($read['has_more']);
		$this->assertNull($read['next_offset']);
	}

	public function testReadPageCapsLimitAndNormalizesNegativeValues(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);

		$service->writePage(42, 'pages/long.md', str_repeat('b', 4000), 'create');
		$capped = $service->readPage(42, 'pages/long.md', 0, 999999);
		$normalized = $service->readPage(42, 'pages/long.md', -50, -10);

		$this->assertSame(3500, $capped['limit']);
		$this->assertSame(3500, $capped['returned_length']);
		$this->assertSame(0, $normalized['offset']);
		$this->assertSame(1, $normalized['limit']);
		$this->assertSame(1, $normalized['returned_length']);
	}

	public function testReadPageUsesUtf8CharacterOffsets(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);

		$service->writePage(42, 'pages/umlauts.md', 'äöüabcdef', 'create');
		$read = $service->readPage(42, 'pages/umlauts.md', 1, 4);

		$this->assertSame('öüab', $read['content']);
		$this->assertSame(9, $read['total_length']);
		$this->assertSame(4, $read['returned_length']);
		$this->assertSame(5, $read['next_offset']);
	}

	public function testLogEventResetsExistingLogTextSession(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$textSessionResetService = $this->getMockBuilder(TextSessionResetService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resetFileSession'])
			->getMock();
		$textSessionResetService->expects($this->atLeastOnce())
			->method('resetFileSession')
			->with($this->isInstanceOf(File::class));
		$service = $this->createService($root, $bot, null, $textSessionResetService);

		$service->logEvent(42, 'Updated wiki', '- Bot wrote new durable notes.');

		$read = $service->readPage(42, 'log.md');
		$this->assertStringContainsString('Updated wiki', $read['content']);
	}

	public function testRejectsInvalidCustomWikiRootPath(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('must start with ' . Application::WIKI_ROOT_FOLDER . '/');

		$service->writePage(42, 'pages/topics/rag.md', '# RAG', 'create', null, ['wiki_root_path' => 'Private Wikis/studybot']);
	}

	public function testSharedBotCannotUseWikiService(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('owner', '@projectbot', 'groups');
		$service = $this->createService($root, $bot);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('only available for personal bots or matching team collective bots');

		$service->writePage(42, 'pages/projects/educ-ai.md', '# EDUC AI', 'overwrite', 'shared update');
	}

	public function testTeamBotCanUseMatchingCollectiveWikiLocation(): void {
		$root = new InMemoryRootFolder();
		$collectiveRoot = new InMemoryFolder('collective');
		$bot = $this->createBot('alice', '@teambot', 'teams');
		$bot->setAllowedTeams(json_encode(['team-a']) ?: '[]');
		$locationService = $this->createMock(WikiLocationService::class);
		$locationService->expects($this->once())
			->method('collectiveMatchesAnyTeam')
			->with(99, 'alice', ['team-a'])
			->willReturn(true);
		$locationService->expects($this->once())
			->method('resolveCollectiveWikiRoot')
			->with(99, 'alice')
			->willReturn([
				'folder' => $collectiveRoot,
				'label' => 'Collective: Team Wiki',
			]);
		$service = $this->createService($root, $bot, $locationService);

		$result = $service->writePage(
			42,
			'pages/team-note.md',
			"# Team\n",
			'create',
			null,
			['wiki_location' => 'collective', 'wiki_collective_id' => 99]
		);

		$this->assertSame('Collective #99', $result['wiki_root']);
	}

	public function testTeamBotRejectsPersonalFilesWikiLocation(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@teambot', 'teams');
		$bot->setAllowedTeams(json_encode(['team-a']) ?: '[]');
		$service = $this->createService($root, $bot);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Team bots can use LLM Wiki only with a collective');

		$service->writePage(42, 'pages/team-note.md', "# Team\n", 'create', null, ['wiki_location' => 'personal_files']);
	}

	public function testTeamBotRejectsCollectiveOutsideSelectedTeams(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@teambot', 'teams');
		$bot->setAllowedTeams(json_encode(['team-a']) ?: '[]');
		$locationService = $this->createMock(WikiLocationService::class);
		$locationService->expects($this->once())
			->method('collectiveMatchesAnyTeam')
			->with(99, 'alice', ['team-a'])
			->willReturn(false);
		$locationService->expects($this->never())
			->method('resolveCollectiveWikiRoot');
		$service = $this->createService($root, $bot, $locationService);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Team bots can use only collectives from one of their selected teams');

		$service->writePage(42, 'pages/team-note.md', "# Team\n", 'create', null, ['wiki_location' => 'collective', 'wiki_collective_id' => 99]);
	}

	public function testRejectsParentDirectorySegments(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$service = $this->createService($root, $bot);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('parent segments');

		$service->writePage(42, '../secrets.md', '# nope');
	}

	private function createService(
		InMemoryRootFolder $root,
		Bot $bot,
		?WikiLocationService $wikiLocationService = null,
		?TextSessionResetService $textSessionResetService = null
	): WikiService {
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findById')->with(42)->willReturn($bot);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')
			->willReturnCallback([$root, 'getUserFolder']);

		return new WikiService(
			$rootFolder,
			$botMapper,
			$this->createMock(LoggerInterface::class),
			$wikiLocationService,
			$textSessionResetService
		);
	}

	private function createBot(string $owner, string $mention, string $visibility): Bot {
		$bot = new Bot();
		$bot->setId(42);
		$bot->setUserId($owner);
		$bot->setBotName(ltrim($mention, '@'));
		$bot->setMentionName($mention);
		$bot->setVisibility($visibility);
		return $bot;
	}
}

class InMemoryRootFolder {
	/** @var array<string,InMemoryFolder> */
	private array $folders = [];

	public function getById(int $id): array {
		return [];
	}

	public function getUserFolder(string $userId): Folder {
		if (!isset($this->folders[$userId])) {
			$this->folders[$userId] = new InMemoryFolder('');
		}
		return $this->folders[$userId];
	}
}

class InMemoryFolder implements Folder {
	private string $name;
	/** @var array<string,File|Folder> */
	private array $children = [];

	public function __construct(string $name) {
		$this->name = $name;
	}

	public function get(string $path) {
		$parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
		$current = $this;
		foreach ($parts as $index => $part) {
			if (!isset($current->children[$part])) {
				throw new NotFoundException($path);
			}
			$node = $current->children[$part];
			if ($index === count($parts) - 1) {
				return $node;
			}
			if (!$node instanceof self) {
				throw new NotFoundException($path);
			}
			$current = $node;
		}
		return $this;
	}

	public function newFolder(string $path): Folder {
		$folder = new self($path);
		$this->children[$path] = $folder;
		return $folder;
	}

	public function newFile(string $path): File {
		$file = new InMemoryFile($path);
		$this->children[$path] = $file;
		return $file;
	}

	public function getDirectoryListing() {
		return array_values($this->children);
	}

	public function getName() {
		return $this->name;
	}

	public function getPath() {
		return '/' . trim($this->name, '/');
	}

	public function getId() {
		return crc32('folder:' . $this->getPath());
	}

	public function getById($id) {
		$matches = [];
		foreach ($this->children as $child) {
			if ($child->getId() === $id) {
				$matches[] = $child;
			}
			if ($child instanceof Folder) {
				array_push($matches, ...$child->getById($id));
			}
		}
		return $matches;
	}

	public function getParent() {
		return null;
	}
}

class InMemoryFile implements File {
	private string $name;
	private string $content = '';

	public function __construct(string $name) {
		$this->name = $name;
	}

	public function getContent() {
		return $this->content;
	}

	public function putContent($data) {
		$this->content = (string)$data;
	}

	public function getId() {
		return crc32($this->name);
	}

	public function getSize() {
		return strlen($this->content);
	}

	public function getMimeType() {
		return 'text/markdown';
	}

	public function getName() {
		return $this->name;
	}

	public function getPath() {
		return '/' . trim($this->name, '/');
	}

	public function getParent() {
		return null;
	}
}
