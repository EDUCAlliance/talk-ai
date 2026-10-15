<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Embedding>
 */
class EmbeddingMapper extends QBMapper {
    /** Model marker of staged rows: batches of an index that is not complete yet. */
    public const STAGED_PREFIX = 'staged:';

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
     * Embeddings that may be used for answers: only those of an existing source of this
     * bot that its owner can still reach. Sources marked "unavailable" keep their vectors
     * but are left out, and so are vectors whose source was removed. Staged batches of an
     * unfinished index use their own model marker and never match.
     *
     * @return array<int,Embedding>
     */
    public function findRetrievableByBotAndModel(int $botId, string $embeddingModel): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('e.*')
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'educai_bot_sources', 's', $qb->expr()->eq('s.id', 'e.source_id'))
            ->where($qb->expr()->eq('e.bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('s.bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('e.embedding_model', $qb->createNamedParameter($embeddingModel, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->neq('s.status', $qb->createNamedParameter(KnowledgeSourceStatus::UNAVAILABLE, IQueryBuilder::PARAM_STR)));

        return $this->findEntities($qb);
    }

    /**
     * Vectors in the given model space that belong to sources their owner cannot reach.
     */
    public function countUnavailableByBotAndModel(int $botId, string $embeddingModel): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'educai_bot_sources', 's', $qb->expr()->eq('s.id', 'e.source_id'))
            ->where($qb->expr()->eq('e.bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('e.embedding_model', $qb->createNamedParameter($embeddingModel, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter(KnowledgeSourceStatus::UNAVAILABLE, IQueryBuilder::PARAM_STR)));

        return $this->fetchCount($qb);
    }

    /**
     * Published vectors of existing sources of the bot, in any model space.
     */
    public function countIndexedByBot(int $botId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName(), 'e')
            ->innerJoin('e', 'educai_bot_sources', 's', $qb->expr()->eq('s.id', 'e.source_id'))
            ->where($qb->expr()->eq('e.bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->publishedOnly($qb, 'e.embedding_model'));

        return $this->fetchCount($qb);
    }

    public function countBySourceAndModel(int $sourceId, string $embeddingModel): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($embeddingModel, IQueryBuilder::PARAM_STR)));

        return $this->fetchCount($qb);
    }

    /**
     * Chunk indexes already staged for an unfinished index of the source. Staged rows of
     * another generation (changed content, model or chunk settings) are deleted.
     *
     * @return array<int,true>
     */
    public function stagedChunkIndexes(int $sourceId, string $embeddingModel, string $generation): array {
        $prefix = self::stagedChunkPrefix($sourceId, $generation);
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'chunk_id', 'embedding_model')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('embedding_model', $qb->createNamedParameter(self::STAGED_PREFIX . '%', IQueryBuilder::PARAM_STR)));
        $result = $qb->executeQuery();
        $indexes = [];
        $stale = [];
        while (($row = $result->fetch()) !== false) {
            $chunkId = (string)$row['chunk_id'];
            if ($row['embedding_model'] === self::STAGED_PREFIX . $embeddingModel && str_starts_with($chunkId, $prefix)) {
                $indexes[(int)substr($chunkId, strlen($prefix))] = true;
            } else {
                $stale[] = (int)$row['id'];
            }
        }
        $result->closeCursor();

        foreach (array_chunk($stale, 500) as $ids) {
            $delete = $this->db->getQueryBuilder();
            $delete->delete($this->getTableName())
                ->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
            $delete->executeStatement();
        }

        return $indexes;
    }

    /**
     * Keep one embedded chunk of an unfinished index; it is not used for answers until published.
     */
    public function insertStaged(Embedding $embedding, int $chunkIndex, string $generation): void {
        $embedding->setChunkId(self::stagedChunkPrefix($embedding->getSourceId(), $generation) . $chunkIndex);
        $embedding->setEmbeddingModel(self::STAGED_PREFIX . (string)$embedding->getEmbeddingModel());
        try {
            $this->insert($embedding);
        } catch (DbException $e) {
            // Another run staged the same chunk of the same generation already.
            if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
        }
    }

    /**
     * Replace the published index of a source with its complete staged generation, in one
     * transaction: answers see either the old or the new index, never a mix.
     *
     * @throws \RuntimeException When staged chunks are missing; nothing is changed then.
     */
    public function publishStaged(int $sourceId, string $embeddingModel, string $generation, int $chunkCount): void {
        $prefix = self::stagedChunkPrefix($sourceId, $generation);
        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'chunk_id')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter(self::STAGED_PREFIX . $embeddingModel, IQueryBuilder::PARAM_STR)))
                ->andWhere($qb->expr()->like('chunk_id', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%', IQueryBuilder::PARAM_STR)))
                ->orderBy('id', 'ASC');
            $result = $qb->executeQuery();
            $rowIds = [];
            while (($row = $result->fetch()) !== false) {
                $index = (int)substr((string)$row['chunk_id'], strlen($prefix));
                if ($index >= 0 && $index < $chunkCount && !isset($rowIds[$index])) {
                    $rowIds[$index] = (int)$row['id'];
                }
            }
            $result->closeCursor();
            if (count($rowIds) !== $chunkCount) {
                throw new \RuntimeException('Staged index is incomplete: ' . count($rowIds) . ' of ' . $chunkCount . ' chunks');
            }

            $delete = $this->db->getQueryBuilder();
            $delete->delete($this->getTableName())
                ->where($delete->expr()->eq('source_id', $delete->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($this->publishedOnly($delete, 'embedding_model'));
            $delete->executeStatement();

            foreach ($rowIds as $index => $rowId) {
                $update = $this->db->getQueryBuilder();
                $update->update($this->getTableName())
                    ->set('chunk_id', $update->createNamedParameter($sourceId . ':' . $index, IQueryBuilder::PARAM_STR))
                    ->set('embedding_model', $update->createNamedParameter($embeddingModel, IQueryBuilder::PARAM_STR))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)));
                $update->executeStatement();
            }

            // Leftovers of other generations or duplicate staging runs.
            $leftover = $this->db->getQueryBuilder();
            $leftover->delete($this->getTableName())
                ->where($leftover->expr()->eq('source_id', $leftover->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($leftover->expr()->like('embedding_model', $leftover->createNamedParameter(self::STAGED_PREFIX . '%', IQueryBuilder::PARAM_STR)));
            $leftover->executeStatement();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private static function stagedChunkPrefix(int $sourceId, string $generation): string {
        return 'stg:' . $sourceId . ':' . substr($generation, 0, 12) . ':';
    }

    /**
     * Rows that are not staged; legacy rows may have no model.
     */
    private function publishedOnly(IQueryBuilder $qb, string $column): \OCP\DB\QueryBuilder\ICompositeExpression {
        return $qb->expr()->orX(
            $qb->expr()->isNull($column),
            $qb->expr()->notLike($column, $qb->createNamedParameter(self::STAGED_PREFIX . '%', IQueryBuilder::PARAM_STR))
        );
    }

    private function fetchCount(IQueryBuilder $qb): int {
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
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
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->publishedOnly($qb, 'embedding_model'));

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
