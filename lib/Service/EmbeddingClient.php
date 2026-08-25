<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Closure;
use Exception;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;
use Throwable;

class EmbeddingClient {
    private const RATE_LIMIT_WAIT_TIMEOUT_SECONDS = 120;
    private const RATE_LIMIT_POLL_SECONDS = 2;
    private const MAX_REQUEST_ATTEMPTS = 2;
    private const DEFAULT_RETRY_DELAY_SECONDS = 1;
    private const MAX_RETRY_DELAY_SECONDS = 2;

    private IClientService $clientService;
    private SettingsService $settingsService;
    private RateLimitService $rateLimitService;
    private LoggerInterface $logger;
    /** @var Closure(int):void */
    private Closure $sleeper;
    /** @var Closure():int */
    private Closure $clock;

    public function __construct(
        IClientService $clientService,
        SettingsService $settingsService,
        RateLimitService $rateLimitService,
        LoggerInterface $logger,
        ?callable $sleeper = null,
        ?callable $clock = null
    ) {
        $this->clientService = $clientService;
        $this->settingsService = $settingsService;
        $this->rateLimitService = $rateLimitService;
        $this->logger = $logger;
        $this->sleeper = $sleeper !== null
            ? Closure::fromCallable($sleeper)
            : static function (int $seconds): void {
                sleep($seconds);
            };
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn (): int => time();
    }

    /**
     * @param array<int,string> $texts
     * @return array<int,array<int,float>>
     * @throws Exception
     */
    public function embedTexts(array $texts, ?string $modelOverride = null): array {
        if (count($texts) === 0) {
            return [];
        }

        $settings = $this->settingsService->getSettings();
        $ragConfig = $this->settingsService->getRagConfig();
        $endpoint = $this->resolveEndpoint(
            $ragConfig['embedding_api_endpoint'] ?? null,
            $settings->getApiEndpoint()
        );
        if ($endpoint === null) {
            throw new Exception('Embedding endpoint not configured');
        }

        $apiKey = $ragConfig['embedding_api_key'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            // Fall back to main API key (decrypted) if no separate embedding key is configured
            $apiKey = $this->settingsService->getApiKey();
        }
        if ($apiKey === null || $apiKey === '') {
            throw new Exception('Embedding API key not configured');
        }

        $model = $this->getActiveModel($modelOverride);

        $client = $this->clientService->newClient();
        // Reduced batch size for large embedding models (e5-mistral-7b-instruct has 4096 dimensions)
        // to avoid timeout issues with slow embedding APIs
        $batchSize = 4;
        $result = [];
        $cursor = 0;

        while ($cursor < count($texts)) {
            $batchTexts = array_slice($texts, $cursor, $batchSize);
            $cursor += count($batchTexts);

            $response = $this->requestEmbeddingBatch(
                $client,
                $endpoint,
                $apiKey,
                $model,
                $batchTexts,
                $cursor,
                count($texts)
            );

            $rateLimitHeaders = $this->extractRateLimitHeaders($response);
            if ($rateLimitHeaders !== []) {
                $this->rateLimitService->updateFromHeaders($rateLimitHeaders, RateLimitService::ENDPOINT_EMBEDDINGS);
            }

            $payload = json_decode($response->getBody(), true);
            if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
                throw new Exception('Invalid embedding response payload');
            }

            foreach ($payload['data'] as $item) {
                $embedding = $item['embedding'] ?? null;
                if (!is_array($embedding)) {
                    throw new Exception('Embedding response missing vector data');
                }
                $result[] = array_map('floatval', $embedding);
            }
        }

        return $result;
    }

    /**
     * @param array<int,string> $batchTexts
     * @throws Exception
     */
    private function requestEmbeddingBatch(
        IClient $client,
        string $endpoint,
        string $apiKey,
        string $model,
        array $batchTexts,
        int $cursor,
        int $totalTexts
    ): IResponse {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_REQUEST_ATTEMPTS; $attempt++) {
            $this->waitForRateLimitCapacity($cursor, $totalTexts, count($batchTexts));

            try {
                return $client->post($endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'input' => array_values($batchTexts),
                    ],
                    'timeout' => 120,
                ]);
            } catch (Exception $e) {
                $lastException = $e;
                $status = $this->httpStatusFromException($e);
                $category = $status !== null
                    ? 'http_' . $status
                    : ($this->isResponseLessTransportFailure($e) ? 'transport' : 'internal');

                if ($attempt < self::MAX_REQUEST_ATTEMPTS && $this->isTransientRequestFailure($e, $status)) {
                    $delay = $this->retryDelaySeconds($e);
                    $this->logger->warning('Embedding request failed transiently, retrying', [
                        'attempt' => $attempt,
                        'next_attempt' => $attempt + 1,
                        'category' => $category,
                        'delay_seconds' => $delay,
                    ]);
                    ($this->sleeper)($delay);
                    continue;
                }

                $this->logger->error('Embedding request failed', [
                    'attempts' => $attempt,
                    'category' => $category,
                    'exception_class' => get_class($e),
                ]);
                throw $e;
            }
        }

        throw $lastException ?? new Exception('Embedding request failed');
    }

    private function isTransientRequestFailure(Exception $exception, ?int $status): bool {
        if ($status !== null) {
            return in_array($status, [408, 425, 429], true)
                || ($status >= 500 && $status <= 599);
        }

        return !$this->hasHttpResponse($exception)
            && $this->isResponseLessTransportFailure($exception);
    }

    private function isResponseLessTransportFailure(Throwable $exception): bool {
        $candidate = $exception;
        while ($candidate instanceof Throwable) {
            if (
                is_a($candidate, 'GuzzleHttp\\Exception\\ConnectException')
                || (
                    is_a($candidate, 'GuzzleHttp\\Exception\\RequestException')
                    && method_exists($candidate, 'hasResponse')
                    && !$candidate->hasResponse()
                )
            ) {
                return true;
            }

            $message = strtolower($candidate->getMessage());
            foreach ([
                'curl error',
                'connection refused',
                'connection reset',
                'could not resolve',
                'network is unreachable',
                'operation timed out',
                'timed out',
                'timeout',
                'ssl connect error',
            ] as $marker) {
                if (str_contains($message, $marker)) {
                    return true;
                }
            }

            $candidate = $candidate->getPrevious();
        }

        return false;
    }

    private function hasHttpResponse(Throwable $exception): bool {
        $candidate = $exception;
        while ($candidate instanceof Throwable) {
            if (
                method_exists($candidate, 'hasResponse')
                && method_exists($candidate, 'getResponse')
                && $candidate->hasResponse()
            ) {
                return true;
            }
            $candidate = $candidate->getPrevious();
        }

        return false;
    }

    private function httpStatusFromException(Throwable $exception): ?int {
        $candidate = $exception;
        while ($candidate instanceof Throwable) {
            if (
                method_exists($candidate, 'hasResponse')
                && method_exists($candidate, 'getResponse')
                && $candidate->hasResponse()
            ) {
                $response = $candidate->getResponse();
                if (is_object($response) && method_exists($response, 'getStatusCode')) {
                    $status = (int)$response->getStatusCode();
                    if ($status >= 100 && $status <= 599) {
                        return $status;
                    }
                }
            }
            $candidate = $candidate->getPrevious();
        }

        $status = (int)$exception->getCode();
        return $status >= 100 && $status <= 599 ? $status : null;
    }

    private function retryDelaySeconds(Throwable $exception): int {
        $retryAfter = $this->retryAfterHeader($exception);
        if ($retryAfter === null || trim($retryAfter) === '') {
            return self::DEFAULT_RETRY_DELAY_SECONDS;
        }

        $retryAfter = trim($retryAfter);
        if (is_numeric($retryAfter)) {
            return min(
                self::MAX_RETRY_DELAY_SECONDS,
                max(0, (int)ceil((float)$retryAfter))
            );
        }

        $retryAt = strtotime($retryAfter);
        if ($retryAt === false) {
            return self::DEFAULT_RETRY_DELAY_SECONDS;
        }

        return min(
            self::MAX_RETRY_DELAY_SECONDS,
            max(0, $retryAt - ($this->clock)())
        );
    }

    private function retryAfterHeader(Throwable $exception): ?string {
        $candidate = $exception;
        while ($candidate instanceof Throwable) {
            if (
                method_exists($candidate, 'hasResponse')
                && method_exists($candidate, 'getResponse')
                && $candidate->hasResponse()
            ) {
                $response = $candidate->getResponse();
                if (is_object($response) && method_exists($response, 'getHeaderLine')) {
                    $value = $response->getHeaderLine('Retry-After');
                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                }
                if (is_object($response) && method_exists($response, 'getHeader')) {
                    $value = $response->getHeader('Retry-After');
                    if (is_array($value)) {
                        $value = $value[0] ?? null;
                    }
                    if (is_scalar($value)) {
                        return (string)$value;
                    }
                }
            }
            $candidate = $candidate->getPrevious();
        }

        return null;
    }

    /**
     * Resolve the embedding model that is currently active.
     */
    public function getActiveModel(?string $modelOverride = null): string {
        $model = $modelOverride;
        if ($model === null || $model === '') {
            $ragConfig = $this->settingsService->getRagConfig();
            $model = $ragConfig['embedding_model'] ?? null;
        }
        if ($model === null || $model === '') {
            $settings = $this->settingsService->getSettings();
            $model = $settings->getDefaultModel();
        }

        return (string)$model;
    }
    private function resolveEndpoint(?string $custom, ?string $chatEndpoint): ?string {
        $endpoint = $custom;
        if ($endpoint === null || $endpoint === '') {
            $endpoint = $chatEndpoint;
        }
        if ($endpoint === null || $endpoint === '') {
            return null;
        }

        $normalized = rtrim($endpoint, '/');
        if (preg_match('#/v1/chat/completions$#', $normalized)) {
            return preg_replace('#/v1/chat/completions$#', '/v1/embeddings', $normalized) ?: null;
        }
        if (preg_match('#/chat/completions$#', $normalized)) {
            return preg_replace('#/chat/completions$#', '/embeddings', $normalized) ?: null;
        }
        if (preg_match('#/v1$#', $normalized)) {
            return $normalized . '/embeddings';
        }

        return $normalized;
    }

    private function waitForRateLimitCapacity(int $cursor, int $totalTexts, int $batchSize): void {
        if (!$this->rateLimitService->isEnabled()) {
            return;
        }

        $waited = 0;
        while (!$this->rateLimitService->canProcess(RateLimitService::ENDPOINT_EMBEDDINGS)) {
            if ($waited >= self::RATE_LIMIT_WAIT_TIMEOUT_SECONDS) {
                throw new Exception('Rate limit wait timeout exceeded for embedding batch');
            }

            $waitSeconds = max(
                self::RATE_LIMIT_POLL_SECONDS,
                min($this->rateLimitService->getSecondsUntilAvailable(RateLimitService::ENDPOINT_EMBEDDINGS), 10)
            );

            $this->logger->info('Embedding batch waiting for embedding endpoint rate limit capacity', [
                'waited_seconds' => $waited,
                'sleep_seconds' => $waitSeconds,
                'batch_cursor' => $cursor,
                'batch_size' => $batchSize,
                'total_texts' => $totalTexts,
            ]);

            ($this->sleeper)($waitSeconds);
            $waited += $waitSeconds;
        }

        // Track embedding consumption separately so observed embedding headers do not pollute chat queue state.
        $this->rateLimitService->recordUsage(RateLimitService::ENDPOINT_EMBEDDINGS);
    }

    /**
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
                    if (is_array($value)) {
                        $value = $value[0] ?? '';
                    }
                    $headers[$name] = is_numeric($value) ? (int)$value : $value;
                }
            } catch (Exception $e) {
                // Header not present, skip.
            }
        }

        if ($headers !== []) {
            $this->logger->debug('Extracted embedding rate limit headers', [
                'headers' => $headers,
            ]);
        }

        return $headers;
    }
}
