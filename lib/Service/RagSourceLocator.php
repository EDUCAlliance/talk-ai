<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\Embedding;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use Throwable;

/**
 * Resolves a file or folder knowledge source in its owner's files.
 *
 * An empty lookup only means the owner cannot reach the node any more (deleted,
 * moved out of reach, share revoked, storage unmounted); it is not proof of a
 * physical deletion. Exceptions from the lookup are backend failures and must
 * not be treated as a deletion either.
 */
class RagSourceLocator {
	public const FOUND = 'found';
	public const MISSING = 'missing';
	public const DENIED = 'denied';
	public const UNAVAILABLE = 'unavailable';

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @return array{state:string,node:?Node,folder:?Folder,exception:?Throwable}
	 */
	public function locate(BotSource $source): array {
		try {
			$folder = $this->rootFolder->getUserFolder($source->getOwnerUid());
		} catch (NotPermittedException|ForbiddenException $e) {
			return $this->result(self::DENIED, null, null, $e);
		} catch (Throwable $e) {
			// The owner account no longer exists (server-internal exception class).
			$state = is_a($e, 'OC\\User\\NoUserException') ? self::MISSING : self::UNAVAILABLE;
			return $this->result($state, null, null, $e);
		}

		try {
			$nodes = $folder->getById($source->getNodeId());
		} catch (NotPermittedException|ForbiddenException $e) {
			return $this->result(self::DENIED, null, $folder, $e);
		} catch (Throwable $e) {
			return $this->result(self::UNAVAILABLE, null, $folder, $e);
		}

		foreach (is_array($nodes) ? $nodes : [] as $node) {
			if ($node instanceof Node) {
				return $this->result(self::FOUND, $node, $folder, null);
			}
		}

		return $this->result(self::MISSING, null, $folder, null);
	}

	/**
	 * @return array{state:string,node:?Node,folder:?Folder,exception:?Throwable}
	 */
	private function result(string $state, ?Node $node, ?Folder $folder, ?Throwable $exception): array {
		return ['state' => $state, 'node' => $node, 'folder' => $folder, 'exception' => $exception];
	}

	/**
	 * Check the actual files, not just the still-reachable parent source. Share
	 * revocation need not produce a file event, so this is an answer-time boundary.
	 * All lookups are local to this call: a later request must see permission changes.
	 *
	 * @param list<Embedding> $embeddings
	 * @return array{embeddings:list<Embedding>,needs_reindex:bool}
	 */
	public function filterReadableEmbeddings(BotSource $source, array $embeddings): array {
		$readable = [];
		$needsReindex = false;
		try {
			$ownerFolder = $this->rootFolder->getUserFolder($source->getOwnerUid());
			$ownerPath = rtrim($ownerFolder->getPath(), '/') . '/';
			$sourceNodes = $ownerFolder->getById($source->getNodeId());
			$sourcePaths = [];
			foreach ($sourceNodes as $node) {
				if (($source->getNodeType() === 'folder' ? $node instanceof Folder : $node instanceof File)
					&& $node->isReadable() && !MetadataFileFilter::isMetadataPath($node->getPath())) {
					$sourcePaths[] = rtrim($node->getPath(), '/');
				}
			}
			if ($sourcePaths === []) {
				return ['embeddings' => [], 'needs_reindex' => false];
			}
			$pathsById = [];
			foreach ($embeddings as $embedding) {
				$metadata = json_decode($embedding->getMetadata() ?? '', true);
				$metadata = is_array($metadata) ? $metadata : [];
				// Direct-file sources already persist identity outside their metadata.
				$id = $metadata['node_id'] ?? ($source->getNodeType() === 'file' ? $source->getNodeId() : null);
				if (!is_int($id) || $id <= 0) {
					$needsReindex = true;
					continue;
				}
				if (!array_key_exists($id, $pathsById)) {
					$pathsById[$id] = null;
					try {
						foreach ($ownerFolder->getById($id) as $file) {
							if (!$file instanceof File || !$file->isReadable()) {
								continue;
							}
							$path = $file->getPath();
							if (!str_starts_with($path, $ownerPath) || MetadataFileFilter::isMetadataPath($path)) {
								continue;
							}
							foreach ($sourcePaths as $sourcePath) {
								if (($source->getNodeType() === 'file' && $id === $source->getNodeId() && $path === $sourcePath)
									|| ($source->getNodeType() === 'folder' && str_starts_with($path, $sourcePath . '/'))) {
									$pathsById[$id] = substr($path, strlen($ownerPath));
									break 2;
								}
							}
						}
					} catch (Throwable) {
						// Fail closed for this file, without hiding other readable files.
					}
				}
				if ($pathsById[$id] !== null) {
					// A rename or a different share alias must not leave a stale citation.
					$metadata['path'] = $pathsById[$id];
					$embedding->setMetadata(json_encode($metadata));
					$readable[] = $embedding;
				}
			}
		} catch (Throwable) {
			// Permission and storage failures are not permission to serve cached text.
			return ['embeddings' => [], 'needs_reindex' => false];
		}
		return ['embeddings' => $readable, 'needs_reindex' => $needsReindex];
	}
}
