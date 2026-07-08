<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BotTool>
 */
class BotToolMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'educai_bot_tools', BotTool::class);
    }

    /**
     * @return array<int,BotTool>
     */
    public function findByBot(int $botId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * @param array<int,string> $names
     * @return array<int,BotTool>
     */
    public function findByBuiltInToolNames(array $names): array {
        $names = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
        if ($names === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'built_in_tool_name',
                $qb->createNamedParameter($names, IQueryBuilder::PARAM_STR_ARRAY)
            ));

        return $this->findEntities($qb);
    }

    public function deleteByBot(int $botId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($botId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
