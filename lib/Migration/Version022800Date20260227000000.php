<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Track embedding model per vector row to avoid cross-model similarity comparisons.
 */
class Version022800Date20260227000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string,mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('educai_embeddings')) {
            $table = $schema->getTable('educai_embeddings');
            if (!$table->hasColumn('embedding_model')) {
                $table->addColumn('embedding_model', Types::STRING, [
                    'length' => 255,
                    'notnull' => false,
                ]);
            }
            if (!$table->hasIndex('educai_emb_bot_model_i')) {
                $table->addIndex(['bot_id', 'embedding_model'], 'educai_emb_bot_model_i');
            }
        }

        if ($schema->hasTable('educai_catalogue_emb')) {
            $table = $schema->getTable('educai_catalogue_emb');
            if (!$table->hasColumn('embedding_model')) {
                $table->addColumn('embedding_model', Types::STRING, [
                    'length' => 255,
                    'notnull' => false,
                ]);
            }
            if (!$table->hasIndex('educai_cat_emb_mod_idx')) {
                $table->addIndex(['is_past', 'embedding_model'], 'educai_cat_emb_mod_idx');
            }
        }

        return $schema;
    }
}
