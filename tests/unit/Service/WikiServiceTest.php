<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Exception\WikiException;
use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\BrandingService;
use OCA\EducAI\Service\TextSessionResetService;
use OCA\EducAI\Service\WikiLocationService;
use OCA\EducAI\Service\WikiService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WikiServiceTest extends TestCase {
	public function testDisplayNameChangeKeepsTheInternalWikiRootAndExistingContent(): void {
		$root = new InMemoryRootFolder();
		$bot = $this->createBot('alice', '@studybot', 'personal');
		$values = ['display_name' => 'EDUC AI', 'wiki_root_folder' => 'EDUC AI'];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default) use (&$values): string {
			return $values[$key] ?? $default;
		});
		$config->method('setAppValue')->willReturnCallback(static function (string $app, string $key, string $value) use (&$values): void {
			$values[$key] = $value;
		});
		$branding = new BrandingService($config);
		$service = $this->createService($root, $bot, null, null, $branding);
		$service->writePage(42, 'pages/existing.md', 'Preserve me');
		$branding->setDisplayName('Campus Assistant');
		$this->assertSame('Campus Assistant', $branding->getDisplayName());
		$this->assertSame('Preserve me', $service->readPage(42, 'pages/existing.md')['content']);
		$this->assertSame('EDUC AI/Personal Wikis/studybot', $service->readPage(42, 'pages/existing.md')['wiki_root']);
	}

	public function testOlderPrivateDefaultFolderIsReusedWithoutCreatingPublicDuplicate(): void {
		$root = new InMemoryRootFolder();
		$existing = $root->getUserFolder('alice')->newFolder('EDUC AI')->newFolder('Personal Wikis')->newFolder('studybot');
		$existing->newFile('existing.md')->putContent('Legacy knowledge');
		$service = $this->createService($root, $this->createBot('alice', '@studybot', 'personal'));
		$this->assertSame('Legacy knowledge', $service->readPage(42, 'existing.md')['content']);
		$this->assertSame('EDUC AI/Personal Wikis/studybot', $service->initializeWiki(42)['wiki_root']);
		$this->expectException(NotFoundException::class);
		$root->getUserFolder('alice')->get('Talk AI');
	}

	public function testWhenBothLegacyDefaultsExistConfiguredRootWins(): void {
		$root = new InMemoryRootFolder();
		foreach (['Talk AI', 'EDUC AI'] as $prefix) {
			$existing = $root->getUserFolder('alice')->newFolder($prefix)->newFolder('Personal Wikis')->newFolder('studybot');
			$existing->newFile('existing.md')->putContent($prefix);
		}
		$service = $this->createService($root, $this->createBot('alice', '@studybot', 'personal'));
		$this->assertSame('Talk AI', $service->readPage(42, 'existing.md')['content']);
		$this->assertSame('EDUC AI', $service->readPage(42, 'existing.md', ['wiki_root_path' => 'EDUC AI/Personal Wikis/studybot'])['content']);
	}

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
		// Links carry the page ID: Collectives opens pages by ID, a plain "page.md" link ends on "page not found".
		$this->assertMatchesRegularExpression('/\[Existing Topic\]\(existing-topic\.md\?fileId=\d+\) - `existing-topic\.md`/', $content);
		$this->assertMatchesRegularExpression('/\[Nested Note\]\(pages\/nested-note\.md\?fileId=\d+\) - `pages\/nested-note\.md`/', $content);
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
		$this->assertMatchesRegularExpression('/\[Notes\]\(notes\.md\?fileId=\d+\) - `notes\.md`/', $content);
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

	#[DataProvider('escapedWikiPages')]
	public function testAgentCanReconstructWikiUsingOnlyDeliveredPaginationOffsets(string $content): void {
		$service = $this->createService(new InMemoryRootFolder(), $this->createBot('alice', '@studybot', 'personal'));
		$service->writePage(42, 'pages/escaped.md', $content, 'create');
		$reflection = new \ReflectionClass(AgentExecutor::class);
		$executor = $reflection->newInstanceWithoutConstructor();
		$sanitize = $reflection->getMethod('sanitizeOutput');
		$offset = 0;
		$reconstructed = '';
		$pages = 0;
		do {
			$this->assertLessThan(50, ++$pages, 'Pagination must make progress');
			$page = $service->readPage(42, 'pages/escaped.md', $offset);
			// The actual built-in provider wraps this JSON as an MCP text block.
			$text = json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
			$output = $sanitize->invoke($executor, ['content' => [['type' => 'text', 'text' => $text]]], true);
			$this->assertLessThanOrEqual(4000, mb_strlen($output, 'UTF-8'));
			$delivered = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
			$length = mb_strlen($delivered['content'], 'UTF-8');
			$this->assertSame($length, $delivered['returned_length']);
			$this->assertSame($offset, $delivered['offset']);
			$this->assertSame(mb_substr($content, $offset, $length, 'UTF-8'), $delivered['content']);
			$reconstructed .= $delivered['content'];
			if ($delivered['has_more']) {
				$this->assertGreaterThan(0, $length);
				$this->assertSame($offset + $length, $delivered['next_offset']);
				$offset = $delivered['next_offset'];
			} else {
				$this->assertNull($delivered['next_offset']);
			}
		} while ($delivered['has_more']);
		$this->assertSame($content, $reconstructed);
	}

	/** @return array<string,array{string}> */
	public static function escapedWikiPages(): array {
		return [
			'quotes' => [str_repeat('"', 7000)],
			'backslashes' => [str_repeat('\\', 7000)],
			'control characters' => [str_repeat("a\x01\n\t", 2000)],
			'Unicode and escapes' => [str_repeat("🐈ä\"\\", 2000)],
			'ordinary ASCII' => [str_repeat('abc', 2500)],
		];
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

	public function testCollectiveSubpageTurnsTheParentIntoAFolderPageAndKeepsItsFileId(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$topic = $collectiveRoot->newFile('topic.md');
		$topic->putContent("# Topic\n\nOverview.");
		$topicId = $topic->getId();
		$service = $this->createCollectiveService($collectiveRoot);

		$result = $service->writePage(42, 'topic/sub.md', "# Sub\n", 'create', null, self::COLLECTIVE);

		$this->assertTrue($result['success']);
		$this->assertTrue($result['verified']);
		$this->assertSame('topic/sub.md', $result['path']);
		$this->assertSame('topic/Readme.md', $result['parent_page']);
		$this->assertSame([['from' => 'topic.md', 'to' => 'topic/Readme.md']], $result['converted_to_parent_pages']);
		$parent = $collectiveRoot->get('topic/Readme.md');
		$this->assertSame($topicId, $parent->getId());
		$this->assertSame("# Topic\n\nOverview.", $parent->getContent());
		$this->assertNull($this->nodeOrNull($collectiveRoot, 'topic.md'));

		$read = $service->readPage(42, 'topic.md', self::COLLECTIVE);
		$this->assertSame('topic/Readme.md', $read['resolved_path']);
		$this->assertSame("# Topic\n\nOverview.", $read['content']);
	}

	public function testWritingAParentPageWhoseSubpagesExistTargetsItsReadme(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFolder('topic')->newFile('sub.md')->putContent("# Sub\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$result = $service->writePage(42, 'topic.md', "# Topic\n", 'create', null, self::COLLECTIVE);

		$this->assertSame('topic/Readme.md', $result['resolved_path']);
		$this->assertNull($result['parent_page']);
		$this->assertNull($this->nodeOrNull($collectiveRoot, 'topic.md'));
		$this->assertSame("# Topic\n", $collectiveRoot->get('topic/Readme.md')->getContent());
	}

	public function testDuplicateParentPagesAreReportedInsteadOfMerged(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFile('topic.md')->putContent("# Topic (old)\n");
		$collectiveRoot->newFolder('topic')->newFile('Readme.md')->putContent("# Topic\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$result = $service->writePage(42, 'topic/sub.md', "# Sub\n", 'create', null, self::COLLECTIVE);

		$this->assertArrayNotHasKey('converted_to_parent_pages', $result);
		$this->assertStringContainsString('Both topic.md and topic/Readme.md exist', implode("\n", $result['warnings']));
		$this->assertSame("# Topic (old)\n", $collectiveRoot->get('topic.md')->getContent());
	}

	public function testMovedParentPageKeepsItsRelativeLinkTargets(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFile('target.md')->putContent("# Target\n");
		$collectiveRoot->newFile('topic.md')->putContent("# Topic\n\nSee [Target](target.md), [Web](https://example.com) and ![Logo](logo.png).\n\n```\n[Code](target.md)\n```\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$result = $service->writePage(42, 'topic/sub.md', "# Sub\n", 'create', null, self::COLLECTIVE);

		$this->assertSame(
			"# Topic\n\nSee [Target](target.md?fileId=" . $collectiveRoot->get('target.md')->getId() . "), [Web](https://example.com) and ![Logo](../logo.png).\n\n```\n[Code](target.md)\n```\n",
			$collectiveRoot->get('topic/Readme.md')->getContent()
		);
		$this->assertSame([['from' => 'topic.md', 'to' => 'topic/Readme.md', 'links_rebased' => 2]], $result['converted_to_parent_pages']);
		$this->assertArrayNotHasKey('warnings', $result);
	}

	public function testRejectedCreateLeavesExistingParentPagesUntouched(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFile('topic.md')->putContent("# Topic\n");
		$collectiveRoot->newFolder('topic')->newFile('sub.md')->putContent("# Sub\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$error = $this->wikiError(fn () => $service->writePage(42, 'topic/sub.md', "# Sub 2\n", 'create', null, self::COLLECTIVE));

		$this->assertSame(WikiException::ALREADY_EXISTS, $error->getErrorCode());
		$this->assertSame("# Topic\n", $collectiveRoot->get('topic.md')->getContent());
		$this->assertNull($this->nodeOrNull($collectiveRoot, 'topic/Readme.md'));
	}

	public function testCreatingTheFolderPageOfAPageThatStillHasTheOldLayoutIsAConflict(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFile('topic.md')->putContent("# Topic\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$error = $this->wikiError(fn () => $service->writePage(42, 'topic/Readme.md', "# New\n", 'create', null, self::COLLECTIVE));

		$this->assertSame(WikiException::ALREADY_EXISTS, $error->getErrorCode());
		$this->assertSame("# Topic\n", $collectiveRoot->get('topic.md')->getContent());
	}

	public function testTheCollectiveLandingPageIsNeverMovedIntoAFolder(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFile('Readme.md')->putContent("# Welcome\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$error = $this->wikiError(fn () => $service->writePage(42, 'Readme/child.md', "# Child\n", 'create', null, self::COLLECTIVE));

		$this->assertSame(WikiException::INVALID_PATH, $error->getErrorCode());
		$this->assertSame("# Welcome\n", $collectiveRoot->get('Readme.md')->getContent());
		$this->assertNull($this->nodeOrNull($collectiveRoot, 'Readme'));
	}

	public function testAPageCalledReadmeInCapitalsIsAnOrdinaryPage(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFile('README.md')->putContent("# Readme page\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$service->writePage(42, 'README/child.md', "# Child\n", 'create', null, self::COLLECTIVE);
		$read = $service->readPage(42, 'README.md', self::COLLECTIVE);
		$write = $service->writePage(42, 'README.md', "# Readme page v2\n", 'overwrite', null, self::COLLECTIVE);

		$this->assertSame('README/Readme.md', $read['resolved_path']);
		$this->assertSame('README/Readme.md', $write['resolved_path']);
		$this->assertNull($this->nodeOrNull($collectiveRoot, 'README.md'));
		$this->assertSame("# Readme page v2\n", $collectiveRoot->get('README/Readme.md')->getContent());
	}

	public function testFolderPagesReportTheFolderAboveAsTheirParent(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($collectiveRoot);

		$top = $service->writePage(42, 'topic/Readme.md', "# Topic\n", 'create', null, self::COLLECTIVE);
		$nested = $service->writePage(42, 'topic/sub/Readme.md', "# Sub\n", 'create', null, self::COLLECTIVE);

		$this->assertNull($top['parent_page']);
		$this->assertSame('topic/Readme.md', $nested['parent_page']);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function nearDuplicates(): array {
		return [
			'hyphen instead of space' => ['General-Orientation.md', 'create', 'General Orientation.md'],
			'lower case, overwrite' => ['general orientation.md', 'overwrite', 'General Orientation.md'],
			'folder segment' => ['general_orientation/sub.md', 'create', 'General Orientation.md'],
		];
	}

	#[DataProvider('nearDuplicates')]
	public function testNearDuplicatePageNamesAreRefused(string $path, string $mode, string $existing): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFile('General Orientation.md')->putContent("# General Orientation\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$error = $this->wikiError(fn () => $service->writePage(42, $path, "# New\n", $mode, null, self::COLLECTIVE));

		$this->assertSame(WikiException::SIMILAR_PAGE_EXISTS, $error->getErrorCode());
		$this->assertSame($existing, $error->getDetails()['existing']);
		$this->assertStringContainsString('"overwrite"', $this->wikiError(fn () => $service->writePage(42, 'General-Orientation.md', "# New\n", 'create', null, self::COLLECTIVE))->getMessage());
		$this->assertCount(1, array_filter($collectiveRoot->getDirectoryListing(), static fn ($node): bool => str_starts_with(strtolower($node->getName()), 'general')));
	}

	public function testPersonalWikiAlsoRefusesNearDuplicates(): void {
		$service = $this->createService(new InMemoryRootFolder(), $this->createBot('alice', '@studybot', 'personal'));
		$service->writePage(42, 'Projekte/Alpha Plan.md', "# Alpha\n", 'create');

		$error = $this->wikiError(fn () => $service->writePage(42, 'Projekte/alpha-plan.md', "# Alpha 2\n", 'create'));

		$this->assertSame(WikiException::SIMILAR_PAGE_EXISTS, $error->getErrorCode());
		$this->assertSame('Projekte/Alpha Plan.md', $error->getDetails()['existing']);
	}

	public function testWriteIsOnlyReportedWhenTheContentCanBeReadBack(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$page = $collectiveRoot->newFile('page.md');
		$page->putContent("# Page\n");
		$page->dropWrites = true;
		$service = $this->createCollectiveService($collectiveRoot);

		$error = $this->wikiError(fn () => $service->writePage(42, 'page.md', "# Page\n\nNew text.", 'overwrite', null, self::COLLECTIVE));

		$this->assertSame(WikiException::VERIFICATION_FAILED, $error->getErrorCode());
		$this->assertSame(['path' => 'page.md'], $error->getDetails());
	}

	public function testBrokenInternalLinksAreReportedAfterAWrite(): void {
		$service = $this->createService(new InMemoryRootFolder(), $this->createBot('alice', '@studybot', 'personal'));
		$service->writePage(42, 'pages/b.md', "# B\n", 'create');

		$result = $service->writePage(42, 'pages/a.md', "# A\n\n[B](b.md), [Index](../index.md), [Web](https://example.com), [Here](#top), ![Pic](missing.png), [Gone](missing-page.md)", 'create');

		$this->assertSame(3, $result['links_checked']);
		$this->assertSame([['text' => 'Gone', 'target' => 'missing-page.md']], $result['broken_links']);
		$this->assertStringContainsString('1 internal link(s)', implode("\n", $result['warnings']));
	}

	public function testBrokenLinksSuggestTheRelativeLinkToAPageWithThatName(): void {
		$service = $this->createService(new InMemoryRootFolder(), $this->createBot('alice', '@studybot', 'personal'));
		$service->writePage(42, 'Projekte/Alpha.md', "# Alpha\n", 'create');

		$top = $service->writePage(42, 'Projekte.md', "# Projekte\n\n- [Alpha](Alpha.md)", 'create');
		$nested = $service->writePage(42, 'Projekte/Beta.md', "# Beta\n\nSiehe [Alpha](Projekte/Alpha.md) und [Gamma](Gamma.md).", 'create');

		$this->assertSame([['text' => 'Alpha', 'target' => 'Alpha.md', 'suggested_target' => 'Projekte/Alpha.md']], $top['broken_links']);
		$this->assertStringContainsString('top level of the wiki', implode("\n", $top['warnings']));
		$this->assertSame([
			['text' => 'Alpha', 'target' => 'Projekte/Alpha.md', 'suggested_target' => 'Alpha.md'],
			['text' => 'Gamma', 'target' => 'Gamma.md'],
		], $nested['broken_links']);
		$this->assertStringContainsString('keep these links as they are', implode("\n", $nested['warnings']));
	}

	public function testLinkTargetsWithUnencodedSpacesAreReportedWithTheEncodedLink(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFolder('Collaboration')->newFile('How to create a team.md')->putContent("# How to create a team\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$result = $service->writePage(42, 'Collaboration/Readme.md', "# Collaboration\n\n- [Guide](How to create a team.md)\n- [Same](How%20to%20create%20a%20team.md)\n- [Angled](<How to create a team.md>)", 'create', null, self::COLLECTIVE);

		$this->assertSame(3, $result['links_checked']);
		$this->assertSame([[
			'text' => 'Guide',
			'target' => 'How to create a team.md',
			'problem' => 'Spaces in a link target must be written as %20.',
			'suggested_target' => 'How%20to%20create%20a%20team.md',
		]], $result['broken_links']);
	}

	public function testWikiStyleLinksAreReportedWithTheMarkdownLink(): void {
		$service = $this->createService(new InMemoryRootFolder(), $this->createBot('alice', '@studybot', 'personal'));
		$service->writePage(42, 'Projekte/Alpha.md', "# Alpha\n", 'create');

		$result = $service->writePage(42, 'Projekte.md', "# Projekte\n\n- [[Projekte/Alpha]]\n\n```\n[[Beispiel]]\n```", 'create');

		$this->assertSame(1, $result['links_checked']);
		$this->assertSame([[
			'text' => 'Projekte/Alpha',
			'target' => '[[Projekte/Alpha]]',
			'problem' => 'Nextcloud does not show [[...]] as a link; write a Markdown link [text](target.md).',
			'suggested_target' => 'Projekte/Alpha.md',
		]], $result['broken_links']);
	}

	public function testReadingOrWritingAFolderPageThroughItsOldNameSaysWhereItIsStored(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFolder('topic')->newFile('Readme.md')->putContent("# Topic\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$read = $service->readPage(42, 'topic.md', self::COLLECTIVE);
		$write = $service->writePage(42, 'topic.md', "# Topic v2\n", 'overwrite', null, self::COLLECTIVE);

		$this->assertStringContainsString('stored as topic/Readme.md', $read['note']);
		$this->assertStringContainsString('resolved from "topic/"', $read['note']);
		$this->assertStringContainsString('stored as topic/Readme.md', implode("\n", $write['warnings']));
	}

	public function testCollectiveLinksToFolderPagesAreNotBroken(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->newFolder('topic')->newFile('Readme.md')->putContent("# Topic\n");
		$service = $this->createCollectiveService($collectiveRoot);

		$result = $service->writePage(42, 'other.md', "# Other\n\nSee [Topic](topic.md) and [Topic again](topic).", 'create', null, self::COLLECTIVE);

		$this->assertSame(2, $result['links_checked']);
		$this->assertArrayNotHasKey('broken_links', $result);
		$this->assertArrayNotHasKey('warnings', $result);
	}

	public function testListingAllPagesIsCompleteAndPaginated(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$pages = $collectiveRoot->newFolder('pages');
		foreach (['delta', 'Alpha', 'charlie', 'bravo'] as $name) {
			$pages->newFile($name . '.md')->putContent('# ' . ucfirst($name) . "\n");
		}
		$service = $this->createCollectiveService($collectiveRoot);

		$first = $service->search(42, '*', 3, 'wiki', self::COLLECTIVE);
		$this->assertSame('list', $first['action']);
		$this->assertSame(7, $first['total']);
		$this->assertSame(['index.md', 'log.md', 'pages/Alpha.md'], array_column($first['pages'], 'path'));
		$this->assertSame('Alpha', $first['pages'][2]['title']);
		$this->assertTrue($first['has_more']);
		$this->assertSame(3, $first['next_offset']);
		$this->assertFalse($first['truncated']);

		$last = $service->search(42, '*', 3, 'wiki', self::COLLECTIVE, 6);
		$this->assertSame(['schema.md'], array_column($last['pages'], 'path'));
		$this->assertFalse($last['has_more']);
		$this->assertNull($last['next_offset']);
	}

	public function testSearchSaysWhenNotAllFilesWereSearched(): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$pages = $collectiveRoot->newFolder('pages');
		for ($i = 0; $i < 505; $i++) {
			$pages->newFile(sprintf('page-%03d.md', $i))->putContent('filler');
		}
		$service = $this->createCollectiveService($collectiveRoot);

		$result = $service->search(42, 'needle', 5, 'wiki', self::COLLECTIVE);

		$this->assertTrue($result['truncated']);
		$this->assertSame(500, $result['searched_files']);
		$this->assertStringContainsString('query "*"', $result['warning']);
	}

	public function testErrorsTellTheModelWhatToDoNext(): void {
		$service = $this->createService(new InMemoryRootFolder(), $this->createBot('alice', '@studybot', 'personal'));
		$service->writePage(42, 'pages/a.md', "# A\n", 'create');

		$exists = $this->wikiError(fn () => $service->writePage(42, 'pages/a.md', "# A2\n", 'create'));
		$this->assertSame(WikiException::ALREADY_EXISTS, $exists->getErrorCode());
		$this->assertStringContainsString('wiki_read_page', $exists->getMessage());
		$this->assertStringContainsString('"overwrite"', $exists->getMessage());
		$this->assertSame(['path' => 'pages/a.md'], $exists->getDetails());

		$missing = $this->wikiError(fn () => $service->readPage(42, 'pages/nope.md'));
		$this->assertSame(WikiException::NOT_FOUND, $missing->getErrorCode());
		$this->assertStringContainsString('query "*"', $missing->getMessage());

		$empty = $this->wikiError(fn () => $service->search(42, '  '));
		$this->assertSame(WikiException::INVALID_ARGUMENT, $empty->getErrorCode());

		$folder = $this->wikiError(fn () => $service->readPage(42, 'pages.md'));
		$this->assertSame(WikiException::NOT_FOUND, $folder->getErrorCode());
	}

	/**
	 * @return array<string,array{0:\Throwable,1:string}>
	 */
	public static function storageFailures(): array {
		return [
			'permission' => [new NotPermittedException('no write permission'), WikiException::FORBIDDEN],
			'lock' => [new LockedException('index.md'), WikiException::LOCKED],
			'unexpected' => [new \RuntimeException('SQLSTATE secret'), WikiException::OPERATION_FAILED],
		];
	}

	#[DataProvider('storageFailures')]
	public function testStorageFailuresBecomeWikiErrorsWithoutInternalDetails(\Throwable $failure, string $code): void {
		$collectiveRoot = new InMemoryFolder('collective');
		$collectiveRoot->failNewFileWith = $failure;
		$service = $this->createCollectiveService($collectiveRoot);

		$error = $this->wikiError(fn () => $service->writePage(42, 'page.md', "# Page\n", 'create', null, self::COLLECTIVE));

		$this->assertSame($code, $error->getErrorCode());
		$this->assertStringNotContainsString('SQLSTATE', $error->getMessage());
		$this->assertSame($failure, $error->getPrevious());
	}

	public function testUnavailableCollectiveKeepsInternalDetailsOutOfTheToolError(): void {
		$wrapped = new \Exception('Unable to load selected collective: SQLSTATE[HY000] secret', 0, new \RuntimeException('SQLSTATE[HY000] secret'));
		$plain = new \Exception('The Collectives app is not available.');
		foreach ([[$wrapped, 'it may have been deleted'], [$plain, 'The Collectives app is not available.']] as [$failure, $expected]) {
			$locationService = $this->createMock(WikiLocationService::class);
			$locationService->method('resolveCollectiveWikiRoot')->willThrowException($failure);
			$service = $this->createService(new InMemoryRootFolder(), $this->createBot('alice', '@studybot', 'personal'), $locationService);

			$error = $this->wikiError(fn () => $service->readPage(42, 'page.md', self::COLLECTIVE));

			$this->assertSame(WikiException::WIKI_UNAVAILABLE, $error->getErrorCode());
			$this->assertStringContainsString($expected, $error->getMessage());
			$this->assertStringNotContainsString('SQLSTATE', $error->getMessage());
		}
	}

	private const COLLECTIVE = ['wiki_location' => 'collective', 'wiki_collective_id' => 99];

	public function testLinksToExistingPagesGetThePageIdSoCollectivesCanOpenThem(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$tools = $wikiRoot->newFile('Tools.md');
		$tools->putContent('# Tools');
		$team = $wikiRoot->newFolder('Collaboration')->newFile('How to create a team.md');
		$team->putContent('# Team');
		$wikiRoot->get('Collaboration')->newFile('Readme.md')->putContent('# Collaboration');
		$readme = $wikiRoot->get('Collaboration/Readme.md');
		$service = $this->createCollectiveService($wikiRoot);

		$result = $service->writePage(42, 'Kalender/Termin.md', "# Termin\n\n[Tools](../Tools.md), [Team](../Collaboration/How%20to%20create%20a%20team.md#steps), [Collaboration](../Collaboration.md), `[Code](../Tools.md)` and [Web](https://example.com).", 'create', null, self::COLLECTIVE);

		// Paths start at the collective root: the browser resolves them against the page
		// address, so "../" could leave the collective; the ID decides which page opens.
		$this->assertSame(
			"# Termin\n\n[Tools](Tools.md?fileId=" . $tools->getId() . "), [Team](Collaboration/How%20to%20create%20a%20team.md?fileId=" . $team->getId() . "#steps), [Collaboration](Collaboration/Readme.md?fileId=" . $readme->getId() . "), `[Code](../Tools.md)` and [Web](https://example.com).",
			$wikiRoot->get('Kalender/Termin.md')->getContent()
		);
		$this->assertSame(3, $result['links_with_page_id']);
		$this->assertArrayNotHasKey('broken_links', $result);
	}

	public function testCollectivesWebAddressesOfWikiPagesBecomePageLinks(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$page = $wikiRoot->newFolder('educ-portal')->newFile('access-and-initial-setup.md');
		$page->putContent('# Access');
		$wikiRoot->get('educ-portal')->newFile('Readme.md')->putContent('# EDUC Portal');
		$service = $this->createCollectiveService($wikiRoot);

		$result = $service->writePage(42, 'index-of-topics.md', "[Access](https://nc.example/apps/collectives/Wiki-for-Portal-4/educ-portal/access-and-initial-setup)\n[Gone](https://nc.example/index.php/apps/collectives/Wiki-for-Portal-4/educ-portal/missing-page)", 'create', null, self::COLLECTIVE);

		$content = $wikiRoot->get('index-of-topics.md')->getContent();
		$this->assertStringContainsString('[Access](educ-portal/access-and-initial-setup.md?fileId=' . $page->getId() . ')', $content);
		$this->assertSame('Do not link Collectives web addresses; link the page with a relative Markdown link [text](page.md).', $result['broken_links'][0]['problem']);
	}

	public function testLinksOnTheLandingPageStartWithTheCollectivePath(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$wikiRoot->newFile('Readme.md')->putContent('# Welcome');
		$tools = $wikiRoot->newFile('Tools.md');
		$tools->putContent('# Tools');
		$service = $this->createCollectiveService($wikiRoot);

		$service->writePage(42, 'Readme.md', "# Welcome\n\n[Tools](Tools.md)", 'overwrite', null, self::COLLECTIVE);

		// The landing page address has no trailing folder, so a relative link would leave the collective.
		$this->assertSame("# Welcome\n\n[Tools](Team-Notes-99/Tools.md?fileId=" . $tools->getId() . ')', $wikiRoot->get('Readme.md')->getContent());
	}

	public function testLinksWrittenBeforeTheirPageExistedGetThePageIdWhenItIsCreated(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($wikiRoot);
		$overview = $service->writePage(42, 'Overview.md', "# Overview\n\n[Data protection](Data%20protection.md)", 'create', null, self::COLLECTIVE);
		$this->assertSame('Data%20protection.md', $overview['broken_links'][0]['target']);

		$created = $service->writePage(42, 'Data protection.md', '# Data protection', 'create', null, self::COLLECTIVE);

		$page = $wikiRoot->get('Data protection.md');
		$this->assertSame("# Overview\n\n[Data protection](Data%20protection.md?fileId=" . $page->getId() . ')', $wikiRoot->get('Overview.md')->getContent());
		$this->assertSame(['Overview.md'], $created['pages_linked_to_this_page']);
		$this->assertMatchesRegularExpression('/linked Overview\.md/', $wikiRoot->get('log.md')->getContent());
	}

	public function testPersonalWikiLinksKeepTheirRelativePathAndGetThePageId(): void {
		$root = new InMemoryRootFolder();
		$service = $this->createService($root, $this->createBot('alice', '@studybot', 'personal'));
		$service->writePage(42, 'target.md', '# Target', 'create');
		$wiki = Application::WIKI_ROOT_FOLDER . '/Personal Wikis/studybot/';
		$target = $root->getUserFolder('alice')->get($wiki . 'target.md');

		$result = $service->writePage(42, 'notes/page.md', '[Target](../target.md) [Stale](missing.md?fileId=999999)', 'create');

		// Files opens "?fileId=" links directly; other Markdown viewers still follow the relative path.
		$this->assertSame('[Target](../target.md?fileId=' . $target->getId() . ') [Stale](missing.md?fileId=999999)', $root->getUserFolder('alice')->get($wiki . 'notes/page.md')->getContent());
		$this->assertSame('missing.md?fileId=999999', $result['broken_links'][0]['target']);
	}

	private function createCollectiveService(InMemoryFolder $collectiveRoot): WikiService {
		$locationService = $this->createMock(WikiLocationService::class);
		$locationService->method('resolveCollectiveWikiRoot')
			->with(99, 'alice')
			->willReturn(['folder' => $collectiveRoot, 'label' => 'Collective: Team Notes', 'url_path' => 'Team-Notes-99']);

		return $this->createService(new InMemoryRootFolder(), $this->createBot('alice', '@studybot', 'personal'), $locationService);
	}

	private function wikiError(callable $operation): WikiException {
		try {
			$operation();
		} catch (WikiException $e) {
			return $e;
		}
		$this->fail('Expected a WikiException');
	}

	public function testAgentCanEnumerateAllPagesUsingOnlyDeliveredListingOffsets(): void {
		$wikiRoot = new InMemoryFolder('collective');
		for ($i = 0; $i < 219; $i++) {
			$wikiRoot->newFile(sprintf('page-%03d.md', $i))->putContent('# ' . str_repeat('猫"\\', 25));
		}
		$service = $this->createCollectiveService($wikiRoot);
		$reflection = new \ReflectionClass(AgentExecutor::class);
		$executor = $reflection->newInstanceWithoutConstructor();
		$sanitize = $reflection->getMethod('sanitizeOutput');
		$offset = 0;
		$seen = [];
		do {
			$page = $service->search(42, '*', 100, 'wiki', self::COLLECTIVE, $offset);
			$text = json_encode($page, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
			$output = $sanitize->invoke($executor, ['content' => [['type' => 'text', 'text' => $text]]], false, true);
			$this->assertLessThanOrEqual(4000, mb_strlen($output, 'UTF-8'));
			$delivered = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
			$this->assertSame(222, $delivered['total']);
			$this->assertSame($offset, $delivered['offset']);
			$this->assertSame(count($delivered['pages']), $delivered['returned_count']);
			$this->assertSame(array_slice($page['pages'], 0, $delivered['returned_count']), $delivered['pages']);
			array_push($seen, ...array_column($delivered['pages'], 'path'));
			$this->assertLessThanOrEqual(222, count($seen));
			if ($delivered['has_more']) {
				$this->assertGreaterThan(0, $delivered['returned_count']);
				$this->assertSame($offset + $delivered['returned_count'], $delivered['next_offset']);
				$offset = $delivered['next_offset'];
			} else {
				$this->assertNull($delivered['next_offset']);
			}
		} while ($delivered['has_more']);
		$this->assertCount(222, array_unique($seen));
		$this->assertContains('page-218.md', $seen);
	}

	public function testRejectedOversizedAppendDoesNotMoveAnAncestorOrChangeTheIndex(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$wikiRoot->newFile('topic.md')->putContent('# Topic');
		$child = $wikiRoot->newFolder('topic')->newFile('child.md');
		$child->putContent(str_repeat('x', 524288));
		$service = $this->createCollectiveService($wikiRoot);
		$service->initializeWiki(42, self::COLLECTIVE);
		$indexBefore = $wikiRoot->get('index.md')->getContent();
		$logBefore = $wikiRoot->get('log.md')->getContent();

		$error = $this->wikiError(fn () => $service->writePage(42, 'topic/child.md', 'x', 'append', null, self::COLLECTIVE));

		$this->assertSame(WikiException::TOO_LARGE, $error->getErrorCode());
		$this->assertSame('# Topic', $wikiRoot->get('topic.md')->getContent());
		$this->assertNull($this->nodeOrNull($wikiRoot, 'topic/Readme.md'));
		$this->assertSame(524288, $child->getSize());
		$this->assertSame($indexBefore, $wikiRoot->get('index.md')->getContent());
		$this->assertSame($logBefore, $wikiRoot->get('log.md')->getContent());
	}

	public function testAppendPreflightIncludesTheRebasedPageSuppliedByAPlannedMove(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$content = str_repeat('x', 524270) . '[T](target.md)';
		$wikiRoot->newFile('topic.md')->putContent($content);
		$service = $this->createCollectiveService($wikiRoot);

		$error = $this->wikiError(fn () => $service->writePage(42, 'topic/Readme.md', 'xxxx', 'append', null, self::COLLECTIVE));

		$this->assertSame(WikiException::TOO_LARGE, $error->getErrorCode());
		$this->assertSame($content, $wikiRoot->get('topic.md')->getContent());
		$this->assertNull($this->nodeOrNull($wikiRoot, 'topic/Readme.md'));
	}

	public function testManagedIndexFitsTheByteLimitAndPreservesTheCuratedSections(): void {
		$wikiRoot = new InMemoryFolder('collective');
		for ($i = 0; $i < 850; $i++) {
			$wikiRoot->newFile(sprintf('%04d-', $i) . str_repeat('x', 173) . '.md')->putContent('# ' . str_repeat('猫', 160));
		}
		$curated = '# Index' . "\n\n## Overview\n" . str_repeat('Curated knowledge. ', 1000);
		$wikiRoot->newFile('index.md')->putContent($curated . "\n\n## Existing Files\n\nThis section is updated automatically when the wiki is initialized or changed.\n\n- Old entry\n\n## Questions\nKeep this question.\n");
		$service = $this->createCollectiveService($wikiRoot);

		$result = $service->writePage(42, 'new.md', '# New', 'create', null, self::COLLECTIVE);

		$this->assertTrue($result['success']);
		$this->assertSame('# New', $wikiRoot->get('new.md')->getContent());
		$index = $wikiRoot->get('index.md')->getContent();
		$this->assertLessThanOrEqual(524288, strlen($index));
		$this->assertTrue(mb_check_encoding($index, 'UTF-8'));
		$this->assertStringContainsString($curated, $index);
		$this->assertStringContainsString("## Questions\nKeep this question.", $index);
		$this->assertStringContainsString('index byte limit reached', $index);
		$this->assertStringNotContainsString('- Old entry', $index);
		foreach (explode("\n", $index) as $line) {
			if (str_starts_with($line, '- [')) {
				$this->assertMatchesRegularExpression('/\([0-9]+ bytes\)$/', $line);
			}
		}
	}

	/** @return array<string,array{bool}> */
	public static function savedWikiOperations(): array {
		return ['page write' => [false], 'explicit log event' => [true]];
	}

	#[DataProvider('savedWikiOperations')]
	public function testIndexFailureDoesNotTurnASavedOperationIntoAFailure(bool $logEvent): void {
		$wikiRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($wikiRoot);
		$service->initializeWiki(42, self::COLLECTIVE);
		$wikiRoot->get('index.md')->putContent(str_repeat('x', 524288));

		$result = $logEvent
			? $service->logEvent(42, 'Decision', 'Keep context', self::COLLECTIVE)
			: $service->writePage(42, 'new.md', '# New', 'create', null, self::COLLECTIVE);

		$this->assertTrue($result['success']);
		$this->assertTrue($result['verified']);
		$this->assertSame('index_not_updated', $result['warnings'][0]['code']);
		$this->assertStringContainsString($logEvent ? 'Keep context' : 'created new.md', $wikiRoot->get('log.md')->getContent());
		if (!$logEvent) {
			$this->assertSame('# New', $wikiRoot->get('new.md')->getContent());
		}
	}

	public function testIndexStorageFailureAfterVerifiedWriteIsNonFatal(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($wikiRoot);
		$service->initializeWiki(42, self::COLLECTIVE);
		$wikiRoot->get('index.md')->dropWrites = true;

		$result = $service->writePage(42, 'new.md', '# New', 'create', null, self::COLLECTIVE);

		$this->assertTrue($result['success']);
		$this->assertSame('index_not_updated', $result['warnings'][0]['code']);
		$this->assertSame('# New', $wikiRoot->get('new.md')->getContent());
	}

	public function testReferenceDefinitionsAreRebasedAndAuditedOnConversion(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$wikiRoot->newFile('target.md')->putContent('# Target');
		$source = "# Topic\n[Target][full], [collapsed][] and [shortcut].\n![Image][image]\n"
			. "[full]: target.md#section \"Target title\"\n[collapsed]: <target.md?mode=1#part> 'Title'\n"
			. "[shortcut]: target.md (Title)\n[image]: .attachments.1/photo.png \"Photo\"\n[missing]: missing.md \"Missing\"\n";
		$wikiRoot->newFile('topic.md')->putContent($source);
		$service = $this->createCollectiveService($wikiRoot);

		$result = $service->writePage(42, 'topic/child.md', '# Child', 'create', null, self::COLLECTIVE);

		$converted = $result['converted_to_parent_pages'][0];
		$this->assertSame(5, $converted['links_rebased']);
		$moved = $wikiRoot->get('topic/Readme.md')->getContent();
		$id = $wikiRoot->get('target.md')->getId();
		$this->assertStringContainsString('[full]: target.md?fileId=' . $id . '#section "Target title"', $moved);
		$this->assertStringContainsString("[collapsed]: target.md?mode=1&fileId=" . $id . "#part 'Title'", $moved);
		$this->assertStringContainsString('[shortcut]: target.md?fileId=' . $id . ' (Title)', $moved);
		$this->assertStringContainsString('[image]: ../.attachments.1/photo.png "Photo"', $moved);
		$this->assertContains(['text' => 'missing', 'target' => '../missing.md'], $converted['broken_links']);
		$this->assertNotContains('full', array_column($converted['broken_links'], 'text'));
	}

	public function testCodeSpansAndEscapedBracketsAreNeitherRebasedNorAudited(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$examples = <<<'MD'
`[single](missing.md)` and `![image](photo.png)`.
``[double](missing.md) ` literal backtick``
Examples: ```[triple](missing.md) `` literal double```
``[multiline](missing.md)
[code]: missing.md "title"``
\[escaped](missing.md) and !\[image](photo.png)
\[reference]: missing.md "title"
\[[wiki]]
~~~markdown
[fenced](missing.md)
```
[still fenced]: missing.md
~~~
MD;
		$wikiRoot->newFile('topic.md')->putContent($examples);
		$service = $this->createCollectiveService($wikiRoot);

		$result = $service->writePage(42, 'topic/child.md', '# Child', 'create', null, self::COLLECTIVE);

		$this->assertSame($examples, $wikiRoot->get('topic/Readme.md')->getContent());
		$this->assertSame([['from' => 'topic.md', 'to' => 'topic/Readme.md']], $result['converted_to_parent_pages']);
		$this->assertArrayNotHasKey('warnings', $result);
		$write = $service->writePage(42, 'examples.md', $examples, 'create', null, self::COLLECTIVE);
		$this->assertSame(0, $write['links_checked']);
		$this->assertArrayNotHasKey('broken_links', $write);
	}

	public function testIndexAndSuggestedTargetsEncodeEveryPathSegment(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$wikiRoot->newFolder('folder #?%')->newFile('lesson#1%20 (ä).md')->putContent('# Lesson');
		$service = $this->createCollectiveService($wikiRoot);

		$result = $service->writePage(42, 'note.md', '[Lesson](lesson%231%2520%20%28%C3%A4%29.md)', 'create', null, self::COLLECTIVE);

		$target = 'folder%20%23%3F%25/lesson%231%2520%20%28%C3%A4%29.md';
		$this->assertStringContainsString('[Lesson](' . $target . '?fileId=' . $wikiRoot->get('folder #?%/lesson#1%20 (ä).md')->getId() . ')', $wikiRoot->get('index.md')->getContent());
		$this->assertSame($target, $result['broken_links'][0]['suggested_target']);
	}

	/** @return array<string,array{bool}> */
	public static function managedIndexFormats(): array {
		return ['current section' => [false], 'legacy markers' => [true]];
	}

	#[DataProvider('managedIndexFormats')]
	public function testDollarNumberTitlesAndPathsAreLiteralInManagedIndex(bool $legacy): void {
		$wikiRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($wikiRoot);
		$service->initializeWiki(42, self::COLLECTIVE);
		if ($legacy) {
			$wikiRoot->get('index.md')->putContent("# Index\n<!-- EDUC-AI-WIKI-FILE-INDEX:START -->\nOld\n<!-- EDUC-AI-WIKI-FILE-INDEX:END -->\n");
		}
		$service->writePage(42, 'cost$1.md', '# Cost $100 ${1} \\1', 'create', null, self::COLLECTIVE);

		$this->assertMatchesRegularExpression('/\[Cost \$100 \$\{1\} \\\\1\]\(cost%241\.md\?fileId=\d+\) - `cost\$1\.md`/', $wikiRoot->get('index.md')->getContent());
		$this->assertSame('# Cost $100 ${1} \\1', $wikiRoot->get('cost$1.md')->getContent());
	}

	public function testEveryWriteModeAndEachParentConversionIsLoggedWithoutPageContent(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$wikiRoot->newFile('topic.md')->putContent('# Secret parent content');
		$wikiRoot->newFolder('topic')->newFile('nested.md')->putContent('# Secret nested content');
		$service = $this->createCollectiveService($wikiRoot);
		$service->writePage(42, 'topic/nested/child.md', 'Secret child content', 'create', null, self::COLLECTIVE);
		$service->writePage(42, 'topic/nested/child.md', 'Changed secret', 'overwrite', null, self::COLLECTIVE);
		$service->writePage(42, 'topic/nested/child.md', ' Appended secret', 'append', null, self::COLLECTIVE);
		$log = $wikiRoot->get('log.md')->getContent();

		foreach (['converted topic/Readme.md (from topic.md)', 'converted topic/nested/Readme.md (from topic/nested.md)', 'created topic/nested/child.md', 'overwritten topic/nested/child.md', 'appended topic/nested/child.md'] as $event) {
			$this->assertSame(1, substr_count($log, $event));
		}
		$this->assertMatchesRegularExpression('/- \[\d{4}-\d{2}-\d{2} \d{2}:\d{2}\] created topic\/nested\/child.md/', $log);
		$this->assertStringNotContainsString('secret', strtolower($log));
	}

	public function testAutomaticLogKeepsTheFullPathAndLogsCompletedConversionsEvenIfTheChildWriteFails(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($wikiRoot);
		$path = str_repeat('a', 180) . '.md';
		$service->writePage(42, $path, '# Page', 'create', null, self::COLLECTIVE);
		$this->assertStringContainsString('created ' . $path, $wikiRoot->get('log.md')->getContent());
		$wikiRoot->newFile('topic.md')->putContent('# Topic');
		$wikiRoot->newFolder('topic')->failNewFileWith = new NotPermittedException('Child creation denied');

		$error = $this->wikiError(fn () => $service->writePage(42, 'topic/child.md', '# Child', 'create', null, self::COLLECTIVE));

		$this->assertSame(WikiException::FORBIDDEN, $error->getErrorCode());
		$this->assertSame('# Topic', $wikiRoot->get('topic/Readme.md')->getContent());
		$log = $wikiRoot->get('log.md')->getContent();
		$this->assertStringContainsString('converted topic/Readme.md (from topic.md)', $log);
		$this->assertStringNotContainsString('created topic/child.md', $log);
	}

	public function testManagedRootWritesDoNotGenerateRecursiveLogEntriesButNestedPagesDo(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($wikiRoot);
		$service->initializeWiki(42, self::COLLECTIVE);
		$before = $wikiRoot->get('log.md')->getContent();
		foreach (['index.md', 'schema.md'] as $path) {
			$service->writePage(42, $path, '# Updated', 'overwrite', null, self::COLLECTIVE);
			$this->assertSame($before, $wikiRoot->get('log.md')->getContent());
		}
		$service->writePage(42, 'log.md', '# User log', 'overwrite', null, self::COLLECTIVE);
		$this->assertSame('# User log', $wikiRoot->get('log.md')->getContent());
		$service->writePage(42, 'topic/index.md', '# Nested', 'create', null, self::COLLECTIVE);
		$this->assertStringContainsString('created topic/index.md', $wikiRoot->get('log.md')->getContent());
		$service->logEvent(42, 'Extra decision', 'Useful context', self::COLLECTIVE);
		$log = $wikiRoot->get('log.md')->getContent();
		$this->assertStringContainsString('Useful context', $log);
		$this->assertStringNotContainsString('appended log.md', $log);
	}

	public function testAutomaticLogFailureIsReportedWithoutLosingTheSavedPage(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($wikiRoot);
		$service->initializeWiki(42, self::COLLECTIVE);
		$wikiRoot->get('log.md')->putContent(str_repeat('x', 524288));

		$result = $service->writePage(42, 'page.md', '# Page', 'create', null, self::COLLECTIVE);

		$this->assertTrue($result['success']);
		$this->assertSame('# Page', $wikiRoot->get('page.md')->getContent());
		$this->assertSame('log_not_updated', $result['warnings'][0]['code']);
		$this->assertStringContainsString('[Page](page.md?fileId=', $wikiRoot->get('index.md')->getContent());
	}

	public function testNewSchemasDescribeAutomaticLoggingWithoutRewritingExistingSchemas(): void {
		$wikiRoot = new InMemoryFolder('collective');
		$service = $this->createCollectiveService($wikiRoot);
		$service->initializeWiki(42, self::COLLECTIVE);
		$this->assertStringContainsString('logged automatically', $wikiRoot->get('schema.md')->getContent());
		$this->assertStringContainsString('not duplicate page-change entries', $wikiRoot->get('schema.md')->getContent());
		$wikiRoot->get('schema.md')->putContent('# Custom schema');
		$service->writePage(42, 'page.md', '# Page', 'create', null, self::COLLECTIVE);
		$service->initializeWiki(42, self::COLLECTIVE);
		$this->assertSame('# Custom schema', $wikiRoot->get('schema.md')->getContent());
	}

	private function nodeOrNull(InMemoryFolder $folder, string $path): mixed {
		try {
			return $folder->get($path);
		} catch (NotFoundException) {
			return null;
		}
	}

	private function createService(
		InMemoryRootFolder $root,
		Bot $bot,
		?WikiLocationService $wikiLocationService = null,
		?TextSessionResetService $textSessionResetService = null,
		?BrandingService $brandingService = null
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
			$textSessionResetService,
			$brandingService
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
	private ?InMemoryFolder $parent;
	/** @var array<string,File|Folder> */
	private array $children = [];
	/** Simulates a storage failure when a page is created. */
	public ?\Throwable $failNewFileWith = null;

	public function __construct(string $name, ?InMemoryFolder $parent = null) {
		$this->name = $name;
		$this->parent = $parent;
	}

	public function attach(string $name, InMemoryFile|InMemoryFolder $node): void {
		$this->children[$name] = $node;
	}

	public function detach(string $name): void {
		unset($this->children[$name]);
	}

	public function top(): InMemoryFolder {
		return $this->parent === null ? $this : $this->parent->top();
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
		$folder = new self($path, $this);
		$this->children[$path] = $folder;
		return $folder;
	}

	public function newFile(string $path): File {
		if ($this->failNewFileWith !== null) {
			throw $this->failNewFileWith;
		}
		$file = new InMemoryFile($path, $this);
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
		return $this->parent === null
			? '/' . trim($this->name, '/')
			: rtrim($this->parent->getPath(), '/') . '/' . $this->name;
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
	private int $id;
	private ?InMemoryFolder $parent;
	private string $content = '';
	/** Simulates a storage that does not keep what was written. */
	public bool $dropWrites = false;

	public function __construct(string $name, ?InMemoryFolder $parent = null) {
		$this->name = $name;
		$this->id = crc32($name);
		$this->parent = $parent;
	}

	public function getContent() {
		return $this->content;
	}

	public function putContent($data) {
		$this->content = $this->dropWrites ? '' : (string)$data;
	}

	public function getId() {
		return $this->id;
	}

	/**
	 * Like Node::move(): the target is an absolute path and the file id is kept.
	 */
	public function move($targetPath) {
		$top = $this->parent->top();
		$relative = ltrim(substr($targetPath, strlen(rtrim($top->getPath(), '/'))), '/');
		$segments = explode('/', $relative);
		$name = array_pop($segments);
		$target = $segments === [] ? $top : $top->get(implode('/', $segments));
		$this->parent->detach($this->name);
		$this->name = $name;
		$this->parent = $target;
		$target->attach($name, $this);
		return $this;
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
		return $this->parent === null
			? '/' . trim($this->name, '/')
			: rtrim($this->parent->getPath(), '/') . '/' . $this->name;
	}

	public function getParent() {
		return $this->parent;
	}
}
