<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Migration;

use OCA\EducAI\Migration\Version024203Date20261008000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version024203Date20261008000000Test extends TestCase {
	public function testUpgradeOnlyAddsOutputSettingsAndIsIdempotent(): void {
		$table = new class {
			public array $columns = ['api_key' => ['encrypted' => true], 'conversation_context_tokens' => ['default' => 8000]];
			public array $added = [];

			public function hasColumn(string $name): bool {
				return isset($this->columns[$name]);
			}

			public function addColumn(string $name, string $type, array $options): void {
				$this->columns[$name] = ['type' => $type] + $options;
				$this->added[] = $name;
			}
		};
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('educai_settings')->willReturn(true);
		$schema->method('getTable')->with('educai_settings')->willReturn($table);
		$migration = new Version024203Date20261008000000();
		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame(['max_output_tokens', 'model_output_token_limits'], $table->added);
		$this->assertSame(['type' => Types::INTEGER, 'notnull' => false, 'default' => 4096], $table->columns['max_output_tokens']);
		$this->assertSame(['type' => Types::TEXT, 'notnull' => false], $table->columns['model_output_token_limits']);
		$this->assertSame(['encrypted' => true], $table->columns['api_key']);
		$this->assertSame(['default' => 8000], $table->columns['conversation_context_tokens']);
		$this->assertNull($migration->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertCount(2, $table->added);
	}

	public function testMissingSettingsTableIsUntouched(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');
		$this->assertNull((new Version024203Date20261008000000())->changeSchema(
			$this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
