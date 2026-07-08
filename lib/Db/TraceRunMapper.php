<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TraceRun>
 */
class TraceRunMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_trace_runs', TraceRun::class);
	}

	public function insertRun(TraceRun $run): TraceRun {
		return $this->insert($run);
	}

	/**
	 * Internal lookup for trace write finalization.
	 */
	public function findById(int $id): TraceRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByIdForUser(int $id, string $userId): TraceRun {
		$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($this->buildUserScope($qb, $userId));

		return $this->findEntity($qb);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return TraceRun[]
	 */
	public function findForUser(string $userId, array $filters = [], int $limit = 25, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($this->buildUserScope($qb, $userId))
				->orderBy('started_at', 'DESC')
			->setMaxResults(max(1, min(100, $limit)))
			->setFirstResult(max(0, $offset));

		$this->applyFilters($qb, $filters);

		return $this->findEntities($qb);
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	public function countForUser(string $userId, array $filters = []): int {
		$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'count'))
				->from($this->getTableName())
				->where($this->buildUserScope($qb, $userId));

		$this->applyFilters($qb, $filters);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['count'] ?? 0);
	}

	public function markFinished(int $id, string $status, ?string $errorSummary, int $finishedAt, int $durationMs): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR))
			->set('error_summary', $errorSummary === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter($errorSummary, IQueryBuilder::PARAM_STR))
			->set('finished_at', $qb->createNamedParameter($finishedAt, IQueryBuilder::PARAM_INT))
			->set('duration_ms', $qb->createNamedParameter($durationMs, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($finishedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('running', IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}

	public function incrementCounters(int $id, bool $isToolCall): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('event_count', $qb->createFunction('event_count + 1'))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		if ($isToolCall) {
			$qb->set('tool_call_count', $qb->createFunction('tool_call_count + 1'));
		}

		return $qb->executeStatement();
	}

	public function addLlmTokenUsage(int $id, int $promptTokens, int $completionTokens, int $totalTokens): int {
		$promptTokens = max(0, $promptTokens);
		$completionTokens = max(0, $completionTokens);
		$totalTokens = max(0, $totalTokens);

		if ($promptTokens === 0 && $completionTokens === 0 && $totalTokens === 0) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		if ($promptTokens > 0) {
			$qb->set('prompt_token_count', $qb->createFunction('COALESCE(prompt_token_count, 0) + ' . $promptTokens));
		}
		if ($completionTokens > 0) {
			$qb->set('completion_token_count', $qb->createFunction('COALESCE(completion_token_count, 0) + ' . $completionTokens));
		}
		if ($totalTokens > 0) {
			$qb->set('total_token_count', $qb->createFunction('COALESCE(total_token_count, 0) + ' . $totalTokens));
		}

		return $qb->executeStatement();
	}

	public function deleteForUser(int $id, string $userId): int {
		$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($this->buildUserScope($qb, $userId));

		return $qb->executeStatement();
	}

	/**
	 * @return int deleted row count
	 */
	public function deleteAllForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($this->buildUserScope($qb, $userId));

		return $qb->executeStatement();
	}

	public function deleteOlderThan(int $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void {
		if (isset($filters['botId']) && is_numeric($filters['botId'])) {
			$qb->andWhere($qb->expr()->eq('bot_id', $qb->createNamedParameter((int)$filters['botId'], IQueryBuilder::PARAM_INT)));
		}

		if (isset($filters['botMentionName']) && is_string($filters['botMentionName']) && trim($filters['botMentionName']) !== '') {
			$term = '%' . $this->db->escapeLikeParameter(trim($filters['botMentionName'])) . '%';
			$qb->andWhere($qb->expr()->like('bot_mention_name', $qb->createNamedParameter($term, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'], IQueryBuilder::PARAM_STR)));
		}

		if (!empty($filters['onlyErrors'])) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('error', IQueryBuilder::PARAM_STR)));
		}

		if (!empty($filters['onlyWithTools'])) {
			$qb->andWhere($qb->expr()->gt('tool_call_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}

		if (isset($filters['from']) && is_numeric($filters['from'])) {
			$qb->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter((int)$filters['from'], IQueryBuilder::PARAM_INT)));
		}

		if (isset($filters['to']) && is_numeric($filters['to'])) {
			$qb->andWhere($qb->expr()->lte('started_at', $qb->createNamedParameter((int)$filters['to'], IQueryBuilder::PARAM_INT)));
		}

		if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
			$term = '%' . $this->db->escapeLikeParameter(trim($filters['q'])) . '%';
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->like('user_message_preview', $qb->createNamedParameter($term, IQueryBuilder::PARAM_STR)),
					$qb->expr()->like('error_summary', $qb->createNamedParameter($term, IQueryBuilder::PARAM_STR)),
					$qb->expr()->like('bot_mention_name', $qb->createNamedParameter($term, IQueryBuilder::PARAM_STR))
				)
			);
		}
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
