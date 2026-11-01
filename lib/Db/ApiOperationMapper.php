<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ApiOperation>
 */
class ApiOperationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_api_op', ApiOperation::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findById(int $id): ApiOperation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	/**
	 * The live identity for a match key, if it has not been tombstoned.
	 */
	public function findActive(int $connectionId, string $matchKey): ?ApiOperation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('active_key', $qb->createNamedParameter(ApiOperation::activeKeyFor($connectionId, $matchKey))))
			->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	/**
	 * Identities that were part of an approved revision.
	 *
	 * @return list<ApiOperation>
	 */
	public function findLabeled(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('last_label'))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByConnection(int $connectionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * All identities of a connection, including tombstones.
	 *
	 * @return list<ApiOperation>
	 */
	public function findByConnection(int $connectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $ids
	 * @return list<ApiOperation>
	 */
	public function findByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values($ids), IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->findEntities($qb);
	}
}
