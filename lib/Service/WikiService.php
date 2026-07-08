<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

class WikiService {
	private const MAX_FILE_BYTES = 524288;
	private const MAX_SEARCH_FILES = 200;
	private const DEFAULT_READ_LIMIT = 3000;
	private const MAX_READ_LIMIT = 3500;
	private const ALLOWED_EXTENSIONS = ['md', 'txt', 'json'];

	private IRootFolder $rootFolder;
	private BotMapper $botMapper;
	private LoggerInterface $logger;
	private ?WikiLocationService $wikiLocationService;
	private TextSessionResetService $textSessionResetService;

	public function __construct(
		IRootFolder $rootFolder,
		BotMapper $botMapper,
		LoggerInterface $logger,
		?WikiLocationService $wikiLocationService = null,
		?TextSessionResetService $textSessionResetService = null
	) {
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

		$context = $this->resolveContext($botId, $config);
		$pagePath = $this->normalizePagePath($path);
		$wikiRoot = $this->ensureWikiRoot($context);

		try {
			$node = $wikiRoot->get($pagePath);
		} catch (NotFoundException $e) {
			throw new Exception('Wiki page not found: ' . $pagePath);
		}

		if (!$node instanceof File) {
			throw new Exception('Wiki path is not a file: ' . $pagePath);
		}

		$content = (string)$node->getContent();
		$offset = max(0, $offset);
		$limit = max(1, min(self::MAX_READ_LIMIT, $limit));
		$totalLength = mb_strlen($content, 'UTF-8');
		$slice = mb_substr($content, $offset, $limit, 'UTF-8');
		$returnedLength = mb_strlen($slice, 'UTF-8');
		$nextOffset = $offset + $returnedLength;
		$hasMore = $nextOffset < $totalLength;

		return [
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
	}

	/**
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	public function writePage(int $botId, string $path, string $content, string $mode = 'create', ?string $reason = null, array $config = []): array {
		$context = $this->resolveContext($botId, $config);
		$pagePath = $this->normalizePagePath($path);
		$this->assertTextSize($content);
		$mode = $this->normalizeWriteMode($mode);
		$wikiRoot = $this->ensureWikiRoot($context);

		$file = $this->writeRelativeFile($wikiRoot, $pagePath, $content, $mode);
		$this->syncIndexForRoot($wikiRoot);
		return [
			'success' => true,
			'action' => $mode === 'append' ? 'appended' : 'written',
			'path' => $pagePath,
			'wiki_root' => $context['root_path'],
			'file_id' => $file->getId(),
			'size' => $file->getSize(),
		];
	}

	/**
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	public function logEvent(int $botId, string $title, string $details = '', array $config = []): array {
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
		];
	}

	/**
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	public function search(int $botId, string $query, int $limit = 5, string $scope = 'wiki', array $config = []): array {
		$query = trim($query);
		if ($query === '') {
			throw new Exception('query parameter is required for wiki search.');
		}

		$context = $this->resolveContext($botId, $config);
		$wikiRoot = $this->ensureWikiRoot($context);
		$limit = max(1, min(20, $limit));
		$scope = in_array($scope, ['wiki', 'sources', 'all'], true) ? $scope : 'wiki';
		$files = $this->collectTextFiles($wikiRoot, '', $scope);
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

		return [
			'success' => true,
			'action' => 'search',
			'query' => $query,
			'scope' => $scope,
			'wiki_root' => $context['root_path'],
			'results' => $results,
			'searched_files' => count($files),
		];
	}

	/**
	 * @return array{bot:Bot,owner_uid:string,root_path:string,visibility:string,location:string,collective_id:?int}
	 */
	private function resolveContext(int $botId, array $config = []): array {
		$bot = $this->botMapper->findById($botId);
		$visibility = $bot->getVisibility() ?? 'groups';
		$location = isset($config['wiki_location']) && is_string($config['wiki_location']) ? trim($config['wiki_location']) : 'personal_files';
		if ($location === '') {
			$location = 'personal_files';
		}
		if (!in_array($location, ['personal_files', 'collective'], true)) {
			throw new Exception('Unsupported wiki location.');
		}
		if ($visibility !== 'personal' && $visibility !== 'teams') {
			throw new Exception('Wiki tools are only available for personal bots or matching team collective bots.');
		}
		if ($visibility === 'teams' && $location !== 'collective') {
			throw new Exception('Team bots can use LLM Wiki only with a collective wiki location.');
		}
		$collectiveId = null;
		if ($location === 'collective') {
			$rawCollectiveId = $config['wiki_collective_id'] ?? null;
			if (!is_int($rawCollectiveId) && !(is_string($rawCollectiveId) && ctype_digit($rawCollectiveId))) {
				throw new Exception('A valid collective must be selected for this wiki location.');
			}
			$collectiveId = (int)$rawCollectiveId;
			if ($collectiveId <= 0) {
				throw new Exception('A valid collective must be selected for this wiki location.');
			}
			if ($visibility === 'teams' && !$this->collectiveMatchesBotTeam($collectiveId, $bot)) {
				throw new Exception('Team bots can use only collectives from one of their selected teams.');
			}
		}
		$slug = $this->slugify($bot->getMentionName() !== '' ? $bot->getMentionName() : $bot->getBotName());
		$rootPath = \OCA\EducAI\AppInfo\Application::WIKI_ROOT_FOLDER . '/Personal Wikis/' . $slug;
		if ($location === 'personal_files' && isset($config['wiki_root_path']) && is_string($config['wiki_root_path']) && trim($config['wiki_root_path']) !== '') {
			$rootPath = $this->normalizeWikiRootPath($config['wiki_root_path']);
		} elseif ($location === 'collective') {
			$rootPath = 'Collective #' . $collectiveId;
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
				throw new Exception('Collective wiki locations are not available.');
			}
			$resolved = $this->wikiLocationService->resolveCollectiveWikiRoot($context['collective_id'], $context['owner_uid']);
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
			'index.md' => "# Index\n\nThis wiki is maintained by " . \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME . ".\n\n## Pages\n",
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
		$entries = $this->buildIndexEntries($wikiRoot);
		$managedBlock = $this->buildManagedIndexBlock($entries);

		try {
			$node = $wikiRoot->get('index.md');
			if (!$node instanceof File) {
				throw new Exception('Wiki index path exists but is not a file.');
			}
			$content = (string)$node->getContent();
		} catch (NotFoundException $e) {
			$content = "# Index\n\nThis wiki is maintained by " . \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME . ".\n";
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
	private function buildIndexEntries(Folder $wikiRoot): array {
		$entries = [];
		foreach ($this->collectTextFiles($wikiRoot, '', 'all') as $entry) {
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
	private function buildManagedIndexBlock(array $entries): string {
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
			. "- The `Existing Files` section in `index.md` is maintained automatically by " . \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME . " and should not be rewritten manually.\n"
			. "- Append a concise entry to `log.md` for every accepted wiki update.\n"
			. "- Mark contradictions and uncertainty instead of silently replacing claims.\n"
			. "- Use Markdown links between related pages.\n"
			. "- Wiki tools are only available for personal bots.\n";
	}

	private function normalizeWikiRootPath(string $path): string {
		$path = trim(str_replace('\\', '/', $path));
		if ($path === '') {
			throw new Exception('Wiki root path is required.');
		}
		if (str_starts_with($path, '/')) {
			throw new Exception('Wiki root path must be relative.');
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
			throw new Exception('Wiki root path contains an invalid character.');
		}

		$path = trim($path, '/');
		if (!str_starts_with($path, \OCA\EducAI\AppInfo\Application::WIKI_ROOT_FOLDER . '/')) {
			throw new Exception('Wiki root path must start with ' . \OCA\EducAI\AppInfo\Application::WIKI_ROOT_FOLDER . '/.');
		}
		if (strlen($path) > 512) {
			throw new Exception('Wiki root path is too long.');
		}

		$segments = explode('/', $path);
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new Exception('Wiki root path must not contain empty, current, or parent segments.');
			}
			if (str_starts_with($segment, '.')) {
				throw new Exception('Wiki root path must not target hidden/internal folders.');
			}
		}

		return implode('/', $segments);
	}

	private function normalizePagePath(string $path): string {
		$path = trim(str_replace('\\', '/', $path));
		$path = ltrim($path, '/');
		if ($path === '') {
			throw new Exception('Wiki path is required.');
		}
		if (str_contains($path, "\0")) {
			throw new Exception('Wiki path contains an invalid character.');
		}

		$segments = explode('/', $path);
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new Exception('Wiki path must not contain empty, current, or parent segments.');
			}
			if (str_starts_with($segment, '.')) {
				throw new Exception('Wiki path must not target hidden/internal files.');
			}
		}

		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
			throw new Exception('Wiki pages must be Markdown, TXT, or JSON files.');
		}

		return implode('/', $segments);
	}

	private function normalizeInternalPath(string $path): string {
		$path = trim(str_replace('\\', '/', $path));
		$path = ltrim($path, '/');
		if ($path === '' || str_contains($path, "\0")) {
			throw new Exception('Invalid internal wiki path.');
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new Exception('Invalid internal wiki path.');
			}
		}
		return $path;
	}

	private function normalizeWriteMode(string $mode): string {
		$mode = strtolower(trim($mode));
		if ($mode === 'propose') {
			throw new Exception('Wiki change proposals are disabled. Wiki tools are only available for personal bots.');
		}
		if (!in_array($mode, ['create', 'overwrite', 'append'], true)) {
			return 'create';
		}
		return $mode;
	}

	private function assertTextSize(string $content): void {
		if (strlen($content) > self::MAX_FILE_BYTES) {
			throw new Exception('Wiki page content is too large for the V1 text writer.');
		}
	}

	private function writeRelativeFile(Folder $wikiRoot, string $path, string $content, string $mode): File {
		$path = str_starts_with($path, '.') ? $this->normalizeInternalPath($path) : $this->normalizePagePath($path);
		$parts = explode('/', $path);
		$fileName = array_pop($parts);
		if ($fileName === null || $fileName === '') {
			throw new Exception('Target file name is required.');
		}
		$folder = $this->ensureFolder($wikiRoot, implode('/', $parts));

		try {
			$node = $folder->get($fileName);
			if (!$node instanceof File) {
				throw new Exception('Target path exists but is not a file.');
			}
			if ($mode === 'create') {
				throw new Exception('File already exists and overwrite is false: ' . $path);
			}
			$newContent = $mode === 'append' ? (string)$node->getContent() . $content : $content;
			$this->assertTextSize($newContent);
			$node->putContent($newContent);
			$this->textSessionResetService->resetFileSession($node);
			return $node;
		} catch (NotFoundException $e) {
			if ($mode === 'append' && !str_starts_with($content, "# ")) {
				$content = ltrim($content);
			}
			$this->assertTextSize($content);
			$file = $folder->newFile($fileName);
			$file->putContent($content);
			return $file;
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
				throw new Exception('Invalid folder path segment.');
			}
			try {
				$node = $current->get($segment);
				if (!$node instanceof Folder) {
					throw new Exception('Path segment exists but is not a folder: ' . $segment);
				}
				$current = $node;
			} catch (NotFoundException $e) {
				$current = $current->newFolder($segment);
			}
		}

		return $current;
	}

	/**
	 * @return array<int,array{file:File,path:string}>
	 */
	private function collectTextFiles(Folder $folder, string $prefix, string $scope): array {
		$result = [];
		foreach ($folder->getDirectoryListing() as $node) {
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
				$result = array_merge($result, $this->collectTextFiles($node, $path, $scope));
			} elseif ($node instanceof File && $this->isAllowedTextPath($path)) {
				$result[] = ['file' => $node, 'path' => $path];
			}

			if (count($result) >= self::MAX_SEARCH_FILES) {
				return array_slice($result, 0, self::MAX_SEARCH_FILES);
			}
		}
		return $result;
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
