<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020000Date20250101000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create educai_bots table
		if (!$schema->hasTable('educai_bots')) {
			$table = $schema->createTable('educai_bots');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('bot_name', Types::STRING, [
				'notnull' => true,
				'length' => 200,
			]);
			$table->addColumn('mention_name', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('system_prompt', Types::TEXT, [
				'notnull' => true,
			]);
            $table->addColumn('is_active', Types::BOOLEAN, [
                // Booleans must be nullable for Nextcloud's cross-db constraints (Oracle)
                'notnull' => false,
                // store default as integer to avoid platform issues interpreting "false" as string
                'default' => 1,
            ]);
            $table->addColumn('is_public', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 0,
            ]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'educai_bots_user_idx');
			$table->addUniqueIndex(['mention_name'], 'educai_bots_mention_idx');
		} else {
			// Harden existing installs that may have incorrect boolean defaults stored as strings
			$table = $schema->getTable('educai_bots');
            if ($table->hasColumn('is_active')) {
                $table->changeColumn('is_active', [
                    'type' => Types::BOOLEAN,
                    'notnull' => false,
                    'default' => 1,
                ]);
            }
            if ($table->hasColumn('is_public')) {
                $table->changeColumn('is_public', [
                    'type' => Types::BOOLEAN,
                    'notnull' => false,
                    'default' => 0,
                ]);
            }
		}

		// Create educai_conversations table
		if (!$schema->hasTable('educai_conversations')) {
			$table = $schema->createTable('educai_conversations');
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
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('role', Types::STRING, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('content', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['bot_id', 'room_token'], 'educai_conv_bot_room_idx');
			$table->addIndex(['created_at'], 'educai_conv_created_idx');
		}

		// Create educai_settings table
		if (!$schema->hasTable('educai_settings')) {
			$table = $schema->createTable('educai_settings');
			$table->addColumn('id', Types::INTEGER, [
				'notnull' => true,
				'default' => 1,
			]);
			$table->addColumn('api_provider', Types::STRING, [
				'notnull' => true,
				'length' => 50,
				'default' => 'custom',
			]);
			$table->addColumn('api_key', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('api_endpoint', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('default_model', Types::STRING, [
				'notnull' => true,
				'length' => 100,
				'default' => 'llama-3.3-70b-instruct',
			]);
			$table->addColumn('webhook_secret', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
		}

		// Note: This app no longer manages any legacy notes tables.

		return $schema;
	}
}

