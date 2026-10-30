<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Db\ApiConnectionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Who may give an API tool to which bot. Execution has its own check in
 * OpenApiExecutionService; an assignment alone never makes a call possible.
 */
class ApiToolAccess {
	public function __construct(
		private OpenApiToolCatalog $catalog,
		private ApiConnectionMapper $connections,
		private IGroupManager $groupManager,
	) {
	}

	public function canAssign(string $userId, ApiConnection $connection): bool {
		if ($userId === '') {
			return false;
		}
		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}
		return match ($connection->getAssignPolicy()) {
			ApiConnection::ASSIGN_ALL_USERS => true,
			ApiConnection::ASSIGN_GROUPS => $this->inAnyGroup($userId, $connection->getAssignGroupList()),
			default => false,
		};
	}

	/**
	 * Callable API tools this user may give to bots, with the bot
	 * visibilities each one is allowed for.
	 *
	 * @return array<string,array{connection:string,bot_visibilities:list<string>}>
	 */
	public function assignableTools(string $userId): array {
		$tools = [];
		$allowed = [];
		foreach ($this->catalog->exposed() as $entry) {
			$connection = $entry['connection'];
			$allowed[$connection->getId()] ??= $this->canAssign($userId, $connection);
			if ($allowed[$connection->getId()]) {
				$tools[$entry['name']] = [
					'connection' => $connection->getName(),
					'bot_visibilities' => $connection->getBotVisibilityList(),
				];
			}
		}
		return $tools;
	}

	public static function allowsVisibility(ApiConnection $connection, string $botVisibility): bool {
		return in_array($botVisibility, $connection->getBotVisibilityList(), true);
	}

	/**
	 * Checks the API tools of a bot that is being saved, submitted or
	 * published. Newly added tools must be available and assignable by the
	 * actor; tools that stay assigned are only re-checked against a changed
	 * visibility, so unavailable assignments can be kept and repaired later.
	 *
	 * @param list<string> $before API and other tool names the bot already had
	 * @param list<string> $after Tool names after the change
	 * @param string|null $visibilityBefore Null when every tool counts as new
	 * @throws ApiToolAssignmentException
	 */
	public function assertAssignable(string $actorId, array $before, array $after, ?string $visibilityBefore, string $visibilityAfter): void {
		foreach (array_values(array_unique($after)) as $name) {
			$ids = OpenApiToolCatalog::parseToolName($name);
			if ($ids === null) {
				continue;
			}
			$isNew = $visibilityBefore === null || !in_array($name, $before, true);
			if (!$isNew && $visibilityBefore === $visibilityAfter) {
				continue;
			}
			$label = null;
			if ($isNew) {
				try {
					$resolved = $this->catalog->resolve($ids[0], $ids[1]);
				} catch (ApiExecutionException) {
					throw new ApiToolAssignmentException($name, ApiToolAssignmentException::TOOL_NOT_AVAILABLE);
				}
				$connection = $resolved['connection'];
				$label = $connection->getName() . ': ' . (string)($resolved['config']['label'] ?? '');
				if (!$this->canAssign($actorId, $connection)) {
					throw new ApiToolAssignmentException($name, ApiToolAssignmentException::NOT_PERMITTED, $label);
				}
			} else {
				try {
					$connection = $this->connections->findById($ids[0]);
				} catch (DoesNotExistException) {
					continue;
				}
			}
			if (!self::allowsVisibility($connection, $visibilityAfter)) {
				throw new ApiToolAssignmentException($name, ApiToolAssignmentException::VISIBILITY_NOT_ALLOWED, $label ?? $connection->getName());
			}
		}
	}

	/** @param list<string> $groups */
	private function inAnyGroup(string $userId, array $groups): bool {
		foreach ($groups as $group) {
			if ($this->groupManager->isInGroup($userId, $group)) {
				return true;
			}
		}
		return false;
	}
}
