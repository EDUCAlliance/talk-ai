<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Migration\Version024100Date20260914000000;
use OCA\EducAI\Service\BrandingService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class BrandingServiceTest extends TestCase {
	/** @var array<string,string> */
	private array $values = [];

	private function createService(array $values = []): BrandingService {
		$this->values = $values;
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default): string => $this->values[$key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->assertSame('educai', $app);
			$this->values[$key] = $value;
		});
		return new BrandingService($config);
	}

	public function testFreshInstallAndPublicUpgradeKeepNeutralDefaults(): void {
		foreach (['', '2.40.0'] as $version) {
			$service = $this->createService(['installed_version' => $version]);
			$service->initializeDefaults();
			$this->assertSame(['displayName' => 'Talk AI', 'wikiRootFolder' => 'Talk AI'], $service->getPublicState());
		}
	}

	public function testInternalUpgradePinsStorageBeforeInstalledVersionChanges(): void {
		$service = $this->createService(['installed_version' => '2.40.0.1']);
		$migration = new Version024100Date20260914000000($service);
		$migration->preSchemaChange($this->createMock(IOutput::class), static fn () => throw new \LogicException('This migration must not rewrite the database schema.'), []);
		$this->values['installed_version'] = '2.41.0';
		$this->assertSame(['displayName' => 'EDUC AI', 'wikiRootFolder' => 'EDUC AI'], $service->getPublicState());
		$service->initializeDefaults();
		$this->assertSame('EDUC AI', $service->getWikiRootFolder());
	}

	public function testBrandingChangesAndResetDoNotChangeWikiRootOrCatalogue(): void {
		$service = $this->createService(['installed_version' => '2.40.0.1', 'catalogue_enabled' => 'true']);
		$service->setDisplayName('Campus Assistant');
		$this->assertSame('Campus Assistant', $service->getDisplayName());
		$this->assertSame('EDUC AI', $service->getWikiRootFolder());
		$service->resetDisplayName();
		$this->assertSame('Talk AI', $service->getDisplayName());
		$this->assertSame('EDUC AI', $service->getWikiRootFolder());
		$this->assertSame('true', $this->values['catalogue_enabled']);
	}

	public function testExplicitSettingsArePreservedByMigration(): void {
		$service = $this->createService(['installed_version' => '2.40.0.1', 'display_name' => 'Campus AI', 'wiki_root_folder' => 'Talk AI']);
		$service->initializeDefaults();
		$this->assertSame(['displayName' => 'Campus AI', 'wikiRootFolder' => 'Talk AI'], $service->getPublicState());
	}

	public function testInvalidNamesDoNotPersistAnyChanges(): void {
		foreach (['', str_repeat('x', 65), "name\nwith control", '<b>Name</b>', "\xFF"] as $name) {
			$service = $this->createService(['installed_version' => '2.40.0.1']);
			try {
				$service->setDisplayName($name);
				$this->fail('Invalid display name was accepted.');
			} catch (\InvalidArgumentException) {
				$this->assertSame(['installed_version' => '2.40.0.1'], $this->values);
			}
		}
	}
}
