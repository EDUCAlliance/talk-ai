<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Migration;

use OCA\EducAI\Migration\Version024500Date20261030010000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version024500Date20261030010000Test extends TestCase {
	private const TABLES = ['educai_api_conn', 'educai_api_rev', 'educai_api_op'];

	private function table(string $name): object {
		return new class($name) {
			public array $columns = [];
			public array $primary = [];
			public array $indexes = [];
			public array $unique = [];

			public function __construct(
				public string $name,
			) {
			}

			public function addColumn(string $name, string $type, array $options): void {
				$this->columns[$name] = ['type' => $type] + $options;
			}

			public function setPrimaryKey(array $columns): void {
				$this->primary = $columns;
			}

			public function addIndex(array $columns, string $name): void {
				$this->indexes[$name] = $columns;
			}

			public function addUniqueIndex(array $columns, string $name): void {
				$this->unique[$name] = $columns;
			}
		};
	}

	public function testCreatesTheThreeAdditiveTablesWithinPortableLimits(): void {
		$created = [];
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $name): bool => isset($created[$name]));
		$schema->method('createTable')->willReturnCallback(function (string $name) use (&$created): object {
			return $created[$name] = $this->table($name);
		});
		$schema->expects($this->never())->method('getTable');

		$result = (new Version024500Date20261030010000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertSame(self::TABLES, array_keys($created));
		foreach ($created as $name => $table) {
			$this->assertLessThanOrEqual(27, strlen($name));
			$this->assertSame(['id'], $table->primary);
			$this->assertTrue($table->columns['id']['autoincrement']);
			foreach ([...array_keys($table->columns), ...array_keys($table->indexes), ...array_keys($table->unique)] as $identifier) {
				$this->assertLessThanOrEqual(30, strlen($identifier), $identifier);
			}
			foreach ($table->columns as $column => $options) {
				if ($options['type'] === Types::BOOLEAN) {
					$this->assertFalse($options['notnull'], $column);
				}
			}
		}

		$connection = $created['educai_api_conn'];
		$this->assertFalse($connection->columns['credential']['notnull']);
		$this->assertSame(Types::TEXT, $connection->columns['credential']['type']);
		$this->assertNull($connection->columns['data_visibility']['default'] ?? null);
		$this->assertFalse($connection->columns['enabled']['default']);

		$this->assertSame(Types::TEXT, $created['educai_api_rev']->columns['document']['type']);
		$this->assertSame('draft', $created['educai_api_rev']->columns['status']['default']);
		$this->assertSame(['educai_api_rev_conn_i' => ['connection_id', 'status']], $created['educai_api_rev']->indexes);

		$operation = $created['educai_api_op'];
		$this->assertSame(['educai_api_op_active_u' => ['active_key']], $operation->unique);
		$this->assertFalse($operation->columns['active_key']['notnull']);
		$this->assertSame(['educai_api_op_match_i' => ['connection_id', 'match_hash']], $operation->indexes);
	}

	public function testExistingTablesAreLeftUntouched(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');
		$schema->expects($this->never())->method('getTable');
		$schema->expects($this->never())->method('dropTable');

		$this->assertNull((new Version024500Date20261030010000())->changeSchema(
			$this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
