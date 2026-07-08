<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020510Date20251112010000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('educai_tools')) {
			$table = $schema->createTable('educai_tools');
			$table->addColumn('id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('name', Types::STRING, [
				'length' => 190,
				'notnull' => true,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('mcp_endpoint_url', Types::STRING, [
				'length' => 255,
				'notnull' => true,
			]);
			$table->addColumn('authentication', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('capabilities', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('enabled', Types::BOOLEAN, [
				// Booleans must be nullable for Nextcloud's cross-db constraints (Oracle)
				'notnull' => false,
				// Store default as integer to avoid platform issues interpreting "false" as string
				'default' => 0,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['enabled'], 'educai_tools_enabled_idx');
		}

		if (!$schema->hasTable('educai_bot_tools')) {
			$table = $schema->createTable('educai_bot_tools');
			$table->addColumn('id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('bot_id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
			]);
			$table->addColumn('tool_id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
			]);
			$table->addColumn('config_override', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['bot_id', 'tool_id'], 'educai_bot_tools_bot_tool_uidx');
			$table->addIndex(['tool_id'], 'educai_bot_tools_tool_idx');
		}

		return $schema;
	}
}
