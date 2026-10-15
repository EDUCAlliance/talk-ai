<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Embedding>
 */
class EmbeddingMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'educai_embeddings', Embedding::class);
    }

    /**
     * @return array<int,Embedding>
     */
    public function findByBot(int $botId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * @return array<int,Embedding>
     */
    public function findByBotAndModel(int $botId, string $embeddingModel): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel, IQueryBuilder::PARAM_STR)));

        return $this->findEntities($qb);
    }

    /**
     * Embeddings that may be used for answers: sources the owner can no longer
     * reach (status "unavailable") keep their vectors but are left out.
     *
     * @return array<int,Embedding>
     */
    public function findRetrievableByBotAndModel(int $botId, string $embeddingModel): array {
        $excluded = $this->findUnavailableSourceIds($botId);
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel, IQueryBuilder::PARAM_STR)));
        if ($excluded !== []) {
            $qb->andWhere($qb->expr()->notIn('source_id', $qb->createNamedParameter($excluded, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        return $this->findEntities($qb);
    }

    /**
     * @return list<int>
     */
    private function findUnavailableSourceIds(int $botId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('educai_bot_sources')
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('unavailable', IQueryBuilder::PARAM_STR)));
        $result = $qb->executeQuery();
        $ids = [];
        while (($id = $result->fetchOne()) !== false) {
            $ids[] = (int)$id;
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * @return array<int,Embedding>
     */
    public function findBySource(int $sourceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    public function findOneByBotAndChunk(int $botId, string $chunkId): ?Embedding {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('chunk_id', $qb->createNamedParameter($chunkId, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function deleteBySource(int $sourceId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    public function deleteByBot(int $botId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    public function countByBot(int $botId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    public function countByBotAndModel(int $botId, string $embeddingModel): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }
}
