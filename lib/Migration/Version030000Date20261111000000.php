<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Every bot says for itself whether it answers guests: people who take part in a
 * public conversation without being signed in.
 *
 * Until now exactly the bots for everyone answered guests. They keep doing so:
 * the switch is turned on for them, and it stays off for every other bot.
 */
class Version030000Date20261111000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $connection) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('educai_bots')) {
			return null;
		}

		$table = $schema->getTable('educai_bots');
		if ($table->hasColumn('allow_guests')) {
			return null;
		}

		// Nextcloud's cross-database checks require nullable booleans (Oracle).
		$table->addColumn('allow_guests', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('educai_bots')
			->set('allow_guests', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->orX(
				$qb->expr()->eq('visibility', $qb->createNamedParameter('global')),
				// Bots saved before the visibility existed are public through the old flag.
				$qb->expr()->andX(
					$qb->expr()->orX(
						$qb->expr()->isNull('visibility'),
						$qb->expr()->emptyString('visibility'),
					),
					$qb->expr()->eq('is_public', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
				),
			));

		$updated = $qb->executeStatement();
		$output->info("Kept guest answers for {$updated} bots that are shared with everyone.");
	}
}
