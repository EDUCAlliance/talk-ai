<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\ConnectionCheckStore;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ModelCatalogueTest extends TestCase {
	private Settings $settings;
	private array $keys;
	private array $storage;
	private array $calls;
	private array $models;
	private array $failures;
	private array $keyFailures;
	private IClient $http;
	private SettingsService $settingsService;
	private IConfig $config;

	protected function setUp(): void {
		$this->settings = new Settings();
		$this->settings->setApiProvider('custom');
		$this->settings->setApiEndpoint('https://primary.invalid/v1/chat/completions');
		$this->settings->setSecondaryApiEndpoint('https://secondary.invalid/v1/chat/completions');
		$this->settings->setDefaultModel('primary:alpha');
		$this->settings->setFallbackModel('secondary:beta');
		$this->settings->setAllowedModels('["primary:alpha"]');
		$this->keys = ['primary' => 'primary-test-key', 'secondary' => 'secondary-test-key'];
		$this->storage = [];
		$this->calls = [];
		$this->models = ['primary' => ['alpha'], 'secondary' => ['beta']];
		$this->failures = [];
		$this->keyFailures = [];
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getSettings')->willReturn($this->settings);
		$this->settingsService->method('getApiKey')->willReturnCallback(function (): string { if (isset($this->keyFailures['primary'])) { throw $this->keyFailures['primary']; } return $this->keys['primary']; });
		$this->settingsService->method('getSecondaryApiKey')->willReturnCallback(function (): string { if (isset($this->keyFailures['secondary'])) { throw $this->keyFailures['secondary']; } return $this->keys['secondary']; });
		$this->settingsService->method('getMaxOutputTokens')->willReturn(4096);
		$this->settingsService->method('normalizePositiveInteger')->willReturnCallback(static fn (?int $value, int $fallback): int => $value ?: $fallback);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->storage[$key] ?? $default);
		$this->config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void { $this->storage[$key] = $value; });
		$this->http = $this->createMock(IClient::class);
		$this->http->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
			$id = str_contains($url, 'secondary') ? 'secondary' : 'primary';
			$this->calls[] = $id;
			$this->assertSame('Bearer ' . $this->keys[$id], $options['headers']['Authorization']);
			if (isset($this->failures[$id])) {
				throw $this->failures[$id];
			}
			return $this->response(['data' => array_map(static fn (string $model): array => ['id' => $model], $this->models[$id])]);
		});
	}

	public function testSecondaryFailureRetainsHealthyPrimaryAndDoesNotGrantAccess(): void {
		$this->failures['secondary'] = new \Exception('private URL https://secondary.invalid secret secondary-test-key', 401);
		$client = $this->client();
		$catalogue = $client->getModelCatalogue();
		$this->assertSame(['primary:alpha'], $catalogue['models']);
		$this->assertSame('ready', $catalogue['providers'][0]['status']);
		$this->assertSame('error', $catalogue['providers'][1]['status']);
		$this->assertSame('Authentication failed. Check the saved provider credentials.', $catalogue['providers'][1]['error']);
		$this->assertSame(300, $catalogue['refresh_interval_seconds']);
		$this->assertSame($catalogue, $client->getModelCatalogue());
		$this->assertSame(['primary', 'secondary'], $this->calls);
		$this->assertSame('["primary:alpha"]', $this->settings->getAllowedModels());
		$this->assertSame('primary:alpha', $this->settings->getDefaultModel());
		$this->assertSame('secondary:beta', $this->settings->getFallbackModel());
		$this->assertStringNotContainsString('secondary.invalid', json_encode($catalogue));
		$this->assertStringNotContainsString('secondary-test-key', json_encode($catalogue));
	}

	public function testOutageRetainsStaleListAndManualRefreshRecovers(): void {
		$client = $this->client();
		$ready = $client->getModelCatalogue();
		$this->failures['secondary'] = new \Exception('unavailable', 503);
		$this->models['primary'][] = 'new-model';
		$stale = $client->getModelCatalogue(true);
		$this->assertSame(['primary:alpha', 'primary:new-model', 'secondary:beta'], $stale['models']);
		$this->assertSame('stale', $stale['providers'][1]['status']);
		$this->assertSame($ready['providers'][1]['last_success_at'], $stale['providers'][1]['last_success_at']);
		$this->assertSame('stale', $stale['model_options'][2]['availability']);
		$this->assertSame(60, $stale['providers'][1]['next_refresh_at'] - $stale['providers'][1]['checked_at']);
		$this->assertSame($stale, $this->client()->getModelCatalogue());
		$this->assertCount(4, $this->calls);
		unset($this->failures['secondary']);
		$this->models['secondary'] = ['replacement'];
		$recovered = $client->getModelCatalogue(true);
		$this->assertSame('ready', $recovered['providers'][1]['status']);
		$this->assertNull($recovered['providers'][1]['error']);
		$this->assertNotContains('secondary:beta', $recovered['models']);
		$this->assertContains('secondary:replacement', $recovered['models']);
		// Removing a catalogue entry does not silently replace a selected model.
		$this->assertSame('secondary:beta', $this->settings->getFallbackModel());
	}

	public function testCredentialAndEndpointChangesInvalidateOnlyTheirProvider(): void {
		$client = $this->client();
		$client->getModelCatalogue();
		$this->keys['primary'] = 'rotated-primary-test-key';
		$this->failures['primary'] = new \Exception('denied', 403);
		$changed = $client->getModelCatalogue();
		$this->assertSame(['secondary:beta'], $changed['models']);
		$this->assertSame('error', $changed['providers'][0]['status']);
		$this->assertNull($changed['providers'][0]['last_success_at']);
		$this->assertSame(['primary', 'secondary', 'primary'], $this->calls);
		$this->settings->setSecondaryApiEndpoint('https://new-secondary.invalid/v1/chat/completions');
		$this->models['secondary'] = ['new-host-model'];
		$changed = $client->getModelCatalogue();
		$this->assertSame(['secondary:new-host-model'], $changed['models']);
		$this->assertSame(['primary', 'secondary', 'primary', 'secondary'], $this->calls);
		$this->assertStringNotContainsString('rotated-primary-test-key', json_encode($this->storage));
	}

	public function testAutomaticExpiryAndFailureCooldownAreProviderSpecific(): void {
		$this->failures['secondary'] = new \Exception('unavailable');
		$this->client()->getModelCatalogue();
		$this->client()->getModelCatalogue();
		$this->assertCount(2, $this->calls);
		$this->expire('secondary');
		unset($this->failures['secondary']);
		$recovered = $this->client()->getModelCatalogue();
		$this->assertSame(['primary', 'secondary', 'secondary'], $this->calls);
		$this->assertSame('ready', $recovered['providers'][1]['status']);
		$this->expire('primary');
		$this->models['primary'][] = 'automatic-new';
		$refreshed = $this->client()->getModelCatalogue();
		$this->assertSame(['primary', 'secondary', 'secondary', 'primary'], $this->calls);
		$this->assertContains('primary:automatic-new', $refreshed['models']);
	}

	public function testPinnedSecondarySurvivesOutageRestartKeyRotationAndModelRemovalWithoutDiscovery(): void {
		$client = $this->client();
		$this->assertSame('secondary:beta', $client->canonicalizeModelReference('beta'));
		$this->assertCount(2, $this->calls);
		$this->models['primary'] = ['alpha', 'beta'];
		$this->models['secondary'] = [];
		$client->getModelCatalogue(true);
		$this->failures = ['primary' => new \Exception('offline'), 'secondary' => new \Exception('offline')];
		$this->expire('primary');
		$this->expire('secondary');
		$this->keys['secondary'] = 'rotated-secondary-key';
		$this->http->expects($this->once())->method('post')->willReturnCallback(function (string $url, array $options): IResponse {
			$this->assertSame('https://secondary.invalid/v1/chat/completions', $url);
			$this->assertSame('Bearer rotated-secondary-key', $options['headers']['Authorization']);
			$this->assertSame('beta', $options['json']['model']);
			return $this->response(['choices' => [['message' => ['content' => 'still secondary'], 'finish_reason' => 'stop']]]);
		});
		$result = $this->client()->sendChatCompletion('system', [['role' => 'user', 'content' => 'test']], 'beta');
		$this->assertSame('secondary:beta', $result['model_reference']);
		$this->assertCount(4, $this->calls);
	}

	public function testPartialColdDiscoveryKeepsPrimaryRoutingAndPinsOnlyAfterRecovery(): void {
		$this->failures['primary'] = new \Exception('offline');
		$client = $this->client();
		$this->http->expects($this->exactly(2))->method('post')->willReturnCallback(function (string $url, array $options): IResponse {
			$this->assertSame('https://primary.invalid/v1/chat/completions', $url);
			$this->assertSame('beta', $options['json']['model']);
			return $this->response(['choices' => [['message' => ['content' => 'as before'], 'finish_reason' => 'stop']]]);
		});
		for ($i = 0; $i < 2; $i++) {
			$this->assertSame('primary:beta', $client->sendChatCompletion('', [], 'beta')['model_reference']);
		}
		$this->assertCount(2, $this->calls);
		$this->assertArrayNotHasKey('llm_model_reference_bindings', $this->storage);
		unset($this->failures['primary']);
		$client->getModelCatalogue(true);
		$this->assertSame('secondary:beta', $client->canonicalizeModelReference('beta'));
		$this->assertCount(4, $this->calls);
	}

	public function testAmbiguousAndMissingReferencesAreNotPinnedAndAnUnkeyedProviderCannotContradict(): void {
		$this->models['primary'][] = 'beta';
		$client = $this->client();
		foreach (['beta', 'missing'] as $reference) {
			try {
				$client->canonicalizeModelReference($reference);
				$this->fail('Expected explicit selection requirement');
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString('Select an explicit', $e->getMessage());
			}
		}
		$this->keys['secondary'] = '';
		$this->assertSame('primary:alpha', $this->client()->canonicalizeModelReference('alpha'));
	}

	public function testBindingsFromWeakerEvidenceAreDroppedAndProvenOnesStoreOnlyTheSlot(): void {
		$this->storage['llm_model_reference_bindings'] = json_encode(['beta' => ['endpoint' => 'primary', 'fingerprint' => 'stale']]);
		$client = $this->client();
		$this->assertSame('secondary:beta', $client->canonicalizeModelReference('beta'));
		$this->assertSame('{"beta":"secondary"}', $this->storage['llm_model_reference_bindings']);
	}

	public function testOldCacheWithoutCredentialFingerprintCannotLeakIntoNewCatalogue(): void {
		$this->storage['llm_model_options_cache'] = json_encode([
			'fingerprint' => sha1('custom|https://primary.invalid/v1/chat/completions|https://secondary.invalid/v1/chat/completions'),
			'expires_at' => time() + 300,
			'options' => [['id' => 'primary:old-private-model', 'model' => 'old-private-model', 'endpoint' => 'primary']],
		]);
		$this->failures['primary'] = new \Exception('new credential denied');
		$catalogue = $this->client()->getModelCatalogue();
		$this->assertSame(['secondary:beta'], $catalogue['models']);
		$this->assertSame('error', $catalogue['providers'][0]['status']);
		$this->assertSame(['primary', 'secondary'], $this->calls);
	}

	public function testExplicitReferencesAndInheritanceDoNotDependOnDiscovery(): void {
		$client = $this->client();
		$this->assertNull($client->canonicalizeModelReference(null));
		$this->assertNull($client->canonicalizeModelReference(''));
		$this->assertSame('secondary:not-listed', $client->canonicalizeModelReference('secondary:not-listed'));
		$this->assertSame([], $this->calls);
	}

	public function testInferenceProbeUsesOneBoundedRequestAndNeverFallback(): void {
		$this->http->expects($this->once())->method('post')->willReturnCallback(function (string $url, array $options): IResponse {
			$this->assertSame('https://primary.invalid/v1/chat/completions', $url);
			$this->assertSame('alpha', $options['json']['model']);
			$this->assertSame(32, $options['json']['max_tokens']);
			throw new \Exception('quota exhausted', 402);
		});
		$this->expectExceptionMessage('quota exhausted');
		try {
			$this->client()->testModelConnection('primary:alpha');
		} finally {
			$this->assertSame([], $this->calls);
		}
	}

	public function testSuccessfulInferenceProbeDoesNotExposeResponseText(): void {
		$this->http->expects($this->once())->method('post')->willReturn($this->response(['choices' => [['message' => ['content' => 'private provider text'], 'finish_reason' => 'stop']]]));
		$result = $this->client()->testModelConnection('secondary:beta');
		$this->assertTrue($result['success']);
		$this->assertSame('secondary:beta', $result['model']);
		$this->assertArrayHasKey('latency_ms', $result);
		$this->assertStringNotContainsString('private provider text', json_encode($result));
		$this->assertSame([], $this->calls);
	}

	public function testUnreadableCredentialDoesNotBreakHealthyProviderDiscoveryChatOrProbe(): void {
		$this->keyFailures['secondary'] = new \RuntimeException('cannot decrypt secret-secondary-key');
		$client = $this->client();
		$catalogue = $client->getModelCatalogue();
		$this->assertSame(['primary:alpha'], $catalogue['models']);
		$this->assertSame('error', $catalogue['providers'][1]['status']);
		$this->assertStringNotContainsString('secret-secondary-key', json_encode($catalogue));
		$this->http->expects($this->exactly(2))->method('post')->willReturnCallback(function (string $url): IResponse {
			$this->assertSame('https://primary.invalid/v1/chat/completions', $url);
			return $this->response(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]]);
		});
		$this->assertSame('primary:alpha', $client->sendChatCompletion('', [], 'primary:alpha')['model_reference']);
		$this->assertTrue($client->testModelConnection('primary:alpha')['success']);
		$this->assertSame(['primary'], $this->calls);
	}

	public function testUnreadablePrimaryCredentialCannotBreakExplicitSecondary(): void {
		$this->keyFailures['primary'] = new \RuntimeException('cannot decrypt secret-primary-key');
		$client = $this->client();
		$catalogue = $client->getModelCatalogue();
		$this->assertSame(['secondary:beta'], $catalogue['models']);
		$this->assertSame('error', $catalogue['providers'][0]['status']);
		$this->http->expects($this->once())->method('post')->willReturnCallback(function (string $url): IResponse {
			$this->assertSame('https://secondary.invalid/v1/chat/completions', $url);
			return $this->response(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]]);
		});
		$this->assertSame('secondary:beta', $client->sendChatCompletion('', [], 'secondary:beta')['model_reference']);
		$this->assertSame(['secondary'], $this->calls);
	}

	public function testModelReferenceLengthAndControlCharactersAreRejectedBeforeNetworkUse(): void {
		$client = $this->client();
		$this->http->expects($this->never())->method('post');
		$maximum = 'secondary:' . str_repeat('x', 502);
		$this->assertSame($maximum, $client->canonicalizeModelReference($maximum));
		foreach ([$maximum . 'x', "primary:alpha\n", "primary:al\0pha", "\tprimary:alpha"] as $invalid) {
			foreach (['canonicalizeModelReference', 'testModelConnection'] as $method) {
				try {
					$client->$method($invalid);
					$this->fail('Invalid reference should not be accepted');
				} catch (\InvalidArgumentException $e) {
					$this->assertStringContainsString('512 bytes', $e->getMessage());
				}
			}
		}
		$this->assertSame([], $this->calls);
	}

	public function testFirstSuccessfulListIsABaselineAndOnlyLaterAdditionsAreMarkedNew(): void {
		$client = $this->client();
		$baseline = $client->getModelCatalogue();
		$this->assertSame(['primary:alpha' => null, 'secondary:beta' => null], $this->firstSeen($baseline));

		$this->models['primary'][] = 'gamma';
		$before = time();
		$refreshed = $this->firstSeen($client->getModelCatalogue(true));
		$this->assertNull($refreshed['primary:alpha']);
		$this->assertNull($refreshed['secondary:beta']);
		$this->assertGreaterThanOrEqual($before, $refreshed['primary:gamma']);
		$this->assertLessThanOrEqual(time(), $refreshed['primary:gamma']);

		// Later refreshes and other processes keep the first sighting.
		$this->expire('primary');
		$this->assertSame($refreshed, $this->firstSeen($this->client()->getModelCatalogue()));
		$this->assertSame(['primary', 'secondary', 'primary', 'secondary', 'primary'], $this->calls);
		$stored = json_decode($this->storage['llm_model_catalogue_primary'], true);
		$this->assertSame([null, $refreshed['primary:gamma']], array_column($stored['options'], 'first_seen_at'));
	}

	public function testFirstSeenAtSurvivesOutagesAndRestartsWithANewBaselineAfterACredentialChange(): void {
		$client = $this->client();
		$client->getModelCatalogue();
		$this->models['secondary'][] = 'delta';
		$firstSeen = $this->firstSeen($client->getModelCatalogue(true))['secondary:delta'];
		$this->assertIsInt($firstSeen);

		$this->failures['secondary'] = new \Exception('unavailable', 503);
		$stale = $client->getModelCatalogue(true);
		$this->assertSame('stale', $stale['providers'][1]['status']);
		$this->assertSame($firstSeen, $this->firstSeen($stale)['secondary:delta']);

		unset($this->failures['secondary']);
		$this->keys['secondary'] = 'rotated-secondary-key';
		$this->models['secondary'][] = 'epsilon';
		$rotated = $this->firstSeen($client->getModelCatalogue());
		$this->assertSame(['primary:alpha' => null, 'secondary:beta' => null, 'secondary:delta' => null, 'secondary:epsilon' => null], $rotated);
	}

	public function testCachedFirstSeenAtIsPreservedAndMalformedValuesAreIgnored(): void {
		$this->storage['llm_model_catalogue_primary'] = json_encode([
			'fingerprint' => ConnectionCheckStore::fingerprint('provider', ['https://primary.invalid/v1/models'], 'primary-test-key'),
			'options' => [
				['id' => 'primary:a', 'model' => 'a', 'endpoint' => 'primary', 'first_seen_at' => 1700000000],
				['id' => 'primary:b', 'model' => 'b', 'endpoint' => 'primary', 'first_seen_at' => 'yesterday'],
				['id' => 'primary:c', 'model' => 'c', 'endpoint' => 'primary', 'first_seen_at' => -5],
				['id' => 'primary:d', 'model' => 'd', 'endpoint' => 'primary'],
			],
			'last_success_at' => time(), 'checked_at' => time(), 'next_refresh_at' => time() + 300, 'status' => 'ready', 'error' => null,
		]);
		$catalogue = $this->client()->getModelCatalogue();
		$this->assertSame(['primary:a' => 1700000000, 'primary:b' => null, 'primary:c' => null, 'primary:d' => null, 'secondary:beta' => null],
			$this->firstSeen($catalogue));
		$this->assertSame(['secondary'], $this->calls);
		foreach ($catalogue['model_options'] as $option) {
			$this->assertArrayHasKey('first_seen_at', $option);
		}
	}

	public function testPlaceholderEntriesAreNoModelsAndMarkAProviderWithoutAList(): void {
		// LiteLLM team keys list "all-team-models" instead of their models.
		$this->models['primary'] = ['all-team-models'];
		$this->models['secondary'] = ['beta', 'openai/*', '*', 'ALL-PROXY-MODELS'];
		$catalogue = $this->client()->getModelCatalogue();
		$this->assertSame(['secondary:beta'], $catalogue['models']);
		$this->assertSame('ready', $catalogue['providers'][0]['status']);
		$this->assertSame(0, $catalogue['providers'][0]['model_count']);
		$this->assertFalse($catalogue['providers'][0]['lists_models']);
		$this->assertTrue($catalogue['providers'][1]['lists_models']);
		// Choices are never touched by discovery.
		$this->assertSame('primary:alpha', $this->settings->getDefaultModel());
		$this->assertSame('["primary:alpha"]', $this->settings->getAllowedModels());

		// An empty list is still a list.
		$this->models['primary'] = [];
		$this->assertTrue($this->client()->getModelCatalogue(true)['providers'][0]['lists_models']);
	}

	/**
	 * @return array<string,?int>
	 */
	private function firstSeen(array $catalogue): array {
		return array_column($catalogue['model_options'], 'first_seen_at', 'id');
	}

	private function expire(string $provider): void {
		$key = 'llm_model_catalogue_' . $provider;
		$record = json_decode($this->storage[$key], true);
		$record['next_refresh_at'] = time() - 1;
		$this->storage[$key] = json_encode($record);
	}

	private function client(): LLMClient {
		$factory = $this->createMock(IClientService::class);
		$factory->method('newClient')->willReturn($this->http);
		return new LLMClient($factory, $this->settingsService, $this->createMock(LoggerInterface::class), $this->config);
	}

	private function response(array $payload): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode($payload));
		$response->method('getHeader')->willReturn('');
		return $response;
	}
}
