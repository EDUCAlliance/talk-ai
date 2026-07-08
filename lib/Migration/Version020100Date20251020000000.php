<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020100Date20251020000000 extends SimpleMigrationStep {

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
			if (!$table->hasColumn('is_public')) {
				$table->addColumn('is_public', Types::BOOLEAN, [
					// Booleans must be nullable for Nextcloud's cross-db constraints (Oracle)
					'notnull' => false,
					// Store default as integer to avoid platform issues interpreting "false" as string
					'default' => 0,
				]);
			}
		}

		return $schema;
	}
}


