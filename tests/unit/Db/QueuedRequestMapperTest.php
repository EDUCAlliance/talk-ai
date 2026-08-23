<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Db;

use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Db\QueuedRequestMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class QueuedRequestMapperTest extends TestCase {
	public function testConditionalUpdateCapsClaimsAcrossWorkersUsingPersistedState(): void {
		$pdo = new \PDO('sqlite::memory:');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE educai_queue (id INTEGER PRIMARY KEY, status TEXT NOT NULL, attempts INTEGER NOT NULL)');
		$insert = $pdo->prepare('INSERT INTO educai_queue (id, status, attempts) VALUES (?, ?, ?)');
		$insert->execute([99, QueuedRequest::STATUS_RESPONSE_READY, 0]);

		$db = $this->sqliteConnection($pdo);
		$firstWorker = new QueuedRequestMapper($db);
		$secondWorker = new QueuedRequestMapper($db);

		$this->assertTrue($firstWorker->claimResponseDeliveryAttempt(99, QueuedRequest::MAX_ATTEMPTS));
		$this->assertTrue($secondWorker->claimResponseDeliveryAttempt(99, QueuedRequest::MAX_ATTEMPTS));
		$this->assertTrue($firstWorker->claimResponseDeliveryAttempt(99, QueuedRequest::MAX_ATTEMPTS));
		$this->assertFalse($secondWorker->claimResponseDeliveryAttempt(99, QueuedRequest::MAX_ATTEMPTS));

		$storedAttempts = $pdo->query('SELECT attempts FROM educai_queue WHERE id = 99')->fetchColumn();
		$this->assertSame(QueuedRequest::MAX_ATTEMPTS, (int)$storedAttempts);
		$lastBuilder = $db->builders[3];
		$this->assertSame(
			'UPDATE educai_queue SET attempts = attempts + 1 WHERE id = :p0 AND status = :p1 AND attempts < :p2',
			$lastBuilder->lastSql
		);
		$this->assertSame(
			[':p0' => 99, ':p1' => QueuedRequest::STATUS_RESPONSE_READY, ':p2' => QueuedRequest::MAX_ATTEMPTS],
			$lastBuilder->parameters
		);

		$transition = $pdo->prepare('UPDATE educai_queue SET status = ? WHERE id = ?');
		$transition->execute([QueuedRequest::STATUS_COMPLETED, 99]);
		$this->assertFalse($firstWorker->claimResponseDeliveryAttempt(99, QueuedRequest::MAX_ATTEMPTS + 1));
		$this->assertSame(
			QueuedRequest::MAX_ATTEMPTS,
			(int)$pdo->query('SELECT attempts FROM educai_queue WHERE id = 99')->fetchColumn()
		);
	}

	public function testSuccessfulDeliveryWinsConcurrentTerminalTransitionsAndRemainsAbsorbing(): void {
		$pdo = new \PDO('sqlite::memory:');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE educai_queue (id INTEGER PRIMARY KEY, status TEXT NOT NULL, result TEXT, error TEXT, attempts INTEGER NOT NULL, processed_at INTEGER)');
		$insert = $pdo->prepare('INSERT INTO educai_queue (id, status, result, error, attempts) VALUES (?, ?, ?, ?, ?)');
		$insert->execute([99, QueuedRequest::STATUS_RESPONSE_READY, 'Queued answer.', null, 2]);

		$db = $this->sqliteConnection($pdo);
		$failedWorker = new QueuedRequestMapper($db);
		$successfulWorker = new QueuedRequestMapper($db);

		$this->assertTrue($failedWorker->failResponseDelivery(99, 'Talk delivery failed', 100, QueuedRequest::MAX_ATTEMPTS));
		$this->assertTrue($successfulWorker->completeResponseDelivery(99, 'Queued answer.', 101));
		$this->assertFalse($failedWorker->failResponseDelivery(
			99,
			'Queued response delivery attempts exhausted',
			102,
			QueuedRequest::MAX_ATTEMPTS
		));

		$row = $pdo->query('SELECT status, result, error, attempts, processed_at FROM educai_queue WHERE id = 99')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame([
			'status' => QueuedRequest::STATUS_COMPLETED,
			'result' => 'Queued answer.',
			'error' => null,
			'attempts' => 3,
			'processed_at' => 101,
		], [
			'status' => $row['status'],
			'result' => $row['result'],
			'error' => $row['error'],
			'attempts' => (int)$row['attempts'],
			'processed_at' => (int)$row['processed_at'],
		]);
	}

	private function sqliteConnection(\PDO $pdo): IDBConnection {
		return new class($pdo) implements IDBConnection {
			/** @var object[] */
			public array $builders = [];

			public function __construct(
				private \PDO $pdo,
			) {
			}

			public function getQueryBuilder(): object {
				$builder = new class($this->pdo) {
					public string $lastSql = '';
					/** @var array<string,mixed> */
					public array $parameters = [];
					private string $table = '';
					/** @var array<string,string> */
					private array $assignments = [];
					/** @var string[] */
					private array $conditions = [];
					public function __construct(
						private \PDO $pdo,
					) {
					}

					public function update(string $table): self {
						$this->table = $table;
						return $this;
					}

					public function set(string $column, mixed $value): self {
						$this->assignments[$column] = (string)$value;
						return $this;
					}

					public function where(mixed $expression): self {
						$this->conditions = [(string)$expression];
						return $this;
					}

					public function andWhere(mixed $expression): self {
						$this->conditions[] = (string)$expression;
						return $this;
					}

					public function expr(): object {
						return new class {
							public function eq(string $column, mixed $value): string {
								return $column . ' = ' . $value;
							}

							public function lt(string $column, mixed $value): string {
								return $column . ' < ' . $value;
							}

							public function orX(string ...$expressions): string {
								return '(' . implode(' OR ', $expressions) . ')';
							}
						};
					}

					public function createNamedParameter(mixed $value, ?int $type = null): string {
						$name = ':p' . count($this->parameters);
						$this->parameters[$name] = $value;
						return $name;
					}

					public function createFunction(string $call): string {
						return $call;
					}

					public function executeStatement(): int {
						$set = [];
						foreach ($this->assignments as $column => $value) {
							$set[] = $column . ' = ' . $value;
						}
						$this->lastSql = sprintf(
							'UPDATE %s SET %s WHERE %s',
							$this->table,
							implode(', ', $set),
							implode(' AND ', $this->conditions)
						);
						$statement = $this->pdo->prepare($this->lastSql);
						foreach ($this->parameters as $name => $value) {
							$statement->bindValue(
								$name,
								$value,
								$value === null ? \PDO::PARAM_NULL : (is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR)
							);
						}
						$statement->execute();
						return $statement->rowCount();
					}
				};
				$this->builders[] = $builder;
				return $builder;
			}
		};
	}
}
