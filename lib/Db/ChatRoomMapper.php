<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for ChatRoom entities.
 *
 * @template-extends QBMapper<ChatRoom>
 */
class ChatRoomMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_chat_rooms', ChatRoom::class);
	}

	/**
	 * Find a chat room state by bot ID and room token.
	 *
	 * @param int $botId
	 * @param string $roomToken
	 * @return ChatRoom|null
	 */
	public function findByBotAndRoom(int $botId, string $roomToken): ?ChatRoom {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR))
			);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find all chat room states for a specific bot.
	 *
	 * @param int $botId
	 * @return ChatRoom[]
	 */
	public function findByBot(int $botId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntities($qb);
	}

	/**
	 * Find all chat room states for a specific room (across all bots).
	 *
	 * @param string $roomToken
	 * @return ChatRoom[]
	 */
	public function findByRoom(string $roomToken): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntities($qb);
	}

	/**
	 * Delete all chat room states for a specific bot.
	 *
	 * @param int $botId
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
	 * Delete a specific chat room state by bot ID and room token.
	 *
	 * @param int $botId
	 * @param string $roomToken
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

	/**
	 * Find all rooms in "always" mode for a specific room token.
	 * Used to find bots that should respond to any message in a room.
	 *
	 * @param string $roomToken
	 * @return ChatRoom[]
	 */
	public function findAlwaysModeByRoom(string $roomToken): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('response_mode', $qb->createNamedParameter('always', IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('onboarding_status', $qb->createNamedParameter('completed', IQueryBuilder::PARAM_STR))
			);

		return $this->findEntities($qb);
	}

	/**
	 * Find all rooms with onboarding in progress for a specific room token.
	 * Used to detect onboarding responses without mentions.
	 *
	 * @param string $roomToken
	 * @return ChatRoom[]
	 */
	public function findOnboardingInProgressByRoom(string $roomToken): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->neq('onboarding_status', $qb->createNamedParameter('completed', IQueryBuilder::PARAM_STR))
			);

		return $this->findEntities($qb);
	}

	/**
	 * Find all rooms (any status) for a specific room token.
	 * Used for reset command to find all bots with any state in the room.
	 *
	 * @param string $roomToken
	 * @return ChatRoom[]
	 */
	public function findAllByRoom(string $roomToken): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntities($qb);
	}
}

