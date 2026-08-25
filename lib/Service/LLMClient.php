<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Exception\AgentRunInterruptedException;
use OCA\EducAI\Exception\IncompleteProviderStreamException;
use OCA\EducAI\Exception\ProviderAttemptBudgetExceededException;
use OCA\EducAI\Exception\ProviderContractException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class LLMClient {
	private const GWDG_CHAT_COMPLETIONS_ENDPOINT = 'https://chat-ai.academiccloud.de/v1/chat/completions';
	private const GWDG_MODELS_ENDPOINT = 'https://chat-ai.academiccloud.de/v1/models';
	private const MODEL_OPTIONS_CACHE_KEY = 'llm_model_options_cache';
	private const MODEL_OPTIONS_CACHE_TTL = 300;
	private const CHAT_FAILURE_MESSAGE = 'Failed to get response from AI';
	private const STREAM_FAILURE_MESSAGE = 'Failed to stream response from AI';
	private const CONTINUED_USER_PLACEHOLDER = '(continued)';
	private const PROVIDER_ERROR_BODY_LIMIT = 500;
	private const FALLBACK_CAUSE_OPTION = '_fallback_cause';
	private const QUARANTINE_LEGACY_STREAM_CONTENT_OPTION = '_quarantine_legacy_stream_content';
	private const STREAM_TO_SYNC_RETRY_REASON = 'stream_to_sync_retry';
	private const FALLBACK_CAUSES = [
		'timeout',
		'network',
		'rate_limit',
		'http_5xx',
		'http_4xx',
		'availability',
		'other',
	];

	private IClientService $clientService;
	private SettingsService $settingsService;
	private LoggerInterface $logger;
	private ?IConfig $config;
	private ?TraceService $traceService;
	private ProviderResponseNormalizer $responseNormalizer;
	/** @var array<int,array{id:string,label:string,model:string,endpoint:string}>|null */
	private ?array $modelOptionsCache = null;
	/** @var \WeakMap<ProviderAttemptBudget,bool> */
	private \WeakMap $modelDiscoveryFailures;

	public function __construct(
		IClientService $clientService,
		SettingsService $settingsService,
		LoggerInterface $logger,
		?IConfig $config = null,
		?TraceService $traceService = null,
	) {
		$this->clientService = $clientService;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
		$this->config = $config;
		$this->traceService = $traceService;
		$this->responseNormalizer = new ProviderResponseNormalizer(new ToolCallIdGenerator());
		$this->modelDiscoveryFailures = new \WeakMap();
	}

	/**
	 * Send a chat completion request to the LLM provider
	 *
	 * @param string $systemPrompt
	 * @param array $messages Array of ['role' => 'user|assistant', 'content' => '...']
	 * @param array<string,mixed> $options
	 * @return array Response with 'content' key, optional 'tool_calls', and 'rate_limit_headers'
	 * @throws \Exception
	 */
	public function sendChatCompletion(string $systemPrompt, array $messages, ?string $modelOverride = null, array $options = []): array {
		$options = $this->withProviderAttemptBudget($options);
		$settings = $this->settingsService->getSettings();
		$fullMessages = $this->prepareMessages($systemPrompt, $messages);
		$modelConfig = $this->resolveModelConfig(
			$settings,
			$modelOverride ?: $settings->getDefaultModel(),
			$options,
			false,
		);

		try {
			return $this->sendResolvedChatCompletionWithReasoningRetry(
				$fullMessages,
				$modelConfig,
				$settings,
				$options,
				'selected',
			);
		} catch (\Exception $e) {
			if ($this->isNonRetryableProviderException($e)) {
				throw $e;
			}
			$fallbackOptions = $this->withFallbackCause($options, $e);
			$fallbackConfig = $this->isFallbackEligibleException($e)
				? $this->tryResolveFallbackModelConfig($settings, $modelConfig, $e, $fallbackOptions, false)
				: null;
			if ($fallbackConfig !== null) {
				$this->logger->warning('LLM API request failed, retrying with fallback model', [
					'exception' => $e,
					'primary_model' => $modelConfig['id'],
					'fallback_model' => $fallbackConfig['id'],
				]);

				try {
					return $this->sendResolvedChatCompletionWithReasoningRetry(
						$fullMessages,
						$fallbackConfig,
						$settings,
						$fallbackOptions,
						'fallback',
					);
				} catch (\Exception $fallbackException) {
					if ($this->isNonRetryableProviderException($fallbackException)) {
						throw $fallbackException;
					}
					$this->logger->error('LLM fallback request failed: ' . $fallbackException->getMessage(), [
						'exception' => $fallbackException,
						'fallback_model' => $fallbackConfig['id'],
					]);
					throw new \Exception(self::CHAT_FAILURE_MESSAGE, 0, $fallbackException);
				}
			}

			$this->logger->error('LLM API request failed: ' . $e->getMessage(), [
				'exception' => $e,
				'model' => $modelConfig['id'],
			]);
			throw new \Exception(self::CHAT_FAILURE_MESSAGE, 0, $e);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,string> $knownToolNames
	 * @param array<string,mixed> $options
	 */
	public function sendAgentTurn(
		string $systemPrompt,
		array $messages,
		array $knownToolNames,
		?string $modelOverride = null,
		array $options = [],
	): AgentTurn {
		$response = $this->sendChatCompletion($systemPrompt, $messages, $modelOverride, $options);
		return $this->normalizeAgentTurn($response, $knownToolNames, $options);
	}

	/**
	 * Send a streaming chat completion request
	 *
	 * @param string $systemPrompt
	 * @param array $messages
	 * @param callable $onChunk function(array $delta): void
	 * @param string|null $modelOverride
	 * @param array $options
	 * @return array Final assembled response
	 * @throws \Exception
	 */
	public function streamChatCompletion(string $systemPrompt, array $messages, callable $onChunk, ?string $modelOverride = null, array $options = []): array {
		$options = $this->withProviderAttemptBudget($options);
		$settings = $this->settingsService->getSettings();
		$fullMessages = $this->prepareMessages($systemPrompt, $messages);
		$modelConfig = $this->resolveModelConfig(
			$settings,
			$modelOverride ?: $settings->getDefaultModel(),
			$options,
			true,
		);
		$streamStarted = false;

		try {
			return $this->streamResolvedRouteWithRecovery(
				$fullMessages,
				$onChunk,
				$modelConfig,
				$settings,
				$options,
				$streamStarted,
				'selected',
			);
		} catch (\Exception $e) {
			$canFallbackAfterHiddenIncompleteStream = $e instanceof IncompleteProviderStreamException
				&& !$streamStarted
				&& !empty($options[self::QUARANTINE_LEGACY_STREAM_CONTENT_OPTION])
				&& $this->providerCompatibilityMode($modelConfig['id'], $options) !== ProviderResponseNormalizer::COMPATIBILITY_OFF;
			if ($this->isNonRetryableProviderException($e) && !$canFallbackAfterHiddenIncompleteStream) {
				throw $e;
			}
			$fallbackOptions = $this->withFallbackCause($options, $e);
			$fallbackConfig = !$streamStarted && ($canFallbackAfterHiddenIncompleteStream || $this->isFallbackEligibleException($e))
				? $this->tryResolveFallbackModelConfig($settings, $modelConfig, $e, $fallbackOptions, true)
				: null;
			if ($fallbackConfig !== null) {
				$this->logger->warning('LLM streaming failed before first chunk, retrying with fallback model', [
					'exception' => $e,
					'primary_model' => $modelConfig['id'],
					'fallback_model' => $fallbackConfig['id'],
				]);

				try {
					return $this->streamResolvedRouteWithRecovery(
						$fullMessages,
						$onChunk,
						$fallbackConfig,
						$settings,
						$fallbackOptions,
						$streamStarted,
						'fallback',
					);
				} catch (\Exception $fallbackException) {
					if ($this->isNonRetryableProviderException($fallbackException)) {
						throw $fallbackException;
					}
					$this->logger->error('LLM fallback streaming failed: ' . $fallbackException->getMessage(), [
						'exception' => $fallbackException,
						'fallback_model' => $fallbackConfig['id'],
					]);
					throw new \Exception(self::STREAM_FAILURE_MESSAGE, 0, $fallbackException);
				}
			}
			if ($canFallbackAfterHiddenIncompleteStream) {
				throw $e;
			}

			$this->logger->error('LLM Streaming failed: ' . $e->getMessage(), [
				'exception' => $e,
				'model' => $modelConfig['id'],
			]);
			throw new \Exception(self::STREAM_FAILURE_MESSAGE, 0, $e);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $fullMessages
	 * @param array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string} $modelConfig
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function streamResolvedRouteWithRecovery(
		array $fullMessages,
		callable $onChunk,
		array $modelConfig,
		$settings,
		array $options,
		bool &$streamStarted,
		string $route,
	): array {
		try {
			return $this->streamResolvedChatCompletionWithCompatibilityRetries(
				$fullMessages,
				$onChunk,
				$modelConfig,
				$settings,
				$options,
				$streamStarted,
				$route,
			);
		} catch (\Exception $e) {
			if ($streamStarted || !$this->isServerHttpError($e)) {
				throw $e;
			}

			$this->logger->warning('LLM streaming failed with a server error before first public chunk, retrying synchronously', [
				'exception' => $e,
				'model' => $modelConfig['id'],
				'route' => $route,
			]);

			return $this->sendResolvedChatCompletion(
				$fullMessages,
				$modelConfig,
				$settings,
				$this->withInitialModelParameterProfile($options, $modelConfig['model']),
				$route,
				self::STREAM_TO_SYNC_RETRY_REASON,
			);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,string> $knownToolNames
	 * @param array<string,mixed> $options
	 */
	public function streamAgentTurn(
		string $systemPrompt,
		array $messages,
		array $knownToolNames,
		callable $onChunk,
		?string $modelOverride = null,
		array $options = [],
	): AgentTurn {
		$options[self::QUARANTINE_LEGACY_STREAM_CONTENT_OPTION] = true;
		$response = $this->streamChatCompletion($systemPrompt, $messages, $onChunk, $modelOverride, $options);
		$turn = $this->normalizeAgentTurn($response, $knownToolNames, $options);
		$modelReference = is_string($response['model_reference'] ?? null)
			? $response['model_reference']
			: (is_string($response['model'] ?? null) ? $response['model'] : null);
		if (
			$this->providerCompatibilityMode($modelReference, $options) !== ProviderResponseNormalizer::COMPATIBILITY_OFF
			&& $turn->getToolCalls() === []
			&& $turn->getText() !== ''
		) {
			$onChunk(['content' => $turn->getText()]);
		}

		return $turn;
	}

	/**
	 * Build the provider JSON payload used for a chat completion request without sending it.
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<string,mixed> $options
	 * @return array{endpoint:string,model_reference:string,payload:array<string,mixed>}
	 */
	public function buildTraceChatCompletionPayload(string $systemPrompt, array $messages, ?string $modelOverride = null, array $options = [], bool $stream = false): array {
		$options = $this->withProviderAttemptBudget($options);
		$settings = $this->settingsService->getSettings();
		$fullMessages = $this->prepareMessages($systemPrompt, $messages);
		$modelConfig = $this->resolveModelConfig(
			$settings,
			$modelOverride ?: $settings->getDefaultModel(),
			$options,
			$stream,
		);

		return [
			'endpoint' => $modelConfig['endpoint_key'],
			'model_reference' => $modelConfig['id'],
			'payload' => $this->buildPayload(
				$modelConfig['model'],
				$fullMessages,
				$this->withInitialModelParameterProfile($options, $modelConfig['model']),
				$stream,
			),
		];
	}

	/**
	 * @param array<string,mixed> $response
	 * @param array<int,string> $knownToolNames
	 * @param array<string,mixed> $options
	 */
	private function normalizeAgentTurn(array $response, array $knownToolNames, array $options): AgentTurn {
		$modelReference = is_string($response['model_reference'] ?? null)
			? $response['model_reference']
			: (is_string($response['model'] ?? null) ? $response['model'] : null);
		$compatibilityMode = $this->providerCompatibilityMode($modelReference, $options);
		$turn = $this->responseNormalizer->normalize($response, $knownToolNames, $compatibilityMode);

		if ($turn->getCompatibilitySource() !== AgentTurn::COMPATIBILITY_NATIVE) {
			$telemetry = [
				'source' => $turn->getCompatibilitySource(),
				'model_reference' => $turn->getModelReference(),
				'endpoint_key' => $turn->getModelEndpoint(),
				'tool_call_count' => count($turn->getToolCalls()),
			];
			$this->logger->info('LLM provider compatibility parsing used', $telemetry);
			$this->traceService?->recordEvent($this->traceRunId($options), 'provider_compatibility', [
				'status' => 'used',
				'payload' => $telemetry,
			]);
		}

		return $turn;
	}

	/** @param array<string,mixed> $options */
	private function providerCompatibilityMode(?string $modelReference, array $options): string {
		$explicitMode = array_key_exists('legacy_tool_call_compatibility', $options)
			? (is_string($options['legacy_tool_call_compatibility'])
				? $options['legacy_tool_call_compatibility']
				: ProviderResponseNormalizer::COMPATIBILITY_OFF)
			: null;

		return $this->responseNormalizer->resolveCompatibilityMode($modelReference, $explicitMode);
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function withProviderAttemptBudget(array $options): array {
		if (!($options['provider_attempt_budget'] ?? null) instanceof ProviderAttemptBudget) {
			$options['provider_attempt_budget'] = new ProviderAttemptBudget();
		}

		return $options;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function providerAttemptBudget(array $options): ?ProviderAttemptBudget {
		$budget = $options['provider_attempt_budget'] ?? null;
		return $budget instanceof ProviderAttemptBudget ? $budget : null;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function agentRunControl(array $options): ?AgentRunControl {
		$runControl = $options['agent_run_control'] ?? null;
		return $runControl instanceof AgentRunControl ? $runControl : null;
	}

	private function isNonRetryableProviderException(\Exception $exception): bool {
		return $exception instanceof ProviderAttemptBudgetExceededException
			|| $exception instanceof AgentRunInterruptedException
			|| $exception instanceof IncompleteProviderStreamException
			|| $exception instanceof ProviderContractException;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function traceRunId(array $options): ?int {
		$runId = $options['trace_run_id'] ?? null;
		return is_numeric($runId) && (int)$runId > 0 ? (int)$runId : null;
	}

	/**
	 * Helper to prepare messages array
	 */
	private function prepareMessages(string $systemPrompt, array $messages): array {
		$fullMessages = [
			['role' => 'system', 'content' => $systemPrompt]
		];

		$lastRole = 'system';
		$knownToolCallIds = [];
		foreach ($messages as $msg) {
			$role = $msg['role'] ?? 'user';
			$content = (string)($msg['content'] ?? '');

			if ($role === 'tool') {
				unset($msg['name']);
				$toolCallId = isset($msg['tool_call_id']) && is_string($msg['tool_call_id'])
					? $msg['tool_call_id']
					: '';
				if ($toolCallId === '' || !isset($knownToolCallIds[$toolCallId])) {
					continue;
				}
				$fullMessages[] = $msg;
				$lastRole = $role;
				continue;
			}

			if ($role === 'system') {
				$role = 'user';
			}

			if ($role === 'assistant' && trim($content) === '' && !$this->messageHasToolCalls($msg)) {
				continue;
			}

			if ($role === 'user' && trim($content) === '') {
				$content = self::CONTINUED_USER_PLACEHOLDER;
			}

			if ($lastRole === 'system' && $role === 'assistant') {
				$fullMessages[] = ['role' => 'user', 'content' => self::CONTINUED_USER_PLACEHOLDER];
				$lastRole = 'user';
			}

			if ($lastRole === $role && $lastRole !== 'tool') {
				$lastIndex = count($fullMessages) - 1;
				if ($lastIndex >= 0 && ($fullMessages[$lastIndex]['role'] ?? '') === $role) {
					$separator = $fullMessages[$lastIndex]['content'] !== '' && $content !== '' ? "\n\n" : '';
					$fullMessages[$lastIndex]['content'] .= $separator . $content;
					continue;
				}
			}

			if ($role === 'assistant' && $this->messageHasToolCalls($msg)) {
				$fullMessages[] = $msg;
				foreach ($this->collectToolCallIds($msg) as $id) {
					$knownToolCallIds[$id] = true;
				}
			} else {
				$fullMessages[] = ['role' => $role, 'content' => $content];
			}
			$lastRole = $role;
		}
		return $fullMessages;
	}

	/**
	 * @param array<string,mixed> $message
	 */
	private function messageHasToolCalls(array $message): bool {
		return isset($message['tool_calls']) && is_array($message['tool_calls']) && $message['tool_calls'] !== [];
	}

	/**
	 * @param array<string,mixed> $message
	 * @return array<int,string>
	 */
	private function collectToolCallIds(array $message): array {
		$ids = [];
		foreach ($message['tool_calls'] ?? [] as $call) {
			if (!is_array($call)) {
				continue;
			}
			$id = $call['id'] ?? '';
			if (is_string($id) && $id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @return array{
	 *     id: string,
	 *     endpoint_key: string,
	 *     endpoint: string,
	 *     api_key: string,
	 *     model: string
	 * }
	 */
	private function resolveModelConfig(
		$settings,
		?string $modelReference,
		array $requestOptions = [],
		bool $streaming = false,
	): array {
		$reference = trim((string)($modelReference ?: $settings->getDefaultModel()));
		if ($reference === '') {
			throw new \Exception('Model is not configured');
		}

		$endpointKey = 'primary';
		$model = $reference;
		if (preg_match('/^(primary|secondary):(.+)$/', $reference, $matches) === 1) {
			$endpointKey = $matches[1];
			$model = $matches[2];
		} else {
			$endpointKey = $this->resolveEndpointKeyForUnprefixedModel(
				$settings,
				$reference,
				$requestOptions,
				$streaming,
			);
		}

		$model = trim($model);
		if ($model === '') {
			throw new \Exception('Model is not configured');
		}

		if ($endpointKey === 'secondary') {
			$endpoint = trim((string)$settings->getSecondaryApiEndpoint());
			if ($endpoint === '') {
				throw new \Exception('Secondary API endpoint is not configured');
			}

			$apiKey = (string)($this->settingsService->getSecondaryApiKey() ?? '');
			if ($apiKey === '') {
				throw new \Exception('Secondary API key is not configured');
			}

			return [
				'id' => 'secondary:' . $model,
				'endpoint_key' => 'secondary',
				'endpoint' => $endpoint,
				'api_key' => $apiKey,
				'model' => $model,
			];
		}

		$apiKey = $this->settingsService->getApiKey();
		if ($apiKey === '') {
			throw new \Exception('API key not configured');
		}

		return [
			'id' => 'primary:' . $model,
			'endpoint_key' => 'primary',
			'endpoint' => $this->getEndpoint($settings->getApiProvider(), $settings->getApiEndpoint()),
			'api_key' => $apiKey,
			'model' => $model,
		];
	}

	private function resolveEndpointKeyForUnprefixedModel(
		$settings,
		string $model,
		array $requestOptions,
		bool $streaming,
	): string {
		$model = trim($model);
		if ($model === '') {
			return 'primary';
		}

		$primaryHasModel = false;
		$secondaryHasModel = false;
		$budget = $this->providerAttemptBudget($requestOptions);
		if ($budget !== null && isset($this->modelDiscoveryFailures[$budget])) {
			return 'primary';
		}
		try {
			$options = $this->getCachedModelOptions($settings, $requestOptions, $model, $streaming);
		} catch (\Throwable $e) {
			if ($e instanceof \Exception && $this->isNonRetryableProviderException($e)) {
				throw $e;
			}
			if ($budget !== null) {
				$this->modelDiscoveryFailures[$budget] = true;
			}
			if (!($e instanceof \Exception)) {
				throw $e;
			}
			$this->logger->warning('LLM model lookup failed, defaulting unprefixed model to primary endpoint', [
				'exception' => $e,
				'model' => $model,
			]);
			return 'primary';
		}

		foreach ($options as $option) {
			if (($option['model'] ?? '') !== $model) {
				continue;
			}

			if (($option['endpoint'] ?? 'primary') === 'secondary') {
				$secondaryHasModel = true;
			} else {
				$primaryHasModel = true;
			}
		}

		if ($primaryHasModel) {
			return 'primary';
		}

		return $secondaryHasModel ? 'secondary' : 'primary';
	}

	/**
	 * @param array{id:string} $currentModelConfig
	 * @return array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string}|null
	 */
	private function resolveFallbackModelConfig(
		$settings,
		array $currentModelConfig,
		array $requestOptions,
		bool $streaming,
	): ?array {
		$fallbackModel = trim((string)$settings->getFallbackModel());
		if ($fallbackModel === '') {
			return null;
		}

		$fallbackConfig = $this->resolveModelConfig($settings, $fallbackModel, $requestOptions, $streaming);
		if ($fallbackConfig['id'] === $currentModelConfig['id']) {
			return null;
		}

		return $fallbackConfig;
	}

	/**
	 * @param array{id:string} $currentModelConfig
	 * @return array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string}|null
	 */
	private function tryResolveFallbackModelConfig(
		$settings,
		array $currentModelConfig,
		\Exception $originalException,
		array $requestOptions,
		bool $streaming,
	): ?array {
		try {
			return $this->resolveFallbackModelConfig($settings, $currentModelConfig, $requestOptions, $streaming);
		} catch (\Exception $fallbackConfigException) {
			if ($this->isNonRetryableProviderException($fallbackConfigException)) {
				throw $fallbackConfigException;
			}
			$this->logger->warning('LLM fallback model is configured but could not be resolved', [
				'exception' => $fallbackConfigException,
				'original_exception' => $originalException,
				'primary_model' => $currentModelConfig['id'],
			]);
			return null;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $fullMessages
	 * @param array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string} $modelConfig
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function sendResolvedChatCompletionWithReasoningRetry(
		array $fullMessages,
		array $modelConfig,
		$settings,
		array $options,
		string $route,
	): array {
		$options = $this->withInitialModelParameterProfile($options, $modelConfig['model']);
		try {
			return $this->sendResolvedChatCompletion($fullMessages, $modelConfig, $settings, $options, $route, 'initial');
		} catch (\Exception $e) {
			if ($this->isNonRetryableProviderException($e)) {
				throw $e;
			}
			if (!$this->shouldRetryWithReasoningParameters($e, $options, $modelConfig['model'])) {
				throw $e;
			}

			try {
				return $this->sendResolvedChatCompletion(
					$fullMessages,
					$modelConfig,
					$settings,
					array_merge($options, ['_use_reasoning_parameters' => true]),
					$route,
					'reasoning_retry',
				);
			} catch (\Exception $retryException) {
				if ($this->isNonRetryableProviderException($retryException)) {
					throw $retryException;
				}
				$this->logger->warning('LLM reasoning-parameter retry failed, keeping original error', [
					'exception' => $retryException,
					'model' => $modelConfig['id'],
				]);
				throw $e;
			}
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $fullMessages
	 * @param callable $onChunk
	 * @param array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string} $modelConfig
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function streamResolvedChatCompletionWithCompatibilityRetries(
		array $fullMessages,
		callable $onChunk,
		array $modelConfig,
		$settings,
		array $options,
		bool &$streamStarted,
		string $route,
	): array {
		$retryOptions = $this->withInitialModelParameterProfile($options, $modelConfig['model']);
		$compatibilityMode = $this->providerCompatibilityMode($modelConfig['id'], $options);
		$quarantineContent = !empty($options[self::QUARANTINE_LEGACY_STREAM_CONTENT_OPTION])
			&& $compatibilityMode !== ProviderResponseNormalizer::COMPATIBILITY_OFF;
		$providerOnChunk = static function (array $delta) use ($onChunk, &$streamStarted, $quarantineContent): void {
			if (!$quarantineContent) {
				if ($delta !== []) {
					$streamStarted = true;
				}
				$onChunk($delta);
				return;
			}

			$delta = array_intersect_key($delta, ['tool_calls' => true]);
			if ($delta !== []) {
				$streamStarted = true;
				$onChunk($delta);
			}
		};
		try {
			return $this->streamResolvedChatCompletion(
				$fullMessages,
				$providerOnChunk,
				$modelConfig,
				$settings,
				$retryOptions,
				$route,
				'initial',
			);
		} catch (\Exception $e) {
			if ($this->isNonRetryableProviderException($e)) {
				throw $e;
			}
			if (!$streamStarted && $this->shouldRetryStreamingWithoutUsage($e, $retryOptions)) {
				$retryOptions = array_merge($retryOptions, ['_disable_stream_usage' => true]);
				try {
					return $this->streamResolvedChatCompletion(
						$fullMessages,
						$providerOnChunk,
						$modelConfig,
						$settings,
						$retryOptions,
						$route,
						'stream_without_usage',
					);
				} catch (\Exception $retryException) {
					if ($this->isNonRetryableProviderException($retryException)) {
						throw $retryException;
					}
					$e = $retryException;
				}
			}

			if (!$streamStarted && $this->shouldRetryWithReasoningParameters($e, $retryOptions, $modelConfig['model'])) {
				$retryOptions = array_merge($retryOptions, ['_use_reasoning_parameters' => true]);
				try {
					return $this->streamResolvedChatCompletion(
						$fullMessages,
						$providerOnChunk,
						$modelConfig,
						$settings,
						$retryOptions,
						$route,
						'reasoning_retry',
					);
				} catch (\Exception $retryException) {
					if ($this->isNonRetryableProviderException($retryException)) {
						throw $retryException;
					}
					$this->logger->warning('LLM streaming reasoning-parameter retry failed, keeping original error', [
						'exception' => $retryException,
						'model' => $modelConfig['id'],
					]);
					throw $e;
				}
			}

			throw $e;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $fullMessages
	 * @param array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string} $modelConfig
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function sendResolvedChatCompletion(
		array $fullMessages,
		array $modelConfig,
		$settings,
		array $options,
		string $route,
		string $reason,
	): array {
		$client = $this->clientService->newClient();
		$payload = $this->buildPayload($modelConfig['model'], $fullMessages, $options, false);

		$this->logger->debug('Sending chat completion request', [
			'model' => $modelConfig['id'],
			'endpoint' => $modelConfig['endpoint_key'],
			'message_count' => count($fullMessages),
			'message_roles' => array_map(fn ($m) => $m['role'] ?? 'unknown', $fullMessages),
			'has_tools' => isset($payload['tools']) && count($payload['tools']) > 0,
		]);

		$requestedTimeout = $this->requestTimeout(
			$options['timeout'] ?? null,
			$this->settingsService->normalizePositiveInteger(
				$settings->getLlmChatTimeout(),
				SettingsService::DEFAULT_LLM_CHAT_TIMEOUT
			),
		);
		return $this->executeProviderRequest(fn (int|float $timeout) => $client->post($modelConfig['endpoint'], [
			'headers' => [
				'Authorization' => 'Bearer ' . $modelConfig['api_key'],
				'Content-Type' => 'application/json',
			],
			'json' => $payload,
			'timeout' => $timeout,
		]), $this->providerAttemptBudget($options), $this->traceRunId($options), $this->withFallbackAttemptMetadata([
			'route' => $route,
			'reason' => $reason,
			'model_reference' => $modelConfig['id'],
			'endpoint_key' => $modelConfig['endpoint_key'],
			'streaming' => false,
		], $options), $requestedTimeout, $this->agentRunControl($options), fn (object $response): array => $this->parseChatCompletionResponse(
			$response,
			$modelConfig,
		));
	}

	/**
	 * @param array<int,array<string,mixed>> $fullMessages
	 * @param callable $onChunk
	 * @param array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string} $modelConfig
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function streamResolvedChatCompletion(
		array $fullMessages,
		callable $onChunk,
		array $modelConfig,
		$settings,
		array $options,
		string $route,
		string $reason,
	): array {
		$client = $this->clientService->newClient();
		$payload = $this->buildPayload($modelConfig['model'], $fullMessages, $options, true);

		$this->logger->debug('Starting streaming chat completion', [
			'model' => $modelConfig['id'],
			'endpoint' => $modelConfig['endpoint_key'],
			'message_count' => count($fullMessages),
		]);

		$requestedTimeout = $this->requestTimeout(
			$options['timeout'] ?? null,
			$this->settingsService->normalizePositiveInteger(
				$settings->getLlmStreamTimeout(),
				SettingsService::DEFAULT_LLM_STREAM_TIMEOUT
			),
		);
		return $this->executeProviderRequest(fn (int|float $timeout) => $client->post($modelConfig['endpoint'], [
			'headers' => [
				'Authorization' => 'Bearer ' . $modelConfig['api_key'],
				'Content-Type' => 'application/json',
			],
			'json' => $payload,
			'timeout' => $timeout,
			'stream' => true,
		]), $this->providerAttemptBudget($options), $this->traceRunId($options), $this->withFallbackAttemptMetadata([
			'route' => $route,
			'reason' => $reason,
			'model_reference' => $modelConfig['id'],
			'endpoint_key' => $modelConfig['endpoint_key'],
			'streaming' => true,
		], $options), $requestedTimeout, $this->agentRunControl($options), fn (object $response): array => $this->parseStreamingResponse(
			$response,
			$onChunk,
			$modelConfig,
		));
	}

	/**
	 * @param array{id:string,endpoint_key:string,model:string} $modelConfig
	 * @return array<string,mixed>
	 */
	private function parseChatCompletionResponse(object $response, array $modelConfig): array {
		$rawBody = $this->readResponseBody($response);
		if (trim($rawBody) === '') {
			throw new IncompleteProviderStreamException();
		}

		try {
			$body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new ProviderContractException();
		}
		$rateLimitHeaders = $this->extractRateLimitHeaders($response);
		if (!is_array($body)) {
			throw new ProviderContractException();
		}
		$choices = $body['choices'] ?? null;
		if ($choices === null || $choices === []) {
			throw new IncompleteProviderStreamException();
		}
		if (!is_array($choices) || !array_is_list($choices) || !is_array($choices[0] ?? null)) {
			throw new ProviderContractException();
		}
		$choice = $choices[0];
		if (!array_key_exists('message', $choice)) {
			throw new IncompleteProviderStreamException();
		}

		$message = $choice['message'];
		if (!is_array($message)) {
			throw new ProviderContractException();
		}
		$content = $message['content'] ?? '';
		$this->responseNormalizer->assertContentShape($content);
		$toolCalls = $message['tool_calls'] ?? [];
		$this->responseNormalizer->assertNativeToolCallsShape($toolCalls);
		$finishReason = $this->validFinishReason($choice['finish_reason'] ?? null);
		if ($finishReason === null) {
			throw new IncompleteProviderStreamException();
		}

		return [
			'content' => $content,
			'model' => $body['model'] ?? $modelConfig['model'],
			'model_reference' => $modelConfig['id'],
			'model_endpoint' => $modelConfig['endpoint_key'],
			'usage' => $body['usage'] ?? null,
			'tool_calls' => $toolCalls,
			'finish_reason' => $finishReason,
			'raw' => $body,
			'rate_limit_headers' => $rateLimitHeaders,
		];
	}

	/**
	 * @param array{id:string,endpoint_key:string,model:string} $modelConfig
	 * @return array<string,mixed>
	 */
	private function parseStreamingResponse(object $response, callable $onChunk, array $modelConfig): array {
		$rateLimitHeaders = $this->extractRateLimitHeaders($response);
		$body = $response->getBody();
		if (!is_resource($body)) {
			$stream = fopen('php://temp', 'r+');
			fwrite($stream, (string)$body);
			rewind($stream);
			$body = $stream;
		}

		$finalContent = '';
		$finalToolCalls = [];
		$usage = null;
		$finishReason = null;
		$responseModel = $modelConfig['model'];
		$sawChoiceFrame = false;

		while (!feof($body)) {
			$line = fgets($body);
			if ($line === false) {
				break;
			}

			$line = trim($line);
			if ($line === '') {
				continue;
			}

			if (str_starts_with($line, 'data:')) {
				$data = ltrim(substr($line, 5));
				if ($data === '[DONE]') {
					break;
				}

				try {
					$chunk = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
				} catch (\JsonException) {
					throw new ProviderContractException();
				}
				if (!is_array($chunk)) {
					throw new ProviderContractException();
				}

				if (is_string($chunk['model'] ?? null) && $chunk['model'] !== '') {
					$responseModel = $chunk['model'];
				}
				$hasUsage = isset($chunk['usage']) && is_array($chunk['usage']);
				if ($hasUsage) {
					$usage = $chunk['usage'];
				}

				$hasChoices = array_key_exists('choices', $chunk);
				$choices = $hasChoices ? $chunk['choices'] : null;
				if ($hasChoices && (!is_array($choices) || !array_is_list($choices))) {
					throw new ProviderContractException();
				}
				if (!$hasChoices || $choices === []) {
					$allowedKeys = [
						'id',
						'created',
						'model',
						'object',
						'choices',
						'system_fingerprint',
						'service_tier',
						'obfuscation',
					];
					if ($hasUsage) {
						$allowedKeys[] = 'usage';
						if (array_diff(array_keys($chunk), $allowedKeys) !== []) {
							throw new ProviderContractException();
						}
					} elseif (
						!$hasChoices
						|| $sawChoiceFrame
						|| count($chunk) < 2
						|| array_diff(array_keys($chunk), $allowedKeys) !== []
						|| !$this->isValidStreamMetadataPreamble($chunk)
					) {
						throw new ProviderContractException();
					} else {
						continue;
					}
					$choice = [];
				} else {
					$choice = $choices[0] ?? null;
					if (!is_array($choice)) {
						throw new ProviderContractException();
					}
					$sawChoiceFrame = true;
				}
				$chunkFinishReason = $this->validFinishReason($choice['finish_reason'] ?? null);
				if ($chunkFinishReason !== null) {
					$finishReason = $chunkFinishReason;
				}
				$deltaValue = $choice['delta'] ?? null;
				if ($deltaValue !== null && !is_array($deltaValue)) {
					throw new ProviderContractException();
				}
				$delta = $deltaValue ?? [];
				$this->responseNormalizer->assertContentShape($delta['content'] ?? null);
				if (array_key_exists('tool_calls', $delta)) {
					$this->responseNormalizer->assertNativeToolCallsShape($delta['tool_calls'], true);
				}
				$onChunk($delta);

				if (isset($delta['content'])) {
					$finalContent .= $delta['content'];
				}
				if (isset($delta['tool_calls'])) {
					foreach ($delta['tool_calls'] as $toolCallChunk) {
						if (!is_array($toolCallChunk)) {
							continue;
						}
						$index = isset($toolCallChunk['index']) && is_numeric($toolCallChunk['index'])
							? (int)$toolCallChunk['index']
							: 0;
						if (!isset($finalToolCalls[$index])) {
							$finalToolCalls[$index] = [
								'id' => $toolCallChunk['id'] ?? '',
								'type' => 'function',
								'function' => ['name' => ''],
							];
						}
						if (isset($toolCallChunk['id'])) {
							$finalToolCalls[$index]['id'] = $toolCallChunk['id'];
						}
						$functionChunk = is_array($toolCallChunk['function'] ?? null)
							? $toolCallChunk['function']
							: [];
						if (isset($functionChunk['name'])) {
							$finalToolCalls[$index]['function']['name'] .= (string)$functionChunk['name'];
						}
						if (array_key_exists('arguments', $functionChunk)) {
							$argumentFragment = $functionChunk['arguments'];
							if (!is_string($argumentFragment)) {
								$argumentFragment = json_encode(
									$argumentFragment,
									JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
								);
								if ($argumentFragment === false) {
									throw new ProviderContractException();
								}
							}
							$finalToolCalls[$index]['function']['arguments'] ??= '';
							$finalToolCalls[$index]['function']['arguments'] .= $argumentFragment;
						}
					}
				}
			}
		}
		if ($finishReason === null) {
			throw new IncompleteProviderStreamException();
		}
		ksort($finalToolCalls, SORT_NUMERIC);
		$finalToolCalls = array_values($finalToolCalls);
		$this->responseNormalizer->assertNativeToolCallsShape($finalToolCalls);

		return [
			'content' => $finalContent,
			'model' => $responseModel,
			'model_reference' => $modelConfig['id'],
			'model_endpoint' => $modelConfig['endpoint_key'],
			'usage' => $usage,
			'tool_calls' => $finalToolCalls,
			'finish_reason' => $finishReason,
			'rate_limit_headers' => $rateLimitHeaders,
		];
	}

	private function validFinishReason(mixed $finishReason): ?string {
		return is_string($finishReason) && trim($finishReason) !== '' ? $finishReason : null;
	}

	/** @param array<string,mixed> $chunk */
	private function isValidStreamMetadataPreamble(array $chunk): bool {
		foreach (['id', 'model', 'object'] as $key) {
			if (array_key_exists($key, $chunk) && !is_string($chunk[$key])) {
				return false;
			}
		}
		if (array_key_exists('created', $chunk) && !is_int($chunk['created'])) {
			return false;
		}
		foreach (['system_fingerprint', 'service_tier', 'obfuscation'] as $key) {
			if (array_key_exists($key, $chunk) && !is_string($chunk[$key]) && $chunk[$key] !== null) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $fullMessages
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function buildPayload(string $model, array $fullMessages, array $options, bool $stream): array {
		$payload = [
			'model' => $model,
			'messages' => $fullMessages,
		];
		$maxTokens = $options['max_tokens'] ?? 1000;

		// Default to classic OpenAI-compatible params. Known reasoning models
		// and the bounded compatibility retry use max_completion_tokens.
		if (!empty($options['_use_reasoning_parameters'])) {
			$payload['max_completion_tokens'] = $maxTokens;
		} else {
			$payload['temperature'] = $options['temperature'] ?? 0.7;
			$payload['max_tokens'] = $maxTokens;
		}

		if ($stream) {
			$payload['stream'] = true;
			if (array_key_exists('stream_options', $options) && is_array($options['stream_options'])) {
				$payload['stream_options'] = $options['stream_options'];
			} elseif (empty($options['_disable_stream_usage'])) {
				$payload['stream_options'] = ['include_usage' => true];
			}
		}
		if (isset($options['tools']) && is_array($options['tools']) && count($options['tools']) > 0) {
			$payload['tools'] = $this->normalizeToolSchemaProperties($options['tools']);
		}
		if (array_key_exists('tool_choice', $options) && $options['tool_choice'] !== null && $options['tool_choice'] !== 'auto') {
			$payload['tool_choice'] = $options['tool_choice'];
		}
		if (!$this->modelRejectsSamplingExtras($model)) {
			foreach (['presence_penalty', 'frequency_penalty', 'top_p'] as $opt) {
				if (isset($options[$opt])) {
					$payload[$opt] = $options[$opt];
				}
			}
		}

		return $this->sanitizePayloadForJson($payload);
	}

	/**
	 * Last-line guard: json_decode turns `"properties": {}` into `[]`, which
	 * re-encodes as a JSON array. Claude/GPT/DeepSeek/Kimi then 400/422.
	 *
	 * @param array<int,mixed> $tools
	 * @return array<int,mixed>
	 */
	private function normalizeToolSchemaProperties(array $tools): array {
		foreach ($tools as $index => $tool) {
			if (!is_array($tool) || !isset($tool['function']['parameters']) || !is_array($tool['function']['parameters'])) {
				continue;
			}
			$tools[$index]['function']['parameters'] = $this->normalizeJsonSchemaProperties($tool['function']['parameters']);
		}

		return $tools;
	}

	/**
	 * @param array<string,mixed> $schema
	 * @return array<string,mixed>
	 */
	private function normalizeJsonSchemaProperties(array $schema): array {
		if (!array_key_exists('properties', $schema)) {
			return $schema;
		}

		$properties = $schema['properties'];
		if ($properties === [] || $properties instanceof \stdClass) {
			$schema['properties'] = new \stdClass();
			return $schema;
		}
		if (!is_array($properties)) {
			return $schema;
		}

		$normalized = [];
		foreach ($properties as $name => $subSchema) {
			$normalized[$name] = is_array($subSchema)
				? $this->normalizeJsonSchemaProperties($subSchema)
				: $subSchema;
		}
		$schema['properties'] = $normalized === [] ? new \stdClass() : $normalized;

		return $schema;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function sanitizePayloadForJson(array $payload): array {
		$sanitized = [];
		foreach ($payload as $key => $value) {
			$sanitizedKey = is_string($key) ? $this->ensureValidUtf8($key) : $key;
			$sanitized[$sanitizedKey] = $this->sanitizeValueForJson($value);
		}

		return $sanitized;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function sanitizeValueForJson($value) {
		if (is_string($value)) {
			return $this->ensureValidUtf8($value);
		}

		if (is_array($value)) {
			$sanitized = [];
			foreach ($value as $key => $item) {
				$sanitizedKey = is_string($key) ? $this->ensureValidUtf8($key) : $key;
				$sanitized[$sanitizedKey] = $this->sanitizeValueForJson($item);
			}
			return $sanitized;
		}

		return $value;
	}

	private function ensureValidUtf8(string $text): string {
		if (mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}

		return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
	}

	private function requestTimeout(mixed $requested, int $fallback): int|float {
		if (is_numeric($requested)) {
			return max(0.001, (float)$requested);
		}

		return $fallback;
	}

	/**
	 * @template TResponse of object
	 * @template TResult
	 * @param callable(int|float):TResponse $request
	 * @param array{route?:string,reason?:string,model_reference?:string,endpoint_key?:string,streaming?:bool,fallback_cause?:string} $attemptMetadata
	 * @param (callable(TResponse):TResult)|null $responseHandler
	 * @return TResponse|TResult
	 */
	private function executeProviderRequest(
		callable $request,
		?ProviderAttemptBudget $budget = null,
		?int $traceRunId = null,
		array $attemptMetadata = [],
		int|float $requestedTimeout = SettingsService::DEFAULT_LLM_CHAT_TIMEOUT,
		?AgentRunControl $runControl = null,
		?callable $responseHandler = null,
	): mixed {
		$effectiveTimeout = $runControl !== null
			? $runControl->clampTimeout($requestedTimeout)
			: $requestedTimeout;
		$attempt = null;
		if ($budget !== null) {
			try {
				$attempt = $budget->consume();
			} catch (ProviderAttemptBudgetExceededException $e) {
				$this->recordProviderAttemptBudgetExhausted($traceRunId, $e, $attemptMetadata);
				throw $e;
			}
		}
		$startedAt = microtime(true);
		$statusCode = null;

		try {
			$response = $request($effectiveTimeout);
			$statusCode = $this->httpStatusFromResponse($response);
			if ($statusCode !== null && $statusCode >= 400) {
				$this->throwForHttpError($response);
			}
			$result = $responseHandler !== null ? $responseHandler($response) : $response;
		} catch (\Throwable $e) {
			if ($attempt !== null && $budget !== null) {
				$this->recordProviderAttempt(
					$traceRunId,
					$attempt,
					$budget->getLimit(),
					$attemptMetadata,
					'error',
					$startedAt,
					$statusCode ?? $this->httpStatusFromException($e),
				);
			}
			if (!($e instanceof \Exception) || $this->isNonRetryableProviderException($e)) {
				throw $e;
			}
			$this->throwForCaughtHttpException($e);
		}

		if ($attempt !== null && $budget !== null) {
			$this->recordProviderAttempt(
				$traceRunId,
				$attempt,
				$budget->getLimit(),
				$attemptMetadata,
				'ok',
				$startedAt,
				$statusCode,
			);
		}
		return $result;
	}

	/**
	 * @param array{route?:string,reason?:string,model_reference?:string,endpoint_key?:string,streaming?:bool,fallback_cause?:string} $metadata
	 */
	private function recordProviderAttempt(
		?int $traceRunId,
		int $attempt,
		int $limit,
		array $metadata,
		string $status,
		float $startedAt,
		?int $httpStatus,
	): void {
		$payload = [
			'attempt' => $attempt,
			'limit' => $limit,
			'route' => $metadata['route'] ?? 'selected',
			'reason' => $metadata['reason'] ?? 'initial',
			'model_reference' => $metadata['model_reference'] ?? null,
			'endpoint_key' => $metadata['endpoint_key'] ?? null,
			'streaming' => $metadata['streaming'] ?? false,
		];
		if ($httpStatus !== null) {
			$payload['http_status'] = $httpStatus;
		}
		if (in_array($metadata['fallback_cause'] ?? null, self::FALLBACK_CAUSES, true)) {
			$payload['fallback_cause'] = $metadata['fallback_cause'];
		}

		$this->traceService?->recordEvent($traceRunId, 'provider_attempt', [
			'status' => $status,
			'duration_ms' => max(0, (int)round((microtime(true) - $startedAt) * 1000)),
			'payload' => $payload,
		]);
	}

	/**
	 * @param array{route?:string,reason?:string,model_reference?:string,endpoint_key?:string,streaming?:bool,fallback_cause?:string} $metadata
	 */
	private function recordProviderAttemptBudgetExhausted(
		?int $traceRunId,
		ProviderAttemptBudgetExceededException $exception,
		array $metadata,
	): void {
		$payload = [
			'attempt' => $exception->getConsumed() + 1,
			'limit' => $exception->getLimit(),
			'route' => $metadata['route'] ?? 'selected',
			'reason' => $metadata['reason'] ?? 'initial',
			'model_reference' => $metadata['model_reference'] ?? null,
			'endpoint_key' => $metadata['endpoint_key'] ?? null,
			'streaming' => $metadata['streaming'] ?? false,
		];
		if (in_array($metadata['fallback_cause'] ?? null, self::FALLBACK_CAUSES, true)) {
			$payload['fallback_cause'] = $metadata['fallback_cause'];
		}

		$this->traceService?->recordEvent($traceRunId, 'provider_attempt_budget_exhausted', [
			'status' => 'error',
			'payload' => $payload,
		]);
	}

	private function httpStatusFromResponse(mixed $response): ?int {
		if (!is_object($response) || !method_exists($response, 'getStatusCode')) {
			return null;
		}

		$statusCode = (int)$response->getStatusCode();
		return $statusCode > 0 ? $statusCode : null;
	}

	private function httpStatusFromException(\Throwable $exception): ?int {
		$candidate = $exception;
		while ($candidate instanceof \Throwable) {
			if (
				method_exists($candidate, 'hasResponse')
				&& method_exists($candidate, 'getResponse')
				&& $candidate->hasResponse()
			) {
				$statusCode = $this->httpStatusFromResponse($candidate->getResponse());
				if ($statusCode !== null) {
					return $statusCode;
				}
			}
			$candidate = $candidate->getPrevious();
		}

		$statusCode = (int)$exception->getCode();
		return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : null;
	}

	private function throwForHttpError($response): void {
		if (!is_object($response) || !method_exists($response, 'getStatusCode')) {
			return;
		}

		$statusCode = (int)$response->getStatusCode();
		if ($statusCode < 400) {
			return;
		}

		$this->throwProviderHttpError($statusCode, $this->readResponseBody($response));
	}

	private function throwForCaughtHttpException(\Exception $e): never {
		$candidate = $e;
		while ($candidate instanceof \Throwable) {
			if (
				method_exists($candidate, 'hasResponse')
				&& method_exists($candidate, 'getResponse')
				&& $candidate->hasResponse()
			) {
				$response = $candidate->getResponse();
				$statusCode = (is_object($response) && method_exists($response, 'getStatusCode'))
					? (int)$response->getStatusCode()
					: (int)$candidate->getCode();
				if ($statusCode >= 400) {
					$this->throwProviderHttpError($statusCode, $this->readResponseBody($response));
				}
			}
			$candidate = $candidate->getPrevious();
		}

		throw $e;
	}

	private function throwProviderHttpError(int $statusCode, string $rawBody): never {
		$excerpt = $this->excerptProviderErrorBody($rawBody);
		$this->logger->error('LLM provider returned HTTP error', [
			'status' => $statusCode,
			'response_body' => $excerpt,
		]);

		$message = 'LLM provider returned HTTP status ' . $statusCode;
		if ($excerpt !== '') {
			$message .= ': ' . $excerpt;
		}

		throw new \Exception($message, $statusCode);
	}

	private function readResponseBody(mixed $response): string {
		if (!is_object($response) || !method_exists($response, 'getBody')) {
			return '';
		}

		$body = $response->getBody();
		if (is_string($body)) {
			return $body;
		}
		if (is_resource($body)) {
			$contents = stream_get_contents($body);
			return $contents === false ? '' : $contents;
		}
		if (is_object($body) && (method_exists($body, '__toString') || $body instanceof \Stringable)) {
			return (string)$body;
		}

		return '';
	}

	private function excerptProviderErrorBody(string $body): string {
		$normalized = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
		if ($normalized === '') {
			return '';
		}

		if (mb_strlen($normalized) <= self::PROVIDER_ERROR_BODY_LIMIT) {
			return $normalized;
		}

		return mb_substr($normalized, 0, self::PROVIDER_ERROR_BODY_LIMIT) . '…';
	}

	private function isFallbackEligibleException(\Exception $e): bool {
		if ($this->isNonRetryableProviderException($e)) {
			return false;
		}

		$code = (int)$e->getCode();
		$message = strtolower($e->getMessage());

		// Transient / availability errors: the selected model or its deployment
		// is temporarily unavailable or overloaded, so retrying with the
		// configured fallback model is the right recovery. This mirrors the
		// OpenAI/LiteLLM "retryable" set (408, 409, 429, 5xx) plus a 404
		// "model not found" (self-hosted model not loaded), plus network errors
		// and provider/LiteLLM availability markers below.
		if (in_array($code, [404, 408, 409, 429], true) || ($code >= 500 && $code < 600)) {
			return true;
		}

		foreach ([
			// network / transport
			'timeout',
			'timed out',
			'connection',
			'connect',
			'network',
			'could not resolve',
			'dns',
			'ssl',
			'curl error 6',
			'curl error 7',
			'curl error 28',
			// model / deployment availability
			'not found',
			'notfounderror',
			'model_not_found',
			'no deployments available',
			'no healthy deployment',
			// temporarily overloaded / provider issues
			'overloaded',
			'service unavailable',
			'temporarily unavailable',
			'bad gateway',
			'gateway timeout',
			'try again in',
		] as $needle) {
			if (str_contains($message, $needle)) {
				return true;
			}
		}

		// Everything else (400 invalid request / bad schema / unsupported param,
		// 401 auth, 403 model access, 422 validation, budget exceeded) is a
		// request or config problem a different model will not fix -> surface it
		// instead of masking it behind a silent fallback.
		return false;
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function withFallbackCause(array $options, \Exception $exception): array {
		$options[self::FALLBACK_CAUSE_OPTION] = $this->fallbackCauseCategory($exception);
		return $options;
	}

	private function fallbackCauseCategory(\Exception $exception): string {
		$message = strtolower($exception->getMessage());
		$status = $this->httpStatusFromException($exception);

		foreach (['timeout', 'timed out', 'curl error 28'] as $needle) {
			if ($status === 408 || str_contains($message, $needle)) {
				return 'timeout';
			}
		}
		foreach (['connection', 'connect', 'network', 'could not resolve', 'dns', 'ssl', 'curl error 6', 'curl error 7'] as $needle) {
			if (str_contains($message, $needle)) {
				return 'network';
			}
		}
		if ($status === 429) {
			return 'rate_limit';
		}
		if ($status !== null && $status >= 500) {
			return 'http_5xx';
		}
		if ($status !== null && $status >= 400) {
			return 'http_4xx';
		}
		foreach ([
			'not found',
			'notfounderror',
			'model_not_found',
			'no deployments available',
			'no healthy deployment',
			'overloaded',
			'service unavailable',
			'temporarily unavailable',
			'bad gateway',
			'gateway timeout',
			'try again in',
		] as $needle) {
			if (str_contains($message, $needle)) {
				return 'availability';
			}
		}

		return 'other';
	}

	/**
	 * @param array{route?:string,reason?:string,model_reference?:string,endpoint_key?:string,streaming?:bool} $metadata
	 * @param array<string,mixed> $options
	 * @return array{route?:string,reason?:string,model_reference?:string,endpoint_key?:string,streaming?:bool,fallback_cause?:string}
	 */
	private function withFallbackAttemptMetadata(array $metadata, array $options): array {
		$cause = $options[self::FALLBACK_CAUSE_OPTION] ?? null;
		if (in_array($cause, self::FALLBACK_CAUSES, true)) {
			$metadata['fallback_cause'] = $cause;
		}

		return $metadata;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function shouldRetryStreamingWithoutUsage(\Exception $e, array $options): bool {
		if (array_key_exists('stream_options', $options) || !empty($options['_disable_stream_usage'])) {
			return false;
		}
		if ($this->isInvalidModelHttpError($e)) {
			return false;
		}

		return in_array((int)$e->getCode(), [400, 422], true);
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function shouldRetryWithReasoningParameters(\Exception $e, array $options, string $model = ''): bool {
		if (!empty($options['_use_reasoning_parameters'])) {
			return false;
		}
		if ($this->isInvalidModelHttpError($e)) {
			return false;
		}

		if ($model !== '' && $this->modelRejectsReasoningTokenParameters($model)) {
			return false;
		}

		return in_array((int)$e->getCode(), [400, 422], true);
	}

	private function modelRejectsReasoningTokenParameters(string $model): bool {
		return preg_match('/mistral|codestral/i', $model) === 1;
	}

	/** @param array<string,mixed> $options */
	private function withInitialModelParameterProfile(array $options, string $model): array {
		if ($this->modelUsesReasoningTokenParameters($model)) {
			$options['_use_reasoning_parameters'] = true;
		}

		return $options;
	}

	private function modelUsesReasoningTokenParameters(string $model): bool {
		return preg_match('/(?:^|[\\/:])(?:gpt-5|claude-sonnet-5)(?:[._-]|$)/i', $model) === 1;
	}

	private function isInvalidModelHttpError(\Exception $exception): bool {
		if (!in_array($this->httpStatusFromException($exception), [400, 422], true)) {
			return false;
		}

		$message = strtolower($exception->getMessage());
		foreach ([
			'invalid model',
			'invalid_model',
			'unknown model',
			'model_not_found',
			'model not found',
			'no such model',
			'model does not exist',
		] as $marker) {
			if (str_contains($message, $marker)) {
				return true;
			}
		}

		return false;
	}

	private function isServerHttpError(\Exception $exception): bool {
		$status = $this->httpStatusFromException($exception);
		return $status !== null && $status >= 500 && $status < 600;
	}

	private function modelRejectsSamplingExtras(string $model): bool {
		return preg_match('/claude|anthropic|gpt-5/i', $model) === 1;
	}

	/**
	 * Get API endpoint based on provider
	 *
	 * @param string $provider
	 * @param ?string $customEndpoint
	 * @return string
	 */
	private function getEndpoint(string $provider, ?string $customEndpoint): string {
		switch ($provider) {
			case 'openai':
			case 'gwdg':
				return self::GWDG_CHAT_COMPLETIONS_ENDPOINT;
			case 'azure':
				return $customEndpoint ?? ''; // Azure requires custom endpoint
			case 'custom':
				return $customEndpoint ?? '';
			default:
				throw new \Exception('Unknown or unconfigured API provider: ' . $provider);
		}
	}

	/**
	 * List available endpoint-specific model identifiers.
	 *
	 * @return array<int,string>
	 */
	public function listModels(): array {
		return array_map(
			static fn (array $option): string => $option['id'],
			$this->listModelOptions()
		);
	}

	/**
	 * @return array<int,array{id:string,label:string,model:string,endpoint:string}>
	 */
	public function listModelOptions(): array {
		$settings = $this->settingsService->getSettings();
		$timeout = $this->settingsService->normalizePositiveInteger(
			$settings->getLlmModelsTimeout(),
			SettingsService::DEFAULT_LLM_MODELS_TIMEOUT
		);
		$options = $this->fetchConfiguredModelOptions($settings, $timeout);
		$this->storeModelOptionsCache($settings, $options);

		return $options;
	}

	/**
	 * @return array<int,array{id:string,label:string,model:string,endpoint:string}>
	 */
	private function fetchConfiguredModelOptions(
		$settings,
		int $timeout,
		array $requestOptions = [],
		?string $modelReference = null,
		bool $streaming = false,
	): array {
		$options = [];
		$options = array_merge(
			$options,
			$this->fetchModelOptionsForEndpoint(
				'primary',
				'Primary',
				$this->getModelsEndpoint($settings->getApiProvider(), $settings->getApiEndpoint()),
				$this->settingsService->getApiKey(),
				$timeout,
				$requestOptions,
				$modelReference,
				$streaming,
			)
		);

		$secondaryEndpoint = trim((string)$settings->getSecondaryApiEndpoint());
		if ($secondaryEndpoint !== '') {
			$options = array_merge(
				$options,
				$this->fetchModelOptionsForEndpoint(
					'secondary',
					'Secondary',
					$this->getModelsEndpoint('custom', $secondaryEndpoint),
					(string)($this->settingsService->getSecondaryApiKey() ?? ''),
					$timeout,
					$requestOptions,
					$modelReference,
					$streaming,
				)
			);
		}

		return $options;
	}

	/**
	 * @return array<int,array{id:string,label:string,model:string,endpoint:string}>
	 */
	private function getCachedModelOptions(
		$settings,
		array $requestOptions = [],
		?string $modelReference = null,
		bool $streaming = false,
	): array {
		if ($this->modelOptionsCache !== null) {
			return $this->modelOptionsCache;
		}

		$fingerprint = $this->modelEndpointFingerprint($settings);
		if ($this->config !== null) {
			$raw = $this->config->getAppValue(Application::APP_ID, self::MODEL_OPTIONS_CACHE_KEY, '');
			$decoded = $raw !== '' ? json_decode($raw, true) : null;
			if (
				is_array($decoded)
				&& ($decoded['fingerprint'] ?? '') === $fingerprint
				&& (int)($decoded['expires_at'] ?? 0) >= time()
				&& isset($decoded['options'])
				&& is_array($decoded['options'])
			) {
				$this->modelOptionsCache = $this->normalizeCachedModelOptions($decoded['options']);
				return $this->modelOptionsCache;
			}
		}

		$timeout = $this->settingsService->normalizePositiveInteger(
			$settings->getLlmModelsTimeout(),
			SettingsService::DEFAULT_LLM_MODELS_TIMEOUT
		);
		$options = $this->fetchConfiguredModelOptions(
			$settings,
			$timeout,
			$requestOptions,
			$modelReference,
			$streaming,
		);
		$this->storeModelOptionsCache($settings, $options);

		return $options;
	}

	/**
	 * @param array<int,array{id:string,label:string,model:string,endpoint:string}> $options
	 */
	private function storeModelOptionsCache($settings, array $options): void {
		$this->modelOptionsCache = $options;
		if ($this->config === null) {
			return;
		}

		$this->config->setAppValue(Application::APP_ID, self::MODEL_OPTIONS_CACHE_KEY, json_encode([
			'fingerprint' => $this->modelEndpointFingerprint($settings),
			'expires_at' => time() + self::MODEL_OPTIONS_CACHE_TTL,
			'options' => $options,
		]) ?: '');
	}

	private function modelEndpointFingerprint($settings): string {
		return sha1(implode('|', [
			trim((string)$settings->getApiProvider()),
			rtrim(trim((string)$settings->getApiEndpoint()), '/'),
			rtrim(trim((string)$settings->getSecondaryApiEndpoint()), '/'),
		]));
	}

	/**
	 * @param array<mixed> $options
	 * @return array<int,array{id:string,label:string,model:string,endpoint:string}>
	 */
	private function normalizeCachedModelOptions(array $options): array {
		$normalized = [];
		foreach ($options as $option) {
			if (!is_array($option) || !isset($option['id'], $option['model'])) {
				continue;
			}

			$endpoint = ($option['endpoint'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary';
			$model = trim((string)$option['model']);
			if ($model === '') {
				continue;
			}

			$normalized[] = [
				'id' => (string)$option['id'],
				'label' => (string)($option['label'] ?? (($endpoint === 'secondary' ? 'Secondary' : 'Primary') . ' · ' . $model)),
				'model' => $model,
				'endpoint' => $endpoint,
			];
		}

		return $normalized;
	}

	/**
	 * @return array<int,array{id:string,label:string,model:string,endpoint:string}>
	 */
	private function fetchModelOptionsForEndpoint(
		string $endpointKey,
		string $labelPrefix,
		string $modelsEndpoint,
		string $apiKey,
		int $timeout,
		array $requestOptions = [],
		?string $modelReference = null,
		bool $streaming = false,
	): array {
		if ($apiKey === '') {
			throw new \Exception($labelPrefix . ' API key not configured');
		}

		$client = $this->clientService->newClient();
		try {
			$response = $this->executeProviderRequest(fn (int|float $effectiveTimeout) => $client->get($modelsEndpoint, [
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Accept' => 'application/json',
				],
				'timeout' => $effectiveTimeout,
			]), $this->providerAttemptBudget($requestOptions), $this->traceRunId($requestOptions), $this->withFallbackAttemptMetadata([
				'route' => 'model_discovery',
				'reason' => 'model_discovery',
				'model_reference' => $modelReference,
				'endpoint_key' => $endpointKey,
				'streaming' => $streaming,
			], $requestOptions), $timeout, $this->agentRunControl($requestOptions));

			$body = json_decode($response->getBody(), true);
			$models = $this->extractModelIds($body);
			sort($models);

			return array_values(array_map(
				static fn (string $model): array => [
					'id' => $endpointKey . ':' . $model,
					'label' => $labelPrefix . ' · ' . $model,
					'model' => $model,
					'endpoint' => $endpointKey,
				],
				array_values(array_unique($models))
			));
		} catch (\Exception $e) {
			if ($this->isNonRetryableProviderException($e)) {
				throw $e;
			}
			$this->logger->error('LLM list models failed: ' . $e->getMessage(), [
				'exception' => $e,
				'endpoint' => $endpointKey,
			]);
			throw new \Exception('Failed to list models: ' . $e->getMessage());
		}
	}

	/**
	 * @param mixed $body
	 * @return array<int,string>
	 */
	private function extractModelIds($body): array {
		$models = [];
		if (isset($body['data']) && is_array($body['data'])) {
			foreach ($body['data'] as $item) {
				if (isset($item['id'])) {
					$models[] = (string)$item['id'];
				} elseif (isset($item['name'])) {
					$models[] = (string)$item['name'];
				}
			}
		} elseif (isset($body['models']) && is_array($body['models'])) {
			foreach ($body['models'] as $item) {
				if (is_string($item)) {
					$models[] = $item;
				} elseif (isset($item['id'])) {
					$models[] = (string)$item['id'];
				}
			}
		}

		return $models;
	}

	private function getModelsEndpoint(string $provider, ?string $customEndpoint): string {
		switch ($provider) {
			case 'openai':
			case 'gwdg':
				return self::GWDG_MODELS_ENDPOINT;
			case 'azure':
			case 'custom':
				// For custom endpoints, we try to infer base URL if a chat/completions path is provided
				if (!empty($customEndpoint)) {
					$u = rtrim($customEndpoint, '/');
					// If ends with /v1/chat/completions -> /v1/models
					if (preg_match('#/v1/chat/completions$#', $u)) {
						return (string)preg_replace('#/v1/chat/completions$#', '/v1/models', $u);
					}
					// If ends with /chat/completions -> /models
					if (preg_match('#/chat/completions$#', $u)) {
						return (string)preg_replace('#/chat/completions$#', '/models', $u);
					}
					// If ends with /v1 -> append /models
					if (preg_match('#/v1$#', $u)) {
						return $u . '/models';
					}
					// Otherwise, append /v1/models
					return rtrim($u, '/') . '/v1/models';
				}
				// Fallback
				if (empty($customEndpoint)) {
					throw new \Exception('Custom API endpoint is not configured');
				}
				return rtrim($customEndpoint, '/') . '/models';
			default:
				throw new \Exception('Unknown or unconfigured API provider: ' . $provider);
		}
	}

	/**
	 * Extract rate limit headers from HTTP response
	 *
	 * Supports GWDG/AcademicCloud format:
	 * - x-ratelimit-limit-second, x-ratelimit-limit-minute, x-ratelimit-limit-hour, x-ratelimit-limit-day
	 * - x-ratelimit-remaining-second, x-ratelimit-remaining-minute, x-ratelimit-remaining-hour, x-ratelimit-remaining-day
	 * - ratelimit-reset (seconds until window resets)
	 *
	 * @param \OCP\Http\Client\IResponse $response
	 * @return array<string,int|string>
	 */
	private function extractRateLimitHeaders($response): array {
		$headers = [];

		$headerNames = [
			'x-ratelimit-limit-second',
			'x-ratelimit-limit-minute',
			'x-ratelimit-limit-hour',
			'x-ratelimit-limit-day',
			'x-ratelimit-limit-month',
			'x-ratelimit-remaining-second',
			'x-ratelimit-remaining-minute',
			'x-ratelimit-remaining-hour',
			'x-ratelimit-remaining-day',
			'x-ratelimit-remaining-month',
			'ratelimit-limit',
			'ratelimit-remaining',
			'ratelimit-reset',
		];

		foreach ($headerNames as $name) {
			try {
				$value = $response->getHeader($name);
				if ($value !== '' && $value !== null) {
					// Handle case where getHeader returns array
					if (is_array($value)) {
						$value = $value[0] ?? '';
					}
					$headers[$name] = is_numeric($value) ? (int)$value : $value;
				}
			} catch (\Exception $e) {
				// Header not present, skip
			}
		}

		if (count($headers) > 0) {
			$this->logger->debug('Extracted rate limit headers', [
				'headers' => $headers,
			]);
		}

		return $headers;
	}
}
