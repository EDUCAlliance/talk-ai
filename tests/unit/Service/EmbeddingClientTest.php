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
}
