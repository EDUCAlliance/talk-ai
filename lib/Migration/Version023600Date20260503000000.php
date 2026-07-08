<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add Talk thread scoping for bot history and queued replies.
 */
class Version023600Date20260503000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('educai_conversations')) {
			$table = $schema->getTable('educai_conversations');
			if (!$table->hasColumn('thread_root_message_id')) {
				$table->addColumn('thread_root_message_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
			}
			if (!$table->hasIndex('educai_conv_thread_i')) {
				$table->addIndex(['bot_id', 'room_token', 'thread_root_message_id'], 'educai_conv_thread_i');
			}
		}

		if ($schema->hasTable('educai_queue')) {
			$table = $schema->getTable('educai_queue');
			if (!$table->hasColumn('reply_to_message_id')) {
				$table->addColumn('reply_to_message_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
			}
			if (!$table->hasColumn('thread_root_message_id')) {
				$table->addColumn('thread_root_message_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
			}
			if (!$table->hasIndex('educai_q_thread_i')) {
				$table->addIndex(['bot_id', 'room_token', 'thread_root_message_id'], 'educai_q_thread_i');
			}
		}

		return $schema;
	}
}
