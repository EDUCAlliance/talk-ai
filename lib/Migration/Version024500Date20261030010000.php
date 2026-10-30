<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Admin-managed OpenAPI connections, their reviewed spec revisions and the
 * stable operation identities that tool names are derived from.
 * Additive only; see docs/adr/0001-openapi-connections.md.
 */
class Version024500Date20261030010000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('educai_api_conn')) {
			$table = $schema->createTable('educai_api_conn');
			self::addId($table);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			// NC30's cross-database checks require nullable booleans (Oracle).
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('base_url', Types::STRING, ['notnull' => false, 'length' => 2048]);
			$table->addColumn('auth_mode', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'none']);
			$table->addColumn('auth_header', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('credential', Types::TEXT, ['notnull' => false]);
			$table->addColumn('active_revision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('data_visibility', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('assign_policy', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'admins']);
			$table->addColumn('assign_groups', Types::TEXT, ['notnull' => false]);
			$table->addColumn('bot_visibilities', Types::TEXT, ['notnull' => false]);
			$table->addColumn('last_check_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('last_check_status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('last_check_message', Types::STRING, ['notnull' => false, 'length' => 512]);
			self::addAudit($table);
			$table->setPrimaryKey(['id']);
			$changed = true;
		}

		if (!$schema->hasTable('educai_api_rev')) {
			$table = $schema->createTable('educai_api_rev');
			self::addId($table);
			$table->addColumn('connection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('source_kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('source_url', Types::STRING, ['notnull' => false, 'length' => 2048]);
			$table->addColumn('document', Types::TEXT, ['notnull' => true]);
			$table->addColumn('oas_version', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('manifest', Types::TEXT, ['notnull' => true]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'draft']);
			$table->addColumn('op_config', Types::TEXT, ['notnull' => false]);
			$table->addColumn('approved_base_url', Types::STRING, ['notnull' => false, 'length' => 2048]);
			$table->addColumn('approved_auth', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('approved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('approved_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			self::addAudit($table);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['connection_id', 'status'], 'educai_api_rev_conn_i');
			$changed = true;
		}

		if (!$schema->hasTable('educai_api_op')) {
			$table = $schema->createTable('educai_api_op');
			self::addId($table);
			$table->addColumn('connection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('match_key', Types::STRING, ['notnull' => true, 'length' => 1100]);
			$table->addColumn('match_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Set only while the identity is live; NULLs never collide in a unique index.
			$table->addColumn('active_key', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('operation_id_hint', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('last_label', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('tombstoned_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['active_key'], 'educai_api_op_active_u');
			$table->addIndex(['connection_id', 'match_hash'], 'educai_api_op_match_i');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	private static function addId(object $table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
	}

	private static function addAudit(object $table): void {
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
	}
}
