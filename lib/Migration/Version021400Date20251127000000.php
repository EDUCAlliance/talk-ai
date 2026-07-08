<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add customizable queue message for rate limiting.
 */
class Version021400Date20251127000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('educai_settings')) {
            $table = $schema->getTable('educai_settings');

            if (!$table->hasColumn('rate_limit_queue_message')) {
                $table->addColumn('rate_limit_queue_message', Types::TEXT, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}





