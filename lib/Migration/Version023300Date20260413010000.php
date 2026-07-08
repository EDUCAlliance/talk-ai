<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCA\EducAI\Db\RoomDocumentTables;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add room-scoped document ingestion plus vision/speech settings.
 */
class Version023300Date20260413010000 extends SimpleMigrationStep {
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
			$this->ensureNullableStringColumn($table, 'vision_api_endpoint', 255);
			$this->ensureNullableTextColumn($table, 'vision_api_key');
			$this->ensureNullableStringColumn($table, 'vision_model', 255);
			$this->ensureNullableStringColumn($table, 'speech_api_endpoint', 255);
			$this->ensureNullableTextColumn($table, 'speech_api_key');
			$this->ensureNullableStringColumn($table, 'speech_model', 255);
		}

		if (!$schema->hasTable(RoomDocumentTables::SOURCES) && !$schema->hasTable(RoomDocumentTables::LEGACY_SOURCES)) {
			// Keep the physical table names short enough for stricter database backends.
			$table = $schema->createTable(RoomDocumentTables::SOURCES);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('bot_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('room_token', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('actor_id', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('message_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('node_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('attachment_id', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('display_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('mime_type', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('checksum', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'pending',
			]);
			$table->addColumn('error_message', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('last_indexed_at', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['bot_id', 'room_token'], 'educai_room_src_bot_room_i');
			$table->addIndex(['bot_id', 'room_token', 'node_id'], 'educai_room_src_node_i');
			$table->addIndex(['updated_at'], 'educai_room_src_updated_i');
		}

		if (!$schema->hasTable(RoomDocumentTables::EMBEDDINGS) && !$schema->hasTable(RoomDocumentTables::LEGACY_EMBEDDINGS)) {
			$table = $schema->createTable(RoomDocumentTables::EMBEDDINGS);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('bot_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('room_token', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('source_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('chunk_id', Types::STRING, [
				'notnull' => true,
				'length' => 191,
			]);
			$table->addColumn('chunk_text', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('embedding', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('embedding_model', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('token_count', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('metadata', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('score', Types::DECIMAL, [
				'notnull' => false,
				'precision' => 6,
				'scale' => 5,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['source_id'], 'educai_room_emb_src_i');
			$table->addIndex(['bot_id', 'room_token'], 'educai_room_emb_bot_room_i');
			$table->addIndex(['bot_id', 'room_token', 'embedding_model'], 'educai_room_emb_model_i');
		}

		return $schema;
	}

	private function ensureNullableStringColumn($table, string $name, int $length): void {
		if (!$table->hasColumn($name)) {
			$table->addColumn($name, Types::STRING, [
				'notnull' => false,
				'length' => $length,
			]);
		}
	}

	private function ensureNullableTextColumn($table, string $name): void {
		if (!$table->hasColumn($name)) {
			$table->addColumn($name, Types::TEXT, [
				'notnull' => false,
			]);
		}
	}
}
