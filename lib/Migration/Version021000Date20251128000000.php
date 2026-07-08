<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add rate limit tracking and request queue tables for LLM rate limiting.
 * 
 * This migration creates:
 * - educai_rate_limit_state: Tracks current rate limit state from API headers
 * - educai_queued_requests: Queue for requests that couldn't be processed due to rate limits
 * - Adds rate limit settings fields to educai_settings
 */
class Version021000Date20251128000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create rate limit state table (short name to avoid index length issues)
        if (!$schema->hasTable('educai_rl_state')) {
            $table = $schema->createTable('educai_rl_state');
            
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            
            // Identifier for the endpoint (e.g., "chat_completions", "embeddings")
            $table->addColumn('endpoint_key', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            
            // Current remaining requests per time window
            $table->addColumn('remaining_second', Types::INTEGER, [
                'notnull' => true,
                'default' => 3,
            ]);
            $table->addColumn('remaining_hour', Types::INTEGER, [
                'notnull' => true,
                'default' => 500,
            ]);
            $table->addColumn('remaining_day', Types::INTEGER, [
                'notnull' => true,
                'default' => 1000,
            ]);
            
            // Configured limits per time window
            $table->addColumn('limit_second', Types::INTEGER, [
                'notnull' => true,
                'default' => 3,
            ]);
            $table->addColumn('limit_hour', Types::INTEGER, [
                'notnull' => true,
                'default' => 500,
            ]);
            $table->addColumn('limit_day', Types::INTEGER, [
                'notnull' => true,
                'default' => 1000,
            ]);
            
            // Unix timestamps for when each window resets
            $table->addColumn('reset_second', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            $table->addColumn('reset_hour', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['endpoint_key'], 'educai_rls_ep_idx');
        }

        // Create queued requests table (short name to avoid index length issues)
        if (!$schema->hasTable('educai_queue')) {
            $table = $schema->createTable('educai_queue');
            
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
            
            $table->addColumn('message', Types::TEXT, [
                'notnull' => true,
            ]);
            
            // Original message with mentions (for heuristics)
            $table->addColumn('original_message', Types::TEXT, [
                'notnull' => false,
            ]);
            
            // Status: pending, processing, completed, failed
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'pending',
            ]);
            
            // Bot response when completed
            $table->addColumn('result', Types::TEXT, [
                'notnull' => false,
            ]);
            
            // Error message when failed
            $table->addColumn('error', Types::TEXT, [
                'notnull' => false,
            ]);
            
            // Number of processing attempts
            $table->addColumn('attempts', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            
            // Priority (lower = higher priority)
            $table->addColumn('priority', Types::INTEGER, [
                'notnull' => true,
                'default' => 100,
            ]);
            
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            
            $table->addColumn('processed_at', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            
            $table->setPrimaryKey(['id']);
            $table->addIndex(['status', 'priority', 'created_at'], 'educai_q_status_idx');
            $table->addIndex(['bot_id'], 'educai_q_bot_idx');
            $table->addIndex(['room_token'], 'educai_q_room_idx');
        }

        // Add rate limit settings to settings table
        if ($schema->hasTable('educai_settings')) {
            $table = $schema->getTable('educai_settings');

            if (!$table->hasColumn('rate_limit_enabled')) {
                $table->addColumn('rate_limit_enabled', Types::BOOLEAN, [
                    // notnull => false for cross-db compatibility
                    'notnull' => false,
                    'default' => 0,
                ]);
            }

            if (!$table->hasColumn('rate_limit_second')) {
                $table->addColumn('rate_limit_second', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 3,
                ]);
            }

            if (!$table->hasColumn('rate_limit_hour')) {
                $table->addColumn('rate_limit_hour', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 500,
                ]);
            }

            if (!$table->hasColumn('rate_limit_day')) {
                $table->addColumn('rate_limit_day', Types::INTEGER, [
                    'notnull' => false,
                    'default' => 1000,
                ]);
            }
        }

        return $schema;
    }
}

