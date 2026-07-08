<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020200Date20251102000000 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add per-bot model column
        if ($schema->hasTable('educai_bots')) {
            $table = $schema->getTable('educai_bots');
            if (!$table->hasColumn('model')) {
                $table->addColumn('model', Types::STRING, [
                    'notnull' => false,
                    'length' => 150,
                ]);
            }
        }

        // Add multi-model settings columns
        if ($schema->hasTable('educai_settings')) {
            $table = $schema->getTable('educai_settings');
            if (!$table->hasColumn('allow_multiple_models')) {
                $table->addColumn('allow_multiple_models', Types::BOOLEAN, [
                    // booleans may be nullable for cross-db compatibility
                    'notnull' => false,
                    'default' => 0,
                ]);
            }
            if (!$table->hasColumn('allowed_models')) {
                $table->addColumn('allowed_models', Types::TEXT, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}



