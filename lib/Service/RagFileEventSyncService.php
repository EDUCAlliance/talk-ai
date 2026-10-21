<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Jobs\SyncRagSourceJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;
use Throwable;

/** Match events by file identity, never by the event user's mount path. */
class RagFileEventSyncService {
	public function __construct(
		private BotSourceMapper $sources,
		private IRootFolder $rootFolder,
		private IJobList $jobs,
		private LoggerInterface $logger,
		private ?ILockingProvider $locking = null,
	) {
	}

	/** @param list<Node> $nodes
	 * @return list<int>
	 */
	public function affectedSourceIds(array $nodes, bool $includeContained = false): array {
		$ancestorIds = [];
		$folderIds = [];
		$eventNodeIds = [];
		$userFolders = [];
		$resolvedNodes = [];
		$mountsByPath = [];
		foreach ($nodes as $node) {
			try {
				$nodeId = (int)$node->getId();
			} catch (Throwable) {
				$nodeId = 0;
			}
			try {
				// Capture the subtree identity even when its old name is metadata:
				// renaming __MACOSX to a normal folder makes its children indexable.
				if ($includeContained && $node instanceof Folder && $nodeId > 0) {
					$folderIds[$nodeId] = true;
				}
				// An event may use a metadata-looking share alias while another
				// source owner sees an ordinary file. Collect identity first.
				$ancestorIds += $this->withOriginalOwnerAncestors($node, $userFolders, $resolvedNodes);
				if ($nodeId > 0) {
					$eventNodeIds[$nodeId] = true;
				}
			} catch (Throwable $e) {
				$this->logger->debug('Could not collect all RAG file event ancestors', ['exception_class' => $e::class]);
			}
		}
		if ($ancestorIds === [] && $folderIds === []) {
			return [];
		}

		$ids = [];
		$visibleAncestorsByOwner = [];
		try {
			foreach ($this->sources->findByNodeIds(array_keys($ancestorIds)) as $source) {
				$ownerUid = (string)$source->getOwnerUid();
				$visibleAncestorsByOwner[$ownerUid] ??= $this->visibleEventAncestorsForOwner(
					$nodes, $ownerUid, $userFolders, $resolvedNodes,
				);
				if (isset($visibleAncestorsByOwner[$ownerUid][(int)$source->getNodeId()])) {
					$ids[$source->getId()] = $source->getId();
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('Failed to match RAG file event to knowledge sources', ['exception_class' => $e::class]);
		}
		try {
			$fileSources = $this->sources->findFileSources();
		} catch (Throwable $e) {
			$this->logger->warning('Failed to find RAG file event source contexts', ['exception_class' => $e::class]);
			return array_values($ids);
		}

		// The original owner's parent is not necessarily the recipient's parent:
		// a shared file may be mounted inside a recipient's knowledge folder.
		// Visit only configured folder-source owners, once per owner and node.
		$folderSourcesByOwner = [];
		foreach ($fileSources as $source) {
			if ($source->getNodeType() === 'folder') {
				$folderSourcesByOwner[$source->getOwnerUid()][] = $source;
			}
		}
		foreach ($folderSourcesByOwner as $ownerUid => $folderSources) {
			$visiblePaths = [];
			foreach (array_keys($eventNodeIds) as $nodeId) {
				foreach ($this->nodesForOwner((string)$ownerUid, $nodeId, $userFolders, $resolvedNodes) as $visible) {
					try {
						if (!MetadataFileFilter::isMetadataPath($visible->getPath())) {
							$visiblePaths[] = $visible->getPath();
						}
					} catch (Throwable $e) {
						$this->logger->debug('Could not collect shared RAG file ancestors', ['exception_class' => $e::class]);
					}
				}
			}
			if ($visiblePaths === []) {
				continue;
			}
			foreach ($folderSources as $source) {
				// A shared node's lazy parent may carry its storage parent's ID,
				// not the recipient's mount parent. Compare independently resolved
				// paths within the same owner's view, never those lazy parent IDs.
				foreach ($this->nodesForOwner((string)$ownerUid, (int)$source->getNodeId(), $userFolders, $resolvedNodes) as $sourceFolder) {
					if (!$sourceFolder instanceof Folder) {
						continue;
					}
					try {
						$sourcePath = rtrim($sourceFolder->getPath(), '/');
						foreach ($visiblePaths as $visiblePath) {
							if ($visiblePath === $sourcePath || str_starts_with($visiblePath, $sourcePath . '/')) {
								$ids[$source->getId()] = $source->getId();
								break 2;
							}
						}
					} catch (Throwable $e) {
						$this->logger->debug('Could not match shared RAG file source path', ['exception_class' => $e::class]);
					}
				}
			}
		}

		// Before a whole folder moves or disappears, remember sources inside it.
		// There is no need to enumerate the folder's potentially huge file tree.
		if ($folderIds !== []) {
			foreach ($fileSources as $source) {
				if (isset($ids[$source->getId()])) {
					continue;
				}
				foreach ($this->nodesForOwner((string)$source->getOwnerUid(), (int)$source->getNodeId(), $userFolders, $resolvedNodes) as $contained) {
					if (array_intersect_key($folderIds, $this->withOriginalOwnerAncestors($contained, $userFolders, $resolvedNodes)) !== []) {
						$ids[$source->getId()] = $source->getId();
						break;
					}
					// Deleting an unshared owner directory can remove a child mounted
					// inside this source without emitting a separate child event.
					// Inspect mount roots only, never the source's recursive file tree.
					if ($contained instanceof Folder && $this->containsAffectedMount(
						$contained, (string)$source->getOwnerUid(), $folderIds, $userFolders, $resolvedNodes, $mountsByPath,
					)) {
						$ids[$source->getId()] = $source->getId();
						break;
					}
				}
			}
		}
		return array_values($ids);
	}

	/** @param list<Node> $nodes
	 * @return array<int,true>
	 */
	private function visibleEventAncestorsForOwner(array $nodes, string $ownerUid, array &$userFolders, array &$resolvedNodes): array {
		$ids = [];
		foreach ($nodes as $node) {
			try {
				// Keep same-owner pre/post paths even when a renamed node no
				// longer has an ID. Never use another user's alias for filtering.
				$path = $node->getPath();
				if (str_starts_with($path, '/' . $ownerUid . '/files/') && !MetadataFileFilter::isMetadataPath($path)) {
					$ids += $this->ancestorIds($node);
				}
				$nodeId = (int)$node->getId();
			} catch (Throwable) {
				continue;
			}
			foreach ($this->nodesForOwner($ownerUid, $nodeId, $userFolders, $resolvedNodes) as $visible) {
				try {
					if (!MetadataFileFilter::isMetadataPath($visible->getPath())) {
						$ids += $this->ancestorIds($visible);
					}
				} catch (Throwable $e) {
					$this->logger->debug('Could not collect visible RAG file ancestors', ['exception_class' => $e::class]);
				}
			}
		}
		return $ids;
	}

	private function containsAffectedMount(Folder $sourceFolder, string $ownerUid, array $folderIds, array &$userFolders, array &$resolvedNodes, array &$mountsByPath): bool {
		try {
			$sourcePath = rtrim($sourceFolder->getPath(), '/');
			if (!array_key_exists($sourcePath, $mountsByPath)) {
				$mountsByPath[$sourcePath] = [];
				$mountsByPath[$sourcePath] = $this->rootFolder->getMountsIn($sourcePath);
			}
		} catch (Throwable $e) {
			$this->logger->debug('Could not inspect RAG source mounts', ['exception_class' => $e::class]);
			return false;
		}
		foreach ($mountsByPath[$sourcePath] as $mount) {
			try {
				foreach ($this->nodesForOwner($ownerUid, (int)$mount->getStorageRootId(), $userFolders, $resolvedNodes) as $mounted) {
					$mountedPath = $mounted->getPath();
					if (str_starts_with($mountedPath, $sourcePath . '/')
						&& !MetadataFileFilter::isMetadataPath($mountedPath)
						&& array_intersect_key($folderIds, $this->withOriginalOwnerAncestors($mounted, $userFolders, $resolvedNodes)) !== []) {
						return true;
					}
				}
			} catch (Throwable $e) {
				$this->logger->debug('Could not match a RAG source mount', ['exception_class' => $e::class]);
			}
		}
		return false;
	}

	/** @param list<int> $sourceIds */
	public function schedule(array $sourceIds): void {
		foreach (array_unique($sourceIds) as $sourceId) {
			try {
				SyncRagSourceJob::schedule($this->jobs, $this->locking, $sourceId);
			} catch (Throwable $e) {
				// Indexing must never cause a successful user file write to fail.
				$this->logger->warning('Failed to queue RAG file update', ['sourceId' => $sourceId, 'exception_class' => $e::class]);
			}
		}
	}

	/** @return array<int,true> */
	private function withOriginalOwnerAncestors(Node $node, array &$userFolders, array &$resolvedNodes): array {
		$ids = $this->ancestorIds($node);
		try {
			// A shared child can be mounted below the configured source. Resolve
			// its original owner's hierarchy too, without reading file contents.
			$owner = $node->getOwner();
			if ($owner !== null && $owner !== false && (int)$node->getId() > 0) {
				foreach ($this->nodesForOwner($owner->getUID(), (int)$node->getId(), $userFolders, $resolvedNodes) as $original) {
					$ids += $this->ancestorIds($original);
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug('Could not collect original RAG file owner ancestors', ['exception_class' => $e::class]);
		}
		return $ids;
	}

	/**
	 * Cache only within one event match; pre- and post-events must see fresh mounts.
	 * A failed owner or node lookup must not suppress other owners' matches.
	 * @param array<string,?Folder> $userFolders
	 * @param array<string,array<int,list<Node>>> $resolvedNodes
	 * @return list<Node>
	 */
	private function nodesForOwner(string $ownerUid, int $nodeId, array &$userFolders, array &$resolvedNodes): array {
		if ($ownerUid === '' || $nodeId <= 0) {
			return [];
		}
		if (isset($resolvedNodes[$ownerUid][$nodeId])) {
			return $resolvedNodes[$ownerUid][$nodeId];
		}
		$resolvedNodes[$ownerUid][$nodeId] = [];
		try {
			if (!array_key_exists($ownerUid, $userFolders)) {
				$userFolders[$ownerUid] = null;
				$userFolders[$ownerUid] = $this->rootFolder->getUserFolder($ownerUid);
			}
			if ($userFolders[$ownerUid] !== null) {
				$resolvedNodes[$ownerUid][$nodeId] = array_values(array_filter(
					$userFolders[$ownerUid]->getById($nodeId),
					static fn ($node): bool => $node instanceof Node,
				));
			}
		} catch (Throwable $e) {
			$this->logger->debug('Could not resolve RAG file event in source owner context', ['exception_class' => $e::class]);
		}
		return $resolvedNodes[$ownerUid][$nodeId];
	}

	/** @return array<int,true> */
	private function ancestorIds(Node $node): array {
		$ids = [];
		$seen = [];
		while (!isset($seen[spl_object_id($node)])) {
			$seen[spl_object_id($node)] = true;
			try {
				$id = (int)$node->getId();
				if ($id > 0) {
					if (isset($ids[$id])) {
						break;
					}
					$ids[$id] = true;
				}
			} catch (Throwable) {
				// A renamed/deleted node can lack an ID while its old parent still exists.
			}
			try {
				$parent = $node->getParent();
				if (!$parent instanceof Node) {
					break;
				}
				$node = $parent;
			} catch (Throwable) {
				break;
			}
		}
		return $ids;
	}
}
