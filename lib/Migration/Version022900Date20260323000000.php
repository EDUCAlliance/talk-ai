<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add minute-level rate limit tracking for GWDG-compatible providers.
 */
class Version022900Date20260323000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string,mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('educai_rl_state')) {
            $table = $schema->getTable('educai_rl_state');

            if (!$table->hasColumn('limit_minute')) {
                $table->addColumn('limit_minute', Types::INTEGER, [
                    'notnull' => false,
                ]);
            }

            if (!$table->hasColumn('remaining_minute')) {
                $table->addColumn('remaining_minute', Types::INTEGER, [
                    'notnull' => false,
                ]);
            }

            if (!$table->hasColumn('reset_minute_at')) {
                $table->addColumn('reset_minute_at', Types::BIGINT, [
                    'notnull' => false,
                    'unsigned' => true,
                ]);
            }
        }

        if ($schema->hasTable('educai_settings')) {
            $table = $schema->getTable('educai_settings');

            if (!$table->hasColumn('rate_limit_minute')) {
                $table->addColumn('rate_limit_minute', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 30,
                ]);
            }
        }

        return $schema;
    }
}
