<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\CatalogueClient;
use OCA\EducAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatalogueClientTest extends TestCase {
	public function testFreshInstallationIsDisabledAndNormalMethodsNeverCreateHttpClient(): void {
		$settings = new Settings();
		$this->assertFalse($settings->getCatalogueEnabled());
		$settings->setCatalogueApiEndpoint('https://catalogue.example/api');
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->expects($this->never())->method('updateSettings');
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->never())->method('newClient');
		$catalogue = new CatalogueClient($clientService, $settingsService, $this->createMock(LoggerInterface::class));

		$this->assertFalse($catalogue->isEnabled());
		$this->assertNull($catalogue->getVersion());
		$this->assertFalse($catalogue->testConnection()['success']);
		foreach ([['searchCourses', []], ['getPastCourses', []], ['getFilterOptions', []], ['getCourseById', [42]]] as [$method, $args]) {
			try {
				$catalogue->$method(...$args);
				$this->fail('Disabled method must not reach the API: ' . $method);
			} catch (\Exception $e) {
				$this->assertStringContainsString('not enabled', $e->getMessage());
			}
		}
		$this->assertSame('https://catalogue.example/api', $settings->getCatalogueApiEndpoint());
	}

	public function testConfiguredEnabledInstallationRetainsEndpointAndSupportsCountPagination(): void {
		$settings = new Settings();
		$settings->setCatalogueEnabled(true);
		$settings->setCatalogueApiEndpoint('https://catalogue.example/api/');
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->expects($this->never())->method('updateSettings');
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"courses":[{"id":42,"title":"Physics"}],"count":3}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')
			->with('https://catalogue.example/api/course/list', $this->callback(static fn (array $options): bool => $options['query']['state'] === 'OPEN'))
			->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$catalogue = new CatalogueClient($clientService, $settingsService, $this->createMock(LoggerInterface::class));

		$this->assertTrue($catalogue->isEnabled());
		$this->assertSame(['courses' => [['id' => 42, 'title' => 'Physics']], 'more' => 2], $catalogue->searchCourses());
		$this->assertTrue($settings->getCatalogueEnabled());
		$this->assertSame('https://catalogue.example/api/', $settings->getCatalogueApiEndpoint());
	}

	public function testMalformedCoursePayloadIsNotTreatedAsAnEmptyCatalogue(): void {
		$settings = new Settings();
		$settings->setCatalogueEnabled(true);
		$settings->setCatalogueApiEndpoint('https://catalogue.example/api');
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"error":"temporarily unavailable"}');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$catalogue = new CatalogueClient($clientService, $settingsService, $this->createMock(LoggerInterface::class));

		foreach (['searchCourses', 'getPastCourses'] as $method) {
			try {
				$catalogue->$method();
				$this->fail('Malformed response must not be accepted');
			} catch (\Exception $e) {
				$this->assertStringContainsString('missing its courses list', $e->getMessage());
			}
		}
	}

	public function testConnectionUsesExplicitUnsavedEndpoint(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"version":"2.4.0"}');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with(
				'https://catalogue.example/api/version',
				$this->callback(static fn (array $options): bool => ($options['timeout'] ?? null) === 10
					&& ($options['headers']['Accept'] ?? null) === 'application/json')
			)
			->willReturn($response);

		$catalogueClient = $this->createCatalogueClient($client);

		$this->assertSame([
			'success' => true,
			'version' => '2.4.0',
			'error' => null,
		], $catalogueClient->testConnection('https://catalogue.example/api/'));
	}

	public function testConnectionFailsWhenVersionEndpointReturnsNoVersion(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{}');

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		$catalogueClient = $this->createCatalogueClient($client);
		$result = $catalogueClient->testConnection('https://catalogue.example/api');

		$this->assertFalse($result['success']);
		$this->assertNull($result['version']);
		$this->assertSame('Catalogue API version endpoint is unavailable or returned no version', $result['error']);
	}

	private function createCatalogueClient(IClient $client): CatalogueClient {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new CatalogueClient(
			$clientService,
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
