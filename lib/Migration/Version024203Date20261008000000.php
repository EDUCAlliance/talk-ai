<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version024203Date20261008000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('educai_settings')) {
			return null;
		}
		$table = $schema->getTable('educai_settings');
		$changed = false;
		if (!$table->hasColumn('max_output_tokens')) {
			$table->addColumn('max_output_tokens', Types::INTEGER, [
				'notnull' => false,
				'default' => 32768,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('model_output_token_limits')) {
			$table->addColumn('model_output_token_limits', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
