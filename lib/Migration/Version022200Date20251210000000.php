<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add conversation_context_tokens setting for token-based conversation memory limit
 */
class Version022200Date20251210000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $settingsTable = 'educai_settings';
        if ($schema->hasTable($settingsTable)) {
            $table = $schema->getTable($settingsTable);

            // Token limit for conversation context (default: 8000)
            if (!$table->hasColumn('conversation_context_tokens')) {
                $table->addColumn('conversation_context_tokens', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 8000,
                    'unsigned' => true,
                ]);
            }
        }

        return $schema;
    }
}

