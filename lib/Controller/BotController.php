<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BotController extends Controller {
	private const TEMPERATURE_NOT_PROVIDED = '__educai_temperature_not_provided__';

	private BotService $botService;
	private PermissionService $permissionService;
	private ?string $userId;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		BotService $botService,
		PermissionService $permissionService,
		?string $userId,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->botService = $botService;
		$this->permissionService = $permissionService;
		$this->userId = $userId;
		$this->logger = $logger;
	}

	/**
	 * @NoAdminRequired
	 * List all bots for the current user
	 */
	public function index(): DataResponse {
		try {
			$bots = $this->botService->getBotsByUser($this->userId);
			return new DataResponse($bots);
		} catch (Exception $e) {
			$this->logger->error('Failed to list bots: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * Get a specific bot
	 */
	public function show(int $id): DataResponse {
		try {
			$bot = $this->botService->getBot($id);
			
			// Verify ownership
			if ($bot->getUserId() !== $this->userId) {
				return new DataResponse(['error' => 'Unauthorized'], 403);
			}
			
			return new DataResponse($bot);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 404);
		}
	}

	/**
	 * @NoAdminRequired
	 * Create a new bot
	 */
	public function create(
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
	): DataResponse {
		// Normalize ragEnabled to bool (handles empty string from JSON false)
		$ragEnabled = $this->normalizeBool($ragEnabled);
		try {
			$bot = $this->botService->createBot(
				$this->userId,
				$botName,
				$mentionName,
				$systemPrompt,
				$isPublic,
				$model,
				$temperature,
				$visibility,
				$allowedGroups,
				$allowedTeams,
				$ragEnabled,
				$tools,
				$description,
				$onboardingQuestions
			);
			
			return new DataResponse($bot, 201);
		} catch (Exception $e) {
			$this->logger->error('Failed to create bot: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * Update a bot
	 */
	public function update(
		int $id,
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
	): DataResponse {
		// Normalize ragEnabled to bool (handles empty string from JSON false)
		$ragEnabled = $this->normalizeBool($ragEnabled);

		try {
			$bot = $this->botService->updateBot(
				$id,
				$this->userId,
				$botName,
				$systemPrompt,
				$isPublic,
				$model,
				$temperature,
				$visibility,
				$allowedGroups,
				$allowedTeams,
				$ragEnabled,
				$tools,
				$description,
				$onboardingQuestions
			);
			
			return new DataResponse($bot);
		} catch (Exception $e) {
			$this->logger->error('Failed to update bot: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function tools(int $id): DataResponse {
		try {
			$tools = $this->botService->getBotTools($id, $this->userId);
			return new DataResponse(['tools' => $tools]);
		} catch (Exception $e) {
			$this->logger->error('Failed to list bot tools', [
				'bot_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			$status = $e->getMessage() === 'You do not have permission to view this bot' ? 403 : 400;
			return new DataResponse(['error' => $e->getMessage()], $status);
		}
	}

	/**
	 * @NoAdminRequired
	 * List all public bots with enriched data (owner name, access reason)
	 */
	public function listPublic(): DataResponse {
		try {
			$bots = $this->botService->getAvailableBotsForUserEnriched((string)$this->userId);
			return new DataResponse($bots);
		} catch (Exception $e) {
			$this->logger->error('Failed to list public bots: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * Get detailed info about a public bot for the detail modal
	 */
	public function showPublic(int $id): DataResponse {
		try {
			$botDetails = $this->botService->getPublicBotDetails($id, (string)$this->userId);
			if ($botDetails === null) {
				return new DataResponse(['error' => 'Bot not found or access denied'], 404);
			}
			return new DataResponse($botDetails);
		} catch (Exception $e) {
			$this->logger->error('Failed to get public bot details: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * Delete a bot
	 */
	public function destroy(int $id): DataResponse {
		try {
			$this->botService->deleteBot($id, $this->userId);
			return new DataResponse(['success' => true]);
		} catch (Exception $e) {
			$this->logger->error('Failed to delete bot: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * Submit a bot for approval
	 */
	public function submit(int $id): DataResponse {
		try {
			$bot = $this->botService->submitForApproval(
				$id,
				(string)$this->userId,
				$this->request->getParam('approval_reason'),
				$this->request->getParam('bot_capabilities'),
				$this->request->getParam('rag_source_description'),
				$this->request->getParam('testing_description')
			);
			return new DataResponse($bot);
		} catch (Exception $e) {
			$this->logger->error('Failed to submit bot for approval: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * Enable testing for a pending bot (requires approval rights)
	 */
	public function enableTest(int $id): DataResponse {
		try {
			$bot = $this->botService->enableTesting($id, (string)$this->userId);
			return new DataResponse($bot);
		} catch (Exception $e) {
			$this->logger->error('Failed to enable testing for bot', [
				'bot_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			$status = str_contains($e->getMessage(), 'permission') ? 403 : 400;
			return new DataResponse(['error' => $e->getMessage()], $status);
		}
	}

	/**
	 * @NoAdminRequired
	 * Approve a pending bot (requires approval rights)
	 */
	public function approve(int $id): DataResponse {
		try {
			$bot = $this->botService->approveBot($id, $this->userId);
			return new DataResponse($bot);
		} catch (Exception $e) {
			$this->logger->error('Failed to approve bot: ' . $e->getMessage());
			$status = str_contains($e->getMessage(), 'permission') ? 403 : 400;
			return new DataResponse(['error' => $e->getMessage()], $status);
		}
	}

	/**
	 * @NoAdminRequired
	 * Reject a pending bot (requires approval rights)
	 */
	public function reject(int $id, ?string $reason = null): DataResponse {
		try {
			$bot = $this->botService->rejectBot($id, $this->userId, $reason);
			return new DataResponse($bot);
		} catch (Exception $e) {
			$this->logger->error('Failed to reject bot: ' . $e->getMessage());
			$status = str_contains($e->getMessage(), 'permission') ? 403 : 400;
			return new DataResponse(['error' => $e->getMessage()], $status);
		}
	}

	/**
	 * @NoAdminRequired
	 * List all bots pending approval (requires approval rights)
	 */
	public function pendingApprovals(): DataResponse {
		try {
			$bots = $this->botService->getPendingApprovals($this->userId);
			return new DataResponse(['bots' => $bots]);
		} catch (Exception $e) {
			$this->logger->error('Failed to list pending approvals: ' . $e->getMessage());
			$status = str_contains($e->getMessage(), 'permission') ? 403 : 400;
			return new DataResponse(['error' => $e->getMessage()], $status);
		}
	}

	/**
	 * @NoAdminRequired
	 * Get user's permission summary
	 */
	public function permissions(): DataResponse {
		try {
			if ($this->userId === null) {
				return new DataResponse([
					'permissions' => [
						'isAdmin' => false,
						'isGroupAdmin' => false,
						'isTeamAdmin' => false,
						'hasApprovalRights' => false,
						'adminGroups' => [],
						'adminTeams' => [],
					],
					'visibilities' => [],
				]);
			}
			$permissions = $this->permissionService->getPermissionSummary($this->userId);
			$visibilities = $this->permissionService->getAvailableVisibilities($this->userId);
			
			$this->logger->debug('User permissions loaded', [
				'user_id' => $this->userId,
				'permissions' => $permissions,
			]);
			
			return new DataResponse([
				'permissions' => $permissions,
				'visibilities' => $visibilities,
			]);
		} catch (Exception $e) {
			$this->logger->error('Failed to get permissions: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @AdminRequired
	 * List all bots (admin only)
	 */
	public function adminIndex(): DataResponse {
		try {
			if (!$this->permissionService->isAdmin((string)$this->userId)) {
				return new DataResponse(['error' => 'Unauthorized'], 403);
			}
			$bots = $this->botService->getAllBots();
			return new DataResponse($bots);
		} catch (Exception $e) {
			$this->logger->error('Failed to list all bots: ' . $e->getMessage());
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @AdminRequired
	 * Update any bot as admin
	 */
	public function adminUpdate(int $id): DataResponse {
		try {
			if (!$this->permissionService->isAdmin((string)$this->userId)) {
				return new DataResponse(['error' => 'Unauthorized'], 403);
			}

			$botName = (string)$this->request->getParam('botName', '');
			$systemPrompt = (string)$this->request->getParam('systemPrompt', '');
			if ($botName === '' || $systemPrompt === '') {
				return new DataResponse(['error' => 'botName and systemPrompt are required'], 400);
			}

			$bot = $this->botService->updateBot(
				$id,
				(string)$this->userId,
				$botName,
				$systemPrompt,
				$this->request->getParam('isPublic'),
				$this->request->getParam('model'),
				$this->request->getParam('temperature', self::TEMPERATURE_NOT_PROVIDED),
				$this->request->getParam('visibility'),
				$this->request->getParam('allowedGroups'),
				$this->request->getParam('allowedTeams'),
				$this->request->getParam('ragEnabled'),
				$this->request->getParam('tools'),
				$this->request->getParam('description'),
				$this->request->getParam('onboardingQuestions')
			);

			return new DataResponse($bot);
		} catch (Exception $e) {
			$this->logger->error('Failed to update bot as admin', [
				'bot_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
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
