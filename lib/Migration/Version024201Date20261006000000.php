<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version024201Date20261006000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('educai_settings')) {
			return null;
		}
		$table = $schema->getTable('educai_settings');
		// Existing installations keep their endpoint, credentials and wire protocol.
		foreach (['docling_api_profile' => 'legacy', 'docling_auth_mode' => 'bearer'] as $name => $default) {
			if (!$table->hasColumn($name)) {
				$table->addColumn($name, Types::STRING, [
					'length' => 32,
					'notnull' => true,
					'default' => $default,
				]);
			}
		}
		return $schema;
	}
}
