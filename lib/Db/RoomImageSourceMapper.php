<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoomImageSource>
 */
class RoomImageSourceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, RoomImageTables::resolveSourcesTable($db), RoomImageSource::class);
	}

	public function findById(int $id): RoomImageSource {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @return array<int,RoomImageSource>
	 */
	public function findByBotAndRoom(int $botId, string $roomToken): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	public function findOneByBotRoomAndNode(int $botId, string $roomToken, int $nodeId): ?RoomImageSource {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			$entity = $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

		return $entity instanceof RoomImageSource ? $entity : null;
	}

	public function findOneByBotRoomAndAttachment(int $botId, string $roomToken, string $attachmentId): ?RoomImageSource {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('attachment_id', $qb->createNamedParameter($attachmentId, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		try {
			$entity = $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

		return $entity instanceof RoomImageSource ? $entity : null;
	}

	/**
	 * @return array<int,RoomImageSource>
	 */
	public function findOlderThan(int $cutoffTimestamp): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoffTimestamp, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	public function deleteById(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

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
