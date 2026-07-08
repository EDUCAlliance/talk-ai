<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotTool;
use OCA\EducAI\Db\BotToolMapper;
use OCA\EducAI\Db\WikiRoot;
use OCA\EducAI\Db\WikiRootBot;
use OCA\EducAI\Db\WikiRootBotMapper;
use OCA\EducAI\Db\WikiRootMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class WikiRootRegistryService {
	private BotMapper $botMapper;
	private BotToolMapper $botToolMapper;
	private WikiRootMapper $rootMapper;
	private WikiRootBotMapper $rootBotMapper;
	private IRootFolder $rootFolder;
	private WikiLocationService $wikiLocationService;
	private LoggerInterface $logger;

	public function __construct(
		BotMapper $botMapper,
		BotToolMapper $botToolMapper,
		WikiRootMapper $rootMapper,
		WikiRootBotMapper $rootBotMapper,
		IRootFolder $rootFolder,
		WikiLocationService $wikiLocationService,
		LoggerInterface $logger
	) {
		$this->botMapper = $botMapper;
		$this->botToolMapper = $botToolMapper;
		$this->rootMapper = $rootMapper;
		$this->rootBotMapper = $rootBotMapper;
		$this->rootFolder = $rootFolder;
		$this->wikiLocationService = $wikiLocationService;
		$this->logger = $logger;
	}

	/**
	 * @param array<string,mixed> $wikiConfig
	 */
	public function refreshBot(Bot $bot, array $wikiConfig): void {
		$visibility = $bot->getVisibility() ?? 'groups';
		if (!in_array($visibility, ['personal', 'teams'], true)) {
			$this->deactivateBot($bot->getId());
			return;
		}

		$context = $this->resolveWikiRoot($bot, $wikiConfig);
		$root = $this->upsertRoot($context['folder'], $context['location'], $context['collective_id']);
		$now = time();
		$configHash = hash('sha256', json_encode($this->normalizeConfigForHash($wikiConfig)) ?: '{}');
		$assignment = $this->rootBotMapper->findByBotId($bot->getId());
		if ($assignment === null) {
			$assignment = new WikiRootBot();
			$assignment->setBotId($bot->getId());
			$assignment->setCreatedAt($now);
		}
		$assignment->setRootId($root->getId());
		$assignment->setConfigHash($configHash);
		$assignment->setActive(true);
		$assignment->setUpdatedAt($now);

		if ($assignment->getId() === null) {
			$this->rootBotMapper->insert($assignment);
		} else {
			$this->rootBotMapper->update($assignment);
		}
	}

	public function deactivateBot(int $botId): void {
		$this->rootBotMapper->deactivateByBotId($botId);
	}

	/**
	 * @param array<int,int> $nodeIds
	 * @return array<int,WikiRoot>
	 */
	public function findRootsForNodeAncestors(array $nodeIds): array {
		return $this->rootMapper->findActiveByRootNodeIds($nodeIds);
	}

	/**
	 * @return array{refreshed:int,failed:int}
	 */
	public function rebuildAll(): array {
		$refreshed = 0;
		$failed = 0;
		foreach ($this->groupWikiAssignmentsByBot($this->botToolMapper->findByBuiltInToolNames(BuiltInToolProvider::WIKI_TOOLS)) as $botId => $config) {
			try {
				$bot = $this->botMapper->findById($botId);
				$this->refreshBot($bot, $config);
				$refreshed++;
			} catch (DoesNotExistException $e) {
				$this->deactivateBot($botId);
			} catch (Exception $e) {
				$failed++;
				$this->logger->warning('Failed to rebuild wiki root registry entry', [
					'bot_id' => $botId,
					'exception' => $e,
				]);
			}
		}

		return [
			'refreshed' => $refreshed,
			'failed' => $failed,
		];
	}

	/**
	 * @return array{folder:Folder,location:string,collective_id:?int}
	 */
	private function resolveWikiRoot(Bot $bot, array $config): array {
		$location = isset($config['wiki_location']) && is_string($config['wiki_location'])
			? trim($config['wiki_location'])
			: 'personal_files';
		if ($location === '') {
			$location = 'personal_files';
		}
		$visibility = $bot->getVisibility() ?? 'groups';
		if ($visibility === 'teams' && $location !== 'collective') {
			throw new Exception('Team bots can use LLM Wiki only with a collective wiki location.');
		}
		if ($visibility !== 'personal' && $visibility !== 'teams') {
			throw new Exception('Wiki root registry is available only for personal bots or matching team collective bots.');
		}

		if ($location === 'collective') {
			$collectiveId = $config['wiki_collective_id'] ?? null;
			if (!is_int($collectiveId) && !(is_string($collectiveId) && ctype_digit($collectiveId))) {
				throw new Exception('Invalid collective wiki location.');
			}
			$collectiveId = (int)$collectiveId;
			if ($collectiveId <= 0) {
				throw new Exception('Invalid collective wiki location.');
			}
			if ($visibility === 'teams' && !$this->wikiLocationService->collectiveMatchesAnyTeam($collectiveId, $bot->getUserId(), $this->decodeIdList($bot->getAllowedTeams()))) {
				throw new Exception('Team bots can use only collectives from one of their selected teams.');
			}
			$resolved = $this->wikiLocationService->resolveCollectiveWikiRoot($collectiveId, $bot->getUserId());
			$folder = $resolved['folder'] ?? null;
			if (!$folder instanceof Folder) {
				throw new Exception('Resolved collective wiki root is not a folder.');
			}

			return [
				'folder' => $folder,
				'location' => 'collective',
				'collective_id' => $collectiveId,
			];
		}
		if ($visibility === 'teams') {
			throw new Exception('Team bots can use LLM Wiki only with a collective wiki location.');
		}

		$rootPath = isset($config['wiki_root_path']) && is_string($config['wiki_root_path']) && trim($config['wiki_root_path']) !== ''
			? $this->normalizeWikiRootPath($config['wiki_root_path'])
			: \OCA\EducAI\AppInfo\Application::WIKI_ROOT_FOLDER . '/Personal Wikis/' . $this->slugify($bot->getMentionName() !== '' ? $bot->getMentionName() : $bot->getBotName());

		$userFolder = $this->rootFolder->getUserFolder($bot->getUserId());
		$node = $userFolder->get($rootPath);
		if (!$node instanceof Folder) {
			throw new Exception('Wiki root path is not a folder.');
		}

		return [
			'folder' => $node,
			'location' => 'personal_files',
			'collective_id' => null,
		];
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

	private function upsertRoot(Folder $folder, string $location, ?int $collectiveId): WikiRoot {
		$now = time();
		$rootNodeId = (int)$folder->getId();
		if ($rootNodeId <= 0) {
			throw new Exception('Wiki root folder has no stable node id.');
		}
		$root = $this->rootMapper->findOneByRootNodeId($rootNodeId);
		if ($root === null) {
			$root = new WikiRoot();
			$root->setRootNodeId($rootNodeId);
			$root->setCreatedAt($now);
		}
		$root->setRootPath((string)$folder->getPath());
		$root->setLocation($location);
		$root->setCollectiveId($collectiveId);
		$root->setActive(true);
		$root->setLastError(null);
		$root->setUpdatedAt($now);

		if ($root->getId() === null) {
			return $this->rootMapper->insert($root);
		}

		return $this->rootMapper->update($root);
	}

	/**
	 * @param array<int,BotTool> $assignments
	 * @return array<int,array<string,mixed>>
	 */
	private function groupWikiAssignmentsByBot(array $assignments): array {
		$grouped = [];
		foreach ($assignments as $assignment) {
			$botId = $assignment->getBotId();
			if (isset($grouped[$botId])) {
				continue;
			}
			$config = [];
			$configJson = $assignment->getConfigOverride();
			if ($configJson !== null && $configJson !== '') {
				$decoded = json_decode($configJson, true);
				if (is_array($decoded)) {
					$config = $decoded;
				}
			}
			$grouped[$botId] = $config;
		}

		return $grouped;
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	private function normalizeConfigForHash(array $config): array {
		ksort($config);
		return $config;
	}

	private function normalizeWikiRootPath(string $path): string {
		$path = trim(str_replace('\\', '/', $path));
		$path = trim($path, '/');
		if ($path === '' || !str_starts_with($path, \OCA\EducAI\AppInfo\Application::WIKI_ROOT_FOLDER . '/')) {
			throw new Exception('Invalid wiki root path.');
		}
		if (strlen($path) > 512) {
			throw new Exception('Wiki root path is too long.');
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
				throw new Exception('Invalid wiki root path segment.');
			}
		}
		return $path;
	}

	private function slugify(string $value): string {
		$value = trim($value);
		$value = str_replace(['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'], ['ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'], $value);
		$value = strtolower($value);
		$value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? 'wiki';
		$value = trim($value, '-_');
		return $value !== '' ? $value : 'wiki';
	}
}
