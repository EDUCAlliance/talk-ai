<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LLMClientTest extends TestCase {
	public function testListModelOptionsCombinesPrimaryAndSecondaryEndpoints(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setLlmModelsTimeout(25);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$primaryResponse = $this->jsonResponse([
			'data' => [
				['id' => 'model-a'],
				['id' => 'model-b'],
			],
		]);
		$secondaryResponse = $this->jsonResponse([
			'models' => [
				['id' => 'model-a'],
				'model-c',
			],
		]);

		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function (string $uri, array $options) use ($primaryResponse, $secondaryResponse): IResponse {
				$this->assertSame(25, $options['timeout']);
				if ($uri === 'https://primary.example.invalid/v1/models') {
					$this->assertSame('Bearer primary-key', $options['headers']['Authorization']);
					return $primaryResponse;
				}
				if ($uri === 'https://secondary.example.invalid/v1/models') {
					$this->assertSame('Bearer secondary-key', $options['headers']['Authorization']);
					return $secondaryResponse;
				}
				$this->fail('Unexpected model endpoint: ' . $uri);
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$options = $llmClient->listModelOptions();

		$this->assertSame([
			'primary:model-a',
			'primary:model-b',
			'secondary:model-a',
			'secondary:model-c',
		], array_column($options, 'id'));
		$this->assertSame('Secondary · model-a', $options[2]['label']);
	}

	public function testPrepareMessagesInsertsContinuedPlaceholderWhenHistoryStartsWithAssistant(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
				$captured = $options['json']['messages'] ?? [];
				return $this->jsonResponse([
					'model' => 'model-a',
					'choices' => [
						['message' => ['content' => 'ok']],
					],
				]);
			});

		$this->chatLlmClient($client)->sendChatCompletion('system', [
			['role' => 'assistant', 'content' => 'previous answer'],
			['role' => 'user', 'content' => 'follow up'],
		]);

		$this->assertSame('system', $captured[0]['role'] ?? null);
		$this->assertSame('user', $captured[1]['role'] ?? null);
		$this->assertSame('(continued)', $captured[1]['content'] ?? null);
		$this->assertSame('assistant', $captured[2]['role'] ?? null);
		$this->assertSame('previous answer', $captured[2]['content'] ?? null);
		$this->assertSame('user', $captured[3]['role'] ?? null);
		$this->assertSame('follow up', $captured[3]['content'] ?? null);
	}

	public function testPrepareMessagesReplacesEmptyUserContent(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
				$captured = $options['json']['messages'] ?? [];
				return $this->jsonResponse([
					'model' => 'model-a',
					'choices' => [
						['message' => ['content' => 'ok']],
					],
				]);
			});

		$this->chatLlmClient($client)->sendChatCompletion('system', [
			['role' => 'user', 'content' => '   '],
			['role' => 'assistant', 'content' => 'hello'],
		]);

		$this->assertSame('(continued)', $captured[1]['content'] ?? null);
		$this->assertSame('hello', $captured[2]['content'] ?? null);
	}

	public function testPrepareMessagesStripsNameFromToolResults(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
				$captured = $options['json']['messages'] ?? [];
				return $this->jsonResponse([
					'model' => 'model-a',
					'choices' => [
						['message' => ['content' => 'ok']],
					],
				]);
			});

		$this->chatLlmClient($client)->sendChatCompletion('system', [
			['role' => 'user', 'content' => 'hi'],
			[
				'role' => 'assistant',
				'content' => null,
				'tool_calls' => [[
					'id' => 'call_1',
					'type' => 'function',
					'function' => ['name' => 'search_test', 'arguments' => '{}'],
				]],
			],
			[
				'role' => 'tool',
				'tool_call_id' => 'call_1',
				'name' => 'search_test',
				'content' => 'found it',
			],
		]);

		$toolMessages = array_values(array_filter(
			$captured,
			static fn (array $message): bool => ($message['role'] ?? '') === 'tool'
		));
		$this->assertCount(1, $toolMessages);
		$this->assertArrayNotHasKey('name', $toolMessages[0]);
		$this->assertSame('call_1', $toolMessages[0]['tool_call_id'] ?? null);
		$this->assertSame('found it', $toolMessages[0]['content'] ?? null);
	}

	public function testSendChatCompletionIncludesProviderErrorBodyInLoggedException(): void {
		$logger = new RecordingLogger();
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'error' => [
					'message' => 'litellm.UnsupportedParamsError: temperature is not supported',
					'type' => 'invalid_request_error',
				],
			], 400));

		$llmClient = $this->chatLlmClient($client, $logger);

		try {
			$llmClient->sendChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				null,
				['_use_reasoning_parameters' => true]
			);
			$this->fail('Expected LLM request failure');
		} catch (\Exception $e) {
			$this->assertSame('Failed to get response from AI', $e->getMessage());
			$previous = $e->getPrevious();
			$this->assertNotNull($previous);
			$this->assertSame(400, $previous->getCode());
			$this->assertStringContainsString('LLM provider returned HTTP status 400', $previous->getMessage());
			$this->assertStringContainsString('litellm.UnsupportedParamsError: temperature is not supported', $previous->getMessage());
		}

		$this->assertNotEmpty($logger->errors);
		$this->assertSame(400, $logger->errors[0]['context']['status'] ?? null);
		$this->assertStringContainsString(
			'litellm.UnsupportedParamsError: temperature is not supported',
			(string)($logger->errors[0]['context']['response_body'] ?? '')
		);
	}

	public function testSendChatCompletionReadsErrorBodyFromThrownHttpException(): void {
		$logger = new RecordingLogger();
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willThrowException(new FakeProviderHttpException(
				$this->jsonResponse(['error' => ['message' => 'text content blocks must be non-empty']], 400),
				400
			));

		$llmClient = $this->chatLlmClient($client, $logger);

		try {
			$llmClient->sendChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				null,
				['_use_reasoning_parameters' => true]
			);
			$this->fail('Expected LLM request failure');
		} catch (\Exception $e) {
			$this->assertSame('Failed to get response from AI', $e->getMessage());
			$this->assertSame(400, $e->getPrevious()?->getCode());
			$this->assertStringContainsString(
				'text content blocks must be non-empty',
				$e->getPrevious()?->getMessage() ?? ''
			);
		}

		$this->assertSame(400, $logger->errors[0]['context']['status'] ?? null);
		$this->assertStringContainsString(
			'text content blocks must be non-empty',
			(string)($logger->errors[0]['context']['response_body'] ?? '')
		);
	}

	public function testSendChatCompletionRetriesFallbackOnTimeout(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setFallbackModel('secondary:model-b');
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$fallbackResponse = $this->jsonResponse([
			'model' => 'model-b',
			'choices' => [
				[
					'message' => ['content' => 'fallback answer'],
					'finish_reason' => 'stop',
				],
			],
		]);

		$calls = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls, $fallbackResponse): IResponse {
				$calls[] = [$uri, $options['json']['model'] ?? null, $options['headers']['Authorization'] ?? null];
				if (count($calls) === 1) {
					throw new \Exception('cURL error 28: Operation timed out');
				}
				return $fallbackResponse;
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$result = $llmClient->sendChatCompletion('system', [['role' => 'user', 'content' => 'hi']]);

		$this->assertSame('fallback answer', $result['content']);
		$this->assertSame('secondary:model-b', $result['model_reference']);
		$this->assertSame([
			['https://primary.example.invalid/v1/chat/completions', 'model-a', 'Bearer primary-key'],
			['https://secondary.example.invalid/v1/chat/completions', 'model-b', 'Bearer secondary-key'],
		], $calls);
	}

	public function testUnprefixedModelRoutesToSecondaryWhenOnlySecondaryHasModelInCache(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->once())
			->method('post')
			->with(
				'https://secondary.example.invalid/v1/chat/completions',
				$this->callback(function (array $options): bool {
					$this->assertSame('model-c', $options['json']['model'] ?? null);
					$this->assertSame('Bearer secondary-key', $options['headers']['Authorization'] ?? null);
					return true;
				})
			)
			->willReturn($this->jsonResponse([
				'model' => 'model-c',
				'choices' => [
					['message' => ['content' => 'secondary answer']],
				],
			]));

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			$this->cachedModelOptionsConfig($settings, [
				['id' => 'primary:model-a', 'label' => 'Primary · model-a', 'model' => 'model-a', 'endpoint' => 'primary'],
				['id' => 'secondary:model-c', 'label' => 'Secondary · model-c', 'model' => 'model-c', 'endpoint' => 'secondary'],
			])
		);

		$result = $llmClient->sendChatCompletion('system', [['role' => 'user', 'content' => 'hi']], 'model-c');

		$this->assertSame('secondary answer', $result['content']);
		$this->assertSame('secondary:model-c', $result['model_reference']);
	}

	public function testUnprefixedModelPrefersPrimaryWhenBothEndpointsHaveModel(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->once())
			->method('post')
			->with(
				'https://primary.example.invalid/v1/chat/completions',
				$this->callback(function (array $options): bool {
					$this->assertSame('model-a', $options['json']['model'] ?? null);
					$this->assertSame('Bearer primary-key', $options['headers']['Authorization'] ?? null);
					return true;
				})
			)
			->willReturn($this->jsonResponse([
				'model' => 'model-a',
				'choices' => [
					['message' => ['content' => 'primary answer']],
				],
			]));

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			$this->cachedModelOptionsConfig($settings, [
				['id' => 'primary:model-a', 'label' => 'Primary · model-a', 'model' => 'model-a', 'endpoint' => 'primary'],
				['id' => 'secondary:model-a', 'label' => 'Secondary · model-a', 'model' => 'model-a', 'endpoint' => 'secondary'],
			])
		);

		$result = $llmClient->sendChatCompletion('system', [['role' => 'user', 'content' => 'hi']], 'model-a');

		$this->assertSame('primary answer', $result['content']);
		$this->assertSame('primary:model-a', $result['model_reference']);
	}

	public function testSendChatCompletionDoesNotFallbackOnFourHundredErrors(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setFallbackModel('secondary:model-b');

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willThrowException(new \Exception('Bad Request', 400));

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		try {
			$llmClient->sendChatCompletion('system', [['role' => 'user', 'content' => 'hi']]);
			$this->fail('Expected LLM request failure');
		} catch (\Exception $e) {
			$this->assertSame('Failed to get response from AI', $e->getMessage());
			$this->assertSame('Bad Request', $e->getPrevious()?->getMessage());
		}
	}

	public function testSendChatCompletionPublicExceptionDoesNotExposeEndpointUrl(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://secret.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willThrowException(new \Exception('cURL error 7: Failed to connect to https://secret.example.invalid/v1/chat/completions'));

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		try {
			$llmClient->sendChatCompletion('system', [['role' => 'user', 'content' => 'hi']]);
			$this->fail('Expected LLM request failure');
		} catch (\Exception $e) {
			$this->assertSame('Failed to get response from AI', $e->getMessage());
			$this->assertStringNotContainsString('https://secret.example.invalid', $e->getMessage());
			$this->assertStringContainsString('https://secret.example.invalid', $e->getPrevious()?->getMessage() ?? '');
		}
	}

	public function testSendChatCompletionSanitizesInvalidUtf8BeforeJsonRequest(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$invalidUtf8 = substr(str_repeat('a', 3999) . '💡', 0, 4000);
		$this->assertFalse(mb_check_encoding($invalidUtf8, 'UTF-8'));

		$response = $this->jsonResponse([
			'model' => 'model-a',
			'choices' => [
				['message' => ['content' => 'sanitized answer']],
			],
		]);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use ($response): IResponse {
				$this->assertSame('https://primary.example.invalid/v1/chat/completions', $uri);
				$this->assertNotFalse(json_encode($options['json']));
				$this->assertTrue(mb_check_encoding($options['json']['messages'][1]['content'] ?? '', 'UTF-8'));
				$this->assertTrue(mb_check_encoding($options['json']['tools'][0]['function']['description'] ?? '', 'UTF-8'));
				return $response;
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$result = $llmClient->sendChatCompletion(
			'system',
			[['role' => 'user', 'content' => $invalidUtf8]],
			null,
			[
				'tools' => [[
					'type' => 'function',
					'function' => [
						'name' => 'search_test',
						'description' => $invalidUtf8,
						'parameters' => ['type' => 'object'],
					],
				]],
			]
		);

		$this->assertSame('sanitized answer', $result['content']);
	}

	public function testStreamChatCompletionRequestsAndReturnsUsage(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setLlmStreamTimeout(240);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with(
				'https://primary.example.invalid/v1/chat/completions',
				$this->callback(function (array $options): bool {
					$this->assertTrue($options['json']['stream'] ?? false);
					$this->assertSame(['include_usage' => true], $options['json']['stream_options'] ?? null);
					return true;
				})
			)
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"content\":\"hel\"}}]}\n\n"
				. "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n"
				. "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":4,\"completion_tokens\":2,\"total_tokens\":6}}\n\n"
				. "data: [DONE]\n\n"
			));

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$result = $llmClient->streamChatCompletion('system', [['role' => 'user', 'content' => 'hi']], static function (): void {});

		$this->assertSame('hello', $result['content']);
		$this->assertSame(['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6], $result['usage']);
	}

	public function testSendChatCompletionKeepsTemperatureForOtherModels(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:up/minimax-m2-5');
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with(
				'https://primary.example.invalid/v1/chat/completions',
				$this->callback(function (array $options): bool {
					$this->assertSame('up/minimax-m2-5', $options['json']['model'] ?? null);
					$this->assertSame(0.2, $options['json']['temperature'] ?? null);
					$this->assertSame(1000, $options['json']['max_tokens'] ?? null);
					$this->assertArrayNotHasKey('max_completion_tokens', $options['json']);
					return true;
				})
			)
			->willReturn($this->jsonResponse([
				'model' => 'up/minimax-m2-5',
				'choices' => [
					['message' => ['content' => 'ok']],
				],
			]));

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$result = $llmClient->sendChatCompletion(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			null,
			['temperature' => 0.2]
		);

		$this->assertSame('ok', $result['content']);
	}

	public function testSendChatCompletionRetriesWithReasoningParametersWhenClassicRejected(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$calls = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls): IResponse {
				$this->assertSame('https://primary.example.invalid/v1/chat/completions', $uri);
				$calls[] = $options['json'];
				if (count($calls) === 1) {
					return $this->jsonResponse(['error' => 'unsupported parameter'], 400);
				}
				return $this->jsonResponse([
					'model' => 'model-a',
					'choices' => [
						['message' => ['content' => 'ok']],
					],
				]);
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$result = $llmClient->sendChatCompletion(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			null,
			['temperature' => 0.2, 'max_tokens' => 800]
		);

		$this->assertSame('ok', $result['content']);
		$this->assertSame(0.2, $calls[0]['temperature'] ?? null);
		$this->assertSame(800, $calls[0]['max_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_completion_tokens', $calls[0]);
		$this->assertSame(800, $calls[1]['max_completion_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_tokens', $calls[1]);
		$this->assertArrayNotHasKey('temperature', $calls[1]);
	}

	public function testStreamChatCompletionRetriesWithReasoningParametersWhenClassicRejected(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setLlmStreamTimeout(240);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$calls = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls): IResponse {
				$this->assertSame('https://primary.example.invalid/v1/chat/completions', $uri);
				$calls[] = $options['json'];
				if (count($calls) === 1) {
					return $this->rawResponse('{"error":"unsupported parameter"}', 400);
				}
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\ndata: [DONE]\n\n");
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		// Caller-supplied stream_options makes the usage-option retry ineligible,
		// so this isolates the reasoning-parameter remap as the second POST.
		$result = $llmClient->streamChatCompletion(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			static function (): void {},
			null,
			[
				'temperature' => 0.2,
				'max_tokens' => 800,
				'stream_options' => ['include_usage' => true],
			]
		);

		$this->assertSame('ok', $result['content']);
		$this->assertSame(0.2, $calls[0]['temperature'] ?? null);
		$this->assertSame(800, $calls[0]['max_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_completion_tokens', $calls[0]);
		$this->assertSame(800, $calls[1]['max_completion_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_tokens', $calls[1]);
		$this->assertArrayNotHasKey('temperature', $calls[1]);
	}

	public function testStreamChatCompletionRetriesWithoutUsageOptionsWhenRejected(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setLlmStreamTimeout(240);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$calls = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls): IResponse {
				$this->assertSame('https://primary.example.invalid/v1/chat/completions', $uri);
				$calls[] = $options['json'];
				if (count($calls) === 1) {
					return $this->rawResponse('{"error":"unknown stream_options"}', 400);
				}
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"fallback\"}}]}\n\ndata: [DONE]\n\n");
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$result = $llmClient->streamChatCompletion('system', [['role' => 'user', 'content' => 'hi']], static function (): void {});

		$this->assertSame('fallback', $result['content']);
		$this->assertSame(['include_usage' => true], $calls[0]['stream_options'] ?? null);
		$this->assertArrayNotHasKey('stream_options', $calls[1]);
		$this->assertSame(0.7, $calls[0]['temperature'] ?? null);
		$this->assertSame(1000, $calls[0]['max_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_completion_tokens', $calls[0]);
		$this->assertSame(0.7, $calls[1]['temperature'] ?? null);
		$this->assertSame(1000, $calls[1]['max_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_completion_tokens', $calls[1]);
	}

	public function testStreamChatCompletionRetriesUsageThenReasoningParametersWhenBothRejected(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setLlmStreamTimeout(240);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$calls = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(3))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls): IResponse {
				$this->assertSame('https://primary.example.invalid/v1/chat/completions', $uri);
				$calls[] = $options['json'];
				if (count($calls) < 3) {
					return $this->rawResponse('{"error":"unsupported parameter"}', 400);
				}
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\ndata: [DONE]\n\n");
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$result = $llmClient->streamChatCompletion(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			static function (): void {},
			null,
			['temperature' => 0.2, 'max_tokens' => 800]
		);

		$this->assertSame('ok', $result['content']);
		$this->assertSame(['include_usage' => true], $calls[0]['stream_options'] ?? null);
		$this->assertSame(0.2, $calls[0]['temperature'] ?? null);
		$this->assertSame(800, $calls[0]['max_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_completion_tokens', $calls[0]);
		$this->assertArrayNotHasKey('stream_options', $calls[1]);
		$this->assertSame(0.2, $calls[1]['temperature'] ?? null);
		$this->assertSame(800, $calls[1]['max_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_completion_tokens', $calls[1]);
		$this->assertArrayNotHasKey('stream_options', $calls[2]);
		$this->assertSame(800, $calls[2]['max_completion_tokens'] ?? null);
		$this->assertArrayNotHasKey('max_tokens', $calls[2]);
		$this->assertArrayNotHasKey('temperature', $calls[2]);
	}

	public function testStreamChatCompletionRetriesFallbackOnServerErrorBeforeFirstChunk(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setFallbackModel('secondary:model-b');
		$settings->setLlmStreamTimeout(240);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$calls = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls): IResponse {
				$calls[] = [$uri, $options['json']['model'] ?? null, $options['headers']['Authorization'] ?? null];
				if (count($calls) === 1) {
					return $this->rawResponse('{"error":"temporary provider failure"}', 500);
				}
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"fallback answer\"}}]}\n\ndata: [DONE]\n\n");
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$result = $llmClient->streamChatCompletion('system', [['role' => 'user', 'content' => 'hi']], static function (): void {});

		$this->assertSame('fallback answer', $result['content']);
		$this->assertSame('secondary:model-b', $result['model_reference']);
		$this->assertSame([
			['https://primary.example.invalid/v1/chat/completions', 'model-a', 'Bearer primary-key'],
			['https://secondary.example.invalid/v1/chat/completions', 'model-b', 'Bearer secondary-key'],
		], $calls);
	}

	private function jsonResponse(array $body, int $status = 200): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode($body) ?: '');
		$response->method('getHeader')->willReturn('');
		$response->method('getStatusCode')->willReturn($status);

		return $response;
	}

	private function rawResponse(string $body, int $status = 200): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturn('');
		$response->method('getStatusCode')->willReturn($status);

		return $response;
	}

	/**
	 * @param array<int,array{id:string,label:string,model:string,endpoint:string}> $options
	 */
	private function cachedModelOptionsConfig(Settings $settings, array $options): IConfig {
		$fingerprint = sha1(implode('|', [
			trim((string)$settings->getApiProvider()),
			rtrim(trim((string)$settings->getApiEndpoint()), '/'),
			rtrim(trim((string)$settings->getSecondaryApiEndpoint()), '/'),
		]));

		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('getAppValue')
			->with(Application::APP_ID, 'llm_model_options_cache', '')
			->willReturn(json_encode([
				'fingerprint' => $fingerprint,
				'expires_at' => time() + 60,
				'options' => $options,
			]) ?: '');
		$config->expects($this->never())->method('setAppValue');

		return $config;
	}

	private function chatLlmClient(IClient $client, ?LoggerInterface $logger = null): LLMClient {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		return new LLMClient(
			$this->clientService($client),
			$settingsService,
			$logger ?? $this->logger()
		);
	}

	private function clientService(IClient $client): IClientService {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return $clientService;
	}

	private function logger(): LoggerInterface {
		return new class implements LoggerInterface {
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
	}
}

class RecordingLogger implements LoggerInterface {
	/** @var array<int,array{message:string,context:array<string,mixed>}> */
	public array $errors = [];

	public function emergency($message, array $context = []): void {}
	public function alert($message, array $context = []): void {}
	public function critical($message, array $context = []): void {}
	public function error($message, array $context = []): void {
		$this->errors[] = ['message' => (string)$message, 'context' => $context];
	}
	public function warning($message, array $context = []): void {}
	public function notice($message, array $context = []): void {}
	public function info($message, array $context = []): void {}
	public function debug($message, array $context = []): void {}
	public function log($level, $message, array $context = []): void {}
}

class FakeProviderHttpException extends \Exception {
	public function __construct(
		private object $response,
		int $code = 400
	) {
		parent::__construct('Client error: POST resulted in a ' . $code . ' response', $code);
	}

	public function hasResponse(): bool {
		return true;
	}

	public function getResponse(): object {
		return $this->response;
	}
}
