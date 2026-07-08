<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add pending_changes column for bot versioning during approval workflow.
 * Stores pending edits as JSON while keeping approved version live.
 */
class Version022400Date20251211000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$tableName = 'educai_bots';
		if ($schema->hasTable($tableName)) {
			$table = $schema->getTable($tableName);

			// JSON field to store pending changes when owner edits an approved bot
			if (!$table->hasColumn('pending_changes')) {
				$table->addColumn('pending_changes', Types::TEXT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}

