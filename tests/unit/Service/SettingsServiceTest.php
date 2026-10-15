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
	public function testInvalidModelReferencesFailBeforeCredentialsOrSettingsAreChanged(): void {
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->expects($this->never())->method('getSettings');
		$mapper->expects($this->never())->method('update');
		$credentials = $this->createMock(CredentialService::class);
		$credentials->expects($this->never())->method('encrypt');
		$service = new SettingsService($mapper, $credentials,
			$this->createMock(TalkBotRegistrationService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class));
		foreach ([
			['defaultModel' => 'primary:' . str_repeat('x', 505)],
			['fallbackModel' => "secondary:bad\nmodel"],
			['allowedModels' => ["primary:bad\0model"]],
		] as $invalid) {
			try {
				$service->updateSettings(...array_merge([
					'apiProvider' => 'custom', 'apiKey' => 'new-key',
					'apiEndpoint' => 'https://new.invalid', 'defaultModel' => 'primary:ok',
				], $invalid));
				$this->fail('Invalid model reference accepted');
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString('512 bytes', $e->getMessage());
			}
		}
	}

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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
		);

		$result = $service->updateSettings(
			apiProvider: 'custom',
			apiKey: 'api-key',
			apiEndpoint: 'https://example.invalid/v1',
			defaultModel: 'model-a',
			doclingEnabled: true,
			doclingApiEndpoint: 'https://docling.example.invalid/v1/documents/convert',
			doclingApiKey: 'docling-key',
			doclingApiProfile: 'docling_serve',
			doclingAuthMode: 'x_api_key',
		);

		$this->assertTrue($result->getDoclingEnabled());
		$this->assertSame('https://docling.example.invalid/v1/documents/convert', $result->getDoclingApiEndpoint());
		$this->assertSame('encrypted-docling-key', $result->getDoclingApiKey());
		$this->assertSame('docling_serve', $result->getDoclingApiProfile());
		$this->assertSame('x_api_key', $result->getDoclingAuthMode());
		$this->assertSame('docling_serve', $result->jsonSerialize()['docling_api_profile']);
		$this->assertSame('x_api_key', $result->jsonSerialize()['docling_auth_mode']);
		$this->assertSame('***', $result->jsonSerialize()['docling_api_key']);
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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class),
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

	public function testDoclingModeOverridesNeverLeakMainKeyOrChangeSavedChoices(): void {
		$settings = new Settings();
		$settings->setApiKey('encrypted-main');
		$settings->setDoclingApiEndpoint('https://docling.example/proxy');
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->method('getSettings')->willReturn($settings);
		$mapper->expects($this->never())->method('update');
		$credentials = $this->createMock(CredentialService::class);
		$decrypted = [];
		$credentials->method('decrypt')->willReturnCallback(static function (string $value) use (&$decrypted): string {
			$decrypted[] = $value;
			return str_replace('encrypted-', '', $value);
		});
		$service = new SettingsService($mapper, $credentials,
			$this->createMock(TalkBotRegistrationService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class));

		foreach ([null, 'encrypted-dedicated'] as $key) {
			$settings->setDoclingApiKey($key);
			foreach (['legacy', 'docling_serve'] as $profile) {
				foreach (['bearer', 'x_api_key', 'none'] as $auth) {
					$decrypted = [];
					$config = $service->getDoclingConfig($profile, $auth);
					$expected = $auth === 'none' ? '' : ($key !== null ? 'dedicated' : ($profile === 'legacy' && $auth === 'bearer' ? 'main' : null));
					$this->assertSame($expected, $config['api_key'], "$profile / $auth");
					$this->assertSame($expected ? ['encrypted-' . $expected] : [], $decrypted);
					$this->assertSame($profile, $config['docling_api_profile']);
					$this->assertSame($auth, $config['docling_auth_mode']);
					$this->assertSame('https://docling.example/proxy', $config['docling_api_endpoint']);
				}
			}
		}
		$this->assertSame('legacy', $settings->getDoclingApiProfile());
		$this->assertSame('bearer', $settings->getDoclingAuthMode());
		$settings->setDoclingApiProfile('docling_serve');
		$settings->setDoclingAuthMode('none');
		$this->assertSame('', $service->getDoclingConfig()['api_key']);
	}

	public function testClearCredentialsRemovesOptionalKeysAndANewValueInTheSameRequestWins(): void {
		$settings = new Settings();
		$settings->setApiKey('encrypted-api-key-existing');
		$settings->setWebhookSecret('encrypted-webhook-existing');
		$settings->setEmbeddingApiKey('encrypted-embedding-existing');
		$settings->setDoclingApiKey('encrypted-docling-existing');
		$settings->setVisionApiKey('encrypted-vision-existing');
		$settings->setSpeechApiKey('encrypted-speech-existing');
		$settings->setSecondaryApiKey('encrypted-secondary-existing');
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->expects($this->once())->method('getSettings')->willReturn($settings);
		$mapper->expects($this->once())->method('update')->willReturnCallback(static fn (Settings $updated): Settings => $updated);
		$credentials = $this->createMock(CredentialService::class);
		$credentials->expects($this->once())->method('encrypt')->with('new-vision-key')->willReturn('encrypted-new-vision');
		$service = new SettingsService($mapper, $credentials,
			$this->createMock(TalkBotRegistrationService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class));

		$result = $service->updateSettings('custom', '', 'https://example.invalid/v1', 'primary:model-a',
			visionApiKey: 'new-vision-key',
			clearCredentials: ['secondaryApiKey', 'embeddingApiKey', 'visionApiKey', 'speechApiKey', 'doclingApiKey', 'speechApiKey']);

		$this->assertNull($result->getSecondaryApiKey());
		$this->assertNull($result->getEmbeddingApiKey());
		$this->assertNull($result->getSpeechApiKey());
		$this->assertNull($result->getDoclingApiKey());
		$this->assertSame('encrypted-new-vision', $result->getVisionApiKey());
		$this->assertSame('encrypted-api-key-existing', $result->getApiKey());
		$this->assertSame('encrypted-webhook-existing', $result->getWebhookSecret());
		$json = $result->jsonSerialize();
		foreach (['secondary_api_key', 'embedding_api_key', 'speech_api_key', 'docling_api_key'] as $field) {
			$this->assertSame('', $json[$field], 'A removed key is reported like one that was never set');
		}
		$this->assertSame('***', $json['vision_api_key']);
	}

	public function testOnlyOptionalCredentialsCanBeClearedAndInvalidRequestsChangeNothing(): void {
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->expects($this->never())->method('getSettings');
		$mapper->expects($this->never())->method('update');
		$credentials = $this->createMock(CredentialService::class);
		$credentials->expects($this->never())->method('encrypt');
		$service = new SettingsService($mapper, $credentials,
			$this->createMock(TalkBotRegistrationService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class));
		foreach ([['apiKey'], ['webhookSecret'], ['catalogueApiKey'], ['visionApiKey', 'unknown'], [1], [['visionApiKey']], [null], ['VisionApiKey']] as $invalid) {
			try {
				$service->updateSettings('custom', 'new-key', 'https://new.example', 'primary:model', clearCredentials: $invalid);
				$this->fail('Invalid credential removal accepted: ' . json_encode($invalid));
			} catch (\InvalidArgumentException $e) {
				$this->assertSame('Only optional credentials can be removed', $e->getMessage());
			}
		}
		$this->assertSame([], SettingsService::validateClearCredentials(null));
		$this->assertSame([], SettingsService::validateClearCredentials([]));
		$this->assertSame(['doclingApiKey', 'visionApiKey'], SettingsService::validateClearCredentials(['doclingApiKey', 'visionApiKey', 'doclingApiKey']));
	}

	public function testClearedCredentialsBehaveLikeNeverSetIncludingFallbacksAndReindexReminders(): void {
		$settings = new Settings();
		$settings->setDefaultModel('primary:model');
		$settings->setApiKey('encrypted-main');
		$settings->setEmbeddingApiKey('encrypted-embedding');
		$settings->setEmbeddingModel('embedding-model');
		$settings->setDoclingApiKey('encrypted-docling');
		$settings->setVisionApiKey('encrypted-vision');
		$settings->setVisionModel('vision-model');
		$settings->setSecondaryApiKey('encrypted-secondary');
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->method('getSettings')->willReturn($settings);
		$mapper->method('update')->willReturnCallback(static fn (Settings $updated): Settings => $updated);
		$credentials = $this->createMock(CredentialService::class);
		$credentials->method('decrypt')->willReturnCallback(static fn (?string $value): string => str_replace('encrypted-', '', (string)$value));
		$appConfig = [];
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$appConfig): string {
			return $appConfig[$key] ?? $default;
		});
		$config->method('setAppValue')->willReturnCallback(static function (string $app, string $key, string $value) use (&$appConfig): void {
			$appConfig[$key] = $value;
		});
		$embeddingConfiguration = new \OCA\EducAI\Service\EmbeddingConfigurationService($config);
		$service = new SettingsService($mapper, $credentials,
			$this->createMock(TalkBotRegistrationService::class), $this->createMock(LoggerInterface::class), $embeddingConfiguration);

		$service->updateSettings('custom', '', '', 'primary:model', clearCredentials: ['visionApiKey', 'doclingApiKey', 'secondaryApiKey']);
		$this->assertSame([], $appConfig, 'Keys outside embeddings do not require reindexing');
		$this->assertNull($service->getVisionApiKey());
		// Like a never-set key: VisionClient then uses the main key.
		$this->assertNull($service->getVisionConfig()['api_key']);
		$this->assertNull($service->getSecondaryApiKey());
		$this->assertSame('main', $service->getDoclingConfig('legacy', 'bearer')['api_key']);
		$this->assertNull($service->getDoclingConfig('docling_serve', 'x_api_key')['api_key']);

		$service->updateSettings('custom', '', '', 'primary:model', clearCredentials: ['embeddingApiKey']);
		$this->assertNull($service->getRagConfig()['embedding_api_key']);
		$this->assertTrue($embeddingConfiguration->isRequired('rag'), 'Embeddings now use the main key: a key change');
		$this->assertTrue($embeddingConfiguration->isRequired('catalogue'));
	}

	public function testInvalidDoclingOptionsFailBeforeAnySettingsOrCredentialMutation(): void {
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->expects($this->never())->method('getSettings');
		$mapper->expects($this->never())->method('update');
		$credentials = $this->createMock(CredentialService::class);
		$credentials->expects($this->never())->method('encrypt');
		$service = new SettingsService($mapper, $credentials,
			$this->createMock(TalkBotRegistrationService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class));
		foreach ([['auto', 'bearer'], ['', 'none'], ['docling_serve', 'automatic'], ['legacy', '']] as [$profile, $auth]) {
			try {
				$service->updateSettings('custom', 'new-key', 'https://new.example', 'new-model',
					doclingApiProfile: $profile, doclingAuthMode: $auth);
				$this->fail('Invalid Docling options accepted');
			} catch (\InvalidArgumentException $e) {
				$this->assertStringStartsWith('Invalid Docling', $e->getMessage());
			}
		}
	}

	public function testChunkOverlapMustBeSmallerThanTheChunkSize(): void {
		$settings = new \OCA\EducAI\Db\Settings();
		$settings->setRagChunkSize(750);
		$settings->setRagChunkOverlap(50);
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->method('getSettings')->willReturn($settings);
		$mapper->expects($this->never())->method('update');
		$service = new SettingsService($mapper, $this->createMock(CredentialService::class),
			$this->createMock(TalkBotRegistrationService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EducAI\Service\EmbeddingConfigurationService::class));

		foreach ([['ragChunkSize' => 100, 'ragChunkOverlap' => 100], ['ragChunkOverlap' => 750], ['ragChunkSize' => 40]] as $invalid) {
			try {
				$service->updateSettings(...array_merge(['apiProvider' => 'custom', 'apiKey' => '', 'apiEndpoint' => 'https://api.invalid', 'defaultModel' => 'primary:ok'], $invalid));
				$this->fail('Invalid chunk settings accepted: ' . json_encode($invalid));
			} catch (\InvalidArgumentException $e) {
				$this->assertSame(SettingsService::INVALID_CHUNK_SETTINGS, $e->getCode());
			}
		}
		$this->assertSame(50, $settings->getRagChunkOverlap());
	}
}
