<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add global and per-bot temperature configuration.
 */
class Version023200Date20260413000000 extends SimpleMigrationStep {
	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

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
			if (!$table->hasColumn('default_temperature')) {
				$table->addColumn('default_temperature', Types::FLOAT, [
					'notnull' => false,
					'default' => 0.2,
				]);
			}
		}

		if ($schema->hasTable('educai_bots')) {
			$table = $schema->getTable('educai_bots');
			if (!$table->hasColumn('temperature')) {
				$table->addColumn('temperature', Types::FLOAT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->connection->getQueryBuilder();
		$updated = $qb->update('educai_settings')
			->set('default_temperature', $qb->createNamedParameter(0.2))
			->where(
				$qb->expr()->isNull('default_temperature')
			)
			->executeStatement();

		if ($updated > 0) {
			$output->info("Backfilled default_temperature for {$updated} settings row(s).");
		}
	}
}
