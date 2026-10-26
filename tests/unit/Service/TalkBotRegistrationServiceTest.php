<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Service\BrandingService;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\TalkBotRegistrationService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkBotRegistrationServiceTest extends TestCase {
	private const BASE = 'https://cloud.example:8443/nc';
	private const SECRET = 'test-only-original-secret';
	private array $metadata;
	private array $writes = [];
	private Settings $settings;
	private SettingsMapper $settingsMapper;
	private CredentialService $credentials;
	private IConfig $config;
	private IURLGenerator $urls;
	private RegistrationMapperFixture $mapper;
	private TalkBotRegistrationService $service;
	private string|false $oldFrontController;

	protected function setUp(): void {
		$this->oldFrontController = getenv('front_controller_active');
		putenv('front_controller_active');
		$this->metadata = ['talk_bot_id' => '17', 'talk_bot_url' => self::BASE . '/'];
		$this->settings = new Settings();
		$this->settings->setWebhookSecret('encrypted-test-fixture');
		$this->settingsMapper = $this->createMock(SettingsMapper::class);
		$this->settingsMapper->method('getSettings')->willReturn($this->settings);
		$this->settingsMapper->expects($this->never())->method('update');
		$this->credentials = $this->createMock(CredentialService::class);
		$this->credentials->method('decrypt')->with('encrypted-test-fixture')->willReturn(self::SECRET);
		$this->credentials->expects($this->never())->method('encrypt');
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $this->metadata[$key] ?? $default);
		$this->config->method('setAppValue')->willReturnCallback(function ($app, $key, $value): void {
			$this->assertSame('educai', $app);
			$this->writes[] = $key;
			$this->metadata[$key] = $value;
		});
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->expects($this->never())->method('linkToRoute');
		$this->mapper = new RegistrationMapperFixture(new RegistrationBotFixture([
			'id' => 17, 'name' => 'Manually named bot', 'description' => 'Preserve this',
			'secret' => self::SECRET, 'state' => 0, 'features' => 2,
			'url' => self::BASE . '/', 'urlHash' => sha1(self::BASE . '/'),
			'errorCount' => 3, 'lastErrorMessage' => 'Preserve diagnostic',
		]));
		$client = $this->createMock(IClientService::class);
		$client->expects($this->never())->method('newClient');
		$this->service = $this->getMockBuilder(TalkBotRegistrationService::class)
			->setConstructorArgs([$this->settingsMapper, $this->credentials, $this->createMock(IAppManager::class),
				$this->config, $this->createMock(IRequest::class), $this->urls, $client,
				$this->createMock(LoggerInterface::class), new BrandingService($this->config)])
			->onlyMethods(['getTalkBotServerMapper', 'isTalkAvailable'])->getMock();
		$this->service->method('getTalkBotServerMapper')->willReturn($this->mapper);
		$this->service->method('isTalkAvailable')->willReturn(true);
	}

	protected function tearDown(): void {
		putenv($this->oldFrontController === false ? 'front_controller_active' : 'front_controller_active=' . $this->oldFrontController);
	}

	/** @dataProvider instancePaths */
	public function testUrlDoesNotDependOnLoadedRoutes(string $base, bool $pretty, string $environment): void {
		putenv('front_controller_active=' . $environment);
		$this->config->method('getSystemValueBool')->with('htaccess.IgnoreFrontController', false)->willReturn($pretty);
		$path = ($pretty || $environment === 'true' ? '' : 'index.php/') . 'apps/educai/webhook/talk';
		$this->urls->expects($this->once())->method('getAbsoluteURL')->with($path)
			->willReturn($base . '/' . $path);

		$this->assertSame($base . '/' . $path, $this->service->getWebhookUrl());
		$this->assertSame([], $this->writes);
	}

	public static function instancePaths(): array {
		return [
			'root' => ['https://cloud.example', false, ''],
			'pretty root' => ['https://cloud.example', true, ''],
			'proxy and subdirectory' => [self::BASE, false, ''],
			'pretty subdirectory' => [self::BASE, true, ''],
			'apps webroot is not stripped' => ['https://cloud.example/apps', true, ''],
			'index webroot is not stripped' => ['https://cloud.example/index.php', false, ''],
			'front controller environment' => [self::BASE, false, 'true'],
			'false environment is not truthy' => [self::BASE, false, 'false'],
			'local HTTP' => ['http://localhost:8080/nc', false, ''],
		];
	}

	/** @dataProvider invalidEndpoints */
	public function testBadResolutionCannotMutateRegistrationOrGenerateSecret(string $url): void {
		$this->settings->setWebhookSecret('');
		$this->urls->method('getAbsoluteURL')->willReturn($url);
		$this->settingsMapper->expects($this->never())->method('getSettings');
		$before = $this->mapper->bot->data;

		$result = $this->service->syncRegistration(null, false, true);

		$this->assertSame('error', $result['status']);
		$this->assertSame(0, $this->mapper->updates);
		$this->assertSame($before, $this->mapper->bot->data);
		$this->assertSame([], $this->writes);
		$this->assertStringNotContainsString($url === '' ? 'private-user' : $url, $result['message']);
	}

	public static function invalidEndpoints(): array {
		$path = '/apps/educai/webhook/talk';
		return [
			'empty' => [''],
			'root' => ['https://cloud.example/'],
			'subdir root' => [self::BASE . '/'],
			'relative' => [$path],
			'wrong endpoint' => ['https://cloud.example/apps/educai/'],
			'userinfo' => ['https://private-user@cloud.example' . $path],
			'query' => ['https://cloud.example' . $path . '?token=private'],
			'fragment' => ['https://cloud.example' . $path . '#fragment'],
			'wrong scheme' => ['ftp://cloud.example' . $path],
			'control character' => ["https://cloud.example/\n" . $path],
		];
	}

	public function testUpgradeRepairsOnlyUrlAndHashOfDisabledManagedBot(): void {
		$this->absoluteUrls();
		$before = $this->mapper->bot->data;
		$expected = self::BASE . '/index.php/apps/educai/webhook/talk';

		$result = $this->service->repairInvalidWebhookUrl();

		$this->assertSame('repaired', $result['status']);
		$this->assertSame(array_replace($before, ['url' => $expected, 'urlHash' => sha1($expected)]), $this->mapper->bot->data);
		$this->assertSame(['talk_bot_url'], $this->writes);
		$this->assertSame(['talk_bot_id' => '17', 'talk_bot_url' => $expected], $this->metadata);
		$this->assertSame(1, $this->mapper->updates);
		$this->assertStringNotContainsString(self::SECRET, json_encode($result));

		$this->assertSame('unchanged', $this->service->repairInvalidWebhookUrl()['status']);
		$this->assertSame(1, $this->mapper->updates);
	}

	public function testUpgradeRepairsRootWithoutTrailingSlashAndRetainsKnownMetadata(): void {
		$this->absoluteUrls();
		$this->mapper->bot->data['url'] = self::BASE;
		$this->metadata['talk_bot_url'] = self::BASE . '/apps/educai/webhook/talk';
		$this->assertSame('repaired', $this->service->repairInvalidWebhookUrl()['status']);
		$this->assertSame(1, $this->mapper->updates);
	}

	/** @dataProvider preservedTargets */
	public function testUpgradeNeverRewritesOtherTargets(string $target): void {
		$this->absoluteUrls();
		$this->mapper->bot->data['url'] = $target;
		$before = $this->mapper->bot->data;
		$this->assertSame('unchanged', $this->service->repairInvalidWebhookUrl()['status']);
		$this->assertSame($before, $this->mapper->bot->data);
		$this->assertSame([], $this->writes);
		$this->assertSame(0, $this->mapper->updates);
	}

	public static function preservedTargets(): array {
		return [
			'correct' => [self::BASE . '/index.php/apps/educai/webhook/talk'],
			'correct pretty' => [self::BASE . '/apps/educai/webhook/talk'],
			'other server' => ['https://other.example/'],
			'other app' => [self::BASE . '/apps/other/webhook'],
			'other installation' => ['https://cloud.example:8443/other/'],
		];
	}

	/** @dataProvider unverifiableIdentity */
	public function testUpgradeDoesNotGuessWhichBotToRepair(array $metadata, ?string $secret): void {
		$this->absoluteUrls();
		$this->metadata = $metadata;
		if ($secret !== null) {
			$this->mapper->bot->data['secret'] = $secret;
		} else {
			$this->settings->setWebhookSecret('');
		}
		$before = $this->mapper->bot->data;
		$this->assertSame('skipped', $this->service->repairInvalidWebhookUrl()['status']);
		$this->assertSame($before, $this->mapper->bot->data);
		$this->assertSame([], $this->writes);
		$this->assertSame(0, $this->mapper->updates);
	}

	public static function unverifiableIdentity(): array {
		$valid = ['talk_bot_id' => '17', 'talk_bot_url' => self::BASE . '/'];
		return [
			'missing id' => [['talk_bot_url' => self::BASE . '/'], self::SECRET],
			'fractional id' => [array_replace($valid, ['talk_bot_id' => '17.5']), self::SECRET],
			'zero id' => [array_replace($valid, ['talk_bot_id' => '0']), self::SECRET],
			'foreign metadata' => [array_replace($valid, ['talk_bot_url' => 'https://other.example/']), self::SECRET],
			'missing metadata' => [['talk_bot_id' => '17'], self::SECRET],
			'mismatched secret' => [$valid, 'another-bots-secret'],
			'missing configured secret' => [$valid, null],
		];
	}

	public function testWriteReadbackFailureDoesNotClaimSuccessOrChangeMetadata(): void {
		$this->absoluteUrls();
		$this->mapper->discardUpdates = true;
		$this->assertSame('error', $this->service->repairInvalidWebhookUrl()['status']);
		$this->assertSame([], $this->writes);
	}

	public function testMissingManagedRecordIsReportedWithoutCreatingAnotherBot(): void {
		$this->absoluteUrls();
		$this->mapper->missing = true;
		$this->assertSame('error', $this->service->repairInvalidWebhookUrl()['status']);
		$this->assertSame(0, $this->mapper->updates);
		$this->assertSame([], $this->writes);
	}

	private function absoluteUrls(): void {
		$this->urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => self::BASE . '/' . $path);
	}
}

/** Persist cloned rows so the tests also detect false in-memory readback. */
class RegistrationMapperFixture {
	public int $updates = 0;
	public bool $discardUpdates = false;
	public bool $missing = false;

	public function __construct(
		public RegistrationBotFixture $bot,
	) {
	}

	public function findById(int $id): RegistrationBotFixture {
		if ($this->missing || $id !== $this->bot->data['id']) {
			throw new \RuntimeException('Missing fixture');
		}
		return clone $this->bot;
	}

	public function update(RegistrationBotFixture $bot): void {
		$this->updates++;
		if (!$this->discardUpdates) {
			$this->bot = clone $bot;
		}
	}
}

class RegistrationBotFixture {
	public function __construct(
		public array $data,
	) {
	}

	public function __call(string $name, array $args): mixed {
		$key = lcfirst(substr($name, 3));
		if (str_starts_with($name, 'get')) {
			return $this->data[$key];
		}
		if (str_starts_with($name, 'set')) {
			$this->data[$key] = $args[0];
			return null;
		}
		throw new \BadMethodCallException($name);
	}
}
