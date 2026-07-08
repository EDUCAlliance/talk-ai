<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create catalogue embeddings table for semantic search and add reindex interval setting
 */
class Version021200Date20251201000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create catalogue embeddings table
        $tableName = 'educai_catalogue_emb';
        if (!$schema->hasTable($tableName)) {
            $table = $schema->createTable($tableName);
            $table->addColumn('id', Types::INTEGER, [
                'unsigned' => true,
                'notnull' => true,
                'autoincrement' => true,
            ]);
            $table->addColumn('course_id', Types::INTEGER, [
                'unsigned' => true,
                'notnull' => true,
            ]);
            $table->addColumn('searchable_text', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('embedding', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('course_data', Types::TEXT, [
                'notnull' => true,
            ]);
            // Distinguish current (0) vs past (1) opportunities
            $table->addColumn('is_past', Types::SMALLINT, [
                'notnull' => false,
                'default' => 0,
                'unsigned' => true,
            ]);
            $table->addColumn('created_at', Types::INTEGER, [
                'unsigned' => true,
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('updated_at', Types::INTEGER, [
                'unsigned' => true,
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['course_id', 'is_past'], 'educai_cat_emb_course_past_u');
            $table->addIndex(['is_past'], 'educai_cat_emb_is_past_idx');
        }

        // Add catalogue reindex settings
        $settingsTable = 'educai_settings';
        if ($schema->hasTable($settingsTable)) {
            $table = $schema->getTable($settingsTable);

            // Reindex interval in hours (default: 24)
            if (!$table->hasColumn('catalogue_reindex_hours')) {
                $table->addColumn('catalogue_reindex_hours', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 24,
                    'unsigned' => true,
                ]);
            }

            // Last indexed timestamp
            if (!$table->hasColumn('catalogue_last_indexed')) {
                $table->addColumn('catalogue_last_indexed', Types::INTEGER, [
                    'notnull' => false,
                    'unsigned' => true,
                ]);
            }

            // Total courses indexed
            if (!$table->hasColumn('catalogue_course_count')) {
                $table->addColumn('catalogue_course_count', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 0,
                    'unsigned' => true,
                ]);
            }
        }

        return $schema;
    }
}

