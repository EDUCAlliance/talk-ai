<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi;

require_once __DIR__ . '/Support/OpenApiHarness.php';

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Db\ApiSpecRevision;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\OpenApi\ApiConnectionException;
use OCA\EducAI\OpenApi\ApiConnectionService;
use OCA\EducAI\OpenApi\ApiHttpTransport;
use OCA\EducAI\OpenApi\CatalogueApiSource;
use OCA\EducAI\OpenApi\OpenApiCompiler;
use OCA\EducAI\OpenApi\OpenApiDocumentLoader;
use OCA\EducAI\Service\CatalogueClient;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Tests\Unit\OpenApi\Support\FakeResponse;
use OCA\EducAI\Tests\Unit\OpenApi\Support\OpenApiHarness;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class EducCataloguePresetTest extends TestCase {
	private const DIRECTORY = __DIR__ . '/../../../lib/OpenApi/Presets/educ-catalogue';
	private const BASE = 'https://catalogue.educalliance.eu/api';

	private OpenApiHarness $harness;

	protected function setUp(): void {
		$this->harness = new OpenApiHarness($this);
	}

	public function testTheShippedUpstreamDocumentIsUnmodifiedAndKeepsItsKnownDefects(): void {
		$manifest = $this->harness->presets->manifest('educ-catalogue');
		$bytes = (string)file_get_contents(self::DIRECTORY . '/' . $manifest['upstream']['file']);
		self::assertSame($manifest['upstream']['sha256'], hash('sha256', $bytes));

		$operations = $this->compile($bytes);
		self::assertSame('https://catalogue.educalliance.eu/api/api', $operations['GET /course/list']['servers']['urls'][0]['resolved']);
		self::assertContains('request_body_unsupported', array_column($operations['GET /course/list']['blockers'], 'code'));
		self::assertArrayNotHasKey('GET /course/view', $operations);
		foreach (['GET /application/apply', 'GET /application/unapply', 'GET /favourite/create', 'GET /favourite/delete', 'GET /login', 'GET /login/logout'] as $mutating) {
			self::assertContains('possibly_mutating', array_column($operations[$mutating]['notes'], 'code'), $mutating);
		}
	}

	public function testTheNormalizedDocumentOnlyDescribesSupportedReads(): void {
		$operations = $this->compile((string)file_get_contents(self::DIRECTORY . '/openapi.json'));
		$manifest = $this->harness->presets->manifest('educ-catalogue');

		self::assertSame(['GET /course/list', 'GET /course/view', 'GET /version'], array_keys($operations));
		self::assertSame(array_keys($operations), array_keys($manifest['operations']));
		foreach ($operations as $key => $operation) {
			self::assertTrue($operation['supported'], $key);
			self::assertSame([], $operation['blockers'], $key);
			self::assertSame([], $operation['notes'], $key);
			self::assertSame(self::BASE, $operation['servers']['urls'][0]['resolved'], $key);
		}
		self::assertSame(self::BASE, $manifest['connection']['base_url']);
		$state = $this->parameter($operations['GET /course/list'], 'state');
		self::assertTrue($state['required']);
		self::assertSame(['OPEN', 'CLOSED'], $state['schema']['enum']);
		self::assertSame('string', $this->parameter($operations['GET /course/list'], 'search')['schema']['type']);
		self::assertTrue($this->parameter($operations['GET /course/view'], 'id')['required']);
	}

	public function testCreatingThePresetLeavesEverythingToTheAdministrator(): void {
		$created = $this->harness->presets->create('educ-catalogue', OpenApiHarness::ADMIN);
		$connection = $this->harness->connections->findById($created['connection']->getId());
		$revision = $created['revision'];

		self::assertFalse($connection->isEnabled());
		self::assertSame(self::BASE, $connection->getBaseUrl());
		self::assertSame(ApiConnection::AUTH_NONE, $connection->getAuthMode());
		self::assertNotSame(ApiConnection::VISIBILITY_SHARED_FOR_BOT_AUDIENCE, $connection->getDataVisibility());
		self::assertNull($connection->getActiveRevisionId());
		self::assertSame(ApiSpecRevision::SOURCE_PRESET, $revision->getSourceKind());
		self::assertTrue($revision->isDraft());
		self::assertSame([], $this->harness->client->requests);

		$config = $revision->getOpConfigArray();
		foreach (ApiConnectionService::operationsById($revision) as $id => $operation) {
			self::assertTrue($config[$id]['enabled'], $operation['match_key']);
			self::assertFalse($config[$id]['read_confirmed'], $operation['match_key']);
			self::assertSame('none', $config[$id]['auth']);
		}
		$problems = $this->harness->service->approvalProblems($revision, $connection);
		self::assertCount(3, $problems);
		foreach ($problems as $list) {
			self::assertSame(['read_not_confirmed'], $list);
		}
		$this->expectConnectionError('no_approved_revision', fn () => $this->harness->service->setEnabled($connection->getId(), true, OpenApiHarness::ADMIN));
		$this->expectConnectionError('catalogue_source_unavailable', fn () => $this->harness->catalogueSource->select($connection->getId()));
		$this->expectConnectionError('preset_not_found', fn () => $this->harness->presets->create('../educ-catalogue', OpenApiHarness::ADMIN));
	}

	public function testTheApiSourceReadsCoursesExactlyLikeTheLegacyEndpoint(): void {
		$this->approvePreset();
		$legacyQueue = [];
		$legacyRequests = [];
		$legacy = $this->catalogueClient(null, $this->legacyClientService($legacyQueue, $legacyRequests));
		$adapter = $this->catalogueClient($this->harness->catalogueSource);
		self::assertTrue($adapter->isEnabled());
		self::assertTrue($adapter->usesApiConnection());

		$current = ['courses' => [['id' => 7, 'title' => 'Synthetic robotics', 'language' => ['id' => 1, 'name' => 'English']]], 'count' => 3];
		$archived = ['courses' => [['id' => 8, 'title' => 'Synthetic history']], 'morePast' => 4];
		$lookupMiss = ['courses' => array_map(static fn (int $id): array => ['id' => $id, 'title' => 'Course ' . $id], range(1, 200)), 'count' => 201];
		$lookupHit = ['courses' => [['id' => 777, 'title' => 'Found']], 'count' => 201];
		$cases = [
			[[$current], static fn (CatalogueClient $c): array => $c->searchCourses('robotics', ['discipline_id' => [4, 5], 'language_id' => 2, 'unknown' => 1], 1, 1)],
			[[$current], static fn (CatalogueClient $c): array => $c->searchCourses('@42')],
			[[$archived], static fn (CatalogueClient $c): array => $c->getPastCourses(['semester_id' => [1, 2], 'language_id' => 3], 500, 0)],
			[[$lookupMiss, $lookupHit], static fn (CatalogueClient $c): ?array => $c->getCourseById(777, true)],
			[[['version' => '3.0.2']], static fn (CatalogueClient $c): array => $c->testConnection()],
		];
		foreach ($cases as $index => [$responses, $call]) {
			foreach ($responses as $response) {
				$legacyQueue[] = $response;
				$this->harness->respondJson($response);
			}
			$before = count($this->harness->client->requests);
			self::assertSame($call($legacy), $call($adapter), 'case ' . $index);
			$sent = array_slice($this->harness->client->requests, $before);
			self::assertSame(array_slice($legacyRequests, -count($sent)), array_map(self::requestKey(...), $sent), 'case ' . $index);
		}
		$this->harness->respondJson($current);
		self::assertSame(['courses' => $current['courses'], 'more' => 2], $adapter->searchCourses());
		self::assertSame([], $this->harness->responses);
	}

	public function testArchivedSnapshotsMayExceedTheToolResponseLimitButStayBounded(): void {
		$this->approvePreset();
		$adapter = $this->catalogueClient($this->harness->catalogueSource);
		$large = ['courses' => [['id' => 1, 'title' => 'Large', 'description' => str_repeat('x', ApiHttpTransport::MAX_RESPONSE_BYTES)]], 'count' => 1];
		$this->harness->respondJson($large);
		self::assertSame(['courses' => $large['courses'], 'morePast' => 0], $adapter->getPastCourses([], 500, 0));

		$this->harness->responses[] = new FakeResponse(200, ['Content-Type' => 'application/json'], '"' . str_repeat('x', CatalogueApiSource::MAX_RESPONSE_BYTES) . '"');
		$this->expectCatalogueFailure($adapter, 'response_too_large');
	}

	public function testFailuresAreNeverAnEmptyCatalogue(): void {
		$connectionId = $this->approvePreset();
		$adapter = $this->catalogueClient($this->harness->catalogueSource);

		$this->harness->respondJson(['error' => 'Wrong or missing course state'], 500);
		$this->expectCatalogueFailure($adapter, 'upstream_error');
		$this->harness->respondJson(['error' => 'temporarily unavailable']);
		$this->expectCatalogueFailure($adapter, 'missing its courses list');
		$this->harness->respondJson([]);
		$this->expectCatalogueFailure($adapter, 'missing its courses list');
		$this->harness->respondJson([['id' => 1]]);
		$this->expectCatalogueFailure($adapter, 'response_unexpected');
		$this->harness->responses[] = new FakeResponse(200, ['Content-Type' => 'text/html'], '<html></html>');
		$this->expectCatalogueFailure($adapter, 'response_not_json');

		$sent = count($this->harness->client->requests);
		$this->harness->service->setEnabled($connectionId, false, OpenApiHarness::ADMIN);
		$this->expectCatalogueFailure($adapter, 'connection_disabled');
		self::assertTrue($adapter->isEnabled());
		$this->harness->service->setEnabled($connectionId, true, OpenApiHarness::ADMIN);
		$this->harness->service->updateConnection($connectionId, ['base_url' => 'https://catalogue.example.org/api'], OpenApiHarness::ADMIN);
		$this->expectCatalogueFailure($adapter, 'base_url_changed');
		$this->harness->service->updateConnection($connectionId, ['base_url' => self::BASE], OpenApiHarness::ADMIN);
		$active = $this->harness->service->activeRevision($this->harness->connections->findById($connectionId));
		$this->harness->service->revokeOperation($active->getId(), $this->harness->operationId($active, CatalogueApiSource::LIST_OPERATION), OpenApiHarness::ADMIN);
		$this->expectCatalogueFailure($adapter, 'operation_revoked');
		self::assertSame($sent, count($this->harness->client->requests));
		self::assertNull($this->harness->catalogueSource->operationStatus($connectionId)['GET /version']);
		self::assertSame('operation_revoked', $this->harness->catalogueSource->operationStatus($connectionId)[CatalogueApiSource::LIST_OPERATION]);
	}

	public function testSwitchingBackUsesTheLegacyEndpointWithoutTouchingTheConnection(): void {
		$connectionId = $this->approvePreset();
		$this->harness->catalogueSource->select(null);
		self::assertSame([], $this->harness->appConfig);
		$queue = [['courses' => [], 'count' => 0]];
		$requests = [];
		$client = $this->catalogueClient($this->harness->catalogueSource, $this->legacyClientService($queue, $requests), self::BASE);

		self::assertFalse($client->usesApiConnection());
		self::assertSame(['courses' => [], 'more' => 0], $client->searchCourses());
		self::assertCount(1, $requests);
		self::assertSame([], $this->harness->client->requests);
		self::assertTrue($this->harness->connections->findById($connectionId)->isEnabled());
		self::assertFalse($this->catalogueClient($this->harness->catalogueSource)->isEnabled());
	}

	private function expectCatalogueFailure(CatalogueClient $client, string $needle): void {
		try {
			$client->getPastCourses();
			self::fail('Expected a failure containing ' . $needle);
		} catch (\Exception $e) {
			self::assertStringContainsString($needle, $e->getMessage());
		}
	}

	/**
	 * Legacy HTTP double. Requests are recorded in the same form as requestKey().
	 *
	 * @param list<array<string,mixed>> $queue
	 * @param list<string> $requests
	 */
	private function legacyClientService(array &$queue, array &$requests): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $uri, array $options) use (&$queue, &$requests): IResponse {
			$query = array_map('strval', $options['query'] ?? []);
			ksort($query);
			$requests[] = $uri . ($query === [] ? '' : '?' . http_build_query($query));
			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn(json_encode(array_shift($queue), JSON_THROW_ON_ERROR));
			return $response;
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return $service;
	}

	/** @param array{uri:string} $request */
	private static function requestKey(array $request): string {
		[$path, $raw] = array_pad(explode('?', $request['uri'], 2), 2, '');
		parse_str($raw, $query);
		ksort($query);
		return $path . ($query === [] ? '' : '?' . http_build_query($query));
	}

	/** @return array<string,array<string,mixed>> */
	private function compile(string $bytes): array {
		$loaded = (new OpenApiDocumentLoader())->load($bytes);
		$operations = [];
		foreach ((new OpenApiCompiler())->compile($loaded['document'], $loaded['version'], 'https://catalogue.educalliance.eu/api/')['operations'] as $operation) {
			$operations[$operation['match_key']] = $operation;
		}
		return $operations;
	}

	/** @return array<string,mixed> */
	private function parameter(array $operation, string $name): array {
		foreach ($operation['parameters'] as $parameter) {
			if ($parameter['name'] === $name) {
				return $parameter;
			}
		}
		self::fail('Missing parameter ' . $name);
	}

	private function approvePreset(): int {
		$created = $this->harness->presets->create('educ-catalogue', OpenApiHarness::ADMIN);
		$id = $created['connection']->getId();
		$edits = [];
		foreach (array_keys(ApiConnectionService::operationsById($created['revision'])) as $operationId) {
			$edits[$operationId] = ['read_confirmed' => true];
		}
		$this->harness->service->updateDraftOperations($created['revision']->getId(), $edits, OpenApiHarness::ADMIN);
		$this->harness->service->updateConnection($id, ['data_visibility' => ApiConnection::VISIBILITY_SHARED_FOR_BOT_AUDIENCE], OpenApiHarness::ADMIN);
		$this->harness->service->approve($created['revision']->getId(), OpenApiHarness::ADMIN);
		$this->harness->service->setEnabled($id, true, OpenApiHarness::ADMIN);
		$this->harness->catalogueSource->select($id);
		return $id;
	}

	private function catalogueClient(?CatalogueApiSource $source, ?IClientService $clientService = null, ?string $endpoint = null): CatalogueClient {
		$settings = new Settings();
		$settings->setCatalogueEnabled(true);
		$settings->setCatalogueApiEndpoint($endpoint ?? ($source === null ? self::BASE : ''));
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$clientService ??= $this->createMock(IClientService::class);
		return new CatalogueClient($clientService, $settingsService, $this->createMock(LoggerInterface::class), $source);
	}

	private function expectConnectionError(string $code, callable $action): void {
		try {
			$action();
			self::fail('Expected ' . $code);
		} catch (ApiConnectionException $e) {
			self::assertSame($code, $e->getErrorCode());
		}
	}
}
