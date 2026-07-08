<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\AppInfo\Application;
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

	private IClientService $clientService;
	private SettingsService $settingsService;
	private LoggerInterface $logger;
	private ?IConfig $config;
	/** @var array<int,array{id:string,label:string,model:string,endpoint:string}>|null */
	private ?array $modelOptionsCache = null;

	public function __construct(
		IClientService $clientService,
		SettingsService $settingsService,
		LoggerInterface $logger,
		?IConfig $config = null
	) {
		$this->clientService = $clientService;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
		$this->config = $config;
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
		$settings = $this->settingsService->getSettings();
		$fullMessages = $this->prepareMessages($systemPrompt, $messages);
		$modelConfig = $this->resolveModelConfig($settings, $modelOverride ?: $settings->getDefaultModel());

		try {
			return $this->sendResolvedChatCompletion($fullMessages, $modelConfig, $settings, $options);
		} catch (\Exception $e) {
			$fallbackConfig = $this->isFallbackEligibleException($e)
				? $this->tryResolveFallbackModelConfig($settings, $modelConfig, $e)
				: null;
			if ($fallbackConfig !== null) {
				$this->logger->warning('LLM API request failed, retrying with fallback model', [
					'exception' => $e,
					'primary_model' => $modelConfig['id'],
					'fallback_model' => $fallbackConfig['id'],
				]);

				try {
					return $this->sendResolvedChatCompletion($fullMessages, $fallbackConfig, $settings, $options);
				} catch (\Exception $fallbackException) {
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
		$settings = $this->settingsService->getSettings();
		$fullMessages = $this->prepareMessages($systemPrompt, $messages);
		$modelConfig = $this->resolveModelConfig($settings, $modelOverride ?: $settings->getDefaultModel());
		$streamStarted = false;
		$trackedOnChunk = function (array $delta) use ($onChunk, &$streamStarted): void {
			if ($delta !== []) {
				$streamStarted = true;
			}
			$onChunk($delta);
		};

		try {
			return $this->streamResolvedChatCompletion($fullMessages, $trackedOnChunk, $modelConfig, $settings, $options);
		} catch (\Exception $e) {
			if (!$streamStarted && $this->shouldRetryStreamingWithoutUsage($e, $options)) {
				try {
					return $this->streamResolvedChatCompletion(
						$fullMessages,
						$trackedOnChunk,
						$modelConfig,
						$settings,
						array_merge($options, ['_disable_stream_usage' => true])
					);
				} catch (\Exception $retryException) {
					$e = $retryException;
				}
			}

			$fallbackConfig = !$streamStarted && $this->isFallbackEligibleException($e)
				? $this->tryResolveFallbackModelConfig($settings, $modelConfig, $e)
				: null;
			if ($fallbackConfig !== null) {
				$this->logger->warning('LLM streaming failed before first chunk, retrying with fallback model', [
					'exception' => $e,
					'primary_model' => $modelConfig['id'],
					'fallback_model' => $fallbackConfig['id'],
				]);

				try {
					return $this->streamResolvedChatCompletion($fullMessages, $trackedOnChunk, $fallbackConfig, $settings, $options);
				} catch (\Exception $fallbackException) {
					$this->logger->error('LLM fallback streaming failed: ' . $fallbackException->getMessage(), [
						'exception' => $fallbackException,
						'fallback_model' => $fallbackConfig['id'],
					]);
					throw new \Exception(self::STREAM_FAILURE_MESSAGE, 0, $fallbackException);
				}
			}

			$this->logger->error('LLM Streaming failed: ' . $e->getMessage(), [
				'exception' => $e,
				'model' => $modelConfig['id'],
			]);
			throw new \Exception(self::STREAM_FAILURE_MESSAGE, 0, $e);
		}
	}

	/**
	 * Build the provider JSON payload used for a chat completion request without sending it.
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<string,mixed> $options
	 * @return array{endpoint:string,model_reference:string,payload:array<string,mixed>}
	 */
	public function buildTraceChatCompletionPayload(string $systemPrompt, array $messages, ?string $modelOverride = null, array $options = [], bool $stream = false): array {
		$settings = $this->settingsService->getSettings();
		$fullMessages = $this->prepareMessages($systemPrompt, $messages);
		$modelConfig = $this->resolveModelConfig($settings, $modelOverride ?: $settings->getDefaultModel());

		return [
			'endpoint' => $modelConfig['endpoint_key'],
			'model_reference' => $modelConfig['id'],
			'payload' => $this->buildPayload($modelConfig['model'], $fullMessages, $options, $stream),
		];
	}

	/**
	 * Helper to prepare messages array
	 */
	private function prepareMessages(string $systemPrompt, array $messages): array {
		$fullMessages = [
			['role' => 'system', 'content' => $systemPrompt]
		];

		$lastRole = 'system';
		foreach ($messages as $msg) {
			$role = $msg['role'] ?? 'user';
			$content = (string)($msg['content'] ?? '');

			if ($role === 'tool') {
				$fullMessages[] = $msg;
				$lastRole = $role;
				continue;
			}

			if ($role === 'system') {
				$role = 'user';
			}

			if ($lastRole === 'system' && $role === 'assistant') {
				$fullMessages[] = ['role' => 'user', 'content' => ''];
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

			if ($role === 'assistant' && isset($msg['tool_calls'])) {
				$fullMessages[] = $msg;
			} else {
				$fullMessages[] = ['role' => $role, 'content' => $content];
			}
			$lastRole = $role;
		}
		return $fullMessages;
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
	private function resolveModelConfig($settings, ?string $modelReference): array {
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
			$endpointKey = $this->resolveEndpointKeyForUnprefixedModel($settings, $reference);
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

	private function resolveEndpointKeyForUnprefixedModel($settings, string $model): string {
		$model = trim($model);
		if ($model === '') {
			return 'primary';
		}

		$primaryHasModel = false;
		$secondaryHasModel = false;
		try {
			$options = $this->getCachedModelOptions($settings);
		} catch (\Exception $e) {
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
	private function resolveFallbackModelConfig($settings, array $currentModelConfig): ?array {
		$fallbackModel = trim((string)$settings->getFallbackModel());
		if ($fallbackModel === '') {
			return null;
		}

		$fallbackConfig = $this->resolveModelConfig($settings, $fallbackModel);
		if ($fallbackConfig['id'] === $currentModelConfig['id']) {
			return null;
		}

		return $fallbackConfig;
	}

	/**
	 * @param array{id:string} $currentModelConfig
	 * @return array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string}|null
	 */
	private function tryResolveFallbackModelConfig($settings, array $currentModelConfig, \Exception $originalException): ?array {
		try {
			return $this->resolveFallbackModelConfig($settings, $currentModelConfig);
		} catch (\Exception $fallbackConfigException) {
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
	private function sendResolvedChatCompletion(array $fullMessages, array $modelConfig, $settings, array $options): array {
		$client = $this->clientService->newClient();
		$payload = $this->buildPayload($modelConfig['model'], $fullMessages, $options, false);

		$this->logger->debug('Sending chat completion request', [
			'model' => $modelConfig['id'],
			'endpoint' => $modelConfig['endpoint_key'],
			'message_count' => count($fullMessages),
			'message_roles' => array_map(fn($m) => $m['role'] ?? 'unknown', $fullMessages),
			'has_tools' => isset($payload['tools']) && count($payload['tools']) > 0,
		]);

		$response = $client->post($modelConfig['endpoint'], [
			'headers' => [
				'Authorization' => 'Bearer ' . $modelConfig['api_key'],
				'Content-Type' => 'application/json',
			],
			'json' => $payload,
			'timeout' => $options['timeout'] ?? $this->settingsService->normalizePositiveInteger(
				$settings->getLlmChatTimeout(),
				SettingsService::DEFAULT_LLM_CHAT_TIMEOUT
			),
		]);

		$this->throwForHttpError($response);
		$body = json_decode($response->getBody(), true);
		$rateLimitHeaders = $this->extractRateLimitHeaders($response);

		if (isset($body['choices'][0]['message'])) {
			$message = $body['choices'][0]['message'];
			return [
				'content' => $message['content'] ?? '',
				'model' => $body['model'] ?? $modelConfig['model'],
				'model_reference' => $modelConfig['id'],
				'model_endpoint' => $modelConfig['endpoint_key'],
				'usage' => $body['usage'] ?? null,
				'tool_calls' => $message['tool_calls'] ?? [],
				'finish_reason' => $body['choices'][0]['finish_reason'] ?? null,
				'raw' => $body,
				'rate_limit_headers' => $rateLimitHeaders,
			];
		}

		throw new \Exception('Invalid response from LLM provider');
	}

	/**
	 * @param array<int,array<string,mixed>> $fullMessages
	 * @param callable $onChunk
	 * @param array{id:string,endpoint_key:string,endpoint:string,api_key:string,model:string} $modelConfig
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function streamResolvedChatCompletion(array $fullMessages, callable $onChunk, array $modelConfig, $settings, array $options): array {
		$client = $this->clientService->newClient();
		$payload = $this->buildPayload($modelConfig['model'], $fullMessages, $options, true);

		$this->logger->debug('Starting streaming chat completion', [
			'model' => $modelConfig['id'],
			'endpoint' => $modelConfig['endpoint_key'],
			'message_count' => count($fullMessages),
		]);

		$response = $client->post($modelConfig['endpoint'], [
			'headers' => [
				'Authorization' => 'Bearer ' . $modelConfig['api_key'],
				'Content-Type' => 'application/json',
			],
			'json' => $payload,
			'timeout' => $options['timeout'] ?? $this->settingsService->normalizePositiveInteger(
				$settings->getLlmStreamTimeout(),
				SettingsService::DEFAULT_LLM_STREAM_TIMEOUT
			),
			'stream' => true,
		]);

		$this->throwForHttpError($response);
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

		while (!feof($body)) {
			$line = fgets($body);
			if ($line === false) {
				break;
			}

			$line = trim($line);
			if ($line === '') {
				continue;
			}

			if (str_starts_with($line, 'data: ')) {
				$data = substr($line, 6);
				if ($data === '[DONE]') {
					break;
				}

				$chunk = json_decode($data, true);
				if (is_array($chunk)) {
					if (isset($chunk['usage']) && is_array($chunk['usage'])) {
						$usage = $chunk['usage'];
					}

					$delta = $chunk['choices'][0]['delta'] ?? [];
					$onChunk($delta);

					if (isset($delta['content'])) {
						$finalContent .= $delta['content'];
					}
					if (isset($delta['tool_calls'])) {
						foreach ($delta['tool_calls'] as $toolCallChunk) {
							$index = $toolCallChunk['index'];
							if (!isset($finalToolCalls[$index])) {
								$finalToolCalls[$index] = [
									'id' => $toolCallChunk['id'] ?? '',
									'type' => 'function',
									'function' => ['name' => '', 'arguments' => '']
								];
							}
							if (isset($toolCallChunk['id'])) {
								$finalToolCalls[$index]['id'] = $toolCallChunk['id'];
							}
							if (isset($toolCallChunk['function']['name'])) {
								$finalToolCalls[$index]['function']['name'] .= $toolCallChunk['function']['name'];
							}
							if (isset($toolCallChunk['function']['arguments'])) {
								$finalToolCalls[$index]['function']['arguments'] .= $toolCallChunk['function']['arguments'];
							}
						}
					}
				}
			}
		}

		return [
			'content' => $finalContent,
			'model' => $modelConfig['model'],
			'model_reference' => $modelConfig['id'],
			'model_endpoint' => $modelConfig['endpoint_key'],
			'usage' => $usage,
			'tool_calls' => array_values($finalToolCalls),
			'finish_reason' => 'stop',
			'rate_limit_headers' => $rateLimitHeaders,
		];
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
			'temperature' => $options['temperature'] ?? 0.7,
			'max_tokens' => $options['max_tokens'] ?? 1000,
		];

		if ($stream) {
			$payload['stream'] = true;
			if (array_key_exists('stream_options', $options) && is_array($options['stream_options'])) {
				$payload['stream_options'] = $options['stream_options'];
			} elseif (empty($options['_disable_stream_usage'])) {
				$payload['stream_options'] = ['include_usage' => true];
			}
		}
		if (isset($options['tools']) && is_array($options['tools']) && count($options['tools']) > 0) {
			$payload['tools'] = $options['tools'];
		}
		if (array_key_exists('tool_choice', $options) && $options['tool_choice'] !== null && $options['tool_choice'] !== 'auto') {
			$payload['tool_choice'] = $options['tool_choice'];
		}
		foreach (['presence_penalty', 'frequency_penalty', 'top_p'] as $opt) {
			if (isset($options[$opt])) {
				$payload[$opt] = $options[$opt];
			}
		}

		// Anthropic/Claude rejects requests that set both temperature and top_p
		// ("`temperature` and `top_p` cannot both be specified"). OpenAI tolerates
		// it, so only drop top_p for Anthropic models and keep temperature.
		if (isset($payload['temperature'], $payload['top_p'])
			&& preg_match('/claude|anthropic/i', $model) === 1) {
			unset($payload['top_p']);
		}

		return $this->sanitizePayloadForJson($payload);
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

	private function throwForHttpError($response): void {
		if (!method_exists($response, 'getStatusCode')) {
			return;
		}

		$statusCode = (int)$response->getStatusCode();
		if ($statusCode >= 400) {
			throw new \Exception('LLM provider returned HTTP status ' . $statusCode, $statusCode);
		}
	}

	private function isFallbackEligibleException(\Exception $e): bool {
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
	 */
	private function shouldRetryStreamingWithoutUsage(\Exception $e, array $options): bool {
		if (array_key_exists('stream_options', $options) || !empty($options['_disable_stream_usage'])) {
			return false;
		}

		return in_array((int)$e->getCode(), [400, 422], true);
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
	private function fetchConfiguredModelOptions($settings, int $timeout): array {
		$options = [];
		$options = array_merge(
			$options,
			$this->fetchModelOptionsForEndpoint(
				'primary',
				'Primary',
				$this->getModelsEndpoint($settings->getApiProvider(), $settings->getApiEndpoint()),
				$this->settingsService->getApiKey(),
				$timeout
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
					$timeout
				)
			);
		}

		return $options;
	}

	/**
	 * @return array<int,array{id:string,label:string,model:string,endpoint:string}>
	 */
	private function getCachedModelOptions($settings): array {
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
		$options = $this->fetchConfiguredModelOptions($settings, $timeout);
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
	private function fetchModelOptionsForEndpoint(string $endpointKey, string $labelPrefix, string $modelsEndpoint, string $apiKey, int $timeout): array {
		if ($apiKey === '') {
			throw new \Exception($labelPrefix . ' API key not configured');
		}

		$client = $this->clientService->newClient();
		try {
			$response = $client->get($modelsEndpoint, [
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Accept' => 'application/json',
				],
				'timeout' => $timeout,
			]);

			$this->throwForHttpError($response);
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
