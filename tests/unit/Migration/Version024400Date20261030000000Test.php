<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Migration;

use OCA\EducAI\Migration\Version024400Date20261030000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version024400Date20261030000000Test extends TestCase {
	public function testUpgradeAddsNullableLinkSettingsKeepsFixedIdAndIsIdempotent(): void {
		$table = new class {
			public array $columns = [
				'id' => ['autoincrement' => true],
				'catalogue_api_endpoint' => ['type' => Types::STRING],
			];
			public array $added = [];

			public function hasColumn(string $name): bool {
				return isset($this->columns[$name]);
			}

			public function getColumn(string $name): object {
				$options = $this->columns[$name];
				return new class($options) {
					public function __construct(private array $options) {
					}

					public function getAutoincrement(): bool {
						return $this->options['autoincrement'] ?? false;
					}
				};
			}

			public function changeColumn(string $name, array $options): void {
				$this->columns[$name] = $options + $this->columns[$name];
			}

			public function addColumn(string $name, string $type, array $options): void {
				$this->columns[$name] = ['type' => $type] + $options;
				$this->added[] = $name;
			}
		};
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('educai_settings')->willReturn(true);
		$schema->method('getTable')->with('educai_settings')->willReturn($table);
		$migration = new Version024400Date20261030000000();

		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame(['catalogue_link_field', 'catalogue_link_template', 'catalogue_link_origins'], $table->added);
		$this->assertSame(['type' => Types::STRING, 'notnull' => false, 'length' => 512], $table->columns['catalogue_link_field']);
		$this->assertSame(['type' => Types::TEXT, 'notnull' => false], $table->columns['catalogue_link_template']);
		$this->assertSame(['type' => Types::TEXT, 'notnull' => false], $table->columns['catalogue_link_origins']);
		$this->assertFalse($table->columns['id']['autoincrement']);
		$this->assertSame(['type' => Types::STRING], $table->columns['catalogue_api_endpoint']);

		$this->assertNull($migration->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertCount(3, $table->added);
	}

	public function testMissingSettingsTableIsUntouched(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');
		$this->assertNull((new Version024400Date20261030000000())->changeSchema(
			$this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
