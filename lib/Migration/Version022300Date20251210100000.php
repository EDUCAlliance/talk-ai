<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add approval questionnaire fields and testing enable flag to educai_bots.
 */
class Version022300Date20251210100000 extends SimpleMigrationStep {
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

			$fields = [
				'approval_reason' => Types::TEXT,
				'bot_capabilities' => Types::TEXT,
				'rag_source_description' => Types::TEXT,
				'testing_description' => Types::TEXT,
				'rejection_reason' => Types::TEXT,
			];

			foreach ($fields as $column => $type) {
				if (!$table->hasColumn($column)) {
					$table->addColumn($column, $type, [
						'notnull' => false,
					]);
				}
			}

			if (!$table->hasColumn('testing_enabled_by')) {
				$table->addColumn('testing_enabled_by', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
			}
		}

		return $schema;
	}
}


