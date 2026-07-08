<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020520Date20251112020000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Fix node_type column to have a default value
		if ($schema->hasTable('educai_bot_sources')) {
			$table = $schema->getTable('educai_bot_sources');
			if ($table->hasColumn('node_type')) {
				// Change the column to have a default value
				$table->changeColumn('node_type', [
					'length' => 16,
					'notnull' => true,
					'default' => 'file',
				]);
			}
		}

		return $schema;
	}
}
