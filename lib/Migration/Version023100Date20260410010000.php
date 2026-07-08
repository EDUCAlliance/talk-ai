<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add dedicated embedding rate limit settings.
 */
class Version023100Date20260410010000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string,mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('educai_settings')) {
            return $schema;
        }

        $table = $schema->getTable('educai_settings');

        if (!$table->hasColumn('embedding_rate_limit_mode')) {
            $table->addColumn('embedding_rate_limit_mode', Types::STRING, [
                'notnull' => false,
                'default' => 'inherit',
                'length' => 16,
            ]);
        }

        if (!$table->hasColumn('embedding_rate_limit_second')) {
            $table->addColumn('embedding_rate_limit_second', Types::INTEGER, [
                'notnull' => false,
            ]);
        }

        if (!$table->hasColumn('embedding_rate_limit_minute')) {
            $table->addColumn('embedding_rate_limit_minute', Types::INTEGER, [
                'notnull' => false,
                'default' => 100,
            ]);
        }

        if (!$table->hasColumn('embedding_rate_limit_hour')) {
            $table->addColumn('embedding_rate_limit_hour', Types::INTEGER, [
                'notnull' => false,
                'default' => 2000,
            ]);
        }

        if (!$table->hasColumn('embedding_rate_limit_day')) {
            $table->addColumn('embedding_rate_limit_day', Types::INTEGER, [
                'notnull' => false,
                'default' => 4000,
            ]);
        }

        return $schema;
    }
}
