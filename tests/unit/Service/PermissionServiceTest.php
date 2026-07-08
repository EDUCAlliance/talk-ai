<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PermissionServiceTest extends TestCase {
	public function testCanApproveBotRequiresMatchingScope(): void {
		$service = $this->createPermissionService(false, ['group-a', 'group-b'], []);
		$bot = $this->createBot('owner', 'groups', ['group-a', 'group-b']);

		$this->assertTrue($service->canApproveBot('reviewer', $bot));

		$otherScopedBot = $this->createBot('owner', 'groups', ['group-a', 'group-c']);
		$this->assertFalse($service->canApproveBot('reviewer', $otherScopedBot));
	}

	public function testCanApproveBotAllowsOwnerWhenTheyCanManageTargetScope(): void {
		$service = $this->createScopedPermissionService(
			static fn (string $userId): bool => $userId === 'owner',
			static fn (string $userId): array => $userId === 'owner' ? ['group-a'] : [],
			static fn (string $userId): array => []
		);
		$bot = $this->createBot('owner', 'groups', ['group-a']);

		$this->assertTrue($service->canApproveBot('owner', $bot));
		$this->assertFalse($service->canApproveBot('reviewer', $bot));
	}

	public function testCanEditBotIsBoundToConcreteBotScope(): void {
		$service = $this->createPermissionService(false, ['group-a'], []);
		$bot = $this->createBot('owner', 'groups', ['group-b']);

		$this->assertTrue($service->canEditBot('owner', $bot));
		$this->assertFalse($service->canEditBot('reviewer', $bot));
	}

	public function testCanApproveBotUsesPendingTargetScopeForVersionedChanges(): void {
		$service = $this->createPermissionService(false, ['group-a'], []);
		$bot = $this->createBot('owner', 'groups', ['group-a']);
		$bot->setApprovalStatus('pending');
		$bot->setPendingChangesArray([
			'visibility' => 'groups',
			'allowed_groups' => json_encode(['group-b']),
			'allowed_teams' => json_encode([]),
		]);

		$this->assertFalse($service->canApproveBot('reviewer', $bot));
	}

	public function testCanPublishBotToScopeRequiresAllTeamAssignments(): void {
		$service = $this->createPermissionService(false, [], ['team-1', 'team-2']);

		$this->assertTrue($service->canPublishBotToScope('reviewer', 'teams', null, ['team-1', 'team-2']));
		$this->assertFalse($service->canPublishBotToScope('reviewer', 'teams', null, ['team-1', 'team-3']));
	}

	public function testCanApproveTeamBotRequiresConcreteTeamAdminEvenForGlobalAdmin(): void {
		$teamBot = $this->createBot('owner', 'teams', [], ['team-1']);

		$globalAdminOnly = $this->createPermissionService(true, [], []);
		$this->assertFalse($globalAdminOnly->canApproveBot('admin', $teamBot));
		$this->assertTrue($globalAdminOnly->canPublishBotToScope('admin', 'teams', null, ['team-1']));

		$teamAdmin = $this->createPermissionService(true, [], ['team-1']);
		$this->assertTrue($teamAdmin->canApproveBot('admin', $teamBot));
	}

	private function createPermissionService(bool $isAdmin, array $adminGroups, array $adminTeams): PermissionService {
		return $this->createScopedPermissionService(
			static fn (string $userId): bool => $isAdmin,
			static fn (string $userId): array => $adminGroups,
			static fn (string $userId): array => $adminTeams
		);
	}

	private function createScopedPermissionService(
		callable $isAdminResolver,
		callable $adminGroupsResolver,
		callable $adminTeamsResolver
	): PermissionService {
		$service = $this->getMockBuilder(PermissionService::class)
			->setConstructorArgs([
				$this->createMock(IGroupManager::class),
				$this->createMock(IUserManager::class),
				$this->createMock(IAppManager::class),
				$this->createMock(LoggerInterface::class),
			])
			->onlyMethods(['isAdmin', 'getAdminGroups', 'getAdminTeams'])
			->getMock();

		$service->method('isAdmin')->willReturnCallback($isAdminResolver);
		$service->method('getAdminGroups')->willReturnCallback($adminGroupsResolver);
		$service->method('getAdminTeams')->willReturnCallback($adminTeamsResolver);

		return $service;
	}

	/**
	 * @param array<int,string> $groups
	 * @param array<int,string> $teams
	 */
	private function createBot(string $ownerId, string $visibility, array $groups = [], array $teams = []): Bot {
		$bot = new Bot();
		$bot->setUserId($ownerId);
		$bot->setVisibility($visibility);
		$bot->setIsPublic($visibility === 'global');
		$bot->setAllowedGroups(json_encode($groups) ?: '[]');
		$bot->setAllowedTeams(json_encode($teams) ?: '[]');

		return $bot;
	}
}
