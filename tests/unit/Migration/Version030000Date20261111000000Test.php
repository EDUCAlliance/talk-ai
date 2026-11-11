<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Migration;

use OCA\EducAI\Migration\Version030000Date20261111000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version030000Date20261111000000Test extends TestCase {
	public function testUpgradeAddsTheGuestSwitchTurnedOffAndIsIdempotent(): void {
		$table = new class {
			public array $columns = ['rag_enabled' => ['type' => Types::BOOLEAN]];
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
		$schema->method('hasTable')->with('educai_bots')->willReturn(true);
		$schema->method('getTable')->with('educai_bots')->willReturn($table);
		$migration = new Version030000Date20261111000000($this->connection([]));

		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame(['allow_guests'], $table->added);
		// Nullable for Nextcloud's cross-database rules; a missing value reads as "no".
		$this->assertSame(['type' => Types::BOOLEAN, 'notnull' => false, 'default' => false], $table->columns['allow_guests']);
		$this->assertSame(['type' => Types::BOOLEAN], $table->columns['rag_enabled']);

		$this->assertNull($migration->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertCount(1, $table->added);
	}

	public function testMissingBotTableIsUntouched(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertNull((new Version030000Date20261111000000($this->connection([])))->changeSchema(
			$this->createMock(IOutput::class), static fn () => $schema, []));
	}

	public function testBotsForEveryoneKeepAnsweringGuestsAndNoOtherBotStarts(): void {
		$connection = $this->connection([
			'everyone' => ['visibility' => 'global', 'is_public' => true, 'allow_guests' => false],
			'everyone, flag out of step' => ['visibility' => 'global', 'is_public' => false, 'allow_guests' => false],
			'public before visibility existed' => ['visibility' => null, 'is_public' => true, 'allow_guests' => false],
			'public, empty visibility' => ['visibility' => '', 'is_public' => true, 'allow_guests' => false],
			'group' => ['visibility' => 'groups', 'is_public' => false, 'allow_guests' => false],
			'group, stale public flag' => ['visibility' => 'groups', 'is_public' => true, 'allow_guests' => false],
			'team' => ['visibility' => 'teams', 'is_public' => false, 'allow_guests' => false],
			'personal' => ['visibility' => 'personal', 'is_public' => false, 'allow_guests' => false],
			'old group bot' => ['visibility' => null, 'is_public' => false, 'allow_guests' => false],
		]);
		$output = new class implements IOutput {
			/** @var string[] */
			public array $messages = [];

			public function info($message): void {
				$this->messages[] = (string)$message;
			}
		};

		(new Version030000Date20261111000000($connection))->postSchemaChange($output, static fn () => null, []);

		$this->assertSame('educai_bots', $connection->table);
		$this->assertCount(1, $output->messages);
		$this->assertStringContainsString('4 bots', $output->messages[0]);
		$this->assertSame(
			['everyone', 'everyone, flag out of step', 'public before visibility existed', 'public, empty visibility'],
			array_keys(array_filter($connection->rows, static fn (array $row): bool => $row['allow_guests'] === true)),
		);
	}

	/**
	 * A connection whose query builder runs the update against rows in memory.
	 *
	 * @param array<string,array<string,mixed>> $rows
	 */
	private function connection(array $rows): IDBConnection {
		return new class($rows) implements IDBConnection {
			public string $table = '';

			/**
			 * @param array<string,array<string,mixed>> $rows
			 */
			public function __construct(
				public array $rows,
			) {
			}

			public function getQueryBuilder(): object {
				return new class($this) {
					/** @var array<string,mixed> */
					private array $assignments = [];
					private ?\Closure $condition = null;

					public function __construct(
						private object $connection,
					) {
					}

					public function update(string $table): self {
						$this->connection->table = $table;
						return $this;
					}

					public function set(string $column, mixed $value): self {
						$this->assignments[$column] = $value;
						return $this;
					}

					public function where(\Closure $condition): self {
						$this->condition = $condition;
						return $this;
					}

					public function createNamedParameter(mixed $value, mixed $type = null): mixed {
						return $value;
					}

					public function expr(): object {
						return new class {
							public function eq(string $column, mixed $value): \Closure {
								return static fn (array $row): bool => $row[$column] === $value;
							}

							public function isNull(string $column): \Closure {
								return static fn (array $row): bool => $row[$column] === null;
							}

							public function emptyString(string $column): \Closure {
								return static fn (array $row): bool => $row[$column] === '';
							}

							public function orX(\Closure ...$parts): \Closure {
								return static fn (array $row): bool => array_filter($parts, static fn (\Closure $part): bool => $part($row)) !== [];
							}

							public function andX(\Closure ...$parts): \Closure {
								return static fn (array $row): bool => count(array_filter($parts, static fn (\Closure $part): bool => $part($row))) === count($parts);
							}
						};
					}

					public function executeStatement(): int {
						$updated = 0;
						foreach ($this->connection->rows as $key => $row) {
							if ($this->condition !== null && ($this->condition)($row)) {
								$this->connection->rows[$key] = $this->assignments + $row;
								$updated++;
							}
						}
						return $updated;
					}
				};
			}
		};
	}
}
