<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add configurable application icon variants for runtime UI surfaces.
 */
class Version023702Date20260605010000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {}

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
			if (!$table->hasColumn('app_icon_url')) {
				$table->addColumn('app_icon_url', Types::TEXT, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('app_icon_mode')) {
				$table->addColumn('app_icon_mode', Types::STRING, [
					'notnull' => true,
					'length' => 32,
					'default' => 'default',
				]);
			}
			if (!$table->hasColumn('app_icon_preset')) {
				$table->addColumn('app_icon_preset', Types::STRING, [
					'notnull' => false,
					'length' => 128,
				]);
			}
			if (!$table->hasColumn('app_icon_black_url')) {
				$table->addColumn('app_icon_black_url', Types::TEXT, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('app_icon_white_url')) {
				$table->addColumn('app_icon_white_url', Types::TEXT, [
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
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('educai_settings')) {
			return;
		}

		$select = $this->connection->getQueryBuilder();
		$result = $select->select('id', 'app_icon_url')
			->from('educai_settings')
			->where($select->expr()->isNotNull('app_icon_url'))
			->andWhere($select->expr()->neq('app_icon_url', $select->createNamedParameter('', IQueryBuilder::PARAM_STR)))
			->executeQuery();

		$updatedLegacyRows = 0;
		while (($row = $result->fetch()) !== false) {
			$legacyIconUrl = (string)$row['app_icon_url'];
			if ($legacyIconUrl === '') {
				continue;
			}

			$update = $this->connection->getQueryBuilder();
			$updatedLegacyRows += $update->update('educai_settings')
				->set('app_icon_mode', $update->createNamedParameter('custom'))
				->set('app_icon_black_url', $update->createNamedParameter($legacyIconUrl, IQueryBuilder::PARAM_STR))
				->set('app_icon_white_url', $update->createNamedParameter($legacyIconUrl, IQueryBuilder::PARAM_STR))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$result->closeCursor();

		if ($updatedLegacyRows > 0) {
			$output->info("Backfilled custom app icon variants for {$updatedLegacyRows} settings row(s).");
		}
	}
}
