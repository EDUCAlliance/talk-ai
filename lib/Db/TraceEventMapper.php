<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TraceEvent>
 */
class TraceEventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_trace_events', TraceEvent::class);
	}

	public function insertEvent(TraceEvent $event): TraceEvent {
		return $this->insert($event);
	}

	/**
	 * @return TraceEvent[]
	 */
	public function findByRunId(int $runId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)))
			->orderBy('sequence', 'ASC');

		return $this->findEntities($qb);
	}

	public function countByRunId(int $runId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['count'] ?? 0);
	}

	public function deleteByRunId(int $runId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Delete events belonging to all traces owned by a user.
	 */
	public function deleteForUser(string $userId): int {
		$select = $this->db->getQueryBuilder();
			$result = $select->select('id')
				->from('educai_trace_runs')
				->where($this->buildUserScope($select, $userId))
				->executeQuery();

		$deleted = 0;
		while (($row = $result->fetch()) !== false) {
			$deleted += $this->deleteByRunId((int)$row['id']);
		}
		$result->closeCursor();

		return $deleted;
	}

	public function deleteOlderThan(int $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Accept both the normalized Nextcloud UID and the ActivityPub actor ID used by Talk webhooks.
	 */
	private function buildUserScope(IQueryBuilder $qb, string $userId) {
		$variants = $this->userIdVariants($userId);
		$scope = null;

		foreach ($variants as $variant) {
			$comparison = $qb->expr()->eq('user_id', $qb->createNamedParameter($variant, IQueryBuilder::PARAM_STR));
			$scope = $scope === null ? $comparison : $qb->expr()->orX($scope, $comparison);
		}

		return $scope ?? $qb->expr()->eq('user_id', $qb->createNamedParameter('', IQueryBuilder::PARAM_STR));
	}

	/**
	 * @return list<string>
	 */
	private function userIdVariants(string $userId): array {
		$normalized = trim($userId);
		if (str_starts_with($normalized, 'users/')) {
			$normalized = substr($normalized, strlen('users/'));
		}

		$variants = [];
		if ($normalized !== '') {
			$variants[] = $normalized;
			$variants[] = 'users/' . $normalized;
		}

		$original = trim($userId);
		if ($original !== '') {
			$variants[] = $original;
		}

		return array_values(array_unique($variants));
	}
}
