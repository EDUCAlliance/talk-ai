<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add built_in_tool_name column to educai_bot_tools table.
 * 
 * This allows storing either:
 * - tool_id (integer) for MCP tools from the tools table
 * - built_in_tool_name (string) for built-in tools like catalogue_search_courses
 * 
 * One of the two columns should be set for each row.
 */
class Version020800Date20251130000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('educai_bot_tools')) {
            $table = $schema->getTable('educai_bot_tools');

            // Add built_in_tool_name column for built-in tools
            if (!$table->hasColumn('built_in_tool_name')) {
                $table->addColumn('built_in_tool_name', 'string', [
                    'notnull' => false,
                    'length' => 100,
                    'default' => null,
                ]);
            }

            // Make tool_id nullable since we now support either tool_id OR built_in_tool_name
            if ($table->hasColumn('tool_id')) {
                $column = $table->getColumn('tool_id');
                $column->setNotnull(false);
            }
        }

        return $schema;
    }
}
