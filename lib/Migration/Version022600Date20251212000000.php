<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add onboarding_questions column to educai_bots table.
 * Stores the custom onboarding question tree as JSON with branching support.
 */
class Version022600Date20251212000000 extends SimpleMigrationStep {
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

			// JSON field to store onboarding question tree
			// Structure: {"start": "q1", "questions": [{id, text, answers: [{id, text, next}]}]}
			if (!$table->hasColumn('onboarding_questions')) {
				$table->addColumn('onboarding_questions', Types::TEXT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}

