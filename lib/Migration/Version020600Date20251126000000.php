<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add progress tracking columns to bot_sources table for RAG indexing progress bar
 */
class Version020600Date20251126000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $tableName = 'educai_bot_sources';
        if ($schema->hasTable($tableName)) {
            $table = $schema->getTable($tableName);

            // Progress percentage (0-100)
            if (!$table->hasColumn('progress')) {
                $table->addColumn('progress', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 0,
                    'unsigned' => true,
                ]);
            }

            // Current item being processed
            if (!$table->hasColumn('progress_current')) {
                $table->addColumn('progress_current', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 0,
                    'unsigned' => true,
                ]);
            }

            // Total items to process
            if (!$table->hasColumn('progress_total')) {
                $table->addColumn('progress_total', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 0,
                    'unsigned' => true,
                ]);
            }

            // Current processing stage: collecting, extracting, chunking, embedding, storing
            if (!$table->hasColumn('progress_stage')) {
                $table->addColumn('progress_stage', Types::STRING, [
                    'notnull' => false,
                    'length' => 50,
                ]);
            }

            return $schema;
        }

        return null;
    }
}
