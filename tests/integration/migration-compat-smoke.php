<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\TextType;
use OC\DB\SchemaWrapper;
use OC\Migration\NullOutput;
use OCA\EducAI\Migration\Version022700Date20260116000000;
use OCA\EducAI\Migration\Version023701Date20260504010000;
use OCA\EducAI\Migration\Version024202Date20261007000000;
use OCP\DB\Types;
use OCP\IDBConnection;

// Only in-memory schemas are changed, but load the real installed core APIs.
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv')) {
	fwrite(STDERR, "Run inside a disposable Nextcloud Docker instance.\n");
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
OC_App::loadApp('educai');
$checks = [];
$check = static function (bool $passed, string $name) use (&$checks): void {
	$checks[] = ['check' => $name, 'passed' => $passed];
};
$error = null;
try {
	$db = \OCP\Server::get(IDBConnection::class)->getInner();
	$output = new NullOutput();
	$migration = new Version022700Date20260116000000();
	foreach ([[], ['webhook_secret'], ['catalogue_api_key'], ['webhook_secret', 'catalogue_api_key']] as $names) {
		$schema = new SchemaWrapper($db, new Schema());
		$table = $schema->createTable('educai_settings');
		$table->addColumn('unrelated', Types::STRING, ['length' => 42, 'default' => 'keep']);
		foreach ($names as $name) {
			$table->addColumn($name, Types::STRING, ['length' => 255, 'notnull' => false]);
		}
		foreach ([1, 2] as $pass) {
			$result = $migration->changeSchema($output, static fn () => $schema, []);
			$check($result === $schema, 'Existing settings table is retained, columns=' . count($names) . ', pass=' . $pass);
			foreach ($names as $name) {
				$column = $table->getColumn($name);
				$type = $column->getType();
				$check(($type instanceof TextType || ($type instanceof BackedEnum && $type->value === Types::TEXT)) && $column->getLength() === null && !$column->getNotnull(), $name . ' accepts long nullable text, pass=' . $pass);
			}
			$untouched = $table->getColumn('unrelated');
			$check($untouched->getLength() === 42 && $untouched->getDefault() === 'keep', 'Unrelated column is preserved, columns=' . count($names) . ', pass=' . $pass);
		}
	}
	$empty = new SchemaWrapper($db, new Schema());
	$check($migration->changeSchema($output, static fn () => $empty, []) === null, 'Missing settings table is a no-op');
	$wiki = new Version023701Date20260504010000();
	$wiki->changeSchema($output, static fn () => $empty, []);
	foreach (['educai_wiki_roots', 'educai_wiki_root_bots'] as $name) {
		$active = $empty->getTable($name)->getColumn('active');
		$check(!$active->getNotnull() && (bool)$active->getDefault(), $name . ' follows Nextcloud nullable-boolean rules and defaults to active');
	}
	$align = new Version024202Date20261007000000();
	$check($align->changeSchema($output, static fn () => $empty, []) === null, 'Fresh wiki tables need no nullability alignment');
	$upgraded = new SchemaWrapper($db, new Schema());
	foreach (['educai_wiki_roots', 'educai_wiki_root_bots'] as $name) {
		$upgraded->createTable($name)->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
	}
	$check($align->changeSchema($output, static fn () => $upgraded, []) === $upgraded, 'NOT NULL wiki flags from earlier releases are migrated');
	foreach (['educai_wiki_roots', 'educai_wiki_root_bots'] as $name) {
		$active = $upgraded->getTable($name)->getColumn('active');
		$check(!$active->getNotnull() && (bool)$active->getDefault(), $name . ' is aligned to a nullable boolean and still defaults to active');
	}
	$check($align->changeSchema($output, static fn () => $upgraded, []) === null, 'Nullability alignment is idempotent');
} catch (Throwable $e) {
	$error = get_class($e);
}
$success = $error === null && !in_array(false, array_column($checks, 'passed'), true);
echo json_encode(['success' => $success, 'nextcloud' => implode('.', \OCP\Util::getVersion()), 'checks' => $checks, 'error_class' => $error], JSON_PRETTY_PRINT) . PHP_EOL;
exit($success ? 0 : 1);
