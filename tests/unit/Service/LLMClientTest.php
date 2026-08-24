<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Exception\AgentRunInterruptedException;
use OCA\EducAI\Exception\IncompleteProviderStreamException;
use OCA\EducAI\Exception\ProviderAttemptBudgetExceededException;
use OCA\EducAI\Exception\ProviderContractException;
use OCA\EducAI\Service\AgentRunControl;
use OCA\EducAI\Service\AgentTurn;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ProviderAttemptBudget;
use OCA\EducAI\Service\ProviderResponseNormalizer;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\TraceService;
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

		$events = [];
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		$options = $llmClient->listModelOptions();

		$this->assertSame([
			'primary:model-a',
			'primary:model-b',
			'secondary:model-a',
			'secondary:model-c',
		], array_column($options, 'id'));
		$this->assertSame('Secondary · model-a', $options[2]['label']);
		$this->assertSame([], $events);
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
						['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
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
						['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
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
						['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
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

	public function testPrepareMessagesDropsEmptyAssistantWithoutToolCalls(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
				$captured = $options['json']['messages'] ?? [];
				return $this->jsonResponse([
					'model' => 'model-a',
					'choices' => [
						['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
					],
				]);
			});

		$this->chatLlmClient($client)->sendChatCompletion('system', [
			['role' => 'user', 'content' => 'hi'],
			['role' => 'assistant', 'content' => ''],
			['role' => 'assistant', 'content' => '   '],
			['role' => 'user', 'content' => 'follow up'],
		]);

		$this->assertSame(['system', 'user'], array_column($captured, 'role'));
		$this->assertSame("hi\n\nfollow up", $captured[1]['content'] ?? null);
	}

	public function testPrepareMessagesDropsOrphanToolResults(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
				$captured = $options['json']['messages'] ?? [];
				return $this->jsonResponse([
					'model' => 'model-a',
					'choices' => [
						['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
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
			[
				'role' => 'tool',
				'tool_call_id' => 'call_missing',
				'name' => 'search_test',
				'content' => 'orphan',
			],
			['role' => 'user', 'content' => 'follow up'],
		]);

		$roles = array_column($captured, 'role');
		$this->assertSame(['system', 'user', 'assistant', 'tool', 'user'], $roles);
		$toolMessages = array_values(array_filter(
			$captured,
			static fn (array $message): bool => ($message['role'] ?? '') === 'tool'
		));
		$this->assertCount(1, $toolMessages);
		$this->assertSame('call_1', $toolMessages[0]['tool_call_id'] ?? null);
		$this->assertSame('found it', $toolMessages[0]['content'] ?? null);
		$this->assertArrayNotHasKey('name', $toolMessages[0]);
	}

	public function testBuildPayloadCoercesEmptyToolPropertiesToObject(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
				$captured = $options['json'] ?? [];
				return $this->jsonResponse([
					'model' => 'model-a',
					'choices' => [
						['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
					],
				]);
			});

		$this->chatLlmClient($client)->sendChatCompletion(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			null,
			[
				'tools' => [[
					'type' => 'function',
					'function' => [
						'name' => 'noop_tool',
						'description' => 'No args',
						'parameters' => [
							'type' => 'object',
							'properties' => [],
						],
					],
				]],
			]
		);

		$properties = $captured['tools'][0]['function']['parameters']['properties'] ?? null;
		$this->assertEquals(new \stdClass(), $properties);
		$this->assertSame('{}', json_encode($properties));
		$this->assertStringNotContainsString('"properties":[]', json_encode($captured['tools']) ?: '');
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
					['message' => ['content' => 'secondary answer'], 'finish_reason' => 'stop'],
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
					['message' => ['content' => 'primary answer'], 'finish_reason' => 'stop'],
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
				['message' => ['content' => 'sanitized answer'], 'finish_reason' => 'stop'],
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
				. "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"},\"finish_reason\":\"stop\"}]}\n\n"
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
					['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
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
						['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
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
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"ok\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n");
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
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"fallback\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n");
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
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"ok\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n");
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

	public function testInvalidModelHttpErrorsDoNotTriggerStreamingCompatibilityRetries(): void {
		foreach ([400, 422] as $status) {
			$settings = new Settings();
			$settings->setApiProvider('custom');
			$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
			$settings->setDefaultModel('primary:stale-model');
			$settings->setLlmStreamTimeout(240);

			$settingsService = $this->createMock(SettingsService::class);
			$settingsService->method('getSettings')->willReturn($settings);
			$settingsService->method('getApiKey')->willReturn('primary-key');
			$settingsService->method('normalizePositiveInteger')
				->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

			$client = $this->createMock(IClient::class);
			$client->expects($this->once())
				->method('post')
				->willReturn($this->rawResponse('{"error":{"message":"Invalid model name passed in model=stale-model"}}', $status));

			$events = [];
			$budget = new ProviderAttemptBudget(4);
			$llmClient = new LLMClient(
				$this->clientService($client),
				$settingsService,
				$this->logger(),
				null,
				$this->recordingTraceService($events),
			);

			try {
				$llmClient->streamChatCompletion(
					'system',
					[['role' => 'user', 'content' => 'hi']],
					static function (): void {},
					null,
					['provider_attempt_budget' => $budget, 'trace_run_id' => 71],
				);
				$this->fail('Expected the invalid model request to fail');
			} catch (\Exception $e) {
				$this->assertSame('Failed to stream response from AI', $e->getMessage());
				$this->assertSame($status, $e->getPrevious()?->getCode());
			}

			$this->assertSame(1, $budget->getConsumed());
			$attemptEvents = array_values(array_filter(
				$events,
				static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
			));
			$this->assertCount(1, $attemptEvents);
			$this->assertSame('initial', $attemptEvents[0]['event']['payload']['reason']);
			$this->assertSame('error', $attemptEvents[0]['event']['status']);
			$this->assertSame($status, $attemptEvents[0]['event']['payload']['http_status']);
		}
	}

	public function testStreamChatCompletionRetriesSelectedServerErrorSynchronouslyBeforeFallback(): void {
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
		$client->expects($this->exactly(3))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls): IResponse {
				$calls[] = [
					'uri' => $uri,
					'model' => $options['json']['model'] ?? null,
					'authorization' => $options['headers']['Authorization'] ?? null,
					'streaming' => !empty($options['stream']),
				];
				if (count($calls) === 1) {
					return $this->rawResponse('{"error":"temporary provider failure"}', 500);
				}
				if (count($calls) === 2) {
					return $this->jsonResponse(['error' => 'temporary provider failure'], 503);
				}
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"fallback answer\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n");
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
			[
				'uri' => 'https://primary.example.invalid/v1/chat/completions',
				'model' => 'model-a',
				'authorization' => 'Bearer primary-key',
				'streaming' => true,
			],
			[
				'uri' => 'https://primary.example.invalid/v1/chat/completions',
				'model' => 'model-a',
				'authorization' => 'Bearer primary-key',
				'streaming' => false,
			],
			[
				'uri' => 'https://secondary.example.invalid/v1/chat/completions',
				'model' => 'model-b',
				'authorization' => 'Bearer secondary-key',
				'streaming' => true,
			],
		], $calls);
	}

	public function testSelectedStreamServerErrorRetriesSynchronouslyWithBudgetAndTrace(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setFallbackModel('secondary:model-b');
		$settings->setLlmStreamTimeout(240);
		$settings->setLlmChatTimeout(90);

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
				$calls[] = [
					'uri' => $uri,
					'payload_stream' => $options['json']['stream'] ?? null,
					'request_stream' => $options['stream'] ?? null,
				];
				if (count($calls) === 1) {
					return $this->rawResponse('{"error":"temporary provider failure"}', 500);
				}

				return $this->jsonResponse([
					'model' => 'model-a',
					'choices' => [[
						'message' => ['content' => 'selected sync answer'],
						'finish_reason' => 'stop',
					]],
				]);
			});

		$events = [];
		$budget = new ProviderAttemptBudget(3);
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		$result = $llmClient->streamChatCompletion(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			static function (): void {},
			null,
			['provider_attempt_budget' => $budget, 'trace_run_id' => 72],
		);

		$this->assertSame('selected sync answer', $result['content']);
		$this->assertSame('primary:model-a', $result['model_reference']);
		$this->assertSame([
			[
				'uri' => 'https://primary.example.invalid/v1/chat/completions',
				'payload_stream' => true,
				'request_stream' => true,
			],
			[
				'uri' => 'https://primary.example.invalid/v1/chat/completions',
				'payload_stream' => null,
				'request_stream' => null,
			],
		], $calls);
		$this->assertSame(2, $budget->getConsumed());

		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(2, $attemptEvents);
		$this->assertSame(['initial', 'stream_to_sync_retry'], array_map(
			static fn (array $event): string => $event['event']['payload']['reason'],
			$attemptEvents
		));
		$this->assertSame(['error', 'ok'], array_map(
			static fn (array $event): string => $event['event']['status'],
			$attemptEvents
		));
		$this->assertSame([true, false], array_map(
			static fn (array $event): bool => $event['event']['payload']['streaming'],
			$attemptEvents
		));
		$this->assertSame(['selected', 'selected'], array_map(
			static fn (array $event): string => $event['event']['payload']['route'],
			$attemptEvents
		));
	}

	public function testFallbackStreamServerErrorRetriesFallbackSynchronouslyWithBudgetAndTrace(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setFallbackModel('secondary:model-b');
		$settings->setLlmStreamTimeout(240);
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$calls = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(3))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls): IResponse {
				$calls[] = [
					'uri' => $uri,
					'model' => $options['json']['model'] ?? null,
					'payload_stream' => $options['json']['stream'] ?? null,
					'request_stream' => $options['stream'] ?? null,
				];
				if (count($calls) === 1) {
					throw new \Exception('cURL error 7: Failed to connect');
				}
				if (count($calls) === 2) {
					return $this->rawResponse('{"error":"temporary fallback stream failure"}', 502);
				}

				return $this->jsonResponse([
					'model' => 'model-b',
					'choices' => [[
						'message' => ['content' => 'fallback sync answer'],
						'finish_reason' => 'stop',
					]],
				]);
			});

		$events = [];
		$observedDeltas = [];
		$budget = new ProviderAttemptBudget(3);
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		$result = $llmClient->streamChatCompletion(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			static function (array $delta) use (&$observedDeltas): void {
				$observedDeltas[] = $delta;
			},
			null,
			['provider_attempt_budget' => $budget, 'trace_run_id' => 73],
		);

		$this->assertSame('fallback sync answer', $result['content']);
		$this->assertSame('secondary:model-b', $result['model_reference']);
		$this->assertSame([], $observedDeltas);
		$this->assertSame(3, $budget->getConsumed());
		$this->assertSame([
			['https://primary.example.invalid/v1/chat/completions', 'model-a', true, true],
			['https://secondary.example.invalid/v1/chat/completions', 'model-b', true, true],
			['https://secondary.example.invalid/v1/chat/completions', 'model-b', null, null],
		], array_map(
			static fn (array $call): array => [
				$call['uri'],
				$call['model'],
				$call['payload_stream'],
				$call['request_stream'],
			],
			$calls
		));

		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(3, $attemptEvents);
		$this->assertSame([1, 2, 3], array_map(
			static fn (array $event): int => $event['event']['payload']['attempt'],
			$attemptEvents
		));
		$this->assertSame(['selected', 'fallback', 'fallback'], array_map(
			static fn (array $event): string => $event['event']['payload']['route'],
			$attemptEvents
		));
		$this->assertSame(['initial', 'initial', 'stream_to_sync_retry'], array_map(
			static fn (array $event): string => $event['event']['payload']['reason'],
			$attemptEvents
		));
		$this->assertSame([true, true, false], array_map(
			static fn (array $event): bool => $event['event']['payload']['streaming'],
			$attemptEvents
		));
		$this->assertSame(['error', 'error', 'ok'], array_map(
			static fn (array $event): string => $event['event']['status'],
			$attemptEvents
		));
		$this->assertArrayNotHasKey('fallback_cause', $attemptEvents[0]['event']['payload']);
		$this->assertSame('network', $attemptEvents[1]['event']['payload']['fallback_cause']);
		$this->assertSame('network', $attemptEvents[2]['event']['payload']['fallback_cause']);
	}

	public function testFallbackRouteDoesNotRetrySynchronouslyAfterPublicDelta(): void {
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

		$posts = 0;
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function () use (&$posts): IResponse {
				$posts++;
				if ($posts === 1) {
					throw new \Exception('cURL error 7: Failed to connect');
				}

				return $this->rawResponse(
					"data: {\"choices\":[{\"delta\":{\"content\":\"public fallback delta\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n"
				);
			});

		$budget = new ProviderAttemptBudget(3);
		$observedDeltas = [];
		$llmClient = new LLMClient($this->clientService($client), $settingsService, $this->logger());

		try {
			$llmClient->streamChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				static function (array $delta) use (&$observedDeltas): void {
					$observedDeltas[] = $delta;
					throw new \Exception('consumer failed after public fallback delta', 500);
				},
				null,
				['provider_attempt_budget' => $budget],
			);
			$this->fail('Expected the callback exception to fail the fallback stream');
		} catch (\Exception $e) {
			$this->assertSame('Failed to stream response from AI', $e->getMessage());
			$this->assertSame('consumer failed after public fallback delta', $e->getPrevious()?->getMessage());
		}

		$this->assertSame([['content' => 'public fallback delta']], $observedDeltas);
		$this->assertSame(2, $budget->getConsumed());
	}

	public function testStreamChatCompletionDoesNotRetrySynchronouslyAfterPublicDelta(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"content\":\"public delta\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n"
			));

		$llmClient = $this->chatLlmClient($client);

		try {
			$llmClient->streamChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				static function (): void {
					throw new \Exception('consumer failed after public delta', 500);
				},
			);
			$this->fail('Expected the callback exception to fail the stream');
		} catch (\Exception $e) {
			$this->assertSame('Failed to stream response from AI', $e->getMessage());
			$this->assertSame('consumer failed after public delta', $e->getPrevious()?->getMessage());
		}
	}

	public function testSendChatCompletionUsesReasoningParametersOnFirstGpt5FallbackAttempt(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:qwen3-30b');
		$settings->setFallbackModel('secondary:gpt-5.6-luna');
		$settings->setLlmChatTimeout(90);

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
				$calls[] = [
					'uri' => $uri,
					'model' => $options['json']['model'] ?? null,
					'payload' => $options['json'],
				];
				if (count($calls) === 1) {
					throw new \Exception('cURL error 28: Operation timed out');
				}
				return $this->jsonResponse([
					'model' => 'gpt-5.6-luna',
					'choices' => [
						['message' => ['content' => 'luna answer'], 'finish_reason' => 'stop'],
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
			['temperature' => 0.7, 'max_tokens' => 800]
		);

		$this->assertSame('luna answer', $result['content']);
		$this->assertSame('secondary:gpt-5.6-luna', $result['model_reference']);
		$this->assertSame('https://primary.example.invalid/v1/chat/completions', $calls[0]['uri']);
		$this->assertSame('qwen3-30b', $calls[0]['model']);
		$this->assertSame(0.7, $calls[0]['payload']['temperature'] ?? null);
		$this->assertSame('https://secondary.example.invalid/v1/chat/completions', $calls[1]['uri']);
		$this->assertSame('gpt-5.6-luna', $calls[1]['model']);
		$this->assertSame(800, $calls[1]['payload']['max_completion_tokens'] ?? null);
		$this->assertArrayNotHasKey('temperature', $calls[1]['payload']);
		$this->assertArrayNotHasKey('max_tokens', $calls[1]['payload']);
	}

	public function testStreamChatCompletionUsesReasoningParametersOnFirstGpt5FallbackAttempt(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:qwen3-30b');
		$settings->setFallbackModel('secondary:gpt-5.6-luna');
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
				$calls[] = [
					'uri' => $uri,
					'model' => $options['json']['model'] ?? null,
					'payload' => $options['json'],
				];
				if (count($calls) === 1) {
					throw new \Exception('cURL error 28: Operation timed out');
				}
				return $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"luna stream\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n");
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
			[
				'temperature' => 0.7,
				'max_tokens' => 800,
				'stream_options' => ['include_usage' => true],
			]
		);

		$this->assertSame('luna stream', $result['content']);
		$this->assertSame('secondary:gpt-5.6-luna', $result['model_reference']);
		$this->assertSame('qwen3-30b', $calls[0]['model']);
		$this->assertSame('gpt-5.6-luna', $calls[1]['model']);
		$this->assertSame(800, $calls[1]['payload']['max_completion_tokens'] ?? null);
		$this->assertArrayNotHasKey('temperature', $calls[1]['payload']);
		$this->assertArrayNotHasKey('max_tokens', $calls[1]['payload']);
	}

	public function testSendChatCompletionUsesModelSpecificTokenAndSamplingProfiles(): void {
		foreach ([
			'primary:microsoft/gpt-5.6-luna' => true,
			'primary:microsoft/claude-sonnet-5' => true,
			'primary:microsoft/claude-sonnet-4-6' => false,
		] as $model => $usesReasoningTokenParameters) {
			$settings = new Settings();
			$settings->setApiProvider('custom');
			$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
			$settings->setDefaultModel($model);
			$settings->setLlmChatTimeout(90);

			$settingsService = $this->createMock(SettingsService::class);
			$settingsService->method('getSettings')->willReturn($settings);
			$settingsService->method('getApiKey')->willReturn('primary-key');
			$settingsService->method('normalizePositiveInteger')
				->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

			$captured = [];
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())
				->method('post')
				->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
					$captured = $options['json'];
					return $this->jsonResponse([
						'model' => $captured['model'] ?? 'model',
						'choices' => [
							['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
						],
					]);
				});

			$llmClient = new LLMClient(
				$this->clientService($client),
				$settingsService,
				$this->logger()
			);

			$llmClient->sendChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				null,
				[
					'temperature' => 0.2,
					'top_p' => 0.9,
					'presence_penalty' => 0.1,
					'frequency_penalty' => 0.2,
				]
			);

			if ($usesReasoningTokenParameters) {
				$this->assertSame(1000, $captured['max_completion_tokens'] ?? null, $model);
				$this->assertArrayNotHasKey('temperature', $captured, $model);
				$this->assertArrayNotHasKey('max_tokens', $captured, $model);
			} else {
				$this->assertSame(0.2, $captured['temperature'] ?? null, $model);
				$this->assertSame(1000, $captured['max_tokens'] ?? null, $model);
				$this->assertArrayNotHasKey('max_completion_tokens', $captured, $model);
			}
			$this->assertArrayNotHasKey('top_p', $captured, $model);
			$this->assertArrayNotHasKey('presence_penalty', $captured, $model);
			$this->assertArrayNotHasKey('frequency_penalty', $captured, $model);
		}
	}

	public function testGpt5ReasoningFirstHttp400DoesNotRepeatTheSameParameterProfile(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:microsoft/gpt-5.6-luna');
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
					$this->assertSame(800, $options['json']['max_completion_tokens'] ?? null);
					$this->assertArrayNotHasKey('temperature', $options['json']);
					$this->assertArrayNotHasKey('max_tokens', $options['json']);
					return true;
				}),
			)
			->willReturn($this->jsonResponse(['error' => 'unsupported reasoning option'], 400));

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
		);

		try {
			$llmClient->sendChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				null,
				['temperature' => 0.2, 'max_tokens' => 800],
			);
			$this->fail('Expected the provider request to fail');
		} catch (\Exception $e) {
			$this->assertSame('Failed to get response from AI', $e->getMessage());
			$this->assertSame(400, $e->getPrevious()?->getCode());
		}
	}

	public function testReasoningModelsStreamingAndTracePayloadUseReasoningParametersOnFirstAttempt(): void {
		foreach (['primary:microsoft/gpt-5.4', 'primary:microsoft/claude-sonnet-5'] as $model) {
			$settings = new Settings();
			$settings->setApiProvider('custom');
			$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
			$settings->setDefaultModel($model);
			$settings->setLlmStreamTimeout(240);

			$settingsService = $this->createMock(SettingsService::class);
			$settingsService->method('getSettings')->willReturn($settings);
			$settingsService->method('getApiKey')->willReturn('primary-key');
			$settingsService->method('normalizePositiveInteger')
				->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

			$captured = [];
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())
				->method('post')
				->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
					$captured = $options['json'];
					return $this->rawResponse(
						"data: {\"choices\":[{\"delta\":{\"content\":\"reasoning stream\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n"
					);
				});

			$llmClient = new LLMClient(
				$this->clientService($client),
				$settingsService,
				$this->logger(),
			);
			$options = ['temperature' => 0.2, 'max_tokens' => 800];

			$tracePayload = $llmClient->buildTraceChatCompletionPayload('system', [], null, $options, true);
			$result = $llmClient->streamChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				static function (): void {},
				null,
				$options,
			);

			$this->assertSame('reasoning stream', $result['content'], $model);
			$this->assertSame(800, $captured['max_completion_tokens'] ?? null, $model);
			$this->assertArrayNotHasKey('temperature', $captured, $model);
			$this->assertArrayNotHasKey('max_tokens', $captured, $model);
			$this->assertSame(800, $tracePayload['payload']['max_completion_tokens'] ?? null, $model);
			$this->assertArrayNotHasKey('temperature', $tracePayload['payload'], $model);
			$this->assertArrayNotHasKey('max_tokens', $tracePayload['payload'], $model);
		}
	}

	public function testSendChatCompletionKeepsSamplingExtrasForClassicModels(): void {
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

		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$captured): IResponse {
				$captured = $options['json'];
				return $this->jsonResponse([
					'model' => 'up/minimax-m2-5',
					'choices' => [
						['message' => ['content' => 'ok'], 'finish_reason' => 'stop'],
					],
				]);
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		$llmClient->sendChatCompletion(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			null,
			[
				'temperature' => 0.2,
				'top_p' => 0.9,
				'presence_penalty' => 0.1,
			]
		);

		$this->assertSame(0.2, $captured['temperature'] ?? null);
		$this->assertSame(0.9, $captured['top_p'] ?? null);
		$this->assertSame(0.1, $captured['presence_penalty'] ?? null);
	}

	public function testSendChatCompletionDoesNotRetryReasoningParametersForMistral(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:microsoft/Mistral-Large-3');
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$calls = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$calls): IResponse {
				$calls[] = $options['json'];
				return $this->jsonResponse(['error' => 'Assistant message must have either content or tool_calls'], 400);
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		try {
			$llmClient->sendChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				null,
				['temperature' => 0.2, 'max_tokens' => 800]
			);
			$this->fail('Expected LLM request failure');
		} catch (\Exception $e) {
			$this->assertSame('Failed to get response from AI', $e->getMessage());
			$this->assertSame(400, $e->getPrevious()?->getCode());
			$this->assertStringContainsString('Assistant message must have either content or tool_calls', $e->getPrevious()?->getMessage() ?? '');
		}

		$this->assertCount(1, $calls);
		$this->assertSame(0.2, $calls[0]['temperature'] ?? null);
		$this->assertArrayNotHasKey('max_completion_tokens', $calls[0]);
	}

	public function testSendChatCompletionKeepsFirstErrorWhenReasoningRetryAlsoFails(): void {
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
				$calls[] = $options['json'];
				if (count($calls) === 1) {
					return $this->jsonResponse(['error' => 'temperature is not supported'], 400);
				}
				return $this->jsonResponse(['error' => 'extra_forbidden: max_completion_tokens'], 422);
			});

		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger()
		);

		try {
			$llmClient->sendChatCompletion(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				null,
				['temperature' => 0.2, 'max_tokens' => 800]
			);
			$this->fail('Expected LLM request failure');
		} catch (\Exception $e) {
			$this->assertSame('Failed to get response from AI', $e->getMessage());
			$this->assertSame(400, $e->getPrevious()?->getCode());
			$this->assertStringContainsString('temperature is not supported', $e->getPrevious()?->getMessage() ?? '');
			$this->assertStringNotContainsString('extra_forbidden', $e->getPrevious()?->getMessage() ?? '');
		}

		$this->assertArrayHasKey('temperature', $calls[0]);
		$this->assertSame(800, $calls[1]['max_completion_tokens'] ?? null);
	}

	public function testSyncAndStreamAgentTurnsHaveEquivalentNativeSemantics(): void {
		$syncClient = $this->createMock(IClient::class);
		$syncClient->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'model' => 'provider-model-a',
				'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 3, 'total_tokens' => 7],
				'choices' => [[
					'message' => [
						'content' => 'Working',
						'tool_calls' => [
							[
								'id' => 'native-call-id',
								'type' => 'function',
								'function' => [
									'name' => 'search_test',
									'arguments' => '{"query":"Berlin"}',
								],
							],
							[
								'id' => 'no-arguments-id',
								'type' => 'function',
								'function' => ['name' => 'ping'],
							],
						],
					],
					'finish_reason' => 'tool_calls',
				]],
			]));

		$streamClient = $this->createMock(IClient::class);
		$streamClient->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"model\":\"provider-model-a\",\"choices\":[{\"delta\":{\"content\":\"Working\",\"tool_calls\":[{\"index\":0,\"id\":\"native-call-id\",\"function\":{\"name\":\"search_\",\"arguments\":\"{\\\"query\\\":\\\"\"}},{\"index\":1,\"id\":\"no-arguments-id\",\"function\":{\"name\":\"ping\"}}]}}]}\n\n"
				. "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"name\":\"test\",\"arguments\":\"Berlin\\\"}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
				. "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":4,\"completion_tokens\":3,\"total_tokens\":7}}\n\n"
				. "data: [DONE]\n\n"
			));

		$syncTurn = $this->chatLlmClient($syncClient)->sendAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test', 'ping']
		);
		$streamTurn = $this->chatLlmClient($streamClient)->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test', 'ping'],
			static function (): void {}
		);

		$this->assertSameAgentTurn($syncTurn, $streamTurn);
		$this->assertSame('Working', $syncTurn->getText());
		$this->assertSame('tool_calls', $syncTurn->getStopReason());
		$this->assertSame('provider-model-a', $syncTurn->getModel());
		$this->assertSame('primary:model-a', $syncTurn->getModelReference());
		$this->assertSame('primary', $syncTurn->getModelEndpoint());
		$this->assertSame(AgentTurn::COMPATIBILITY_NATIVE, $syncTurn->getCompatibilitySource());
		$this->assertSame('{}', $syncTurn->getToolCalls()[1]['function']['arguments']);
		$this->assertArrayNotHasKey('argument_error', $syncTurn->getToolCalls()[1]);
	}

	public function testStreamingToolCallsKeepProviderIndexOrderWhenChunksArriveOutOfOrder(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":1,\"id\":\"call-b\",\"function\":{\"name\":\"ping\",\"arguments\":\"{}\"}}]}}]}\n\n"
				. "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call-a\",\"function\":{\"name\":\"search_test\",\"arguments\":\"{\\\"query\\\":\\\"Berlin\\\"}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
				. "data: [DONE]\n\n"
			));

		$turn = $this->chatLlmClient($client)->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test', 'ping'],
			static function (): void {}
		);

		$this->assertSame(['call-a', 'call-b'], array_column($turn->getToolCalls(), 'id'));
		$this->assertSame(['search_test', 'ping'], array_map(
			static fn (array $call): string => $call['function']['name'],
			$turn->getToolCalls()
		));
	}

	public function testIncompleteStreamWithMutatingToolFragmentFailsTypedAndTracesTheAttemptAsError(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"content\":\"partial\",\"tool_calls\":[{\"index\":0,\"id\":\"danger-call\",\"function\":{\"name\":\"delete_record\",\"arguments\":\"{\\\"id\\\":\\\"secret-record\\\"}\"}}]}}]}\n\n"
				. "data: [DONE]\n\n"
			));

		$events = [];
		$budget = new ProviderAttemptBudget(2);
		$llmClient = $this->chatLlmClient(
			$client,
			$this->logger(),
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->streamAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['delete_record'],
				static function (): void {},
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 56]
			);
			$this->fail('Expected an incomplete provider stream');
		} catch (IncompleteProviderStreamException $e) {
			$this->assertSame(IncompleteProviderStreamException::MESSAGE, $e->getMessage());
			$this->assertStringNotContainsString('secret-record', $e->getMessage());
		}

		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('error', $attemptEvents[0]['event']['status']);
		$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		$this->assertSame('initial', $attemptEvents[0]['event']['payload']['reason']);
		$this->assertStringNotContainsString('secret-record', json_encode($attemptEvents) ?: '');
	}

	public function testStreamingCallbackErrorStillClosesTheConsumedProviderAttemptTrace(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"content\":\"partial\"},\"finish_reason\":\"stop\"}]}\n\n"
				. "data: [DONE]\n\n"
			));

		$events = [];
		$budget = new ProviderAttemptBudget(1);
		$callbackError = new \Error('secret-callback-error');
		$llmClient = $this->chatLlmClient(
			$client,
			$this->logger(),
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->streamAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				[],
				static function (array $delta) use ($callbackError): void {
					throw $callbackError;
				},
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 63]
			);
			$this->fail('Expected the callback error');
		} catch (\Error $e) {
			$this->assertSame($callbackError, $e);
		}

		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('error', $attemptEvents[0]['event']['status']);
		$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		$this->assertStringNotContainsString('secret-callback-error', json_encode($attemptEvents) ?: '');
	}

	public function testSyncTextWithoutFinishReasonFailsTypedAndTracesTheAttemptAsError(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => ['content' => 'secret-partial-text'],
				]],
			]));

		$events = [];
		$budget = new ProviderAttemptBudget(2);
		$llmClient = $this->chatLlmClient(
			$client,
			$this->logger(),
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				[],
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 58]
			);
			$this->fail('Expected an incomplete provider response');
		} catch (IncompleteProviderStreamException $e) {
			$this->assertSame(IncompleteProviderStreamException::MESSAGE, $e->getMessage());
			$this->assertStringNotContainsString('secret-partial-text', $e->getMessage());
		}

		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('error', $attemptEvents[0]['event']['status']);
		$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		$this->assertStringNotContainsString('secret-partial-text', json_encode($attemptEvents) ?: '');
	}

	public function testSyncMutatingToolCallWithoutFinishReasonFailsBeforeAgentTurn(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => [
						'content' => null,
						'tool_calls' => [[
							'id' => 'danger-call',
							'type' => 'function',
							'function' => [
								'name' => 'delete_record',
								'arguments' => '{"id":"secret-record"}',
							],
						]],
					],
				]],
			]));

		$events = [];
		$budget = new ProviderAttemptBudget(2);
		$llmClient = $this->chatLlmClient(
			$client,
			$this->logger(),
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['delete_record'],
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 59]
			);
			$this->fail('Expected an incomplete provider response before AgentTurn creation');
		} catch (IncompleteProviderStreamException $e) {
			$this->assertSame(IncompleteProviderStreamException::MESSAGE, $e->getMessage());
		}

		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('error', $attemptEvents[0]['event']['status']);
		$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		$this->assertStringNotContainsString('secret-record', json_encode($attemptEvents) ?: '');
	}

	public function testSyncInvalidContentTypeFailsBeforeAgentTurnWithoutFallback(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => [
						'content' => ['secret-invalid-content'],
						'tool_calls' => [[
							'id' => 'danger-call',
							'type' => 'function',
							'function' => [
								'name' => 'delete_record',
								'arguments' => '{"id":"secret-record"}',
							],
						]],
					],
					'finish_reason' => 'tool_calls',
				]],
			]));

		$events = [];
		$budget = new ProviderAttemptBudget(2);
		$llmClient = $this->chatLlmClient(
			$client,
			traceService: $this->recordingTraceService($events),
			withFallback: true,
		);

		try {
			$llmClient->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['delete_record'],
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 66]
			);
			$this->fail('Expected invalid provider content to fail before AgentTurn creation');
		} catch (ProviderContractException $e) {
			$this->assertSame(ProviderContractException::MESSAGE, $e->getMessage());
			$this->assertStringNotContainsString('secret-invalid-content', $e->getMessage());
			$this->assertStringNotContainsString('secret-record', $e->getMessage());
		}

		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('error', $attemptEvents[0]['event']['status']);
		$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		$this->assertStringNotContainsString('secret-invalid-content', json_encode($attemptEvents) ?: '');
		$this->assertStringNotContainsString('secret-record', json_encode($attemptEvents) ?: '');
	}

	public function testStreamingInvalidContentTypeFailsBeforeCallbackWithoutFallback(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"content\":[\"secret-invalid-content\"],\"tool_calls\":[{\"index\":0,\"id\":\"danger-call\",\"type\":\"function\",\"function\":{\"name\":\"delete_record\",\"arguments\":\"{\\\"id\\\":\\\"secret-record\\\"}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
				. "data: [DONE]\n\n"
			));

		$events = [];
		$callbackCount = 0;
		$budget = new ProviderAttemptBudget(2);
		$llmClient = $this->chatLlmClient(
			$client,
			traceService: $this->recordingTraceService($events),
			withFallback: true,
		);

		try {
			$llmClient->streamAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['delete_record'],
				static function () use (&$callbackCount): void {
					$callbackCount++;
				},
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 67]
			);
			$this->fail('Expected invalid streaming content to fail before the callback');
		} catch (ProviderContractException $e) {
			$this->assertSame(ProviderContractException::MESSAGE, $e->getMessage());
			$this->assertStringNotContainsString('secret-invalid-content', $e->getMessage());
			$this->assertStringNotContainsString('secret-record', $e->getMessage());
		}

		$this->assertSame(0, $callbackCount);
		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('error', $attemptEvents[0]['event']['status']);
		$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		$this->assertStringNotContainsString('secret-invalid-content', json_encode($attemptEvents) ?: '');
		$this->assertStringNotContainsString('secret-record', json_encode($attemptEvents) ?: '');
	}

	public function testStreamingInvalidDataFramesFailTypedBeforeAnyCallbackOrFallback(): void {
		$invalidFrames = [
			'{"choices":[{"delta":{"content":"secret-corrupt-text"}}',
			'"secret-scalar-tool-arguments"',
			'{"choices":{"unexpected":{"delta":{"content":"secret-associative-choice"}}}}',
			'{"choices":[]}',
			'{"choices":[],"unknown":"secret-unknown-metadata"}',
			'{"choices":[],"created":"secret-invalid-created"}',
			'{"model":"provider-model","object":"chat.completion.chunk"}',
		];

		foreach ($invalidFrames as $index => $invalidFrame) {
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())
				->method('post')
				->willReturn($this->rawResponse(
					": keepalive\n"
					. "event: message\n"
					. "data: {$invalidFrame}\n\n"
					. "data:{\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"danger-call\",\"function\":{\"name\":\"delete_record\",\"arguments\":\"{\\\"id\\\":\\\"secret-record\\\"}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
					. "data: [DONE]\n\n"
				));

			$events = [];
			$callbackCount = 0;
			$budget = new ProviderAttemptBudget(2);
			$llmClient = $this->chatLlmClient(
				$client,
				traceService: $this->recordingTraceService($events),
				withFallback: true,
			);

			try {
				$llmClient->streamAgentTurn(
					'system',
					[['role' => 'user', 'content' => 'hi']],
					['delete_record'],
					static function () use (&$callbackCount): void {
						$callbackCount++;
					},
					null,
					['provider_attempt_budget' => $budget, 'trace_run_id' => 70 + $index]
				);
				$this->fail('Expected the invalid streaming frame to fail before AgentTurn creation');
			} catch (ProviderContractException $e) {
				$this->assertSame(ProviderContractException::MESSAGE, $e->getMessage());
				$this->assertStringNotContainsString('secret-corrupt-text', $e->getMessage());
				$this->assertStringNotContainsString('secret-scalar-tool-arguments', $e->getMessage());
				$this->assertStringNotContainsString('secret-associative-choice', $e->getMessage());
				$this->assertStringNotContainsString('secret-unknown-metadata', $e->getMessage());
				$this->assertStringNotContainsString('secret-invalid-created', $e->getMessage());
				$this->assertStringNotContainsString('secret-record', $e->getMessage());
			}

			$this->assertSame(0, $callbackCount);
			$this->assertSame(1, $budget->getConsumed());
			$attemptEvents = array_values(array_filter(
				$events,
				static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
			));
			$this->assertCount(1, $attemptEvents);
			$this->assertSame('error', $attemptEvents[0]['event']['status']);
			$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
			$this->assertStringNotContainsString('secret-corrupt-text', json_encode($attemptEvents) ?: '');
			$this->assertStringNotContainsString('secret-scalar-tool-arguments', json_encode($attemptEvents) ?: '');
			$this->assertStringNotContainsString('secret-associative-choice', json_encode($attemptEvents) ?: '');
			$this->assertStringNotContainsString('secret-unknown-metadata', json_encode($attemptEvents) ?: '');
			$this->assertStringNotContainsString('secret-invalid-created', json_encode($attemptEvents) ?: '');
			$this->assertStringNotContainsString('secret-record', json_encode($attemptEvents) ?: '');
		}
	}

	public function testStreamingAcceptsStrictMetadataPreambleBeforeCompletionFrames(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"id\":\"chatcmpl-preamble\",\"created\":1787529600,\"model\":\"provider-model\",\"object\":\"chat.completion.chunk\",\"choices\":[],\"system_fingerprint\":null,\"service_tier\":null,\"obfuscation\":\"opaque\"}\n\n"
				. "data: {\"choices\":[{\"delta\":{\"content\":\"PONG\"},\"finish_reason\":\"stop\"}]}\n\n"
				. "data: [DONE]\n\n"
			));
		$observedDeltas = [];

		$turn = $this->chatLlmClient($client)->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			[],
			static function (array $delta) use (&$observedDeltas): void {
				$observedDeltas[] = $delta;
			},
		);

		$this->assertSame([['content' => 'PONG']], $observedDeltas);
		$this->assertSame('PONG', $turn->getText());
		$this->assertSame('stop', $turn->getStopReason());
		$this->assertSame('provider-model', $turn->getModel());
	}

	public function testEmptyTwoHundredBodiesFailWithSameTypedOutcomeAndAttemptTrace(): void {
		$outcomes = [];
		foreach (['sync', 'stream'] as $index => $mode) {
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())
				->method('post')
				->willReturn($this->rawResponse(''));
			$events = [];
			$callbackCount = 0;
			$budget = new ProviderAttemptBudget(2);
			$llmClient = $this->chatLlmClient(
				$client,
				traceService: $this->recordingTraceService($events),
				withFallback: true,
			);

			try {
				if ($mode === 'sync') {
					$llmClient->sendAgentTurn(
						'system',
						[['role' => 'user', 'content' => 'hi']],
						[],
						null,
						['provider_attempt_budget' => $budget, 'trace_run_id' => 68 + $index]
					);
				} else {
					$llmClient->streamAgentTurn(
						'system',
						[['role' => 'user', 'content' => 'hi']],
						[],
						static function () use (&$callbackCount): void {
							$callbackCount++;
						},
						null,
						['provider_attempt_budget' => $budget, 'trace_run_id' => 68 + $index]
					);
				}
				$this->fail('Expected an empty provider body to fail with a typed outcome');
			} catch (IncompleteProviderStreamException $e) {
				$outcomes[$mode] = [get_class($e), $e->getMessage()];
			}

			$this->assertSame(0, $callbackCount);
			$this->assertSame(1, $budget->getConsumed());
			$attemptEvents = array_values(array_filter(
				$events,
				static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
			));
			$this->assertCount(1, $attemptEvents);
			$this->assertSame('error', $attemptEvents[0]['event']['status']);
			$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		}

		$this->assertSame($outcomes['sync'], $outcomes['stream']);
		$this->assertSame([
			IncompleteProviderStreamException::class,
			IncompleteProviderStreamException::MESSAGE,
		], $outcomes['sync']);
	}

	public function testSyncMalformedToolCallsCollectionFailsTypedBeforeAgentTurnAndTracesError(): void {
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
		$client->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => [
						'content' => null,
						'tool_calls' => 'secret-malformed-tool-calls',
					],
					'finish_reason' => 'tool_calls',
				]],
			]));

		$events = [];
		$budget = new ProviderAttemptBudget(2);
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['delete_record'],
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 64]
			);
			$this->fail('Expected a provider contract error before AgentTurn creation');
		} catch (ProviderContractException $e) {
			$this->assertSame(ProviderContractException::MESSAGE, $e->getMessage());
			$this->assertStringNotContainsString('secret-malformed-tool-calls', $e->getMessage());
		}

		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('error', $attemptEvents[0]['event']['status']);
		$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		$this->assertStringNotContainsString('secret-malformed-tool-calls', json_encode($attemptEvents) ?: '');
	}

	public function testSyncUnsupportedNativeToolCallTypeFailsTypedBeforeAgentTurn(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => [
						'content' => null,
						'tool_calls' => [[
							'id' => 'danger-call',
							'type' => 'unsupported',
							'function' => [
								'name' => 'delete_record',
								'arguments' => '{"id":"secret-record"}',
							],
						]],
					],
					'finish_reason' => 'tool_calls',
				]],
			]));

		try {
			$this->chatLlmClient($client)->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['delete_record']
			);
			$this->fail('Expected an unsupported native tool-call type to fail at the provider boundary');
		} catch (ProviderContractException $e) {
			$this->assertSame(ProviderContractException::MESSAGE, $e->getMessage());
			$this->assertStringNotContainsString('delete_record', $e->getMessage());
			$this->assertStringNotContainsString('secret-record', $e->getMessage());
		}
	}

	public function testStreamingNonArrayNativeToolCallFailsTypedBeforeCallbackAndTracesError(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"danger-call\",\"function\":{\"name\":\"delete_record\",\"arguments\":\"{\\\"id\\\":\\\"secret-record\\\"}\"}},\"secret-non-array-entry\"]},\"finish_reason\":\"tool_calls\"}]}\n\n"
				. "data: [DONE]\n\n"
			));

		$events = [];
		$callbackCount = 0;
		$budget = new ProviderAttemptBudget(2);
		$llmClient = $this->chatLlmClient(
			$client,
			$this->logger(),
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->streamAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['delete_record'],
				static function () use (&$callbackCount): void {
					$callbackCount++;
				},
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 65]
			);
			$this->fail('Expected a malformed streaming tool-call entry to fail at the provider boundary');
		} catch (ProviderContractException $e) {
			$this->assertSame(ProviderContractException::MESSAGE, $e->getMessage());
			$this->assertStringNotContainsString('secret-record', $e->getMessage());
		}

		$this->assertSame(0, $callbackCount);
		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('error', $attemptEvents[0]['event']['status']);
		$this->assertSame(200, $attemptEvents[0]['event']['payload']['http_status']);
		$this->assertStringNotContainsString('secret-record', json_encode($attemptEvents) ?: '');
		$this->assertStringNotContainsString('secret-non-array-entry', json_encode($attemptEvents) ?: '');
	}

	public function testStreamingInvalidToolCallIndexFailsTypedBeforeCallback(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":\"bogus\",\"id\":\"danger-call\",\"function\":{\"name\":\"delete_record\",\"arguments\":\"{}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
				. "data: [DONE]\n\n"
			));

		$callbackCount = 0;
		try {
			$this->chatLlmClient($client)->streamAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['delete_record'],
				static function () use (&$callbackCount): void {
					$callbackCount++;
				}
			);
			$this->fail('Expected an invalid streaming tool-call index to fail at the provider boundary');
		} catch (ProviderContractException $e) {
			$this->assertSame(ProviderContractException::MESSAGE, $e->getMessage());
		}

		$this->assertSame(0, $callbackCount);
	}

	public function testMissingFunctionAndBlankNameRemainMalformedToolObservationsInSyncAndStream(): void {
		$syncClient = $this->createMock(IClient::class);
		$syncClient->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => [
						'content' => null,
						'tool_calls' => [
							['id' => 'missing-function'],
							['id' => 'blank-name', 'function' => ['name' => '', 'arguments' => '{}']],
						],
					],
					'finish_reason' => 'tool_calls',
				]],
			]));
		$streamClient = $this->createMock(IClient::class);
		$streamClient->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"missing-function\"},{\"index\":1,\"id\":\"blank-name\",\"function\":{\"name\":\"\",\"arguments\":\"{}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
				. "data: [DONE]\n\n"
			));

		$syncTurn = $this->chatLlmClient($syncClient)->sendAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['delete_record']
		);
		$streamTurn = $this->chatLlmClient($streamClient)->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['delete_record'],
			static function (): void {}
		);

		$this->assertSameAgentTurn($syncTurn, $streamTurn);
		$this->assertSame(['missing-function', 'blank-name'], array_column($syncTurn->getToolCalls(), 'id'));
		$this->assertSame(['', ''], array_map(
			static fn (array $call): string => $call['function']['name'],
			$syncTurn->getToolCalls()
		));
		$this->assertSame(['{}', '{}'], array_map(
			static fn (array $call): string => $call['function']['arguments'],
			$syncTurn->getToolCalls()
		));
	}

	public function testAgentTurnPreservesProviderIdsAndInvalidArgumentPayloads(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => [
						'content' => null,
						'tool_calls' => [
							[
								'id' => 'provider-id-42',
								'function' => ['name' => 'search_test', 'arguments' => '{"query":"Berlin"}'],
							],
							[
								'function' => ['name' => 'search_test'],
							],
							[
								'id' => 'invalid-args',
								'function' => ['name' => 'search_test', 'arguments' => '{"broken"'],
							],
							[
								'id' => '',
								'function' => ['name' => 'search_test', 'arguments' => '   '],
							],
							[
								'id' => 'list-arguments',
								'function' => ['name' => 'search_test', 'arguments' => []],
							],
						],
					],
					'finish_reason' => 'tool_calls',
				]],
			]));

		$turn = $this->chatLlmClient($client)->sendAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test']
		);
		$calls = $turn->getToolCalls();

		$this->assertSame('provider-id-42', $calls[0]['id']);
		$this->assertSame('{"query":"Berlin"}', $calls[0]['function']['arguments']);
		$this->assertArrayNotHasKey('argument_error', $calls[0]);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{9}$/', $calls[1]['id']);
		$this->assertSame('{}', $calls[1]['function']['arguments']);
		$this->assertArrayNotHasKey('argument_error', $calls[1]);
		$this->assertSame('{"broken"', $calls[2]['function']['arguments']);
		$this->assertSame(ProviderResponseNormalizer::ARGUMENT_ERROR_INVALID_JSON, $calls[2]['argument_error']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{9}$/', $calls[3]['id']);
		$this->assertSame('   ', $calls[3]['function']['arguments']);
		$this->assertSame(ProviderResponseNormalizer::ARGUMENT_ERROR_INVALID_JSON, $calls[3]['argument_error']);
		$this->assertSame('[]', $calls[4]['function']['arguments']);
		$this->assertSame(ProviderResponseNormalizer::ARGUMENT_ERROR_NOT_OBJECT, $calls[4]['argument_error']);
	}

	public function testStructuredNativeArgumentsHaveEquivalentSyncAndStreamSemantics(): void {
		$arguments = [
			'query' => 'Berlin',
			'filters' => ['kind' => 'campus'],
		];
		$syncClient = $this->createMock(IClient::class);
		$syncClient->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => [
						'content' => null,
						'tool_calls' => [[
							'id' => 'structured-arguments',
							'type' => 'function',
							'function' => [
								'name' => 'search_test',
								'arguments' => $arguments,
							],
						]],
					],
					'finish_reason' => 'tool_calls',
				]],
			]));

		$streamPayload = json_encode([
			'choices' => [[
				'delta' => [
					'content' => null,
					'tool_calls' => [[
						'index' => 0,
						'id' => 'structured-arguments',
						'type' => 'function',
						'function' => [
							'name' => 'search_test',
							'arguments' => $arguments,
						],
					]],
				],
				'finish_reason' => 'tool_calls',
			]],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$streamClient = $this->createMock(IClient::class);
		$streamClient->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse("data: {$streamPayload}\n\ndata: [DONE]\n\n"));

		$syncTurn = $this->chatLlmClient($syncClient)->sendAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test']
		);
		$streamTurn = $this->chatLlmClient($streamClient)->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test'],
			static function (): void {}
		);

		$this->assertSameAgentTurn($syncTurn, $streamTurn);
		$this->assertSame(
			json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			$syncTurn->getToolCalls()[0]['function']['arguments']
		);
		$this->assertArrayNotHasKey('argument_error', $syncTurn->getToolCalls()[0]);
	}

	public function testAgentTurnPreservesEveryProviderFinishReasonInSyncAndStreamModes(): void {
		foreach (['stop', 'length', 'content_filter', 'tool_calls'] as $finishReason) {
			$syncClient = $this->createMock(IClient::class);
			$syncClient->method('post')->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => ['content' => 'answer'],
					'finish_reason' => $finishReason,
				]],
			]));
			$streamClient = $this->createMock(IClient::class);
			$streamClient->method('post')->willReturn($this->rawResponse(
				'data: ' . json_encode([
					'choices' => [[
						'delta' => ['content' => 'answer'],
						'finish_reason' => $finishReason,
					]],
				]) . "\n\ndata: [DONE]\n\n"
			));

			$syncTurn = $this->chatLlmClient($syncClient)->sendAgentTurn('system', [], []);
			$observedContent = [];
			$streamTurn = $this->chatLlmClient($streamClient)->streamAgentTurn(
				'system',
				[],
				[],
				static function (array $delta) use (&$observedContent): void {
					if (is_string($delta['content'] ?? null)) {
						$observedContent[] = $delta['content'];
					}
				}
			);

			$this->assertSame($finishReason, $syncTurn->getStopReason());
			$this->assertSame($finishReason, $streamTurn->getStopReason());
			$this->assertSame(['answer'], $observedContent);
		}
	}

	public function testEmptySuccessfulResponsesHaveTheSameTypedRepresentation(): void {
		$syncClient = $this->createMock(IClient::class);
		$syncClient->method('post')->willReturn($this->jsonResponse([
			'choices' => [[
				'message' => ['content' => ''],
				'finish_reason' => 'stop',
			]],
		]));
		$streamClient = $this->createMock(IClient::class);
		$streamClient->method('post')->willReturn($this->rawResponse(
			"data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n"
		));

		$syncTurn = $this->chatLlmClient($syncClient)->sendAgentTurn('system', [], []);
		$streamTurn = $this->chatLlmClient($streamClient)->streamAgentTurn(
			'system',
			[],
			[],
			static function (): void {}
		);

		$this->assertTrue($syncTurn->isEmpty());
		$this->assertTrue($streamTurn->isEmpty());
		$this->assertSame('', $syncTurn->getText());
		$this->assertSame([], $syncTurn->getToolCalls());
		$this->assertSame('stop', $syncTurn->getStopReason());
		$this->assertSameAgentTurn($syncTurn, $streamTurn);
	}

	public function testLegacyCompatibilityNormalizesOnlySupportedWholeMessageShapesAndEmitsSafeTelemetry(): void {
		$cases = [
			[
				'{"name":"search_test","arguments":{"query":"secret-query-value"}}',
				ProviderResponseNormalizer::COMPATIBILITY_JSON,
				AgentTurn::COMPATIBILITY_LEGACY_JSON,
				['query' => 'secret-query-value'],
			],
			[
				'<tool_call>{"name":"search_test","arguments":{"query":"secret-query-value"}}</tool_call>',
				ProviderResponseNormalizer::COMPATIBILITY_XML,
				AgentTurn::COMPATIBILITY_LEGACY_XML,
				['query' => 'secret-query-value'],
			],
			[
				'<function_call><name>search_test</name><arguments>{"query":"secret-query-value"}</arguments></function_call>',
				ProviderResponseNormalizer::COMPATIBILITY_XML,
				AgentTurn::COMPATIBILITY_LEGACY_XML,
				['query' => 'secret-query-value'],
			],
			[
				'<minimax:tool_call><invoke name="search_test"><parameter name="query">secret-query-value</parameter><parameter name="limit">10</parameter></invoke></minimax:tool_call>',
				ProviderResponseNormalizer::COMPATIBILITY_XML,
				AgentTurn::COMPATIBILITY_LEGACY_XML,
				['query' => 'secret-query-value', 'limit' => 10],
			],
			[
				'<tool_call>search_test<arg_key>query</arg_key><arg_value>secret-query-value</arg_value><arg_key>limit</arg_key><arg_value>10</arg_value></tool_call>',
				ProviderResponseNormalizer::COMPATIBILITY_XML,
				AgentTurn::COMPATIBILITY_LEGACY_XML,
				['query' => 'secret-query-value', 'limit' => 10],
			],
		];

		foreach ($cases as [$content, $mode, $expectedSource, $expectedArguments]) {
			$events = [];
			$logger = new RecordingLogger();
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())
				->method('post')
				->willReturnCallback(function (string $uri, array $options) use ($content): IResponse {
					$this->assertSame('https://primary.example.invalid/v1/chat/completions', $uri);
					$this->assertArrayNotHasKey('legacy_tool_call_compatibility', $options['json']);
					$this->assertArrayNotHasKey('provider_attempt_budget', $options['json']);
					$this->assertArrayNotHasKey('trace_run_id', $options['json']);
					return $this->jsonResponse([
						'choices' => [[
							'message' => ['content' => $content],
							'finish_reason' => 'stop',
						]],
					]);
				});

			$turn = $this->chatLlmClient(
				$client,
				$logger,
				$this->recordingTraceService($events),
			)->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				['search_test'],
				null,
				[
					'legacy_tool_call_compatibility' => $mode,
					'trace_run_id' => 91,
				]
			);

			$this->assertSame('', $turn->getText());
			$this->assertSame($expectedSource, $turn->getCompatibilitySource());
			$this->assertCount(1, $turn->getToolCalls());
			$toolCall = $turn->getToolCalls()[0];
			$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{9}$/', $toolCall['id']);
			$this->assertSame('search_test', $toolCall['function']['name']);
			$this->assertSame($expectedArguments, json_decode($toolCall['function']['arguments'], true, 512, JSON_THROW_ON_ERROR));

			$compatibilityEvents = array_values(array_filter(
				$events,
				static fn (array $event): bool => $event['event_type'] === 'provider_compatibility'
			));
			$this->assertCount(1, $compatibilityEvents);
			$this->assertSame(91, $compatibilityEvents[0]['run_id']);
			$this->assertSame([
				'source' => $expectedSource,
				'model_reference' => 'primary:model-a',
				'endpoint_key' => 'primary',
				'tool_call_count' => 1,
			], $compatibilityEvents[0]['event']['payload']);
			$this->assertCount(1, $logger->infos);
			$telemetryJson = json_encode([$compatibilityEvents[0], $logger->infos[0]]) ?: '';
			$this->assertStringNotContainsString('secret-query-value', $telemetryJson);
			$this->assertStringNotContainsString('primary-key', $telemetryJson);
			$this->assertStringNotContainsString('https://', $telemetryJson);
		}
	}

	public function testLegacyCompatibilityDefaultsOffAndRejectsMixedOrNamelessContent(): void {
		$cases = [
			[
				'{"name":"search_test","arguments":{"query":"Berlin"}}',
				ProviderResponseNormalizer::COMPATIBILITY_OFF,
			],
			[
				'Before {"name":"search_test","arguments":{"query":"Berlin"}} after',
				ProviderResponseNormalizer::COMPATIBILITY_JSON_XML,
			],
			[
				'{"arguments":{"query":"Berlin"}}',
				ProviderResponseNormalizer::COMPATIBILITY_JSON,
			],
			[
				'{"name":"search_test","arguments":{},"text":"ordinary prose"}',
				ProviderResponseNormalizer::COMPATIBILITY_JSON,
			],
			[
				'Before <tool_call>{"name":"search_test","arguments":{}}</tool_call> after',
				ProviderResponseNormalizer::COMPATIBILITY_JSON_XML,
			],
		];

		foreach ($cases as [$content, $mode]) {
			$events = [];
			$logger = new RecordingLogger();
			$client = $this->createMock(IClient::class);
			$client->method('post')->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => ['content' => $content],
					'finish_reason' => 'stop',
				]],
			]));

			$turn = $this->chatLlmClient(
				$client,
				$logger,
				$this->recordingTraceService($events),
			)->sendAgentTurn(
				'system',
				[],
				['search_test'],
				null,
				['legacy_tool_call_compatibility' => $mode, 'trace_run_id' => 92]
			);

			$this->assertSame($content, $turn->getText());
			$this->assertSame([], $turn->getToolCalls());
			$this->assertSame(AgentTurn::COMPATIBILITY_NATIVE, $turn->getCompatibilitySource());
			$this->assertSame([], array_values(array_filter(
				$events,
				static fn (array $event): bool => $event['event_type'] === 'provider_compatibility'
			)));
			$this->assertSame([], $logger->infos);
		}
	}

	public function testLegacyCompatibilityNormalizesUnknownWholeMessageCallsWithoutLeakingArtifacts(): void {
		$jsonContent = '{"name":"unknown_tool","arguments":{"query":"secret-json"}}';
		$jsonClient = $this->createMock(IClient::class);
		$jsonClient->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => ['content' => $jsonContent],
					'finish_reason' => 'stop',
				]],
			]));

		$jsonTurn = $this->chatLlmClient($jsonClient)->sendAgentTurn(
			'system',
			[],
			['search_test'],
			null,
			['legacy_tool_call_compatibility' => ProviderResponseNormalizer::COMPATIBILITY_JSON]
		);

		$xmlContent = '<minimax:tool_call><invoke name="unknown_tool"><parameter name="query">secret-xml</parameter></invoke></minimax:tool_call>';
		$streamClient = $this->createMock(IClient::class);
		$streamClient->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				'data: ' . json_encode([
					'model' => 'up/minimax-m2-5',
					'choices' => [[
						'delta' => ['content' => $xmlContent],
						'finish_reason' => 'stop',
					]],
				], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n\ndata: [DONE]\n\n"
			));
		$observedDeltas = [];
		$xmlTurn = $this->chatLlmClient($streamClient)->streamAgentTurn(
			'system',
			[],
			['search_test'],
			static function (array $delta) use (&$observedDeltas): void {
				$observedDeltas[] = $delta;
			},
			'primary:up/minimax-m2-5',
		);

		$this->assertSame('', $jsonTurn->getText());
		$this->assertSame('unknown_tool', $jsonTurn->getToolCalls()[0]['function']['name']);
		$this->assertSame(AgentTurn::COMPATIBILITY_LEGACY_JSON, $jsonTurn->getCompatibilitySource());
		$this->assertSame([], $observedDeltas);
		$this->assertSame('', $xmlTurn->getText());
		$this->assertSame('unknown_tool', $xmlTurn->getToolCalls()[0]['function']['name']);
		$this->assertSame(AgentTurn::COMPATIBILITY_LEGACY_XML, $xmlTurn->getCompatibilitySource());
	}

	public function testMiniMaxCapabilityEnablesLegacyXmlUnlessExplicitlyDisabled(): void {
		$content = '<minimax:tool_call><invoke name="search_test"><parameter name="query">secret-query-value</parameter></invoke></minimax:tool_call>';
		$events = [];
		$logger = new RecordingLogger();
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => ['content' => $content],
					'finish_reason' => 'stop',
				]],
			]));
		$llmClient = $this->chatLlmClient(
			$client,
			$logger,
			$this->recordingTraceService($events),
		);

		$compatibleTurn = $llmClient->sendAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test'],
			'primary:up/minimax-m2-5',
			['trace_run_id' => 93]
		);
		$disabledTurn = $llmClient->sendAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test'],
			'primary:up/minimax-m2-5',
			['legacy_tool_call_compatibility' => ProviderResponseNormalizer::COMPATIBILITY_OFF]
		);

		$this->assertSame(AgentTurn::COMPATIBILITY_LEGACY_XML, $compatibleTurn->getCompatibilitySource());
		$this->assertSame('', $compatibleTurn->getText());
		$this->assertSame('search_test', $compatibleTurn->getToolCalls()[0]['function']['name']);
		$this->assertSame(AgentTurn::COMPATIBILITY_NATIVE, $disabledTurn->getCompatibilitySource());
		$this->assertSame($content, $disabledTurn->getText());
		$this->assertSame([], $disabledTurn->getToolCalls());

		$compatibilityEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_compatibility'
		));
		$this->assertCount(1, $compatibilityEvents);
		$this->assertSame('primary:up/minimax-m2-5', $compatibilityEvents[0]['event']['payload']['model_reference']);
		$telemetryJson = json_encode([$compatibilityEvents, $logger->infos]) ?: '';
		$this->assertStringNotContainsString('secret-query-value', $telemetryJson);
	}

	public function testLegacyFallbackStreamContentIsQuarantinedUntilNormalization(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setFallbackModel('secondary:minimax-m2-5');
		$settings->setLlmStreamTimeout(240);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$content = '<minimax:tool_call><invoke name="search_test"><parameter name="query">secret-query-value</parameter></invoke></minimax:tool_call>';
		$posts = 0;
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function () use (&$posts, $content): IResponse {
				$posts++;
				if ($posts === 1) {
					throw new \Exception('cURL error 28: Operation timed out');
				}

				$payload = json_encode([
					'model' => 'minimax-m2-5',
					'choices' => [[
						'delta' => ['content' => $content],
						'finish_reason' => 'stop',
					]],
				], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
				return $this->rawResponse("data: {$payload}\n\ndata: [DONE]\n\n");
			});

		$events = [];
		$observedDeltas = [];
		$turn = (new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		))->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test'],
			static function (array $delta) use (&$observedDeltas): void {
				$observedDeltas[] = $delta;
			},
		);

		$this->assertSame([], $observedDeltas);
		$this->assertSame('', $turn->getText());
		$this->assertSame(AgentTurn::COMPATIBILITY_LEGACY_XML, $turn->getCompatibilitySource());
		$this->assertSame('secondary:minimax-m2-5', $turn->getModelReference());
		$this->assertSame('search_test', $turn->getToolCalls()[0]['function']['name']);
		$this->assertStringNotContainsString('secret-query-value', json_encode($events) ?: '');
	}

	public function testMiniMaxOrdinaryStreamTextIsReleasedOnceAfterNormalization(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"content\":\"Safe MiniMax answer\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n"
			));
		$observedDeltas = [];

		$turn = $this->chatLlmClient($client)->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test'],
			static function (array $delta) use (&$observedDeltas): void {
				$observedDeltas[] = $delta;
			},
			'primary:up/minimax-m2-5',
		);

		$this->assertSame([['content' => 'Safe MiniMax answer']], $observedDeltas);
		$this->assertSame('Safe MiniMax answer', $turn->getText());
		$this->assertSame([], $turn->getToolCalls());
		$this->assertSame(AgentTurn::COMPATIBILITY_NATIVE, $turn->getCompatibilitySource());
	}

	public function testMiniMaxReasoningArtifactsAreRemovedBeforeOrdinaryStreamRelease(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				"data: {\"choices\":[{\"delta\":{\"content\":\"<think>private reasoning\"},\"finish_reason\":null}]}\n\n"
				. "data: {\"choices\":[{\"delta\":{\"content\":\"</think>\\nVisible answer.\"},\"finish_reason\":\"stop\"}]}\n\n"
				. "data: [DONE]\n\n"
			));
		$observedDeltas = [];

		$turn = $this->chatLlmClient($client)->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			[],
			static function (array $delta) use (&$observedDeltas): void {
				$observedDeltas[] = $delta;
			},
			'primary:up/minimax-m2-5',
		);

		$this->assertSame([['content' => 'Visible answer.']], $observedDeltas);
		$this->assertSame('Visible answer.', $turn->getText());
		$this->assertStringNotContainsString('<think>', json_encode([$observedDeltas, $turn->getText()]) ?: '');
		$this->assertStringNotContainsString('private reasoning', json_encode([$observedDeltas, $turn->getText()]) ?: '');
	}

	public function testMiniMaxReasoningArtifactsAreRemovedBeforeLegacyToolParsing(): void {
		$content = '<think>private tool reasoning</think>'
			. '<minimax:tool_call><invoke name="search_test"><parameter name="query">Potsdam</parameter></invoke></minimax:tool_call>';
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->rawResponse(
				'data: ' . json_encode([
					'choices' => [[
						'delta' => ['content' => $content],
						'finish_reason' => 'stop',
					]],
				], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n\ndata: [DONE]\n\n"
			));
		$observedDeltas = [];

		$turn = $this->chatLlmClient($client)->streamAgentTurn(
			'system',
			[],
			['search_test'],
			static function (array $delta) use (&$observedDeltas): void {
				$observedDeltas[] = $delta;
			},
			'primary:up/minimax-m2-5',
		);

		$this->assertSame([], $observedDeltas);
		$this->assertSame('', $turn->getText());
		$this->assertSame(AgentTurn::COMPATIBILITY_LEGACY_XML, $turn->getCompatibilitySource());
		$this->assertSame('search_test', $turn->getToolCalls()[0]['function']['name']);
		$this->assertSame(
			['query' => 'Potsdam'],
			json_decode($turn->getToolCalls()[0]['function']['arguments'], true, 512, JSON_THROW_ON_ERROR)
		);
	}

	public function testReasoningOnlySyncCompletionNormalizesToEmptyAgentTurn(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($this->jsonResponse([
				'choices' => [[
					'message' => ['content' => '<think>private reasoning only</think>'],
					'finish_reason' => 'stop',
				]],
			]));

		$turn = $this->chatLlmClient($client)->sendAgentTurn('system', [], []);

		$this->assertTrue($turn->isEmpty());
		$this->assertSame('', $turn->getText());
		$this->assertSame([], $turn->getToolCalls());
	}

	public function testHiddenIncompleteLegacyStreamCanFallbackWithoutLeakingContent(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:up/minimax-m2-5');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setFallbackModel('secondary:model-b');
		$settings->setLlmStreamTimeout(240);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$hiddenContent = '<minimax:tool_call><invoke name="search_test"><parameter name="query">never-leak</parameter></invoke></minimax:tool_call>';
		$posts = 0;
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function () use (&$posts, $hiddenContent): IResponse {
				$posts++;
				if ($posts === 1) {
					$payload = json_encode([
						'choices' => [['delta' => ['role' => 'assistant', 'content' => $hiddenContent]]],
					], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
					return $this->rawResponse("data: {$payload}\n\ndata: [DONE]\n\n");
				}

				return $this->rawResponse(
					"data: {\"choices\":[{\"delta\":{\"content\":\"Safe fallback\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n"
				);
			});

		$observedDeltas = [];
		$turn = (new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
		))->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			['search_test'],
			static function (array $delta) use (&$observedDeltas): void {
				$observedDeltas[] = $delta;
			},
		);

		$this->assertSame([['content' => 'Safe fallback']], $observedDeltas);
		$this->assertSame('Safe fallback', $turn->getText());
		$this->assertSame('secondary:model-b', $turn->getModelReference());
		$this->assertSame(AgentTurn::COMPATIBILITY_NATIVE, $turn->getCompatibilitySource());
		$this->assertStringNotContainsString('never-leak', json_encode($observedDeltas) ?: '');
	}

	public function testStreamingUsageAndReasoningRetriesConsumeAndTraceEveryAttempt(): void {
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

		$posts = 0;
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(3))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$posts): IResponse {
				$posts++;
				$this->assertSame('https://primary.example.invalid/v1/chat/completions', $uri);
				$this->assertArrayNotHasKey('provider_attempt_budget', $options['json']);
				$this->assertArrayNotHasKey('trace_run_id', $options['json']);
				if ($posts < 3) {
					return $this->rawResponse('{"error":"unsupported parameter secret-body"}', 400);
				}

				return $this->rawResponse(
					"data: {\"choices\":[{\"delta\":{\"content\":\"ok\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n"
				);
			});

		$events = [];
		$budget = new ProviderAttemptBudget(5);
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		$turn = $llmClient->streamAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			[],
			static function (): void {},
			null,
			[
				'provider_attempt_budget' => $budget,
				'trace_run_id' => 51,
				'temperature' => 0.2,
				'max_tokens' => 800,
			]
		);

		$this->assertSame('ok', $turn->getText());
		$this->assertSame('stop', $turn->getStopReason());
		$this->assertSame(3, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(3, $attemptEvents);
		$this->assertSame(['initial', 'stream_without_usage', 'reasoning_retry'], array_map(
			static fn (array $event): string => $event['event']['payload']['reason'],
			$attemptEvents
		));
		$this->assertSame(['error', 'error', 'ok'], array_map(
			static fn (array $event): string => $event['event']['status'],
			$attemptEvents
		));

		foreach ($attemptEvents as $index => $event) {
			$this->assertSame(51, $event['run_id']);
			$this->assertSame(['status', 'duration_ms', 'payload'], array_keys($event['event']));
			$this->assertSame([
				'attempt',
				'limit',
				'route',
				'reason',
				'model_reference',
				'endpoint_key',
				'streaming',
				'http_status',
			], array_keys($event['event']['payload']));
			$this->assertSame($index + 1, $event['event']['payload']['attempt']);
			$this->assertSame(5, $event['event']['payload']['limit']);
			$this->assertSame('selected', $event['event']['payload']['route']);
			$this->assertSame('primary:model-a', $event['event']['payload']['model_reference']);
			$this->assertSame('primary', $event['event']['payload']['endpoint_key']);
			$this->assertTrue($event['event']['payload']['streaming']);
			$this->assertIsInt($event['event']['payload']['http_status']);
			$this->assertIsInt($event['event']['duration_ms']);
		}

		$traceJson = json_encode($attemptEvents) ?: '';
		$this->assertStringNotContainsString('secret-body', $traceJson);
		$this->assertStringNotContainsString('primary-key', $traceJson);
		$this->assertStringNotContainsString('https://', $traceJson);
	}

	public function testFallbackConsumesAndTracesASeparateProviderAttempt(): void {
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

		$posts = 0;
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function () use (&$posts): IResponse {
				$posts++;
				if ($posts === 1) {
					throw new \Exception('cURL error 7: Failed to connect to secret-host');
				}

				return $this->jsonResponse([
					'model' => 'model-b',
					'choices' => [[
						'message' => ['content' => 'fallback answer'],
						'finish_reason' => 'stop',
					]],
				]);
			});

		$events = [];
		$budget = new ProviderAttemptBudget(4);
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);
		$turn = $llmClient->sendAgentTurn(
			'system',
			[['role' => 'user', 'content' => 'hi']],
			[],
			null,
			['provider_attempt_budget' => $budget, 'trace_run_id' => 52]
		);

		$this->assertSame('fallback answer', $turn->getText());
		$this->assertSame('secondary:model-b', $turn->getModelReference());
		$this->assertSame(2, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(2, $attemptEvents);
		$this->assertSame(['selected', 'fallback'], array_map(
			static fn (array $event): string => $event['event']['payload']['route'],
			$attemptEvents
		));
		$this->assertSame([1, 2], array_map(
			static fn (array $event): int => $event['event']['payload']['attempt'],
			$attemptEvents
		));
		$this->assertArrayNotHasKey('http_status', $attemptEvents[0]['event']['payload']);
		$this->assertArrayNotHasKey('fallback_cause', $attemptEvents[0]['event']['payload']);
		$this->assertSame(200, $attemptEvents[1]['event']['payload']['http_status']);
		$this->assertSame('network', $attemptEvents[1]['event']['payload']['fallback_cause']);
		$this->assertStringNotContainsString('secret-host', json_encode($attemptEvents) ?: '');
	}

	public function testProviderBudgetExhaustionIsTypedAndPreventsAnExtraPost(): void {
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
		$client->expects($this->once())
			->method('post')
			->willThrowException(new \Exception('cURL error 28: Operation timed out'));

		$events = [];
		$budget = new ProviderAttemptBudget(1);
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				[],
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 53]
			);
			$this->fail('Expected provider attempt budget exhaustion');
		} catch (ProviderAttemptBudgetExceededException $e) {
			$this->assertSame('Provider attempt budget exhausted', $e->getMessage());
			$this->assertSame(1, $e->getLimit());
			$this->assertSame(1, $e->getConsumed());
		}

		$this->assertSame(1, $budget->getConsumed());
		$this->assertSame(0, $budget->getRemaining());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame(1, $attemptEvents[0]['event']['payload']['attempt']);
		$exhaustionEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt_budget_exhausted'
		));
		$this->assertCount(1, $exhaustionEvents);
		$this->assertSame(2, $exhaustionEvents[0]['event']['payload']['attempt']);
		$this->assertSame('fallback', $exhaustionEvents[0]['event']['payload']['route']);
		$this->assertSame('timeout', $exhaustionEvents[0]['event']['payload']['fallback_cause']);
	}

	public function testColdCacheModelDiscoveryAndAgentTurnShareOneBudgetAndTrace(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('model-b');
		$settings->setLlmModelsTimeout(25);
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function (string $uri, array $options): IResponse {
				$this->assertSame(10.0, $options['timeout']);
				if ($uri === 'https://primary.example.invalid/v1/models') {
					return $this->jsonResponse(['data' => [['id' => 'model-a']]]);
				}
				$this->assertSame('https://secondary.example.invalid/v1/models', $uri);
				return $this->jsonResponse(['data' => [['id' => 'model-b']]]);
			});
		$client->expects($this->once())
			->method('post')
			->with(
				'https://secondary.example.invalid/v1/chat/completions',
				$this->callback(function (array $options): bool {
					$this->assertSame('Bearer secondary-key', $options['headers']['Authorization']);
					$this->assertSame('model-b', $options['json']['model']);
					$this->assertArrayNotHasKey('agent_run_control', $options['json']);
					$this->assertSame(10.0, $options['timeout']);
					return true;
				})
			)
			->willReturn($this->jsonResponse([
				'model' => 'model-b',
				'choices' => [[
					'message' => ['content' => 'secondary answer'],
					'finish_reason' => 'stop',
				]],
			]));

		$events = [];
		$budget = new ProviderAttemptBudget(5);
		$runControl = new AgentRunControl(10, null, static fn (): float => 100.0);
		$options = [
			'provider_attempt_budget' => $budget,
			'agent_run_control' => $runControl,
			'trace_run_id' => 54,
		];
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		$tracePayload = $llmClient->buildTraceChatCompletionPayload('system', [], null, $options);
		$this->assertSame('secondary', $tracePayload['endpoint']);
		$this->assertSame('secondary:model-b', $tracePayload['model_reference']);
		$this->assertSame(2, $budget->getConsumed());

		$cachedTracePayload = $llmClient->buildTraceChatCompletionPayload('system', [], null, $options);
		$this->assertSame($tracePayload, $cachedTracePayload);
		$this->assertSame(2, $budget->getConsumed());

		$turn = $llmClient->sendAgentTurn('system', [], [], null, $options);
		$this->assertSame('secondary answer', $turn->getText());
		$this->assertSame('secondary:model-b', $turn->getModelReference());
		$this->assertSame(3, $budget->getConsumed());

		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(3, $attemptEvents);
		$this->assertSame([54, 54, 54], array_column($attemptEvents, 'run_id'));
		$this->assertSame([1, 2, 3], array_map(
			static fn (array $event): int => $event['event']['payload']['attempt'],
			$attemptEvents
		));
		$this->assertSame(['model_discovery', 'model_discovery', 'selected'], array_map(
			static fn (array $event): string => $event['event']['payload']['route'],
			$attemptEvents
		));
		$this->assertSame(['primary', 'secondary', 'secondary'], array_map(
			static fn (array $event): string => $event['event']['payload']['endpoint_key'],
			$attemptEvents
		));
		$this->assertSame(['model-b', 'model-b', 'secondary:model-b'], array_map(
			static fn (array $event): string => $event['event']['payload']['model_reference'],
			$attemptEvents
		));
		$this->assertStringNotContainsString('primary-key', json_encode($attemptEvents) ?: '');
		$this->assertStringNotContainsString('secondary-key', json_encode($attemptEvents) ?: '');
		$this->assertStringNotContainsString('https://', json_encode($attemptEvents) ?: '');
	}

	public function testTracePreflightDiscoveryFailureIsNotRepeatedBeforeAgentTurn(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('model-a');
		$settings->setLlmModelsTimeout(25);
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with('https://primary.example.invalid/v1/models')
			->willThrowException(new \Exception('secret-discovery-host unavailable'));
		$client->expects($this->once())
			->method('post')
			->with('https://primary.example.invalid/v1/chat/completions')
			->willReturn($this->jsonResponse([
				'model' => 'model-a',
				'choices' => [[
					'message' => ['content' => 'answer'],
					'finish_reason' => 'stop',
				]],
			]));

		$events = [];
		$budget = new ProviderAttemptBudget(2);
		$options = ['provider_attempt_budget' => $budget, 'trace_run_id' => 60];
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		$tracePayload = $llmClient->buildTraceChatCompletionPayload('system', [], null, $options);
		$this->assertSame('primary', $tracePayload['endpoint']);
		$this->assertSame('primary:model-a', $tracePayload['model_reference']);
		$this->assertSame(1, $budget->getConsumed());

		$turn = $llmClient->sendAgentTurn('system', [], [], null, $options);
		$this->assertSame('answer', $turn->getText());
		$this->assertSame('primary:model-a', $turn->getModelReference());
		$this->assertSame(2, $budget->getConsumed());

		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(2, $attemptEvents);
		$this->assertSame(['error', 'ok'], array_column(array_column($attemptEvents, 'event'), 'status'));
		$this->assertSame(['model_discovery', 'selected'], array_map(
			static fn (array $event): string => $event['event']['payload']['route'],
			$attemptEvents
		));
		$this->assertSame([1, 2], array_map(
			static fn (array $event): int => $event['event']['payload']['attempt'],
			$attemptEvents
		));
		$traceJson = json_encode($attemptEvents) ?: '';
		$this->assertStringNotContainsString('secret-discovery-host', $traceJson);
		$this->assertStringNotContainsString('primary-key', $traceJson);
		$this->assertStringNotContainsString('https://', $traceJson);
	}

	public function testTracePreflightDiscoveryErrorIsMemoizedBeforeRethrowAndNotRepeatedByAgentTurn(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('model-a');
		$settings->setLlmModelsTimeout(25);
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$discoveryError = new \Error('secret-discovery-error');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with('https://primary.example.invalid/v1/models')
			->willThrowException($discoveryError);
		$client->expects($this->once())
			->method('post')
			->with('https://primary.example.invalid/v1/chat/completions')
			->willReturn($this->jsonResponse([
				'model' => 'model-a',
				'choices' => [[
					'message' => ['content' => 'answer after trace preflight'],
					'finish_reason' => 'stop',
				]],
			]));

		$events = [];
		$budget = new ProviderAttemptBudget(2);
		$options = ['provider_attempt_budget' => $budget, 'trace_run_id' => 63];
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->buildTraceChatCompletionPayload('system', [], null, $options);
			$this->fail('Expected the original model-discovery Error');
		} catch (\Error $e) {
			$this->assertSame($discoveryError, $e);
		}
		$this->assertSame(1, $budget->getConsumed());

		$turn = $llmClient->sendAgentTurn('system', [], [], null, $options);
		$this->assertSame('answer after trace preflight', $turn->getText());
		$this->assertSame('primary:model-a', $turn->getModelReference());
		$this->assertSame(2, $budget->getConsumed());

		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(2, $attemptEvents);
		$this->assertSame(['error', 'ok'], array_column(array_column($attemptEvents, 'event'), 'status'));
		$this->assertSame(['model_discovery', 'selected'], array_map(
			static fn (array $event): string => $event['event']['payload']['route'],
			$attemptEvents
		));
		$this->assertSame([1, 2], array_map(
			static fn (array $event): int => $event['event']['payload']['attempt'],
			$attemptEvents
		));
		$traceJson = json_encode($attemptEvents) ?: '';
		$this->assertStringNotContainsString('secret-discovery-error', $traceJson);
		$this->assertStringNotContainsString('primary-key', $traceJson);
		$this->assertStringNotContainsString('https://', $traceJson);
	}

	public function testDiscoveryFailureMemoizationDoesNotLeakIntoTheNextRun(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('model-b');
		$settings->setLlmModelsTimeout(25);
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$getCount = 0;
		$postCount = 0;
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(3))
			->method('get')
			->willReturnCallback(function (string $uri) use (&$getCount): IResponse {
				$getCount++;
				if ($getCount === 1) {
					$this->assertSame('https://primary.example.invalid/v1/models', $uri);
					throw new \Exception('first-run discovery failure');
				}
				if ($getCount === 2) {
					$this->assertSame('https://primary.example.invalid/v1/models', $uri);
					return $this->jsonResponse(['data' => [['id' => 'model-a']]]);
				}

				$this->assertSame('https://secondary.example.invalid/v1/models', $uri);
				return $this->jsonResponse(['data' => [['id' => 'model-b']]]);
			});
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function (string $uri) use (&$postCount): IResponse {
				$postCount++;
				$this->assertSame(
					$postCount === 1
						? 'https://primary.example.invalid/v1/chat/completions'
						: 'https://secondary.example.invalid/v1/chat/completions',
					$uri,
				);
				return $this->jsonResponse([
					'model' => 'model-b',
					'choices' => [[
						'message' => ['content' => 'run ' . $postCount],
						'finish_reason' => 'stop',
					]],
				]);
			});

		$events = [];
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		$firstBudget = new ProviderAttemptBudget(2);
		$firstOptions = ['provider_attempt_budget' => $firstBudget, 'trace_run_id' => 61];
		$llmClient->buildTraceChatCompletionPayload('system', [], null, $firstOptions);
		$firstTurn = $llmClient->sendAgentTurn('system', [], [], null, $firstOptions);
		$this->assertSame('run 1', $firstTurn->getText());
		$this->assertSame('primary:model-b', $firstTurn->getModelReference());
		$this->assertSame(2, $firstBudget->getConsumed());

		$secondBudget = new ProviderAttemptBudget(3);
		$secondOptions = ['provider_attempt_budget' => $secondBudget, 'trace_run_id' => 62];
		$llmClient->buildTraceChatCompletionPayload('system', [], null, $secondOptions);
		$secondTurn = $llmClient->sendAgentTurn('system', [], [], null, $secondOptions);
		$this->assertSame('run 2', $secondTurn->getText());
		$this->assertSame('secondary:model-b', $secondTurn->getModelReference());
		$this->assertSame(3, $secondBudget->getConsumed());
		$this->assertSame(3, $getCount);
		$this->assertSame(2, $postCount);
	}

	public function testColdCacheDiscoveryExhaustionPreventsTheNextGetAndChatPost(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('model-b');
		$settings->setLlmModelsTimeout(25);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with('https://primary.example.invalid/v1/models')
			->willReturn($this->jsonResponse(['data' => [['id' => 'model-a']]]));
		$client->expects($this->never())->method('post');

		$events = [];
		$budget = new ProviderAttemptBudget(1);
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				[],
				null,
				['provider_attempt_budget' => $budget, 'trace_run_id' => 55]
			);
			$this->fail('Expected provider attempt budget exhaustion');
		} catch (ProviderAttemptBudgetExceededException $e) {
			$this->assertSame(1, $e->getLimit());
			$this->assertSame(1, $e->getConsumed());
		}

		$this->assertSame(1, $budget->getConsumed());
		$attemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(1, $attemptEvents);
		$this->assertSame('model_discovery', $attemptEvents[0]['event']['payload']['reason']);
		$this->assertSame('primary', $attemptEvents[0]['event']['payload']['endpoint_key']);
		$exhaustionEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt_budget_exhausted'
		));
		$this->assertCount(1, $exhaustionEvents);
		$this->assertSame('model_discovery', $exhaustionEvents[0]['event']['payload']['reason']);
		$this->assertSame('secondary', $exhaustionEvents[0]['event']['payload']['endpoint_key']);
	}

	public function testRunInterruptionPreventsColdCacheDiscoveryAndDoesNotConsumeBudget(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('model-a');

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->never())->method('post');

		$events = [];
		$budget = new ProviderAttemptBudget(2);
		$runControl = new AgentRunControl(30, static fn (): bool => true);
		$llmClient = new LLMClient(
			$this->clientService($client),
			$settingsService,
			$this->logger(),
			null,
			$this->recordingTraceService($events),
		);

		try {
			$llmClient->sendAgentTurn(
				'system',
				[['role' => 'user', 'content' => 'hi']],
				[],
				null,
				[
					'provider_attempt_budget' => $budget,
					'agent_run_control' => $runControl,
					'trace_run_id' => 57,
				]
			);
			$this->fail('Expected the run to be interrupted');
		} catch (AgentRunInterruptedException $e) {
			$this->assertSame(AgentRunInterruptedException::REASON_ABORTED, $e->getReason());
		}

		$this->assertSame(0, $budget->getConsumed());
		$this->assertSame([], $events);
	}

	public function testProviderAttemptBudgetHasNamedDefaultAndHardCap(): void {
		$this->assertSame(24, ProviderAttemptBudget::DEFAULT_LIMIT);
		$this->assertSame(48, ProviderAttemptBudget::HARD_LIMIT);
		$this->assertSame(ProviderAttemptBudget::DEFAULT_LIMIT, (new ProviderAttemptBudget())->getLimit());
		$this->assertSame(ProviderAttemptBudget::HARD_LIMIT, (new ProviderAttemptBudget(500))->getLimit());
	}

	private function assertSameAgentTurn(AgentTurn $expected, AgentTurn $actual): void {
		$this->assertSame($expected->getText(), $actual->getText());
		$this->assertSame($expected->getToolCalls(), $actual->getToolCalls());
		$this->assertSame($expected->getStopReason(), $actual->getStopReason());
		$this->assertSame($expected->getModel(), $actual->getModel());
		$this->assertSame($expected->getModelReference(), $actual->getModelReference());
		$this->assertSame($expected->getModelEndpoint(), $actual->getModelEndpoint());
		$this->assertSame($expected->getUsage(), $actual->getUsage());
		$this->assertSame($expected->getRateLimitHeaders(), $actual->getRateLimitHeaders());
		$this->assertSame($expected->getCompatibilitySource(), $actual->getCompatibilitySource());
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

	private function chatLlmClient(
		IClient $client,
		?LoggerInterface $logger = null,
		?TraceService $traceService = null,
		bool $withFallback = false,
	): LLMClient {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setLlmChatTimeout(90);
		if ($withFallback) {
			$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
			$settings->setFallbackModel('secondary:model-b');
		}

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		if ($withFallback) {
			$settingsService->method('getSecondaryApiKey')->willReturn('secondary-key');
		}
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		return new LLMClient(
			$this->clientService($client),
			$settingsService,
			$logger ?? $this->logger(),
			null,
			$traceService,
		);
	}

	/**
	 * @param array<int,array{run_id:?int,event_type:string,event:array<string,mixed>}> $events
	 */
	private function recordingTraceService(array &$events): TraceService {
		$traceService = $this->createMock(TraceService::class);
		$traceService->method('recordEvent')
			->willReturnCallback(static function (?int $runId, string $eventType, array $event = []) use (&$events): void {
				$events[] = [
					'run_id' => $runId,
					'event_type' => $eventType,
					'event' => $event,
				];
			});

		return $traceService;
	}

	private function clientService(IClient $client): IClientService {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return $clientService;
	}

	private function logger(): LoggerInterface {
		return new class implements LoggerInterface {
			public function emergency($message, array $context = []): void {
			}
			public function alert($message, array $context = []): void {
			}
			public function critical($message, array $context = []): void {
			}
			public function error($message, array $context = []): void {
			}
			public function warning($message, array $context = []): void {
			}
			public function notice($message, array $context = []): void {
			}
			public function info($message, array $context = []): void {
			}
			public function debug($message, array $context = []): void {
			}
			public function log($level, $message, array $context = []): void {
			}
		};
	}
}

class RecordingLogger implements LoggerInterface {
	/** @var array<int,array{message:string,context:array<string,mixed>}> */
	public array $errors = [];
	/** @var array<int,array{message:string,context:array<string,mixed>}> */
	public array $infos = [];

	public function emergency($message, array $context = []): void {
	}
	public function alert($message, array $context = []): void {
	}
	public function critical($message, array $context = []): void {
	}
	public function error($message, array $context = []): void {
		$this->errors[] = ['message' => (string)$message, 'context' => $context];
	}
	public function warning($message, array $context = []): void {
	}
	public function notice($message, array $context = []): void {
	}
	public function info($message, array $context = []): void {
		$this->infos[] = ['message' => (string)$message, 'context' => $context];
	}
	public function debug($message, array $context = []): void {
	}
	public function log($level, $message, array $context = []): void {
	}
}

class FakeProviderHttpException extends \Exception {
	public function __construct(
		private object $response,
		int $code = 400,
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
