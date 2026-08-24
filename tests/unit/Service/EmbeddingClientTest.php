<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmbeddingClientTest extends TestCase {
    public function testEmbedTextsTracksRateLimitStateOnEmbeddingEndpoint(): void {
        $settings = new Settings();
        $settings->setApiEndpoint('https://chat-ai.academiccloud.de/v1/chat/completions');

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')
            ->willReturn($settings);
        $settingsService->method('getRagConfig')
            ->willReturn([
                'embedding_api_endpoint' => 'https://chat-ai.academiccloud.de/v1/embeddings',
                'embedding_api_key' => 'embedding-key',
                'embedding_model' => 'qwen3-embedding-4b',
            ]);

        $rateLimitService = $this->createMock(RateLimitService::class);
        $rateLimitService->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);
        $rateLimitService->expects($this->once())
            ->method('canProcess')
            ->with(RateLimitService::ENDPOINT_EMBEDDINGS)
            ->willReturn(true);
        $rateLimitService->expects($this->once())
            ->method('recordUsage')
            ->with(RateLimitService::ENDPOINT_EMBEDDINGS);
        $rateLimitService->expects($this->once())
            ->method('updateFromHeaders')
            ->with([
                'x-ratelimit-limit-minute' => 100,
                'x-ratelimit-remaining-minute' => 99,
                'ratelimit-limit' => 100,
                'ratelimit-remaining' => 99,
                'ratelimit-reset' => 35,
            ], RateLimitService::ENDPOINT_EMBEDDINGS);

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')
            ->willReturn(json_encode([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                ],
            ]) ?: '');
        $response->method('getHeader')
            ->willReturnCallback(static fn (string $header): string => match ($header) {
                'x-ratelimit-limit-minute' => '100',
                'x-ratelimit-remaining-minute' => '99',
                'ratelimit-limit' => '100',
                'ratelimit-remaining' => '99',
                'ratelimit-reset' => '35',
                default => '',
            });

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                'https://chat-ai.academiccloud.de/v1/embeddings',
                $this->callback(static function (array $options): bool {
                    return ($options['json']['model'] ?? null) === 'qwen3-embedding-4b'
                        && ($options['json']['input'] ?? null) === ['hello world'];
                })
            )
            ->willReturn($response);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')
            ->willReturn($client);

        $logger = new class implements LoggerInterface {
            public function emergency($message, array $context = []): void {}
            public function alert($message, array $context = []): void {}
            public function critical($message, array $context = []): void {}
            public function error($message, array $context = []): void {}
            public function warning($message, array $context = []): void {}
            public function notice($message, array $context = []): void {}
            public function info($message, array $context = []): void {}
            public function debug($message, array $context = []): void {}
            public function log($level, $message, array $context = []): void {}
        };

        $embeddingClient = new EmbeddingClient(
            $clientService,
            $settingsService,
            $rateLimitService,
            $logger
        );

        $result = $embeddingClient->embedTexts(['hello world']);

        $this->assertCount(1, $result);
        $this->assertSame([0.1, 0.2, 0.3], $result[0]);
    }

    #[DataProvider('transientHttpStatuses')]
    public function testRetriesTransientHttpStatusOnce(
        int $status,
        string $retryAfter,
        int $expectedDelay,
        ?int $clockNow = null
    ): void {
        $failureResponse = $this->response('', $status, ['Retry-After' => $retryAfter]);
        $failure = $this->httpException($status, $failureResponse);
        $success = $this->embeddingResponse();
        $requestAttempt = 0;
        $client = $this->createMock(IClient::class);
        $client->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(static function () use (&$requestAttempt, $failure, $success): IResponse {
                if ($requestAttempt++ === 0) {
                    throw $failure;
                }
                return $success;
            });
        $rateLimitService = $this->createMock(RateLimitService::class);
        $rateLimitService->expects($this->exactly(2))->method('isEnabled')->willReturn(false);
        $delays = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Embedding request failed transiently, retrying',
                $this->callback(static function (array $context) use ($status, $expectedDelay): bool {
                    return $context === [
                        'attempt' => 1,
                        'next_attempt' => 2,
                        'category' => 'http_' . $status,
                        'delay_seconds' => $expectedDelay,
                    ];
                })
            );
        $logger->expects($this->never())->method('error');

        $embeddingClient = $this->createEmbeddingClient(
            $client,
            $rateLimitService,
            $logger,
            static function (int $seconds) use (&$delays): void {
                $delays[] = $seconds;
            },
            $clockNow === null ? null : static fn (): int => $clockNow
        );

        $this->assertSame([[0.1, 0.2, 0.3]], $embeddingClient->embedTexts(['probe']));
        $this->assertSame([$expectedDelay], $delays);
    }

    /** @return array<string,array{int,string,int,?int}> */
    public static function transientHttpStatuses(): array {
        return [
            'request timeout' => [408, '', 1, null],
            'too early' => [425, '', 1, null],
            'rate limit with capped numeric retry-after' => [429, '9', 2, null],
            'rate limit with capped HTTP-date retry-after' => [429, 'Thu, 01 Jan 1970 00:16:45 GMT', 2, 1000],
            'internal server error' => [500, '', 1, null],
            'service unavailable' => [503, '', 1, null],
        ];
    }

    public function testRetriesResponseLessTransportFailureOnce(): void {
        $failure = new \RuntimeException('cURL error 7: Connection refused');
        $success = $this->embeddingResponse();
        $requestAttempt = 0;
        $client = $this->createMock(IClient::class);
        $client->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(static function () use (&$requestAttempt, $failure, $success): IResponse {
                if ($requestAttempt++ === 0) {
                    throw $failure;
                }
                return $success;
            });
        $rateLimitService = $this->createMock(RateLimitService::class);
        $rateLimitService->expects($this->exactly(2))->method('isEnabled')->willReturn(false);
        $delays = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Embedding request failed transiently, retrying',
                $this->callback(static fn (array $context): bool => ($context['category'] ?? null) === 'transport')
            );

        $embeddingClient = $this->createEmbeddingClient(
            $client,
            $rateLimitService,
            $logger,
            static function (int $seconds) use (&$delays): void {
                $delays[] = $seconds;
            }
        );

        $this->assertSame([[0.1, 0.2, 0.3]], $embeddingClient->embedTexts(['probe']));
        $this->assertSame([1], $delays);
    }

    public function testOrdinaryClientErrorIsNotRetriedAndOriginalExceptionIsPreserved(): void {
        $failure = $this->httpException(400, $this->response('', 400));
        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('post')->willThrowException($failure);
        $rateLimitService = $this->createMock(RateLimitService::class);
        $rateLimitService->expects($this->once())->method('isEnabled')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->once())
            ->method('error')
            ->with('Embedding request failed', [
                'attempts' => 1,
                'category' => 'http_400',
                'exception_class' => get_class($failure),
            ]);

        try {
            $this->createEmbeddingClient($client, $rateLimitService, $logger)->embedTexts(['probe']);
            $this->fail('Expected the original provider exception');
        } catch (\Throwable $caught) {
            $this->assertSame($failure, $caught);
        }
    }

    public function testExhaustedTransientRetryThrowsLastExceptionAndLogsOnlySafeMetadata(): void {
        $first = $this->httpException(500, $this->response('sensitive-response-body', 500));
        $last = $this->httpException(503, $this->response('another-sensitive-body', 503));
        $requestAttempt = 0;
        $client = $this->createMock(IClient::class);
        $client->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(static function () use (&$requestAttempt, $first, $last): never {
                if ($requestAttempt++ === 0) {
                    throw $first;
                }
                throw $last;
            });
        $rateLimitService = $this->createMock(RateLimitService::class);
        $rateLimitService->expects($this->exactly(2))->method('isEnabled')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $logger->expects($this->once())
            ->method('error')
            ->with('Embedding request failed', [
                'attempts' => 2,
                'category' => 'http_503',
                'exception_class' => get_class($last),
            ]);

        try {
            $this->createEmbeddingClient(
                $client,
                $rateLimitService,
                $logger,
                static function (int $seconds): void {}
            )->embedTexts(['probe']);
            $this->fail('Expected the final provider exception');
        } catch (\Throwable $caught) {
            $this->assertSame($last, $caught);
        }
    }

    private function createEmbeddingClient(
        IClient $client,
        RateLimitService $rateLimitService,
        LoggerInterface $logger,
        ?callable $sleeper = null,
        ?callable $clock = null
    ): EmbeddingClient {
        $settings = new Settings();
        $settings->setApiEndpoint('https://chat.example.test/v1/chat/completions');
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')->willReturn($settings);
        $settingsService->method('getRagConfig')->willReturn([
            'embedding_api_endpoint' => 'https://embedding.example.test/v1/embeddings',
            'embedding_api_key' => 'secret-key',
            'embedding_model' => 'embedding-model',
        ]);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return new EmbeddingClient(
            $clientService,
            $settingsService,
            $rateLimitService,
            $logger,
            $sleeper,
            $clock
        );
    }

    private function embeddingResponse(): IResponse {
        return $this->response(json_encode([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
        ], JSON_THROW_ON_ERROR), 200);
    }

    /** @param array<string,string> $headers */
    private function response(string $body, int $status, array $headers = []): IResponse {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($body);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getHeader')
            ->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');
        return $response;
    }

    private function httpException(int $status, IResponse $response): \Exception {
        return new class($status, $response) extends \Exception {
            public function __construct(int $status, private IResponse $response) {
                parent::__construct('Provider request failed', $status);
            }

            public function hasResponse(): bool {
                return true;
            }

            public function getResponse(): IResponse {
                return $this->response;
            }
        };
    }
}
