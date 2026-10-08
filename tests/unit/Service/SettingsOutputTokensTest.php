<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\EmbeddingConfigurationService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\TalkBotRegistrationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsOutputTokensTest extends TestCase {
	public function testDefaultAndOverridesAreIndependentOfConversationContext(): void {
		$settings = new Settings();
		$service = $this->service($settings);
		$this->assertSame(32768, $service->getMaxOutputTokens());
		$settings->setConversationContextTokens(20000);
		$settings->setMaxOutputTokens(6000);
		$settings->setDefaultModel('secondary:reasoning');
		$settings->setModelOutputTokenLimits('{"primary:reasoning":8192,"secondary:reasoning":16384}');
		$this->assertSame(16384, $service->getMaxOutputTokens());
		$this->assertSame(16384, $service->getMaxOutputTokens(''));
		$this->assertSame(8192, $service->getMaxOutputTokens('reasoning'));
		$this->assertSame(8192, $service->getMaxOutputTokens('primary:reasoning'));
		$this->assertSame(6000, $service->getMaxOutputTokens('secondary:other'));
		$this->assertSame(6000, $service->getMaxOutputTokens('primary:reasoning-new'));
		$this->assertSame(6000, $service->getMaxOutputTokens('primary:Reasoning'));
		$this->assertSame(20000, $settings->getConversationContextTokens());
	}

	public function testMalformedStoredSettingsUseTheGlobalOrBuiltInDefault(): void {
		$settings = new Settings();
		$settings->setMaxOutputTokens(7000);
		$service = $this->service($settings);
		foreach (['{', 'true', '[4096]', '{"primary:model":false}', '{"primary:model":4.5}', '{"primary:model":0}', '{"primary:model":131073}'] as $stored) {
			$settings->setModelOutputTokenLimits($stored);
			$this->assertSame(7000, $service->getMaxOutputTokens('primary:model'), $stored);
		}
		foreach ([null, 0, -1, 131073] as $stored) {
			$settings->setMaxOutputTokens($stored);
			$this->assertSame(32768, $service->getMaxOutputTokens('primary:model'));
		}
	}

	public function testSavePreservesOmittedSettingsAndCanClearOverrides(): void {
		$settings = new Settings();
		$settings->setConversationContextTokens(9000);
		$settings->setMaxOutputTokens(8192);
		$settings->setModelOutputTokenLimits('{"secondary:model":16384}');
		$service = $this->service($settings);
		$service->updateSettings('custom', '', 'https://llm.example', 'primary:model');
		$this->assertSame(8192, $settings->getMaxOutputTokens());
		$this->assertSame(['secondary:model' => 16384], $settings->getModelOutputTokenLimitsArray());
		$this->assertSame(9000, $settings->getConversationContextTokens());
		$service->updateSettings('custom', '', 'https://llm.example', 'primary:model',
			maxOutputTokens: '1', modelOutputTokenLimits: ['primary:org/model:latest' => '131072']);
		$this->assertSame(1, $settings->getMaxOutputTokens());
		$this->assertSame(['primary:org/model:latest' => 131072], $settings->getModelOutputTokenLimitsArray());
		$service->updateSettings('custom', '', 'https://llm.example', 'primary:model', modelOutputTokenLimits: []);
		$this->assertSame('{}', $settings->getModelOutputTokenLimits());
		$this->assertSame(1, $service->getMaxOutputTokens('primary:org/model:latest'));
	}

	public function testInvalidRequestsFailBeforeAnySettingsOrCredentialMutation(): void {
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->expects($this->never())->method('getSettings');
		$mapper->expects($this->never())->method('update');
		$credentials = $this->createMock(CredentialService::class);
		$credentials->expects($this->never())->method('encrypt');
		$service = $this->service(new Settings(), $mapper, $credentials);
		$invalid = [
			[0, null], [-1, null], [131073, null], [1.5, null], [4096.0, null], [true, null], [false, null],
			['', null], ['2.5', null], ['8e3', null], [' 4096', null], ['+4096', null],
			[4096, '{}'], [4096, true], [4096, [8192]], [4096, ['model' => 8192]],
			[4096, ['primary:' => 8192]], [4096, ["primary:model\n" => 8192]],
			[4096, ['other:model' => 8192]], [4096, ['primary:space model' => 8192]],
			[4096, ['primary:model' => true]], [4096, ['primary:model' => 4096.0]],
			[4096, ['primary:model' => 0]], [4096, ['primary:model' => 131073]],
		];
		foreach ($invalid as [$global, $overrides]) {
			try {
				$service->updateSettings('custom', 'new-secret', 'https://llm.example', 'model',
					maxOutputTokens: $global, modelOutputTokenLimits: $overrides);
				$this->fail('Invalid output token configuration accepted');
			} catch (\InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testJsonExposesNumericLimitsButNotCredentials(): void {
		$settings = new Settings();
		$settings->setApiKey('encrypted-secret');
		$settings->setModelOutputTokenLimits('{"primary:model":32768}');
		$data = json_decode(json_encode($settings, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		$this->assertSame(32768, $data['max_output_tokens']);
		$this->assertSame(['primary:model' => 32768], $data['model_output_token_limits']);
		$this->assertSame('***', $data['api_key']);
	}

	private function service(Settings $settings, ?SettingsMapper $mapper = null, ?CredentialService $credentials = null): SettingsService {
		if ($mapper === null) {
			$mapper = $this->createMock(SettingsMapper::class);
			$mapper->method('getSettings')->willReturn($settings);
			$mapper->method('update')->willReturnCallback(static fn (Settings $updated): Settings => $updated);
		}
		return new SettingsService($mapper, $credentials ?? $this->createMock(CredentialService::class),
			$this->createMock(TalkBotRegistrationService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(EmbeddingConfigurationService::class));
	}
}
