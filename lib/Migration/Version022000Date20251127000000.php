<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add approval workflow columns to bots table.
 * - approval_status: 'draft', 'pending', 'approved', 'personal'
 * - submitted_at: timestamp when bot was submitted for approval
 * - approved_by: user ID who approved the bot
 * - approved_at: timestamp when bot was approved
 */
class Version022000Date20251127000000 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('educai_bots')) {
            $table = $schema->getTable('educai_bots');

            // Approval status: draft (not submitted), pending (awaiting approval), 
            // approved (live), personal (just for me - no approval needed)
            if (!$table->hasColumn('approval_status')) {
                $table->addColumn('approval_status', Types::STRING, [
                    'notnull' => false,
                    'length' => 20,
                    'default' => 'approved', // New bots default to approved
                ]);
            }

            // Timestamp when bot was submitted for approval
            if (!$table->hasColumn('submitted_at')) {
                $table->addColumn('submitted_at', Types::BIGINT, [
                    'notnull' => false,
                    'unsigned' => true,
                ]);
            }

            // User ID who approved the bot
            if (!$table->hasColumn('approved_by')) {
                $table->addColumn('approved_by', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                ]);
            }

            // Timestamp when bot was approved
            if (!$table->hasColumn('approved_at')) {
                $table->addColumn('approved_at', Types::BIGINT, [
                    'notnull' => false,
                    'unsigned' => true,
                ]);
            }

            // Add index on approval_status for efficient queries
            if (!$table->hasIndex('educai_bots_approval_idx')) {
                $table->addIndex(['approval_status'], 'educai_bots_approval_idx');
            }
        }

        return $schema;
    }

    /**
     * Update existing bots to have 'approved' status.
     * This ensures legacy bots created before this migration are treated as approved.
     */
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

