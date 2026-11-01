<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ApiSpecRevision>
 */
class ApiSpecRevisionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_api_rev', ApiSpecRevision::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findById(int $id): ApiSpecRevision {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	/**
	 * Revisions without the stored document, newest first.
	 *
	 * @return list<ApiSpecRevision>
	 */
	public function findByConnection(int $connectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(ApiSpecRevision::STATUS_DISCARDED)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return list<ApiSpecRevision> */
	public function findDrafts(int $connectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ApiSpecRevision::STATUS_DRAFT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function deleteByConnection(int $connectionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
