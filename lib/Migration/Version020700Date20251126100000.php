<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add Course Catalogue integration settings columns
 */
class Version020700Date20251126100000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $tableName = 'educai_settings';
        if ($schema->hasTable($tableName)) {
            $table = $schema->getTable($tableName);

            // Enable/disable the catalogue integration
            if (!$table->hasColumn('catalogue_enabled')) {
                $table->addColumn('catalogue_enabled', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => 0,
                ]);
            }

            // The base URL for the catalogue API (e.g., https://catalogue.example.com/api)
            if (!$table->hasColumn('catalogue_api_endpoint')) {
                $table->addColumn('catalogue_api_endpoint', Types::STRING, [
                    'notnull' => false,
                    'length' => 512,
                ]);
            }

            // Optional API key for authenticated catalogue endpoints.
            if (!$table->hasColumn('catalogue_api_key')) {
                $table->addColumn('catalogue_api_key', Types::STRING, [
                    'notnull' => false,
                    'length' => 512,
                ]);
            }

            return $schema;
        }

        return null;
    }
}
