<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<WikiRoot>
 */
class WikiRootMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_wiki_roots', WikiRoot::class);
	}

	public function findById(int $id): WikiRoot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	public function findOneByRootNodeId(int $rootNodeId): ?WikiRoot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('root_node_id', $qb->createNamedParameter($rootNodeId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * @param array<int,int> $nodeIds
	 * @return array<int,WikiRoot>
	 */
	public function findActiveByRootNodeIds(array $nodeIds): array {
		$nodeIds = array_values(array_unique(array_filter($nodeIds, static fn (int $id): bool => $id > 0)));
		if ($nodeIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'root_node_id',
				$qb->createNamedParameter($nodeIds, IQueryBuilder::PARAM_INT_ARRAY)
			))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		return $this->findEntities($qb);
	}

	/**
	 * @return array<int,WikiRoot>
	 */
	public function findAllActive(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		return $this->findEntities($qb);
	}
}
