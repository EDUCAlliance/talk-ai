<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Db;

use OCA\EducAI\Db\WikiRootMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class WikiRootMapperTest extends TestCase {
	public function testOnlyActiveAssignmentsToTheRequestedRootKeepItMaintained(): void {
		foreach ([[], [['root_id' => 77, 'active' => false]], [['root_id' => 88, 'active' => true]], [['root_id' => 77, 'active' => true]]] as $rows) {
			$query = new class($rows) {
				private array $filters = [];
				public function __construct(private array $rows) {
				}
				public function select(string $column): self {
					return $this;
				}
				public function from(string $table): self {
					TestCase::assertSame('educai_wiki_root_bots', $table);
					return $this;
				}
				public function where(callable $filter): self {
					$this->filters = [$filter];
					return $this;
				}
				public function andWhere(callable $filter): self {
					$this->filters[] = $filter;
					return $this;
				}
				public function expr(): object {
					return new class {
						public function eq(string $column, mixed $value): callable {
							return static fn (array $row): bool => $row[$column] === $value;
						}
					};
				}
				public function createNamedParameter(mixed $value, int $type): mixed {
					return $value;
				}
				public function setMaxResults(int $limit): self {
					return $this;
				}
				public function executeQuery(): object {
					$rows = $this->rows;
					foreach ($this->filters as $filter) {
						$rows = array_filter($rows, $filter);
					}
					return new class($rows) {
						public function __construct(private array $rows) {
						}
						public function fetchOne(): int|false {
							return $this->rows === [] ? false : reset($this->rows)['root_id'];
						}
						public function closeCursor(): void {
						}
					};
				}
			};
			$db = new class($query) implements IDBConnection {
				public function __construct(private object $query) {
				}
				public function getQueryBuilder(): object {
					return $this->query;
				}
			};
			$mapper = new WikiRootMapper($db);
			$this->assertSame($rows === [['root_id' => 77, 'active' => true]], $mapper->hasActiveAssignments(77));
		}
	}
}
