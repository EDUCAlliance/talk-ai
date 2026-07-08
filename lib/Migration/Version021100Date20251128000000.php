<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add URL source support to RAG system.
 * 
 * This migration adds:
 * - source_url column to educai_bot_sources for storing URL sources
 * - Sets default value for node_id column (needed for URL sources)
 */
class Version021100Date20251128000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add source_url column to bot_sources table
        if ($schema->hasTable('educai_bot_sources')) {
            $table = $schema->getTable('educai_bot_sources');

            if (!$table->hasColumn('source_url')) {
                $table->addColumn('source_url', Types::TEXT, [
                    'notnull' => false,
                ]);
            }

            // Modify node_id to have a default value of 0 (needed for URL sources)
            if ($table->hasColumn('node_id')) {
                $table->changeColumn('node_id', [
                    'default' => 0,
                ]);
            }
        }

        return $schema;
    }
}

