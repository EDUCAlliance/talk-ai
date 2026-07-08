<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the educai_chat_rooms table for storing per-room, per-bot state.
 * This tracks onboarding status, response mode (mention/always), and user answers.
 */
class Version022500Date20251212000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$tableName = 'educai_chat_rooms';
		if (!$schema->hasTable($tableName)) {
			$table = $schema->createTable($tableName);

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

			// Response mode: 'mention' = only when @mentioned, 'always' = respond to all messages
			$table->addColumn('response_mode', Types::STRING, [
				'notnull' => false,
				'length' => 16,
			]);

			// Onboarding status: 'mode_selection', 'questions', 'completed'
			$table->addColumn('onboarding_status', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'mode_selection',
			]);

			// Current question ID in the question tree (for branching questions)
			$table->addColumn('current_question_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);

			// JSON array of Q&A pairs from onboarding
			$table->addColumn('onboarding_answers', Types::TEXT, [
				'notnull' => false,
			]);

			// User who first activated the bot in this room
			$table->addColumn('activated_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
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
			// Unique index: one state per bot per room
			$table->addUniqueIndex(['bot_id', 'room_token'], 'educai_chatroom_bot_room');
			// Index for quick lookups by bot
			$table->addIndex(['bot_id'], 'educai_chatroom_bot');
		}

		return $schema;
	}
}

