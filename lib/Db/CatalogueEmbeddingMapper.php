<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CatalogueEmbedding>
 */
class CatalogueEmbeddingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_catalogue_emb', CatalogueEmbedding::class);
	}

	/**
	 * Find embedding by course ID and past status
	 *
	 * @param int $courseId
	 * @param int|bool $isPast 0/false = current, 1/true = past
	 * @throws DoesNotExistException
	 */
	public function findByCourseIdAndPast(int $courseId, int|bool $isPast): CatalogueEmbedding {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
			->andWhere($qb->expr()->eq('is_past', $qb->createNamedParameter($isPast ? 1 : 0)));

		return $this->findEntity($qb);
	}

	/**
	 * Get all embeddings
	 *
	 * @return CatalogueEmbedding[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());

		return $this->findEntities($qb);
	}

	/**
	 * Get all current (non-past) embeddings
	 *
	 * @return CatalogueEmbedding[]
	 */
	public function findAllCurrent(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_past', $qb->createNamedParameter(0)));

		return $this->findEntities($qb);
	}

	/**
	 * Get all past embeddings
	 *
	 * @return CatalogueEmbedding[]
	 */
	public function findAllPast(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_past', $qb->createNamedParameter(1)));

		return $this->findEntities($qb);
	}

	/**
	 * Get all current embeddings for a specific embedding model
	 *
	 * @return CatalogueEmbedding[]
	 */
	public function findAllCurrentByModel(string $embeddingModel): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_past', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel)));

		return $this->findEntities($qb);
	}

	/**
	 * Get all past embeddings for a specific embedding model
	 *
	 * @return CatalogueEmbedding[]
	 */
	public function findAllPastByModel(string $embeddingModel): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_past', $qb->createNamedParameter(1)))
			->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel)));

		return $this->findEntities($qb);
	}

	/**
	 * Get total count of embeddings
	 */
	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName());

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Delete all embeddings (for full reindex)
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->executeStatement();
	}

	/**
	 * Check if a course exists with given past status
	 *
	 * @param int $courseId
	 * @param int|bool $isPast 0/false = current, 1/true = past
	 */
	public function courseExists(int $courseId, int|bool $isPast): bool {
		try {
			$this->findByCourseIdAndPast($courseId, $isPast);
			return true;
		} catch (DoesNotExistException $e) {
			return false;
		}
	}

	/**
	 * Upsert a catalogue embedding (insert or update based on course_id and is_past)
	 */
	public function upsert(CatalogueEmbedding $entity): CatalogueEmbedding {
		try {
			$existing = $this->findByCourseIdAndPast($entity->getCourseId(), $entity->getIsPast());
			$entity->setId($existing->getId());
			return $this->update($entity);
		} catch (DoesNotExistException $e) {
			return $this->insert($entity);
		}
	}

	/**
	 * Get count of current (non-past) embeddings
	 */
	public function countCurrent(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('is_past', $qb->createNamedParameter(0)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Get count of past embeddings
	 */
	public function countPast(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('is_past', $qb->createNamedParameter(1)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function countCurrentByModel(string $embeddingModel): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('is_past', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function countPastByModel(string $embeddingModel): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('is_past', $qb->createNamedParameter(1)))
			->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Delete embeddings not in the given course IDs list
	 *
	 * @param int[] $currentCourseIds Course IDs to keep (is_past=0)
	 * @param int[] $pastCourseIds Course IDs to keep (is_past=1)
	 * @return int Number of deleted records
	 */
	public function deleteStale(array $currentCourseIds, array $pastCourseIds): int {
		$deleted = 0;

		// Delete stale current courses
		if (count($currentCourseIds) > 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('is_past', $qb->createNamedParameter(0)))
				->andWhere($qb->expr()->notIn('course_id', $qb->createNamedParameter($currentCourseIds, $qb::PARAM_INT_ARRAY)));
			$deleted += $qb->executeStatement();
		} else {
			// No current courses - delete all current
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('is_past', $qb->createNamedParameter(0)));
			$deleted += $qb->executeStatement();
		}

		// Delete stale past courses
		if (count($pastCourseIds) > 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('is_past', $qb->createNamedParameter(1)))
				->andWhere($qb->expr()->notIn('course_id', $qb->createNamedParameter($pastCourseIds, $qb::PARAM_INT_ARRAY)));
			$deleted += $qb->executeStatement();
		} else {
			// No past courses - delete all past
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('is_past', $qb->createNamedParameter(1)));
			$deleted += $qb->executeStatement();
		}

		return $deleted;
	}
}
