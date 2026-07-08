<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

class WikiLocationService {
	private const COLLECTIVE_ADMIN_LEVEL = 8;

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger
	) {
	}

	/**
	 * @return array<int,array{id:int,name:string,emoji:?string,display_name:string,team_id:?string}>
	 */
	public function listEditableCollectives(?string $userId): array {
		if ($userId === null || !$this->collectivesAvailable()) {
			return [];
		}

		try {
			$collectiveService = \OCP\Server::get(\OCA\Collectives\Service\CollectiveService::class);
			$collectives = $collectiveService->getCollectives($userId);
		} catch (\Throwable $e) {
			$this->logger->debug('Unable to list collectives for wiki locations', [
				'exception' => $e,
			]);
			return [];
		}

		$result = [];
		foreach ($collectives as $collective) {
			if (!$this->isCollectiveAdmin($collective)) {
				continue;
			}

			try {
				$id = (int)$collective->getId();
				$name = (string)$collective->getName();
			} catch (\Throwable) {
				continue;
			}
			if ($id <= 0 || $name === '') {
				continue;
			}
			try {
				$emoji = $collective->getEmoji();
			} catch (\Throwable) {
				$emoji = null;
			}
			$emoji = is_string($emoji) && $emoji !== '' ? $emoji : null;

			$result[] = [
				'id' => $id,
				'name' => $name,
				'emoji' => $emoji,
				'display_name' => $emoji !== null ? $emoji . ' ' . $name : $name,
				'team_id' => $this->extractCollectiveTeamId($collective),
			];
		}

		usort($result, static fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));
		return $result;
	}

	/**
	 * @param array<int,string> $teamIds
	 */
	public function collectiveMatchesAnyTeam(int $collectiveId, string $userId, array $teamIds): bool {
		if ($teamIds === []) {
			return false;
		}
		if (!$this->collectivesAvailable()) {
			return false;
		}

		$collective = $this->getAdminCollective($collectiveId, $userId);
		$teamId = $this->extractCollectiveTeamId($collective);
		return $teamId !== null && in_array($teamId, $teamIds, true);
	}

	/**
	 * @return array{folder:Folder,label:string}
	 * @throws Exception
	 */
	public function resolveCollectiveWikiRoot(int $collectiveId, string $userId): array {
		if ($collectiveId <= 0) {
			throw new Exception('A valid collective must be selected for this wiki location.');
		}
		if (!$this->collectivesAvailable()) {
			throw new Exception('The Collectives app is not available.');
		}

		try {
			$collective = $this->getAdminCollective($collectiveId, $userId);
			$pageService = \OCP\Server::get(\OCA\Collectives\Service\PageService::class);
			$pageService->verifyEditPermissions($collectiveId, $userId);
			$folder = $pageService->getCollectiveFolder($collectiveId, $userId);

			$label = 'Collective: ' . (string)$collective->getName();

			return [
				'folder' => $folder,
				'label' => $label,
			];
		} catch (\Throwable $e) {
			throw new Exception('Unable to use selected collective as wiki location: ' . $e->getMessage(), 0, $e);
		}
	}

	private function collectivesAvailable(): bool {
		try {
			if (method_exists($this->appManager, 'isEnabledForUser') && !$this->appManager->isEnabledForUser('collectives')) {
				return false;
			}
		} catch (\Throwable) {
			return false;
		}

		return class_exists(\OCA\Collectives\Service\CollectiveService::class)
			&& class_exists(\OCA\Collectives\Service\PageService::class)
			&& class_exists(\OCP\Server::class)
			&& method_exists(\OCP\Server::class, 'get');
	}

	private function getAdminCollective(int $collectiveId, string $userId): object {
		try {
			$collectiveService = \OCP\Server::get(\OCA\Collectives\Service\CollectiveService::class);
			$collective = $collectiveService->getCollective($collectiveId, $userId);
		} catch (\Throwable $e) {
			throw new Exception('Unable to load selected collective: ' . $e->getMessage(), 0, $e);
		}

		if (!$this->isCollectiveAdmin($collective)) {
			throw new Exception('Only collective admins can use a collective as wiki location.');
		}

		return $collective;
	}

	private function isCollectiveAdmin(object $collective): bool {
		if (!method_exists($collective, 'getLevel')) {
			return false;
		}

		try {
			return (int)$collective->getLevel() >= self::COLLECTIVE_ADMIN_LEVEL;
		} catch (\Throwable) {
			return false;
		}
	}

	private function extractCollectiveTeamId(object $collective): ?string {
		foreach (['getCircleId', 'getCircleUniqueId'] as $method) {
			if (!method_exists($collective, $method)) {
				continue;
			}
			try {
				$value = (string)$collective->{$method}();
			} catch (\Throwable) {
				continue;
			}
			if ($value !== '') {
				return $value;
			}
		}

		return null;
	}
}
