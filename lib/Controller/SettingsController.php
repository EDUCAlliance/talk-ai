<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\AppIconService;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\ConnectionCheckStore;
use OCA\EducAI\Service\EmbeddingAdminService;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ModelReferenceMigrationService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\TraceService;
use OCA\EducAI\Webhook\TalkHandler;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller {
	private const DEFAULT_TEMPERATURE_NOT_PROVIDED = '__educai_default_temperature_not_provided__';

	private SettingsService $settingsService;
	private LLMClient $llmClient;
	private RateLimitService $rateLimitService;
	private BotService $botService;
	private BotMapper $botMapper;
	private TalkHandler $talkHandler;
	private AppIconService $appIconService;
	private IURLGenerator $urlGenerator;
	private IGroupManager $groupManager;
	private IUserSession $userSession;
	private IAppManager $appManager;
	private LoggerInterface $logger;
	private TraceService $traceService;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		SettingsService $settingsService,
		LLMClient $llmClient,
		RateLimitService $rateLimitService,
		BotService $botService,
		BotMapper $botMapper,
		TalkHandler $talkHandler,
		AppIconService $appIconService,
		IURLGenerator $urlGenerator,
		IGroupManager $groupManager,
		IUserSession $userSession,
		IAppManager $appManager,
		LoggerInterface $logger,
		TraceService $traceService,
		IL10N $l10n,
		private EmbeddingAdminService $embeddingAdmin,
		private ?ModelReferenceMigrationService $modelReferenceMigration = null,
		private ?ConnectionCheckStore $connectionChecks = null,
	) {
		parent::__construct($appName, $request);
		$this->settingsService = $settingsService;
		$this->llmClient = $llmClient;
		$this->rateLimitService = $rateLimitService;
		$this->botService = $botService;
		$this->botMapper = $botMapper;
		$this->talkHandler = $talkHandler;
		$this->appIconService = $appIconService;
		$this->urlGenerator = $urlGenerator;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->appManager = $appManager;
		$this->logger = $logger;
		$this->traceService = $traceService;
		$this->l10n = $l10n;
	}

	/**
	 * @NoAdminRequired
	 * Get settings (masked for non-admin users)
	 */
	public function index(): DataResponse {
		try {
			$settings = $this->settingsService->getSettings();
			return new DataResponse($this->buildSettingsPayload($settings));
		} catch (Exception $e) {
			$this->logger->error('Failed to get settings: ' . $e->getMessage());
			return $this->errorResponse('settings_load_failed', $this->l10n->t('Failed to load settings'), 500);
		}
	}

	/**
	 * @AdminRequired
	 * Update global settings (admin-only)
	 */
	public function update(
		string $apiKey,
		string $apiEndpoint,
		string $defaultModel,
		$defaultTemperature = self::DEFAULT_TEMPERATURE_NOT_PROVIDED,
		?string $webhookSecret = null,
		string $apiProvider = 'custom',
		?bool $allowMultipleModels = null,
		?array $allowedModels = null,
		?string $embeddingApiEndpoint = null,
		?string $embeddingApiKey = null,
		?string $embeddingModel = null,
		?int $ragChunkSize = null,
		?int $ragChunkOverlap = null,
		?bool $ragEnabled = null,
		?bool $catalogueEnabled = null,
		?string $catalogueApiEndpoint = null,
		?int $catalogueReindexHours = null,
		?bool $doclingEnabled = null,
		?string $doclingApiEndpoint = null,
		?string $doclingApiKey = null,
		?string $visionApiEndpoint = null,
		?string $visionApiKey = null,
		?string $visionModel = null,
		?string $speechApiEndpoint = null,
		?string $speechApiKey = null,
		?string $speechModel = null,
		?bool $rateLimitEnabled = null,
		?int $rateLimitSecond = null,
		?int $rateLimitMinute = null,
		?int $rateLimitHour = null,
		?int $rateLimitDay = null,
		?string $rateLimitQueueMessage = null,
		?int $conversationContextTokens = null,
		?string $embeddingRateLimitMode = null,
		?int $embeddingRateLimitSecond = null,
		?int $embeddingRateLimitMinute = null,
		?int $embeddingRateLimitHour = null,
		?int $embeddingRateLimitDay = null,
		?string $secondaryApiEndpoint = null,
		?string $secondaryApiKey = null,
		?string $fallbackModel = null,
		?int $llmChatTimeout = null,
		?int $llmStreamTimeout = null,
		?int $llmModelsTimeout = null,
		?string $appIconUrl = null,
		?string $appIconMode = null,
		?string $appIconBlackUrl = null,
		?string $appIconWhiteUrl = null,
		?string $doclingApiProfile = null,
		?string $doclingAuthMode = null,
		mixed $maxOutputTokens = null,
		mixed $modelOutputTokenLimits = null,
		?array $clearCredentials = null,
	): DataResponse {
		try {
			if (trim($defaultModel) === '') {
				return $this->errorResponse('default_model_required', $this->l10n->t('Choose a default model before saving.'), 400);
			}
			$this->settingsService->validateDoclingOptions($doclingApiProfile, $doclingAuthMode);
			$this->settingsService->validateOutputTokenOptions($maxOutputTokens, $modelOutputTokenLimits);
			$cleared = SettingsService::validateClearCredentials($clearCredentials);
			$this->logger->info('EducAI Settings Update - catalogueApiEndpoint: ' . var_export($catalogueApiEndpoint, true) . ', catalogueEnabled: ' . var_export($catalogueEnabled, true));
			$previous = $this->settingsService->getSettings();
			$connectionsChanged = $apiProvider !== $previous->getApiProvider()
				|| $apiEndpoint !== $previous->getApiEndpoint()
				|| ($secondaryApiEndpoint !== null && $secondaryApiEndpoint !== $previous->getSecondaryApiEndpoint())
				|| ($apiKey !== '' && $apiKey !== '***')
				|| ($secondaryApiKey !== null && $secondaryApiKey !== '' && $secondaryApiKey !== '***')
				|| in_array('secondaryApiKey', $cleared, true);
			$defaultModel = $this->normalizeModelChoice($defaultModel, [$previous->getDefaultModel()], $connectionsChanged);
			if ($fallbackModel !== null) {
				$fallbackModel = $this->normalizeModelChoice($fallbackModel, [$previous->getFallbackModel()], $connectionsChanged);
			}
			if ($allowedModels !== null) {
				$previousAllowed = json_decode($previous->getAllowedModels() ?: '[]', true) ?: [];
				$allowedModels = array_values(array_unique(array_map(
					fn (string $model): string => $this->normalizeModelChoice($model, $previousAllowed, $connectionsChanged),
					$allowedModels,
				)));
			}
			if (is_array($modelOutputTokenLimits)) {
				$previousLimitModels = array_keys($previous->getModelOutputTokenLimitsArray());
				$normalizedLimits = [];
				foreach ($modelOutputTokenLimits as $model => $limit) {
					$normalizedLimits[$this->normalizeModelChoice((string)$model, $previousLimitModels, $connectionsChanged)] = $limit;
				}
				$modelOutputTokenLimits = $normalizedLimits;
			}
			$beforeRateLimitConfig = $this->extractRateLimitConfig($previous);
			$settings = $this->settingsService->updateSettings(
				$apiProvider,
				$apiKey,
				$apiEndpoint,
				$defaultModel,
				$defaultTemperature === self::DEFAULT_TEMPERATURE_NOT_PROVIDED ? null : $defaultTemperature,
				$webhookSecret,
				$allowMultipleModels,
				$allowedModels,
				$embeddingApiEndpoint,
				$embeddingApiKey,
				$embeddingModel,
				$ragChunkSize,
				$ragChunkOverlap,
				$ragEnabled,
				$catalogueEnabled,
				$catalogueApiEndpoint,
				$catalogueReindexHours,
				$doclingEnabled,
				$doclingApiEndpoint,
				$doclingApiKey,
				$visionApiEndpoint,
				$visionApiKey,
				$visionModel,
				$speechApiEndpoint,
				$speechApiKey,
				$speechModel,
				$rateLimitEnabled,
				$rateLimitSecond,
				$rateLimitMinute,
				$rateLimitHour,
				$rateLimitDay,
				$rateLimitQueueMessage,
				$conversationContextTokens,
				$embeddingRateLimitMode,
				$embeddingRateLimitSecond,
				$embeddingRateLimitMinute,
				$embeddingRateLimitHour,
				$embeddingRateLimitDay,
				$secondaryApiEndpoint,
				$secondaryApiKey,
				$fallbackModel,
				$llmChatTimeout,
				$llmStreamTimeout,
				$llmModelsTimeout,
				$appIconUrl,
				$appIconMode,
				$appIconBlackUrl,
				$appIconWhiteUrl,
				$doclingApiProfile,
				$doclingAuthMode,
				$maxOutputTokens,
				$modelOutputTokenLimits,
				$clearCredentials,
			);

			$afterRateLimitConfig = $this->extractRateLimitConfig($settings);
			if ($beforeRateLimitConfig !== $afterRateLimitConfig) {
				$this->rateLimitService->resetState();
			}

			return new DataResponse($this->buildSettingsPayload($settings));
		} catch (\InvalidArgumentException $e) {
			return $this->errorResponse('settings_validation_failed', $this->l10n->t('Check the model selections and settings. Select an explicit provider for new or ambiguous model names.'), 400);
		} catch (Exception $e) {
			$this->logger->error('Failed to update settings: ' . $e->getMessage());
			return $this->errorResponse('settings_update_failed', $this->l10n->t('Failed to update settings'), 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * Read independently cached provider catalogues. This never tests inference.
	 */
	public function models(): DataResponse {
		return $this->modelCatalogueResponse(false);
	}

	/**
	 * @AdminRequired
	 * Explicitly refresh discovery; this does not authorize new models.
	 */
	public function refreshModels(): DataResponse {
		return $this->modelCatalogueResponse(true);
	}

	/**
	 * @AdminRequired
	 * Send one small test to the explicitly selected, saved provider. No fallback.
	 */
	public function testModel(string $model): DataResponse {
		if (strlen($model) > 512 || preg_match('/[\x00-\x1f\x7f]/', $model)
			|| !preg_match('/^(primary|secondary):\S+$/D', $model)) {
			return $this->errorResponse('model_reference_required', $this->l10n->t('Select a model with an explicit provider before testing.'), 400);
		}
		$started = microtime(true);
		try {
			$result = $this->llmClient->testModelConnection($model);
			$result['message'] = $this->l10n->t('A real test answer was received from the selected provider.');
			$this->rememberModelCheck($model, true, is_int($result['latency_ms'] ?? null) ? $result['latency_ms'] : $this->elapsedMs($started), null);
			return new DataResponse($result);
		} catch (\Throwable $e) {
			// Provider errors can contain credentials or upstream response bodies.
			$this->logger->warning('The explicit model connection test failed');
			$error = $this->l10n->t('The provider did not return a test answer. Check the saved endpoint, credentials, selected model and available credit.');
			$this->rememberModelCheck($model, false, $this->elapsedMs($started), $error);
			return new DataResponse([
				'success' => false,
				'errorCode' => 'model_test_failed',
				'error' => $error,
				'model' => $model,
				'checked_at' => time(),
			], 502);
		}
	}

	/**
	 * Remember the explicit test for the administrator overview. It never changes
	 * the test response; only the safe message shown to the administrator is kept.
	 */
	private function rememberModelCheck(string $reference, bool $success, ?int $durationMs, ?string $error): void {
		if ($this->connectionChecks === null) {
			return;
		}
		try {
			$target = $this->llmClient->connectionCheckTarget($reference);
			$this->connectionChecks->record('model', $success, $durationMs, $target['summary'] ?? $reference, $error, $target['fingerprint'] ?? null);
		} catch (\Throwable $e) {
			$this->logger->warning('The connection check result could not be remembered', ['service' => 'model']);
		}
	}

	private function elapsedMs(float $started): int {
		return (int)round((microtime(true) - $started) * 1000);
	}

	private function modelCatalogueResponse(bool $refresh): DataResponse {
		try {
			$catalogue = $this->llmClient->getModelCatalogue($refresh);
			try {
				$this->modelReferenceMigration?->migrate();
			} catch (\Throwable $e) {
				// A deferred migration must not hide an otherwise usable catalogue.
				$this->logger->warning('Legacy model references could not yet be migrated');
			}
			$modelOptions = array_map(function (array $option): array {
				$endpoint = (string)($option['endpoint'] ?? '');
				$model = (string)($option['model'] ?? '');
				if ($endpoint === 'primary') {
					$option['label'] = $this->l10n->t('Primary') . ' · ' . $model;
				} elseif ($endpoint === 'secondary') {
					$option['label'] = $this->l10n->t('Secondary') . ' · ' . $model;
				}

				return $option;
			}, $catalogue['model_options']);
			$catalogue['models'] = array_values(array_map(static fn (array $option): string => (string)$option['id'], $modelOptions));
			$catalogue['model_options'] = $modelOptions;
			$catalogue['providers'] = array_map(function (array $provider): array {
				$provider['label'] = $provider['id'] === 'secondary' ? $this->l10n->t('Secondary') : $this->l10n->t('Primary');
				if (is_string($provider['error'] ?? null)) {
					$provider['error'] = $this->l10n->t($provider['error']);
				}
				return $provider;
			}, $catalogue['providers'] ?? []);
			return new DataResponse($catalogue);
		} catch (Exception $e) {
			$this->logger->error('Failed to read the model catalogue');
			return $this->errorResponse('models_load_failed', $this->l10n->t('Failed to load models'), 400);
		}
	}

	private function normalizeModelChoice(string $reference, array $previous, bool $connectionsChanged): string {
		$reference = trim($reference);
		if (strlen($reference) > 512 || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
			throw new \InvalidArgumentException('Invalid model reference');
		}
		if ($reference === '' || in_array($reference, $previous, true)) {
			// Untouched legacy choices must not prevent unrelated settings changes.
			return $reference;
		}
		if (preg_match('/^(primary|secondary):\S+$/D', $reference)) {
			return $reference;
		}
		if ($connectionsChanged) {
			throw new \InvalidArgumentException('New model choices require an explicit provider');
		}
		return $this->llmClient->canonicalizeModelReference($reference)
			?? throw new \InvalidArgumentException('A model reference is required');
	}

	/**
	 * @NoAdminRequired
	 * List groups for selection when creating bots
	 */
	public function groups(): DataResponse {
		try {
			$groupManager = $this->groupManager;
			$currentUser = $this->userSession->getUser();

			$groups = [];
			// Prefer a broad search (limited) if available
			if (method_exists($groupManager, 'search')) {
				// Some implementations return array of IGroup, others array of strings
				$result = $groupManager->search('', 200);
				foreach ($result as $g) {
					if (is_object($g) && method_exists($g, 'getGID')) {
						$groups[] = [
							'id' => $g->getGID(),
							'displayName' => method_exists($g, 'getDisplayName') ? ($g->getDisplayName() ?? $g->getGID()) : $g->getGID(),
						];
					} else {
						$gid = (string)$g;
						$groups[] = [
							'id' => $gid,
							'displayName' => $gid,
						];
					}
				}
			} elseif (method_exists($groupManager, 'getGroups')) {
				$result = $groupManager->getGroups('', 200, 0);
				foreach ($result as $g) {
					$groups[] = [
						'id' => $g->getGID(),
						'displayName' => $g->getDisplayName() ?? $g->getGID(),
					];
				}
			}

			// Fallback to user's own groups if listing is restricted or empty
			if (count($groups) === 0 && $currentUser !== null) {
				$userGroups = $groupManager->getUserGroups($currentUser);
				foreach ($userGroups as $g) {
					$groups[] = [
						'id' => $g->getGID(),
						'displayName' => $g->getDisplayName() ?? $g->getGID(),
					];
				}
			}

			return new DataResponse(['groups' => $groups]);
		} catch (Exception $e) {
			$this->logger->error('Failed to list groups: ' . $e->getMessage());
			return $this->errorResponse('groups_load_failed', $this->l10n->t('Failed to load groups'), 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * List teams (Circles) available to the current user
	 */
	public function teams(): DataResponse {
		try {
			if (!class_exists('\\OCA\\Circles\\Api\\v1\\Circles')) {
				return new DataResponse(['teams' => []]);
			}

			$currentUser = $this->userSession->getUser();
			if ($currentUser === null) {
				return new DataResponse(['teams' => []]);
			}

			$appManager = $this->appManager;
			if (method_exists($appManager, 'isEnabledForUser')) {
				try {
					if (!$appManager->isEnabledForUser('circles', $currentUser)) {
						return new DataResponse(['teams' => []]);
					}
				} catch (\Throwable $e) {
					$this->logger->debug('Unable to verify Circles availability for user', [
						'exception' => $e,
					]);
				}
			}

			$userId = method_exists($currentUser, 'getUID') ? $currentUser->getUID() : null;
			if ($userId === null) {
				return new DataResponse(['teams' => []]);
			}

			$circles = \OCA\Circles\Api\v1\Circles::joinedCircles($userId, true);
			$teams = [];
			foreach ($circles as $circle) {
				try {
					$source = method_exists($circle, 'getSource') ? $circle->getSource() : null;
					if ($source !== null && !in_array($source, [16, 10001], true)) {
						continue;
					}
					$displayName = '';
					if (method_exists($circle, 'getDisplayName')) {
						$displayName = (string)$circle->getDisplayName();
					}
					if ($displayName === '' && method_exists($circle, 'getName')) {
						$displayName = (string)$circle->getName();
					}
					if ($displayName === '') {
						$displayName = (string)$circle->getSingleId();
					}
					$teams[] = [
						'id' => method_exists($circle, 'getSingleId') ? $circle->getSingleId() : $displayName,
						'displayName' => $displayName,
					];
				} catch (\Throwable $e) {
					$this->logger->debug('Failed to process circle for teams list', [
						'exception' => $e,
					]);
				}
			}

			usort($teams, static function (array $a, array $b): int {
				return strcasecmp($a['displayName'], $b['displayName']);
			});

			return new DataResponse(['teams' => $teams]);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to list teams: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			return new DataResponse(['teams' => []], 200);
		}
	}

	/**
	 * @AdminRequired
	 * Get rate limit status (admin-only)
	 */
	public function rateLimitStatus(): DataResponse {
		try {
			$status = $this->rateLimitService->getStatus();
			return new DataResponse($status);
		} catch (Exception $e) {
			$this->logger->error('Failed to get rate limit status: ' . $e->getMessage());
			return $this->errorResponse(
				'rate_limit_status_load_failed',
				$this->l10n->t('Failed to load rate limit status'),
				500,
			);
		}
	}

	/**
	 * @AdminRequired
	 * Manually process queued requests (admin-only)
	 */
	public function processQueue(): DataResponse {
		try {
			$processedCount = 0;
			$errors = [];
			$errorCodes = [];
			$this->rateLimitService->failExhaustedPendingRequests();
			$this->rateLimitService->recoverStaleResponseDeliveries();
			$readyRequests = $this->rateLimitService->getResponseReadyRequests(10);
			foreach ($readyRequests as $request) {
				$result = $this->processResponseReadyRequest($request);
				if ($result['success'] && !($result['skipped'] ?? false)) {
					$processedCount++;
				} elseif (!$result['success']) {
					$this->appendQueueResponseError($errors, $errorCodes, $request, $result['error'] ?? null);
				}
			}

			if (!$this->rateLimitService->isEnabled()) {
				if ($readyRequests !== []) {
					$remainingStats = $this->rateLimitService->getQueueStats();
					return new DataResponse([
						'success' => true,
						'processed' => $processedCount,
						'remaining' => $remainingStats['pending'],
						'errors' => $errors,
						'errorCodes' => $errorCodes,
					]);
				}
				return new DataResponse([
					'success' => false,
					'error' => $this->l10n->t('Rate limiting is not enabled'),
					'errorCode' => 'rate_limiting_disabled',
				], 400);
			}

			$stats = $this->rateLimitService->getQueueStats();
			if ($stats['pending'] === 0) {
				if ($readyRequests !== []) {
					return new DataResponse([
						'success' => true,
						'processed' => $processedCount,
						'remaining' => 0,
						'errors' => $errors,
						'errorCodes' => $errorCodes,
					]);
				}
				return new DataResponse([
					'success' => true,
					'processed' => 0,
					'message' => $this->l10n->t('No pending requests in queue'),
				]);
			}

			// Process up to 10 requests
			$maxToProcess = min(10 - count($readyRequests), $stats['pending']);

			for ($i = 0; $i < $maxToProcess; $i++) {
				// Check if we have rate limit capacity
				if (!$this->rateLimitService->canProcess()) {
					break;
				}

				$request = $this->rateLimitService->getNextPending();
				if ($request === null) {
					break;
				}

				// Process the request
				$result = $this->processQueuedRequest($request);
				if ($result['success'] && !($result['skipped'] ?? false)) {
					$processedCount++;
				} elseif (!$result['success']) {
					$this->appendQueueResponseError($errors, $errorCodes, $request, $result['error'] ?? null);
				}

				// Small delay between requests
				usleep(100000); // 100ms
			}

			$remainingStats = $this->rateLimitService->getQueueStats();

			return new DataResponse([
				'success' => true,
				'processed' => $processedCount,
				'remaining' => $remainingStats['pending'],
				'errors' => $errors,
				'errorCodes' => $errorCodes,
			]);
		} catch (Exception $e) {
			$this->logger->error('Failed to process queue: ' . $e->getMessage());
			return new DataResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to process the queue'),
				'errorCode' => 'queue_processing_failed',
			], 500);
		}
	}

	/**
	 * Sanitize per-item queue diagnostics at the synchronous admin API boundary.
	 * Queue persistence and trace records retain their technical details.
	 *
	 * @param array<int,string> $errors
	 * @param array<int,string> $errorCodes
	 */
	private function appendQueueResponseError(
		array &$errors,
		array &$errorCodes,
		QueuedRequest $request,
		?string $technicalError,
	): void {
		$this->logger->warning('Queued request failed during manual queue processing', [
			'request_id' => $request->getId(),
			'error' => $technicalError,
		]);
		$errors[] = $this->l10n->t('A queued request could not be processed.');
		$errorCodes[] = 'queued_request_failed';
	}

	/**
	 * Process a single queued request
	 *
	 * @return array{success: bool, error?: string, skipped?: bool}
	 */
	private function processQueuedRequest(QueuedRequest $request): array {
		try {
			$this->rateLimitService->markProcessing($request);
		} catch (\LogicException $e) {
			if (!$this->isProcessingClaimLost($e)) {
				throw $e;
			}
			$this->logger->debug('Skipping queued request claimed by another worker', [
				'request_id' => $request->getId(),
			]);
			return ['success' => true, 'skipped' => true];
		}
		if ($request->isStale(3600)) {
			$this->failProcessingIfOwned($request, 'Request expired (too old)');
			return ['success' => true, 'skipped' => true];
		}
		$this->rateLimitService->recordUsage();
		$traceRunId = null;
		$traceStatus = null;
		$traceErrorSummary = null;
		$agentExecutionStarted = false;
		$executionFailed = false;
		$terminalNotification = null;
		$deliverySucceeded = false;
		$agentExecutionCompleted = false;
		$responsePersisted = false;

		try {
			$bot = $this->botMapper->findById($request->getBotId());

			if (!$bot->getIsActive()) {
				throw new Exception('Bot is no longer active');
			}

			$traceRunId = $this->startQueuedTrace($request, $bot);
			$executionError = null;
			$agentExecutionStarted = true;
			$response = $this->botService->processMessage(
				$bot,
				$request->getMessage(),
				$request->getRoomToken(),
				$request->getUserId(),
				$request->getOriginalMessage(),
				null,
				true, // isFromQueue
				threadRootMessageId: $request->getThreadRootMessageId(),
				replyToMessageId: $request->getReplyToMessageId(),
				traceRunId: $traceRunId,
				onExecutionError: static function (?string $errorSummary = null) use (&$executionFailed, &$executionError): void {
					$executionFailed = true;
					$executionError = $errorSummary;
				},
				onIncomplete: static function (string $reason) use (&$traceStatus, &$traceErrorSummary): void {
					$traceStatus = 'incomplete';
					$traceErrorSummary = 'Output limit reached (' . $reason . ')';
				}
			);
			if ($executionFailed) {
				$terminalNotification = $response;
				throw new Exception($executionError ?? 'Agent execution failed');
			}
			$agentExecutionCompleted = true;

			$this->rateLimitService->markResponseReady($request, $response);
			$responsePersisted = true;
			$request = $this->rateLimitService->markResponseDeliveryAttempt($request);
			$deliveryOutcome = $this->talkHandler->sendReplyToTalkWithOutcome(
				$request->getRoomToken(),
				$response,
				$request->getReplyToMessageId() ?? 0,
				$request->getDeliveryReferenceId()
			);
			if ($deliveryOutcome['status'] !== TalkHandler::DELIVERY_SUCCESS) {
				$traceStatus = 'partial';
				$traceErrorSummary = $this->persistDeliveryFailure($request, $deliveryOutcome, $traceRunId);
				return ['success' => false, 'error' => $traceErrorSummary];
			}
			$deliverySucceeded = true;

			// A persistence failure after Talk accepted the response stays in the
			// delivery-only lane: the agent/provider/tools are never re-run, but lease
			// recovery may resend the stored response up to the bounded attempt budget.
			try {
				$this->rateLimitService->markCompleted($request, $response);
			} catch (\Throwable $e) {
				$traceStatus = 'partial';
				$traceErrorSummary = 'Queued response delivered, but completion persistence failed: ' . $e->getMessage();
				$this->traceService->recordEvent($traceRunId, 'queue_persistence', [
					'status' => 'error',
					'error_message' => $traceErrorSummary,
				]);
				$this->logger->error('Queued response delivered, but completion persistence failed', [
					'request_id' => $request->getId(),
					'exception' => $e->getMessage(),
				]);

				return ['success' => false, 'error' => $traceErrorSummary];
			}
			$traceStatus ??= 'success';

			return ['success' => true];

		} catch (DoesNotExistException $e) {
			$traceStatus = 'error';
			$traceErrorSummary = 'Bot no longer exists';
			if (!$this->failProcessingIfOwned($request, 'Bot no longer exists')) {
				return ['success' => true, 'skipped' => true];
			}
			return ['success' => false, 'error' => 'Bot not found'];
		} catch (Exception $e) {
			if ($this->isProcessingClaimLost($e)) {
				$traceStatus = 'partial';
				$traceErrorSummary = 'Queued processing ownership was lost; the current owner was left unchanged';
				$this->logger->warning('Queued processing ownership was lost', [
					'request_id' => $request->getId(),
				]);
				return ['success' => true, 'skipped' => true];
			}
			if ($responsePersisted && $this->isResponseDeliveryClaimLost($e)) {
				$traceStatus = 'partial';
				$traceErrorSummary = 'Queued response delivery ownership transferred to another worker';
				$this->logger->debug('Queued response delivery ownership transferred', [
					'request_id' => $request->getId(),
				]);
				return ['success' => true, 'skipped' => true];
			}
			if ($deliverySucceeded) {
				if ($traceStatus !== 'partial') {
					$traceStatus = 'partial';
					$traceErrorSummary = 'Queued response was delivered, but post-delivery bookkeeping failed: ' . $e->getMessage();
				}
				$this->logger->error('Post-delivery queue bookkeeping failed', [
					'request_id' => $request->getId(),
					'exception' => $e->getMessage(),
				]);

				return ['success' => false, 'error' => $traceErrorSummary];
			}
			if ($responsePersisted) {
				$traceStatus = 'partial';
				$traceErrorSummary = 'Queued response persisted, but delivery reconciliation was deferred: ' . $e->getMessage();
				$this->traceService->recordEvent($traceRunId, 'queue_delivery', [
					'status' => 'reconciliation_deferred',
					'error_message' => $traceErrorSummary,
				]);
				$this->logger->error('Queued delivery reconciliation deferred after response persistence', [
					'request_id' => $request->getId(),
					'exception' => $e->getMessage(),
				]);

				return ['success' => false, 'error' => $traceErrorSummary];
			}
			if ($agentExecutionCompleted) {
				$traceStatus = 'partial';
				$traceErrorSummary = 'Queued response persistence failed: ' . $e->getMessage();
				if (!$this->failProcessingIfOwned($request, $traceErrorSummary)) {
					return ['success' => true, 'skipped' => true];
				}
				$this->traceService->recordEvent($traceRunId, 'queue_persistence', [
					'status' => 'error',
					'error_message' => $traceErrorSummary,
				]);
				return ['success' => false, 'error' => $traceErrorSummary];
			}
			if ($traceStatus !== 'partial') {
				$traceStatus = 'error';
			}
			$traceErrorSummary = $e->getMessage();
			if ($agentExecutionStarted) {
				if (!$this->failProcessingIfOwned($request, 'Agent execution failed: ' . $e->getMessage())) {
					return ['success' => true, 'skipped' => true];
				}
				if ($terminalNotification !== null) {
					$this->talkHandler->sendReplyToTalk(
						$request->getRoomToken(),
						$terminalNotification,
						$request->getReplyToMessageId() ?? 0
					);
				}
			} elseif ($request->getAttempts() < QueuedRequest::MAX_ATTEMPTS) {
				if (!$this->retryProcessingIfOwned($request, $e->getMessage())) {
					return ['success' => true, 'skipped' => true];
				}
			} else {
				if (!$this->failProcessingIfOwned($request, 'Max retries exceeded: ' . $e->getMessage())) {
					return ['success' => true, 'skipped' => true];
				}
			}
			return ['success' => false, 'error' => $e->getMessage()];
		} finally {
			if ($traceRunId !== null && $traceStatus !== null) {
				$this->traceService->finishRun($traceRunId, $traceStatus, $traceErrorSummary);
			}
		}
	}

	/**
	 * @return array{success: bool, error?: string}
	 */
	private function processResponseReadyRequest(QueuedRequest $request): array {
		$traceRunId = $this->startDeliveryTrace($request);
		$traceStatus = 'partial';
		$traceErrorSummary = null;
		$result = $request->getResult();
		$referenceId = $request->getDeliveryReferenceId();

		try {
			if ($request->getAttempts() >= QueuedRequest::MAX_ATTEMPTS) {
				$traceErrorSummary = 'Queued response delivery attempts exhausted';
				$this->rateLimitService->markResponseDeliveryFailed($request, $traceErrorSummary);
				$this->traceService->recordEvent($traceRunId, 'queue_delivery', [
					'status' => 'error',
					'error_message' => $traceErrorSummary,
				]);
				return ['success' => false, 'error' => $traceErrorSummary];
			}

			$request = $this->rateLimitService->markResponseDeliveryAttempt($request);

			if ($result === null || trim($result) === '') {
				$traceStatus = 'error';
				$traceErrorSummary = 'Stored queued response is empty';
				$this->rateLimitService->markResponseDeliveryFailed($request, $traceErrorSummary);
				return ['success' => false, 'error' => $traceErrorSummary];
			}

			$this->traceService->recordEvent($traceRunId, 'queue_delivery', [
				'status' => 'started',
				'payload' => ['reference_id' => $referenceId],
			]);
			$deliveryOutcome = $this->talkHandler->sendReplyToTalkWithOutcome(
				$request->getRoomToken(),
				$result,
				$request->getReplyToMessageId() ?? 0,
				$referenceId
			);
			if ($deliveryOutcome['status'] !== TalkHandler::DELIVERY_SUCCESS) {
				$traceErrorSummary = $this->persistDeliveryFailure($request, $deliveryOutcome, $traceRunId);
				return ['success' => false, 'error' => $traceErrorSummary];
			}

			try {
				$this->rateLimitService->markCompleted($request, $result);
			} catch (\Throwable $e) {
				$traceErrorSummary = 'Queued response delivered, but completion persistence failed: ' . $e->getMessage();
				$this->traceService->recordEvent($traceRunId, 'queue_persistence', [
					'status' => 'error',
					'error_message' => $traceErrorSummary,
				]);
				return ['success' => false, 'error' => $traceErrorSummary];
			}

			$traceStatus = 'success';
			$this->traceService->recordEvent($traceRunId, 'queue_delivery', [
				'status' => 'success',
				'payload' => ['reference_id' => $referenceId],
			]);
			return ['success' => true];
		} catch (\Throwable $e) {
			if ($this->isResponseDeliveryClaimLost($e)) {
				$traceErrorSummary = 'Queued response delivery ownership transferred to another worker';
				$this->logger->debug('Queued response delivery ownership transferred', [
					'request_id' => $request->getId(),
				]);
				return ['success' => true, 'skipped' => true];
			}
			$traceErrorSummary ??= 'Queued response delivery reconciliation failed: ' . $e->getMessage();
			$this->logger->error('Queued response delivery reconciliation failed', [
				'request_id' => $request->getId(),
				'exception' => $e->getMessage(),
			]);
			return ['success' => false, 'error' => $traceErrorSummary];
		} finally {
			$this->traceService->finishRun($traceRunId, $traceStatus, $traceErrorSummary);
		}
	}

	/**
	 * @param array{status: string, error: ?string, http_status: ?int} $outcome
	 */
	private function persistDeliveryFailure(QueuedRequest $request, array $outcome, ?int $traceRunId): string {
		$error = $outcome['error'] ?? 'Talk delivery failed without an error detail';
		$retryable = $outcome['status'] === TalkHandler::DELIVERY_RETRYABLE
			|| $outcome['status'] === TalkHandler::DELIVERY_AMBIGUOUS;
		$stored = $retryable
			? $this->rateLimitService->markResponseDeliveryRetry($request, $error)
			: $this->rateLimitService->markResponseDeliveryFailed($request, $error);
		$retryScheduled = $stored->getStatus() === QueuedRequest::STATUS_RESPONSE_READY;

		$this->traceService->recordEvent($traceRunId, 'queue_delivery', [
			'status' => $retryScheduled ? 'retry_scheduled' : 'error',
			'error_message' => $stored->getError() ?? $error,
			'payload' => [
				'delivery_outcome' => $outcome['status'],
				'http_status' => $outcome['http_status'],
				'attempts' => $stored->getAttempts(),
			],
		]);

		return $stored->getError() ?? $error;
	}

	private function failProcessingIfOwned(QueuedRequest $request, string $error): bool {
		try {
			$this->rateLimitService->markProcessingFailed($request, $error);
			return true;
		} catch (\LogicException $e) {
			if (!$this->isProcessingClaimLost($e)) {
				throw $e;
			}
			$this->logger->warning('Ignoring stale processing failure from a former queue owner', [
				'request_id' => $request->getId(),
			]);
			return false;
		}
	}

	private function retryProcessingIfOwned(QueuedRequest $request, string $error): bool {
		try {
			$this->rateLimitService->markProcessingRetry($request, $error);
			return true;
		} catch (\LogicException $e) {
			if (!$this->isProcessingClaimLost($e)) {
				throw $e;
			}
			$this->logger->warning('Ignoring stale processing retry from a former queue owner', [
				'request_id' => $request->getId(),
			]);
			return false;
		}
	}

	private function isProcessingClaimLost(\Throwable $e): bool {
		return $e instanceof \LogicException
			&& $e->getMessage() === RateLimitService::PROCESSING_CLAIM_LOST_MESSAGE;
	}

	private function isResponseDeliveryClaimLost(\Throwable $e): bool {
		return $e instanceof \LogicException
			&& $e->getMessage() === RateLimitService::RESPONSE_DELIVERY_CLAIM_LOST_MESSAGE;
	}

	private function startQueuedTrace(QueuedRequest $request, Bot $bot): ?int {
		return $this->traceService->startRun([
			'user_id' => $request->getUserId(),
			'bot_id' => $request->getBotId(),
			'bot_mention_name' => $bot->getMentionName(),
			'room_token' => $request->getRoomToken(),
			'talk_message_id' => $request->getReplyToMessageId(),
			'reply_target_message_id' => $request->getReplyToMessageId(),
			'thread_root_message_id' => $request->getThreadRootMessageId(),
			'source' => 'queue',
			'user_message' => $request->getOriginalMessage() ?? $request->getMessage(),
		]);
	}

	private function startDeliveryTrace(QueuedRequest $request): ?int {
		return $this->traceService->startRun([
			'user_id' => $request->getUserId(),
			'bot_id' => $request->getBotId(),
			'room_token' => $request->getRoomToken(),
			'talk_message_id' => $request->getReplyToMessageId(),
			'reply_target_message_id' => $request->getReplyToMessageId(),
			'thread_root_message_id' => $request->getThreadRootMessageId(),
			'source' => 'queue_delivery',
			'user_message' => $request->getOriginalMessage() ?? $request->getMessage(),
		]);
	}

	/**
	 * @AdminRequired
	 */
	public function embeddingStatus(): DataResponse {
		try {
			return new DataResponse($this->embeddingAdmin->getStatus());
		} catch (Exception $e) {
			$this->logger->error('Failed to load embedding status', ['exception' => $e]);
			return $this->errorResponse('embedding_status_failed', $this->l10n->t('Failed to load embedding status'), 500);
		}
	}

	/**
	 * @AdminRequired
	 */
	public function catalogueStatus(): DataResponse {
		try {
			return new DataResponse($this->embeddingAdmin->getCatalogueStatus());
		} catch (Exception $e) {
			$this->logger->error('Failed to load Catalogue status', ['exception' => $e]);
			return $this->errorResponse('catalogue_status_failed', $this->l10n->t('Failed to load catalogue status'), 500);
		}
	}

	/**
	 * @AdminRequired
	 * Queue only: never run remote Catalogue ingestion inside the HTTP request.
	 */
	public function catalogueReindex(): DataResponse {
		try {
			$queued = $this->embeddingAdmin->queueCatalogue();
			return new DataResponse(['success' => true, 'queued' => $queued, 'already_queued' => !$queued]);
		} catch (\InvalidArgumentException $e) {
			return $this->errorResponse('catalogue_disabled', $this->l10n->t('Catalogue integration is not enabled'), 400);
		} catch (Exception $e) {
			$this->logger->error('Failed to queue Catalogue reindex', ['exception' => $e]);
			return $this->errorResponse('catalogue_reindex_failed', $this->l10n->t('Failed to queue catalogue reindex'), 500);
		}
	}

	/**
	 * @AdminRequired
	 * Queue all enabled scopes; report partial failures without losing accepted counts.
	 */
	public function reindexAllEmbeddings(): DataResponse {
		try {
			return new DataResponse($this->embeddingAdmin->queueAll());
		} catch (Exception $e) {
			$this->logger->error('Failed to queue global embedding reindex', ['exception' => $e]);
			return $this->errorResponse('reindex_failed', $this->l10n->t('Failed to queue reindex jobs'), 500);
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildSettingsPayload(Settings $settings): array {
		$data = $settings->jsonSerialize();
		$data['app_icon_upload_urls'] = [
			'black' => $this->urlGenerator->linkToRoute('educai.app_icon.upload', ['variant' => 'black']),
			'white' => $this->urlGenerator->linkToRoute('educai.app_icon.upload', ['variant' => 'white']),
		];
		$data['app_icon_preview_urls'] = [
			'black' => $this->urlGenerator->linkToRoute('educai.app_icon.preview', ['variant' => 'black']),
			'white' => $this->urlGenerator->linkToRoute('educai.app_icon.preview', ['variant' => 'white']),
		];
		$data['app_icon_runtime_urls'] = $this->appIconService->getRuntimeIconUrls();

		return $data;
	}

	private function errorResponse(string $errorCode, string $error, int $status): DataResponse {
		return new DataResponse([
			'error' => $error,
			'errorCode' => $errorCode,
		], $status);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extractRateLimitConfig(Settings $settings): array {
		return [
			'rate_limit_enabled' => (bool)$settings->getRateLimitEnabled(),
			'rate_limit_second' => $settings->getRateLimitSecond(),
			'rate_limit_minute' => $settings->getRateLimitMinute(),
			'rate_limit_hour' => $settings->getRateLimitHour(),
			'rate_limit_day' => $settings->getRateLimitDay(),
			'embedding_rate_limit_mode' => $settings->getEmbeddingRateLimitMode(),
			'embedding_rate_limit_second' => $settings->getEmbeddingRateLimitSecond(),
			'embedding_rate_limit_minute' => $settings->getEmbeddingRateLimitMinute(),
			'embedding_rate_limit_hour' => $settings->getEmbeddingRateLimitHour(),
			'embedding_rate_limit_day' => $settings->getEmbeddingRateLimitDay(),
		];
	}
}
