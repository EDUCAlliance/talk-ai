<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add is_past column to catalogue embeddings table for distinguishing current vs past opportunities
 */
class Version021300Date20251128000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $tableName = 'educai_catalogue_emb';
        if ($schema->hasTable($tableName)) {
            $table = $schema->getTable($tableName);

            // Add is_past column to distinguish current vs past opportunities
            if (!$table->hasColumn('is_past')) {
                $table->addColumn('is_past', Types::SMALLINT, [
                    // notnull => false for cross-db compatibility
                    'notnull' => false,
                    'default' => 0,
                    'unsigned' => true,
                ]);
            }

            // Drop old unique index on course_id only (if exists)
            // We need a composite index on (course_id, is_past) since the same course
            // could theoretically appear in both current and past (though unlikely)
            $indexes = $table->getIndexes();
            foreach ($indexes as $index) {
                if ($index->getName() === 'educai_cat_emb_course_u') {
                    $table->dropIndex('educai_cat_emb_course_u');
                    break;
                }
            }

            // Add new composite unique index
            if (!$table->hasIndex('educai_cat_emb_course_past_u')) {
                $table->addUniqueIndex(['course_id', 'is_past'], 'educai_cat_emb_course_past_u');
            }

            // Add index on is_past for efficient filtering
            if (!$table->hasIndex('educai_cat_emb_is_past_idx')) {
                $table->addIndex(['is_past'], 'educai_cat_emb_is_past_idx');
            }
        }

        return $schema;
    }
}

