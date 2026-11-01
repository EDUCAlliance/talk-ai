<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Course-page link configuration. Existing installations stay without a
 * template or accepted origin until an administrator chooses one.
 */
class Version024400Date20261030000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('educai_settings')) {
			return null;
		}
		$table = $schema->getTable('educai_settings');
		$changed = false;
		if ($table->hasColumn('id') && $table->getColumn('id')->getAutoincrement()) {
			// SQLite introspection reports the fixed singleton ID as autoincrement;
			// keep it fixed when DBAL rebuilds the table for the new columns.
			$table->changeColumn('id', ['autoincrement' => false]);
		}
		foreach ([
			'catalogue_link_field' => [Types::STRING, ['notnull' => false, 'length' => 512]],
			'catalogue_link_template' => [Types::TEXT, ['notnull' => false]],
			'catalogue_link_origins' => [Types::TEXT, ['notnull' => false]],
		] as $column => [$type, $columnOptions]) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, $type, $columnOptions);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
