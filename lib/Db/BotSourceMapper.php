<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BotSource>
 */
class BotSourceMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'educai_bot_sources', BotSource::class);
    }

    public function findById(int $id): BotSource {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @return array<int,BotSource>
     */
    public function findByBot(int $botId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find all sources (for cleanup jobs)
     * @return array<int,BotSource>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        return $this->findEntities($qb);
    }

    public function findOneByBotAndNode(int $botId, int $nodeId): ?BotSource {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            $entity = $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }

        return $entity instanceof BotSource ? $entity : null;
    }

    public function findOneByBotAndUrl(int $botId, string $url): ?BotSource {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('source_url', $qb->createNamedParameter($url, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);

        try {
            $entity = $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }

        return $entity instanceof BotSource ? $entity : null;
    }

    public function deleteById(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    public function deleteByBot(int $botId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
