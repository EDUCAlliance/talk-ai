<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\ToolsController;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\Service\BuiltInToolUiService;
use OCA\EducAI\Service\CatalogueClient;
use OCA\EducAI\Service\ConnectionCheckStore;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\McpClient;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\VisionClient;
use OCA\EducAI\Service\WikiLocationService;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ToolsControllerTest extends TestCase {
	/** @var array<string,string> */
	private array $checkStorage = [];

	public function testAvailableLocalizesOnlyBuiltInUiMetadata(): void {
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolRegistry->method('getEnabledTools')->willReturn([]);
		$providerRegistry = $this->createMock(ToolProviderRegistry::class);
		$providerRegistry->method('getAvailableTools')->willReturn([[
			'name' => BuiltInToolProvider::TOOL_RAG_SEARCH,
			'label' => 'Provider label',
			'description' => 'Model-facing schema description',
		]]);
		$l10n = $this->createL10n();
		$controller = $this->createController($toolRegistry, $providerRegistry, $l10n);

		$response = $controller->available();
		$tool = $response->getData()['tools'][0];

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(BuiltInToolProvider::TOOL_RAG_SEARCH, $tool['builtin_name']);
		$this->assertSame('translated:Document Search (RAG)', $tool['name']);
		$this->assertSame('translated:Search through indexed documents attached to this bot.', $tool['description']);
	}

	public function testAvailableDoesNotExposeRegistryFailure(): void {
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolRegistry->method('getEnabledTools')->willThrowException(new \RuntimeException('provider credential secret'));
		$providerRegistry = $this->createMock(ToolProviderRegistry::class);
		$controller = $this->createController($toolRegistry, $providerRegistry, $this->createL10n());

		$response = $controller->available();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('tools_load_failed', $response->getData()['errorCode']);
		$this->assertSame('translated:Failed to load available tools', $response->getData()['error']);
		$this->assertStringNotContainsString('secret', $response->getData()['error']);
	}

	public function testCatalogueConnectionUsesUnsavedEndpointWithoutChangingSettings(): void {
		$catalogueClient = $this->createMock(CatalogueClient::class);
		$catalogueClient->expects($this->once())->method('testConnection')
			->with('https://catalogue.example/api')->willReturn(['success' => true, 'version' => '1.0', 'error' => null]);
		$controller = $this->createController(
			$this->createMock(ToolRegistry::class),
			$this->createMock(ToolProviderRegistry::class),
			$this->createL10n(),
			$catalogueClient,
		);

		$this->assertSame(['success' => true, 'error' => null], $controller->testCatalogue('https://catalogue.example/api')->getData());
	}

	public function testCatalogueConnectionFailureUsesSafeTranslatedError(): void {
		$catalogueClient = $this->createMock(CatalogueClient::class);
		$catalogueClient->method('testConnection')->willReturn(['success' => false, 'version' => null, 'error' => 'upstream-private-error']);
		$controller = $this->createController(
			$this->createMock(ToolRegistry::class),
			$this->createMock(ToolProviderRegistry::class),
			$this->createL10n(),
			$catalogueClient,
		);

		$response = $controller->testCatalogue();
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('translated:Catalogue connection test failed', $response->getData()['error']);
	}

	public function testDoclingTestForwardsUnsavedProfileAndAuthAndKeepsSafeDiagnostic(): void {
		$docling = $this->createMock(DoclingClient::class);
		$docling->expects($this->once())->method('testConnection')
			->with('https://docling.example/proxy/v1/convert/source', null, 'docling_serve', 'none')
			->willReturn(['success' => false, 'error' => 'Use the file-upload endpoint /v1/convert/file.']);
		$controller = $this->createController($this->createMock(ToolRegistry::class),
			$this->createMock(ToolProviderRegistry::class), $this->createL10n(), doclingClient: $docling);
		$response = $controller->testDocling('https://docling.example/proxy/v1/convert/source', null, 'docling_serve', 'none');
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('docling_connection_failed', $response->getData()['errorCode']);
		$this->assertSame('Use the file-upload endpoint /v1/convert/file.', $response->getData()['error']);
	}

	public function testDoclingTestStillHidesUnexpectedExceptionDetails(): void {
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('testConnection')->willThrowException(new \RuntimeException('private credential'));
		$controller = $this->createController($this->createMock(ToolRegistry::class),
			$this->createMock(ToolProviderRegistry::class), $this->createL10n(), doclingClient: $docling);
		$response = $controller->testDocling();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('translated:Docling connection test failed', $response->getData()['error']);
	}

	public function testDoclingDetectionReturnsTheResultAndRemembersACurrentCheck(): void {
		$detection = $this->detection();
		$fingerprint = ConnectionCheckStore::fingerprint('docling', ['https://docling.example/v1/convert/file', 'docling_serve', 'x_api_key'], 'typed-key');
		$docling = $this->createMock(DoclingClient::class);
		$docling->expects($this->once())->method('detect')->with('https://docling.example', ' typed-key ')->willReturn($detection);
		$docling->expects($this->once())->method('connectionCheckTarget')
			->with('https://docling.example', 'typed-key', 'docling_serve', 'x_api_key')
			->willReturn(['fingerprint' => $fingerprint, 'summary' => 'Docling Serve · x_api_key']);
		$controller = $this->createController($this->createMock(ToolRegistry::class), $this->createMock(ToolProviderRegistry::class),
			$this->createL10n(), doclingClient: $docling, connectionChecks: $this->checkStore());

		$response = $controller->detectDocling('https://docling.example', ' typed-key ');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($detection, $response->getData());
		$this->assertSame(['success' => true, 'duration_ms' => 321, 'summary' => 'Docling Serve 1.36.0 · x_api_key',
			'error' => null, 'fingerprint' => $fingerprint], $this->remembered('docling'));
	}

	public function testDoclingDetectionCanIgnoreAStoredKeyThatIsBeingRemoved(): void {
		$detection = ['auth_mode' => 'none'] + $this->detection();
		$docling = $this->createMock(DoclingClient::class);
		$docling->expects($this->once())->method('detect')->with('https://docling.example', '', true)->willReturn($detection);
		$docling->expects($this->once())->method('connectionCheckTarget')
			->with('https://docling.example', null, 'docling_serve', 'none', true)
			->willReturn(['fingerprint' => str_repeat('b', 64), 'summary' => 'Docling Serve · none']);
		$controller = $this->createController($this->createMock(ToolRegistry::class), $this->createMock(ToolProviderRegistry::class),
			$this->createL10n(), doclingClient: $docling, connectionChecks: $this->checkStore());

		$this->assertSame(200, $controller->detectDocling('https://docling.example', '', true)->getStatus());
		$this->assertSame(str_repeat('b', 64), $this->remembered('docling')['fingerprint']);
	}

	public function testFailedDoclingConversionAfterDetectionIsStillReported(): void {
		$detection = ['success' => false, 'auth_mode' => 'none', 'version' => null, 'source' => 'probe',
			'error' => 'Docling authentication failed (HTTP 401). Check the authentication mode and Docling API key.'] + $this->detection();
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('detect')->willReturn($detection);
		$docling->expects($this->once())->method('connectionCheckTarget')->with('https://docling.example', null, 'docling_serve', 'none')
			->willReturn(['fingerprint' => str_repeat('a', 64), 'summary' => 'Docling Serve · none']);
		$controller = $this->createController($this->createMock(ToolRegistry::class), $this->createMock(ToolProviderRegistry::class),
			$this->createL10n(), doclingClient: $docling, connectionChecks: $this->checkStore());

		$response = $controller->detectDocling('https://docling.example', '');

		$this->assertSame(200, $response->getStatus(), 'The UI can say: detected, but authentication failed');
		$this->assertSame($detection, $response->getData());
		$this->assertSame(['success' => false, 'summary' => 'Docling Serve · none', 'error' => $detection['error']],
			array_intersect_key($this->remembered('docling'), array_flip(['success', 'summary', 'error'])));
	}

	public function testUndetectableDoclingServerIsRememberedAsAFailureThatIsNeverCurrent(): void {
		$detection = ['success' => false, 'profile' => null, 'auth_mode' => null, 'service' => null, 'version' => null,
			'source' => null, 'error' => 'No Docling API was detected at this address. Check the URL or enter the full conversion endpoint.'] + $this->detection();
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('detect')->willReturn($detection);
		$docling->expects($this->never())->method('connectionCheckTarget');
		$controller = $this->createController($this->createMock(ToolRegistry::class), $this->createMock(ToolProviderRegistry::class),
			$this->createL10n(), doclingClient: $docling, connectionChecks: $this->checkStore());

		$this->assertSame(200, $controller->detectDocling('https://docling.example')->getStatus());
		$this->assertSame(['success' => false, 'duration_ms' => 321, 'summary' => null, 'error' => $detection['error'], 'fingerprint' => null],
			$this->remembered('docling'));
	}

	public function testDoclingDetectionRejectsInvalidInputWithoutRememberingACheck(): void {
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('detect')->willThrowException(new \InvalidArgumentException('Docling endpoint must be an HTTP(S) URL without embedded credentials or a fragment.'));
		$controller = $this->createController($this->createMock(ToolRegistry::class), $this->createMock(ToolProviderRegistry::class),
			$this->createL10n(), doclingClient: $docling, connectionChecks: $this->checkStore());

		$response = $controller->detectDocling('https://user:secret@docling.example');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([
			'success' => false,
			'error' => 'Docling endpoint must be an HTTP(S) URL without embedded credentials or a fragment.',
			'errorCode' => 'docling_detection_invalid',
		], $response->getData());
		$this->assertSame([], $this->checkStorage);
	}

	public function testUnexpectedDoclingDetectionFailureIsNotExposedOrLogged(): void {
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('detect')->willThrowException(new \RuntimeException('Bearer private-credential'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Docling detection failed unexpectedly', $this->callback(
			static fn (array $context): bool => !str_contains(json_encode($context), 'private') && !isset($context['exception'])
		));
		$controller = $this->createController($this->createMock(ToolRegistry::class), $this->createMock(ToolProviderRegistry::class),
			$this->createL10n(), doclingClient: $docling, connectionChecks: $this->checkStore(), logger: $logger);

		$response = $controller->detectDocling('https://docling.example');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'translated:Docling detection failed', 'errorCode' => 'docling_detection_failed'], $response->getData());
		$this->assertSame([], $this->checkStorage);
	}

	public function testConnectionTestsRememberTheirTargetAndOnlyTheSafeError(): void {
		$vision = $this->createMock(VisionClient::class);
		$vision->method('testConnection')->willReturn(['success' => false, 'error' => 'HTTP 500 private upstream body Bearer private-key']);
		$vision->expects($this->once())->method('connectionCheckTarget')->with('https://vision.example', null, 'vision-model')
			->willReturn(['fingerprint' => str_repeat('b', 64), 'summary' => 'vision-model']);
		$speech = $this->createMock(SpeechToTextClient::class);
		$speech->method('testConnection')->willThrowException(new \Exception('private transport detail'));
		$speech->method('connectionCheckTarget')->willReturn(['fingerprint' => str_repeat('c', 64), 'summary' => 'whisper']);
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('testConnection')->willReturn(['success' => true]);
		$docling->expects($this->once())->method('connectionCheckTarget')->with('https://docling.example', null, 'docling_serve', 'none')
			->willReturn(['fingerprint' => str_repeat('d', 64), 'summary' => 'Docling Serve · none']);
		$catalogue = $this->createMock(CatalogueClient::class);
		$catalogue->method('testConnection')->willReturn(['success' => true, 'version' => " 2.4.1\n", 'error' => null]);
		$catalogue->expects($this->once())->method('connectionCheckTarget')->with('https://catalogue.example/api')
			->willReturn(['fingerprint' => str_repeat('e', 64), 'summary' => null]);
		$controller = $this->createController($this->createMock(ToolRegistry::class), $this->createMock(ToolProviderRegistry::class),
			$this->createL10n(), $catalogue, $docling, $vision, $speech, $this->checkStore());

		$this->assertSame(400, $controller->testVision('https://vision.example', null, 'vision-model')->getStatus());
		$this->assertSame(400, $controller->testSpeech()->getStatus());
		$this->assertSame(200, $controller->testDocling('https://docling.example', null, 'docling_serve', 'none')->getStatus());
		$this->assertSame(200, $controller->testCatalogue('https://catalogue.example/api')->getStatus());

		$this->assertSame([false, 'vision-model', 'translated:Vision connection test failed', str_repeat('b', 64)],
			array_values(array_intersect_key($this->remembered('vision'), array_flip(['success', 'summary', 'error', 'fingerprint']))));
		$this->assertSame([false, 'whisper', 'translated:Speech connection test failed'],
			array_values(array_intersect_key($this->remembered('speech'), array_flip(['success', 'summary', 'error']))));
		$this->assertSame([true, 'Docling Serve · none', null],
			array_values(array_intersect_key($this->remembered('docling'), array_flip(['success', 'summary', 'error']))));
		$this->assertSame([true, 'Catalogue API 2.4.1', null],
			array_values(array_intersect_key($this->remembered('catalogue'), array_flip(['success', 'summary', 'error']))));
		foreach (['vision', 'speech', 'docling', 'catalogue'] as $service) {
			$this->assertIsInt($this->remembered($service)['duration_ms']);
		}
		$this->assertStringNotContainsString('private', json_encode($this->checkStorage));
	}

	public function testDetectionIsAnAdministratorOnlyPostRoute(): void {
		$doc = (string)(new \ReflectionMethod(ToolsController::class, 'detectDocling'))->getDocComment();
		$this->assertStringContainsString('@AdminRequired', $doc);
		$this->assertStringNotContainsString('@NoAdminRequired', $doc);
		$this->assertStringNotContainsString('@PublicPage', $doc);
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$this->assertContains(['name' => 'tools#detectDocling', 'url' => '/api/v1/admin/docling/detect', 'verb' => 'POST'], $routes['routes']);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function detection(): array {
		return [
			'success' => true, 'profile' => 'docling_serve', 'auth_mode' => 'x_api_key', 'service' => 'Docling Serve',
			'title' => 'Docling Serve', 'version' => '1.36.0', 'source' => 'openapi', 'endpoint' => 'https://docling.example',
			'conversion_url' => 'https://docling.example/v1/convert/file', 'duration_ms' => 321, 'error' => null,
		];
	}

	private function checkStore(): ConnectionCheckStore {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->checkStorage[$key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->checkStorage[$key] = $value;
		});
		return new ConnectionCheckStore($config, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function remembered(string $service): array {
		$record = json_decode($this->checkStorage['connection_check_' . $service] ?? 'null', true);
		$this->assertIsArray($record, 'No check remembered for ' . $service);
		$this->assertIsInt($record['checked_at']);
		unset($record['checked_at']);
		return $record;
	}

	private function createController(
		ToolRegistry $toolRegistry,
		ToolProviderRegistry $providerRegistry,
		IL10N $l10n,
		?CatalogueClient $catalogueClient = null,
		?DoclingClient $doclingClient = null,
		?VisionClient $visionClient = null,
		?SpeechToTextClient $speechClient = null,
		?ConnectionCheckStore $connectionChecks = null,
		?LoggerInterface $logger = null,
	): ToolsController {
		return new ToolsController(
			'educai',
			$this->createMock(IRequest::class),
			$this->createMock(ToolMapper::class),
			$toolRegistry,
			$this->createMock(McpClient::class),
			$doclingClient ?? $this->createMock(DoclingClient::class),
			$visionClient ?? $this->createMock(VisionClient::class),
			$speechClient ?? $this->createMock(SpeechToTextClient::class),
			$providerRegistry,
			$this->createMock(CredentialService::class),
			$this->createMock(WikiLocationService::class),
			'alice',
			$logger ?? $this->createMock(LoggerInterface::class),
			$l10n,
			new BuiltInToolUiService($l10n),
			$catalogueClient ?? $this->createMock(CatalogueClient::class),
			$connectionChecks,
		);
	}

	private function createL10n(): IL10N {
		$l10nBuilder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$l10nBuilder->addMethods(['t']);
		}
		$l10n = $l10nBuilder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'translated:' . $text);

		return $l10n;
	}
}
