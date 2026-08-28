<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\BotTool;
use OCA\EducAI\Db\BotToolMapper;
use OCA\EducAI\Db\ChatRoomMapper;
use OCA\EducAI\Db\Conversation;
use OCA\EducAI\Db\ConversationMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Db\Tool;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Exception\AuthorizationException;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type AttachmentSummary from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type BotToolAssignmentView from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type MessageContext from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type MessageContextInput from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type SplitToolLoadout from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type ToolAssignmentPayload from \OCA\EducAI\TypeDefinitions
 */
class BotService {
	public const TEMPERATURE_NOT_PROVIDED = '__educai_temperature_not_provided__';
	private const AI_SERVICE_UNAVAILABLE_MESSAGE = "Sorry, I'm having trouble connecting to the AI service right now. Please try again later.";

	private BotMapper $botMapper;
	private ConversationMapper $conversationMapper;
	private ChatRoomMapper $chatRoomMapper;
	private LoggerInterface $logger;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private IAppManager $appManager;
	private BotSourceMapper $botSourceMapper;
	private EmbeddingMapper $embeddingMapper;
	private BotToolMapper $botToolMapper;
	private ToolMapper $toolMapper;
	private ToolRegistry $toolRegistry;
	private AgentExecutor $agentExecutor;
	private ToolProviderRegistry $toolProviderRegistry;
	private WikiService $wikiService;
	private RateLimitService $rateLimitService;
	private PermissionService $permissionService;
	private SettingsService $settingsService;
	private RoomDocumentIngestionService $roomDocumentIngestionService;
	private ?RoomImageIngestionService $roomImageIngestionService;
	private ?WikiRootRegistryService $wikiRootRegistryService;
	private ?WikiLocationService $wikiLocationService;
	private ?TraceService $traceService;

	public function __construct(
		BotMapper $botMapper,
		ConversationMapper $conversationMapper,
		ChatRoomMapper $chatRoomMapper,
		LoggerInterface $logger,
		IGroupManager $groupManager,
		IUserManager $userManager,
		IAppManager $appManager,
		BotSourceMapper $botSourceMapper,
		EmbeddingMapper $embeddingMapper,
		BotToolMapper $botToolMapper,
		ToolMapper $toolMapper,
		ToolRegistry $toolRegistry,
		AgentExecutor $agentExecutor,
		ToolProviderRegistry $toolProviderRegistry,
		WikiService $wikiService,
		RateLimitService $rateLimitService,
		PermissionService $permissionService,
		SettingsService $settingsService,
		RoomDocumentIngestionService $roomDocumentIngestionService,
		?RoomImageIngestionService $roomImageIngestionService = null,
		?WikiRootRegistryService $wikiRootRegistryService = null,
		?WikiLocationService $wikiLocationService = null,
		?TraceService $traceService = null,
	) {
		$this->botMapper = $botMapper;
		$this->conversationMapper = $conversationMapper;
		$this->chatRoomMapper = $chatRoomMapper;
		$this->logger = $logger;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->appManager = $appManager;
		$this->botSourceMapper = $botSourceMapper;
		$this->embeddingMapper = $embeddingMapper;
		$this->botToolMapper = $botToolMapper;
		$this->toolMapper = $toolMapper;
		$this->toolRegistry = $toolRegistry;
		$this->agentExecutor = $agentExecutor;
		$this->toolProviderRegistry = $toolProviderRegistry;
		$this->wikiService = $wikiService;
		$this->rateLimitService = $rateLimitService;
		$this->permissionService = $permissionService;
		$this->settingsService = $settingsService;
		$this->roomDocumentIngestionService = $roomDocumentIngestionService;
		$this->roomImageIngestionService = $roomImageIngestionService;
		$this->wikiRootRegistryService = $wikiRootRegistryService;
		$this->wikiLocationService = $wikiLocationService;
		$this->traceService = $traceService;
	}

	/**
	 * Get all bots for a user
	 *
	 * @param string $userId
	 * @return Bot[]
	 */
	public function getBotsByUser(string $userId): array {
		return $this->botMapper->findByUserId($userId);
	}

	/**
	 * Get a specific bot
	 *
	 * @param int $id
	 * @return Bot
	 * @throws DoesNotExistException
	 */
    public function getBot(int $id): Bot {
        return $this->botMapper->findById($id);
    }

	/**
	 * @return array<int,BotToolAssignmentView>
	 * @throws Exception
	 */
	public function getBotTools(int $botId, ?string $userId): array {
		if ($userId === null) {
			throw new AuthorizationException('You do not have permission to view this bot');
		}

		$bot = $this->botMapper->findById($botId);
		// Allow owner or admin to view bot tools
		if ($bot->getUserId() !== $userId && !$this->permissionService->isAdmin($userId)) {
			throw new AuthorizationException('You do not have permission to view this bot');
		}
		$visibility = $this->normalizeVisibilityValue($bot->getVisibility(), $bot->getIsPublic());

		$result = [];

		// Get MCP tool assignments
		$mcpAssignments = $this->toolRegistry->getToolsForBot($botId);
		foreach ($mcpAssignments as $entry) {
			$tool = $entry['tool'];
			$config = $entry['config'];
			$result[] = [
				'tool' => $tool,
				'tool_id' => $tool->getId(),
				'is_builtin' => false,
				'builtin_name' => null,
				'config' => $config,
			];
		}

		// Get built-in tool assignments
		$builtInAssignments = $this->toolRegistry->getBuiltInToolsForBot($botId);
		foreach ($builtInAssignments as $entry) {
			$name = (string)($entry['name'] ?? '');
			$config = isset($entry['config']) && is_array($entry['config']) ? $entry['config'] : [];
			if (in_array($name, BuiltInToolProvider::WIKI_TOOLS, true) && !$this->wikiAllowedForConfig($bot, $visibility, $config)) {
				continue;
			}
			$result[] = [
				'is_builtin' => true,
				'builtin_name' => $name,
				'tool_id' => null,
				'config' => $config,
			];
		}

		return $result;
	}

	/**
	 * Create a new bot
	 *
	 * @param string $userId
	 * @param string $botName
	 * @param string $mentionName
	 * @param string $systemPrompt
	 * @return Bot
	 * @throws Exception
	 */
	public function createBot(
		string $userId,
		string $botName,
		string $mentionName,
		string $systemPrompt,
		bool $isPublic = false,
		?string $model = null,
		$temperature = null,
		?string $visibility = null,
		?array $allowedGroups = null,
		?array $allowedTeams = null,
		$ragEnabled = null,
		?array $tools = null,
		?string $description = null,
		?array $onboardingQuestions = null
	): Bot {
		// Validate mention name format
		if (!preg_match('/^@?[a-z0-9_-]+$/i', $mentionName)) {
			throw new Exception('Invalid mention name format. Use only letters, numbers, hyphens, and underscores.');
		}

		// Ensure mention name starts with @
		if (!str_starts_with($mentionName, '@')) {
			$mentionName = '@' . $mentionName;
		}

		// Check if mention name already exists
		try {
			$this->botMapper->findByMentionName($mentionName);
			throw new Exception('A bot with this mention name already exists');
		} catch (DoesNotExistException $e) {
			// Good, mention name is available
		}

		// Normalize visibility
		$normalizedVisibility = $visibility ?? ($isPublic ? 'global' : 'groups');
		$isPublicNormalized = $normalizedVisibility === 'global' ? true : $isPublic;

		$bot = new Bot();
		$bot->setUserId($userId);
		$bot->setBotName($botName);
		$bot->setMentionName($mentionName);
		$bot->setSystemPrompt($systemPrompt);
		if ($model !== null && $model !== '') {
			$bot->setModel($model);
		}
		$bot->setTemperature($this->settingsService->normalizeTemperatureValue($temperature, true));
		$bot->setIsActive(true);
		$bot->setIsPublic($isPublicNormalized);
		$bot->setVisibility($normalizedVisibility);
		$bot->setAllowedGroups($this->encodeIdList($normalizedVisibility === 'groups' ? ($allowedGroups ?? []) : []));
		$bot->setAllowedTeams($this->encodeIdList($normalizedVisibility === 'teams' ? ($allowedTeams ?? []) : []));
		// Normalize ragEnabled to bool (handles empty string from JSON false)
		$bot->setRagEnabled($this->normalizeBool($ragEnabled) ?? false);
		if ($description !== null) {
			$bot->setDescription($description);
		}
		if ($onboardingQuestions !== null) {
			$bot->setOnboardingQuestionsArray($onboardingQuestions);
		}

		// Determine approval status based on visibility and permissions
		$now = time();
		if ($normalizedVisibility === 'personal') {
			// Personal bots are always allowed, no approval needed
			$bot->setApprovalStatus('personal');
		} elseif ($this->permissionService->canPublishBotToScope($userId, $normalizedVisibility, $allowedGroups, $allowedTeams)) {
			// User has permission to create directly (admin, group admin for this group, team admin for this team)
			$bot->setApprovalStatus('approved');
			$bot->setApprovedBy($userId);
			$bot->setApprovedAt($now);
		} else {
			// User needs approval - save as draft
			$bot->setApprovalStatus('draft');
		}

		$bot->setCreatedAt($now);
		$bot->setUpdatedAt($now);

		$bot = $this->botMapper->insert($bot);

		if ($tools !== null) {
			$this->syncBotTools($bot, $tools);
		}

		return $bot;
	}

	/**
	 * Update a bot
	 *
	 * @param int $id
	 * @param string $userId
	 * @param string $botName
	 * @param string $systemPrompt
	 * @return Bot
	 * @throws Exception
	 */
	public function updateBot(
		int $id,
		string $userId,
		string $botName,
		string $systemPrompt,
		?bool $isPublic = null,
		?string $model = null,
		$temperature = self::TEMPERATURE_NOT_PROVIDED,
		?string $visibility = null,
		?array $allowedGroups = null,
		?array $allowedTeams = null,
		$ragEnabled = null,
		?array $tools = null,
		?string $description = null,
		?array $onboardingQuestions = null
	): Bot {
		$bot = $this->botMapper->findById($id);
		$originalStatus = $bot->getApprovalStatus() ?? 'approved';

		// Check edit permission using PermissionService
		if (!$this->permissionService->canEditBot($userId, $bot)) {
			throw new AuthorizationException('You do not have permission to edit this bot');
		}

		$editableState = $this->getEditableBotState($bot);
		$targetVisibility = $this->resolveTargetVisibility($editableState['visibility'], $visibility, $isPublic);
		$targetAllowedGroups = $targetVisibility === 'groups'
			? $this->normalizeIdArray($allowedGroups ?? $editableState['allowed_groups'])
			: [];
		$targetAllowedTeams = $targetVisibility === 'teams'
			? $this->normalizeIdArray($allowedTeams ?? $editableState['allowed_teams'])
			: [];
		$targetModel = $model !== null ? ($model !== '' ? $model : null) : $editableState['model'];
		$targetTemperature = $temperature !== self::TEMPERATURE_NOT_PROVIDED
			? $this->settingsService->normalizeTemperatureValue($temperature, true)
			: $editableState['temperature'];
		$targetDescription = $description !== null ? $description : $editableState['description'];
		$targetOnboardingQuestions = $onboardingQuestions !== null ? $onboardingQuestions : $editableState['onboarding_questions'];
		$targetRagEnabled = $ragEnabled !== null ? $this->normalizeBool($ragEnabled) : $editableState['rag_enabled'];
		if ($targetRagEnabled === null) {
			$targetRagEnabled = $editableState['rag_enabled'];
		}
		$targetTools = $this->stripWikiToolsForVisibility($tools, $targetVisibility, $targetAllowedTeams, $bot->getUserId());
		$targetIsPublic = $targetVisibility === 'global';
		$canPublishTargetScope = $this->permissionService->canPublishBotToScope(
			$userId,
			$targetVisibility,
			$targetAllowedGroups,
			$targetAllowedTeams
		);
		$selfApprovalBlocked = $bot->getUserId() === $userId && $targetVisibility !== 'personal';

		// Already-versioned updates must stay in pending_changes so the approved version remains live.
		$storePending = $bot->hasPendingChanges()
			|| ($originalStatus === 'approved' && (!$canPublishTargetScope || $selfApprovalBlocked));

		if ($storePending) {
			// Store changes as pending - approved version stays live
			$pendingChanges = [
				'bot_name' => $botName,
				'system_prompt' => $systemPrompt,
				'description' => $targetDescription,
				'model' => $targetModel,
				'temperature' => $targetTemperature,
				'visibility' => $targetVisibility,
				'is_public' => $targetIsPublic,
				'allowed_groups' => $this->encodeIdList($targetAllowedGroups),
				'allowed_teams' => $this->encodeIdList($targetAllowedTeams),
				'rag_enabled' => $targetRagEnabled,
				'tools' => $targetTools, // Store tool changes too
				'onboarding_questions' => $targetOnboardingQuestions, // Store onboarding question changes
			];

			$bot->setPendingChangesArray($pendingChanges);
			$bot->setApprovalStatus('pending');
			$bot->setSubmittedAt(time());
			$bot->setRejectionReason(null);
			$bot->setTestingEnabledBy(null);
			$bot->setUpdatedAt(time());

			$bot = $this->botMapper->update($bot);

			// Don't sync tools - they're stored in pending_changes
			return $bot;
		}

		// Direct update (draft bots, approvers editing, etc.)
		$bot->setBotName($botName);
		$bot->setSystemPrompt($systemPrompt);
		$bot->setIsPublic($targetIsPublic);
		$bot->setModel($targetModel);
		$bot->setTemperature($targetTemperature);
		$bot->setVisibility($targetVisibility);
		$bot->setAllowedGroups($this->encodeIdList($targetAllowedGroups));
		$bot->setAllowedTeams($this->encodeIdList($targetAllowedTeams));
		$bot->setRagEnabled($targetRagEnabled);
		$bot->setDescription($targetDescription);
		$bot->setOnboardingQuestionsArray($targetOnboardingQuestions);

		$now = time();
		$isNewPendingBot = $originalStatus === 'pending'
			&& !$bot->hasPendingChanges()
			&& $bot->getApprovedAt() === null;

		// Recalculate status for direct updates so transitions such as
		// personal -> groups/teams/global do not get stuck in 'personal'.
		if ($targetVisibility === 'personal') {
			$bot->setApprovalStatus('personal');
			$bot->setSubmittedAt(null);
			$bot->setApprovedBy(null);
			$bot->setApprovedAt(null);
			$bot->setRejectionReason(null);
			$bot->setTestingEnabledBy(null);
			$bot->setApprovalReason(null);
			$bot->setBotCapabilities(null);
			$bot->setRagSourceDescription(null);
			$bot->setTestingDescription(null);
			$bot->clearPendingChanges();
		} elseif ($isNewPendingBot) {
			// Keep first-time submissions pending while the owner edits them.
			$bot->setApprovalStatus('pending');
			if ($bot->getSubmittedAt() === null) {
				$bot->setSubmittedAt($now);
			}
			$bot->setRejectionReason(null);
			$bot->setTestingEnabledBy(null);
			$bot->clearPendingChanges();
		} elseif ($canPublishTargetScope) {
			$bot->setApprovalStatus('approved');
			$bot->setSubmittedAt(null);
			$bot->setApprovedBy($userId);
			$bot->setApprovedAt($now);
			$bot->setRejectionReason(null);
			$bot->setTestingEnabledBy(null);
			$bot->clearPendingChanges();
		} else {
			$bot->setApprovalStatus('draft');
			$bot->setSubmittedAt(null);
			$bot->setApprovedBy(null);
			$bot->setApprovedAt(null);
			$bot->setRejectionReason(null);
			$bot->setTestingEnabledBy(null);
			$bot->setApprovalReason(null);
			$bot->setBotCapabilities(null);
			$bot->setRagSourceDescription(null);
			$bot->setTestingDescription(null);
			$bot->clearPendingChanges();
		}

		$bot->setUpdatedAt($now);

		$bot = $this->botMapper->update($bot);

		if ($targetTools !== null) {
			$this->syncBotTools($bot, $targetTools);
		}

		return $bot;
	}

	/**
	 * Delete a bot
	 *
	 * @param int $id
	 * @param string $userId
	 * @return void
	 * @throws Exception
	 */
	public function deleteBot(int $id, string $userId): void {
		$bot = $this->botMapper->findById($id);

		// Check delete permission using PermissionService
		if (!$this->permissionService->canDeleteBot($userId, $bot)) {
			$status = $bot->getApprovalStatus() ?? 'approved';
			if (in_array($status, ['pending', 'approved'], true)) {
				throw new AuthorizationException('This bot can only be deleted by users with approval rights');
			}
			throw new AuthorizationException('You do not have permission to delete this bot');
		}

		// Delete all related data for this bot
		$this->conversationMapper->deleteByBot($id);
		$this->chatRoomMapper->deleteByBot($id);
		$this->embeddingMapper->deleteByBot($id);
		$this->botSourceMapper->deleteByBot($id);
		$this->roomDocumentIngestionService->deleteBotDocuments($id);
		if ($this->roomImageIngestionService !== null) {
			$this->roomImageIngestionService->deleteBotImages($id);
		}
		$this->botToolMapper->deleteByBot($id);
		$this->toolRegistry->refresh();

		// Delete the bot
		$this->botMapper->delete($bot);
	}

	/**
	 * Submit a bot for approval.
	 * Changes status from 'draft' to 'pending'.
	 *
	 * @param int $botId
	 * @param string $userId
	 * @param string|null $approvalReason
	 * @param string|null $botCapabilities
	 * @param string|null $ragSourceDescription
	 * @param string|null $testingDescription
	 * @return Bot
	 * @throws Exception
	 */
	public function submitForApproval(
		int $botId,
		string $userId,
		?string $approvalReason = null,
		?string $botCapabilities = null,
		?string $ragSourceDescription = null,
		?string $testingDescription = null
	): Bot {
		$bot = $this->botMapper->findById($botId);

		// Only owner can submit their bot
		if ($bot->getUserId() !== $userId) {
			throw new AuthorizationException('You do not have permission to submit this bot for approval');
		}

		$status = $bot->getApprovalStatus() ?? 'draft';

		// Can only submit drafts
		if ($status !== 'draft') {
			if ($status === 'pending') {
				throw new Exception('This bot is already pending approval');
			}
			if ($status === 'approved') {
				throw new Exception('This bot is already approved');
			}
			if ($status === 'personal') {
				throw new Exception('Personal bots do not require approval. Change visibility first.');
			}
			throw new Exception('Cannot submit this bot for approval');
		}

		// Store questionnaire responses
		$bot->setApprovalReason($approvalReason);
		$bot->setBotCapabilities($botCapabilities);
		$bot->setRagSourceDescription($ragSourceDescription);
		$bot->setTestingDescription($testingDescription);
		$bot->setRejectionReason(null);
		$bot->setTestingEnabledBy(null);

		$bot->setApprovalStatus('pending');
		$bot->setSubmittedAt(time());
		$bot->setUpdatedAt(time());

		return $this->botMapper->update($bot);
	}

	/**
	 * Approve a pending bot.
	 * If bot has pending changes, apply them to actual fields.
	 * User must have approval rights.
	 *
	 * @param int $botId
	 * @param string $approverId
	 * @return Bot
	 * @throws Exception
	 */
	public function approveBot(int $botId, string $approverId): Bot {
		$bot = $this->botMapper->findById($botId);
		if (!$this->permissionService->canApproveBot($approverId, $bot)) {
			throw new AuthorizationException('You do not have permission to approve this bot');
		}
		$status = $bot->getApprovalStatus() ?? 'draft';

		if ($status !== 'pending') {
			if ($status === 'approved') {
				throw new Exception('This bot is already approved');
			}
			if ($status === 'draft') {
				throw new Exception('This bot has not been submitted for approval yet');
			}
			throw new Exception('Cannot approve this bot');
		}

		$now = time();

		// Apply pending changes if any (versioning)
		$pendingChanges = $bot->getPendingChangesArray();
		if ($pendingChanges !== null) {
			$bot->applyPendingChanges();

			// Sync tools from pending changes
			if (isset($pendingChanges['tools']) && is_array($pendingChanges['tools'])) {
				$this->syncBotTools($bot, $pendingChanges['tools']);
			}
		}

		$bot->setApprovalStatus('approved');
		$bot->setApprovedBy($approverId);
		$bot->setApprovedAt($now);
		$bot->setRejectionReason(null);
		$bot->setTestingEnabledBy(null);
		$bot->setUpdatedAt($now);

		return $this->botMapper->update($bot);
	}

	/**
	 * Reject a pending bot.
	 * If bot had pending changes, discards them and reverts to approved status.
	 * If bot was never approved, reverts to draft.
	 * User must have approval rights.
	 *
	 * @param int $botId
	 * @param string $rejecterId
	 * @param string|null $reason
	 * @return Bot
	 * @throws Exception
	 */
	public function rejectBot(int $botId, string $rejecterId, ?string $reason = null): Bot {
		$bot = $this->botMapper->findById($botId);
		if (!$this->permissionService->canApproveBot($rejecterId, $bot)) {
			throw new AuthorizationException('You do not have permission to reject this bot');
		}
		$status = $bot->getApprovalStatus() ?? 'draft';

		if ($status !== 'pending') {
			throw new Exception('Can only reject bots that are pending approval');
		}

		// Check if this was a previously approved bot with pending changes
		// If so, revert to approved (original version stays live)
		// If not (new bot), revert to draft
		$hadPendingChanges = $bot->hasPendingChanges();
		$wasApproved = $bot->getApprovedAt() !== null;

		if ($hadPendingChanges && $wasApproved) {
			// Discard pending changes, keep original approved version
			$bot->clearPendingChanges();
			$bot->setApprovalStatus('approved');
			// Keep original approved_by and approved_at
		} else {
			// New bot that was never approved - revert to draft
			$bot->setApprovalStatus('draft');
			$bot->clearPendingChanges();
		}

		$bot->setSubmittedAt(null);
		$bot->setRejectionReason($reason);
		$bot->setTestingEnabledBy(null);
		$bot->setUpdatedAt(time());

		$this->logger->info('Bot rejected', [
			'bot_id' => $botId,
			'rejected_by' => $rejecterId,
			'reason' => $reason,
			'had_pending_changes' => $hadPendingChanges,
			'was_approved' => $wasApproved,
		]);

		return $this->botMapper->update($bot);
	}

	/**
	 * Get all bots pending approval.
	 * Only returns bots for users with approval rights.
	 *
	 * @param string $userId
	 * @return array<int, array<string, mixed>>
	 * @throws Exception
	 */
	public function getPendingApprovals(string $userId): array {
		if (!$this->permissionService->hasApprovalRights($userId)) {
			throw new AuthorizationException('You do not have permission to view pending approvals');
		}

		$pendingBots = $this->botMapper->findByApprovalStatus('pending');
		$result = [];

		foreach ($pendingBots as $bot) {
			if (!$this->permissionService->canApproveBot($userId, $bot)) {
				continue;
			}

			$botData = $bot->jsonSerialize();
			$botData['review_target'] = $this->buildReviewTarget($bot);

			// Get owner display name
			$owner = $this->userManager->get($bot->getUserId());
			$botData['owner_name'] = $owner !== null ? $owner->getDisplayName() : $bot->getUserId();

			$result[] = $botData;
		}

		return $result;
	}

	/**
	 * Get all bots (admin only - permission enforced in controller).
	 *
	 * @return Bot[]
	 */
	public function getAllBots(): array {
		return $this->botMapper->findAll();
	}

	/**
	 * Enable testing for a pending bot for the current approver.
	 *
	 * @throws Exception
	 */
	public function enableTesting(int $botId, string $userId): Bot {
		$bot = $this->botMapper->findById($botId);
		if (!$this->permissionService->canApproveBot($userId, $bot)) {
			throw new AuthorizationException('You do not have permission to enable testing for this bot');
		}
		$status = $bot->getApprovalStatus() ?? 'draft';

		if ($status !== 'pending') {
			throw new Exception('Testing can only be enabled for pending bots');
		}

		$bot->setTestingEnabledBy($userId);
		$bot->setUpdatedAt(time());

		return $this->botMapper->update($bot);
	}

	public function canInspectPendingReviewContext(Bot $bot, string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$status = $bot->getApprovalStatus() ?? 'approved';
		if ($status !== 'pending') {
			return false;
		}

		$normalizedUserId = $this->normalizeExternalUserId($userId);
		return $this->userCanTestPendingBot($bot, $normalizedUserId)
			|| $this->permissionService->canApproveBot($normalizedUserId, $bot);
	}

	/**
	 * Get user's draft bots (not yet submitted for approval).
	 *
	 * @param string $userId
	 * @return Bot[]
	 */
	public function getUserDrafts(string $userId): array {
		return $this->botMapper->findByUserIdAndStatus($userId, 'draft');
	}

	/**
	 * Get user's personal bots.
	 *
	 * @param string $userId
	 * @return Bot[]
	 */
	public function getUserPersonalBots(string $userId): array {
		return $this->botMapper->findByUserIdAndStatus($userId, 'personal');
	}

	/**
	 * Process a message for a bot and generate a response
	 *
	 * @param Bot $bot
	 * @param string $message Sanitized user message with bot mention removed
	 * @param string $roomToken
	 * @param string $userId
	 * @param string|null $originalMessage Full user message including mentions (for heuristics & logging)
	 * @param callable|null $onProgress Callback for streaming progress
	 * @param bool $isFromQueue True if this is being processed from the queue (skip rate limit check)
	 * @param string|null $onboardingContext Context from onboarding Q&A to inject into system prompt
	 * @param array<string,mixed>|null $messageContext Structured current-turn context including attachments
	 * @param int|null $threadRootMessageId Talk thread root used to isolate room history
	 * @param int|null $replyToMessageId Talk message ID to reply to when a queued request completes
	 * @param int|null $traceRunId App-owned trace run ID for best-effort personal activity capture
	 * @param callable(?string):void|null $onExecutionError Internal error propagation for the trace owner
	 * @param callable():void|null $onStreamMismatch Internal warning propagation for the trace owner
	 * @param callable(string):void|null $onToolProgress Callback for executor-owned tool progress
	 * @return string
	 * @throws Exception
	 */
	public function processMessage(
		Bot $bot,
		string $message,
		string $roomToken,
		string $userId,
		?string $originalMessage = null,
		?callable $onProgress = null,
		bool $isFromQueue = false,
		?string $onboardingContext = null,
		?array $messageContext = null,
		?int $threadRootMessageId = null,
		?int $replyToMessageId = null,
		?int $traceRunId = null,
		?callable $onExecutionError = null,
		?callable $onStreamMismatch = null,
		?callable $onToolProgress = null,
	): string {
		$effectiveBot = $this->resolveEffectiveBotForUser($bot, $userId);
		$messageContext = $this->normalizeMessageContext($messageContext);
		$threadRootMessageId = $threadRootMessageId !== null && $threadRootMessageId > 0 ? $threadRootMessageId : null;
		$replyToMessageId = $replyToMessageId !== null && $replyToMessageId > 0 ? $replyToMessageId : null;

		if (!$effectiveBot->getIsActive()) {
			throw new Exception('This bot is currently inactive');
		}

		// Check rate limits (unless this is being processed from queue)
		if (!$isFromQueue && $this->rateLimitService->isEnabled()) {
			if (!$this->rateLimitService->canProcess()) {
				if ($this->hasConversationAttachmentContext($messageContext)) {
					return 'Uploaded attachments cannot be processed while the chat endpoint is rate-limited. Please retry once capacity is available.';
				}

				// Queue the request
				$queued = $this->rateLimitService->queueRequest(
					$effectiveBot->getId(),
					$roomToken,
					$userId,
					$message,
					$originalMessage,
					100,
					$replyToMessageId,
					$threadRootMessageId
				);

				$this->logger->info('Request queued due to rate limit', [
					'request_id' => $queued->getId(),
					'bot_id' => $effectiveBot->getId(),
					'room_token' => $roomToken,
				]);

				$waitSeconds = $this->rateLimitService->getSecondsUntilAvailable();
				$queueStats = $this->rateLimitService->getQueueStats();
				$position = $queueStats['pending'];
				$estimatedWait = max($waitSeconds, $position * 2);

				// Use custom message if configured, otherwise use default
				$customMessage = $this->rateLimitService->getQueueMessage();
				if ($customMessage !== null && $customMessage !== '') {
					// Replace placeholders in custom message
					return str_replace(
						['{position}', '{wait}'],
						[(string)$position, (string)$estimatedWait],
						$customMessage
					);
				}

				// Default message
				return sprintf(
					"⏳ Your request has been queued and will be processed shortly. " .
					"(Queue position: %d, estimated wait: ~%d seconds)\n\n" .
					"_Rate limit reached. The system will respond automatically when capacity is available._",
					$position,
					$estimatedWait
				);
			}

			// Record that we're about to make a request
			$this->rateLimitService->recordUsage();
		}

		// Store user message
		$userConversation = new Conversation();
		$userConversation->setBotId($effectiveBot->getId());
		$userConversation->setRoomToken($roomToken);
		$userConversation->setThreadRootMessageId($threadRootMessageId);
		$userConversation->setUserId($userId);
		$userConversation->setRole('user');
		$userConversation->setContent($this->appendAttachmentHistoryContext($message, $messageContext));
		$userConversation->setCreatedAt(time());
		$this->conversationMapper->insert($userConversation);

		// Get conversation history (fetch more than needed for token-based filtering)
		$history = $this->conversationMapper->findByBotRoomAndThread($effectiveBot->getId(), $roomToken, $threadRootMessageId, 50);

		// Get token limit from settings (default 8000)
		$tokenLimit = $this->settingsService->getSettings()->getConversationContextTokens() ?? 8000;

		// Build messages array respecting token limit
		$messages = $this->buildContextWithinTokenLimit($history, $tokenLimit);
		$this->traceService?->recordEvent($traceRunId, 'conversation_history', [
			'status' => 'ok',
			'payload' => [
				'bot_id' => $effectiveBot->getId(),
				'room_token' => $roomToken,
				'thread_root_message_id' => $threadRootMessageId,
				'history_rows' => count($history),
				'message_count' => count($messages),
				'token_limit' => $tokenLimit,
			],
		]);

		$assistantMessage = '';
		$toolInvocations = [];
		$effectiveToolLoadout = $this->resolveEffectiveToolLoadout($bot, $userId);
		$resolvedTemperature = $this->resolveTemperature($effectiveBot);
		$toolLoadout = $effectiveToolLoadout['mcp'];
		$builtInToolLoadout = $effectiveToolLoadout['built_in'];
		$builtInToolNames = array_values(array_filter(array_map(
			static fn (array $entry): string => (string)($entry['name'] ?? ''),
			$builtInToolLoadout
		)));
		$hasRoomDocumentTool = in_array(BuiltInToolProvider::TOOL_ROOM_SEARCH, $builtInToolNames, true);
		$hasRoomImageTool = in_array(BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH, $builtInToolNames, true);
		$hasImageAttachmentTool = in_array(BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE, $builtInToolNames, true);
		$hasAudioAttachmentTool = in_array(BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO, $builtInToolNames, true);
		$hasWikiTools = count(array_intersect($builtInToolNames, BuiltInToolProvider::WIKI_TOOLS)) > 0;
		$attachmentSummary = $this->summarizeAttachmentContext($messageContext);
		$forceInitialAudioTranscription = $this->shouldForceInitialAudioTranscription($attachmentSummary, $hasAudioAttachmentTool);
		$forceInitialImageAnalysis = $this->shouldForceInitialImageAnalysis($attachmentSummary, $hasImageAttachmentTool, $messageContext['attachment_only']);

		// Product-level RAG availability stays in BotService. When available, add the
		// tool to the same explicit built-in loadout as every other executable tool.
		$ragEnabled = $effectiveBot->getRagEnabled();
		$hasRagTool = $ragEnabled && $this->hasIndexedDocuments($effectiveBot->getId());
		if ($hasRagTool && !in_array(BuiltInToolProvider::TOOL_RAG_SEARCH, $builtInToolNames, true)) {
			$builtInToolLoadout[] = [
				'name' => BuiltInToolProvider::TOOL_RAG_SEARCH,
				'config' => [],
			];
			$builtInToolNames[] = BuiltInToolProvider::TOOL_RAG_SEARCH;
		}

		// If RAG is enabled and bot has documents, include RAG tool info in system prompt
		$systemPrompt = $effectiveBot->getSystemPrompt();

		// Inject onboarding context if available
		if ($onboardingContext !== null && $onboardingContext !== '') {
			$systemPrompt .= $onboardingContext;
		}
		if ($attachmentSummary['has_images'] && $hasImageAttachmentTool) {
			$systemPrompt .= "\n\n## Current Image Attachment Context\n";
			$systemPrompt .= "The current user message includes image attachment(s). For any question about the uploaded image, call `attachment_analyze_image` before answering.\n";
			$systemPrompt .= 'Available current image attachments: ' . implode(', ', $attachmentSummary['image_names']) . ".\n";
		}
		if ($forceInitialImageAnalysis) {
			$systemPrompt .= "\n\n## CRITICAL: Image-Only Attachment Handling\n";
			$systemPrompt .= "The current user message contains exactly one image attachment and no text, audio, or document attachments.\n";
			$systemPrompt .= "Your first action MUST be to call `attachment_analyze_image` for the current attachment before writing any answer.\n";
			$systemPrompt .= "After the image analysis is available, answer the user based on the image content.\n";
		}
		if ($attachmentSummary['has_audio'] && $hasAudioAttachmentTool) {
			$systemPrompt .= "\n\n## Current Audio Attachment Context\n";
			$systemPrompt .= "The current user message includes audio or voice-message attachment(s). Call `attachment_transcribe_audio` before answering questions about spoken content.\n";
			$systemPrompt .= 'Available current audio attachments: ' . implode(', ', $attachmentSummary['audio_names']) . ".\n";
		}
		if ($forceInitialAudioTranscription) {
			$systemPrompt .= "\n\n## CRITICAL: Audio-Only Attachment Handling\n";
			$systemPrompt .= "The current user message contains exactly one audio or voice-message attachment and no image or document attachments.\n";
			$systemPrompt .= "Your first action MUST be to call `attachment_transcribe_audio` for the current attachment before writing any answer.\n";
			$systemPrompt .= "Do not call `attachment_analyze_image` or `room_search_documents` for this message.\n";
			$systemPrompt .= "After the transcription is available, answer the user based on the spoken content.\n";
		}
		if ($attachmentSummary['has_room_documents'] && $hasRoomDocumentTool) {
			$systemPrompt .= "\n\n## Current Room Document Context\n";
			$systemPrompt .= "This Talk room contains uploaded room-scoped documents for the current bot. When the user refers to files uploaded in this chat, search them with `room_search_documents` before answering.\n";
			$systemPrompt .= 'Available room-document attachments from the current turn: ' . implode(', ', $attachmentSummary['document_names']) . ".\n";
		}
		if ($hasRoomImageTool) {
			$systemPrompt .= "\n\n## Room Image Memory Context\n";
			$systemPrompt .= "This Talk room may contain indexed image analyses from previous messages. When the user refers to earlier screenshots, previous images, visual evidence across multiple messages, or asks to compare images, call `room_search_images` before answering.\n";
			if ($attachmentSummary['has_room_images']) {
				$systemPrompt .= 'Image attachments from the current turn were indexed for room image memory: ' . implode(', ', $attachmentSummary['image_names']) . ".\n";
			}
		}
		if ($hasRagTool) {
			$systemPrompt .= "\n\n## Document Search Instructions\n";
			$systemPrompt .= "You have access to a knowledge base with indexed documents. Use it when it is relevant to the user's requested scope:\n\n";
			if ($attachmentSummary['has_room_documents'] && $hasRoomDocumentTool) {
				$systemPrompt .= "1. **Respect explicit scope**: If the user explicitly requests a different source or tool and does not ask about these documents, follow that request without calling a document-search tool.\n";
				$systemPrompt .= "2. **Prefer room documents first**: If the question is about files uploaded in the current Talk chat, call `room_search_documents` first.\n";
				$systemPrompt .= "3. **Use the global KB for bot knowledge**: For the bot's permanent knowledge base, call `rag_search_documents`.\n";
				$systemPrompt .= "4. **Do not guess about documents**: Search before answering when the answer should come from uploaded documents.\n";
				$systemPrompt .= "5. **How to search**: Convert the user's question into search keywords.\n";
				$systemPrompt .= "6. **After searching**: Read the returned chunks carefully and synthesize an answer based on the document content.\n";
				$systemPrompt .= "7. **If no results**: Tell the user you couldn't find that information in the documents.\n";
				$systemPrompt .= "8. **Never output raw JSON**: Use proper tool calls, not JSON text in your response.\n";
			} else {
				$systemPrompt .= "1. **Respect explicit scope**: If the user explicitly requests a different source or tool and does not ask about the knowledge base, follow that request without calling `rag_search_documents`.\n";
				$systemPrompt .= "2. **Search when relevant**: When the user asks about the bot's indexed documents, call `rag_search_documents` before answering.\n";
				$systemPrompt .= "3. **Do not guess about documents**: Search before answering when the answer should come from the knowledge base.\n";
				$systemPrompt .= "4. **How to search**: Convert the user's question into search keywords.\n";
				$systemPrompt .= "   - User asks: \"Was ist der Titel von Modul 3?\" → Search: \"Modul 3 Titel\"\n";
				$systemPrompt .= "   - User asks: \"What are the requirements?\" → Search: \"requirements prerequisites\"\n";
				$systemPrompt .= "5. **After searching**: Read the returned chunks carefully and synthesize an answer based on the document content.\n";
				$systemPrompt .= "6. **If no results**: Tell the user you couldn't find that information in the documents.\n";
				$systemPrompt .= "7. **Never output raw JSON**: Use proper tool calls, not JSON text in your response.\n";
			}
		}
		if ($hasWikiTools) {
			$systemPrompt .= "\n\n## Wiki Instructions\n";
			$systemPrompt .= 'You have access to a persistent ' . \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME . " Markdown wiki for this bot. Use it for durable knowledge, not temporary chat context.\n";
			$systemPrompt .= "Search the wiki before answering questions about durable personal or bot knowledge.\n";
			$systemPrompt .= "When `wiki_read_page` returns `has_more=true`, continue reading with `offset=next_offset` before finishing any incomplete file review. If you intentionally stop before the full file was reviewed, explicitly mention the page path and `next_offset` needed to continue.\n";
			$systemPrompt .= "Only save new information when the user explicitly asks you to save, remember, document, or maintain it.\n";
			$systemPrompt .= "If the user explicitly asks you to write, save, document, or remember information in the wiki, call `wiki_write_page` before claiming that it was written or saved.\n";
			$systemPrompt .= 'After accepted wiki updates, check whether `index.md` needs a curated overview update: add or revise short summaries, topic/entity groupings, important pages, open questions, or synthesis notes. Leave the `Existing Files` section to ' . \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME . " automation.\n";
			$systemPrompt .= "Append a concise event to `log.md` after accepted wiki updates.\n";
			$systemPrompt .= "Treat the wiki tool response as authoritative for where a page was stored.\n";
		}

		$initialToolChoice = $this->buildInitialToolChoice(
			$forceInitialAudioTranscription
				? BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO
				: ($forceInitialImageAnalysis ? BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE : null)
		);

		$partialBuffer = '';
		$currentAssistantTurnText = '';
		$releaseAssistantBuffer = function () use ($onProgress, &$partialBuffer): void {
			if ($onProgress === null || $partialBuffer === '') {
				return;
			}

			$chunk = $partialBuffer;
			$partialBuffer = '';
			if (trim($chunk) !== '') {
				$onProgress($chunk);
			}
		};
		$discardAssistantBuffer = static function () use (&$partialBuffer, &$currentAssistantTurnText): void {
			$partialBuffer = '';
			$currentAssistantTurnText = '';
		};
		$assistantProgress = $onProgress === null
			? null
			: static function (string $partial) use (&$partialBuffer, &$currentAssistantTurnText): void {
				if ($partial === '') {
					return;
				}

				$partialBuffer .= $partial;
				$currentAssistantTurnText .= $partial;
			};
		$toolProgress = static function (string $progress) use ($onToolProgress, $discardAssistantBuffer): void {
			$discardAssistantBuffer();
			if ($onToolProgress !== null && $progress !== '') {
				$onToolProgress($progress);
			}
		};

		try {
			$this->logger->info('Calling LLM API', [
				'bot_id' => $effectiveBot->getId(),
				'message_count' => count($messages),
				'mcp_tools_enabled' => count($toolLoadout),
				'builtin_tools_enabled' => count($builtInToolLoadout),
				'rag_tool_enabled' => $hasRagTool,
				'trace_run_id' => $traceRunId,
			]);

			try {
				$this->toolProviderRegistry->setInvocationContext([
					'bot_id' => $effectiveBot->getId(),
					'room_token' => $roomToken,
					'attachments' => $messageContext['attachments'],
					'document_source_ids' => $messageContext['document_source_ids'],
					'image_source_ids' => $messageContext['image_source_ids'],
				]);

				$agentOptions = array_filter([
					'model' => $effectiveBot->getModel(),
					'temperature' => $resolvedTemperature,
					'initial_tool_choice' => $initialToolChoice,
					'on_partial_result' => $assistantProgress,
					'bot_id' => $effectiveBot->getId(),
					'built_in_tools' => $builtInToolLoadout,
					'trace_run_id' => $traceRunId,
				], static fn ($value) => $value !== null);
				$agentOptions['on_tool_progress'] = $toolProgress;

				$agentResult = $this->agentExecutor->run(
					$systemPrompt,
					$messages,
					$toolLoadout,
					$agentOptions
				);
			} finally {
				$this->toolProviderRegistry->setInvocationContext(null);
			}

			$agentStatus = $agentResult['status'] ?? null;
			if (!is_string($agentStatus) || !in_array($agentStatus, ['completed', 'error', 'budget_exhausted'], true)) {
				throw new Exception('Agent execution returned an invalid status');
			}

			$terminalReason = $agentResult['terminalReason'] ?? null;
			if (!is_string($terminalReason) || trim($terminalReason) === '') {
				throw new Exception('Agent execution returned an invalid terminal reason');
			}

			$assistantMessage = $agentResult['content'] ?? null;
			if (!is_string($assistantMessage)) {
				throw new Exception('Agent execution returned invalid content');
			}
			$assistantMessage = AssistantContentSanitizer::sanitize($assistantMessage);

			$toolInvocations = $agentResult['toolInvocations'] ?? null;
			if (!is_array($toolInvocations)) {
				throw new Exception('Agent execution returned invalid tool invocations');
			}

			$rateLimitHeaders = $agentResult['rateLimitHeaders'] ?? null;
			if (!is_array($rateLimitHeaders)) {
				throw new Exception('Agent execution returned invalid rate-limit headers');
			}
			$this->rateLimitService->updateFromHeaders($rateLimitHeaders);

			if ($agentStatus !== 'completed') {
				$discardAssistantBuffer();
				throw new Exception('Agent execution terminated: ' . $terminalReason);
			}
			if (trim($assistantMessage) === '') {
				$discardAssistantBuffer();
				throw new Exception('Agent execution completed without assistant content');
			}

			if ($onProgress !== null) {
				$sanitizedStreamedText = AssistantContentSanitizer::sanitize($currentAssistantTurnText);
				if ($sanitizedStreamedText !== $currentAssistantTurnText) {
					$partialBuffer = $sanitizedStreamedText;
					$currentAssistantTurnText = $sanitizedStreamedText;
				}

				if ($currentAssistantTurnText === '') {
					$partialBuffer .= $assistantMessage;
					$currentAssistantTurnText = $assistantMessage;
				} elseif ($currentAssistantTurnText !== $assistantMessage && str_starts_with($assistantMessage, $currentAssistantTurnText)) {
					$missingSuffix = substr($assistantMessage, strlen($currentAssistantTurnText));
					$partialBuffer .= $missingSuffix;
					$currentAssistantTurnText = $assistantMessage;
				} elseif ($currentAssistantTurnText !== $assistantMessage) {
					$this->logger->warning('Streamed assistant text does not match terminal content', [
						'bot_id' => $effectiveBot->getId(),
						'trace_run_id' => $traceRunId,
						'streamed_length' => strlen($currentAssistantTurnText),
						'terminal_length' => strlen($assistantMessage),
					]);
					if ($onStreamMismatch !== null) {
						try {
							$onStreamMismatch();
						} catch (\Throwable $statusCallbackError) {
							$this->logger->warning('Failed to propagate stream mismatch status', [
								'bot_id' => $effectiveBot->getId(),
								'trace_run_id' => $traceRunId,
								'exception' => $statusCallbackError,
							]);
						}
					}
					$partialBuffer = $assistantMessage;
					$currentAssistantTurnText = $assistantMessage;
				}
			}
			$releaseAssistantBuffer();

			$this->logger->info('Got LLM response', [
				'bot_id' => $effectiveBot->getId(),
				'response_length' => strlen($assistantMessage),
				'tool_invocations' => count($toolInvocations),
				'trace_run_id' => $traceRunId,
			]);

			$this->traceService?->recordEvent($traceRunId, 'assistant_response', [
				'status' => 'ok',
				'payload' => [
					'bot_id' => $effectiveBot->getId(),
					'tool_invocations' => count($toolInvocations),
				],
				'result' => [
					'content' => $assistantMessage,
				],
			]);

			// Note: Tool invocations are intentionally NOT persisted to the database.
			// They are session-specific (only needed during the agent loop) and storing them
			// would quickly fill up the conversation history limit, wasting context tokens.
			// The assistant's final response already synthesizes the relevant tool results.

			$assistantConversation = new Conversation();
			$assistantConversation->setBotId($effectiveBot->getId());
			$assistantConversation->setRoomToken($roomToken);
			$assistantConversation->setThreadRootMessageId($threadRootMessageId);
			$assistantConversation->setUserId($effectiveBot->getMentionName());
			$assistantConversation->setRole('assistant');
			$assistantConversation->setContent($assistantMessage);
			$assistantConversation->setCreatedAt(time());
			$this->conversationMapper->insert($assistantConversation);

			return $assistantMessage;
		} catch (Exception $e) {
			$discardAssistantBuffer();
			$this->logger->error('Failed to process bot message: ' . $e->getMessage(), [
				'bot_id' => $effectiveBot->getId(),
				'trace_run_id' => $traceRunId,
				'exception' => $e,
			]);

			$this->traceService?->recordEvent($traceRunId, 'error', [
				'status' => 'error',
				'payload' => [
					'stage' => 'bot_service',
					'bot_id' => $effectiveBot->getId(),
				],
				'error_message' => $e->getMessage(),
			]);
			if ($onExecutionError !== null) {
				try {
					$onExecutionError($e->getMessage());
				} catch (\Throwable $statusCallbackError) {
					$this->logger->warning('Failed to propagate bot execution status', [
						'bot_id' => $effectiveBot->getId(),
						'trace_run_id' => $traceRunId,
						'exception' => $statusCallbackError,
					]);
				}
			}

			return self::AI_SERVICE_UNAVAILABLE_MESSAGE;
		}
	}

	/**
	 * @param MessageContextInput|null $messageContext
	 * @return MessageContext
	 */
	private function normalizeMessageContext(?array $messageContext): array {
		if ($messageContext === null) {
			return [
				'attachments' => [],
				'document_source_ids' => [],
				'image_source_ids' => [],
				'attachment_only' => false,
			];
		}

		return [
			'attachments' => isset($messageContext['attachments']) && is_array($messageContext['attachments'])
				? array_values(array_filter(
					$messageContext['attachments'],
					static fn ($attachment): bool => $attachment instanceof IncomingTalkAttachment
				))
				: [],
			'document_source_ids' => isset($messageContext['document_source_ids']) && is_array($messageContext['document_source_ids'])
				? array_values(array_map('intval', $messageContext['document_source_ids']))
				: [],
			'image_source_ids' => isset($messageContext['image_source_ids']) && is_array($messageContext['image_source_ids'])
				? array_values(array_map('intval', $messageContext['image_source_ids']))
				: [],
			'attachment_only' => isset($messageContext['attachment_only']) && $messageContext['attachment_only'] === true,
		];
	}

	/**
	 * @param MessageContext $messageContext
	 */
	private function hasConversationAttachmentContext(array $messageContext): bool {
		return count($messageContext['attachments']) > 0 || count($messageContext['document_source_ids']) > 0 || count($messageContext['image_source_ids']) > 0;
	}

	/**
	 * @param MessageContext $messageContext
	 * @return AttachmentSummary
	 */
	private function summarizeAttachmentContext(array $messageContext): array {
		$imageNames = [];
		$audioNames = [];
		$documentNames = [];

		foreach ($messageContext['attachments'] as $attachment) {
			$kind = $attachment->getKind();
			$name = $attachment->getDisplayName();

			if ($kind === 'image') {
				$imageNames[] = $name;
			} elseif ($kind === 'audio') {
				$audioNames[] = $name;
			} elseif ($kind === 'document') {
				$documentNames[] = $name;
			}
		}

		return [
			'has_images' => count($imageNames) > 0,
			'has_audio' => count($audioNames) > 0,
			'has_room_documents' => count($messageContext['document_source_ids']) > 0,
			'has_room_images' => count($messageContext['image_source_ids']) > 0,
			'image_names' => $imageNames,
			'audio_names' => $audioNames,
			'document_names' => $documentNames,
		];
	}

	/**
	 * @param AttachmentSummary $attachmentSummary
	 */
	private function shouldForceInitialAudioTranscription(array $attachmentSummary, bool $hasAudioAttachmentTool): bool {
		return $hasAudioAttachmentTool
			&& !$attachmentSummary['has_images']
			&& !$attachmentSummary['has_room_documents']
			&& count($attachmentSummary['audio_names']) === 1;
	}

	/**
	 * @param AttachmentSummary $attachmentSummary
	 */
	private function shouldForceInitialImageAnalysis(array $attachmentSummary, bool $hasImageAttachmentTool, bool $attachmentOnly): bool {
		return $attachmentOnly
			&& $hasImageAttachmentTool
			&& !$attachmentSummary['has_audio']
			&& !$attachmentSummary['has_room_documents']
			&& count($attachmentSummary['image_names']) === 1;
	}

	/**
	 * @param MessageContext $messageContext
	 */
	private function appendAttachmentHistoryContext(string $message, array $messageContext): string {
		$labels = [];
		foreach ($messageContext['attachments'] as $attachment) {
			if (!$attachment instanceof IncomingTalkAttachment) {
				continue;
			}
			$labels[] = $attachment->getKind() . ': ' . $attachment->getDisplayName();
		}

		if ($labels === []) {
			return $message;
		}

		return rtrim($message) . "\n\n[Attachments: " . implode('; ', $labels) . ']';
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function buildInitialToolChoice(?string $toolName): ?array {
		if ($toolName === null || trim($toolName) === '') {
			return null;
		}

		return [
			'type' => 'function',
			'function' => [
				'name' => $toolName,
			],
		];
	}

	/**
	 * Check if a bot has any indexed documents in the RAG knowledge base
	 */
	private function hasIndexedDocuments(int $botId): bool {
		try {
			$embeddings = $this->embeddingMapper->findByBot($botId);
			return count($embeddings) > 0;
		} catch (Exception $e) {
			$this->logger->warning('Failed to check indexed documents', [
				'bot_id' => $botId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * @return array<int,string>
	 */
	public function getEffectiveBuiltInToolNames(Bot $bot, string $userId): array {
		$toolLoadout = $this->resolveEffectiveToolLoadout($bot, $userId);
		return array_values(array_filter(array_map(
			static fn (array $entry): string => (string)($entry['name'] ?? ''),
			$toolLoadout['built_in']
		)));
	}

	/**
	 * Find a bot by its mention name
	 *
	 * @param string $mentionName
	 * @return Bot
	 * @throws DoesNotExistException
	 */
	public function findByMentionName(string $mentionName): Bot {
		return $this->botMapper->findByMentionName($mentionName);
	}

	public function getEffectiveBotForUser(Bot $bot, string $userId): Bot {
		return $this->resolveEffectiveBotForUser($bot, $userId);
	}

	/**
	 * Determine if a user can access a bot based on visibility and groups
	 */
	public function userCanAccessBot(Bot $bot, string $userId): bool {
		$normalizedUserId = $this->normalizeExternalUserId($userId);

		// Owner is always allowed
		if ($bot->getUserId() === $normalizedUserId) {
			return true;
		}

		// Check approval status
		$status = $bot->getApprovalStatus() ?? 'approved';

		// Draft bots are only accessible to owner (already handled above)
		if ($status === 'draft') {
			return false;
		}

		// Personal bots are only accessible to owner (already handled above)
		if ($status === 'personal') {
			return false;
		}

		if ($status === 'pending' && $this->userCanTestPendingBot($bot, $normalizedUserId)) {
			return true;
		}

		if ($status === 'pending' && $this->isInitialPendingApproval($bot)) {
			return false;
		}

		// Pending updates of already approved bots keep using the live scope.

		// Back-compat: old installs may rely on is_public
		$visibility = $bot->getVisibility();
		if ($visibility === null || $visibility === '') {
			if ($bot->getIsPublic()) {
				return true; // legacy public behaves as global
			}
			// Otherwise treat as group-limited with possibly empty groups
			$visibility = 'groups';
		}

		if ($visibility === 'global') {
			return true;
		}

		if ($visibility === 'teams') {
			$user = $this->userManager->get($normalizedUserId);
			if ($user === null) {
				return false;
			}
			return $this->userInAllowedTeams($bot, $normalizedUserId, $user);
		}

		if ($visibility !== 'groups') {
			// Unknown mode: deny for safety
			return false;
		}

		$user = $this->userManager->get($normalizedUserId);
		if ($user === null) {
			return false;
		}

		// Allowed group IDs configured on the bot
		$allowedGroupIds = $this->decodeIdList($bot->getAllowedGroups());
		if (count($allowedGroupIds) === 0) {
			return false;
		}

		// Prefer authoritative membership check via GroupManager::isInGroup
		if (is_callable([$this->groupManager, 'isInGroup'])) {
			$uid = method_exists($user, 'getUID') ? $user->getUID() : $normalizedUserId;
			foreach ($allowedGroupIds as $gid) {
				try {
					if ($this->groupManager->isInGroup($uid, $gid)) {
						return true;
					}
				} catch (\Throwable $e) {
					// Fall through to intersection-based check below on any failure
					break;
				}
			}
		}

		// Fallback: compute intersection from user's groups when direct check didn't succeed
		$userGroups = $this->groupManager->getUserGroups($user);
		$userGroupIds = array_map(static function ($g) {
			return method_exists($g, 'getGID') ? $g->getGID() : (string)$g;
		}, $userGroups);

		return count(array_intersect($userGroupIds, $allowedGroupIds)) > 0;
	}

	/**
	 * Get bots visible to a user, only active.
	 *
	 * Includes global, matching group/team bots, and the user's own personal bots.
	 *
	 * @param string $userId
	 * @return Bot[]
	 */
	public function getAvailableBotsForUser(string $userId): array {
		$allActive = $this->botMapper->findAllActive();
		$result = [];
		foreach ($allActive as $bot) {
			$visibility = $bot->getVisibility();
			if ($visibility === null || $visibility === '') {
				if ($bot->getIsPublic()) {
					$visibility = 'global';
				} else {
					$visibility = 'groups';
				}
			}
			if ($visibility !== 'global' && $visibility !== 'groups' && $visibility !== 'teams' && $visibility !== 'personal') {
				continue;
			}
			if ($this->userCanAccessBot($bot, $userId)) {
				$result[] = $bot;
			}
		}
		return $result;
	}

	/**
	 * Get bots visible to a user with enriched data for public listing.
	 * Returns bot data plus owner display name and access reason.
	 * Includes global, matching group/team bots, and the user's own personal bots.
	 *
	 * @param string $userId
	 * @return array<int,array<string,mixed>>
	 */
	public function getAvailableBotsForUserEnriched(string $userId): array {
		$allActive = $this->botMapper->findAllActive();
		$result = [];
		$user = $this->userManager->get($userId);

		foreach ($allActive as $bot) {
			$visibility = $bot->getVisibility();
			if ($visibility === null || $visibility === '') {
				if ($bot->getIsPublic()) {
					$visibility = 'global';
				} else {
					$visibility = 'groups';
				}
			}
			if ($visibility !== 'global' && $visibility !== 'groups' && $visibility !== 'teams' && $visibility !== 'personal') {
				continue;
			}

			$accessReason = $this->getAccessReason($bot, $userId, $user);
			if ($accessReason === null) {
				continue; // User doesn't have access
			}

			// Get owner display name
			$ownerUser = $this->userManager->get($bot->getUserId());
			$ownerDisplayName = $ownerUser !== null ? $ownerUser->getDisplayName() : $bot->getUserId();

			$botData = $bot->jsonSerialize();
			$botData['owner_display_name'] = $ownerDisplayName;
			$botData['access_reason'] = $accessReason;

			$result[] = $botData;
		}

		return $result;
	}

	/**
	 * Get detailed info about a public bot for the detail modal.
	 *
	 * @param int $botId
	 * @param string $userId
	 * @return array<string,mixed>|null
	 */
	public function getPublicBotDetails(int $botId, string $userId): ?array {
		try {
			$bot = $this->botMapper->findById($botId);
		} catch (DoesNotExistException $e) {
			return null;
		}

		if (!$bot->getIsActive()) {
			return null;
		}

		$user = $this->userManager->get($userId);
		$accessReason = $this->getAccessReason($bot, $userId, $user);
		if ($accessReason === null) {
			return null; // User doesn't have access
		}

		// Get owner display name
		$ownerUser = $this->userManager->get($bot->getUserId());
		$ownerDisplayName = $ownerUser !== null ? $ownerUser->getDisplayName() : $bot->getUserId();

		// Get tools assigned to this bot (public info only, no configs)
		$tools = [];

		// MCP tools
		$mcpAssignments = $this->toolRegistry->getToolsForBot($botId);
		foreach ($mcpAssignments as $entry) {
			$tool = $entry['tool'];
			$tools[] = [
				'name' => $tool->getName(),
				'description' => $tool->getDescription(),
				'is_builtin' => false,
			];
		}

		// Built-in tools
		$builtInAssignments = $this->toolRegistry->getBuiltInToolsForBot($botId);
		foreach ($builtInAssignments as $entry) {
			$tools[] = [
				'name' => $this->formatBuiltInToolName($entry['name']),
				'description' => $this->getBuiltInToolDescription($entry['name']),
				'is_builtin' => true,
				'builtin_name' => $entry['name'],
			];
		}

		// Count RAG sources
		$ragSourceCount = 0;
		if ($bot->getRagEnabled()) {
			try {
				$sources = $this->botSourceMapper->findByBot($botId);
				$ragSourceCount = count($sources);
			} catch (\Throwable $e) {
				// Ignore
			}
		}

		$botData = $bot->jsonSerialize();
		$botData['owner_display_name'] = $ownerDisplayName;
		$botData['access_reason'] = $accessReason;
		$botData['tools'] = $tools;
		$botData['rag_source_count'] = $ragSourceCount;

		return $botData;
	}

	/**
	 * Determine why a user has access to a bot and return the reason.
	 *
	 * @param Bot $bot
	 * @param string $userId
	 * @param IUser|null $user
	 * @return array{type:string,names:array<string>}|null Null if no access
	 */
	private function getAccessReason(Bot $bot, string $userId, ?IUser $user): ?array {
		$normalizedUserId = $this->normalizeExternalUserId($userId);

		// Owner always has access
		if ($bot->getUserId() === $normalizedUserId) {
			return ['type' => 'owner', 'names' => []];
		}

		// Check approval status - draft and personal bots only accessible to owner
		$status = $bot->getApprovalStatus() ?? 'approved';
		if ($status === 'draft' || $status === 'personal') {
			return null;
		}

		if ($status === 'pending' && $this->isInitialPendingApproval($bot)) {
			return null;
		}

		$visibility = $bot->getVisibility();
		if ($visibility === null || $visibility === '') {
			if ($bot->getIsPublic()) {
				return ['type' => 'global', 'names' => []];
			}
			$visibility = 'groups';
		}

		if ($visibility === 'global') {
			return ['type' => 'global', 'names' => []];
		}

		if ($user === null) {
			return null;
		}

		if ($visibility === 'teams') {
			$matchingTeams = $this->getMatchingTeams($bot, $normalizedUserId, $user);
			if (count($matchingTeams) > 0) {
				return ['type' => 'team', 'names' => $matchingTeams];
			}
			return null;
		}

		if ($visibility === 'groups') {
			$matchingGroups = $this->getMatchingGroups($bot, $user);
			if (count($matchingGroups) > 0) {
				return ['type' => 'group', 'names' => $matchingGroups];
			}
			return null;
		}

		return null;
	}

	/**
	 * Get names of groups that grant the user access to the bot.
	 *
	 * @param Bot $bot
	 * @param IUser $user
	 * @return array<string>
	 */
	private function getMatchingGroups(Bot $bot, IUser $user): array {
		$allowedGroupIds = $this->decodeIdList($bot->getAllowedGroups());
		if (count($allowedGroupIds) === 0) {
			return [];
		}

		$userGroups = $this->groupManager->getUserGroups($user);
		$userGroupIds = array_map(static function ($g) {
			return method_exists($g, 'getGID') ? $g->getGID() : (string)$g;
		}, $userGroups);

		$matchingIds = array_intersect($userGroupIds, $allowedGroupIds);
		$matchingNames = [];

		foreach ($userGroups as $group) {
			$gid = method_exists($group, 'getGID') ? $group->getGID() : (string)$group;
			if (in_array($gid, $matchingIds, true)) {
				$displayName = method_exists($group, 'getDisplayName') ? $group->getDisplayName() : $gid;
				$matchingNames[] = $displayName;
			}
		}

		return $matchingNames;
	}

	/**
	 * Get names of teams that grant the user access to the bot.
	 *
	 * @param Bot $bot
	 * @param string $userId
	 * @param IUser $user
	 * @return array<string>
	 */
	private function getMatchingTeams(Bot $bot, string $userId, IUser $user): array {
		if (!class_exists('\\OCA\\Circles\\Api\\v1\\Circles')) {
			return [];
		}

		try {
			if (method_exists($this->appManager, 'isEnabledForUser')) {
				if (!$this->appManager->isEnabledForUser('circles', $user)) {
					return [];
				}
			}
		} catch (\Throwable $e) {
			return [];
		}

		$allowedTeams = $this->decodeIdList($bot->getAllowedTeams());
		if (count($allowedTeams) === 0) {
			return [];
		}

		$matchingNames = [];
		foreach ($allowedTeams as $teamId) {
			try {
				$membership = \OCA\Circles\Api\v1\Circles::getMember($teamId, $userId, \OCA\Circles\Api\v1\Circles::TYPE_USER, true);
				if ($membership !== null) {
					// Try to get team name
					try {
						$circle = \OCA\Circles\Api\v1\Circles::detailsCircle($teamId);
						$matchingNames[] = $circle->getName() ?? $teamId;
					} catch (\Throwable $e) {
						$matchingNames[] = $teamId;
					}
				}
			} catch (\Throwable $e) {
				// Ignore
			}
		}

		return $matchingNames;
	}

	/**
	 * @param array<int,string> $teamIds
	 * @return array<int,string>
	 */
	private function resolveTeamDisplayNames(array $teamIds): array {
		if ($teamIds === []) {
			return [];
		}

		if (!class_exists('\\OCA\\Circles\\Api\\v1\\Circles')) {
			return $teamIds;
		}

		$names = [];
		foreach ($teamIds as $teamId) {
			$displayName = '';
			try {
				$circle = \OCA\Circles\Api\v1\Circles::detailsCircle($teamId);
				if (method_exists($circle, 'getDisplayName')) {
					$displayName = (string)$circle->getDisplayName();
				}
				if ($displayName === '' && method_exists($circle, 'getName')) {
					$displayName = (string)$circle->getName();
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Failed to resolve team display name', [
					'team_id' => $teamId,
					'exception' => $e,
				]);
			}

			$names[] = $displayName !== '' ? $displayName : $teamId;
		}

		return $names;
	}

	/**
	 * Format built-in tool name for display.
	 */
	private function formatBuiltInToolName(string $name): string {
		$providerMetadata = $this->toolProviderRegistry->getToolMetadata($name);
		if (is_array($providerMetadata) && isset($providerMetadata['label']) && is_string($providerMetadata['label']) && $providerMetadata['label'] !== '') {
			return $providerMetadata['label'];
		}

		$mapping = [
			BuiltInToolProvider::TOOL_RAG_SEARCH => 'Document Search (RAG)',
			BuiltInToolProvider::TOOL_ROOM_SEARCH => 'Room Document Search',
			BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH => 'Room Image Search',
			BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE => 'Image Attachment Analysis',
			BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO => 'Audio Attachment Transcription',
			BuiltInToolProvider::TOOL_WIKI_SEARCH => 'Wiki Search',
			BuiltInToolProvider::TOOL_WIKI_READ_PAGE => 'Wiki Read Page',
			BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE => 'Wiki Write Page',
			BuiltInToolProvider::TOOL_WIKI_LOG_EVENT => 'Wiki Log Event',
		];
		return $mapping[$name] ?? ucfirst(str_replace('_', ' ', $name));
	}

	/**
	 * Get description for built-in tool.
	 */
	private function getBuiltInToolDescription(string $name): string {
		$providerMetadata = $this->toolProviderRegistry->getToolMetadata($name);
		if (is_array($providerMetadata) && isset($providerMetadata['summary']) && is_string($providerMetadata['summary']) && $providerMetadata['summary'] !== '') {
			return $providerMetadata['summary'];
		}

		$mapping = [
			BuiltInToolProvider::TOOL_RAG_SEARCH => 'Search through indexed documents attached to this bot.',
			BuiltInToolProvider::TOOL_ROOM_SEARCH => 'Search documents uploaded in the current Nextcloud Talk room. Can be used together with the bot\'s global document search.',
			BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH => 'Search image analyses from screenshots and photos uploaded in the current Nextcloud Talk room.',
			BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE => 'Analyze image attachments from the current Talk message.',
			BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO => 'Transcribe audio or voice-message attachments from the current Talk message.',
			BuiltInToolProvider::TOOL_WIKI_SEARCH => 'Search the bot\'s persistent Markdown wiki.',
			BuiltInToolProvider::TOOL_WIKI_READ_PAGE => 'Read one page from the bot\'s persistent Markdown wiki.',
			BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE => 'Create, update, or append pages in the bot\'s persistent Markdown wiki.',
			BuiltInToolProvider::TOOL_WIKI_LOG_EVENT => 'Append a maintenance event to the bot wiki log.',
		];
		return $mapping[$name] ?? '';
	}

	/**
	 * @return array{
	 *   visibility:string,
	 *   allowed_groups:array<int,string>,
	 *   allowed_teams:array<int,string>,
	 *   model:?string,
	 *   temperature:?float,
	 *   description:?string,
	 *   rag_enabled:bool,
	 *   onboarding_questions:?array
	 * }
	 */
	private function getEditableBotState(Bot $bot): array {
		$visibility = $this->normalizeVisibilityValue($bot->getVisibility(), $bot->getIsPublic());
		$state = [
			'visibility' => $visibility,
			'allowed_groups' => $visibility === 'groups' ? $this->decodeIdList($bot->getAllowedGroups()) : [],
			'allowed_teams' => $visibility === 'teams' ? $this->decodeIdList($bot->getAllowedTeams()) : [],
			'model' => $bot->getModel(),
			'temperature' => $bot->getTemperature(),
			'description' => $bot->getDescription(),
			'rag_enabled' => $bot->getRagEnabled(),
			'onboarding_questions' => $bot->getOnboardingQuestionsArray(),
		];

		$pendingChanges = $bot->getPendingChangesArray();
		if ($pendingChanges === null) {
			return $state;
		}

		if (isset($pendingChanges['visibility']) && is_string($pendingChanges['visibility']) && $pendingChanges['visibility'] !== '') {
			$state['visibility'] = $pendingChanges['visibility'];
		}
		if (array_key_exists('allowed_groups', $pendingChanges)) {
			$state['allowed_groups'] = $this->normalizeIdArray($pendingChanges['allowed_groups']);
		}
		if (array_key_exists('allowed_teams', $pendingChanges)) {
			$state['allowed_teams'] = $this->normalizeIdArray($pendingChanges['allowed_teams']);
		}
		if (array_key_exists('model', $pendingChanges)) {
			$state['model'] = is_string($pendingChanges['model']) ? $pendingChanges['model'] : null;
		}
		if (array_key_exists('temperature', $pendingChanges)) {
			$state['temperature'] = is_numeric($pendingChanges['temperature']) ? (float)$pendingChanges['temperature'] : null;
		}
		if (array_key_exists('description', $pendingChanges)) {
			$state['description'] = is_string($pendingChanges['description']) ? $pendingChanges['description'] : null;
		}
		if (array_key_exists('rag_enabled', $pendingChanges)) {
			$state['rag_enabled'] = (bool)$pendingChanges['rag_enabled'];
		}
		if (array_key_exists('onboarding_questions', $pendingChanges)) {
			$state['onboarding_questions'] = is_array($pendingChanges['onboarding_questions'])
				? $pendingChanges['onboarding_questions']
				: null;
		}

		if ($state['visibility'] !== 'groups') {
			$state['allowed_groups'] = [];
		}
		if ($state['visibility'] !== 'teams') {
			$state['allowed_teams'] = [];
		}

		return $state;
	}

	private function resolveTargetVisibility(string $currentVisibility, ?string $visibility, ?bool $isPublic): string {
		if ($visibility !== null && $visibility !== '') {
			return $visibility;
		}

		if ($isPublic === true) {
			return 'global';
		}

		if ($isPublic === false && $currentVisibility === 'global') {
			return 'groups';
		}

		return $currentVisibility;
	}

	private function normalizeVisibilityValue(?string $visibility, bool $isPublic): string {
		if ($visibility === null || $visibility === '') {
			return $isPublic ? 'global' : 'groups';
		}

		return $visibility;
	}

	private function isSharedBot(Bot $bot): bool {
		return $this->normalizeVisibilityValue($bot->getVisibility(), $bot->getIsPublic()) !== 'personal';
	}

	private function isInitialPendingApproval(Bot $bot): bool {
		$status = $bot->getApprovalStatus() ?? 'approved';
		return $status === 'pending'
			&& !$bot->hasPendingChanges()
			&& $bot->getApprovedAt() === null;
	}

	private function normalizeExternalUserId(string $userId): string {
		if (strpos($userId, '/') === false) {
			return $userId;
		}

		$parts = explode('/', $userId);
		$tail = end($parts);
		return is_string($tail) && $tail !== '' ? $tail : $userId;
	}

	/**
	 * @param array<int,int|string>|string|null $ids
	 * @return array<int,string>
	 */
	private function normalizeIdArray(array|string|null $ids): array {
		if (is_string($ids)) {
			return $this->decodeIdList($ids);
		}

		if (!is_array($ids)) {
			return [];
		}

		$normalized = [];
		foreach ($ids as $value) {
			if (is_string($value) || is_numeric($value)) {
				$trimmed = trim((string)$value);
				if ($trimmed !== '') {
					$normalized[] = $trimmed;
				}
			}
		}

		return $normalized;
	}

	private function encodeIdList(?array $ids): string {
		if ($ids === null) {
			return '[]';
		}

		$normalized = [];
		foreach ($ids as $value) {
			if (is_string($value) || is_numeric($value)) {
				$trimmed = trim((string)$value);
				if ($trimmed !== '') {
					$normalized[] = $trimmed;
				}
			}
		}

		return json_encode($normalized) ?: '[]';
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

	private function userCanTestPendingBot(Bot $bot, string $userId): bool {
		$testingEnabledBy = $bot->getTestingEnabledBy();
		if ($testingEnabledBy === null || $testingEnabledBy === '') {
			return false;
		}

		return $this->normalizeExternalUserId($testingEnabledBy) === $userId;
	}

	private function resolveEffectiveBotForUser(Bot $bot, string $userId): Bot {
		$status = $bot->getApprovalStatus() ?? 'approved';
		if ($status !== 'pending') {
			return $bot;
		}

		$normalizedUserId = $this->normalizeExternalUserId($userId);
		if ($bot->getUserId() === $normalizedUserId || $this->userCanTestPendingBot($bot, $normalizedUserId)) {
			return $this->createPendingTargetBot($bot);
		}

		return $bot;
	}

	private function createPendingTargetBot(Bot $bot): Bot {
		$pendingChanges = $bot->getPendingChangesArray();
		if ($pendingChanges === null) {
			return $bot;
		}

		$targetBot = clone $bot;

		if (array_key_exists('bot_name', $pendingChanges) && is_string($pendingChanges['bot_name'])) {
			$targetBot->setBotName($pendingChanges['bot_name']);
		}
		if (array_key_exists('system_prompt', $pendingChanges) && is_string($pendingChanges['system_prompt'])) {
			$targetBot->setSystemPrompt($pendingChanges['system_prompt']);
		}
		if (array_key_exists('description', $pendingChanges)) {
			$targetBot->setDescription(is_string($pendingChanges['description']) ? $pendingChanges['description'] : null);
		}
		if (array_key_exists('model', $pendingChanges)) {
			$targetBot->setModel(is_string($pendingChanges['model']) ? $pendingChanges['model'] : null);
		}
		if (array_key_exists('temperature', $pendingChanges)) {
			$targetBot->setTemperature(is_numeric($pendingChanges['temperature']) ? (float)$pendingChanges['temperature'] : null);
		}
		if (array_key_exists('visibility', $pendingChanges) && is_string($pendingChanges['visibility']) && $pendingChanges['visibility'] !== '') {
			$targetBot->setVisibility($pendingChanges['visibility']);
		}
		if (array_key_exists('is_public', $pendingChanges)) {
			$targetBot->setIsPublic((bool)$pendingChanges['is_public']);
		}
		if (array_key_exists('allowed_groups', $pendingChanges)) {
			$targetBot->setAllowedGroups($this->encodeIdList($this->normalizeIdArray($pendingChanges['allowed_groups'])));
		}
		if (array_key_exists('allowed_teams', $pendingChanges)) {
			$targetBot->setAllowedTeams($this->encodeIdList($this->normalizeIdArray($pendingChanges['allowed_teams'])));
		}
		if (array_key_exists('rag_enabled', $pendingChanges)) {
			$targetBot->setRagEnabled((bool)$pendingChanges['rag_enabled']);
		}
		if (array_key_exists('onboarding_questions', $pendingChanges)) {
			$targetBot->setOnboardingQuestionsArray(
				is_array($pendingChanges['onboarding_questions']) ? $pendingChanges['onboarding_questions'] : null
			);
		}

		return $targetBot;
	}

	/**
	 * @return array{
	 *   bot_name:string,
	 *   description:?string,
	 *   system_prompt:string,
	 *   temperature:?float,
	 *   visibility:string,
	 *   allowed_groups:array<int,string>,
	 *   allowed_teams:array<int,string>,
	 *   allowed_team_names:array<int,string>,
	 *   rag_enabled:bool,
	 *   onboarding_questions:?array,
	 *   tools:array<int,array{name:string,description:string,is_builtin:bool}>,
	 *   is_update:bool
	 * }
	 */
	private function buildReviewTarget(Bot $bot): array {
		$targetBot = $this->createPendingTargetBot($bot);
		$toolLoadout = $this->resolveToolLoadout($bot, true);
		$allowedTeamIds = $this->decodeIdList($targetBot->getAllowedTeams());

		return [
			'bot_name' => $targetBot->getBotName(),
			'description' => $targetBot->getDescription(),
			'system_prompt' => $targetBot->getSystemPrompt(),
			'temperature' => $targetBot->getTemperature(),
			'visibility' => $this->normalizeVisibilityValue($targetBot->getVisibility(), $targetBot->getIsPublic()),
			'allowed_groups' => $this->decodeIdList($targetBot->getAllowedGroups()),
			'allowed_teams' => $allowedTeamIds,
			'allowed_team_names' => $this->resolveTeamDisplayNames($allowedTeamIds),
			'rag_enabled' => $targetBot->getRagEnabled(),
			'onboarding_questions' => $targetBot->getOnboardingQuestionsArray(),
			'tools' => $this->buildToolDisplayEntries($toolLoadout),
			'is_update' => $bot->hasPendingChanges(),
		];
	}

	/**
	 * @return SplitToolLoadout
	 */
	private function resolveEffectiveToolLoadout(Bot $bot, string $userId): array {
		$status = $bot->getApprovalStatus() ?? 'approved';
		if ($status === 'pending') {
			$normalizedUserId = $this->normalizeExternalUserId($userId);
			if ($bot->getUserId() === $normalizedUserId || $this->userCanTestPendingBot($bot, $normalizedUserId)) {
				return $this->resolveToolLoadout($bot, true);
			}
		}

		return $this->resolveToolLoadout($bot, false);
	}

	/**
	 * @return SplitToolLoadout
	 */
	private function resolveToolLoadout(Bot $bot, bool $preferPending): array {
		$pendingChanges = $bot->getPendingChangesArray();
		if ($preferPending && $pendingChanges !== null && array_key_exists('tools', $pendingChanges) && is_array($pendingChanges['tools'])) {
			$visibility = isset($pendingChanges['visibility']) && is_string($pendingChanges['visibility']) && $pendingChanges['visibility'] !== ''
				? $pendingChanges['visibility']
				: $this->normalizeVisibilityValue($bot->getVisibility(), $bot->getIsPublic());
			$allowedTeams = array_key_exists('allowed_teams', $pendingChanges)
				? $this->normalizeIdArray($pendingChanges['allowed_teams'])
				: $this->decodeIdList($bot->getAllowedTeams());
			return $this->filterWikiToolsFromLoadout($this->buildToolLoadoutFromAssignments($pendingChanges['tools']), $bot, $visibility, $allowedTeams);
		}

		return $this->filterWikiToolsFromLoadout([
			'mcp' => $this->toolRegistry->getToolsForBot($bot->getId()),
			'built_in' => $this->toolRegistry->getBuiltInToolsForBot($bot->getId()),
		], $bot, $this->normalizeVisibilityValue($bot->getVisibility(), $bot->getIsPublic()), $this->decodeIdList($bot->getAllowedTeams()));
	}

	public function resolveTemperature(?Bot $bot): float {
		$defaultTemperature = $this->settingsService->getDefaultTemperature();

		if ($bot !== null && $bot->getTemperature() !== null) {
			return $this->settingsService->sanitizeTemperatureForRuntime($bot->getTemperature(), $defaultTemperature);
		}

		return $defaultTemperature;
	}

	/**
	 * @param array<int,mixed> $tools
	 * @return SplitToolLoadout
	 */
	private function buildToolLoadoutFromAssignments(array $tools): array {
		$assignments = $this->normalizeToolAssignments($tools);
		$mcpTools = [];
		$builtInTools = [];

		foreach ($assignments as $assignment) {
			if ($assignment['is_builtin']) {
				$builtInTools[] = [
					'name' => (string)$assignment['builtin_name'],
					'config' => $assignment['config'],
				];
				continue;
			}

			try {
				$tool = $this->toolMapper->findById((int)$assignment['tool_id']);
			} catch (DoesNotExistException $e) {
				continue;
			}
			if (!$tool->getEnabled()) {
				continue;
			}

			$mcpTools[] = [
				'tool' => $tool,
				'config' => $assignment['config'],
			];
		}

		return [
			'mcp' => $mcpTools,
			'built_in' => $builtInTools,
		];
	}

	/**
	 * @param SplitToolLoadout $toolLoadout
	 * @return array<int,array{name:string,description:string,is_builtin:bool}>
	 */
	private function buildToolDisplayEntries(array $toolLoadout): array {
		$result = [];

		foreach ($toolLoadout['mcp'] as $entry) {
			$result[] = [
				'name' => $entry['tool']->getName(),
				'description' => $entry['tool']->getDescription() ?? '',
				'is_builtin' => false,
			];
		}

		foreach ($toolLoadout['built_in'] as $entry) {
			$result[] = [
				'name' => $this->formatBuiltInToolName($entry['name']),
				'description' => $this->getBuiltInToolDescription($entry['name']),
				'is_builtin' => true,
			];
		}

		return $result;
	}

	private function syncBotTools(Bot $bot, array $tools): void {
		$visibility = $this->normalizeVisibilityValue($bot->getVisibility(), $bot->getIsPublic());
		$assignments = $this->normalizeToolAssignments(
			$this->stripWikiToolsForVisibility($tools, $visibility, $this->decodeIdList($bot->getAllowedTeams()), $bot->getUserId()) ?? []
		);
		$wikiConfig = $this->initializeWikiForAssignments($bot, $assignments, $visibility);
		$this->botToolMapper->deleteByBot($bot->getId());
		$now = time();

		foreach ($assignments as $assignment) {
			$botTool = new BotTool();
			$botTool->setBotId($bot->getId());
			$botTool->setCreatedAt($now);
			$botTool->setUpdatedAt($now);

			if ($assignment['is_builtin']) {
				// Built-in tool: store by name
				$botTool->setBuiltInToolName($assignment['builtin_name']);
				$botTool->setToolId(null);
			} else {
				// MCP tool: verify it exists and is enabled
				try {
					$tool = $this->toolMapper->findById($assignment['tool_id']);
				} catch (DoesNotExistException $e) {
					continue;
				}
				if (!$tool->getEnabled()) {
					continue;
				}
				$botTool->setToolId($tool->getId());
				$botTool->setBuiltInToolName(null);
			}

			$config = $assignment['config'];
			$botTool->setConfigOverride(count($config) > 0 ? (json_encode($config) ?: '{}') : null);
			$this->botToolMapper->insert($botTool);
		}
		if ($this->wikiRootRegistryService !== null) {
			if ($wikiConfig !== null) {
				$this->wikiRootRegistryService->refreshBot($bot, $wikiConfig);
			} else {
				$this->wikiRootRegistryService->deactivateBot($bot->getId());
			}
		}
		$this->toolRegistry->refresh();
	}

	/**
	 * @param array<int,ToolAssignmentPayload> $assignments
	 * @return array<string,mixed>|null
	 * @throws Exception
	 */
	private function initializeWikiForAssignments(Bot $bot, array $assignments, string $visibility): ?array {
		if (!in_array($visibility, ['personal', 'teams'], true)) {
			return null;
		}

		foreach ($assignments as $assignment) {
			if (($assignment['is_builtin'] ?? false) !== true) {
				continue;
			}
			$builtinName = (string)($assignment['builtin_name'] ?? '');
			if (!in_array($builtinName, BuiltInToolProvider::WIKI_TOOLS, true)) {
				continue;
			}

			$this->wikiService->initializeWiki($bot->getId(), $assignment['config'] ?? []);
			return $assignment['config'] ?? [];
		}

		return null;
	}

	/**
	 * @param array<int,mixed>|null $tools
	 * @return array<int,mixed>|null
	 */
	private function stripWikiToolsForVisibility(?array $tools, string $visibility, array $allowedTeams = [], ?string $ownerUserId = null): ?array {
		if ($tools === null) {
			return null;
		}
		if ($visibility === 'personal') {
			return $this->expandWikiToolAssignments($tools);
		}
		if ($visibility === 'teams') {
			return $this->expandTeamWikiToolAssignments($tools, $allowedTeams, $ownerUserId);
		}

		$result = [];
		foreach ($tools as $entry) {
			$builtinName = null;
			if (is_array($entry)) {
				if (($entry['is_builtin'] ?? false) === true && isset($entry['builtin_name']) && is_string($entry['builtin_name'])) {
					$builtinName = $entry['builtin_name'];
				}
			} elseif (is_string($entry) && str_starts_with($entry, 'builtin:')) {
				$builtinName = substr($entry, 8);
			}

			if ($builtinName !== null && in_array($builtinName, BuiltInToolProvider::WIKI_TOOLS, true)) {
				continue;
			}
			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * @param SplitToolLoadout $loadout
	 * @return SplitToolLoadout
	 */
	private function filterWikiToolsFromLoadout(array $loadout, Bot $bot, string $visibility, array $allowedTeams): array {
		if (in_array($visibility, ['personal', 'teams'], true)) {
			$hasWikiTools = false;
			$wikiConfig = [];
			$builtInTools = [];
			foreach ($loadout['built_in'] as $entry) {
				$name = (string)($entry['name'] ?? '');
				if (in_array($name, BuiltInToolProvider::WIKI_TOOLS, true)) {
					$hasWikiTools = true;
					if (isset($entry['config']) && is_array($entry['config']) && count($entry['config']) > 0) {
						$wikiConfig = $entry['config'];
					}
					continue;
				}
				$builtInTools[] = $entry;
			}
			if ($hasWikiTools && $this->wikiAllowedForConfig($bot, $visibility, $wikiConfig, $allowedTeams)) {
				foreach (BuiltInToolProvider::WIKI_TOOLS as $name) {
					$builtInTools[] = [
						'name' => $name,
						'config' => $wikiConfig,
					];
				}
				$loadout['built_in'] = $builtInTools;
			}
			return $loadout;
		}

		$loadout['built_in'] = array_values(array_filter(
			$loadout['built_in'],
			static fn (array $entry): bool => !in_array((string)($entry['name'] ?? ''), BuiltInToolProvider::WIKI_TOOLS, true)
		));

		return $loadout;
	}

	/**
	 * @param array<int,mixed> $tools
	 * @param array<int,string> $allowedTeams
	 * @return array<int,mixed>
	 */
	private function expandTeamWikiToolAssignments(array $tools, array $allowedTeams, ?string $ownerUserId): array {
		$hasWikiTool = false;
		$wikiConfig = [];
		$result = [];

		foreach ($tools as $entry) {
			$builtinName = null;
			$config = [];
			if (is_array($entry)) {
				if (($entry['is_builtin'] ?? false) === true && isset($entry['builtin_name']) && is_string($entry['builtin_name'])) {
					$builtinName = $entry['builtin_name'];
					$config = isset($entry['config']) && is_array($entry['config']) ? $entry['config'] : [];
				}
			} elseif (is_string($entry) && str_starts_with($entry, 'builtin:')) {
				$builtinName = substr($entry, 8);
			}

			if ($builtinName !== null && in_array($builtinName, BuiltInToolProvider::WIKI_TOOLS, true)) {
				$hasWikiTool = true;
				if (count($config) > 0 && count($wikiConfig) === 0) {
					$wikiConfig = $this->normalizeWikiToolConfig($config);
				}
				continue;
			}
			$result[] = $entry;
		}

		if (!$hasWikiTool) {
			return $result;
		}
		if (!$this->teamWikiConfigIsAllowed($wikiConfig, $allowedTeams, $ownerUserId)) {
			throw new Exception('Team bots can use LLM Wiki only with an admin-owned collective from one of the selected teams.');
		}

		foreach (BuiltInToolProvider::WIKI_TOOLS as $name) {
			$result[] = [
				'is_builtin' => true,
				'builtin_name' => $name,
				'config' => $wikiConfig,
			];
		}

		return $result;
	}

	/**
	 * @param array<int,mixed> $tools
	 * @return array<int,mixed>
	 */
	private function expandWikiToolAssignments(array $tools): array {
		$hasWikiTool = false;
		$wikiConfig = [];
		$result = [];

		foreach ($tools as $entry) {
			$builtinName = null;
			$config = [];
			if (is_array($entry)) {
				if (($entry['is_builtin'] ?? false) === true && isset($entry['builtin_name']) && is_string($entry['builtin_name'])) {
					$builtinName = $entry['builtin_name'];
					$config = isset($entry['config']) && is_array($entry['config']) ? $entry['config'] : [];
				}
			} elseif (is_string($entry) && str_starts_with($entry, 'builtin:')) {
				$builtinName = substr($entry, 8);
			}

			if ($builtinName !== null && in_array($builtinName, BuiltInToolProvider::WIKI_TOOLS, true)) {
				$hasWikiTool = true;
				if (count($config) > 0 && count($wikiConfig) === 0) {
					$wikiConfig = $this->normalizeWikiToolConfig($config);
				}
				continue;
			}
			$result[] = $entry;
		}

		if (!$hasWikiTool) {
			return $result;
		}

		foreach (BuiltInToolProvider::WIKI_TOOLS as $name) {
			$entry = [
				'is_builtin' => true,
				'builtin_name' => $name,
			];
			if (count($wikiConfig) > 0) {
				$entry['config'] = $wikiConfig;
			}
			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $config
	 * @param array<int,string>|null $allowedTeams
	 */
	private function wikiAllowedForConfig(Bot $bot, string $visibility, array $config, ?array $allowedTeams = null): bool {
		if ($visibility === 'personal') {
			return true;
		}
		if ($visibility !== 'teams') {
			return false;
		}

		return $this->teamWikiConfigIsAllowed(
			$config,
			$allowedTeams ?? $this->decodeIdList($bot->getAllowedTeams()),
			$bot->getUserId()
		);
	}

	/**
	 * @param array<string,mixed> $config
	 * @param array<int,string> $allowedTeams
	 */
	private function teamWikiConfigIsAllowed(array $config, array $allowedTeams, ?string $ownerUserId): bool {
		if ($ownerUserId === null || $ownerUserId === '' || $this->wikiLocationService === null) {
			return false;
		}

		try {
			$normalized = $this->normalizeWikiToolConfig($config);
		} catch (Exception) {
			return false;
		}
		if (($normalized['wiki_location'] ?? 'personal_files') !== 'collective') {
			return false;
		}

		$collectiveId = $normalized['wiki_collective_id'] ?? null;
		if (!is_int($collectiveId)) {
			return false;
		}

		try {
			return $this->wikiLocationService->collectiveMatchesAnyTeam($collectiveId, $ownerUserId, $allowedTeams);
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	private function normalizeWikiToolConfig(array $config): array {
		$location = isset($config['wiki_location']) && is_string($config['wiki_location'])
			? trim($config['wiki_location'])
			: 'personal_files';
		if ($location === '') {
			$location = 'personal_files';
		}
		if (!in_array($location, ['personal_files', 'collective'], true)) {
			throw new Exception('Unsupported wiki location.');
		}
		$config['wiki_location'] = $location;

		if ($location === 'collective') {
			$collectiveId = $config['wiki_collective_id'] ?? null;
			if (!is_int($collectiveId) && !(is_string($collectiveId) && ctype_digit($collectiveId))) {
				throw new Exception('A valid collective must be selected for this wiki location.');
			}
			$collectiveId = (int)$collectiveId;
			if ($collectiveId <= 0) {
				throw new Exception('A valid collective must be selected for this wiki location.');
			}
			unset($config['wiki_root_path']);
			$config['wiki_collective_id'] = $collectiveId;
			return $config;
		}

		unset($config['wiki_collective_id']);
		if (!array_key_exists('wiki_root_path', $config)) {
			return $config;
		}
		if (!is_string($config['wiki_root_path'])) {
			throw new Exception('Wiki root path must be a string.');
		}
		if (trim($config['wiki_root_path']) === '') {
			unset($config['wiki_root_path']);
			return $config;
		}

		$config['wiki_root_path'] = $this->normalizeWikiRootPath($config['wiki_root_path']);
		return $config;
	}

	private function normalizeWikiRootPath(string $path): string {
		$path = trim(str_replace('\\', '/', $path));
		if ($path === '') {
			throw new Exception('Wiki root path is required.');
		}
		if (str_starts_with($path, '/')) {
			throw new Exception('Wiki root path must be relative.');
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
			throw new Exception('Wiki root path contains an invalid character.');
		}

		$path = trim($path, '/');
		if (!str_starts_with($path, \OCA\EducAI\AppInfo\Application::WIKI_ROOT_FOLDER . '/')) {
			throw new Exception('Wiki root path must start with ' . \OCA\EducAI\AppInfo\Application::WIKI_ROOT_FOLDER . '/.');
		}
		if (strlen($path) > 512) {
			throw new Exception('Wiki root path is too long.');
		}

		$segments = explode('/', $path);
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new Exception('Wiki root path must not contain empty, current, or parent segments.');
			}
			if (str_starts_with($segment, '.')) {
				throw new Exception('Wiki root path must not target hidden/internal folders.');
			}
		}

		return implode('/', $segments);
	}

	/**
	 * @param array<int,mixed>|null $tools
	 * @return array<int,ToolAssignmentPayload>
	 */
	private function normalizeToolAssignments(?array $tools): array {
		if ($tools === null) {
			return [];
		}
		$result = [];
		foreach ($tools as $entry) {
			$toolId = null;
			$builtinName = null;
			$isBuiltin = false;
			$config = [];

			if (is_array($entry)) {
				// Check if it's a built-in tool
				if (isset($entry['is_builtin']) && $entry['is_builtin'] === true) {
					$isBuiltin = true;
					$builtinName = $entry['builtin_name'] ?? null;
				} else {
					// MCP tool
					$toolId = $entry['tool_id'] ?? $entry['id'] ?? null;
				}
				$config = isset($entry['config']) && is_array($entry['config']) ? $entry['config'] : [];
			} elseif (is_numeric($entry)) {
				// Legacy format: just a tool ID
				$toolId = (int)$entry;
			} elseif (is_string($entry) && str_starts_with($entry, 'builtin:')) {
				// String format for built-in: "builtin:catalogue_search_courses"
				$isBuiltin = true;
				$builtinName = substr($entry, 8);
			}

			// Validate assignment
			if ($isBuiltin) {
				if ($builtinName === null || $builtinName === '') {
					continue;
				}
				$key = 'builtin:' . $builtinName;
				$result[$key] = [
					'tool_id' => null,
					'builtin_name' => $builtinName,
					'is_builtin' => true,
					'config' => $config,
				];
			} else {
				if ($toolId === null || !is_numeric($toolId)) {
					continue;
				}
				$toolId = (int)$toolId;
				$result['mcp:' . $toolId] = [
					'tool_id' => $toolId,
					'builtin_name' => null,
					'is_builtin' => false,
					'config' => $config,
				];
			}
		}

		return array_values($result);
	}

	private function userInAllowedTeams(Bot $bot, string $userId, IUser $user): bool {
		if (!class_exists('\\OCA\\Circles\\Api\\v1\\Circles')) {
			return false;
		}

		try {
			if (method_exists($this->appManager, 'isEnabledForUser')) {
				if (!$this->appManager->isEnabledForUser('circles', $user)) {
					return false;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Unable to verify Circles availability for user', [
				'exception' => $e,
			]);
		}

		$allowedTeams = $this->decodeIdList($bot->getAllowedTeams());
		if (count($allowedTeams) === 0) {
			return false;
		}

		foreach ($allowedTeams as $teamId) {
			try {
				$membership = \OCA\Circles\Api\v1\Circles::getMember($teamId, $userId, \OCA\Circles\Api\v1\Circles::TYPE_USER, true);
				if ($membership !== null) {
					return true;
				}
			} catch (\Throwable $e) {
				// Ignore and test next team id
				$this->logger->debug('Team membership check failed', [
					'team_id' => $teamId,
					'user_id' => $userId,
					'exception' => $e,
				]);
			}
		}

		return false;
	}

	/**
	 * Estimate the number of tokens in a text string.
	 * Uses a simple heuristic: ~4 characters = 1 token (industry standard for English/mixed content).
	 * This is conservative and works well cross-model.
	 *
	 * @param string $text The text to estimate tokens for
	 * @return int Estimated token count
	 */
	private function estimateTokens(string $text): int {
		return (int) ceil(mb_strlen($text, 'UTF-8') / 4);
	}

	/**
	 * Build conversation context array respecting a token limit.
	 * Includes messages from newest to oldest until the token budget is exhausted.
	 * Always includes at least the most recent user message even if it exceeds the limit.
	 *
	 * @param Conversation[] $history Conversation history (oldest first)
	 * @param int $tokenLimit Maximum tokens to include
	 * @return array<int,array{role:string,content:string}> Messages array for LLM
	 */
	private function buildContextWithinTokenLimit(array $history, int $tokenLimit): array {
		if (count($history) === 0) {
			return [];
		}

		// Reverse to process newest first
		$reversed = array_reverse($history);
		$selected = [];
		$totalTokens = 0;
		$includedLatestUser = false;

		foreach ($reversed as $conv) {
			$role = $conv->getRole();
			$content = $role === 'assistant'
				? AssistantContentSanitizer::sanitize($conv->getContent())
				: $conv->getContent();
			if ($role === 'assistant' && trim($content) === '') {
				continue;
			}
			$tokens = $this->estimateTokens($content);

			// Always include the most recent user message (it's the current query)
			if (!$includedLatestUser && $role === 'user') {
				$selected[] = [
					'role' => $role,
					'content' => $content,
				];
				$totalTokens += $tokens;
				$includedLatestUser = true;
				continue;
			}

			// Check if adding this message would exceed the limit
			if ($totalTokens + $tokens > $tokenLimit) {
				// Stop adding more messages
				break;
			}

			$selected[] = [
				'role' => $role,
				'content' => $content,
			];
			$totalTokens += $tokens;
		}

		// Reverse back to chronological order (oldest first)
		return array_reverse($selected);
	}

	/**
	 * Normalize a value to boolean or null.
	 * Handles edge cases from JSON parsing where false may become empty string.
	 *
	 * @return bool|null
	 */
	private function normalizeBool(bool|int|string|null $value): ?bool {
		if ($value === null) {
			return null;
		}
		if (is_bool($value)) {
			return $value;
		}
		if ($value === '' || $value === 'false' || $value === '0' || $value === 0) {
			return false;
		}
		if ($value === 'true' || $value === '1' || $value === 1) {
			return true;
		}
		return (bool)$value;
	}
}
