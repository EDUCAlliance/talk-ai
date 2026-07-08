<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCA\EducAI\Db\RoomImageTables;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add room-scoped image memory for Talk image attachments.
 */
class Version023700Date20260503010000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(RoomImageTables::SOURCES) && !$schema->hasTable(RoomImageTables::LEGACY_SOURCES)) {
			$table = $schema->createTable(RoomImageTables::SOURCES);
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
			$table->addColumn('vision_model', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('analysis_text', Types::TEXT, [
				'notnull' => false,
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
			$table->addIndex(['bot_id', 'room_token'], 'educai_room_img_src_room_i');
			$table->addIndex(['bot_id', 'room_token', 'node_id'], 'educai_room_img_src_node_i');
			$table->addIndex(['updated_at'], 'educai_room_img_src_upd_i');
		}

		if (!$schema->hasTable(RoomImageTables::EMBEDDINGS) && !$schema->hasTable(RoomImageTables::LEGACY_EMBEDDINGS)) {
			$table = $schema->createTable(RoomImageTables::EMBEDDINGS);
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
			$table->addIndex(['source_id'], 'educai_room_img_emb_src_i');
			$table->addIndex(['bot_id', 'room_token'], 'educai_room_img_emb_room_i');
			$table->addIndex(['bot_id', 'room_token', 'embedding_model'], 'educai_room_img_emb_model_i');
		}

		return $schema;
	}
}
