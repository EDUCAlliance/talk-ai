<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Align wiki "active" flags created as NOT NULL by 2.37.1–2.41.0 with the
 * nullable booleans that Nextcloud's cross-database checks require.
 */
class Version024202Date20261007000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (['educai_wiki_roots', 'educai_wiki_root_bots'] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if (!$table->hasColumn('active')) {
				continue;
			}
			$column = $table->getColumn('active');
			if ($column->getNotnull()) {
				$column->setNotnull(false);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
