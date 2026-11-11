<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Exception\AssistantExistsException;
use OCA\EducAI\Exception\AuthorizationException;
use OCA\EducAI\Exception\ModelNotReleasedException;
use OCA\EducAI\Exception\PreviewUnavailableException;
use OCA\EducAI\OpenApi\ApiToolAssignmentException;
use OCA\EducAI\Service\AssistantService;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\BuiltInToolUiService;
use OCA\EducAI\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BotController extends Controller {
	private const TEMPERATURE_NOT_PROVIDED = '__educai_temperature_not_provided__';

	private BotService $botService;
	private PermissionService $permissionService;
	private ?string $userId;
	private LoggerInterface $logger;
	private IL10N $l10n;
	private BuiltInToolUiService $builtInToolUiService;
	private ?AssistantService $assistantService;

	public function __construct(
		string $appName,
		IRequest $request,
		BotService $botService,
		PermissionService $permissionService,
		?string $userId,
		LoggerInterface $logger,
		IL10N $l10n,
		BuiltInToolUiService $builtInToolUiService,
		?AssistantService $assistantService = null,
	) {
		parent::__construct($appName, $request);
		$this->botService = $botService;
		$this->permissionService = $permissionService;
		$this->userId = $userId;
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->builtInToolUiService = $builtInToolUiService;
		$this->assistantService = $assistantService;
	}

	/**
	 * @NoAdminRequired
	 * List all bots for the current user
	 */
	public function index(): DataResponse {
		try {
			$bots = $this->markAssistant($this->botService->getBotsByUserEnriched($this->userId));
			return new DataResponse(array_map(fn (array $bot): array => $this->localizeToolList($bot), $bots));
		} catch (Exception $e) {
			$this->logger->error('Failed to list bots: ' . $e->getMessage());
			return $this->errorResponse('bots_load_failed', $this->l10n->t('Failed to load bots'), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * Get a specific bot
	 */
	public function show(int $id): DataResponse {
		try {
			$bot = $this->botService->getBot($id);

			// Whoever may save the bot may also load it
			if ($this->userId === null || !$this->permissionService->canEditBot($this->userId, $bot)) {
				return $this->errorResponse('forbidden', $this->l10n->t('Unauthorized'), 403);
			}

			return new DataResponse($bot);
		} catch (Exception $e) {
			$this->logger->warning('Failed to load bot', [
				'bot_id' => $id,
				'exception' => $e,
			]);
			return $this->errorResponse('bot_not_found', $this->l10n->t('Bot not found'), 404);
		}
	}

	/**
	 * @NoAdminRequired
	 * Create a new bot. With `assistant` it becomes the personal assistant of the user:
	 * a personal bot with a mention name of its own, pinned in first place.
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
		?array $onboardingQuestions = null,
		$assistant = null,
		$allowGuests = null,
	): DataResponse {
		// Normalize ragEnabled to bool (handles empty string from JSON false)
		$ragEnabled = $this->normalizeBool($ragEnabled);
		$allowGuests = $this->optionalBool($allowGuests);
		try {
			if (is_scalar($assistant) && $this->normalizeBool($assistant) === true) {
				if ($this->assistantService === null || $this->userId === null) {
					return $this->errorResponse('bot_create_failed', $this->l10n->t('Failed to create bot'), 400);
				}
				$bot = $this->assistantService->createAssistant(
					$this->userId,
					$botName,
					$systemPrompt,
					$model,
					$temperature,
					$ragEnabled,
					$tools,
					$description,
					$onboardingQuestions
				);
				$botData = $bot->jsonSerialize();
				$botData['is_assistant'] = true;

				return new DataResponse($botData, 201);
			}

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
				$onboardingQuestions,
				$allowGuests
			);

			return new DataResponse($bot, 201);
		} catch (AssistantExistsException $e) {
			return $this->errorResponse(
				'assistant_exists',
				$this->l10n->t('You already have a personal assistant. You find it under "My bots".'),
				409,
			);
		} catch (ApiToolAssignmentException $e) {
			return $this->apiToolNotAssignableResponse($e);
		} catch (ModelNotReleasedException $e) {
			return $this->modelNotReleasedResponse($e);
		} catch (Exception $e) {
			$this->logger->error('Failed to create bot: ' . $e->getMessage());
			return $this->errorResponse('bot_create_failed', $this->l10n->t('Failed to create bot'), 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * Try the instruction of a bot that is not created yet: one answer to a short conversation.
	 * Nothing is stored; tools and knowledge are not part of a draft.
	 */
	public function preview(
		string $systemPrompt = '',
		?string $model = null,
		$temperature = null,
		?array $messages = null,
	): DataResponse {
		if ($this->userId === null) {
			return $this->errorResponse('forbidden', $this->l10n->t('Unauthorized'), 403);
		}
		try {
			return new DataResponse($this->botService->previewReply($systemPrompt, $model, $temperature, $messages ?? []));
		} catch (ModelNotReleasedException $e) {
			return $this->modelNotReleasedResponse($e);
		} catch (\InvalidArgumentException $e) {
			return $this->errorResponse(
				'preview_invalid',
				$this->l10n->t('The preview needs an instruction and a message. Very long conversations cannot be tried here.'),
				400,
			);
		} catch (PreviewUnavailableException $e) {
			return $e->reason === PreviewUnavailableException::RATE_LIMITED
				? $this->errorResponse('preview_rate_limited', $this->l10n->t('The model is busy right now. Try again in a moment.'), 429)
				: $this->errorResponse('preview_failed', $this->l10n->t('The model gave no answer. Try again, or ask your administrator to check the model connection.'), 502);
		} catch (Exception $e) {
			$this->logger->error('Bot preview failed unexpectedly', ['exception_class' => get_class($e)]);
			return $this->errorResponse('preview_failed', $this->l10n->t('The model gave no answer. Try again, or ask your administrator to check the model connection.'), 502);
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
		?array $onboardingQuestions = null,
		$allowGuests = null,
	): DataResponse {
		// Normalize ragEnabled to bool (handles empty string from JSON false)
		$ragEnabled = $this->normalizeBool($ragEnabled);
		// Not sent means unchanged
		$allowGuests = $this->optionalBool($allowGuests);

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
				$onboardingQuestions,
				$allowGuests
			);

			return new DataResponse($bot);
		} catch (ApiToolAssignmentException $e) {
			return $this->apiToolNotAssignableResponse($e);
		} catch (ModelNotReleasedException $e) {
			return $this->modelNotReleasedResponse($e);
		} catch (AuthorizationException $e) {
			$this->logger->error('Failed to update bot: ' . $e->getMessage());
			return $this->errorResponse(
				'forbidden',
				$this->l10n->t('You do not have permission to update this bot'),
				403,
			);
		} catch (Exception $e) {
			$this->logger->error('Failed to update bot: ' . $e->getMessage());
			return $this->errorResponse('bot_update_failed', $this->l10n->t('Failed to update bot'), 400);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function tools(int $id): DataResponse {
		try {
			$tools = $this->botService->getBotTools($id, $this->userId);
			return new DataResponse(['tools' => $tools]);
		} catch (AuthorizationException $e) {
			$this->logger->error('Failed to list bot tools', [
				'bot_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return $this->errorResponse(
				'forbidden',
				$this->l10n->t('You do not have permission to view this bot'),
				403,
			);
		} catch (Exception $e) {
			$this->logger->error('Failed to list bot tools', [
				'bot_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return $this->errorResponse('bot_tools_load_failed', $this->l10n->t('Failed to load bot tools'), 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * List all public bots with enriched data (owner name, access reason)
	 */
	public function listPublic(): DataResponse {
		try {
			$bots = $this->markAssistant($this->botService->getAvailableBotsForUserEnriched((string)$this->userId));
			return new DataResponse(array_map(fn (array $bot): array => $this->localizeToolList($bot), $bots));
		} catch (Exception $e) {
			$this->logger->error('Failed to list public bots: ' . $e->getMessage());
			return $this->errorResponse('public_bots_load_failed', $this->l10n->t('Failed to load public bots'), 500);
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
				return $this->errorResponse(
					'public_bot_unavailable',
					$this->l10n->t('Bot not found or access denied'),
					404,
				);
			}
			return new DataResponse($this->localizeToolList($botDetails));
		} catch (Exception $e) {
			$this->logger->error('Failed to get public bot details: ' . $e->getMessage());
			return $this->errorResponse('public_bots_load_failed', $this->l10n->t('Failed to load public bot details'), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * Delete a bot
	 */
	public function destroy(int $id): DataResponse {
		try {
			$this->botService->deleteBot($id, $this->userId);
			$this->assistantService?->forget((string)$this->userId, $id);
			return new DataResponse(['success' => true]);
		} catch (AuthorizationException $e) {
			$this->logger->error('Failed to delete bot: ' . $e->getMessage());
			return $this->errorResponse(
				'forbidden',
				$this->l10n->t('You do not have permission to delete this bot'),
				403,
			);
		} catch (Exception $e) {
			$this->logger->error('Failed to delete bot: ' . $e->getMessage());
			return $this->errorResponse('bot_delete_failed', $this->l10n->t('Failed to delete bot'), 400);
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
		} catch (ApiToolAssignmentException $e) {
			return $this->apiToolNotAssignableResponse($e);
		} catch (AuthorizationException $e) {
			$this->logger->error('Failed to submit bot for approval: ' . $e->getMessage());
			return $this->errorResponse(
				'forbidden',
				$this->l10n->t('You do not have permission to submit this bot for approval'),
				403,
			);
		} catch (Exception $e) {
			$this->logger->error('Failed to submit bot for approval: ' . $e->getMessage());
			return $this->errorResponse('bot_submit_failed', $this->l10n->t('Failed to submit bot for approval'), 400);
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
		} catch (AuthorizationException $e) {
			$this->logger->error('Failed to enable testing for bot', [
				'bot_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return $this->errorResponse(
				'forbidden',
				$this->l10n->t('You do not have permission to enable testing for this bot'),
				403,
			);
		} catch (Exception $e) {
			$this->logger->error('Failed to enable testing for bot', [
				'bot_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return $this->errorResponse('bot_test_enable_failed', $this->l10n->t('Failed to enable bot testing'), 400);
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
		} catch (ApiToolAssignmentException $e) {
			return $this->apiToolNotAssignableResponse($e);
		} catch (AuthorizationException $e) {
			$this->logger->error('Failed to approve bot: ' . $e->getMessage());
			return $this->errorResponse(
				'forbidden',
				$this->l10n->t('You do not have permission to approve this bot'),
				403,
			);
		} catch (Exception $e) {
			$this->logger->error('Failed to approve bot: ' . $e->getMessage());
			return $this->errorResponse('bot_approve_failed', $this->l10n->t('Failed to approve bot'), 400);
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
		} catch (AuthorizationException $e) {
			$this->logger->error('Failed to reject bot: ' . $e->getMessage());
			return $this->errorResponse(
				'forbidden',
				$this->l10n->t('You do not have permission to reject this bot'),
				403,
			);
		} catch (Exception $e) {
			$this->logger->error('Failed to reject bot: ' . $e->getMessage());
			return $this->errorResponse('bot_reject_failed', $this->l10n->t('Failed to reject bot'), 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * List all bots pending approval (requires approval rights)
	 */
	public function pendingApprovals(): DataResponse {
		try {
			$bots = $this->botService->getPendingApprovals($this->userId);
			foreach ($bots as &$bot) {
				$bot = $this->localizeToolList($bot, 'live_tools');
				if (isset($bot['review_target']) && is_array($bot['review_target'])) {
					$bot['review_target'] = $this->localizeToolList($bot['review_target']);
				}
			}
			unset($bot);
			return new DataResponse(['bots' => $bots]);
		} catch (AuthorizationException $e) {
			$this->logger->error('Failed to list pending approvals: ' . $e->getMessage());
			return $this->errorResponse(
				'forbidden',
				$this->l10n->t('You do not have permission to view pending approvals'),
				403,
			);
		} catch (Exception $e) {
			$this->logger->error('Failed to list pending approvals: ' . $e->getMessage());
			return $this->errorResponse(
				'pending_approvals_load_failed',
				$this->l10n->t('Failed to load pending approvals'),
				400,
			);
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
			return $this->errorResponse('permissions_load_failed', $this->l10n->t('Failed to load permissions'), 500);
		}
	}

	/**
	 * @AdminRequired
	 * List all bots (admin only)
	 */
	public function adminIndex(): DataResponse {
		try {
			if (!$this->permissionService->isAdmin((string)$this->userId)) {
				return $this->errorResponse('forbidden', $this->l10n->t('Unauthorized'), 403);
			}
			$bots = $this->botService->getAllBots();
			return new DataResponse($bots);
		} catch (Exception $e) {
			$this->logger->error('Failed to list all bots: ' . $e->getMessage());
			return $this->errorResponse('admin_bots_load_failed', $this->l10n->t('Failed to load all bots'), 500);
		}
	}

	/**
	 * @AdminRequired
	 * Update any bot as admin
	 */
	public function adminUpdate(int $id): DataResponse {
		try {
			if (!$this->permissionService->isAdmin((string)$this->userId)) {
				return $this->errorResponse('forbidden', $this->l10n->t('Unauthorized'), 403);
			}

			$botName = (string)$this->request->getParam('botName', '');
			$systemPrompt = (string)$this->request->getParam('systemPrompt', '');
			if ($botName === '' || $systemPrompt === '') {
				return $this->errorResponse(
					'bot_fields_required',
					$this->l10n->t('Bot name and system prompt are required'),
					400,
				);
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
				$this->request->getParam('onboardingQuestions'),
				$this->optionalBool($this->request->getParam('allowGuests'))
			);

			return new DataResponse($bot);
		} catch (ApiToolAssignmentException $e) {
			return $this->apiToolNotAssignableResponse($e);
		} catch (ModelNotReleasedException $e) {
			return $this->modelNotReleasedResponse($e);
		} catch (Exception $e) {
			$this->logger->error('Failed to update bot as admin', [
				'bot_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return $this->errorResponse('bot_update_failed', $this->l10n->t('Failed to update bot'), 400);
		}
	}

	/**
	 * Say which of the listed bots is the personal assistant of the current user.
	 *
	 * @param array<int,array<string,mixed>> $bots
	 * @return array<int,array<string,mixed>>
	 */
	private function markAssistant(array $bots): array {
		if ($this->assistantService === null || $this->userId === null) {
			return $bots;
		}

		return $this->assistantService->markAssistant($this->userId, $bots);
	}

	/**
	 * Read a switch that a request may leave out: anything that is not a boolean, a number
	 * or a string counts as "not sent".
	 */
	private function optionalBool(mixed $value): ?bool {
		return is_bool($value) || is_int($value) || is_string($value) ? $this->normalizeBool($value) : null;
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

	/**
	 * Localize the built-in tools in one tool list of a bot payload.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function localizeToolList(array $payload, string $key = 'tools'): array {
		if (isset($payload[$key]) && is_array($payload[$key])) {
			$payload[$key] = $this->localizeBuiltInTools($payload[$key]);
		}

		return $payload;
	}

	/**
	 * @param array<int,array<string,mixed>> $tools
	 * @return array<int,array<string,mixed>>
	 */
	private function localizeBuiltInTools(array $tools): array {
		foreach ($tools as &$tool) {
			if (($tool['is_builtin'] ?? false) !== true || !isset($tool['builtin_name']) || !is_string($tool['builtin_name'])) {
				continue;
			}
			$tool['name'] = $this->builtInToolUiService->getLabel(
				$tool['builtin_name'],
				isset($tool['name']) && is_string($tool['name']) ? $tool['name'] : null,
			);
			$tool['description'] = $this->builtInToolUiService->getDescription(
				$tool['builtin_name'],
				isset($tool['description']) && is_string($tool['description']) ? $tool['description'] : null,
			);
		}
		unset($tool);

		return $tools;
	}

	private function apiToolNotAssignableResponse(ApiToolAssignmentException $e): DataResponse {
		$label = $e->getLabel() ?? $e->getToolName();
		$message = match ($e->getReason()) {
			ApiToolAssignmentException::NOT_PERMITTED => $this->l10n->t('You are not allowed to give the API tool "%s" to bots.', [$label]),
			ApiToolAssignmentException::VISIBILITY_NOT_ALLOWED => $this->l10n->t('The API tool "%s" is not allowed for bots with this visibility.', [$label]),
			default => $this->l10n->t('The API tool "%s" is not available. Remove it from the bot or ask an administrator to approve it.', [$label]),
		};
		return new DataResponse([
			'error' => $message,
			'errorCode' => 'api_tool_not_assignable',
			'reason' => $e->getReason(),
			'tool' => $e->getToolName(),
		], 403);
	}

	private function modelNotReleasedResponse(ModelNotReleasedException $e): DataResponse {
		return $this->errorResponse('model_not_released', $e->selectionDisabled
			? $this->l10n->t('Choosing a model per bot is turned off. The bot uses the administrator default model.')
			: $this->l10n->t('This model has not been released for bots. Choose a released model or the administrator default.'), 400);
	}

	private function errorResponse(string $errorCode, string $error, int $status): DataResponse {
		return new DataResponse([
			'error' => $error,
			'errorCode' => $errorCode,
		], $status);
	}
}
