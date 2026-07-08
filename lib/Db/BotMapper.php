<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Bot>
 */
class BotMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_bots', Bot::class);
	}

    /**
     * Find a bot by its primary id
     *
     * NC33 does not expose QBMapper::find() publicly in some contexts; provide our own helper.
     *
     * @param int $id
     * @return Bot
     */
    public function findById(int $id): Bot {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

	/**
	 * Find a bot by its mention name
	 *
	 * @param string $mentionName
	 * @return Bot
	 * @throws DoesNotExistException
	 */
	public function findByMentionName(string $mentionName): Bot {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('mention_name', $qb->createNamedParameter($mentionName, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntity($qb);
	}

	/**
	 * Find all bots created by a specific user
	 *
	 * @param string $userId
	 * @return Bot[]
	 */
	public function findByUserId(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all bots in the system
	 *
	 * @return Bot[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all active bots
	 *
	 * @return Bot[]
	 */
	public function findAllActive(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			)
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all bots marked as public (and active by default)
	 *
	 * @param bool $onlyActive
	 * @return Bot[]
	 */
	public function findAllPublic(bool $onlyActive = true): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('is_public', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			);

		if ($onlyActive) {
			$qb->andWhere(
				$qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			);
		}

		$qb->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all bots with a specific approval status
	 *
	 * @param string $status 'draft', 'pending', 'approved', 'personal'
	 * @return Bot[]
	 */
	public function findByApprovalStatus(string $status): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('approval_status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR))
			)
			->orderBy('submitted_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all bots for a user with a specific approval status
	 *
	 * @param string $userId
	 * @param string $status 'draft', 'pending', 'approved', 'personal'
	 * @return Bot[]
	 */
	public function findByUserIdAndStatus(string $userId, string $status): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('approval_status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR))
			)
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}
}


