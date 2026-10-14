<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\CatalogueClient;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\VisionClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A remembered check is current when the saved settings resolve to the same
 * effective endpoint, options and key that the test used, including unsaved
 * values that were tested and saved afterwards.
 */
class ConnectionCheckTargetTest extends TestCase {
	private Settings $settings;
	private array $vision;
	private array $speech;
	private string $mainKey;
	private ?string $secondaryKey;

	protected function setUp(): void {
		$this->settings = new Settings();
		$this->settings->setApiProvider('custom');
		$this->settings->setApiEndpoint('https://main.example/v1/chat/completions');
		$this->settings->setSecondaryApiEndpoint('https://secondary.example/v1/chat/completions');
		$this->settings->setCatalogueApiEndpoint('https://catalogue.example/api/');
		$this->vision = ['enabled' => true, 'endpoint' => null, 'api_key' => null, 'model' => 'vision-model'];
		$this->speech = ['enabled' => true, 'endpoint' => null, 'api_key' => null, 'model' => 'whisper'];
		$this->mainKey = 'main-key';
		$this->secondaryKey = 'secondary-key';
	}

	public function testVisionTestOfUnsavedValuesBecomesCurrentOnlyOnceExactlyThoseValuesAreSaved(): void {
		$client = new VisionClient($this->http(), $this->settingsService(), $this->createMock(LoggerInterface::class));
		$tested = $client->connectionCheckTarget('https://vision.example/v1/chat/completions/', 'typed-vision-key', 'vision-large');
		$this->assertSame('vision-large', $tested['summary']);
		$this->assertNotSame($tested['fingerprint'], $client->connectionCheckTarget()['fingerprint'], 'Not saved yet');

		$this->vision = ['enabled' => true, 'endpoint' => 'https://vision.example/v1/chat/completions', 'api_key' => 'typed-vision-key', 'model' => 'vision-large'];
		$this->assertSame($tested, $client->connectionCheckTarget(), 'Saved exactly as tested');

		$this->vision['api_key'] = 'rotated-key';
		$this->assertNotSame($tested['fingerprint'], $client->connectionCheckTarget()['fingerprint']);
		$this->vision['api_key'] = null;
		$this->assertSame($client->connectionCheckTarget(null, 'main-key')['fingerprint'], $client->connectionCheckTarget()['fingerprint'],
			'A cleared key falls back to the main key, like the test itself');
		$this->assertStringNotContainsString('typed-vision-key', json_encode($tested));
	}

	public function testSpeechUsesTheEffectiveTranscriptionEndpointAndKeyFallback(): void {
		$client = new SpeechToTextClient($this->http(), $this->settingsService(), $this->createMock(LoggerInterface::class));
		$saved = $client->connectionCheckTarget();
		$this->assertSame('whisper', $saved['summary']);
		$this->assertSame($saved, $client->connectionCheckTarget('https://main.example/v1/audio/transcriptions', 'main-key', 'whisper'),
			'Blank speech settings inherit the main endpoint and key');
		$this->assertNotSame($saved['fingerprint'], $client->connectionCheckTarget(null, null, 'whisper-large')['fingerprint']);
		$this->mainKey = 'rotated-main-key';
		$this->assertNotSame($saved['fingerprint'], $client->connectionCheckTarget()['fingerprint'], 'An inherited key change is a change');
	}

	public function testCatalogueComparesTheNormalizedEndpoint(): void {
		$client = new CatalogueClient($this->http(), $this->settingsService(), $this->createMock(LoggerInterface::class));
		$saved = $client->connectionCheckTarget();
		$this->assertSame($saved, $client->connectionCheckTarget(' https://catalogue.example/api '));
		$this->assertNotSame($saved['fingerprint'], $client->connectionCheckTarget('https://other.example/api')['fingerprint']);
		$this->assertNull($saved['summary']);
	}

	public function testModelTargetFollowsTheSavedProviderOfTheTestedReferenceWithoutNetworkUse(): void {
		$client = new LLMClient($this->http(), $this->settingsService(), $this->createMock(LoggerInterface::class));
		$primary = $client->connectionCheckTarget(' primary:alpha ');
		$secondary = $client->connectionCheckTarget('secondary:beta');
		$this->assertSame('primary:alpha', $primary['summary']);
		$this->assertSame('secondary:beta', $secondary['summary']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $primary['fingerprint']);
		$this->assertNotSame($primary['fingerprint'], $client->connectionCheckTarget('primary:beta')['fingerprint']);

		$this->secondaryKey = 'rotated-secondary-key';
		$this->assertSame($primary, $client->connectionCheckTarget('primary:alpha'), 'Another provider is unrelated');
		$this->assertNotSame($secondary['fingerprint'], $client->connectionCheckTarget('secondary:beta')['fingerprint']);
		$this->secondaryKey = null;
		$cleared = $client->connectionCheckTarget('secondary:beta');
		$this->assertNotSame($secondary['fingerprint'], $cleared['fingerprint'], 'A removed key is a change');
		$this->assertSame($cleared, $client->connectionCheckTarget('secondary:beta'), 'An incomplete provider is still comparable');

		$this->settings->setApiEndpoint('https://moved.example/v1/chat/completions');
		$this->assertNotSame($primary['fingerprint'], $client->connectionCheckTarget('primary:alpha')['fingerprint']);
		foreach (['alpha', 'primary:', "primary:al\npha", 'secondary:' . str_repeat('x', 600)] as $reference) {
			$this->assertNull($client->connectionCheckTarget($reference)['fingerprint'], $reference);
		}
		$this->assertStringNotContainsString('main-key', json_encode([$primary, $secondary]));
	}

	public function testBaseUrlAndFullChatUrlAreTheSameEffectiveEndpoint(): void {
		$llm = new LLMClient($this->http(), $this->settingsService(), $this->createMock(LoggerInterface::class));
		$vision = new VisionClient($this->http(), $this->settingsService(), $this->createMock(LoggerInterface::class));
		$targets = fn (): array => [$llm->connectionCheckTarget('primary:alpha'), $llm->connectionCheckTarget('secondary:beta'), $vision->connectionCheckTarget()];
		$full = $targets();
		$this->settings->setApiEndpoint('https://main.example/v1');
		$this->settings->setSecondaryApiEndpoint('https://secondary.example/v1/');
		$this->assertSame($full, $targets());
	}

	private function settingsService(): SettingsService {
		$service = $this->createMock(SettingsService::class);
		$service->method('getSettings')->willReturnCallback(fn (): Settings => $this->settings);
		$service->method('getVisionConfig')->willReturnCallback(fn (): array => $this->vision);
		$service->method('getSpeechConfig')->willReturnCallback(fn (): array => $this->speech);
		$service->method('getApiKey')->willReturnCallback(fn (): string => $this->mainKey);
		$service->method('getSecondaryApiKey')->willReturnCallback(fn (): ?string => $this->secondaryKey);
		return $service;
	}

	private function http(): IClientService {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->never())->method('post');
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return $service;
	}
}
