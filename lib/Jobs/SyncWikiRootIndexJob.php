<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Db\WikiRoot;
use OCA\EducAI\Db\WikiRootMapper;
use OCA\EducAI\Service\WikiService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

class SyncWikiRootIndexJob extends QueuedJob {
	private WikiRootMapper $rootMapper;
	private IRootFolder $rootFolder;
	private WikiService $wikiService;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		WikiRootMapper $rootMapper,
		IRootFolder $rootFolder,
		WikiService $wikiService,
		LoggerInterface $logger
	) {
		parent::__construct($time);
		$this->rootMapper = $rootMapper;
		$this->rootFolder = $rootFolder;
		$this->wikiService = $wikiService;
		$this->logger = $logger;
	}

	/**
	 * @param array<string,mixed> $arguments
	 */
	public function run($arguments): void {
		$rootId = $arguments['root_id'] ?? null;
		if (!is_int($rootId) && !(is_string($rootId) && ctype_digit($rootId))) {
			$this->logger->warning('SyncWikiRootIndexJob received invalid root id', [
				'arguments' => $arguments,
			]);
			return;
		}

		try {
			$root = $this->rootMapper->findById((int)$rootId);
		} catch (Throwable $e) {
			$this->logger->warning('SyncWikiRootIndexJob could not load wiki root', [
				'root_id' => $rootId,
				'exception' => $e,
			]);
			return;
		}

		try {
			$folder = $this->loadRootFolder($root);
			$this->wikiService->syncIndexForRoot($folder);
			$this->markSuccess($root);
		} catch (Throwable $e) {
			$this->markFailure($root, $e);
			$this->logger->warning('Failed to sync wiki index for registered root', [
				'root_id' => (int)$root->getId(),
				'root_node_id' => (int)$root->getRootNodeId(),
				'exception' => $e,
			]);
		}
	}

	private function loadRootFolder(WikiRoot $root): Folder {
		$nodes = $this->rootFolder->getById((int)$root->getRootNodeId());
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				return $node;
			}
		}

		$rootPath = (string)$root->getRootPath();
		if ($rootPath !== '' && method_exists($this->rootFolder, 'get')) {
			try {
				$node = $this->rootFolder->get($rootPath);
				if ($node instanceof Folder) {
					return $node;
				}
			} catch (Throwable) {
			}
		}

		throw new \RuntimeException('Registered wiki root node is not available as a folder.');
	}

	private function markSuccess(WikiRoot $root): void {
		$root->setLastSyncedAt(time());
		$root->setLastError(null);
		$root->setUpdatedAt(time());
		$this->rootMapper->update($root);
	}

	private function markFailure(WikiRoot $root, Throwable $e): void {
		$root->setLastError(substr($e->getMessage(), 0, 4000));
		$root->setUpdatedAt(time());
		$this->rootMapper->update($root);
	}
}
