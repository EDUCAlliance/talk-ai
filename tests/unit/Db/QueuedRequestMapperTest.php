<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Db;

use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Db\QueuedRequestMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class QueuedRequestMapperTest extends TestCase {
	public function testDeliveryClaimIsExclusiveAndAttemptVersionRejectsStaleWorkerTransitions(): void {
		$pdo = new \PDO('sqlite::memory:');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE educai_queue (id INTEGER PRIMARY KEY, status TEXT NOT NULL, result TEXT, error TEXT, attempts INTEGER NOT NULL, processed_at INTEGER)');
		$insert = $pdo->prepare('INSERT INTO educai_queue (id, status, result, error, attempts, processed_at) VALUES (?, ?, ?, ?, ?, ?)');
		$insert->execute([99, QueuedRequest::STATUS_RESPONSE_READY, 'Queued answer.', null, 0, null]);

		$db = $this->sqliteConnection($pdo);
		$firstWorker = new QueuedRequestMapper($db);
		$secondWorker = new QueuedRequestMapper($db);

		$this->assertTrue($firstWorker->claimResponseDeliveryAttempt(99, 0, QueuedRequest::MAX_ATTEMPTS, 100));
		$this->assertFalse($secondWorker->claimResponseDeliveryAttempt(99, 0, QueuedRequest::MAX_ATTEMPTS, 101));
		$this->assertSame(
			[QueuedRequest::STATUS_DELIVERING, 1, 100],
			$this->deliveryState($pdo, 99)
		);

		$this->assertTrue($firstWorker->releaseResponseDeliveryForRetry(99, 1, 'HTTP 503', QueuedRequest::MAX_ATTEMPTS));
		$this->assertFalse($firstWorker->claimResponseDeliveryAttempt(99, 0, QueuedRequest::MAX_ATTEMPTS, 102));
		$this->assertTrue($secondWorker->claimResponseDeliveryAttempt(99, 1, QueuedRequest::MAX_ATTEMPTS, 102));
		$this->assertSame(
			[QueuedRequest::STATUS_DELIVERING, 2, 102],
			$this->deliveryState($pdo, 99)
		);

		$this->assertFalse($firstWorker->completeResponseDelivery(99, 1, 'Stale answer.', 103));
		$this->assertFalse($firstWorker->failResponseDelivery(99, 1, 'Stale failure', 104, QueuedRequest::MAX_ATTEMPTS));
		$this->assertTrue($secondWorker->completeResponseDelivery(99, 2, 'Queued answer.', 105));
		$this->assertFalse($firstWorker->failResponseDelivery(99, 2, 'Late failure', 106, QueuedRequest::MAX_ATTEMPTS));

		$row = $pdo->query('SELECT status, result, error, attempts, processed_at FROM educai_queue WHERE id = 99')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(QueuedRequest::STATUS_COMPLETED, $row['status']);
		$this->assertSame('Queued answer.', $row['result']);
		$this->assertNull($row['error']);
		$this->assertSame(2, (int)$row['attempts']);
		$this->assertSame(105, (int)$row['processed_at']);
	}

	public function testProcessingClaimRejectsPendingSnapshotFromBeforeRetryRelease(): void {
		$pdo = new \PDO('sqlite::memory:');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE educai_queue (id INTEGER PRIMARY KEY, status TEXT NOT NULL, result TEXT, error TEXT, attempts INTEGER NOT NULL, created_at INTEGER NOT NULL, processed_at INTEGER)');
		$pdo->exec("INSERT INTO educai_queue (id, status, result, error, attempts, created_at, processed_at) VALUES (99, 'pending', NULL, NULL, 1, 100, NULL)");

		$db = $this->sqliteConnection($pdo);
		$currentWorker = new QueuedRequestMapper($db);
		$staleWorker = new QueuedRequestMapper($db);

		$this->assertTrue($currentWorker->claimProcessingAttempt(99, 1, QueuedRequest::MAX_ATTEMPTS, 200));
		$this->assertTrue($currentWorker->releaseProcessingForRetry(99, 2, 'retry', QueuedRequest::MAX_ATTEMPTS));
		$this->assertSame([QueuedRequest::STATUS_PENDING, 2], $this->processingState($pdo, 99));
		$this->assertFalse($staleWorker->claimProcessingAttempt(99, 1, QueuedRequest::MAX_ATTEMPTS, 201));
		$this->assertSame([QueuedRequest::STATUS_PENDING, 2], $this->processingState($pdo, 99));
		$this->assertTrue($currentWorker->claimProcessingAttempt(99, 2, QueuedRequest::MAX_ATTEMPTS, 202));
		$this->assertSame([QueuedRequest::STATUS_PROCESSING, 3], $this->processingState($pdo, 99));
	}

	public function testExhaustedPendingRowDoesNotBlockEligibleWorkAndIsTerminalized(): void {
		$pdo = new \PDO('sqlite::memory:');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE educai_queue (id INTEGER PRIMARY KEY, bot_id INTEGER NOT NULL, room_token TEXT NOT NULL, user_id TEXT NOT NULL, message TEXT NOT NULL, original_message TEXT, reply_to_message_id INTEGER, thread_root_message_id INTEGER, status TEXT NOT NULL, result TEXT, error TEXT, attempts INTEGER NOT NULL, priority INTEGER NOT NULL, created_at INTEGER NOT NULL, processed_at INTEGER)');
		$insert = $pdo->prepare('INSERT INTO educai_queue (id, bot_id, room_token, user_id, message, status, attempts, priority, created_at) VALUES (?, 7, ?, ?, ?, ?, ?, ?, ?)');
		$insert->execute([99, 'room', 'owner', 'exhausted', QueuedRequest::STATUS_PENDING, QueuedRequest::MAX_ATTEMPTS, 1, 100]);
		$insert->execute([100, 'room', 'owner', 'eligible', QueuedRequest::STATUS_PENDING, 1, 100, 200]);

		$db = $this->sqliteConnection($pdo);
		$mapper = new QueuedRequestMapper($db);
		$mapper->findPending(1);

		$this->assertSame(['status = :p0', 'attempts < :p1'], $db->builders[0]->conditions);
		$this->assertSame([':p0' => QueuedRequest::STATUS_PENDING, ':p1' => QueuedRequest::MAX_ATTEMPTS], $db->builders[0]->parameters);
		$this->assertSame(1, $mapper->failExhaustedPending(QueuedRequest::MAX_ATTEMPTS, 300));
		$this->assertSame([QueuedRequest::STATUS_FAILED, QueuedRequest::MAX_ATTEMPTS], $this->processingState($pdo, 99));
		$this->assertSame([QueuedRequest::STATUS_PENDING, 1], $this->processingState($pdo, 100));
		$row = $pdo->query('SELECT error, processed_at FROM educai_queue WHERE id = 99')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame('Processing attempts exhausted before claim', $row['error']);
		$this->assertSame(300, (int)$row['processed_at']);
	}

	public function testStaleDeliveryLeaseIsRecoveredOrFailedWithoutTouchingFreshOwner(): void {
		$pdo = new \PDO('sqlite::memory:');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE educai_queue (id INTEGER PRIMARY KEY, status TEXT NOT NULL, result TEXT, error TEXT, attempts INTEGER NOT NULL, processed_at INTEGER)');
		$insert = $pdo->prepare('INSERT INTO educai_queue (id, status, result, error, attempts, processed_at) VALUES (?, ?, ?, ?, ?, ?)');
		$insert->execute([99, QueuedRequest::STATUS_DELIVERING, 'First answer.', null, 1, 100]);
		$insert->execute([100, QueuedRequest::STATUS_DELIVERING, 'Second answer.', null, QueuedRequest::MAX_ATTEMPTS, 100]);
		$insert->execute([101, QueuedRequest::STATUS_DELIVERING, 'Fresh answer.', null, 1, 250]);

		$db = $this->sqliteConnection($pdo);
		$mapper = new QueuedRequestMapper($db);

		$this->assertSame(2, $mapper->recoverStaleResponseDeliveries(200, 300, QueuedRequest::MAX_ATTEMPTS));
		$this->assertSame([QueuedRequest::STATUS_RESPONSE_READY, 1, null], $this->deliveryState($pdo, 99));
		$this->assertSame([QueuedRequest::STATUS_FAILED, QueuedRequest::MAX_ATTEMPTS, 300], $this->deliveryState($pdo, 100));
		$this->assertSame([QueuedRequest::STATUS_DELIVERING, 1, 250], $this->deliveryState($pdo, 101));

		$this->assertTrue($mapper->completeResponseDelivery(99, 1, 'First answer.', 301));
		$this->assertSame([QueuedRequest::STATUS_COMPLETED, 1, 301], $this->deliveryState($pdo, 99));
	}

	public function testProcessingClaimIsExclusiveAndStaleAttemptCannotOverwriteReclaimedWork(): void {
		$pdo = new \PDO('sqlite::memory:');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE educai_queue (id INTEGER PRIMARY KEY, status TEXT NOT NULL, result TEXT, error TEXT, attempts INTEGER NOT NULL, created_at INTEGER NOT NULL, processed_at INTEGER)');
		$insert = $pdo->prepare('INSERT INTO educai_queue (id, status, result, error, attempts, created_at, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
		$insert->execute([99, QueuedRequest::STATUS_PENDING, null, null, 0, time() - 1000, null]);
		$insert->execute([100, QueuedRequest::STATUS_PROCESSING, null, null, QueuedRequest::MAX_ATTEMPTS, time() - 1000, time() - 1000]);

		$db = $this->sqliteConnection($pdo);
		$firstWorker = new QueuedRequestMapper($db);
		$secondWorker = new QueuedRequestMapper($db);

		$this->assertTrue($firstWorker->claimProcessingAttempt(99, 0, QueuedRequest::MAX_ATTEMPTS, time() - 1000));
		$this->assertFalse($secondWorker->claimProcessingAttempt(99, 0, QueuedRequest::MAX_ATTEMPTS, time() - 999));
		$this->assertSame([QueuedRequest::STATUS_PROCESSING, 1], $this->processingState($pdo, 99));

		$this->assertSame(2, $firstWorker->resetStaleProcessing(300, QueuedRequest::MAX_ATTEMPTS));
		$this->assertSame([QueuedRequest::STATUS_PENDING, 1], $this->processingState($pdo, 99));
		$this->assertSame([QueuedRequest::STATUS_FAILED, QueuedRequest::MAX_ATTEMPTS], $this->processingState($pdo, 100));

		$this->assertTrue($secondWorker->claimProcessingAttempt(99, 1, QueuedRequest::MAX_ATTEMPTS, time()));
		$this->assertSame([QueuedRequest::STATUS_PROCESSING, 2], $this->processingState($pdo, 99));
		$this->assertFalse($firstWorker->storeResponseReady(99, 1, 'Stale answer.'));
		$this->assertFalse($firstWorker->failProcessingAttempt(99, 1, 'Stale failure', time(), QueuedRequest::MAX_ATTEMPTS));
		$this->assertTrue($secondWorker->storeResponseReady(99, 2, 'Current answer.'));

		$row = $pdo->query('SELECT status, result, error, attempts, processed_at FROM educai_queue WHERE id = 99')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(QueuedRequest::STATUS_RESPONSE_READY, $row['status']);
		$this->assertSame('Current answer.', $row['result']);
		$this->assertNull($row['error']);
		$this->assertSame(0, (int)$row['attempts']);
		$this->assertNull($row['processed_at']);
	}

	/**
	 * @return array{string, int, ?int}
	 */
	private function deliveryState(\PDO $pdo, int $id): array {
		$statement = $pdo->prepare('SELECT status, attempts, processed_at FROM educai_queue WHERE id = ?');
		$statement->execute([$id]);
		$row = $statement->fetch(\PDO::FETCH_ASSOC);

		return [
			(string)$row['status'],
			(int)$row['attempts'],
			$row['processed_at'] === null ? null : (int)$row['processed_at'],
		];
	}

	/**
	 * @return array{string, int}
	 */
	private function processingState(\PDO $pdo, int $id): array {
		$statement = $pdo->prepare('SELECT status, attempts FROM educai_queue WHERE id = ?');
		$statement->execute([$id]);
		$row = $statement->fetch(\PDO::FETCH_ASSOC);

		return [(string)$row['status'], (int)$row['attempts']];
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
					/** @var string[] */
					private array $selected = [];
					/** @var array<string,string> */
					private array $assignments = [];
					/** @var string[] */
					public array $conditions = [];
					/** @var string[] */
					private array $orderBy = [];
					private ?int $limit = null;
					public function __construct(
						private \PDO $pdo,
					) {
					}

					public function update(string $table): self {
						$this->table = $table;
						return $this;
					}

					public function select(string ...$columns): self {
						$this->selected = $columns;
						return $this;
					}

					public function from(string $table): self {
						$this->table = $table;
						return $this;
					}

					public function orderBy(string $column, string $direction): self {
						$this->orderBy = [$column . ' ' . $direction];
						return $this;
					}

					public function addOrderBy(string $column, string $direction): self {
						$this->orderBy[] = $column . ' ' . $direction;
						return $this;
					}

					public function setMaxResults(int $limit): self {
						$this->limit = $limit;
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

							public function gte(string $column, mixed $value): string {
								return $column . ' >= ' . $value;
							}

							public function orX(string ...$expressions): string {
								return '(' . implode(' OR ', $expressions) . ')';
							}

							public function andX(string ...$expressions): string {
								return '(' . implode(' AND ', $expressions) . ')';
							}

							public function isNull(string $column): string {
								return $column . ' IS NULL';
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
