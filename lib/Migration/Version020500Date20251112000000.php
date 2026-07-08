<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020500Date20251112000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('educai_bots')) {
			$table = $schema->getTable('educai_bots');
			if (!$table->hasColumn('rag_enabled')) {
				$table->addColumn('rag_enabled', Types::BOOLEAN, [
					'notnull' => false,
					// Store default as integer to avoid platform issues interpreting "false" as string
					'default' => 0,
				]);
			}
		}

		if ($schema->hasTable('educai_settings')) {
			$table = $schema->getTable('educai_settings');
			if (!$table->hasColumn('embedding_api_endpoint')) {
				$table->addColumn('embedding_api_endpoint', Types::STRING, [
					'length' => 255,
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('embedding_api_key')) {
				$table->addColumn('embedding_api_key', Types::TEXT, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('embedding_model')) {
				$table->addColumn('embedding_model', Types::STRING, [
					'length' => 255,
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('rag_chunk_size')) {
				$table->addColumn('rag_chunk_size', Types::INTEGER, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('rag_chunk_overlap')) {
				$table->addColumn('rag_chunk_overlap', Types::INTEGER, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('rag_top_k')) {
				$table->addColumn('rag_top_k', Types::INTEGER, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('rag_max_context_tokens')) {
				$table->addColumn('rag_max_context_tokens', Types::INTEGER, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('rag_enabled')) {
				$table->addColumn('rag_enabled', Types::BOOLEAN, [
					'notnull' => false,
					// Store default as integer to avoid platform issues interpreting "false" as string
					'default' => 0,
				]);
			}
		}

		$botSourcesTableName = 'educai_bot_sources';
		if (!$schema->hasTable($botSourcesTableName)) {
			$table = $schema->createTable($botSourcesTableName);
			$table->addColumn('id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('bot_id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
			]);
			$table->addColumn('owner_uid', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('node_id', Types::BIGINT, [
				'unsigned' => true,
				'notnull' => true,
			]);
			$table->addColumn('node_type', Types::STRING, [
				'length' => 16,
				'notnull' => true,
			]);
			$table->addColumn('checksum', Types::STRING, [
				'length' => 128,
				'notnull' => false,
			]);
			$table->addColumn('status', Types::STRING, [
				'length' => 16,
				'notnull' => true,
				'default' => 'pending',
			]);
			$table->addColumn('error_message', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('last_indexed_at', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
		}

		$botSourcesTable = $schema->getTable($botSourcesTableName);
		if ($botSourcesTable->hasIndex('educai_bot_sources_bot_idx')) {
			$botSourcesTable->dropIndex('educai_bot_sources_bot_idx');
		}
		if (!$botSourcesTable->hasIndex('educai_bot_src_bot_idx')) {
			$botSourcesTable->addIndex(['bot_id'], 'educai_bot_src_bot_idx');
		}
		if ($botSourcesTable->hasIndex('educai_bot_sources_status_idx')) {
			$botSourcesTable->dropIndex('educai_bot_sources_status_idx');
		}
		if (!$botSourcesTable->hasIndex('educai_bot_src_status_idx')) {
			$botSourcesTable->addIndex(['status'], 'educai_bot_src_status_idx');
		}

		$embeddingsTableName = 'educai_embeddings';
		if (!$schema->hasTable($embeddingsTableName)) {
			$table = $schema->createTable($embeddingsTableName);
			$table->addColumn('id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('bot_id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
			]);
			$table->addColumn('source_id', Types::INTEGER, [
				'unsigned' => true,
				'notnull' => true,
			]);
			$table->addColumn('chunk_id', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('chunk_text', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('embedding', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('token_count', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('metadata', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('score', Types::STRING, [
				'length' => 32,
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
		}

		$embeddingsTable = $schema->getTable($embeddingsTableName);
		if ($embeddingsTable->hasIndex('educai_embeddings_bot_idx')) {
			$embeddingsTable->dropIndex('educai_embeddings_bot_idx');
		}
		if (!$embeddingsTable->hasIndex('educai_emb_bot_idx')) {
			$embeddingsTable->addIndex(['bot_id'], 'educai_emb_bot_idx');
		}
		if ($embeddingsTable->hasIndex('educai_embeddings_source_idx')) {
			$embeddingsTable->dropIndex('educai_embeddings_source_idx');
		}
		if (!$embeddingsTable->hasIndex('educai_emb_src_idx')) {
			$embeddingsTable->addIndex(['source_id'], 'educai_emb_src_idx');
		}
		if ($embeddingsTable->hasIndex('educai_embeddings_bot_chunk_uidx')) {
			$embeddingsTable->dropIndex('educai_embeddings_bot_chunk_uidx');
		}
		if (!$embeddingsTable->hasIndex('educai_emb_bot_chunk_u')) {
			$embeddingsTable->addUniqueIndex(['bot_id', 'chunk_id'], 'educai_emb_bot_chunk_u');
		}

		return $schema;
	}
}
