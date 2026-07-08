<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add Docling document conversion settings to educai_settings table.
 * 
 * Docling allows converting PDF, DOCX, PPTX, and other binary documents
 * to Markdown format for RAG ingestion.
 */
class Version020900Date20251126200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('educai_settings')) {
            $table = $schema->getTable('educai_settings');

            // Enable/disable Docling document conversion
            if (!$table->hasColumn('docling_enabled')) {
                $table->addColumn('docling_enabled', Types::BOOLEAN, [
                    // notnull => false for cross-db compatibility
                    'notnull' => false,
                    'default' => 0,
                ]);
            }

            // Custom Docling API endpoint (optional, defaults to academiccloud)
            if (!$table->hasColumn('docling_api_endpoint')) {
                $table->addColumn('docling_api_endpoint', Types::STRING, [
                    'notnull' => false,
                    'length' => 512,
                ]);
            }
        }

        return $schema;
    }
}
