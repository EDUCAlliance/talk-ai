<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Db;

use OCA\EducAI\Db\Conversation;
use OCA\EducAI\Db\ConversationMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ConversationMapperTest extends TestCase {
	public function testSameSecondRowsAreReturnedInStableChronologicalOrder(): void {
		$queryBuilder = new class {
			/** @var array<int,array{string,string}> */
			public array $orders = [];
			public ?int $limit = null;

			public function select(string ...$columns): self {
				return $this;
			}

			public function from(string $table): self {
				return $this;
			}

			public function where(mixed $expression): self {
				return $this;
			}

			public function andWhere(mixed $expression): self {
				return $this;
			}

			public function expr(): object {
				return new class {
					public function eq(string $column, mixed $value): array {
						return [$column, $value];
					}

					public function isNull(string $column): array {
						return [$column, null];
					}
				};
			}

			public function createNamedParameter(mixed $value, int $type): mixed {
				return $value;
			}

			public function orderBy(string $column, string $direction): self {
				$this->orders = [[$column, $direction]];
				return $this;
			}

			public function addOrderBy(string $column, string $direction): self {
				$this->orders[] = [$column, $direction];
				return $this;
			}

			public function setMaxResults(int $limit): self {
				$this->limit = $limit;
				return $this;
			}
		};
		$db = new class($queryBuilder) implements IDBConnection {
			public function __construct(
				private object $queryBuilder,
			) {
			}

			public function getQueryBuilder(): object {
				return $this->queryBuilder;
			}
		};

		$databaseRows = array_reverse([
			$this->conversation(1, 'user', 'First question.'),
			$this->conversation(2, 'assistant', 'First answer.'),
			$this->conversation(3, 'user', 'Second question.'),
			$this->conversation(4, 'assistant', 'Second answer.'),
			$this->conversation(5, 'user', 'Current prompt.'),
		]);
		$mapper = new class($db, $databaseRows) extends ConversationMapper {
			/** @param Conversation[] $rows */
			public function __construct(
				IDBConnection $db,
				private array $rows,
			) {
				parent::__construct($db);
			}

			protected function findEntities($query): array {
				return $this->rows;
			}
		};

		$history = $mapper->findByBotRoomAndThread(7, 'room-token', null, 50);

		$this->assertSame([
			['created_at', 'DESC'],
			['id', 'DESC'],
		], $queryBuilder->orders);
		$this->assertSame(50, $queryBuilder->limit);
		$this->assertSame([1, 2, 3, 4, 5], array_map(
			static fn (Conversation $conversation): int => $conversation->getId(),
			$history
		));
		$this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_map(
			static fn (Conversation $conversation): string => $conversation->getRole(),
			$history
		));
		$this->assertSame('Current prompt.', $history[4]->getContent());
	}

	private function conversation(int $id, string $role, string $content): Conversation {
		$conversation = new Conversation();
		$conversation->setId($id);
		$conversation->setBotId(7);
		$conversation->setRoomToken('room-token');
		$conversation->setUserId($role === 'assistant' ? '@personal-bot' : 'owner');
		$conversation->setRole($role);
		$conversation->setContent($content);
		$conversation->setCreatedAt(1_700_000_000);

		return $conversation;
	}
}
