<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\Bot;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Service to check user permissions for bot creation and approval.
 * 
 * Permission hierarchy:
 * - Nextcloud admins: Full access to create any bot type directly
 * - Group admins: Can create bots for their groups directly
 * - Team moderators/admins/owners: Can create bots for their teams directly
 * - Regular users: Can only create personal bots; all others require approval
 */
class PermissionService {
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private IAppManager $appManager;
    private LoggerInterface $logger;

    /** @var array<string, array<string, mixed>> Cache for user permissions */
    private array $permissionCache = [];

    public function __construct(
        IGroupManager $groupManager,
        IUserManager $userManager,
        IAppManager $appManager,
        LoggerInterface $logger
    ) {
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->appManager = $appManager;
        $this->logger = $logger;
    }

    /**
     * Check if user is a Nextcloud system administrator.
     */
    public function isAdmin(string $userId): bool {
        return $this->groupManager->isAdmin($userId);
    }

    /**
     * Check if user is a group admin (subadmin) for any group.
     */
    public function isGroupAdmin(string $userId): bool {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return false;
        }

        try {
            // Try to get the SubAdmin service
            $subAdmin = \OC::$server->get(\OCP\Group\ISubAdmin::class);
            return $subAdmin->isSubAdmin($user);
        } catch (\Throwable $e) {
            $this->logger->debug('SubAdmin service not available', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Get list of group IDs where user is an admin (subadmin).
     * 
     * @return array<string>
     */
    public function getAdminGroups(string $userId): array {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return [];
        }

        try {
            $subAdmin = \OC::$server->get(\OCP\Group\ISubAdmin::class);
            $groups = $subAdmin->getSubAdminsGroups($user);
            
            return array_map(static function ($group) {
                return $group->getGID();
            }, $groups);
        } catch (\Throwable $e) {
            $this->logger->debug('SubAdmin service not available', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Check if user is a team admin, moderator, or owner for any team.
     * Uses Circles API - level >= 4 (Moderator) grants admin-like permissions.
     * 
     * Levels in Circles:
     * - 1: Member
     * - 4: Moderator
     * - 8: Admin
     * - 9: Owner
     */
    public function isTeamAdminOrHigher(string $userId): bool {
        $adminTeams = $this->getAdminTeams($userId);
        return count($adminTeams) > 0;
    }

    /**
     * Get list of team IDs where user is moderator, admin, or owner.
     * 
     * @return array<string>
     */
    public function getAdminTeams(string $userId): array {
        if (!class_exists('\\OCA\\Circles\\Api\\v1\\Circles')) {
            return [];
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            return [];
        }

        try {
            if (method_exists($this->appManager, 'isEnabledForUser')) {
                if (!$this->appManager->isEnabledForUser('circles', $user)) {
                    return [];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to verify Circles availability for user', [
                'exception' => $e,
            ]);
            return [];
        }

        $adminTeams = [];

        try {
            // Get all circles the user is a member of
            $circles = \OCA\Circles\Api\v1\Circles::joinedCircles($userId, true);
            
            foreach ($circles as $circle) {
                try {
                    // Only consider actual teams (source type 16 or 10001)
                    $source = method_exists($circle, 'getSource') ? $circle->getSource() : null;
                    if ($source !== null && !in_array($source, [16, 10001], true)) {
                        continue;
                    }

                    // Get user's membership level in this circle
                    $member = \OCA\Circles\Api\v1\Circles::getMember(
                        $circle->getSingleId(),
                        $userId,
                        \OCA\Circles\Api\v1\Circles::TYPE_USER,
                        true
                    );

                    if ($member !== null) {
                        $level = 0;
                        if (method_exists($member, 'getLevel')) {
                            $level = (int) $member->getLevel();
                        }

                        // Level >= 4 means Moderator or higher (Admin=8, Owner=9)
                        if ($level >= 4) {
                            $adminTeams[] = $circle->getSingleId();
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to check team membership level', [
                        'circle_id' => method_exists($circle, 'getSingleId') ? $circle->getSingleId() : 'unknown',
                        'user_id' => $userId,
                        'exception' => $e,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to get user circles for admin check', [
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }

        return $adminTeams;
    }

    /**
     * Check if user has any approval rights (admin, group admin, or team admin/moderator/owner).
     */
    public function hasApprovalRights(string $userId): bool {
        return $this->isAdmin($userId)
            || $this->isGroupAdmin($userId)
            || $this->isTeamAdminOrHigher($userId);
    }

    /**
     * Check if a user may review a concrete bot in its effective target scope.
     * Pending updates must be reviewed against the submitted target visibility,
     * not the currently live visibility.
     *
     * Owners may review their own pending submission when they can manage the
     * target scope themselves (for example Nextcloud admins reviewing global
     * bots, or scope admins reviewing bots in their own scope).
     */
    public function canApproveBot(string $userId, Bot $bot): bool {
        $approvalScope = $this->getApprovalScope($bot);
        $visibility = $approvalScope['visibility'];

        if ($visibility === 'personal') {
            return false;
        }

        return $this->canManageScope(
            $userId,
            $visibility,
            $approvalScope['groups'],
            $approvalScope['teams'],
            $visibility !== 'teams'
        );
    }

    /**
     * Check if a user may publish a bot directly into a target scope.
     *
     * @param array<string>|null $groups Group IDs for group-scoped bots
     * @param array<string>|null $teams Team IDs for team-scoped bots
     */
    public function canPublishBotToScope(
        string $userId,
        string $visibility,
        ?array $groups = null,
        ?array $teams = null
    ): bool {
        if ($visibility === 'personal') {
            return true;
        }

        return $this->canManageScope($userId, $visibility, $groups ?? [], $teams ?? []);
    }

    /**
     * Check if user can edit a bot.
     * Owners can always edit their own bots. Shared bots can additionally be
     * managed by admins responsible for the bot's concrete scope.
     */
    public function canEditBot(string $userId, Bot $bot): bool {
        if ($bot->getUserId() === $userId) {
            return true;
        }

        return $this->canManageBotScope($userId, $bot);
    }

    /**
     * Check if user can delete a bot.
     * Owners can always delete their own bots. Shared bots can additionally be
     * deleted by admins responsible for the bot's concrete scope.
     */
    public function canDeleteBot(string $userId, Bot $bot): bool {
        if ($bot->getUserId() === $userId) {
            return true;
        }

        return $this->canManageBotScope($userId, $bot);
    }

    /**
     * Get user's permission summary for frontend.
     * 
     * @return array{
     *   isAdmin: bool,
     *   isGroupAdmin: bool,
     *   isTeamAdmin: bool,
     *   hasApprovalRights: bool,
     *   adminGroups: array<string>,
     *   adminTeams: array<string>
     * }
     */
    public function getPermissionSummary(string $userId): array {
        // Use cache if available
        if (isset($this->permissionCache[$userId])) {
            return $this->permissionCache[$userId];
        }

        $isAdmin = $this->isAdmin($userId);
        $adminGroups = $this->getAdminGroups($userId);
        $adminTeams = $this->getAdminTeams($userId);

        $summary = [
            'isAdmin' => $isAdmin,
            'isGroupAdmin' => count($adminGroups) > 0,
            'isTeamAdmin' => count($adminTeams) > 0,
            'hasApprovalRights' => $isAdmin || count($adminGroups) > 0 || count($adminTeams) > 0,
            'adminGroups' => $adminGroups,
            'adminTeams' => $adminTeams,
        ];

        $this->permissionCache[$userId] = $summary;
        return $summary;
    }

    /**
     * Get available visibility options for a user when creating a bot.
     * 
     * @return array<array{value: string, label: string, requiresApproval: bool}>
     */
    public function getAvailableVisibilities(string $userId): array {
        $isAdmin = $this->isAdmin($userId);
        $isGroupAdmin = $this->isGroupAdmin($userId);
        $isTeamAdmin = $this->isTeamAdminOrHigher($userId);

        $options = [];

        // Personal is always available
        $options[] = [
            'value' => 'personal',
            'label' => 'Just for me (personal)',
            'requiresApproval' => false,
        ];

        // Global only for admins
        if ($isAdmin) {
            $options[] = [
                'value' => 'global',
                'label' => 'Global (available to all users)',
                'requiresApproval' => false,
            ];
        }

        // Groups - available to all, but may require approval
        $options[] = [
            'value' => 'groups',
            'label' => 'Specific groups',
            'requiresApproval' => !$isAdmin && !$isGroupAdmin,
        ];

        // Teams - available to all, but may require approval
        $options[] = [
            'value' => 'teams',
            'label' => 'Specific teams',
            'requiresApproval' => !$isAdmin && !$isTeamAdmin,
        ];

        return $options;
    }

    private function canManageBotScope(string $userId, Bot $bot): bool {
        $visibility = $this->normalizeVisibility($bot->getVisibility(), $bot->getIsPublic());
        if ($visibility === 'personal') {
            return false;
        }

        return $this->canManageScope(
            $userId,
            $visibility,
            $this->decodeIdList($bot->getAllowedGroups()),
            $this->decodeIdList($bot->getAllowedTeams())
        );
    }

    /**
     * @param array<string> $groups
     * @param array<string> $teams
     */
    private function canManageScope(string $userId, string $visibility, array $groups, array $teams, bool $includeGlobalAdmins = true): bool {
        if ($includeGlobalAdmins && $this->isAdmin($userId)) {
            return true;
        }

        if ($visibility === 'global') {
            return false;
        }

        if ($visibility === 'groups') {
            return $this->hasAllIds($groups, $this->getAdminGroups($userId));
        }

        if ($visibility === 'teams') {
            return $this->hasAllIds($teams, $this->getAdminTeams($userId));
        }

        return false;
    }

    /**
     * @return array{visibility:string,groups:array<string>,teams:array<string>}
     */
    private function getApprovalScope(Bot $bot): array {
        $visibility = $this->normalizeVisibility($bot->getVisibility(), $bot->getIsPublic());
        $groups = $this->decodeIdList($bot->getAllowedGroups());
        $teams = $this->decodeIdList($bot->getAllowedTeams());

        $pendingChanges = $bot->getPendingChangesArray();
        if ($pendingChanges === null) {
            return [
                'visibility' => $visibility,
                'groups' => $groups,
                'teams' => $teams,
            ];
        }

        if (isset($pendingChanges['visibility']) && is_string($pendingChanges['visibility']) && $pendingChanges['visibility'] !== '') {
            $visibility = $pendingChanges['visibility'];
        }
        if (array_key_exists('allowed_groups', $pendingChanges)) {
            $groups = $this->decodePendingIdList($pendingChanges['allowed_groups']);
        }
        if (array_key_exists('allowed_teams', $pendingChanges)) {
            $teams = $this->decodePendingIdList($pendingChanges['allowed_teams']);
        }

        if ($visibility !== 'groups') {
            $groups = [];
        }
        if ($visibility !== 'teams') {
            $teams = [];
        }

        return [
            'visibility' => $visibility,
            'groups' => $groups,
            'teams' => $teams,
        ];
    }

    private function normalizeVisibility(?string $visibility, bool $isPublic): string {
        if ($visibility === null || $visibility === '') {
            return $isPublic ? 'global' : 'groups';
        }

        return $visibility;
    }

    /**
     * @param array<string> $requiredIds
     * @param array<string> $adminIds
     */
    private function hasAllIds(array $requiredIds, array $adminIds): bool {
        if ($requiredIds === []) {
            return false;
        }

        foreach ($requiredIds as $requiredId) {
            if (!in_array($requiredId, $adminIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string>
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
     * @param mixed $value
     * @return array<string>
     */
    private function decodePendingIdList($value): array {
        if (is_string($value) || $value === null) {
            return $this->decodeIdList($value);
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (is_string($entry) || is_numeric($entry)) {
                $result[] = (string)$entry;
            }
        }

        return $result;
    }
}
