<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Exception\UnexpectedContentEncodingException;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ProviderAttemptBudget;
use OCA\EducAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LLMContentEncodingTest extends TestCase {
	#[DataProvider('rejectedProviderResponses')]
	public function testEncodedProviderResponseIsTerminalWithoutModelFallbackOrStreamRetry(bool $stream, bool $wrappedHeaderFailure): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getHeader')->with('Content-Encoding')->willReturn('br');
		$response->expects($this->never())->method('getBody');
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->once())->method('post')->willReturnCallback(function (string $url, array $options) use ($stream, $wrappedHeaderFailure, $response): IResponse {
			$this->assertSame('https://primary.example.invalid/v1/chat/completions', $url);
			$this->assertSame('identity', $options['headers']['Accept-Encoding']);
			$this->assertFalse($options['decode_content']);
			$this->assertSame($stream, $options['json']['stream'] ?? false);
			if ($wrappedHeaderFailure) {
				try {
					$options['on_headers'](new class {
						public function getHeaderLine(string $name): string {
							return $name === 'Content-Encoding' ? 'br' : '';
						}
					});
				} catch (UnexpectedContentEncodingException $e) {
					// Simulate a transport's exception wrapper; its text would normally
					// be eligible for retry/fallback if the typed cause were lost.
					throw new \RuntimeException('cURL error 28: Operation timed out', 0, $e);
				}
				$this->fail('The transport header callback must reject Brotli.');
			}
			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->once())->method('newClient')->willReturn($client);

		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setFallbackModel('secondary:model-b');
		$settings->setLlmChatTimeout(90);
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-fixture-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-fixture-key');
		$settingsService->method('getMaxOutputTokens')->willReturn(4096);
		$settingsService->method('normalizePositiveInteger')->willReturn(90);
		$llmClient = new LLMClient($clientService, $settingsService, $this->createMock(LoggerInterface::class));
		$budget = new ProviderAttemptBudget();
		$chunks = [];
		$messages = [['role' => 'user', 'content' => 'Return one sentence.']];
		$options = ['provider_attempt_budget' => $budget];

		try {
			if ($stream) {
				$llmClient->streamAgentTurn('system', $messages, [], static function (array $chunk) use (&$chunks): void {
					$chunks[] = $chunk;
				}, null, $options);
			} else {
				$llmClient->sendAgentTurn('system', $messages, [], null, $options);
			}
			$this->fail('An encoded response must fail closed, not trigger a compatibility retry or fallback.');
		} catch (UnexpectedContentEncodingException $e) {
			$this->assertStringContainsString('uncompressed', $e->getMessage());
		}

		$this->assertSame(1, $budget->getConsumed());
		$this->assertSame([], $chunks);
	}

	public static function rejectedProviderResponses(): iterable {
		yield 'sync response fallback guard' => [false, false];
		yield 'stream response fallback guard' => [true, false];
		yield 'sync wrapped headers guard' => [false, true];
		yield 'stream wrapped headers guard' => [true, true];
	}
}
