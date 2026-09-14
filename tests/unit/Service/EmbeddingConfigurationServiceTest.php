<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\EmbeddingConfigurationService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class EmbeddingConfigurationServiceTest extends TestCase {
	public function testSavedModelChangeSurvivesReloadAndScopesClearIndependently(): void {
		$values = [];
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(static function ($app, $key, $value) use (&$values): void { $values[$key] = $value; });
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default) use (&$values): string { return $values[$key] ?? $default; });
		$before = new Settings();
		$before->setEmbeddingModel('old-model');
		$after = clone $before;
		$after->setEmbeddingModel('new-model');
		(new EmbeddingConfigurationService($config))->recordChange($before, $after);

		$reloaded = new EmbeddingConfigurationService($config);
		$this->assertTrue($reloaded->isRequired('rag'));
		$this->assertTrue($reloaded->isRequired('catalogue'));
		$reloaded->markQueued('rag');
		$this->assertFalse($reloaded->isRequired('rag'));
		$this->assertTrue($reloaded->isRequired('catalogue'));
	}

	public function testUnrelatedChatModelAndBrandingDoNotDemandReindexing(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setAppValue');
		$before = new Settings();
		$before->setEmbeddingApiEndpoint('https://embedding.example.org');
		$before->setEmbeddingModel('dedicated-embedding-model');
		$after = clone $before;
		$after->setApiEndpoint('https://new-chat.example.org');
		$after->setDefaultModel('other-chat-model');
		(new EmbeddingConfigurationService($config))->recordChange($before, $after);
	}

	public function testChatDefaultChangeMarksBothScopesWhenEmbeddingModelIsInherited(): void {
		foreach ([null, ''] as $model) {
			$before = new Settings();
			$before->setEmbeddingModel($model);
			$before->setDefaultModel('old-inherited-model');
			$after = clone $before;
			$after->setDefaultModel('new-inherited-model');
			$this->assertMarkedScopes($before, $after, ['rag', 'catalogue']);
		}
	}

	public function testSwitchingFromImplicitToIdenticalExplicitModelDoesNotReindex(): void {
		$before = new Settings();
		$before->setDefaultModel('same-model');
		$after = clone $before;
		$after->setEmbeddingModel('same-model');
		$this->assertMarkedScopes($before, $after, []);
	}

	public function testCatalogueEndpointChangeOnlyMarksCatalogueEvenWhileDisabled(): void {
		$before = new Settings();
		$before->setCatalogueEnabled(false);
		$before->setCatalogueApiEndpoint('https://catalogue-a.example.org/api');
		$after = clone $before;
		$after->setCatalogueApiEndpoint('https://catalogue-b.example.org/api');
		$this->assertMarkedScopes($before, $after, ['catalogue']);
	}

	public function testEquivalentCatalogueEndpointFormattingDoesNotReindex(): void {
		$before = new Settings();
		$before->setCatalogueApiEndpoint('https://catalogue.example.org/api');
		$after = clone $before;
		$after->setCatalogueApiEndpoint(' https://catalogue.example.org/api/ ');
		$this->assertMarkedScopes($before, $after, []);
	}

	public function testRagChunkingChangeDoesNotRequireCatalogueReindex(): void {
		$before = new Settings();
		$before->setRagChunkSize(750);
		$before->setRagChunkOverlap(50);
		$after = clone $before;
		$after->setRagChunkSize(500);
		$after->setRagChunkOverlap(25);
		$this->assertMarkedScopes($before, $after, ['rag']);
	}

	/** @param list<string> $expected */
	private function assertMarkedScopes(Settings $before, Settings $after, array $expected): void {
		$marked = [];
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value) use (&$marked): void {
			$this->assertSame('educai', $app);
			$this->assertSame('1', $value);
			$marked[] = $key;
		});
		(new EmbeddingConfigurationService($config))->recordChange($before, $after);
		$this->assertSame(array_map(static fn (string $scope): string => $scope . '_reindex_required', $expected), $marked);
	}
}
