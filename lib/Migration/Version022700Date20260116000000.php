<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to fix credential column sizes for encrypted storage.
 * 
 * CRIT-03 security fix introduced encrypted credential storage, but
 * some columns (webhook_secret, catalogue_api_key) were too small
 * to hold the encrypted values, causing truncation and decryption failures.
 */
class Version022700Date20260116000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('educai_settings')) {
			$table = $schema->getTable('educai_settings');

			foreach (['webhook_secret', 'catalogue_api_key'] as $name) {
				if ($table->hasColumn($name)) {
					$column = $table->getColumn($name);
					// NC35 exposes IColumn; older versions return Doctrine columns.
					$column->setType($column instanceof IColumn ? Types::TEXT : Type::getType(Types::TEXT));
					$column->setLength(null);
				}
			}

			return $schema;
		}

		return null;
	}
}
