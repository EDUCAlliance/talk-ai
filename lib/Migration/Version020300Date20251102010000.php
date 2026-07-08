<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020300Date20251102010000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('educai_bots')) {
			$table = $schema->getTable('educai_bots');

			// Add visibility column: 'global' or 'groups' (default to 'groups' for non-public bots)
			if (!$table->hasColumn('visibility')) {
				$table->addColumn('visibility', Types::STRING, [
					'notnull' => false,
					'length' => 16,
					'default' => 'groups',
				]);
			}

			// Add allowed_groups column storing JSON array of group IDs
			if (!$table->hasColumn('allowed_groups')) {
				$table->addColumn('allowed_groups', Types::TEXT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}



