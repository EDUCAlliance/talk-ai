<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\AdminStatusController;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Service\BrandingService;
use OCA\EducAI\Service\CatalogueClient;
use OCA\EducAI\Service\ConnectionCheckStore;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\TalkBotRegistrationService;
use OCA\EducAI\Service\VisionClient;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminStatusControllerTest extends TestCase {
	private const WEBHOOK_URL = 'https://cloud.example/index.php/apps/educai/webhook/talk';

	/** @var array<string,string> */
	private array $storage = [];
	private ConnectionCheckStore $store;
	private LLMClient $llm;
	private DoclingClient $docling;
	private VisionClient $vision;
	private SpeechToTextClient $speech;
	private CatalogueClient $catalogue;
	private TalkBotRegistrationService $talk;

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->storage[$key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->storage[$key] = $value;
		});
		$this->store = new ConnectionCheckStore($config, $this->createMock(LoggerInterface::class));
		$this->llm = $this->createMock(LLMClient::class);
		$this->docling = $this->createMock(DoclingClient::class);
		$this->vision = $this->createMock(VisionClient::class);
		$this->speech = $this->createMock(SpeechToTextClient::class);
		$this->catalogue = $this->createMock(CatalogueClient::class);
		$this->talk = $this->createMock(TalkBotRegistrationService::class);
		$this->talk->method('getWebhookUrl')->willReturn(self::WEBHOOK_URL);
		$this->talk->method('hasWebhookSecret')->willReturn(true);
	}

	public function testStatusReportsRememberedChecksAndWhetherSavedSettingsStillMatch(): void {
		$this->store->record('model', true, 840, 'secondary:beta', null, $this->fingerprint('model-a'));
		$this->store->record('docling', false, 1200, 'Docling Serve 1.36.0 · x_api_key', 'Docling authentication failed (HTTP 401).', $this->fingerprint('docling-a'));
		$this->store->record('vision', true, 95, 'vision-model', null, $this->fingerprint('vision-tested'));
		$this->store->record('catalogue', true, 30, 'Catalogue API 2.4.1', null, null);
		$this->llm->expects($this->once())->method('connectionCheckTarget')->with('secondary:beta')
			->willReturn(['fingerprint' => $this->fingerprint('model-a'), 'summary' => 'secondary:beta']);
		$this->docling->expects($this->once())->method('connectionCheckTarget')
			->willReturn(['fingerprint' => $this->fingerprint('docling-a'), 'summary' => 'Docling Serve · x_api_key']);
		$this->vision->expects($this->once())->method('connectionCheckTarget')
			->willReturn(['fingerprint' => $this->fingerprint('vision-saved'), 'summary' => 'vision-model']);
		$this->speech->expects($this->never())->method('connectionCheckTarget');
		$this->catalogue->method('connectionCheckTarget')->willReturn(['fingerprint' => $this->fingerprint('catalogue'), 'summary' => null]);
		foreach ([$this->llm, $this->docling, $this->vision, $this->speech, $this->catalogue] as $client) {
			$this->assertNoTestRuns($client);
		}

		$response = $this->controller()->status();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(['checks', 'talk'], array_keys($data));
		$this->assertSame(ConnectionCheckStore::SERVICES, array_keys($data['checks']));
		foreach (['model', 'docling', 'vision', 'catalogue'] as $service) {
			$this->assertIsInt($data['checks'][$service]['checked_at']);
			$this->assertLessThanOrEqual(time(), $data['checks'][$service]['checked_at']);
			unset($data['checks'][$service]['checked_at']);
		}
		$this->assertSame([
			'checks' => [
				'model' => ['success' => true, 'duration_ms' => 840, 'summary' => 'secondary:beta', 'error' => null, 'current' => true],
				'docling' => ['success' => false, 'duration_ms' => 1200, 'summary' => 'Docling Serve 1.36.0 · x_api_key',
					'error' => 'Docling authentication failed (HTTP 401).', 'current' => true],
				'vision' => ['success' => true, 'duration_ms' => 95, 'summary' => 'vision-model', 'error' => null, 'current' => false],
				'speech' => null,
				'catalogue' => ['success' => true, 'duration_ms' => 30, 'summary' => 'Catalogue API 2.4.1', 'error' => null, 'current' => false],
			],
			'talk' => ['webhook_url' => self::WEBHOOK_URL, 'secret_configured' => true],
		], $data);
		$this->assertStringNotContainsString('fingerprint', json_encode($response->getData()));
	}

	public function testAnUnreadableCurrentConfigurationMarksTheCheckAsNotCurrent(): void {
		$this->store->record('model', true, 1, 'primary:alpha', null, $this->fingerprint('model'));
		$this->store->record('speech', true, 1, 'whisper', null, $this->fingerprint('speech'));
		$this->llm->method('connectionCheckTarget')->willThrowException(new \RuntimeException('cannot decrypt private-key'));
		$this->speech->method('connectionCheckTarget')->willReturn(['fingerprint' => null, 'summary' => 'whisper']);

		$data = $this->controller()->status()->getData();

		$this->assertFalse($data['checks']['model']['current']);
		$this->assertFalse($data['checks']['speech']['current']);
		$this->assertStringNotContainsString('private', json_encode($data));
	}

	public function testStatusFailureIsReportedWithoutDetails(): void {
		$talk = $this->createMock(TalkBotRegistrationService::class);
		$talk->method('getWebhookUrl')->willThrowException(new \RuntimeException('database private-detail'));
		$this->talk = $talk;

		$response = $this->controller()->status();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'translated:Failed to load the status', 'errorCode' => 'admin_status_failed'], $response->getData());
	}

	public function testTalkFactsComeFromTheManagedRegistrationWithoutRevealingTheSecret(): void {
		$settings = new Settings();
		$mapper = $this->createMock(SettingsMapper::class);
		$mapper->method('getSettings')->willReturn($settings);
		$urlGeneratorBuilder = $this->getMockBuilder(IURLGenerator::class)->onlyMethods(get_class_methods(IURLGenerator::class));
		if (!method_exists(IURLGenerator::class, 'linkToRouteAbsolute')) {
			// The unit-test stub of IURLGenerator predates linkToRouteAbsolute().
			$urlGeneratorBuilder->addMethods(['linkToRouteAbsolute']);
		}
		$urlGenerator = $urlGeneratorBuilder->getMock();
		$urlGenerator->expects($this->once())->method('linkToRouteAbsolute')->with('educai.webhook.talk')->willReturn(self::WEBHOOK_URL);
		$credentials = $this->createMock(CredentialService::class);
		$credentials->expects($this->never())->method('decrypt');
		$config = $this->createMock(IConfig::class);
		$service = new TalkBotRegistrationService($mapper, $credentials, $this->createMock(IAppManager::class), $config,
			$this->createMock(IRequest::class), $urlGenerator, $this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class), new BrandingService($config));

		$this->assertSame(self::WEBHOOK_URL, $service->getWebhookUrl());
		$this->assertFalse($service->hasWebhookSecret());
		$settings->setWebhookSecret('   ');
		$this->assertFalse($service->hasWebhookSecret());
		$settings->setWebhookSecret('$ENCRYPTED$private-secret');
		$this->assertTrue($service->hasWebhookSecret());
	}

	public function testStatusIsAnAdministratorOnlyGetRoute(): void {
		$method = new \ReflectionMethod(AdminStatusController::class, 'status');
		$doc = (string)$method->getDocComment();
		$this->assertStringContainsString('@AdminRequired', $doc);
		$this->assertStringNotContainsString('@NoAdminRequired', $doc);
		$this->assertStringNotContainsString('@PublicPage', $doc);
		$this->assertSame([], array_filter($method->getAttributes(), static fn (\ReflectionAttribute $attribute): bool
			=> str_ends_with($attribute->getName(), 'NoAdminRequired') || str_ends_with($attribute->getName(), 'PublicPage')));
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$this->assertContains(['name' => 'admin_status#status', 'url' => '/api/v1/admin/status', 'verb' => 'GET'], $routes['routes']);
	}

	private function controller(): AdminStatusController {
		$l10nBuilder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$l10nBuilder->addMethods(['t']);
		}
		$l10n = $l10nBuilder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'translated:' . $text);

		return new AdminStatusController('educai', $this->createMock(IRequest::class), $this->store, $this->llm,
			$this->docling, $this->vision, $this->speech, $this->catalogue, $this->talk,
			$this->createMock(LoggerInterface::class), $l10n);
	}

	/** Reading the status must never start a provider test. */
	private function assertNoTestRuns(object $client): void {
		foreach (['testConnection', 'testModelConnection', 'detect', 'getModelCatalogue'] as $method) {
			if (method_exists($client, $method)) {
				$client->expects($this->never())->method($method);
			}
		}
	}

	private function fingerprint(string $seed): string {
		return hash('sha256', $seed);
	}
}
