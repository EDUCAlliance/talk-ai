<?php

declare(strict_types=1);

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\BotTool;
use OCA\EducAI\Db\BotToolMapper;
use OCA\EducAI\Db\CatalogueEmbeddingMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Jobs\RefreshCatalogueEmbeddingsJob;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCA\EducAI\Jobs\ReindexCatalogueEmbeddingsJob;
use OCA\EducAI\Service\BrandingService;
use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\EmbeddingAdminService;
use OCA\EducAI\Service\EmbeddingConfigurationService;
use OCA\EducAI\Service\WikiPathService;
use OCA\EducAI\ToolProvider\CatalogueToolProvider;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;

if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || getenv('EDUCAI_TEST_ALLOW_MUTATION') !== '1') {
	fwrite(STDERR, "This mutating test requires a disposable Docker instance and EDUCAI_TEST_ALLOW_MUTATION=1.\n");
	exit(2);
}
$origin = getenv('EDUCAI_TEST_FIXTURE_ORIGIN') ?: 'http://127.0.0.1:18095';
if (preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $origin) !== 1) {
	throw new RuntimeException('The synthetic fixture must use a loopback HTTP port');
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
\OC_App::loadApp('educai');
$container = \OC::$server->getRegisteredAppContainer('educai');
$get = static fn (string $class) => $container->get($class);
$checks = [];
$assert = static function (bool $condition, string $label) use (&$checks): void {
	if (!$condition) {
		throw new RuntimeException('Assertion failed: ' . $label);
	}
	$checks[] = $label;
};
$fixture = static function (string $path, ?array $body = null) use ($origin): array {
	$options = ['http' => ['timeout' => 5, 'ignore_errors' => true]];
	if ($body !== null) {
		$options['http'] += ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body, JSON_THROW_ON_ERROR)];
	}
	$result = file_get_contents($origin . $path, false, stream_context_create($options));
	if ($result === false) {
		throw new RuntimeException('Synthetic fixture is not running');
	}
	return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
};
$catalogueRequests = static function (array $stats): int {
	return array_sum(array_filter($stats['requests'], static fn (string $path): bool => str_starts_with($path, '/catalogue/'), ARRAY_FILTER_USE_KEY));
};

$config = $get(IConfig::class);
$settingsMapper = $get(SettingsMapper::class);
$sourceMapper = $get(BotSourceMapper::class);
$catalogueMapper = $get(CatalogueEmbeddingMapper::class);
$jobs = $get(IJobList::class);
$assert($catalogueMapper->count() === 0 && $sourceMapper->findAll() === [], 'Disposable instance starts without knowledge index data');
$assert(!$jobs->has(ReindexCatalogueEmbeddingsJob::class, null), 'No existing Catalogue work will be replaced');
$original = $settingsMapper->getSettings();
$assert(!$original->getCatalogueEnabled(), 'Fresh installation defaults Catalogue to disabled');
$changedFields = [
	'ApiProvider', 'ApiKey', 'ApiEndpoint', 'DefaultModel', 'EmbeddingApiEndpoint',
	'EmbeddingApiKey', 'EmbeddingModel', 'RagEnabled', 'DoclingEnabled', 'RateLimitEnabled',
	'CatalogueEnabled', 'CatalogueApiEndpoint', 'CatalogueReindexHours', 'CatalogueLastIndexed', 'CatalogueCourseCount',
];
$originalValues = [];
foreach ($changedFields as $field) {
	$originalValues[$field] = $original->{'get' . $field}();
}
$originalConfig = [];
foreach (['catalogue_reindex_status', 'catalogue_reindex_required', 'rag_reindex_required', 'display_name', 'wiki_root_folder'] as $key) {
	$originalConfig[$key] = $config->getAppValue('educai', $key, '__SMOKE_UNSET__');
}
$allowLocal = $config->getSystemValue('allow_local_remote_servers', '__SMOKE_UNSET__');
$user = null;
$bot = null;
$source = null;
$result = null;

try {
	$config->setSystemValue('allow_local_remote_servers', true);
	$fixture('/control', ['mode' => 'normal', 'reset' => true]);
	$settings = $settingsMapper->getSettings();
	$settings->setApiProvider('openai');
	$settings->setApiEndpoint($origin . '/v1');
	$settings->setApiKey($get(CredentialService::class)->encrypt('synthetic-fixture-only'));
	$settings->setDefaultModel('fixture-embedding');
	$settings->setEmbeddingApiEndpoint($origin . '/v1');
	$settings->setEmbeddingApiKey($get(CredentialService::class)->encrypt('synthetic-fixture-only'));
	$settings->setEmbeddingModel('fixture-embedding');
	$settings->setRagEnabled(true);
	$settings->setDoclingEnabled(false);
	$settings->setRateLimitEnabled(false);
	$settings->setCatalogueEnabled(false);
	$settings->setCatalogueApiEndpoint($origin . '/catalogue');
	$settingsMapper->update($settings);
	$catalogue = $get(CatalogueEmbeddingService::class);
	$admin = $get(EmbeddingAdminService::class);
	$providers = $get(ToolProviderRegistry::class);
	$catalogueJob = $get(ReindexCatalogueEmbeddingsJob::class);
	$refresh = $get(RefreshCatalogueEmbeddingsJob::class);
	$catalogueJob->run(null);
	(new ReflectionMethod($refresh, 'run'))->invoke($refresh, null);
	$assert(!in_array('catalogue_search', array_column($providers->getAvailableTools(), 'name'), true), 'Disabled Catalogue tools are absent from the real provider registry');
	$assert($catalogueRequests($fixture('/stats')) === 0, 'Disabled queued and scheduled jobs perform no Catalogue requests');

	$uid = 'educai-smoke-' . bin2hex(random_bytes(4));
	$user = $get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
	$assert($user !== false, 'Synthetic user was created');
	$folder = $get(IRootFolder::class)->getUserFolder($uid);
	$file = $folder->newFile('physics-smoke.txt', 'Physics laboratory notes. Scientific experiments observe matter and energy. These synthetic notes exist only to validate local knowledge indexing.');
	$bot = new Bot();
	$bot->setUserId($uid);
	$bot->setBotName('Synthetic integration bot');
	$bot->setMentionName($uid);
	$bot->setSystemPrompt('Use only synthetic fixture content.');
	$bot->setVisibility('personal');
	$bot->setRagEnabled(true);
	$bot->setCreatedAt(time());
	$bot->setUpdatedAt(time());
	$bot = $get(BotMapper::class)->insert($bot);
	$source = new BotSource();
	$source->setBotId($bot->getId());
	$source->setOwnerUid($uid);
	$source->setNodeId($file->getId());
	$source->setNodeType('file');
	$source->setCreatedAt(time());
	$source->setUpdatedAt(time());
	$source = $sourceMapper->insert($source);
	$assignment = new BotTool();
	$assignment->setBotId($bot->getId());
	$assignment->setBuiltInToolName('catalogue_search_courses');
	$assignment->setConfigOverride('{"scope":"preserved"}');
	$assignment->setCreatedAt(time());
	$assignment->setUpdatedAt(time());
	$get(BotToolMapper::class)->insert($assignment);

	$queued = $admin->queueAll();
	$assert($queued['queued_rag_sources'] === 1 && $queued['queued_catalogue_jobs'] === 0, 'Reindex All works with only generic knowledge enabled');
	$assert($admin->getStatus()['rag']['queued'] === 1, 'Generic queue status is visible before processing');
	$get(ReindexBotSourceJob::class)->run(['sourceId' => $source->getId(), 'force' => true]);
	$jobs->remove(ReindexBotSourceJob::class, ['sourceId' => $source->getId(), 'force' => true]);
	$assert($sourceMapper->findById($source->getId())->getStatus() === 'ready', 'Native Nextcloud file is indexed by the real RAG worker');
	$assert($get(EmbeddingMapper::class)->countByBot($bot->getId()) > 0, 'RAG vectors are stored in the real database');
	$assert($catalogueRequests($fixture('/stats')) === 0, 'Generic reindex never contacts the disabled Catalogue');

	$settings = $settingsMapper->getSettings();
	$settings->setCatalogueEnabled(true);
	$settingsMapper->update($settings);
	$assert(in_array('catalogue_search', array_column($providers->getAvailableTools(), 'name'), true), 'Enabling Catalogue exposes its tools without a second package');
	$assert($admin->queueCatalogue() && !$admin->queueCatalogue(), 'Repeated Catalogue queue requests coalesce');
	$assert($admin->getCatalogueStatus()['state'] === 'queued', 'Catalogue queued state is visible');
	$catalogueJob->run(null);
	$jobs->remove(ReindexCatalogueEmbeddingsJob::class, null);
	$assert($catalogueMapper->count() === 3, 'Current and archived synthetic Catalogue courses are indexed');
	$assert($admin->getCatalogueStatus()['state'] === 'completed', 'Catalogue completion persists across status reads');
	$search = $catalogue->search('physics');
	$assert($search['total'] === 1 && $search['courses'][0]['id'] === 42, 'Catalogue semantic search reads matching stored vectors');
	$legacy = $get(CatalogueToolProvider::class)->executeTool('catalogue_search_courses', ['query' => 'physics']);
	$assert(!$legacy['isError'] && str_contains($legacy['content'][0]['text'], 'Physics laboratory'), 'Legacy Catalogue tool invocation remains functional');

	$fixture('/control', ['mode' => 'fail_past']);
	$failed = $catalogue->reindex();
	$assert(!$failed['success'] && $catalogueMapper->count() === 3, 'Failed archived fetch preserves both index partitions');
	$assert($admin->getCatalogueStatus()['state'] === 'failed', 'Catalogue failures persist for central administration');
	$fixture('/control', ['mode' => 'normal']);
	$assert($catalogue->reindex()['success'], 'Catalogue indexing recovers after fixture failure');

	$settings = $settingsMapper->getSettings();
	$before = clone $settings;
	$settings->setEmbeddingApiEndpoint($origin . '/v1-alt');
	$settingsMapper->update($settings);
	$get(EmbeddingConfigurationService::class)->recordChange($before, $settings);
	(new ReflectionMethod($refresh, 'run'))->invoke($refresh, null);
	$assert($jobs->has(ReindexCatalogueEmbeddingsJob::class, null), 'Same-model endpoint changes are picked up by scheduled Catalogue maintenance');
	$settings->setCatalogueEnabled(false);
	$settingsMapper->update($settings);
	$requestsBefore = $fixture('/stats')['requests'];
	$catalogueJob->run(null);
	(new ReflectionMethod($refresh, 'run'))->invoke($refresh, null);
	$assert($requestsBefore === $fixture('/stats')['requests'], 'Disabling Catalogue also blocks already queued work');
	$assert($catalogueMapper->count() === 3 && $catalogue->getCourseFromEmbeddings(42, false) === null, 'Disabling preserves rows but blocks cached tool access');
	$stored = $get(BotToolMapper::class)->findByBot($bot->getId());
	$assert(count($stored) === 1 && $stored[0]->getBuiltInToolName() === 'catalogue_search_courses' && $stored[0]->getConfigOverride() === '{"scope":"preserved"}', 'Toggle and reindex operations preserve stored tool assignments and config');

	$branding = $get(BrandingService::class);
	$wikiRoot = $branding->getWikiRootFolder();
	$branding->setDisplayName('EDUC AI');
	$assert($branding->getDisplayName() === 'EDUC AI' && $branding->getWikiRootFolder() === $wikiRoot, 'Runtime branding leaves persistent wiki storage unchanged');
	$legacyWiki = $folder;
	foreach (['EDUC AI', 'Personal Wikis', 'smoke'] as $segment) {
		$legacyWiki = $legacyWiki->newFolder($segment);
	}
	$assert($get(WikiPathService::class)->getDefaultPath($folder, 'smoke') === 'EDUC AI/Personal Wikis/smoke', 'Existing legacy wiki folder is reused without renaming');
	$result = ['success' => true, 'checks' => $checks];
} finally {
	// Attempt every cleanup independently: a partial insert or one failed delete
	// must not prevent restoration of settings and local-HTTP configuration.
	$cleanupErrors = [];
	$cleanup = static function (string $label, callable $action) use (&$cleanupErrors): void {
		try {
			$action();
		} catch (Throwable $e) {
			$cleanupErrors[] = $label . ' (' . get_class($e) . ')';
		}
	};
	$cleanup('Catalogue queue', static fn () => $jobs->remove(ReindexCatalogueEmbeddingsJob::class, null));
	if ($source !== null && $source->getId() !== null) {
		$cleanup('RAG queue', static fn () => $jobs->remove(ReindexBotSourceJob::class, ['sourceId' => $source->getId(), 'force' => true]));
		$cleanup('RAG vectors', static fn () => $get(EmbeddingMapper::class)->deleteBySource($source->getId()));
		$cleanup('RAG source', static fn () => $sourceMapper->deleteById($source->getId()));
	}
	if ($bot !== null && $bot->getId() !== null) {
		$cleanup('Tool assignment', static fn () => $get(BotToolMapper::class)->deleteByBot($bot->getId()));
		$cleanup('Bot', static fn () => $get(BotMapper::class)->delete($bot));
	}
	if ($user) {
		$cleanup('Synthetic user', static function () use ($user): void {
			if (!$user->delete()) {
				throw new RuntimeException('Synthetic user deletion failed');
			}
		});
	}
	$cleanup('Catalogue rows', static fn () => $catalogueMapper->deleteAll());
	$cleanup('Settings', static function () use ($settingsMapper, $originalValues): void {
		$settings = $settingsMapper->getSettings();
		foreach ($originalValues as $field => $value) {
			$settings->{'set' . $field}($value);
		}
		$settingsMapper->update($settings);
	});
	foreach ($originalConfig as $key => $value) {
		$cleanup('App state ' . $key, static function () use ($config, $key, $value): void {
			if ($value === '__SMOKE_UNSET__') {
				$config->deleteAppValue('educai', $key);
			} else {
				$config->setAppValue('educai', $key, $value);
			}
		});
	}
	$cleanup('Local HTTP configuration', static function () use ($config, $allowLocal): void {
		if ($allowLocal === '__SMOKE_UNSET__') {
			$config->deleteSystemValue('allow_local_remote_servers');
		} else {
			$config->setSystemValue('allow_local_remote_servers', $allowLocal);
		}
	});
	$cleanup('Fixture mode', static fn () => $fixture('/control', ['mode' => 'normal']));
	if ($cleanupErrors !== []) {
		throw new RuntimeException('Cleanup needs attention: ' . implode(', ', $cleanupErrors));
	}
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
