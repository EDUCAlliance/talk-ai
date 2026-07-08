<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoomDocumentEmbedding>
 */
class RoomDocumentEmbeddingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, RoomDocumentTables::resolveEmbeddingsTable($db), RoomDocumentEmbedding::class);
	}

	/**
	 * @return array<int,RoomDocumentEmbedding>
	 */
	public function findBySource(int $sourceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * @return array<int,RoomDocumentEmbedding>
	 */
	public function findByBotRoomAndModel(int $botId, string $roomToken, string $embeddingModel): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel, IQueryBuilder::PARAM_STR)));

		return $this->findEntities($qb);
	}

	public function countByBotAndRoom(int $botId, string $roomToken): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function deleteBySource(int $sourceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	public function deleteByBot(int $botId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	public function deleteByBotAndRoom(int $botId, string $roomToken): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR)));

		$qb->executeStatement();
	}
}
