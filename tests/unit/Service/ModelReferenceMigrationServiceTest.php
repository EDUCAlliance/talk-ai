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
		$bots = [1 => $bot, 2 => $inherited, 3 => $unresolved];
		$settingsMapper = $this->createMock(SettingsMapper::class);
		$settingsMapper->method('getSettings')->willReturn($settings);
		$settingsMapper->expects($this->once())->method('update')->with($settings)->willReturn($settings);
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findAll')->willReturn(array_values($bots));
		$botMapper->method('findById')->willReturnCallback(static fn (int $id): Bot => $bots[$id]);
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

	public function testEditsSavedWhileReferencesResolveAreKept(): void {
		$before = new Settings();
		$before->setDefaultModel('alpha');
		$before->setAllowedModels('["alpha"]');
		$saved = new Settings();
		$saved->setDefaultModel('alpha');
		$saved->setAllowedModels('["alpha","secondary:new"]');
		$readBot = new Bot();
		$readBot->setId(4);
		$readBot->setModel('alpha');
		$readBot->setPendingChangesArray(['model' => 'alpha', 'system_prompt' => 'Old draft']);
		$approvedBot = new Bot();
		$approvedBot->setId(4);
		$approvedBot->setModel('alpha');
		$approvedBot->setPendingChangesArray(null);
		$settingsReads = 0;
		$settingsMapper = $this->createMock(SettingsMapper::class);
		$settingsMapper->method('getSettings')->willReturnCallback(static function () use (&$settingsReads, $before, $saved): Settings {
			return $settingsReads++ === 0 ? $before : $saved;
		});
		$settingsMapper->expects($this->once())->method('update')->with($saved)->willReturn($saved);
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findAll')->willReturn([$readBot]);
		$botMapper->method('findById')->with(4)->willReturn($approvedBot);
		$botMapper->expects($this->once())->method('update')->with($approvedBot)->willReturn($approvedBot);
		$llm = $this->createMock(LLMClient::class);
		$llm->method('canonicalizeModelReference')->willReturn('primary:alpha');

		(new ModelReferenceMigrationService($settingsMapper, $botMapper, $llm))->migrate();

		$this->assertSame(['primary:alpha', 'secondary:new'], json_decode($saved->getAllowedModels(), true));
		$this->assertSame('primary:alpha', $approvedBot->getModel());
		$this->assertNull($approvedBot->getPendingChangesArray(), 'An approval saved meanwhile must not be undone');
		$this->assertSame(['model' => 'alpha', 'system_prompt' => 'Old draft'], $readBot->getPendingChangesArray());
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
		$service = new ModelReferenceMigrationService($settingsMapper, $botMapper, $llm);
		$this->assertSame(['settings' => 0, 'bots' => 0, 'unresolved' => 0], $service->migrate());
		$this->assertSame([], $service->unqualifiedReferences());
	}

	public function testUnqualifiedReferencesListsEachNameOnceWithoutDiscovery(): void {
		$settings = new Settings();
		$settings->setDefaultModel('primary:alpha');
		$settings->setFallbackModel('zeta');
		$settings->setAllowedModels('["zeta","primary:alpha",42]');
		$bot = new Bot();
		$bot->setId(5);
		$bot->setModel('gpt-4o');
		$bot->setPendingChangesArray(['model' => 'zeta']);
		$settingsMapper = $this->createMock(SettingsMapper::class);
		$settingsMapper->method('getSettings')->willReturn($settings);
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findAll')->willReturn([$bot]);
		$llm = $this->createMock(LLMClient::class);
		$llm->expects($this->never())->method('canonicalizeModelReference');
		$this->assertSame(['gpt-4o', 'zeta'], (new ModelReferenceMigrationService($settingsMapper, $botMapper, $llm))->unqualifiedReferences());
	}
}
