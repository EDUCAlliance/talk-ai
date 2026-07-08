<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
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

			// Change webhook_secret from varchar(255) to text to hold encrypted values
			// Encrypted values are ~300+ characters
			if ($table->hasColumn('webhook_secret')) {
				$column = $table->getColumn('webhook_secret');
				// Change to TEXT type which can hold large encrypted values
				$column->setType(\Doctrine\DBAL\Types\Type::getType(Types::TEXT));
				$column->setLength(null); // TEXT doesn't need length
			}

			// Change catalogue_api_key from varchar(512) to text for consistency
			if ($table->hasColumn('catalogue_api_key')) {
				$column = $table->getColumn('catalogue_api_key');
				$column->setType(\Doctrine\DBAL\Types\Type::getType(Types::TEXT));
				$column->setLength(null);
			}

			return $schema;
		}

		return null;
	}
}
