<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add per-user EDUC AI trace storage for Talk bot activity.
 */
class Version023800Date20260605020000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('educai_trace_runs')) {
			$table = $schema->createTable('educai_trace_runs');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('bot_id', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('bot_mention_name', Types::STRING, [
				'notnull' => false,
				'length' => 128,
			]);
			$table->addColumn('room_token', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('talk_message_id', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('reply_target_message_id', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('thread_root_message_id', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('source', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'talk',
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'running',
			]);
			$table->addColumn('user_message_preview', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('error_summary', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('started_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('finished_at', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('duration_ms', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('tool_call_count', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('event_count', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
				'default' => 0,
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
			$table->addIndex(['user_id', 'started_at'], 'educai_tr_user_start_i');
			$table->addIndex(['user_id', 'status', 'started_at'], 'educai_tr_user_status_i');
			$table->addIndex(['bot_id'], 'educai_tr_bot_i');
			$table->addIndex(['room_token'], 'educai_tr_room_i');
			$table->addIndex(['thread_root_message_id'], 'educai_tr_thread_i');
		}

		if (!$schema->hasTable('educai_trace_events')) {
			$table = $schema->createTable('educai_trace_events');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('run_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('sequence', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('event_type', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$table->addColumn('tool_name', Types::STRING, [
				'notnull' => false,
				'length' => 128,
			]);
			$table->addColumn('duration_ms', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('payload_json', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('payload_preview', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('result_json', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('result_preview', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('error_message', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id', 'sequence'], 'educai_te_run_seq_i');
			$table->addIndex(['event_type'], 'educai_te_type_i');
			$table->addIndex(['tool_name'], 'educai_te_tool_i');
			$table->addIndex(['created_at'], 'educai_te_created_i');
		}

		return $schema;
	}
}
