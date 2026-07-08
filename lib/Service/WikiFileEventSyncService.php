<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\WikiRoot;
use OCA\EducAI\Jobs\SyncWikiRootIndexJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Throwable;

class WikiFileEventSyncService {
	private const MANAGED_ROOT_FILES = ['index.md', 'log.md', 'schema.md'];
	private const WIKI_FILE_EXTENSIONS = ['md', 'txt', 'json'];
	private const SYNC_DELAY_SECONDS = 5;
	private const MAX_ANCESTOR_DEPTH = 64;

	private WikiRootRegistryService $registryService;
	private IJobList $jobList;
	private LoggerInterface $logger;

	public function __construct(
		WikiRootRegistryService $registryService,
		IJobList $jobList,
		LoggerInterface $logger
	) {
		$this->registryService = $registryService;
		$this->jobList = $jobList;
		$this->logger = $logger;
	}

	/**
	 * @param array<int,Node> $nodes
	 */
	public function scheduleForChangedNodes(array $nodes): void {
		$ancestorIds = [];
		foreach ($nodes as $node) {
			if (!$node instanceof Node || $this->isIgnoredNode($node)) {
				continue;
			}

			foreach ($this->collectAncestorIds($node) as $id) {
				$ancestorIds[$id] = $id;
			}
		}

		if ($ancestorIds === []) {
			return;
		}

		try {
			$roots = $this->registryService->findRootsForNodeAncestors(array_values($ancestorIds));
		} catch (Throwable $e) {
			$this->logger->warning('Failed to match wiki file event against registry', [
				'exception' => $e,
			]);
			return;
		}

		$queuedRootIds = [];
		foreach ($roots as $root) {
			if (!$root instanceof WikiRoot) {
				continue;
			}
			$rootId = (int)$root->getId();
			if ($rootId <= 0 || isset($queuedRootIds[$rootId])) {
				continue;
			}

			$args = ['root_id' => $rootId];
			if (!$this->jobList->has(SyncWikiRootIndexJob::class, $args)) {
				$this->jobList->scheduleAfter(SyncWikiRootIndexJob::class, time() + self::SYNC_DELAY_SECONDS, $args);
			}
			$queuedRootIds[$rootId] = true;
		}
	}

	private function isIgnoredNode(Node $node): bool {
		try {
			$path = trim(str_replace('\\', '/', (string)$node->getPath()), '/');
			$name = strtolower((string)$node->getName());
		} catch (Throwable $e) {
			$this->logger->debug('Skipping wiki file event for unreadable node metadata', [
				'exception' => $e,
			]);
			return true;
		}

		if ($name !== '' && in_array($name, self::MANAGED_ROOT_FILES, true)) {
			return true;
		}

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || str_starts_with($segment, '.')) {
				return true;
			}
		}

		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		return $extension !== '' && !in_array($extension, self::WIKI_FILE_EXTENSIONS, true);
	}

	/**
	 * @return array<int,int>
	 */
	private function collectAncestorIds(Node $node): array {
		$ids = [];
		$current = $node;
		for ($depth = 0; $depth < self::MAX_ANCESTOR_DEPTH && $current instanceof Node; $depth++) {
			try {
				$id = (int)$current->getId();
				if ($id > 0) {
					$ids[$id] = $id;
				}
				$parent = $current->getParent();
			} catch (Throwable $e) {
				$this->logger->debug('Stopped collecting wiki node ancestors', [
					'exception' => $e,
				]);
				break;
			}

			if (!$parent instanceof Node || $parent === $current) {
				break;
			}
			$current = $parent;
		}

		return array_values($ids);
	}
}
