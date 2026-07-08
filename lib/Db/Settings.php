<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getApiProvider()
 * @method void setApiProvider(string $apiProvider)
 * @method ?string getApiKey()
 * @method void setApiKey(?string $apiKey)
 * @method ?string getApiEndpoint()
 * @method void setApiEndpoint(?string $apiEndpoint)
 * @method ?string getSecondaryApiEndpoint()
 * @method void setSecondaryApiEndpoint(?string $secondaryApiEndpoint)
 * @method ?string getSecondaryApiKey()
 * @method void setSecondaryApiKey(?string $secondaryApiKey)
 * @method string getDefaultModel()
 * @method void setDefaultModel(string $defaultModel)
 * @method ?string getFallbackModel()
 * @method void setFallbackModel(?string $fallbackModel)
 * @method ?string getAppIconUrl()
 * @method void setAppIconUrl(?string $appIconUrl)
 * @method string getAppIconMode()
 * @method void setAppIconMode(string $appIconMode)
 * @method ?string getAppIconPreset()
 * @method void setAppIconPreset(?string $appIconPreset)
 * @method ?string getAppIconBlackUrl()
 * @method void setAppIconBlackUrl(?string $appIconBlackUrl)
 * @method ?string getAppIconWhiteUrl()
 * @method void setAppIconWhiteUrl(?string $appIconWhiteUrl)
 * @method ?float getDefaultTemperature()
 * @method void setDefaultTemperature(?float $defaultTemperature)
 * @method bool getAllowMultipleModels()
 * @method void setAllowMultipleModels(bool $allowMultipleModels)
 * @method ?string getAllowedModels()
 * @method void setAllowedModels(?string $allowedModels)
 * @method ?string getWebhookSecret()
 * @method void setWebhookSecret(?string $webhookSecret)
 * @method ?string getEmbeddingApiEndpoint()
 * @method void setEmbeddingApiEndpoint(?string $embeddingApiEndpoint)
 * @method ?string getEmbeddingApiKey()
 * @method void setEmbeddingApiKey(?string $embeddingApiKey)
 * @method ?string getEmbeddingModel()
 * @method void setEmbeddingModel(?string $embeddingModel)
 * @method string getEmbeddingRateLimitMode()
 * @method void setEmbeddingRateLimitMode(string $embeddingRateLimitMode)
 * @method ?int getEmbeddingRateLimitSecond()
 * @method void setEmbeddingRateLimitSecond(?int $embeddingRateLimitSecond)
 * @method ?int getEmbeddingRateLimitMinute()
 * @method void setEmbeddingRateLimitMinute(?int $embeddingRateLimitMinute)
 * @method ?int getEmbeddingRateLimitHour()
 * @method void setEmbeddingRateLimitHour(?int $embeddingRateLimitHour)
 * @method ?int getEmbeddingRateLimitDay()
 * @method void setEmbeddingRateLimitDay(?int $embeddingRateLimitDay)
 * @method ?int getRagChunkSize()
 * @method void setRagChunkSize(?int $ragChunkSize)
 * @method ?int getRagChunkOverlap()
 * @method void setRagChunkOverlap(?int $ragChunkOverlap)
 * @method ?int getRagTopK()
 * @method void setRagTopK(?int $ragTopK)
 * @method ?int getRagMaxContextTokens()
 * @method void setRagMaxContextTokens(?int $ragMaxContextTokens)
 * @method bool getRagEnabled()
 * @method void setRagEnabled(bool $ragEnabled)
 * @method bool getCatalogueEnabled()
 * @method void setCatalogueEnabled(bool $catalogueEnabled)
 * @method ?string getCatalogueApiEndpoint()
 * @method void setCatalogueApiEndpoint(?string $catalogueApiEndpoint)
 * @method ?string getCatalogueApiKey()
 * @method void setCatalogueApiKey(?string $catalogueApiKey)
 * @method ?int getCatalogueReindexHours()
 * @method void setCatalogueReindexHours(?int $catalogueReindexHours)
 * @method ?int getCatalogueLastIndexed()
 * @method void setCatalogueLastIndexed(?int $catalogueLastIndexed)
 * @method ?int getCatalogueCourseCount()
 * @method void setCatalogueCourseCount(?int $catalogueCourseCount)
 * @method bool getDoclingEnabled()
 * @method void setDoclingEnabled(bool $doclingEnabled)
 * @method ?string getDoclingApiEndpoint()
 * @method void setDoclingApiEndpoint(?string $doclingApiEndpoint)
 * @method ?string getDoclingApiKey()
 * @method void setDoclingApiKey(?string $doclingApiKey)
 * @method ?string getVisionApiEndpoint()
 * @method void setVisionApiEndpoint(?string $visionApiEndpoint)
 * @method ?string getVisionApiKey()
 * @method void setVisionApiKey(?string $visionApiKey)
 * @method ?string getVisionModel()
 * @method void setVisionModel(?string $visionModel)
 * @method ?string getSpeechApiEndpoint()
 * @method void setSpeechApiEndpoint(?string $speechApiEndpoint)
 * @method ?string getSpeechApiKey()
 * @method void setSpeechApiKey(?string $speechApiKey)
 * @method ?string getSpeechModel()
 * @method void setSpeechModel(?string $speechModel)
 * @method bool getRateLimitEnabled()
 * @method void setRateLimitEnabled(bool $rateLimitEnabled)
 * @method ?int getRateLimitSecond()
 * @method void setRateLimitSecond(?int $rateLimitSecond)
 * @method ?int getRateLimitMinute()
 * @method void setRateLimitMinute(?int $rateLimitMinute)
 * @method ?int getRateLimitHour()
 * @method void setRateLimitHour(?int $rateLimitHour)
 * @method ?int getRateLimitDay()
 * @method void setRateLimitDay(?int $rateLimitDay)
 * @method ?string getRateLimitQueueMessage()
 * @method void setRateLimitQueueMessage(?string $rateLimitQueueMessage)
 * @method ?int getConversationContextTokens()
 * @method void setConversationContextTokens(?int $conversationContextTokens)
 * @method ?int getLlmChatTimeout()
 * @method void setLlmChatTimeout(?int $llmChatTimeout)
 * @method ?int getLlmStreamTimeout()
 * @method void setLlmStreamTimeout(?int $llmStreamTimeout)
 * @method ?int getLlmModelsTimeout()
 * @method void setLlmModelsTimeout(?int $llmModelsTimeout)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Settings extends Entity implements JsonSerializable {
	protected string $apiProvider = 'custom';
	protected ?string $apiKey = null;
	protected ?string $apiEndpoint = null;
	protected ?string $secondaryApiEndpoint = null;
	protected ?string $secondaryApiKey = null;
	protected string $defaultModel = 'llama-3.3-70b-instruct';
	protected ?string $fallbackModel = null;
	protected ?string $appIconUrl = null;
	protected string $appIconMode = 'default';
	protected ?string $appIconPreset = null;
	protected ?string $appIconBlackUrl = null;
	protected ?string $appIconWhiteUrl = null;
	protected ?float $defaultTemperature = 0.2;
    protected bool $allowMultipleModels = false;
    /**
     * Stored as JSON-encoded array of strings (model IDs)
     */
    protected ?string $allowedModels = null;
	protected ?string $webhookSecret = null;
    protected ?string $embeddingApiEndpoint = null;
    protected ?string $embeddingApiKey = null;
    protected ?string $embeddingModel = null;
    protected string $embeddingRateLimitMode = 'inherit';
    protected ?int $embeddingRateLimitSecond = null;
    protected ?int $embeddingRateLimitMinute = 100;
    protected ?int $embeddingRateLimitHour = 2000;
    protected ?int $embeddingRateLimitDay = 4000;
    protected ?int $ragChunkSize = null;
    protected ?int $ragChunkOverlap = null;
    protected ?int $ragTopK = null;
    protected ?int $ragMaxContextTokens = null;
    protected bool $ragEnabled = false;
    protected bool $catalogueEnabled = false;
    protected ?string $catalogueApiEndpoint = null;
    /** @deprecated No longer used - API is public */
    protected ?string $catalogueApiKey = null;
    protected ?int $catalogueReindexHours = 24;
    protected ?int $catalogueLastIndexed = null;
    protected ?int $catalogueCourseCount = 0;
    protected bool $doclingEnabled = false;
    protected ?string $doclingApiEndpoint = null;
    protected ?string $doclingApiKey = null;
    protected ?string $visionApiEndpoint = null;
    protected ?string $visionApiKey = null;
    protected ?string $visionModel = null;
    protected ?string $speechApiEndpoint = null;
    protected ?string $speechApiKey = null;
    protected ?string $speechModel = null;
    protected bool $rateLimitEnabled = false;
    protected ?int $rateLimitSecond = null;
    protected ?int $rateLimitMinute = 30;
    protected ?int $rateLimitHour = 200;
	protected ?int $rateLimitDay = 1000;
	protected ?string $rateLimitQueueMessage = null;
	protected ?int $conversationContextTokens = 8000;
	protected ?int $llmChatTimeout = 90;
	protected ?int $llmStreamTimeout = 240;
	protected ?int $llmModelsTimeout = 20;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('appIconMode', 'string');
		$this->addType('defaultTemperature', 'float');
		$this->addType('allowMultipleModels', 'bool');
		$this->addType('embeddingRateLimitMode', 'string');
		$this->addType('embeddingRateLimitSecond', 'integer');
		$this->addType('embeddingRateLimitMinute', 'integer');
		$this->addType('embeddingRateLimitHour', 'integer');
		$this->addType('embeddingRateLimitDay', 'integer');
		$this->addType('ragChunkSize', 'integer');
		$this->addType('ragChunkOverlap', 'integer');
		$this->addType('ragTopK', 'integer');
		$this->addType('ragMaxContextTokens', 'integer');
		$this->addType('ragEnabled', 'bool');
		$this->addType('catalogueEnabled', 'bool');
		$this->addType('catalogueReindexHours', 'integer');
		$this->addType('catalogueLastIndexed', 'integer');
		$this->addType('catalogueCourseCount', 'integer');
		$this->addType('doclingEnabled', 'bool');
		$this->addType('rateLimitEnabled', 'bool');
		$this->addType('rateLimitSecond', 'integer');
		$this->addType('rateLimitMinute', 'integer');
		$this->addType('rateLimitHour', 'integer');
		$this->addType('rateLimitDay', 'integer');
		$this->addType('conversationContextTokens', 'integer');
		$this->addType('llmChatTimeout', 'integer');
		$this->addType('llmStreamTimeout', 'integer');
		$this->addType('llmModelsTimeout', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'api_provider' => $this->apiProvider,
			'api_key' => $this->apiKey ? '***' : '', // Mask API key in JSON
			'api_endpoint' => $this->apiEndpoint ?? '',
			'secondary_api_endpoint' => $this->secondaryApiEndpoint ?? '',
			'secondary_api_key' => $this->secondaryApiKey ? '***' : '',
			'default_model' => $this->defaultModel,
			'fallback_model' => $this->fallbackModel,
			'app_icon_url' => $this->appIconUrl,
			'app_icon_mode' => $this->appIconMode ?: 'default',
			'app_icon_black_url' => $this->appIconBlackUrl,
			'app_icon_white_url' => $this->appIconWhiteUrl,
			'default_temperature' => $this->defaultTemperature ?? 0.2,
			'allow_multiple_models' => (bool)$this->allowMultipleModels,
			'allowed_models' => $this->decodeAllowedModels(),
			'webhook_secret' => $this->webhookSecret ? '***' : '', // Mask secret in JSON
			'embedding_api_endpoint' => $this->embeddingApiEndpoint,
			'embedding_api_key' => $this->embeddingApiKey ? '***' : '',
			'embedding_model' => $this->embeddingModel,
			'embedding_rate_limit_mode' => $this->embeddingRateLimitMode,
			'embedding_rate_limit_second' => $this->embeddingRateLimitSecond,
			'embedding_rate_limit_minute' => $this->embeddingRateLimitMinute,
			'embedding_rate_limit_hour' => $this->embeddingRateLimitHour,
			'embedding_rate_limit_day' => $this->embeddingRateLimitDay,
			'rag_chunk_size' => $this->ragChunkSize,
			'rag_chunk_overlap' => $this->ragChunkOverlap,
			'rag_top_k' => $this->ragTopK,
			'rag_max_context_tokens' => $this->ragMaxContextTokens,
			'rag_enabled' => (bool)$this->ragEnabled,
			'catalogue_enabled' => (bool)$this->catalogueEnabled,
			'catalogue_api_endpoint' => $this->catalogueApiEndpoint,
			'catalogue_reindex_hours' => $this->catalogueReindexHours ?? 24,
			'catalogue_last_indexed' => $this->catalogueLastIndexed,
			'catalogue_course_count' => $this->catalogueCourseCount ?? 0,
			'docling_enabled' => (bool)$this->doclingEnabled,
			'docling_api_endpoint' => $this->doclingApiEndpoint,
			'docling_api_key' => $this->doclingApiKey ? '***' : '',
			'vision_api_endpoint' => $this->visionApiEndpoint,
			'vision_api_key' => $this->visionApiKey ? '***' : '',
			'vision_model' => $this->visionModel,
			'speech_api_endpoint' => $this->speechApiEndpoint,
			'speech_api_key' => $this->speechApiKey ? '***' : '',
			'speech_model' => $this->speechModel,
			'rate_limit_enabled' => (bool)$this->rateLimitEnabled,
			'rate_limit_second' => $this->rateLimitSecond,
			'rate_limit_minute' => $this->rateLimitMinute,
			'rate_limit_hour' => $this->rateLimitHour,
			'rate_limit_day' => $this->rateLimitDay,
			'rate_limit_queue_message' => $this->rateLimitQueueMessage,
			'conversation_context_tokens' => $this->conversationContextTokens ?? 8000,
			'llm_chat_timeout' => $this->llmChatTimeout ?? 90,
			'llm_stream_timeout' => $this->llmStreamTimeout ?? 240,
			'llm_models_timeout' => $this->llmModelsTimeout ?? 20,
			'updated_at' => $this->updatedAt,
		];
	}

    /**
     * @return array<int,string>
     */
    private function decodeAllowedModels(): array {
        $raw = $this->allowedModels;
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            return array_values(array_map('strval', $decoded));
        }
        return [];
    }
}
