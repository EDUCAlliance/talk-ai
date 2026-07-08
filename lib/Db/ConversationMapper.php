<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Conversation>
 */
class ConversationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_conversations', Conversation::class);
	}

	/**
	 * Find conversation history for a bot in a specific room
	 *
	 * @param int $botId
	 * @param string $roomToken
	 * @param int $limit Maximum messages to retrieve (default 50 for token-based filtering)
	 * @return Conversation[]
	 */
	public function findByBotAndRoom(int $botId, string $roomToken, int $limit = 50): array {
		return $this->findByBotRoomAndThread($botId, $roomToken, null, $limit);
	}

	/**
	 * Find conversation history for a bot in a room and Talk thread.
	 *
	 * @param int $botId
	 * @param string $roomToken
	 * @param int|null $threadRootMessageId
	 * @param int $limit Maximum messages to retrieve (default 50 for token-based filtering)
	 * @return Conversation[]
	 */
	public function findByBotRoomAndThread(int $botId, string $roomToken, ?int $threadRootMessageId, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR))
			);

		if ($threadRootMessageId === null) {
			$qb->andWhere($qb->expr()->isNull('thread_root_message_id'));
		} else {
			$qb->andWhere(
				$qb->expr()->eq('thread_root_message_id', $qb->createNamedParameter($threadRootMessageId, IQueryBuilder::PARAM_INT))
			);
		}

		$qb->orderBy('created_at', 'DESC')
			->setMaxResults($limit);

		$entities = $this->findEntities($qb);
		
		// Return in chronological order (oldest first)
		return array_reverse($entities);
	}

	/**
	 * Delete all conversations for a specific bot
	 *
	 * @param int $botId
	 * @return void
	 */
	public function deleteByBot(int $botId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT))
			);

		$qb->executeStatement();
	}

	/**
	 * Delete old conversations (older than specified timestamp)
	 *
	 * @param int $timestamp
	 * @return void
	 */
	public function deleteOlderThan(int $timestamp): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT))
			);

		$qb->executeStatement();
	}

	/**
	 * Delete all conversations for a specific bot in a specific room
	 *
	 * @param int $botId
	 * @param string $roomToken
	 * @return void
	 */
	public function deleteByBotAndRoom(int $botId, string $roomToken): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR))
			);

		$qb->executeStatement();
	}
}

