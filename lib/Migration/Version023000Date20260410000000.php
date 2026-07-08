<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Reset cached rate limit state so chat and embeddings can rebuild independently.
 *
 * Previous app versions stored chat and embedding headers in the same runtime row.
 * Clearing the cache is safe because the table only contains ephemeral provider state.
 */
class Version023000Date20260410000000 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->connection->getQueryBuilder();
        $deleted = $qb->delete('educai_rl_state')->executeStatement();
        $output->info("Cleared {$deleted} cached rate limit state row(s).");
    }
}
