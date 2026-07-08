<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add description column to bots table for public listing display.
 */
class Version021500Date20251127000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('educai_bots')) {
            $table = $schema->getTable('educai_bots');

            if (!$table->hasColumn('description')) {
                $table->addColumn('description', Types::TEXT, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}





