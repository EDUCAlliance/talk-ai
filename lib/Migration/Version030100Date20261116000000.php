<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A bot for several groups or teams is approved part by part: an admin of each
 * group or team approves for it. The column remembers which parts already said
 * yes while the bot waits. It is empty for every bot that does not wait.
 */
class Version030100Date20261116000000 extends SimpleMigrationStep {
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
		if ($table->hasColumn('scope_approvals')) {
			return null;
		}

		$table->addColumn('scope_approvals', Types::TEXT, [
			'notnull' => false,
		]);

		return $schema;
	}
}
