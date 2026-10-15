<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Exception\WikiException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

class WikiService {
	private const MAX_FILE_BYTES = 524288;
	private const MAX_SEARCH_FILES = 500;
	private const MAX_INDEX_FILES = 1000;
	private const MAX_LIST_FILES = 5000;
	private const DEFAULT_LIST_LIMIT = 50;
	private const MAX_LIST_LIMIT = 100;
	private const MAX_REPORTED_BROKEN_LINKS = 20;
	private const DEFAULT_READ_LIMIT = 3000;
	private const MAX_READ_LIMIT = 3500;
	private const ALLOWED_EXTENSIONS = ['md', 'txt', 'json'];
	/** Collectives stores the content of a page with subpages as <page>/Readme.md. */
	private const COLLECTIVE_PARENT_PAGE = 'Readme.md';

	private IRootFolder $rootFolder;
	private BotMapper $botMapper;
	private LoggerInterface $logger;
	private ?WikiLocationService $wikiLocationService;
	private TextSessionResetService $textSessionResetService;
	private BrandingService $brandingService;
	private WikiPathService $wikiPathService;

	public function __construct(
		IRootFolder $rootFolder,
		BotMapper $botMapper,
		LoggerInterface $logger,
		?WikiLocationService $wikiLocationService = null,
		?TextSessionResetService $textSessionResetService = null,
		?BrandingService $brandingService = null,
		?WikiPathService $wikiPathService = null
	) {
		$this->brandingService = $brandingService ?? new BrandingService();
		$this->wikiPathService = $wikiPathService ?? new WikiPathService($this->brandingService);
		$this->rootFolder = $rootFolder;
		$this->botMapper = $botMapper;
		$this->logger = $logger;
		$this->wikiLocationService = $wikiLocationService;
		$this->textSessionResetService = $textSessionResetService ?? new TextSessionResetService($logger);
	}

	/**
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	public function initializeWiki(int $botId, array $config = []): array {
		$context = $this->resolveContext($botId, $config);
		$wikiRoot = $this->ensureWikiRoot($context);
		$index = $this->syncIndexForRoot($wikiRoot);

		return [
			'success' => true,
			'action' => 'initialized',
			'wiki_root' => $context['root_path'],
			'indexed_files' => $index['count'],
			'index_path' => 'index.md',
		];
	}

	/**
	 * @return array{count:int}
	 */
	public function syncIndexForRoot(Folder $wikiRoot): array {
		return $this->syncIndexFile($wikiRoot);
	}

	/**
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	public function readPage(
		int $botId,
		string $path,
		int|array $offset = 0,
		int|array $limit = self::DEFAULT_READ_LIMIT,
		array $config = []
	): array {
		if (is_array($offset)) {
			$config = $offset;
			$offset = 0;
			$limit = self::DEFAULT_READ_LIMIT;
		} elseif (is_array($limit)) {
			$config = $limit;
			$limit = self::DEFAULT_READ_LIMIT;
		}

		return $this->withWikiErrors(function () use ($botId, $path, $offset, $limit, $config): array {
			$context = $this->resolveContext($botId, $config);
			$pagePath = $this->normalizePagePath($path);
			$wikiRoot = $this->ensureWikiRoot($context);

			$resolvedPath = $pagePath;
			$node = $this->getNodeOrNull($wikiRoot, $pagePath);
			if ($node === null && $context['location'] === 'collective') {
				$parentPage = $this->collectiveParentPagePath($pagePath);
				if ($parentPage !== null && ($candidate = $this->getNodeOrNull($wikiRoot, $parentPage)) instanceof File) {
					$node = $candidate;
					$resolvedPath = $parentPage;
				}
			}
			if ($node === null) {
				throw new WikiException(WikiException::NOT_FOUND, 'Wiki page not found: ' . $pagePath . '. Use wiki_search with query "*" to list the existing pages.', ['path' => $pagePath]);
			}
			if (!$node instanceof File) {
				throw new WikiException(WikiException::PATH_CONFLICT, 'Wiki path is a folder, not a page: ' . $pagePath, ['path' => $pagePath]);
			}

			return $this->readSlice($node, $pagePath, $resolvedPath, $context, $offset, $limit);
		});
	}

	/**
	 * @param array{root_path:string} $context
	 * @return array<string,mixed>
	 */
	private function readSlice(File $node, string $pagePath, string $resolvedPath, array $context, int $offset, int $limit): array {
		$content = (string)$node->getContent();
		$offset = max(0, $offset);
		$limit = max(1, min(self::MAX_READ_LIMIT, $limit));
		$totalLength = mb_strlen($content, 'UTF-8');
		$slice = mb_substr($content, $offset, $limit, 'UTF-8');
		$returnedLength = mb_strlen($slice, 'UTF-8');
		$nextOffset = $offset + $returnedLength;
		$hasMore = $nextOffset < $totalLength;

		$result = [
			'success' => true,
			'action' => 'read',
			'path' => $pagePath,
			'wiki_root' => $context['root_path'],
			'offset' => $offset,
			'limit' => $limit,
			'returned_length' => $returnedLength,
			'total_length' => $totalLength,
			'size' => $node->getSize(),
			'has_more' => $hasMore,
			'next_offset' => $hasMore ? $nextOffset : null,
			'content' => $slice,
		];
		if ($resolvedPath !== $pagePath) {
			$result['resolved_path'] = $resolvedPath;
			$result['note'] = $this->storedAsNote($resolvedPath);
		}

		return $result;
	}

	private function storedAsNote(string $resolvedPath): string {
		return 'This page is stored as ' . $resolvedPath . '. Write it under that path; relative links in it are resolved from "' . dirname($resolvedPath) . '/".';
	}

	/**
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	public function writePage(int $botId, string $path, string $content, string $mode = 'create', ?string $reason = null, array $config = []): array {
		return $this->withWikiErrors(function () use ($botId, $path, $content, $mode, $config): array {
			$context = $this->resolveContext($botId, $config);
			$pagePath = $this->normalizePagePath($path);
			$this->assertTextSize($content);
			$mode = $this->normalizeWriteMode($mode);
			$wikiRoot = $this->ensureWikiRoot($context);

			$collective = $context['location'] === 'collective';
			$layout = $collective
				? $this->planCollectivePage($wikiRoot, $pagePath)
				: ['path' => $pagePath, 'parent' => null, 'moves' => [], 'warnings' => []];
			$this->preflightWrite($wikiRoot, $layout['path'], $layout['moves'], $mode);
			$converted = $this->applyCollectiveMoves($wikiRoot, $layout['moves']);
			$file = $this->writeRelativeFile($wikiRoot, $layout['path'], $content, $mode);
			$this->syncIndexForRoot($wikiRoot);

			$result = [
				'success' => true,
				'action' => $mode === 'append' ? 'appended' : 'written',
				'path' => $pagePath,
				'wiki_root' => $context['root_path'],
				'file_id' => $file->getId(),
				'size' => $file->getSize(),
				'verified' => true,
			];
			if ($layout['path'] !== $pagePath) {
				$result['resolved_path'] = $layout['path'];
			}
			if ($collective) {
				$result['parent_page'] = $layout['parent'];
			}
			$warnings = $layout['warnings'];
			if ($layout['path'] !== $pagePath && dirname($layout['path']) !== dirname($pagePath)) {
				$warnings[] = $this->storedAsNote($layout['path']);
			}
			foreach ($converted as $move) {
				$entry = ['from' => $move['from'], 'to' => $move['to']];
				if ($move['links_rebased'] > 0) {
					$entry['links_rebased'] = $move['links_rebased'];
				}
				if ($move['broken'] !== []) {
					$entry['broken_links'] = $move['broken'];
					$warnings[] = 'The moved page ' . $move['to'] . ' has ' . count($move['broken']) . ' internal link(s) to pages that do not exist. ' . $this->linkBaseHint($move['to']);
				}
				$result['converted_to_parent_pages'][] = $entry;
			}
			if (strtolower(pathinfo($layout['path'], PATHINFO_EXTENSION)) === 'md') {
				$linkAudit = $this->auditInternalLinks($wikiRoot, $layout['path'], (string)$file->getContent(), $collective);
				$result['links_checked'] = $linkAudit['checked'];
				if ($linkAudit['broken'] !== []) {
					$result['broken_links'] = $linkAudit['broken'];
					$warnings[] = count($linkAudit['broken']) . ' internal link(s) point to pages that do not exist. ' . $this->linkBaseHint($layout['path']);
				}
			}
			if ($warnings !== []) {
				$result['warnings'] = $warnings;
			}

			return $result;
		});
	}

	/**
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	public function logEvent(int $botId, string $title, string $details = '', array $config = []): array {
		return $this->withWikiErrors(function () use ($botId, $title, $details, $config): array {
			$context = $this->resolveContext($botId, $config);
			$wikiRoot = $this->ensureWikiRoot($context);
			$entry = "\n\n## [" . date('Y-m-d H:i') . '] ' . $this->sanitizeLogTitle($title) . "\n";
			if (trim($details) !== '') {
				$entry .= trim($details) . "\n";
			}

			$file = $this->writeRelativeFile($wikiRoot, 'log.md', $entry, 'append');
			$this->syncIndexForRoot($wikiRoot);
			return [
				'success' => true,
				'action' => 'logged',
				'path' => 'log.md',
				'wiki_root' => $context['root_path'],
				'file_id' => $file->getId(),
				'size' => $file->getSize(),
				'verified' => true,
			];
		});
	}

	/**
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	public function search(int $botId, string $query, int $limit = 5, string $scope = 'wiki', array $config = [], int $offset = 0): array {
		$query = trim($query);
		if ($query === '') {
			throw new WikiException(WikiException::INVALID_ARGUMENT, 'query parameter is required for wiki search. Use "*" to list all pages.');
		}

		return $this->withWikiErrors(fn (): array => $this->searchOrList($botId, $query, $limit, $scope, $config, $offset));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function searchOrList(int $botId, string $query, int $limit, string $scope, array $config, int $offset): array {
		$context = $this->resolveContext($botId, $config);
		$wikiRoot = $this->ensureWikiRoot($context);
		$scope = in_array($scope, ['wiki', 'sources', 'all'], true) ? $scope : 'wiki';
		if ($query === '*') {
			return $this->listPages($wikiRoot, $context, $scope, $offset, $limit);
		}

		$limit = max(1, min(20, $limit));
		$truncated = false;
		$files = $this->collectTextFiles($wikiRoot, '', $scope, self::MAX_SEARCH_FILES, $truncated);
		$needle = mb_strtolower($query);
		$results = [];

		foreach ($files as $entry) {
			/** @var File $file */
			$file = $entry['file'];
			$path = $entry['path'];
			$content = (string)$file->getContent();
			$haystack = mb_strtolower($path . "\n" . $content);
			$pathScore = substr_count(mb_strtolower($path), $needle) * 5;
			$contentScore = substr_count($haystack, $needle);
			$score = $pathScore + $contentScore;
			if ($score <= 0) {
				continue;
			}
			$results[] = [
				'path' => $path,
				'score' => $score,
				'snippet' => $this->buildSnippet($content, $query),
				'size' => $file->getSize(),
			];
		}

		usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
		$results = array_slice($results, 0, $limit);

		$result = [
			'success' => true,
			'action' => 'search',
			'query' => $query,
			'scope' => $scope,
			'wiki_root' => $context['root_path'],
			'results' => $results,
			'searched_files' => count($files),
			'truncated' => $truncated,
		];
		if ($truncated) {
			$result['warning'] = 'Only the first ' . count($files) . ' files were searched. Use a more specific query, or list all pages with query "*".';
		}

		return $result;
	}

	/**
	 * Complete, paginated inventory of the wiki pages.
	 *
	 * @param array{root_path:string} $context
	 * @return array<string,mixed>
	 */
	private function listPages(Folder $wikiRoot, array $context, string $scope, int $offset, int $limit): array {
		$limit = $limit > 0 ? min(self::MAX_LIST_LIMIT, $limit) : self::DEFAULT_LIST_LIMIT;
		$offset = max(0, $offset);
		$truncated = false;
		$files = $this->collectTextFiles($wikiRoot, '', $scope, self::MAX_LIST_FILES, $truncated);
		usort($files, static fn (array $a, array $b): int => strcasecmp($a['path'], $b['path']));
		$pages = [];
		foreach (array_slice($files, $offset, $limit) as $entry) {
			/** @var File $file */
			$file = $entry['file'];
			$pages[] = [
				'path' => $entry['path'],
				'title' => $this->extractIndexTitle($entry['path'], (string)$file->getContent()),
				'size' => $file->getSize(),
			];
		}
		$nextOffset = $offset + count($pages);
		$hasMore = $nextOffset < count($files);

		$result = [
			'success' => true,
			'action' => 'list',
			'scope' => $scope,
			'wiki_root' => $context['root_path'],
			'pages' => $pages,
			'total' => count($files),
			'offset' => $offset,
			'has_more' => $hasMore,
			'next_offset' => $hasMore ? $nextOffset : null,
			'truncated' => $truncated,
		];
		if ($truncated) {
			$result['warning'] = 'The wiki has more than ' . self::MAX_LIST_FILES . ' files; only the first ' . self::MAX_LIST_FILES . ' are listed.';
		}

		return $result;
	}

	/**
	 * @return array{bot:Bot,owner_uid:string,root_path:string,visibility:string,location:string,collective_id:?int}
	 */
	private function resolveContext(int $botId, array $config = []): array {
		try {
			return $this->resolveContextUnchecked($botId, $config);
		} catch (WikiException $e) {
			throw $e;
		} catch (Exception $e) {
			throw $this->locationError($e, 'The wiki location is not available right now. Details are in the Nextcloud log.');
		}
	}

	/**
	 * Wiki root validation reports configuration problems the bot owner can fix with
	 * plain exceptions. Wrapped or foreign exceptions (database, Collectives) may carry
	 * internal details, so their message is only logged.
	 */
	private function locationError(Exception $e, string $fallback): WikiException {
		if (get_class($e) === Exception::class && $e->getPrevious() === null) {
			return new WikiException(WikiException::WIKI_UNAVAILABLE, $e->getMessage(), [], $e);
		}
		$this->logger->warning('Wiki location is not available', ['exception' => $e]);

		return new WikiException(WikiException::WIKI_UNAVAILABLE, $fallback, [], $e);
	}

	/**
	 * @return array{bot:Bot,owner_uid:string,root_path:string,visibility:string,location:string,collective_id:?int}
	 */
	private function resolveContextUnchecked(int $botId, array $config): array {
		$bot = $this->botMapper->findById($botId);
		$visibility = $bot->getVisibility() ?? 'groups';
		$location = isset($config['wiki_location']) && is_string($config['wiki_location']) ? trim($config['wiki_location']) : 'personal_files';
		if ($location === '') {
			$location = 'personal_files';
		}
		if (!in_array($location, ['personal_files', 'collective'], true)) {
			throw new WikiException(WikiException::WIKI_UNAVAILABLE, 'Unsupported wiki location.');
		}
		if ($visibility !== 'personal' && $visibility !== 'teams') {
			throw new WikiException(WikiException::WIKI_UNAVAILABLE, 'Wiki tools are only available for personal bots or matching team collective bots.');
		}
		if ($visibility === 'teams' && $location !== 'collective') {
			throw new WikiException(WikiException::WIKI_UNAVAILABLE, 'Team bots can use LLM Wiki only with a collective wiki location.');
		}
		$collectiveId = null;
		if ($location === 'collective') {
			$rawCollectiveId = $config['wiki_collective_id'] ?? null;
			if (!is_int($rawCollectiveId) && !(is_string($rawCollectiveId) && ctype_digit($rawCollectiveId))) {
				throw new WikiException(WikiException::WIKI_UNAVAILABLE, 'A valid collective must be selected for this wiki location.');
			}
			$collectiveId = (int)$rawCollectiveId;
			if ($collectiveId <= 0) {
				throw new WikiException(WikiException::WIKI_UNAVAILABLE, 'A valid collective must be selected for this wiki location.');
			}
			if ($visibility === 'teams' && !$this->collectiveMatchesBotTeam($collectiveId, $bot)) {
				throw new WikiException(WikiException::WIKI_UNAVAILABLE, 'Team bots can use only collectives from one of their selected teams.');
			}
		}
		$slug = $this->slugify($bot->getMentionName() !== '' ? $bot->getMentionName() : $bot->getBotName());
		if ($location === 'personal_files' && isset($config['wiki_root_path']) && is_string($config['wiki_root_path']) && trim($config['wiki_root_path']) !== '') {
			$rootPath = $this->normalizeWikiRootPath($config['wiki_root_path']);
		} elseif ($location === 'collective') {
			$rootPath = 'Collective #' . $collectiveId;
		} else {
			$rootPath = $this->wikiPathService->getDefaultPath($this->rootFolder->getUserFolder($bot->getUserId()), $slug);
		}

		return [
			'bot' => $bot,
			'owner_uid' => $bot->getUserId(),
			'root_path' => $rootPath,
			'visibility' => $visibility,
			'location' => $location,
			'collective_id' => $collectiveId,
		];
	}

	private function collectiveMatchesBotTeam(int $collectiveId, Bot $bot): bool {
		if ($this->wikiLocationService === null) {
			return false;
		}

		return $this->wikiLocationService->collectiveMatchesAnyTeam(
			$collectiveId,
			$bot->getUserId(),
			$this->decodeIdList($bot->getAllowedTeams())
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function decodeIdList(?string $json): array {
		if ($json === null || $json === '') {
			return [];
		}

		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}

		$result = [];
		foreach ($decoded as $value) {
			if (is_string($value) || is_numeric($value)) {
				$result[] = (string)$value;
			}
		}

		return $result;
	}

	/**
	 * @param array{owner_uid:string,root_path:string,visibility:string,bot:Bot,location:string,collective_id:?int} $context
	 */
	private function ensureWikiRoot(array $context): Folder {
		if ($context['location'] === 'collective') {
			if ($this->wikiLocationService === null || $context['collective_id'] === null) {
				throw new WikiException(WikiException::WIKI_UNAVAILABLE, 'Collective wiki locations are not available.');
			}
			try {
				$resolved = $this->wikiLocationService->resolveCollectiveWikiRoot($context['collective_id'], $context['owner_uid']);
			} catch (Exception $e) {
				throw $this->locationError($e, 'The selected collective cannot be used as wiki location right now: it may have been deleted, or the bot owner is no longer a collective admin with edit rights. Ask the bot owner to check the wiki settings.');
			}
			$context['root_path'] = $resolved['label'];
			$this->ensureDefaultFiles($resolved['folder'], $context);
			return $resolved['folder'];
		}

		$userFolder = $this->rootFolder->getUserFolder($context['owner_uid']);
		$wikiRoot = $this->ensureFolder($userFolder, $context['root_path']);
		$this->ensureDefaultFiles($wikiRoot, $context);
		return $wikiRoot;
	}

	/**
	 * @param array{root_path:string,visibility:string,bot:Bot,location:string} $context
	 */
	private function ensureDefaultFiles(Folder $wikiRoot, array $context): void {
		$defaults = [
			'index.md' => "# Index\n\nThis wiki is maintained by " . $this->brandingService->getDisplayName() . ".\n\n## Pages\n",
			'log.md' => "# Log\n\n## [" . date('Y-m-d H:i') . "] wiki | Initialized\n- Wiki root: " . $context['root_path'] . "\n- Location: " . $context['location'] . "\n- Visibility: " . $context['visibility'] . "\n",
			'schema.md' => $this->defaultSchema($context),
		];

		foreach ($defaults as $path => $content) {
			try {
				$node = $wikiRoot->get($path);
				if ($node instanceof File) {
					continue;
				}
			} catch (NotFoundException $e) {
				// Create below.
			}
			$this->writeRelativeFile($wikiRoot, $path, $content, 'create');
		}
	}

	/**
	 * @return array{count:int}
	 */
	private function syncIndexFile(Folder $wikiRoot): array {
		$truncated = false;
		$entries = $this->buildIndexEntries($wikiRoot, $truncated);
		$managedBlock = $this->buildManagedIndexBlock($entries, $truncated);

		try {
			$node = $wikiRoot->get('index.md');
			if (!$node instanceof File) {
				throw new Exception('Wiki index path exists but is not a file.');
			}
			$content = (string)$node->getContent();
		} catch (NotFoundException $e) {
			$content = "# Index\n\nThis wiki is maintained by " . $this->brandingService->getDisplayName() . ".\n";
		}

		$nextContent = $this->upsertManagedIndexBlock($content, $managedBlock);

		if ($nextContent !== $content) {
			$this->writeRelativeFile($wikiRoot, 'index.md', $nextContent, 'overwrite');
		}

		return ['count' => count($entries)];
	}

	private function upsertManagedIndexBlock(string $content, string $managedBlock): string {
		$replacement = "\n\n" . $managedBlock;
		$legacyMarkerPattern = '/\n*<!-- EDUC-AI-WIKI-FILE-INDEX:START -->\n?.*?\n?<!-- EDUC-AI-WIKI-FILE-INDEX:END -->/s';
		if (preg_match($legacyMarkerPattern, $content) === 1) {
			return rtrim(preg_replace($legacyMarkerPattern, $replacement, $content) ?? $content) . "\n";
		}

		$managedSectionPattern = '/\n*## Existing Files\n\nThis section is updated automatically when the wiki is initialized or changed\.\n\n.*?(?=\n## [^\n]+|\z)/s';
		if (preg_match($managedSectionPattern, $content) === 1) {
			return rtrim(preg_replace($managedSectionPattern, $replacement, $content) ?? $content) . "\n";
		}

		return rtrim($content) . "\n\n" . $managedBlock . "\n";
	}

	/**
	 * @return array<int,array{path:string,title:string,size:int}>
	 */
	private function buildIndexEntries(Folder $wikiRoot, bool &$truncated = false): array {
		$entries = [];
		foreach ($this->collectTextFiles($wikiRoot, '', 'all', self::MAX_INDEX_FILES, $truncated) as $entry) {
			$path = $entry['path'];
			if (in_array($path, ['index.md', 'log.md', 'schema.md'], true)) {
				continue;
			}
			/** @var File $file */
			$file = $entry['file'];
			$content = (string)$file->getContent();
			$entries[] = [
				'path' => $path,
				'title' => $this->extractIndexTitle($path, $content),
				'size' => $file->getSize(),
			];
		}

		usort($entries, static fn (array $a, array $b): int => strcasecmp($a['path'], $b['path']));
		return $entries;
	}

	/**
	 * @param array<int,array{path:string,title:string,size:int}> $entries
	 */
	private function buildManagedIndexBlock(array $entries, bool $truncated = false): string {
		$block = "## Existing Files\n\n";
		$block .= "This section is updated automatically when the wiki is initialized or changed.\n\n";
		if (count($entries) === 0) {
			$block .= "- No content files found yet.\n";
		} else {
			foreach ($entries as $entry) {
				$title = $entry['title'] !== '' ? $entry['title'] : $entry['path'];
				$block .= '- [' . $this->escapeMarkdownLinkText($title) . '](' . $this->encodeMarkdownLinkTarget($entry['path']) . ')';
				if ($title !== $entry['path']) {
					$block .= ' - `' . $entry['path'] . '`';
				}
				$block .= ' (' . $entry['size'] . " bytes)\n";
			}
			if ($truncated) {
				$block .= '- More files exist than listed here (limit ' . self::MAX_INDEX_FILES . ").\n";
			}
		}
		return rtrim($block);
	}

	private function extractIndexTitle(string $path, string $content): string {
		if (preg_match('/^\s*#\s+(.+)$/m', $content, $matches) === 1) {
			$title = trim($matches[1]);
			$title = preg_replace('/\s+#*$/', '', $title) ?? $title;
			return mb_substr($title, 0, 160);
		}

		$name = pathinfo($path, PATHINFO_FILENAME);
		$name = str_replace(['-', '_'], ' ', $name);
		$name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
		return $name !== '' ? mb_substr($name, 0, 160) : $path;
	}

	private function escapeMarkdownLinkText(string $value): string {
		return str_replace([']', '['], ['\]', '\['], $value);
	}

	private function encodeMarkdownLinkTarget(string $path): string {
		return str_replace([' ', ')', '('], ['%20', '%29', '%28'], $path);
	}

	/**
	 * @param array{visibility:string,bot:Bot} $context
	 */
	private function defaultSchema(array $context): string {
		return "# Wiki Schema\n\n"
			. "## Scope\n"
			. "- This is a personal wiki. Store durable personal knowledge only when the user asks for it.\n"
			. "- Raw sources are source material and should not be overwritten by wiki maintenance.\n\n"
			. "## Maintenance Rules\n"
			. "- Search the wiki before answering questions about durable knowledge.\n"
			. "- Keep `index.md` as the content-oriented map of the wiki: short summaries, topic/entity groupings, important pages, current synthesis, open questions, and useful entry points.\n"
			. "- Review `index.md` after accepted wiki updates and update the curated overview when new or changed pages affect navigation or synthesis.\n"
			. "- The `Existing Files` section in `index.md` is maintained automatically by " . $this->brandingService->getDisplayName() . " and should not be rewritten manually.\n"
			. "- Append a concise entry to `log.md` for every accepted wiki update.\n"
			. "- Mark contradictions and uncertainty instead of silently replacing claims.\n"
			. "- Use Markdown links between related pages.\n"
			. "- Wiki tools are only available for personal bots.\n";
	}

	private function normalizeWikiRootPath(string $path): string {
		return $this->wikiPathService->normalizeRootPath($path);
	}

	private function normalizePagePath(string $path): string {
		$path = trim(str_replace('\\', '/', $path));
		$path = ltrim($path, '/');
		if ($path === '') {
			throw new WikiException(WikiException::INVALID_PATH, 'Wiki path is required.');
		}
		if (str_contains($path, "\0")) {
			throw new WikiException(WikiException::INVALID_PATH, 'Wiki path contains an invalid character.');
		}

		$segments = explode('/', $path);
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new WikiException(WikiException::INVALID_PATH, 'Wiki path must not contain empty, current, or parent segments.');
			}
			if (str_starts_with($segment, '.')) {
				throw new WikiException(WikiException::INVALID_PATH, 'Wiki path must not target hidden/internal files.');
			}
		}

		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
			throw new WikiException(WikiException::INVALID_PATH, 'Wiki pages must be Markdown, TXT, or JSON files.');
		}

		return implode('/', $segments);
	}

	private function normalizeInternalPath(string $path): string {
		$path = trim(str_replace('\\', '/', $path));
		$path = ltrim($path, '/');
		if ($path === '' || str_contains($path, "\0")) {
			throw new WikiException(WikiException::INVALID_PATH, 'Invalid internal wiki path.');
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new WikiException(WikiException::INVALID_PATH, 'Invalid internal wiki path.');
			}
		}
		return $path;
	}

	private function normalizeWriteMode(string $mode): string {
		$mode = strtolower(trim($mode));
		if ($mode === 'propose') {
			throw new WikiException(WikiException::INVALID_ARGUMENT, 'Wiki change proposals are disabled. Use mode create, overwrite or append.');
		}
		if (!in_array($mode, ['create', 'overwrite', 'append'], true)) {
			return 'create';
		}
		return $mode;
	}

	private function assertTextSize(string $content): void {
		if (strlen($content) > self::MAX_FILE_BYTES) {
			throw new WikiException(WikiException::TOO_LARGE, 'Wiki page content is too large (maximum ' . self::MAX_FILE_BYTES . ' bytes). Split it into several pages.');
		}
	}

	private function writeRelativeFile(Folder $wikiRoot, string $path, string $content, string $mode): File {
		$path = str_starts_with($path, '.') ? $this->normalizeInternalPath($path) : $this->normalizePagePath($path);
		$parts = explode('/', $path);
		$fileName = array_pop($parts);
		if ($fileName === null || $fileName === '') {
			throw new WikiException(WikiException::INVALID_PATH, 'Target file name is required.');
		}
		$folder = $this->ensureFolder($wikiRoot, implode('/', $parts));

		$node = $this->getNodeOrNull($folder, $fileName);
		if ($node !== null) {
			if (!$node instanceof File) {
				throw new WikiException(WikiException::PATH_CONFLICT, 'Target path exists but is a folder, not a page: ' . $path, ['path' => $path]);
			}
			if ($mode === 'create') {
				throw $this->alreadyExists($path);
			}
			$newContent = $mode === 'append' ? (string)$node->getContent() . $content : $content;
			$this->assertTextSize($newContent);
			$node->putContent($newContent);
			$this->textSessionResetService->resetFileSession($node);
			$this->verifyContent($node, $newContent, $path);
			return $node;
		}

		if ($mode === 'append' && !str_starts_with($content, "# ")) {
			$content = ltrim($content);
		}
		$this->assertTextSize($content);
		$file = $folder->newFile($fileName);
		$file->putContent($content);
		$this->verifyContent($file, $content, $path);
		return $file;
	}

	/**
	 * Read the stored page back so a write is only reported once the content is really there.
	 */
	private function verifyContent(File $file, string $expected, string $path): void {
		if ((string)$file->getContent() !== $expected) {
			throw new WikiException(WikiException::VERIFICATION_FAILED, 'The page was saved, but reading it back returned different content: ' . $path . '. Read the page and check it before reporting success.', ['path' => $path]);
		}
	}

	private function ensureFolder(Folder $root, string $folderPath): Folder {
		$current = $root;
		$folderPath = trim($folderPath, '/');
		if ($folderPath === '') {
			return $current;
		}

		foreach (explode('/', $folderPath) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new WikiException(WikiException::INVALID_PATH, 'Invalid folder path segment.');
			}
			$node = $this->getNodeOrNull($current, $segment);
			if ($node === null) {
				$current = $current->newFolder($segment);
				continue;
			}
			if (!$node instanceof Folder) {
				throw new WikiException(WikiException::PATH_CONFLICT, 'Path segment exists as a file, not a folder: ' . $segment, ['segment' => $segment]);
			}
			$current = $node;
		}

		return $current;
	}

	/**
	 * @return array<int,array{file:File,path:string}>
	 */
	private function collectTextFiles(Folder $folder, string $prefix, string $scope, int $max = self::MAX_SEARCH_FILES, bool &$truncated = false): array {
		$result = [];
		$this->collectTextFilesInto($folder, $prefix, $scope, $max, $result, $truncated);
		return $result;
	}

	/**
	 * @param array<int,array{file:File,path:string}> $result
	 */
	private function collectTextFilesInto(Folder $folder, string $prefix, string $scope, int $max, array &$result, bool &$truncated): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($truncated) {
				return;
			}
			$name = method_exists($node, 'getName') ? (string)$node->getName() : '';
			if ($name === '' || str_starts_with($name, '.')) {
				continue;
			}
			$path = $prefix === '' ? $name : $prefix . '/' . $name;
			if ($scope === 'wiki' && str_starts_with($path, 'sources/')) {
				continue;
			}
			if ($scope === 'sources' && !str_starts_with($path, 'sources/')) {
				if ($node instanceof Folder && $path === 'sources') {
					// Continue into the sources folder below.
				} else {
					continue;
				}
			}

			if ($node instanceof Folder) {
				$this->collectTextFilesInto($node, $path, $scope, $max, $result, $truncated);
			} elseif ($node instanceof File && $this->isAllowedTextPath($path)) {
				if (count($result) >= $max) {
					$truncated = true;
					return;
				}
				$result[] = ['file' => $node, 'path' => $path];
			}
		}
	}

	/**
	 * Translate file-system failures into wiki errors the model can act on.
	 *
	 * @template T
	 * @param callable():T $operation
	 * @return T
	 */
	private function withWikiErrors(callable $operation): mixed {
		try {
			return $operation();
		} catch (WikiException $e) {
			throw $e;
		} catch (NotPermittedException $e) {
			throw new WikiException(WikiException::FORBIDDEN, 'The wiki owner has no permission for this page or folder.', [], $e);
		} catch (LockedException $e) {
			throw new WikiException(WikiException::LOCKED, 'The page is being edited or synchronised right now. Try again in a moment.', [], $e);
		} catch (Throwable $e) {
			$this->logger->error('Wiki operation failed', ['exception' => $e]);
			throw new WikiException(WikiException::OPERATION_FAILED, 'The wiki operation failed unexpectedly. Details are in the Nextcloud log.', [], $e);
		}
	}

	private function getNodeOrNull(Folder $folder, string $path): ?Node {
		try {
			return $folder->get($path);
		} catch (NotFoundException $e) {
			return null;
		}
	}

	/**
	 * Collectives keeps the content of a page with subpages in <page>/Readme.md.
	 */
	private function collectiveParentPagePath(string $pagePath): ?string {
		// Collectives reserves exactly "Readme.md"; a page called README.md is an ordinary page.
		if (strtolower(pathinfo($pagePath, PATHINFO_EXTENSION)) !== 'md' || basename($pagePath) === self::COLLECTIVE_PARENT_PAGE) {
			return null;
		}

		return substr($pagePath, 0, -3) . '/' . self::COLLECTIVE_PARENT_PAGE;
	}

	/**
	 * The page above a Collectives page: <folder>/Readme.md, or null at the top level.
	 * A folder page (topic/Readme.md) belongs to the folder above its own.
	 */
	private function collectiveParentOf(string $pagePath): ?string {
		$folders = explode('/', $pagePath);
		$name = array_pop($folders);
		if ($name === self::COLLECTIVE_PARENT_PAGE) {
			array_pop($folders);
		}

		return $folders === [] ? null : implode('/', $folders) . '/' . self::COLLECTIVE_PARENT_PAGE;
	}

	/**
	 * Plan the layout Collectives expects, without changing anything yet:
	 * - topic.md has to become topic/Readme.md when a subpage topic/x.md is written;
	 * - writing topic.md while topic/ already holds subpages targets topic/Readme.md.
	 * Existing duplicates are reported, never merged automatically.
	 *
	 * @return array{path:string,parent:?string,moves:list<array{from:string,to:string}>,warnings:list<string>}
	 */
	private function planCollectivePage(Folder $wikiRoot, string $pagePath): array {
		$segments = explode('/', $pagePath);
		array_pop($segments);
		if (strtolower(pathinfo($pagePath, PATHINFO_EXTENSION)) !== 'md') {
			return ['path' => $pagePath, 'parent' => null, 'moves' => [], 'warnings' => []];
		}

		$moves = [];
		$warnings = [];
		$prefix = '';
		foreach ($segments as $segment) {
			if ($segment . '.md' === self::COLLECTIVE_PARENT_PAGE) {
				throw new WikiException(
					WikiException::INVALID_PATH,
					'"Readme" is reserved for the page of a Collectives folder and cannot be a folder name. Write the page one level up instead.',
					['path' => $pagePath]
				);
			}
			$folderPath = $prefix === '' ? $segment : $prefix . '/' . $segment;
			if ($this->getNodeOrNull($wikiRoot, $folderPath . '.md') instanceof File) {
				$parentPage = $folderPath . '/' . self::COLLECTIVE_PARENT_PAGE;
				if ($this->getNodeOrNull($wikiRoot, $parentPage) === null) {
					$moves[] = ['from' => $folderPath . '.md', 'to' => $parentPage];
				} else {
					$warnings[] = 'Both ' . $folderPath . '.md and ' . $parentPage . ' exist, so Collectives shows two pages named "' . $segment . '". Merge them or ask the user.';
				}
			}
			$prefix = $folderPath;
		}

		$target = $pagePath;
		$parentPage = $this->collectiveParentPagePath($pagePath);
		if ($parentPage !== null && $this->getNodeOrNull($wikiRoot, substr($pagePath, 0, -3)) instanceof Folder) {
			if ($this->getNodeOrNull($wikiRoot, $pagePath) instanceof File) {
				$warnings[] = 'Both ' . $pagePath . ' and ' . $parentPage . ' exist, so Collectives shows two pages with this name. Merge them or ask the user.';
			} else {
				$target = $parentPage;
			}
		}

		return ['path' => $target, 'parent' => $this->collectiveParentOf($target), 'moves' => $moves, 'warnings' => $warnings];
	}

	/**
	 * Everything that can reject a write is checked before the first change, so a
	 * rejected write never leaves moved pages behind.
	 *
	 * @param list<array{from:string,to:string}> $moves
	 */
	private function preflightWrite(Folder $wikiRoot, string $target, array $moves, string $mode): void {
		$folders = explode('/', $target);
		array_pop($folders);
		$prefix = '';
		foreach ($folders as $segment) {
			$prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
			$node = $this->getNodeOrNull($wikiRoot, $prefix);
			if ($node !== null && !$node instanceof Folder) {
				throw new WikiException(WikiException::PATH_CONFLICT, 'Path segment exists as a file, not a folder: ' . $segment, ['segment' => $segment]);
			}
		}

		$node = $this->getNodeOrNull($wikiRoot, $target);
		if ($node instanceof Folder) {
			throw new WikiException(WikiException::PATH_CONFLICT, 'Target path exists but is a folder, not a page: ' . $target, ['path' => $target]);
		}
		$exists = $node !== null || in_array($target, array_column($moves, 'to'), true);
		if ($exists && $mode === 'create') {
			throw $this->alreadyExists($target);
		}
		if (!$exists) {
			$this->assertNoNearDuplicate($wikiRoot, $target);
		}
	}

	private function alreadyExists(string $path): WikiException {
		return new WikiException(
			WikiException::ALREADY_EXISTS,
			'The page already exists: ' . $path . '. Read it with wiki_read_page, merge your changes, then write it again with mode "overwrite" (or "append" to add text at the end).',
			['path' => $path]
		);
	}

	/**
	 * Refuse a new page or folder next to one whose name only differs in case, spaces,
	 * hyphens or underscores ("General Orientation.md" and "general-orientation.md"):
	 * in Collectives both would show up as separate pages with the same title.
	 */
	private function assertNoNearDuplicate(Folder $wikiRoot, string $path): void {
		$segments = explode('/', $path);
		$current = $wikiRoot;
		foreach ($segments as $index => $segment) {
			$node = $this->getNodeOrNull($current, $segment);
			if ($node instanceof Folder) {
				$current = $node;
				continue;
			}
			if ($node !== null) {
				return;
			}

			$isFile = $index === count($segments) - 1;
			$similar = $this->findNearDuplicate($current, $segment, $isFile);
			if ($similar !== null) {
				$existing = implode('/', array_merge(array_slice($segments, 0, $index), [$similar]));
				throw new WikiException(
					WikiException::SIMILAR_PAGE_EXISTS,
					$isFile
						? 'A page with a very similar name already exists: ' . $existing . '. Update that page instead (read it, then write it with mode "overwrite"), or choose a clearly different name.'
						: 'A page or folder with a very similar name already exists: ' . $existing . '. Use that name in the path, or choose a clearly different name.',
					['path' => $path, 'existing' => $existing]
				);
			}
			// Nothing below a missing segment exists either.
			return;
		}
	}

	private function findNearDuplicate(Folder $folder, string $name, bool $isFile): ?string {
		$stem = $isFile ? pathinfo($name, PATHINFO_FILENAME) : $name;
		$key = $this->nameKey($stem);
		foreach ($folder->getDirectoryListing() as $node) {
			$nodeName = (string)$node->getName();
			if ($nodeName === '' || str_starts_with($nodeName, '.') || $nodeName === self::COLLECTIVE_PARENT_PAGE) {
				continue;
			}
			if (!$node instanceof Folder && !$this->isAllowedTextPath($nodeName)) {
				continue;
			}
			$nodeStem = $node instanceof Folder ? $nodeName : pathinfo($nodeName, PATHINFO_FILENAME);
			// The same name in the other layout (topic.md / topic/) is the same page, not a duplicate.
			if ($nodeStem !== $stem && $this->nameKey($nodeStem) === $key) {
				return $nodeName;
			}
		}

		return null;
	}

	private function nameKey(string $name): string {
		return trim((string)preg_replace('/[\s_\-]+/u', ' ', mb_strtolower($name)));
	}

	/**
	 * Turn topic.md into topic/Readme.md (Node::move keeps the file id, like Collectives'
	 * own page-to-folder transition). Relative links move one level down with the page,
	 * so they are rewritten to keep their targets.
	 *
	 * @param list<array{from:string,to:string}> $moves
	 * @return list<array{from:string,to:string,links_rebased:int,broken:list<array{text:string,target:string}>}>
	 */
	private function applyCollectiveMoves(Folder $wikiRoot, array $moves): array {
		$done = [];
		foreach ($moves as $move) {
			$leaf = $this->getNodeOrNull($wikiRoot, $move['from']);
			if (!$leaf instanceof File) {
				continue;
			}
			$folder = $this->ensureFolder($wikiRoot, dirname($move['to']));
			$leaf->move($folder->getPath() . '/' . self::COLLECTIVE_PARENT_PAGE);
			$moved = $this->getNodeOrNull($wikiRoot, $move['to']);
			$rebased = 0;
			$broken = [];
			if ($moved instanceof File) {
				$content = (string)$moved->getContent();
				[$rebasedContent, $rebased] = $this->rebaseRelativeLinks($content);
				if ($rebased > 0) {
					$moved->putContent($rebasedContent);
					$this->textSessionResetService->resetFileSession($moved);
					$this->verifyContent($moved, $rebasedContent, $move['to']);
					$content = $rebasedContent;
				}
				$broken = $this->auditInternalLinks($wikiRoot, $move['to'], $content, true)['broken'];
			}
			$done[] = ['from' => $move['from'], 'to' => $move['to'], 'links_rebased' => $rebased, 'broken' => $broken];
		}

		return $done;
	}

	private function linkBaseHint(string $pagePath): string {
		$folder = dirname($pagePath);
		$base = $folder === '.' || $folder === ''
			? 'Relative links are resolved from the top level of the wiki.'
			: 'Relative links are resolved from the folder "' . $folder . '/" of this page, so a page in the same folder is linked by its file name only.';

		return 'If you create the missing pages next, keep these links as they are; otherwise fix them (use suggested_target where given). ' . $base;
	}

	/**
	 * Markdown without fenced code blocks: links inside code are examples, not links.
	 */
	private function withoutFencedCode(string $content): string {
		$lines = explode("\n", $content);
		foreach ($this->fencedLines($lines) as $index) {
			$lines[$index] = '';
		}
		return implode("\n", $lines);
	}

	/**
	 * @param list<string> $lines
	 * @return list<int> Indexes of fence lines and the lines between them
	 */
	private function fencedLines(array $lines): array {
		$fenced = [];
		$inFence = false;
		foreach ($lines as $index => $line) {
			$isFence = preg_match('/^\s*(```|~~~)/', $line) === 1;
			if ($inFence || $isFence) {
				$fenced[] = $index;
			}
			if ($isFence) {
				$inFence = !$inFence;
			}
		}
		return $fenced;
	}

	/**
	 * Prefix relative link and image targets with ../ (outside fenced code blocks).
	 *
	 * @return array{0:string,1:int}
	 */
	private function rebaseRelativeLinks(string $content): array {
		$count = 0;
		$lines = explode("\n", $content);
		$fenced = array_flip($this->fencedLines($lines));
		foreach ($lines as $index => $line) {
			if (isset($fenced[$index])) {
				continue;
			}
			$lines[$index] = (string)preg_replace_callback('/(!?\[[^\]\n]{0,200}\]\(\s*<?)([^)\s>]+)/u', static function (array $match) use (&$count): string {
				if (preg_match('/^(?:[a-z][a-z0-9+.\-]*:|#|\/|\?)/i', $match[2]) === 1) {
					return $match[0];
				}
				$count++;
				return $match[1] . '../' . $match[2];
			}, $line);
		}

		return [implode("\n", $lines), $count];
	}

	/**
	 * Check relative Markdown links of a page against the wiki contents.
	 *
	 * @return array{checked:int,broken:list<array{text:string,target:string}>}
	 */
	private function auditInternalLinks(Folder $wikiRoot, string $pagePath, string $content, bool $collective): array {
		$checked = 0;
		$broken = [];
		$plain = $this->withoutFencedCode($content);
		if (preg_match_all('/(?<!!)\[([^\]\n]{0,200})\]\(\s*(<[^>\n]*>|[^)\n]*?)(?:\s+"[^"\n]*")?\s*\)/u', $plain, $matches, PREG_SET_ORDER) === false) {
			return ['checked' => 0, 'broken' => []];
		}
		$baseSegments = explode('/', $pagePath);
		array_pop($baseSegments);

		foreach ($matches as $match) {
			$raw = trim($match[2]);
			$angled = str_starts_with($raw, '<') && str_ends_with($raw, '>');
			$target = $angled ? substr($raw, 1, -1) : $raw;
			// External URLs, anchors, absolute app links and mail links are not wiki pages.
			if ($target === '' || preg_match('/^(?:[a-z][a-z0-9+.\-]*:|#|\/|\?)/i', $target) === 1) {
				continue;
			}
			$path = rawurldecode(preg_replace('/[?#].*$/', '', $target) ?? $target);
			if ($path === '') {
				continue;
			}
			$checked++;
			// Markdown does not treat a target with unencoded spaces as a link.
			$malformed = !$angled && preg_match('/\s/u', $target) === 1;
			if (($malformed || !$this->linkTargetExists($wikiRoot, $baseSegments, $path, $collective)) && count($broken) < self::MAX_REPORTED_BROKEN_LINKS) {
				$broken[] = ['text' => mb_substr($match[1], 0, 120), 'target' => mb_substr($target, 0, 300), 'path' => $path, 'malformed' => $malformed];
			}
		}
		// Nextcloud Text and Collectives do not render wiki-style [[Page]] links.
		if (preg_match_all('/\[\[([^\[\]\n|]{1,200})(?:\|[^\]\n]*)?\]\]/u', $plain, $wikiLinks, PREG_SET_ORDER) > 0) {
			foreach ($wikiLinks as $wikiLink) {
				$checked++;
				$name = trim($wikiLink[1]);
				if (count($broken) < self::MAX_REPORTED_BROKEN_LINKS) {
					$broken[] = ['text' => mb_substr($name, 0, 120), 'target' => mb_substr($wikiLink[0], 0, 300), 'path' => $name, 'malformed' => false, 'wikilink' => true];
				}
			}
		}

		return ['checked' => $checked, 'broken' => $this->suggestLinkTargets($wikiRoot, $baseSegments, $broken)];
	}

	/**
	 * Where exactly one page with the linked file name exists elsewhere, suggest the
	 * relative link to it; models often get the folder part of a link wrong.
	 *
	 * @param list<string> $baseSegments
	 * @param list<array{text:string,target:string,path:string,malformed:bool,wikilink?:bool}> $broken
	 * @return list<array{text:string,target:string,problem?:string,suggested_target?:string}>
	 */
	private function suggestLinkTargets(Folder $wikiRoot, array $baseSegments, array $broken): array {
		if ($broken === []) {
			return [];
		}
		$byName = [];
		foreach ($this->collectTextFiles($wikiRoot, '', 'all') as $entry) {
			$byName[$this->nameKey(pathinfo($entry['path'], PATHINFO_FILENAME))][] = $entry['path'];
		}

		$result = [];
		foreach ($broken as $link) {
			$entry = ['text' => $link['text'], 'target' => $link['target']];
			if ($link['malformed']) {
				$entry['problem'] = 'Spaces in a link target must be written as %20.';
			}
			if ($link['wikilink'] ?? false) {
				$entry['problem'] = 'Nextcloud does not show [[...]] as a link; write a Markdown link [text](target.md).';
			}
			$name = basename($link['path']);
			$candidates = $byName[$this->nameKey(pathinfo($name, PATHINFO_FILENAME))] ?? [];
			if (count($candidates) === 1) {
				$entry['suggested_target'] = str_replace(' ', '%20', $this->relativeLink($baseSegments, $candidates[0]));
			}
			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * @param list<string> $baseSegments Folder of the linking page
	 */
	private function relativeLink(array $baseSegments, string $targetPath): string {
		$target = explode('/', $targetPath);
		$common = 0;
		while ($common < count($baseSegments) && $common < count($target) - 1 && $baseSegments[$common] === $target[$common]) {
			$common++;
		}

		return str_repeat('../', count($baseSegments) - $common) . implode('/', array_slice($target, $common));
	}

	/**
	 * @param list<string> $baseSegments
	 */
	private function linkTargetExists(Folder $wikiRoot, array $baseSegments, string $path, bool $collective): bool {
		$segments = $baseSegments;
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				if ($segments === []) {
					return false;
				}
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}
		if ($segments === []) {
			return true;
		}
		$resolved = implode('/', $segments);
		$candidates = [$resolved];
		if (pathinfo($resolved, PATHINFO_EXTENSION) === '') {
			$candidates[] = $resolved . '.md';
		}
		if ($collective && ($parentPage = $this->collectiveParentPagePath(str_ends_with(strtolower($resolved), '.md') ? $resolved : $resolved . '.md')) !== null) {
			$candidates[] = $parentPage;
		}
		foreach ($candidates as $candidate) {
			if ($this->getNodeOrNull($wikiRoot, $candidate) !== null) {
				return true;
			}
		}

		return false;
	}

	private function isAllowedTextPath(string $path): bool {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($extension, self::ALLOWED_EXTENSIONS, true);
	}

	private function buildSnippet(string $content, string $query): string {
		$lowerContent = mb_strtolower($content);
		$pos = mb_strpos($lowerContent, mb_strtolower($query));
		if ($pos === false) {
			return mb_substr(trim($content), 0, 240);
		}
		$start = max(0, $pos - 80);
		$snippet = mb_substr($content, $start, 240);
		return trim(($start > 0 ? '...' : '') . $snippet);
	}

	private function slugify(string $value): string {
		$value = strtolower(trim($value));
		$value = ltrim($value, '@');
		$value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? 'wiki';
		$value = trim($value, '-_');
		return $value !== '' ? $value : 'wiki';
	}

	private function sanitizeLogTitle(string $title): string {
		$title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
		return $title !== '' ? mb_substr($title, 0, 160) : 'wiki update';
	}
}
