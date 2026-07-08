<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Data migration: Set all legacy bots (with NULL approval_status) to 'approved'.
 * This ensures bots created before the approval workflow feature are treated as approved.
 */
class Version022100Date20251127000000 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // No schema changes, just data migration
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->connection->getQueryBuilder();

        // Update all bots with NULL approval_status to 'approved'
        $qb->update('educai_bots')
            ->set('approval_status', $qb->createNamedParameter('approved'))
            ->where($qb->expr()->isNull('approval_status'));

        $updated = $qb->executeStatement();
        $output->info("Updated {$updated} legacy bots to 'approved' status.");
    }
}

