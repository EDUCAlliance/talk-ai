<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\SettingsMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

class SettingsService {
	public const DEFAULT_TEMPERATURE = 0.2;
	public const DEFAULT_LLM_CHAT_TIMEOUT = 90;
	public const DEFAULT_LLM_STREAM_TIMEOUT = 240;
	public const DEFAULT_LLM_MODELS_TIMEOUT = 20;
	public const APP_ICON_MODE_DEFAULT = 'default';
	public const APP_ICON_MODE_CUSTOM = 'custom';
	public const APP_ICON_UPLOAD_PREFIX = 'educai-upload://';

	private SettingsMapper $mapper;
	private CredentialService $credentialService;
	private TalkBotRegistrationService $talkBotRegistrationService;
	private LoggerInterface $logger;

	public function __construct(
		SettingsMapper $mapper,
		CredentialService $credentialService,
		TalkBotRegistrationService $talkBotRegistrationService,
		LoggerInterface $logger
	) {
		$this->mapper = $mapper;
		$this->credentialService = $credentialService;
		$this->talkBotRegistrationService = $talkBotRegistrationService;
		$this->logger = $logger;
	}

	/**
	 * Get the global settings.
	 * Note: This returns the Settings entity as-is. Sensitive fields are
	 * encrypted in the database and will be masked in JSON serialization.
	 *
	 * @return Settings
	 */
	public function getSettings(): Settings {
		$settings = $this->mapper->getSettings();

		// Check for and migrate any unencrypted credentials
		$this->migrateCredentialsIfNeeded($settings);

		return $settings;
	}

	/**
	 * Migrate plaintext credentials to encrypted storage.
	 * This is called on every getSettings() to transparently migrate
	 * any existing plaintext credentials.
	 */
	private function migrateCredentialsIfNeeded(Settings $settings): void {
		$needsUpdate = false;

		// Check API key
		$apiKey = $settings->getApiKey();
		if ($this->credentialService->needsMigration($apiKey)) {
			$this->logger->info('EducAI: Migrating API key to encrypted storage');
			$settings->setApiKey($this->credentialService->encrypt($apiKey));
			$needsUpdate = true;
		}

		// Check webhook secret
		$webhookSecret = $settings->getWebhookSecret();
		if ($this->credentialService->needsMigration($webhookSecret)) {
			$this->logger->info('EducAI: Migrating webhook secret to encrypted storage');
			$settings->setWebhookSecret($this->credentialService->encrypt($webhookSecret));
			$needsUpdate = true;
		}

		// Check embedding API key
		$embeddingApiKey = $settings->getEmbeddingApiKey();
		if ($this->credentialService->needsMigration($embeddingApiKey)) {
			$this->logger->info('EducAI: Migrating embedding API key to encrypted storage');
			$settings->setEmbeddingApiKey($this->credentialService->encrypt($embeddingApiKey));
			$needsUpdate = true;
		}

		$visionApiKey = $settings->getVisionApiKey();
		if ($this->credentialService->needsMigration($visionApiKey)) {
			$this->logger->info('EducAI: Migrating vision API key to encrypted storage');
			$settings->setVisionApiKey($this->credentialService->encrypt($visionApiKey));
			$needsUpdate = true;
		}

		$speechApiKey = $settings->getSpeechApiKey();
		if ($this->credentialService->needsMigration($speechApiKey)) {
			$this->logger->info('EducAI: Migrating speech API key to encrypted storage');
			$settings->setSpeechApiKey($this->credentialService->encrypt($speechApiKey));
			$needsUpdate = true;
		}

		$doclingApiKey = $settings->getDoclingApiKey();
		if ($this->credentialService->needsMigration($doclingApiKey)) {
			$this->logger->info('EducAI: Migrating Docling API key to encrypted storage');
			$settings->setDoclingApiKey($this->credentialService->encrypt($doclingApiKey));
			$needsUpdate = true;
		}

		$secondaryApiKey = $settings->getSecondaryApiKey();
		if ($this->credentialService->needsMigration($secondaryApiKey)) {
			$this->logger->info('EducAI: Migrating secondary API key to encrypted storage');
			$settings->setSecondaryApiKey($this->credentialService->encrypt($secondaryApiKey));
			$needsUpdate = true;
		}

		if ($needsUpdate) {
			$settings->setUpdatedAt(time());
			$this->mapper->update($settings);
			$this->logger->info('EducAI: Credential migration completed');
		}
	}

	/**
	 * Update settings
	 *
	 * @param string $apiProvider
	 * @param string $apiKey
	 * @param string $apiEndpoint
	 * @param string $defaultModel
	 * @param mixed $defaultTemperature
	 * @param string|null $webhookSecret
	 * @param bool|null $allowMultipleModels
	 * @param array<int,string>|null $allowedModels
	 * @param string|null $embeddingApiEndpoint
	 * @param string|null $embeddingApiKey
	 * @param string|null $embeddingModel
	 * @param int|null $ragChunkSize
	 * @param int|null $ragChunkOverlap
	 * @param bool|null $ragEnabled
	 * @param bool|null $catalogueEnabled
	 * @param string|null $catalogueApiEndpoint
	 * @param int|null $catalogueReindexHours
	 * @param bool|null $doclingEnabled
	 * @param string|null $doclingApiEndpoint
	 * @param string|null $doclingApiKey
	 * @param string|null $visionApiEndpoint
	 * @param string|null $visionApiKey
	 * @param string|null $visionModel
	 * @param string|null $speechApiEndpoint
	 * @param string|null $speechApiKey
	 * @param string|null $speechModel
	 * @param bool|null $rateLimitEnabled
	 * @param int|null $rateLimitSecond
	 * @param int|null $rateLimitMinute
	 * @param int|null $rateLimitHour
	 * @param int|null $rateLimitDay
	 * @param string|null $rateLimitQueueMessage
	 * @param int|null $conversationContextTokens
	 * @param string|null $embeddingRateLimitMode
	 * @param int|null $embeddingRateLimitSecond
	 * @param int|null $embeddingRateLimitMinute
	 * @param int|null $embeddingRateLimitHour
	 * @param int|null $embeddingRateLimitDay
	 * @param string|null $appIconUrl
	 * @param string|null $appIconMode
	 * @param string|null $appIconBlackUrl
	 * @param string|null $appIconWhiteUrl
	 * @return Settings
	 */
	public function updateSettings(
		string $apiProvider,
		string $apiKey,
		string $apiEndpoint,
		string $defaultModel,
		$defaultTemperature = self::DEFAULT_TEMPERATURE,
		?string $webhookSecret = null,
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
		?string $appIconWhiteUrl = null
	): Settings {
		$settings = $this->mapper->getSettings();
		$shouldSyncTalkBot = false;
		$talkBotSecret = '';
		
		$settings->setApiProvider($apiProvider);
		if (!empty($apiKey)) {
			// Encrypt API key before storing
			$settings->setApiKey($this->credentialService->encrypt($apiKey));
		}
		$settings->setApiEndpoint($apiEndpoint);
		$settings->setDefaultModel($defaultModel);
		if ($secondaryApiEndpoint !== null) {
			$settings->setSecondaryApiEndpoint($secondaryApiEndpoint !== '' ? $secondaryApiEndpoint : null);
		}
		if ($secondaryApiKey !== null && $secondaryApiKey !== '') {
			$settings->setSecondaryApiKey($this->credentialService->encrypt($secondaryApiKey));
		}
		if ($fallbackModel !== null) {
			$settings->setFallbackModel($fallbackModel !== '' ? $fallbackModel : null);
		}
		if ($defaultTemperature !== null) {
			$settings->setDefaultTemperature($this->normalizeTemperatureValue($defaultTemperature, false, 'default temperature'));
		} elseif ($settings->getDefaultTemperature() === null) {
			$settings->setDefaultTemperature(self::DEFAULT_TEMPERATURE);
		}
		// Only update the webhook secret when a non-empty value is provided.
		// This prevents accidentally clearing the stored secret when the UI leaves
		// the field blank (it is intentionally not prefilled for security).
		if ($webhookSecret !== null) {
			$normalizedSecret = trim((string)$webhookSecret);
			if ($normalizedSecret !== '') {
				// Encrypt webhook secret before storing
				$settings->setWebhookSecret($this->credentialService->encrypt($normalizedSecret));
				$shouldSyncTalkBot = true;
				$talkBotSecret = $normalizedSecret;
			}
		}
		if ($allowMultipleModels !== null) {
			$settings->setAllowMultipleModels($allowMultipleModels);
		}
		if ($allowedModels !== null) {
			// store as JSON array of strings
			$encoded = json_encode(array_values(array_map('strval', $allowedModels)));
			$settings->setAllowedModels($encoded === false ? '[]' : $encoded);
		}
		if ($embeddingApiEndpoint !== null) {
			$settings->setEmbeddingApiEndpoint($embeddingApiEndpoint !== '' ? $embeddingApiEndpoint : null);
		}
		if ($embeddingApiKey !== null && $embeddingApiKey !== '') {
			// Encrypt embedding API key before storing
			$settings->setEmbeddingApiKey($this->credentialService->encrypt($embeddingApiKey));
		}
		if ($embeddingModel !== null) {
			$settings->setEmbeddingModel($embeddingModel !== '' ? $embeddingModel : null);
		}
		if ($embeddingRateLimitMode !== null) {
			$settings->setEmbeddingRateLimitMode($this->normalizeEmbeddingRateLimitMode($embeddingRateLimitMode));
		}
		if (
			$embeddingRateLimitMode !== null
			|| $embeddingRateLimitSecond !== null
			|| $embeddingRateLimitMinute !== null
			|| $embeddingRateLimitHour !== null
			|| $embeddingRateLimitDay !== null
		) {
			$settings->setEmbeddingRateLimitSecond($embeddingRateLimitSecond !== null && $embeddingRateLimitSecond > 0 ? $embeddingRateLimitSecond : null);
			$settings->setEmbeddingRateLimitMinute($embeddingRateLimitMinute !== null && $embeddingRateLimitMinute > 0 ? $embeddingRateLimitMinute : 100);
			$settings->setEmbeddingRateLimitHour($embeddingRateLimitHour !== null && $embeddingRateLimitHour > 0 ? $embeddingRateLimitHour : 2000);
			$settings->setEmbeddingRateLimitDay($embeddingRateLimitDay !== null && $embeddingRateLimitDay > 0 ? $embeddingRateLimitDay : 4000);
		}
		if ($ragChunkSize !== null) {
			$settings->setRagChunkSize($ragChunkSize);
		}
		if ($ragChunkOverlap !== null) {
			$settings->setRagChunkOverlap($ragChunkOverlap);
		}
		if ($ragEnabled !== null) {
			$settings->setRagEnabled($ragEnabled);
		}
		if ($catalogueEnabled !== null) {
			$settings->setCatalogueEnabled($catalogueEnabled);
		}
		if ($catalogueApiEndpoint !== null) {
			$settings->setCatalogueApiEndpoint($catalogueApiEndpoint !== '' ? $catalogueApiEndpoint : null);
		}
		if ($catalogueReindexHours !== null) {
			$settings->setCatalogueReindexHours($catalogueReindexHours > 0 ? $catalogueReindexHours : 24);
		}
		if ($doclingEnabled !== null) {
			$settings->setDoclingEnabled($doclingEnabled);
		}
		if ($doclingApiEndpoint !== null) {
			$settings->setDoclingApiEndpoint($doclingApiEndpoint !== '' ? $doclingApiEndpoint : null);
		}
		if ($doclingApiKey !== null && $doclingApiKey !== '') {
			$settings->setDoclingApiKey($this->credentialService->encrypt($doclingApiKey));
		}
		if ($visionApiEndpoint !== null) {
			$settings->setVisionApiEndpoint($visionApiEndpoint !== '' ? $visionApiEndpoint : null);
		}
		if ($visionApiKey !== null && $visionApiKey !== '') {
			$settings->setVisionApiKey($this->credentialService->encrypt($visionApiKey));
		}
		if ($visionModel !== null) {
			$settings->setVisionModel($visionModel !== '' ? $visionModel : null);
		}
		if ($speechApiEndpoint !== null) {
			$settings->setSpeechApiEndpoint($speechApiEndpoint !== '' ? $speechApiEndpoint : null);
		}
		if ($speechApiKey !== null && $speechApiKey !== '') {
			$settings->setSpeechApiKey($this->credentialService->encrypt($speechApiKey));
		}
		if ($speechModel !== null) {
			$settings->setSpeechModel($speechModel !== '' ? $speechModel : null);
		}
		if ($rateLimitEnabled !== null) {
			$settings->setRateLimitEnabled($rateLimitEnabled);
		}
		if ($rateLimitEnabled !== null || $rateLimitSecond !== null || $rateLimitMinute !== null || $rateLimitHour !== null || $rateLimitDay !== null) {
			$settings->setRateLimitSecond($rateLimitSecond !== null && $rateLimitSecond > 0 ? $rateLimitSecond : null);
			$settings->setRateLimitMinute($rateLimitMinute !== null && $rateLimitMinute > 0 ? $rateLimitMinute : 30);
			$settings->setRateLimitHour($rateLimitHour !== null && $rateLimitHour > 0 ? $rateLimitHour : 200);
			$settings->setRateLimitDay($rateLimitDay !== null && $rateLimitDay > 0 ? $rateLimitDay : 1000);
		}
		if ($rateLimitQueueMessage !== null) {
			$settings->setRateLimitQueueMessage($rateLimitQueueMessage !== '' ? $rateLimitQueueMessage : null);
		}
		if ($conversationContextTokens !== null) {
			$settings->setConversationContextTokens($conversationContextTokens > 0 ? $conversationContextTokens : 8000);
		}
		if ($llmChatTimeout !== null) {
			$settings->setLlmChatTimeout($this->normalizePositiveInteger($llmChatTimeout, self::DEFAULT_LLM_CHAT_TIMEOUT));
		}
		if ($llmStreamTimeout !== null) {
			$settings->setLlmStreamTimeout($this->normalizePositiveInteger($llmStreamTimeout, self::DEFAULT_LLM_STREAM_TIMEOUT));
		}
		if ($llmModelsTimeout !== null) {
			$settings->setLlmModelsTimeout($this->normalizePositiveInteger($llmModelsTimeout, self::DEFAULT_LLM_MODELS_TIMEOUT));
		}
		if ($appIconMode !== null || $appIconBlackUrl !== null || $appIconWhiteUrl !== null) {
			$this->updateAppIconConfig(
				$settings,
				$appIconMode,
				$appIconBlackUrl,
				$appIconWhiteUrl
			);
		} elseif ($appIconUrl !== null) {
			$this->updateLegacyAppIconUrl($settings, $appIconUrl);
		}
		$settings->setUpdatedAt(time());

		$updatedSettings = $this->mapper->update($settings);

		if ($shouldSyncTalkBot) {
			$this->talkBotRegistrationService->syncRegistration($talkBotSecret);
		}

		return $updatedSettings;
	}

	public function getDefaultTemperature(): float {
		return $this->sanitizeTemperatureForRuntime(
			$this->mapper->getSettings()->getDefaultTemperature(),
			self::DEFAULT_TEMPERATURE
		);
	}

	/**
	 * @param mixed $value
	 */
	public function normalizeTemperatureValue($value, bool $allowNull = true, string $fieldName = 'temperature'): ?float {
		if ($value === null || $value === '') {
			if ($allowNull) {
				return null;
			}

			throw new \InvalidArgumentException(ucfirst($fieldName) . ' is required');
		}

		if (is_bool($value)) {
			throw new \InvalidArgumentException('Invalid ' . $fieldName . ' value');
		}

		if (is_string($value)) {
			$value = trim($value);
			if ($value === '') {
				if ($allowNull) {
					return null;
				}
				throw new \InvalidArgumentException(ucfirst($fieldName) . ' is required');
			}
		}

		if (!is_numeric($value)) {
			throw new \InvalidArgumentException('Invalid ' . $fieldName . ' value');
		}

		$temperature = (float)$value;
		if (!is_finite($temperature) || $temperature < 0.0 || $temperature > 1.0) {
			throw new \InvalidArgumentException(ucfirst($fieldName) . ' must be between 0.0 and 1.0');
		}

		return round($temperature, 2);
	}

	public function sanitizeTemperatureForRuntime(?float $value, float $fallback = self::DEFAULT_TEMPERATURE): float {
		if ($value === null || !is_finite($value) || $value < 0.0 || $value > 1.0) {
			return $fallback;
		}

		return round($value, 2);
	}

	public function normalizePositiveInteger(?int $value, int $fallback): int {
		return $value !== null && $value > 0 ? $value : $fallback;
	}

	public function normalizeAppIconUrl(?string $value): ?string {
		$url = trim((string)$value);
		if ($url === '') {
			return null;
		}

		if (strlen($url) > 1024) {
			throw new \InvalidArgumentException('App icon URL must be 1024 characters or shorter');
		}

		if (str_starts_with($url, self::APP_ICON_UPLOAD_PREFIX)) {
			if (!$this->isValidUploadedIconReference($url)) {
				throw new \InvalidArgumentException('App icon upload reference is invalid');
			}

			return $url;
		}

		if (preg_match('/^https?:\/\//i', $url) === 1) {
			if (filter_var($url, FILTER_VALIDATE_URL) === false) {
				throw new \InvalidArgumentException('App icon URL must be a valid http(s) URL');
			}

			return $url;
		}

		if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
			return $url;
		}

		throw new \InvalidArgumentException('App icon URL must be an http(s) URL, an absolute Nextcloud path, or an uploaded SVG reference');
	}

	private function isValidUploadedIconReference(string $value): bool {
		$parts = parse_url($value);
		if (!is_array($parts)) {
			return false;
		}

		return ($parts['scheme'] ?? '') === 'educai-upload'
			&& in_array((string)($parts['host'] ?? ''), ['black', 'white'], true);
	}

	public function normalizeAppIconMode(?string $value): string {
		$mode = trim((string)($value ?? self::APP_ICON_MODE_DEFAULT));
		if ($mode === '') {
			$mode = self::APP_ICON_MODE_DEFAULT;
		}

		if (!in_array($mode, [
			self::APP_ICON_MODE_DEFAULT,
			self::APP_ICON_MODE_CUSTOM,
		], true)) {
			throw new \InvalidArgumentException('App icon mode must be default or custom');
		}

		return $mode;
	}

	private function updateLegacyAppIconUrl(Settings $settings, ?string $appIconUrl): void {
		$normalized = $this->normalizeAppIconUrl($appIconUrl);
		$settings->setAppIconUrl($normalized);
		if ($normalized === null) {
			$settings->setAppIconMode(self::APP_ICON_MODE_DEFAULT);
			$settings->setAppIconPreset(null);
			$settings->setAppIconBlackUrl(null);
			$settings->setAppIconWhiteUrl(null);
			return;
		}

		$settings->setAppIconMode(self::APP_ICON_MODE_CUSTOM);
		$settings->setAppIconPreset(null);
		$settings->setAppIconBlackUrl($normalized);
		$settings->setAppIconWhiteUrl($normalized);
	}

	private function updateAppIconConfig(
		Settings $settings,
		?string $appIconMode,
		?string $appIconBlackUrl,
		?string $appIconWhiteUrl
	): void {
		$mode = $this->normalizeAppIconMode($appIconMode);
		if ($mode === self::APP_ICON_MODE_DEFAULT) {
			$settings->setAppIconMode(self::APP_ICON_MODE_DEFAULT);
			$settings->setAppIconPreset(null);
			$settings->setAppIconBlackUrl(null);
			$settings->setAppIconWhiteUrl(null);
			$settings->setAppIconUrl(null);
			return;
		}

		if ($mode === self::APP_ICON_MODE_CUSTOM) {
			$blackUrl = $this->normalizeAppIconUrl($appIconBlackUrl);
			$whiteUrl = $this->normalizeAppIconUrl($appIconWhiteUrl);
			if ($blackUrl === null || $whiteUrl === null) {
				throw new \InvalidArgumentException('Custom app icon mode requires both black and white icon URLs');
			}

			$settings->setAppIconMode(self::APP_ICON_MODE_CUSTOM);
			$settings->setAppIconPreset(null);
			$settings->setAppIconBlackUrl($blackUrl);
			$settings->setAppIconWhiteUrl($whiteUrl);
			$settings->setAppIconUrl($blackUrl);
			return;
		}
	}

	/**
	 * Get API key (for internal use only).
	 * Returns the decrypted API key.
	 *
	 * @return string
	 */
	public function getApiKey(): string {
		$encryptedKey = $this->mapper->getSettings()->getApiKey();
		return $this->credentialService->decrypt($encryptedKey ?? '');
	}

	public function getSecondaryApiKey(): ?string {
		return $this->decryptOptionalCredential($this->mapper->getSettings()->getSecondaryApiKey());
	}

	/**
	 * Get webhook secret (for internal use only).
	 * Returns the decrypted webhook secret.
	 *
	 * @return string
	 */
	public function getWebhookSecret(): string {
		$encryptedSecret = $this->mapper->getSettings()->getWebhookSecret();
		return $this->credentialService->decrypt($encryptedSecret ?? '');
	}

	/**
	 * Get embedding API key (for internal use only).
	 * Returns the decrypted embedding API key.
	 *
	 * @return string|null
	 */
	public function getEmbeddingApiKey(): ?string {
		$encryptedKey = $this->mapper->getSettings()->getEmbeddingApiKey();
		if ($encryptedKey === null || $encryptedKey === '') {
			return null;
		}
		$decrypted = $this->credentialService->decrypt($encryptedKey);
		return $decrypted !== '' ? $decrypted : null;
	}

	public function getVisionApiKey(): ?string {
		return $this->decryptOptionalCredential($this->mapper->getSettings()->getVisionApiKey());
	}

	public function getSpeechApiKey(): ?string {
		return $this->decryptOptionalCredential($this->mapper->getSettings()->getSpeechApiKey());
	}

	public function getDoclingApiKey(): ?string {
		return $this->decryptOptionalCredential($this->mapper->getSettings()->getDoclingApiKey());
	}

	public function getEmbeddingEndpoint(): ?string {
		return $this->mapper->getSettings()->getEmbeddingApiEndpoint();
	}

	public function getEmbeddingModel(): ?string {
		return $this->mapper->getSettings()->getEmbeddingModel();
	}

	/**
	 * @return array{
	 *     rag_enabled: bool,
	 *     embedding_api_endpoint: ?string,
	 *     embedding_api_key: ?string,
	 *     embedding_model: ?string,
	 *     embedding_rate_limit_mode: string,
	 *     embedding_rate_limit_second: ?int,
	 *     embedding_rate_limit_minute: int,
	 *     embedding_rate_limit_hour: int,
	 *     embedding_rate_limit_day: int,
	 *     rag_chunk_size: ?int,
	 *     rag_chunk_overlap: ?int
	 * }
	 */
	public function getRagConfig(): array {
		$settings = $this->mapper->getSettings();

		// Decrypt the embedding API key
		$embeddingApiKey = $settings->getEmbeddingApiKey();
		$decryptedEmbeddingKey = null;
		if ($embeddingApiKey !== null && $embeddingApiKey !== '') {
			$decryptedEmbeddingKey = $this->credentialService->decrypt($embeddingApiKey);
			if ($decryptedEmbeddingKey === '') {
				$decryptedEmbeddingKey = null;
			}
		}

		return [
			'rag_enabled' => (bool)$settings->getRagEnabled(),
			'embedding_api_endpoint' => $settings->getEmbeddingApiEndpoint(),
			'embedding_api_key' => $decryptedEmbeddingKey,
			'embedding_model' => $settings->getEmbeddingModel(),
			'embedding_rate_limit_mode' => $this->normalizeEmbeddingRateLimitMode($settings->getEmbeddingRateLimitMode()),
			'embedding_rate_limit_second' => $settings->getEmbeddingRateLimitSecond(),
			'embedding_rate_limit_minute' => $settings->getEmbeddingRateLimitMinute() ?? 100,
			'embedding_rate_limit_hour' => $settings->getEmbeddingRateLimitHour() ?? 2000,
			'embedding_rate_limit_day' => $settings->getEmbeddingRateLimitDay() ?? 4000,
			'rag_chunk_size' => $settings->getRagChunkSize(),
			'rag_chunk_overlap' => $settings->getRagChunkOverlap(),
		];
	}

	/**
	 * @return array{
	 *     catalogue_enabled: bool,
	 *     catalogue_api_endpoint: ?string,
	 *     catalogue_reindex_hours: int,
	 *     catalogue_last_indexed: ?int,
	 *     catalogue_course_count: int
	 * }
	 */
	public function getCatalogueConfig(): array {
		$settings = $this->mapper->getSettings();
		return [
			'catalogue_enabled' => (bool)$settings->getCatalogueEnabled(),
			'catalogue_api_endpoint' => $settings->getCatalogueApiEndpoint(),
			'catalogue_reindex_hours' => $settings->getCatalogueReindexHours() ?? 24,
			'catalogue_last_indexed' => $settings->getCatalogueLastIndexed(),
			'catalogue_course_count' => $settings->getCatalogueCourseCount() ?? 0,
		];
	}

	/**
	 * Update catalogue index statistics
	 */
	public function updateCatalogueIndexStats(int $courseCount): void {
		$settings = $this->mapper->getSettings();
		$settings->setCatalogueLastIndexed(time());
		$settings->setCatalogueCourseCount($courseCount);
		$settings->setUpdatedAt(time());
		$this->mapper->update($settings);
	}

	/**
	 * @return array{
	 *     docling_enabled: bool,
	 *     docling_api_endpoint: ?string,
	 *     api_key: ?string
	 * }
	 */
	public function getDoclingConfig(): array {
		$settings = $this->mapper->getSettings();

		return [
			'docling_enabled' => (bool)$settings->getDoclingEnabled(),
			'docling_api_endpoint' => $settings->getDoclingApiEndpoint(),
			'api_key' => $this->decryptOptionalCredential($settings->getDoclingApiKey())
				?? $this->credentialService->decrypt($settings->getApiKey() ?? ''),
		];
	}

	/**
	 * @return array{
	 *     enabled: bool,
	 *     endpoint: ?string,
	 *     api_key: ?string,
	 *     model: ?string
	 * }
	 */
	public function getVisionConfig(): array {
		$settings = $this->mapper->getSettings();
		$model = $settings->getVisionModel();
		$endpoint = $settings->getVisionApiEndpoint();

		return [
			'enabled' => $model !== null && $model !== '',
			'endpoint' => $endpoint,
			'api_key' => $this->getVisionApiKey(),
			'model' => $model !== '' ? $model : null,
		];
	}

	/**
	 * @return array{
	 *     enabled: bool,
	 *     endpoint: ?string,
	 *     api_key: ?string,
	 *     model: ?string
	 * }
	 */
	public function getSpeechConfig(): array {
		$settings = $this->mapper->getSettings();
		$model = $settings->getSpeechModel();
		$endpoint = $settings->getSpeechApiEndpoint();

		return [
			'enabled' => $model !== null && $model !== '',
			'endpoint' => $endpoint,
			'api_key' => $this->getSpeechApiKey(),
			'model' => $model !== '' ? $model : null,
		];
	}

	/**
	 * @return array{
	 *     rate_limit_enabled: bool,
	 *     rate_limit_second: ?int,
	 *     rate_limit_minute: int,
	 *     rate_limit_hour: int,
	 *     rate_limit_day: int,
	 *     rate_limit_queue_message: ?string
	 * }
	 */
	public function getRateLimitConfig(): array {
		$settings = $this->mapper->getSettings();
		$second = $settings->getRateLimitSecond();
		$minute = $settings->getRateLimitMinute();
		$hour = $settings->getRateLimitHour();
		$day = $settings->getRateLimitDay();
		return [
			'rate_limit_enabled' => (bool)$settings->getRateLimitEnabled(),
			'rate_limit_second' => $second !== null && $second > 0 ? $second : null,
			'rate_limit_minute' => $minute !== null && $minute > 0 ? $minute : 30,
			'rate_limit_hour' => $hour !== null && $hour > 0 ? $hour : 200,
			'rate_limit_day' => $day !== null && $day > 0 ? $day : 1000,
			'rate_limit_queue_message' => $settings->getRateLimitQueueMessage(),
		];
	}

	private function normalizeEmbeddingRateLimitMode(?string $mode): string {
		return match ($mode) {
			'disabled', 'custom' => $mode,
			default => 'inherit',
		};
	}

	private function decryptOptionalCredential(?string $encryptedValue): ?string {
		if ($encryptedValue === null || $encryptedValue === '') {
			return null;
		}

		$decrypted = $this->credentialService->decrypt($encryptedValue);
		return $decrypted !== '' ? $decrypted : null;
	}
}
