<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\BotSource;
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
}
