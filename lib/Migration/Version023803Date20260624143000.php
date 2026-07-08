<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Track aggregated LLM token usage for personal activity traces.
 */
class Version023803Date20260624143000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('educai_trace_runs')) {
			return $schema;
		}

		$table = $schema->getTable('educai_trace_runs');
		if (!$table->hasColumn('prompt_token_count')) {
			$table->addColumn('prompt_token_count', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
			]);
		}
		if (!$table->hasColumn('completion_token_count')) {
			$table->addColumn('completion_token_count', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
			]);
		}
		if (!$table->hasColumn('total_token_count')) {
			$table->addColumn('total_token_count', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
			]);
		}

		return $schema;
	}
}
