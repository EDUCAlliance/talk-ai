<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Exception\ContextLengthExceededException;
use OCA\EducAI\Service\AgentTurn;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ProviderAttemptBudget;
use OCA\EducAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Exercise capacity discovery, caching and the actual outgoing request together. */
class LLMOutputBudgetTest extends TestCase {
	#[DataProvider('requestModes')]
	public function testQualifiedColdRequestsUse32kWithoutFetchingModelLists(bool $stream, string $model, string $parameter): void {
		$settings = $this->settings();
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$sent = null;
		$client->expects($this->once())->method('post')->willReturnCallback(function (string $url, array $request) use ($stream, $parameter, &$sent): IResponse {
			$sent = $request['json'];
			$this->assertSame(32768, $sent[$parameter]);
			$this->assertArrayNotHasKey($parameter === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens', $sent);
			return $this->answer($stream);
		});
		$cache = [];
		$llm = $this->llm($client, $settings, $this->config($cache));
		$messages = [['role' => 'user', 'content' => 'Give a short answer.']];
		$trace = $llm->buildTraceChatCompletionPayload('system', $messages, $model, [], $stream);
		$budget = new ProviderAttemptBudget();
		$turn = $this->turn($llm, $stream, $model, 'system', $messages, ['provider_attempt_budget' => $budget]);
		$this->assertSame('ok', $turn->getText());
		$this->assertSame($sent, $trace['payload']);
		$this->assertSame(1, $budget->getConsumed());
	}

	public static function requestModes(): iterable {
		foreach ([false, true] as $stream) {
			foreach (['primary:model-a' => 'max_tokens', 'secondary:gpt-5-mini' => 'max_completion_tokens'] as $model => $parameter) {
				yield ($stream ? 'stream ' : 'sync ') . $model => [$stream, $model, $parameter];
			}
		}
	}

	public function testProviderMetadataRoundTripsThroughPersistentCacheAndExpiredRoutingTtl(): void {
		$settings = $this->settings();
		$cache = [];
		$config = $this->config($cache);
		$discovery = $this->createMock(IClient::class);
		$discovery->expects($this->exactly(2))->method('get')->willReturnCallback(function (string $url): IResponse {
			return str_contains($url, 'primary.') ? $this->jsonResponse(['data' => [
				['id' => 'model-a', 'max_output_tokens' => 65536, 'max_completion_tokens' => 32768,
					'context_length' => 262144, 'max_model_len' => 131072,
					'top_provider' => ['max_completion_tokens' => '8192', 'context_length' => '65536']],
				['name' => 'context-only', 'max_model_len' => 16384],
			]]) : $this->jsonResponse(['models' => [
				['id' => 'model-a', 'max_output_tokens' => 2048, 'context_length' => 32768],
				'no-metadata',
			]]);
		});
		$discovery->expects($this->never())->method('post');
		$options = $this->llm($discovery, $settings, $config)->listModelOptions();
		$byId = array_column($options, null, 'id');
		$this->assertSame(['output_tokens' => 8192, 'context_tokens' => 65536], $byId['primary:model-a']['limits']);
		$this->assertSame(['context_tokens' => 16384], $byId['primary:context-only']['limits']);
		$this->assertSame(['output_tokens' => 2048, 'context_tokens' => 32768], $byId['secondary:model-a']['limits']);
		$this->assertEmpty($byId['secondary:no-metadata']['limits'] ?? []);
		$stored = json_decode($cache['llm_model_options_cache'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame($options, $stored['options']);
		$stored['expires_at'] = time() - 3600;
		$cache['llm_model_options_cache'] = json_encode($stored, JSON_THROW_ON_ERROR);

		// A new client must retain known capacities even when routing discovery is stale.
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$budgets = [];
		$client->expects($this->exactly(3))->method('post')->willReturnCallback(function (string $url, array $request) use (&$budgets): IResponse {
			$budgets[] = $request['json']['max_tokens'];
			return $this->answer(false);
		});
		$llm = $this->llm($client, $settings, $config);
		foreach (['primary:model-a', 'secondary:model-a', 'secondary:no-metadata'] as $reference) {
			$this->turn($llm, false, $reference);
		}
		$this->assertSame([8192, 2048, 32768], $budgets);
	}

	#[DataProvider('invalidMetadata')]
	public function testInvalidProviderCapacitiesDoNotBecomeZeroOrInventedLimits(mixed $invalid): void {
		$settings = $this->settings(false);
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->willReturn($this->jsonResponse(['data' => [[
			'id' => 'model-a', 'max_output_tokens' => $invalid, 'max_completion_tokens' => $invalid,
			'context_length' => $invalid, 'max_model_len' => $invalid,
			'top_provider' => ['max_completion_tokens' => $invalid, 'context_length' => $invalid],
		]]]));
		$client->expects($this->once())->method('post')->willReturnCallback(function (string $url, array $request): IResponse {
			$this->assertSame(32768, $request['json']['max_tokens']);
			return $this->answer(false);
		});
		$llm = $this->llm($client, $settings);
		$options = $llm->listModelOptions();
		$this->assertEmpty($options[0]['limits'] ?? []);
		$this->turn($llm, false, 'primary:model-a');
	}

	public static function invalidMetadata(): iterable {
		foreach ([null, 0, -1, true, 8192.5, '8192.5', 'unlimited', ['tokens' => 8192], 2147483648, '999999999999999999999'] as $index => $value) {
			yield 'invalid capacity ' . $index => [$value];
		}
	}

	#[DataProvider('cachedIdentityCases')]
	public function testCachedLimitsRequireExactEndpointModelIdentity(array $cachedOption): void {
		$settings = $this->settings();
		$cache = $this->cacheData($settings, [$cachedOption]);
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->once())->method('post')->willReturnCallback(function (string $url, array $request): IResponse {
			$this->assertSame(32768, $request['json']['max_tokens']);
			return $this->answer(false);
		});
		$this->turn($this->llm($client, $settings, $this->config($cache)), false, 'primary:model-a');
	}

	public static function cachedIdentityCases(): iterable {
		yield 'different route' => [['id' => 'secondary:model-a', 'model' => 'model-a', 'endpoint' => 'secondary', 'limits' => ['output_tokens' => 128]]];
		yield 'different model' => [['id' => 'primary:model-a-longer', 'model' => 'model-a-longer', 'endpoint' => 'primary', 'limits' => ['output_tokens' => 128]]];
		yield 'inconsistent route' => [['id' => 'primary:model-a', 'model' => 'model-a', 'endpoint' => 'secondary', 'limits' => ['output_tokens' => 128]]];
		yield 'inconsistent model' => [['id' => 'primary:model-a', 'model' => 'model-b', 'endpoint' => 'primary', 'limits' => ['output_tokens' => 128]]];
		yield 'malformed capacities' => [['id' => 'primary:model-a', 'model' => 'model-a', 'endpoint' => 'primary', 'limits' => ['output_tokens' => false, 'context_tokens' => -1]]];
		yield 'non-map capacities' => [['id' => 'primary:model-a', 'model' => 'model-a', 'endpoint' => 'primary', 'limits' => '128']];
	}

	public function testChangedEndpointsInvalidateMemoryAndPersistentCapacitiesWithoutDiscovery(): void {
		$settings = $this->settings(false);
		$cache = [];
		$config = $this->config($cache);
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->willReturn($this->jsonResponse(['data' => [
			['id' => 'model-a', 'max_output_tokens' => 2048],
		]]));
		$seen = [];
		$client->expects($this->exactly(3))->method('post')->willReturnCallback(function (string $url, array $request) use (&$seen): IResponse {
			$seen[] = [$url, $request['json']['max_tokens']];
			return $this->answer(false);
		});
		$llm = $this->llm($client, $settings, $config);
		$llm->listModelOptions();
		$this->turn($llm, false, 'primary:model-a');
		$settings->setApiEndpoint('https://replacement.example.invalid/v1/chat/completions');
		$this->turn($llm, false, 'primary:model-a');
		$this->turn($this->llm($client, $settings, $config), false, 'primary:model-a');
		$this->assertSame([
			['https://primary.example.invalid/v1/chat/completions', 2048],
			['https://replacement.example.invalid/v1/chat/completions', 32768],
			['https://replacement.example.invalid/v1/chat/completions', 32768],
		], $seen);
	}

	public function testExplicitModelRefreshReplacesAndRemovesPreviouslyKnownCapacities(): void {
		$settings = $this->settings(false);
		$cache = [];
		$config = $this->config($cache);
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(4))->method('get')->willReturnOnConsecutiveCalls(
			$this->jsonResponse(['data' => [['id' => 'model-a', 'max_output_tokens' => 2048]]]),
			$this->jsonResponse(['data' => [['id' => 'model-a', 'max_output_tokens' => 8192]]]),
			$this->jsonResponse(['data' => [['id' => 'model-a']]]),
			$this->jsonResponse(['data' => []]),
		);
		$budgets = [];
		$client->expects($this->exactly(4))->method('post')->willReturnCallback(function (string $url, array $request) use (&$budgets): IResponse {
			$budgets[] = $request['json']['max_tokens'];
			return $this->answer(false);
		});
		$llm = $this->llm($client, $settings, $config);
		for ($i = 0; $i < 4; $i++) {
			$llm->listModelOptions();
			$this->turn($llm, false, 'primary:model-a');
		}
		$this->assertSame([2048, 8192, 32768, 32768], $budgets);
		$this->assertSame([], json_decode($cache['llm_model_options_cache'], true, 512, JSON_THROW_ON_ERROR)['options']);
	}

	#[DataProvider('cappedRequests')]
	public function testActualAndTracePayloadsRespectConfiguredExplicitAndModelCaps(bool $stream, string $model, string $parameter, int $configured, array $options, int $cap, int $expected): void {
		$settings = $this->settings();
		[$endpoint, $name] = explode(':', $model, 2);
		$cache = $this->cacheData($settings, [['id' => $model, 'model' => $name, 'endpoint' => $endpoint, 'limits' => ['output_tokens' => $cap]]]);
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$sent = null;
		$client->expects($this->once())->method('post')->willReturnCallback(function (string $url, array $request) use ($stream, $parameter, $expected, &$sent): IResponse {
			$sent = $request['json'];
			$this->assertSame($expected, $sent[$parameter]);
			$this->assertArrayNotHasKey('_use_configured_output_budget', $sent);
			return $this->answer($stream);
		});
		$llm = $this->llm($client, $settings, $this->config($cache), [$model => $configured]);
		$messages = [['role' => 'user', 'content' => 'Hi']];
		$trace = $llm->buildTraceChatCompletionPayload('system', $messages, $model, $options, $stream);
		$this->turn($llm, $stream, $model, 'system', $messages, $options);
		$this->assertSame($sent, $trace['payload']);
	}

	public static function cappedRequests(): iterable {
		foreach (self::requestModes() as $mode => [$stream, $model, $parameter]) {
			foreach ([
				'provider cap' => [32768, [], 8192, 8192],
				'explicit smaller budget' => [32768, ['max_tokens' => 128], 8192, 128],
				'explicit larger budget' => [32768, ['max_tokens' => 65536], 8192, 8192],
				'configured smaller budget' => [512, [], 8192, 512],
				'configured reasoning headroom' => [65536, ['max_tokens' => 128, '_use_configured_output_budget' => true], 49152, 49152],
			] as $case => $args) {
				yield $mode . ' ' . $case => [$stream, $model, $parameter, ...$args];
			}
		}
	}

	#[DataProvider('requestModes')]
	public function testSystemHistoryAndNormalizedToolSchemasConsumeContextBeforeOutput(bool $stream, string $model, string $parameter): void {
		$settings = $this->settings();
		[$endpoint, $name] = explode(':', $model, 2);
		$cache = $this->cacheData($settings, [['id' => $model, 'model' => $name, 'endpoint' => $endpoint,
			'limits' => ['output_tokens' => 8192, 'context_tokens' => 6000]]]);
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$sent = null;
		$client->expects($this->once())->method('post')->willReturnCallback(function (string $url, array $request) use ($stream, &$sent): IResponse {
			$sent = $request['json'];
			return $this->answer($stream);
		});
		$llm = $this->llm($client, $settings, $this->config($cache));
		$messages = [['role' => 'user', 'content' => 'Hi']];
		$system = str_repeat('Rules 🧪 / ', 50);
		$history = [['role' => 'user', 'content' => 'previous'], ['role' => 'assistant', 'content' => str_repeat('Earlier answer. ', 50)], ...$messages];
		$tools = [['type' => 'function', 'function' => ['name' => 'lookup', 'description' => str_repeat('Tool 🧪 / ', 40),
			'parameters' => ['type' => 'object', 'properties' => []]]]];
		$baseline = $llm->buildTraceChatCompletionPayload('system', $messages, $model, [], $stream)['payload'][$parameter];
		foreach ([[$system, $messages, []], ['system', $history, []], ['system', $messages, ['tools' => $tools]]] as [$prompt, $input, $options]) {
			$this->assertLessThan($baseline, $llm->buildTraceChatCompletionPayload($prompt, $input, $model, $options, $stream)['payload'][$parameter]);
		}
		$trace = $llm->buildTraceChatCompletionPayload($system, $history, $model, ['tools' => $tools], $stream);
		$this->turn($llm, $stream, $model, $system, $history, ['tools' => $tools]);
		$this->assertSame(json_encode($sent, JSON_THROW_ON_ERROR), json_encode($trace['payload'], JSON_THROW_ON_ERROR));
		$this->assertIsObject($sent['tools'][0]['function']['parameters']['properties']);
		$inputBytes = strlen(json_encode(['messages' => $sent['messages'], 'tools' => $sent['tools']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$this->assertSame(6000 - $inputBytes - 1024, $sent[$parameter]);
		$this->assertGreaterThan(0, $sent[$parameter]);
		$this->assertLessThan($baseline, $sent[$parameter]);
	}

	#[DataProvider('oversizedRequests')]
	public function testExhaustedContextStopsBeforeAnyRequestOrFallback(bool $stream, string $part): void {
		$settings = $this->settings();
		$settings->setFallbackModel('secondary:gpt-5-mini');
		$cache = $this->cacheData($settings, [['id' => 'primary:model-a', 'model' => 'model-a', 'endpoint' => 'primary',
			'limits' => ['context_tokens' => 2048]]]);
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->never())->method('post');
		$budget = new ProviderAttemptBudget();
		$options = ['provider_attempt_budget' => $budget];
		$system = $part === 'system' ? str_repeat('rules ', 1000) : 'system';
		$messages = [['role' => 'user', 'content' => $part === 'history' ? str_repeat('input ', 1000) : 'Hi']];
		if ($part === 'tools') {
			$options['tools'] = [['type' => 'function', 'function' => ['name' => 'lookup', 'description' => str_repeat('schema ', 1000),
				'parameters' => ['type' => 'object', 'properties' => []]]]];
		}
		$llm = $this->llm($client, $settings, $this->config($cache));
		try {
			$this->turn($llm, $stream, 'primary:model-a', $system, $messages, $options);
			$this->fail('Expected the known context limit to reject the request before network access.');
		} catch (ContextLengthExceededException $e) {
			$this->assertSame(ContextLengthExceededException::MESSAGE, $e->getMessage());
			$this->assertSame(0, $e->getCode());
			$this->assertNull($e->getPrevious());
		}
		$this->assertSame(0, $budget->getConsumed());
	}

	public static function oversizedRequests(): iterable {
		foreach ([false, true] as $stream) {
			foreach (['system', 'history', 'tools'] as $part) {
				yield ($stream ? 'stream ' : 'sync ') . $part => [$stream, $part];
			}
		}
	}

	#[DataProvider('fallbackRequests')]
	public function testFallbackResolvesItsOwnConfiguredBudgetAndProviderCap(bool $stream, int $secondaryCap): void {
		$settings = $this->settings();
		$settings->setFallbackModel('secondary:gpt-5-mini');
		$cache = $this->cacheData($settings, [
			['id' => 'primary:model-a', 'model' => 'model-a', 'endpoint' => 'primary', 'limits' => ['output_tokens' => 8192]],
			['id' => 'secondary:gpt-5-mini', 'model' => 'gpt-5-mini', 'endpoint' => 'secondary', 'limits' => ['output_tokens' => $secondaryCap]],
		]);
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$sent = [];
		$client->expects($this->exactly(2))->method('post')->willReturnCallback(function (string $url, array $request) use ($stream, &$sent): IResponse {
			$sent[] = ['url' => $url, 'payload' => $request['json']];
			if (count($sent) === 1) {
				throw new \RuntimeException('cURL error 28: Operation timed out');
			}
			return $this->answer($stream);
		});
		$llm = $this->llm($client, $settings, $this->config($cache), ['primary:model-a' => 32768, 'secondary:gpt-5-mini' => 65536]);
		$budget = new ProviderAttemptBudget();
		$options = ['max_tokens' => 32768, '_use_configured_output_budget' => true, 'provider_attempt_budget' => $budget];
		$turn = $this->turn($llm, $stream, 'primary:model-a', 'system', [['role' => 'user', 'content' => 'Hi']], $options);
		$this->assertSame('secondary:gpt-5-mini', $turn->getModelReference());
		$this->assertSame(8192, $sent[0]['payload']['max_tokens']);
		$this->assertSame($secondaryCap, $sent[1]['payload']['max_completion_tokens']);
		$this->assertArrayNotHasKey('max_tokens', $sent[1]['payload']);
		$this->assertSame('https://secondary.example.invalid/v1/chat/completions', $sent[1]['url']);
		$this->assertSame(2, $budget->getConsumed());
	}

	public static function fallbackRequests(): iterable {
		foreach ([false, true] as $stream) {
			foreach ([2048, 49152] as $cap) {
				yield ($stream ? 'stream ' : 'sync ') . $cap => [$stream, $cap];
			}
		}
	}

	private function settings(bool $secondary = true): Settings {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:model-a');
		$settings->setSecondaryApiEndpoint($secondary ? 'https://secondary.example.invalid/v1/chat/completions' : null);
		return $settings;
	}

	private function llm(IClient $client, Settings $settings, ?IConfig $config = null, array $budgets = []): LLMClient {
		$service = $this->createMock(SettingsService::class);
		$service->method('getSettings')->willReturn($settings);
		$service->method('getApiKey')->willReturn('synthetic-primary-key');
		$service->method('getSecondaryApiKey')->willReturn('synthetic-secondary-key');
		$service->method('getMaxOutputTokens')->willReturnCallback(static fn (?string $model): int => $budgets[$model] ?? 32768);
		$service->method('normalizePositiveInteger')->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		return new LLMClient($clients, $service, $this->createMock(LoggerInterface::class), $config);
	}

	private function turn(LLMClient $llm, bool $stream, string $model, string $system = 'system', array $messages = [['role' => 'user', 'content' => 'Hi']], array $options = []): AgentTurn {
		return $stream ? $llm->streamAgentTurn($system, $messages, [], static function (): void {}, $model, $options)
			: $llm->sendAgentTurn($system, $messages, [], $model, $options);
	}

	private function cacheData(Settings $settings, array $options): array {
		return ['llm_model_options_cache' => json_encode([
			'fingerprint' => sha1(implode('|', [
				trim((string)$settings->getApiProvider()),
				rtrim(trim((string)$settings->getApiEndpoint()), '/'),
				rtrim(trim((string)$settings->getSecondaryApiEndpoint()), '/'),
			])),
			'expires_at' => time() + 300,
			'options' => $options,
		], JSON_THROW_ON_ERROR)];
	}

	private function config(array &$cache): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$cache): string {
			return $cache[$key] ?? $default;
		});
		$config->method('setAppValue')->willReturnCallback(static function (string $app, string $key, string $value) use (&$cache): void {
			self::assertSame(Application::APP_ID, $app);
			$cache[$key] = $value;
		});
		return $config;
	}

	private function answer(bool $stream): IResponse {
		return $stream ? $this->rawResponse("data: {\"choices\":[{\"delta\":{\"content\":\"ok\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n")
			: $this->jsonResponse(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);
	}

	private function jsonResponse(array $body): IResponse {
		return $this->rawResponse(json_encode($body, JSON_THROW_ON_ERROR));
	}

	private function rawResponse(string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeader')->willReturn('');
		return $response;
	}
}
