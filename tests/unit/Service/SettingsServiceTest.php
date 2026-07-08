<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\TalkBotRegistrationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsServiceTest extends TestCase {
	public function testUpdateSettingsTriggersTalkBotRegistrationAfterPersistingSecret(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);
		$persisted = false;

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(function (Settings $updated) use (&$persisted): Settings {
				$persisted = true;
				return $updated;
			});

		$credentialService->method('encrypt')
			->willReturnMap([
				['api-key', 'encrypted-api-key'],
				['secret-123', 'encrypted-secret-123'],
			]);

		$talkBotRegistrationService->expects($this->once())
			->method('syncRegistration')
			->with('secret-123')
			->willReturnCallback(function () use (&$persisted): array {
				$this->assertTrue($persisted);
				return ['status' => 'registered', 'message' => 'ok'];
			});

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			defaultTemperature: SettingsService::DEFAULT_TEMPERATURE,
			webhookSecret: ' secret-123 '
		);

		$this->assertSame($settings, $result);
		$this->assertSame('encrypted-secret-123', $result->getWebhookSecret());
	}

	public function testUpdateSettingsSkipsTalkRegistrationWhenWebhookSecretIsBlank(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$credentialService->method('encrypt')
			->with('api-key')
			->willReturn('encrypted-api-key');

		$talkBotRegistrationService->expects($this->never())
			->method('syncRegistration');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			defaultTemperature: SettingsService::DEFAULT_TEMPERATURE,
			webhookSecret: '   '
		);

		$this->assertSame($settings, $result);
		$this->assertNull($result->getWebhookSecret());
	}

	public function testBlankSecretsKeepExistingEncryptedValues(): void {
		$settings = new Settings();
		$settings->setApiKey('encrypted-api-key-existing');
		$settings->setWebhookSecret('encrypted-webhook-existing');
		$settings->setEmbeddingApiKey('encrypted-embedding-existing');
		$settings->setDoclingApiKey('encrypted-docling-existing');
		$settings->setVisionApiKey('encrypted-vision-existing');
		$settings->setSpeechApiKey('encrypted-speech-existing');
		$settings->setSecondaryApiKey('encrypted-secondary-existing');

		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$credentialService->expects($this->never())
			->method('encrypt');
		$talkBotRegistrationService->expects($this->never())
			->method('syncRegistration');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: '',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			defaultTemperature: SettingsService::DEFAULT_TEMPERATURE,
			webhookSecret: '   ',
			embeddingApiKey: null,
			doclingApiKey: '',
			visionApiKey: null,
			speechApiKey: '',
			secondaryApiKey: ''
		);

		$this->assertSame('encrypted-api-key-existing', $result->getApiKey());
		$this->assertSame('encrypted-webhook-existing', $result->getWebhookSecret());
		$this->assertSame('encrypted-embedding-existing', $result->getEmbeddingApiKey());
		$this->assertSame('encrypted-docling-existing', $result->getDoclingApiKey());
		$this->assertSame('encrypted-vision-existing', $result->getVisionApiKey());
		$this->assertSame('encrypted-speech-existing', $result->getSpeechApiKey());
		$this->assertSame('encrypted-secondary-existing', $result->getSecondaryApiKey());
	}

	public function testUpdateSettingsPersistsSecondaryEndpointFallbackAndTimeouts(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$credentialService->method('encrypt')
			->willReturnMap([
				['api-key', 'encrypted-api-key'],
				['secondary-key', 'encrypted-secondary-key'],
			]);

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://primary.example.invalid/v1/chat/completions',
			defaultModel: 'primary:model-a',
			secondaryApiEndpoint: 'https://secondary.example.invalid/v1/chat/completions',
			secondaryApiKey: 'secondary-key',
			fallbackModel: 'secondary:model-b',
			llmChatTimeout: 120,
			llmStreamTimeout: 300,
			llmModelsTimeout: 30
		);

		$this->assertSame('https://secondary.example.invalid/v1/chat/completions', $result->getSecondaryApiEndpoint());
		$this->assertSame('encrypted-secondary-key', $result->getSecondaryApiKey());
		$this->assertSame('secondary:model-b', $result->getFallbackModel());
		$this->assertSame(120, $result->getLlmChatTimeout());
		$this->assertSame(300, $result->getLlmStreamTimeout());
		$this->assertSame(30, $result->getLlmModelsTimeout());
	}

	public function testSettingsJsonMasksSecondaryApiKeyAndIncludesLlmTimeouts(): void {
		$settings = new Settings();
		$settings->setSecondaryApiEndpoint('https://secondary.example.invalid/v1/chat/completions');
		$settings->setSecondaryApiKey('encrypted-secondary-key');
		$settings->setFallbackModel('secondary:model-b');
		$settings->setAppIconUrl('https://example.invalid/educai.svg');
		$settings->setLlmChatTimeout(120);
		$settings->setLlmStreamTimeout(300);
		$settings->setLlmModelsTimeout(30);

		$json = $settings->jsonSerialize();

		$this->assertSame('https://secondary.example.invalid/v1/chat/completions', $json['secondary_api_endpoint']);
		$this->assertSame('***', $json['secondary_api_key']);
		$this->assertSame('secondary:model-b', $json['fallback_model']);
		$this->assertSame('https://example.invalid/educai.svg', $json['app_icon_url']);
		$this->assertSame('default', $json['app_icon_mode']);
		$this->assertNull($json['app_icon_black_url']);
		$this->assertNull($json['app_icon_white_url']);
		$this->assertSame(120, $json['llm_chat_timeout']);
		$this->assertSame(300, $json['llm_stream_timeout']);
		$this->assertSame(30, $json['llm_models_timeout']);
	}

	public function testUpdateSettingsPersistsAndClearsAppIconUrl(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->exactly(2))
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->exactly(2))
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$credentialService->method('encrypt')
			->with('api-key')
			->willReturn('encrypted-api-key');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			appIconUrl: ' /apps/theming/img/custom-educai.svg '
		);

		$this->assertSame('/apps/theming/img/custom-educai.svg', $result->getAppIconUrl());
		$this->assertSame('custom', $result->getAppIconMode());
		$this->assertSame('/apps/theming/img/custom-educai.svg', $result->getAppIconBlackUrl());
		$this->assertSame('/apps/theming/img/custom-educai.svg', $result->getAppIconWhiteUrl());

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			appIconUrl: ''
		);

		$this->assertNull($result->getAppIconUrl());
		$this->assertSame('default', $result->getAppIconMode());
		$this->assertNull($result->getAppIconBlackUrl());
		$this->assertNull($result->getAppIconWhiteUrl());
	}

	public function testUpdateSettingsPersistsCustomAppIconVariants(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: '',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			appIconMode: 'custom',
			appIconBlackUrl: ' /apps/theming/img/educai-black.svg ',
			appIconWhiteUrl: 'https://example.invalid/educai-white.svg'
		);

		$this->assertSame('custom', $result->getAppIconMode());
		$this->assertNull($result->getAppIconPreset());
		$this->assertSame('/apps/theming/img/educai-black.svg', $result->getAppIconBlackUrl());
		$this->assertSame('https://example.invalid/educai-white.svg', $result->getAppIconWhiteUrl());
		$this->assertSame('/apps/theming/img/educai-black.svg', $result->getAppIconUrl());
	}

	public function testUpdateSettingsRejectsNextcloudFileAppIconReferences(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->never())
			->method('update');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('App icon URL must be an http(s) URL, an absolute Nextcloud path, or an uploaded SVG reference');

		$service->updateSettings(
			apiProvider: 'custom',
			apiKey: '',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			appIconMode: 'custom',
			appIconBlackUrl: 'nextcloud-fileid://pkienast/12345',
			appIconWhiteUrl: 'educai-upload://white'
		);
	}

	public function testUpdateSettingsPersistsUploadedAppIconReferences(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: '',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			appIconMode: 'custom',
			appIconBlackUrl: ' educai-upload://black ',
			appIconWhiteUrl: 'educai-upload://white'
		);

		$this->assertSame('custom', $result->getAppIconMode());
		$this->assertSame('educai-upload://black', $result->getAppIconBlackUrl());
		$this->assertSame('educai-upload://white', $result->getAppIconWhiteUrl());
		$this->assertSame('educai-upload://black', $result->getAppIconUrl());
	}

	public function testUpdateSettingsRejectsCustomAppIconWithoutBothVariants(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->never())
			->method('update');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Custom app icon mode requires both black and white icon URLs');

		$service->updateSettings(
			apiProvider: 'custom',
			apiKey: '',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			appIconMode: 'custom',
			appIconBlackUrl: '/apps/theming/img/educai-black.svg',
			appIconWhiteUrl: ''
		);
	}

	public function testUpdateSettingsRejectsRemovedAppIconPresetMode(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->never())
			->method('update');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('App icon mode must be default or custom');

		$service->updateSettings(
			apiProvider: 'custom',
			apiKey: '',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			appIconMode: 'preset',
			appIconBlackUrl: '/apps/theming/img/not-real-black.svg',
			appIconWhiteUrl: '/apps/theming/img/not-real-white.svg'
		);
	}

	public function testUpdateSettingsRejectsInvalidAppIconUrl(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->never())
			->method('update');

		$credentialService->method('encrypt')
			->with('api-key')
			->willReturn('encrypted-api-key');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('App icon URL must be an http(s) URL, an absolute Nextcloud path, or an uploaded SVG reference');

		$service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			appIconUrl: 'javascript:alert(1)'
		);
	}

	public function testBlankOptionalFallbackFieldsAreCleared(): void {
		$settings = new Settings();
		$settings->setEmbeddingApiEndpoint('https://example.invalid/embeddings');
		$settings->setEmbeddingModel('embedding-model');
		$settings->setDoclingApiEndpoint('https://example.invalid/docling');
		$settings->setVisionApiEndpoint('https://example.invalid/vision');
		$settings->setVisionModel('vision-model');
		$settings->setSpeechApiEndpoint('https://example.invalid/speech');
		$settings->setSpeechModel('speech-model');

		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$credentialService->method('encrypt')
			->with('api-key')
			->willReturn('encrypted-api-key');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			embeddingApiEndpoint: '',
			embeddingModel: '',
			doclingApiEndpoint: '',
			visionApiEndpoint: '',
			visionModel: '',
			speechApiEndpoint: '',
			speechModel: ''
		);

		$this->assertNull($result->getEmbeddingApiEndpoint());
		$this->assertNull($result->getEmbeddingModel());
		$this->assertNull($result->getDoclingApiEndpoint());
		$this->assertNull($result->getVisionApiEndpoint());
		$this->assertNull($result->getVisionModel());
		$this->assertNull($result->getSpeechApiEndpoint());
		$this->assertNull($result->getSpeechModel());
	}

	public function testUpdateSettingsPersistsEmbeddingRateLimitModeAndCustomValues(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$credentialService->method('encrypt')
			->with('api-key')
			->willReturn('encrypted-api-key');

		$talkBotRegistrationService->expects($this->never())
			->method('syncRegistration');

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			embeddingRateLimitMode: 'custom',
			embeddingRateLimitSecond: 4,
			embeddingRateLimitMinute: 120,
			embeddingRateLimitHour: 2400,
			embeddingRateLimitDay: 4800
		);

		$this->assertSame('custom', $result->getEmbeddingRateLimitMode());
		$this->assertSame(4, $result->getEmbeddingRateLimitSecond());
		$this->assertSame(120, $result->getEmbeddingRateLimitMinute());
		$this->assertSame(2400, $result->getEmbeddingRateLimitHour());
		$this->assertSame(4800, $result->getEmbeddingRateLimitDay());
	}

	public function testUpdateSettingsStoresDedicatedDoclingApiKeyEncrypted(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$talkBotRegistrationService = $this->createMock(TalkBotRegistrationService::class);

		$mapper->expects($this->once())
			->method('getSettings')
			->willReturn($settings);
		$mapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Settings $updated): Settings => $updated);

		$credentialService->method('encrypt')
			->willReturnMap([
				['api-key', 'encrypted-api-key'],
				['docling-key', 'encrypted-docling-key'],
			]);

		$service = new SettingsService(
			$mapper,
			$credentialService,
			$talkBotRegistrationService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			doclingEnabled: true,
			doclingApiEndpoint: 'https://docling.example.invalid/v1/documents/convert',
			doclingApiKey: 'docling-key'
		);

		$this->assertTrue($result->getDoclingEnabled());
		$this->assertSame('https://docling.example.invalid/v1/documents/convert', $result->getDoclingApiEndpoint());
		$this->assertSame('encrypted-docling-key', $result->getDoclingApiKey());
	}

	public function testDoclingConfigPrefersDedicatedApiKeyAndFallsBackToMainKey(): void {
		$settings = new Settings();
		$settings->setDoclingEnabled(true);
		$settings->setDoclingApiEndpoint('https://docling.example.invalid/v1/documents/convert');
		$settings->setApiKey('encrypted-api-key');
		$settings->setDoclingApiKey('encrypted-docling-key');

		$mapper = $this->createMock(SettingsMapper::class);
		$credentialService = $this->createMock(CredentialService::class);
		$service = new SettingsService(
			$mapper,
			$credentialService,
			$this->createMock(TalkBotRegistrationService::class),
			$this->createMock(LoggerInterface::class)
		);

		$mapper->method('getSettings')->willReturn($settings);
		$credentialService->method('decrypt')
			->willReturnMap([
				['encrypted-api-key', 'api-key'],
				['encrypted-docling-key', 'docling-key'],
				['', ''],
			]);

		$config = $service->getDoclingConfig();
		$this->assertSame('docling-key', $config['api_key']);

		$settings->setDoclingApiKey(null);
		$config = $service->getDoclingConfig();
		$this->assertSame('api-key', $config['api_key']);
	}
}
