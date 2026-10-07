<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Migration;

use OCA\EducAI\Migration\Version024202Date20261007000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version024202Date20261007000000Test extends TestCase {
	public function testUpgradedInstallationsGetNullableActiveFlagsWithTheirDefaultPreserved(): void {
		$roots = $this->column(true);
		$rootBots = $this->column(true);
		$schema = $this->schema([
			'educai_wiki_roots' => ['active' => $roots],
			'educai_wiki_root_bots' => ['active' => $rootBots],
		]);

		$this->assertSame($schema, $this->migrate($schema));
		foreach ([$roots, $rootBots] as $column) {
			$this->assertFalse($column->getNotnull());
			$this->assertTrue($column->getDefault());
		}
	}

	public function testFreshInstallationsAreLeftUntouched(): void {
		$schema = $this->schema([
			'educai_wiki_roots' => ['active' => $this->column(false)],
			'educai_wiki_root_bots' => ['active' => $this->column(false)],
		]);

		$this->assertNull($this->migrate($schema));
	}

	public function testOnlyColumnsThatAreStillNotNullAreChanged(): void {
		$roots = $this->column(false);
		$rootBots = $this->column(true);
		$schema = $this->schema([
			'educai_wiki_roots' => ['active' => $roots],
			'educai_wiki_root_bots' => ['active' => $rootBots],
		]);

		$this->assertSame($schema, $this->migrate($schema));
		$this->assertSame(0, $roots->changes);
		$this->assertSame(1, $rootBots->changes);
		$this->assertFalse($rootBots->getNotnull());
	}

	public function testMissingTablesAndColumnsAreNoOps(): void {
		$this->assertNull($this->migrate($this->schema([])));
		$this->assertNull($this->migrate($this->schema([
			'educai_wiki_roots' => [],
			'educai_wiki_root_bots' => ['root_id' => $this->column(true)],
		])));
	}

	public function testRunningTwiceChangesNothingTheSecondTime(): void {
		$schema = $this->schema([
			'educai_wiki_roots' => ['active' => $this->column(true)],
			'educai_wiki_root_bots' => ['active' => $this->column(true)],
		]);

		$this->assertSame($schema, $this->migrate($schema));
		$this->assertNull($this->migrate($schema));
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version024202Date20261007000000())->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[],
		);
	}

	/**
	 * @param array<string,array<string,object>> $tables
	 */
	private function schema(array $tables): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $name): bool => isset($tables[$name]));
		$schema->method('getTable')->willReturnCallback(static fn (string $name): object => new class($tables[$name]) {
			/** @param array<string,object> $columns */
			public function __construct(private array $columns) {
			}

			public function hasColumn(string $name): bool {
				return isset($this->columns[$name]);
			}

			public function getColumn(string $name): object {
				return $this->columns[$name];
			}
		});
		return $schema;
	}

	private function column(bool $notnull): object {
		return new class($notnull) {
			public int $changes = 0;

			public function __construct(private bool $notnull) {
			}

			public function getNotnull(): bool {
				return $this->notnull;
			}

			public function setNotnull(bool $notnull): self {
				$this->notnull = $notnull;
				$this->changes++;
				return $this;
			}

			public function getDefault(): bool {
				return true;
			}
		};
	}
}
