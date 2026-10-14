<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Migration;

use OCA\EducAI\Migration\Version024300Date20261013000000;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class ModelReferenceLengthMigrationTest extends TestCase {
	public function testWideningPreservesDefaultsNullabilityAndIsIdempotent(): void {
		$settings = $this->table([
			'id' => ['length' => 0, 'autoincrement' => true],
			'default_model' => ['length' => 200, 'notnull' => true, 'default' => 'old-default'],
			'fallback_model' => ['length' => 200, 'notnull' => false, 'default' => null],
			'embedding_model' => ['length' => 200, 'notnull' => false],
		]);
		$bots = $this->table(['model' => ['length' => 200, 'notnull' => false, 'default' => null]]);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturnCallback(static fn (string $name) => $name === 'educai_settings' ? $settings : $bots);
		$output = $this->createMock(IOutput::class);
		$migration = new Version024300Date20261013000000($this->createMock(IConfig::class));
		$this->assertSame($schema, $migration->changeSchema($output, static fn () => $schema, []));
		$this->assertSame(['length' => 512, 'notnull' => true, 'default' => 'old-default'], $settings->columns['default_model']);
		$this->assertSame(['length' => 512, 'notnull' => false, 'default' => null], $settings->columns['fallback_model']);
		$this->assertSame(['length' => 512, 'notnull' => false, 'default' => null], $bots->columns['model']);
		$this->assertSame(200, $settings->columns['embedding_model']['length']);
		$this->assertNull($migration->changeSchema($output, static fn () => $schema, []));
		$this->assertFalse($settings->columns['id']['autoincrement']);
		$this->assertCount(3, $settings->changes);
		$this->assertCount(1, $bots->changes);
	}

	public function testMissingAndAlreadyWiderColumnsAreNotChanged(): void {
		$table = $this->table(['default_model' => ['length' => 1024, 'notnull' => true]]);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $name): bool => $name === 'educai_settings');
		$schema->method('getTable')->willReturn($table);
		$this->assertNull((new Version024300Date20261013000000($this->createMock(IConfig::class)))->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame([], $table->changes);
	}

	private function table(array $columns): object {
		return new class($columns) {
			public array $changes = [];
			public function __construct(public array $columns) {
			}
			public function hasColumn(string $name): bool {
				return isset($this->columns[$name]);
			}
			public function getColumn(string $name): object {
				return new class($this->columns[$name]) {
					public function __construct(private array $options) {
					}
					public function getAutoincrement(): bool {
						return $this->options['autoincrement'] ?? false;
					}
					public function getLength(): int {
						return $this->options['length'];
					}
				};
			}
			public function changeColumn(string $name, array $options): void {
				$this->columns[$name] = array_merge($this->columns[$name], $options);
				$this->changes[] = [$name, $options];
			}
		};
	}
}
