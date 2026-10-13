<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Provider-qualified references must fit without truncating older model IDs. */
class Version024300Date20261013000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;
		foreach (['educai_settings' => ['default_model', 'fallback_model'], 'educai_bots' => ['model']] as $tableName => $columns) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			// SQLite introspection treats an integer primary key as autoincrement.
			// This singleton settings row uses a fixed ID; retain its declared
			// schema when DBAL rebuilds the table for the wider model columns.
			if ($tableName === 'educai_settings' && $table->hasColumn('id') && $table->getColumn('id')->getAutoincrement()) {
				$table->changeColumn('id', ['autoincrement' => false]);
				$changed = true;
			}
			foreach ($columns as $column) {
				if ($table->hasColumn($column) && $table->getColumn($column)->getLength() < 512) {
					// Preserve defaults and nullability, including inherited bot models.
					$table->changeColumn($column, ['length' => 512]);
					$changed = true;
				}
			}
		}
		return $changed ? $schema : null;
	}
}
