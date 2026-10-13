<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ModelReferenceMigrationService;
use PHPUnit\Framework\TestCase;

class ModelReferenceMigrationServiceTest extends TestCase {
	public function testMigrationPinsOnlyProvenReferencesAndPreservesPendingContentAndInheritance(): void {
		$settings = new Settings();
		$settings->setDefaultModel('alpha');
		$settings->setFallbackModel('ambiguous');
		$settings->setAllowMultipleModels(false);
		$settings->setAllowedModels('["alpha","ambiguous","secondary:explicit"]');
		$settings->setUpdatedAt(100);
		$settings->setModelOutputTokenLimits('{"alpha":123,"secondary:beta":456}');
		$bot = new Bot();
		$bot->setId(1);
		$bot->setModel('alpha');
		$bot->setApprovalStatus('pending');
		$bot->setSystemPrompt('Approved original');
		$bot->setPendingChangesArray(['model' => 'beta', 'system_prompt' => 'Still awaiting approval', 'description' => 'Keep me']);
		$bot->setUpdatedAt(100);
		$inherited = new Bot();
		$inherited->setId(2);
		$inherited->setModel(null);
		$inherited->setPendingChangesArray(['model' => null, 'description' => 'Keep inheritance']);
		$unresolved = new Bot();
		$unresolved->setId(3);
		$unresolved->setModel('ambiguous');
		$unresolved->setPendingChangesArray(['model' => 'ambiguous']);
		$settingsMapper = $this->createMock(SettingsMapper::class);
		$settingsMapper->method('getSettings')->willReturn($settings);
		$settingsMapper->expects($this->once())->method('update')->with($settings)->willReturn($settings);
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findAll')->willReturn([$bot, $inherited, $unresolved]);
		$botMapper->expects($this->once())->method('update')->with($bot)->willReturn($bot);
		$llm = $this->createMock(LLMClient::class);
		$calls = [];
		$llm->method('canonicalizeModelReference')->willReturnCallback(static function (?string $reference) use (&$calls): ?string {
			$calls[] = $reference;
			if ($reference === 'ambiguous') {
				throw new \InvalidArgumentException('Choose a provider');
			}
			return $reference === 'alpha' ? 'primary:alpha' : 'secondary:beta';
		});
		$result = (new ModelReferenceMigrationService($settingsMapper, $botMapper, $llm))->migrate();
		$this->assertSame(['settings' => 1, 'bots' => 1, 'unresolved' => 1], $result);
		$this->assertSame(['alpha', 'ambiguous', 'beta'], $calls);
		$this->assertSame('primary:alpha', $settings->getDefaultModel());
		$this->assertSame('ambiguous', $settings->getFallbackModel());
		$this->assertSame(['primary:alpha', 'ambiguous', 'secondary:explicit'], json_decode($settings->getAllowedModels(), true));
		$this->assertFalse($settings->getAllowMultipleModels());
		$this->assertSame('{"alpha":123,"secondary:beta":456}', $settings->getModelOutputTokenLimits());
		$this->assertSame(100, $settings->getUpdatedAt());
		$this->assertSame('primary:alpha', $bot->getModel());
		$this->assertSame(['model' => 'secondary:beta', 'system_prompt' => 'Still awaiting approval', 'description' => 'Keep me'], $bot->getPendingChangesArray());
		$this->assertSame('pending', $bot->getApprovalStatus());
		$this->assertSame('Approved original', $bot->getSystemPrompt());
		$this->assertSame(100, $bot->getUpdatedAt());
		$this->assertNull($inherited->getModel());
		$this->assertSame(['model' => null, 'description' => 'Keep inheritance'], $inherited->getPendingChangesArray());
		$this->assertSame('ambiguous', $unresolved->getModel());
		$this->assertSame(['model' => 'ambiguous'], $unresolved->getPendingChangesArray());
	}

	public function testAlreadyCanonicalMigrationDoesNotWriteOrRunDiscovery(): void {
		$settings = new Settings();
		$settings->setDefaultModel('primary:alpha');
		$settings->setFallbackModel(null);
		$settings->setAllowedModels('["secondary:beta"]');
		$bot = new Bot();
		$bot->setModel('secondary:beta');
		$settingsMapper = $this->createMock(SettingsMapper::class);
		$settingsMapper->method('getSettings')->willReturn($settings);
		$settingsMapper->expects($this->never())->method('update');
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findAll')->willReturn([$bot]);
		$botMapper->expects($this->never())->method('update');
		$llm = $this->createMock(LLMClient::class);
		$llm->expects($this->never())->method('canonicalizeModelReference');
		$this->assertSame(['settings' => 0, 'bots' => 0, 'unresolved' => 0], (new ModelReferenceMigrationService($settingsMapper, $botMapper, $llm))->migrate());
	}
}
