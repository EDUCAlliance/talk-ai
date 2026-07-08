<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version023500Date20260424010000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('educai_settings')) {
			$table = $schema->getTable('educai_settings');
			if ($table->hasColumn('default_model')) {
				$table->changeColumn('default_model', [
					'length' => 200,
					'notnull' => true,
					'default' => 'llama-3.3-70b-instruct',
				]);
			}
			if (!$table->hasColumn('secondary_api_endpoint')) {
				$table->addColumn('secondary_api_endpoint', Types::STRING, [
					'notnull' => false,
					'length' => 255,
				]);
			}
			if (!$table->hasColumn('secondary_api_key')) {
				$table->addColumn('secondary_api_key', Types::TEXT, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('fallback_model')) {
				$table->addColumn('fallback_model', Types::STRING, [
					'notnull' => false,
					'length' => 200,
				]);
			}
			if (!$table->hasColumn('llm_chat_timeout')) {
				$table->addColumn('llm_chat_timeout', Types::INTEGER, [
					'notnull' => false,
					'default' => 90,
				]);
			}
			if (!$table->hasColumn('llm_stream_timeout')) {
				$table->addColumn('llm_stream_timeout', Types::INTEGER, [
					'notnull' => false,
					'default' => 240,
				]);
			}
			if (!$table->hasColumn('llm_models_timeout')) {
				$table->addColumn('llm_models_timeout', Types::INTEGER, [
					'notnull' => false,
					'default' => 20,
				]);
			}
		}

		if ($schema->hasTable('educai_bots')) {
			$table = $schema->getTable('educai_bots');
			if ($table->hasColumn('model')) {
				$table->changeColumn('model', [
					'length' => 200,
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
