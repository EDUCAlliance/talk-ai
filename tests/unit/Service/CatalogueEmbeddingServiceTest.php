<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\CatalogueEmbedding;
use OCA\EducAI\Db\CatalogueEmbeddingMapper;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\CatalogueClient;
use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatalogueEmbeddingServiceTest extends TestCase {
	private CatalogueClient&MockObject $client;
	private CatalogueEmbeddingMapper&MockObject $mapper;
	private EmbeddingClient&MockObject $embeddings;
	private SettingsService&MockObject $settingsService;
	private IConfig&MockObject $config;
	private CatalogueEmbeddingService $service;
	private Settings $settings;
	private bool $enabled = true;
	private array $status = [];

	protected function setUp(): void {
		$this->client = $this->createMock(CatalogueClient::class);
		$this->client->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$this->mapper = $this->createMock(CatalogueEmbeddingMapper::class);
		$this->embeddings = $this->createMock(EmbeddingClient::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settings = new Settings();
		$this->settings->setCatalogueEnabled(true);
		$this->settings->setCatalogueApiEndpoint('https://catalogue.example/api');
		$this->settingsService->method('getSettings')->willReturn($this->settings);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(fn (): string => json_encode($this->status));
		$this->config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->assertSame('educai', $app);
			$this->assertSame('catalogue_reindex_status', $key);
			$this->status = json_decode($value, true);
		});
		$this->service = new CatalogueEmbeddingService(
			$this->client, $this->mapper, $this->embeddings, $this->settingsService,
			$this->createMock(LoggerInterface::class), $this->config,
		);
	}

	public function testDisabledIntegrationDoesNotSearchReadIndexOrReindex(): void {
		$this->enabled = false;
		$this->client->expects($this->never())->method('searchCourses');
		$this->client->expects($this->never())->method('getPastCourses');
		$this->embeddings->expects($this->never())->method('embedTexts');
		$this->mapper->expects($this->never())->method('findByCourseIdAndPast');
		$this->mapper->expects($this->never())->method('deleteStale');
		$this->mapper->expects($this->never())->method('count');
		$this->config->expects($this->never())->method('setAppValue');
		$this->settingsService->expects($this->never())->method('updateCatalogueIndexStats');

		$this->assertFalse($this->service->reindex()['success']);
		$this->assertSame(['courses' => [], 'total' => 0], $this->service->search('physics'));
		$this->assertSame(['courses' => [], 'total' => 0], $this->service->searchPast('physics'));
		$this->assertNull($this->service->getCourseFromEmbeddings(42, false));
	}

	public function testSuccessfulIndexPreservesCourseIdsAndUsesOneModelForBothPartitions(): void {
		$this->client->method('searchCourses')->willReturn(['courses' => [['id' => 42, 'title' => 'Physics']], 'more' => 0]);
		$this->client->method('getPastCourses')->willReturn(['courses' => [['id' => 81, 'title' => 'History']], 'morePast' => 0]);
		$this->embeddings->expects($this->once())->method('getActiveModel')->willReturn('embedding-v2');
		$this->embeddings->expects($this->exactly(2))->method('embedTexts')
			->with($this->isType('array'), 'embedding-v2')->willReturn([[1.0, 0.0]]);
		$stored = [];
		$this->mapper->expects($this->exactly(2))->method('upsert')->willReturnCallback(function (CatalogueEmbedding $entity) use (&$stored): CatalogueEmbedding {
			$stored[] = [$entity->getCourseId(), $entity->getIsPast(), $entity->getEmbeddingModel()];
			return $entity;
		});
		$this->mapper->expects($this->once())->method('deleteStale')->with([42], [81])->willReturn(0);
		$this->mapper->method('count')->willReturn(2);
		$this->settingsService->expects($this->once())->method('updateCatalogueIndexStats')->with(2);

		$result = $this->service->reindex();
		$this->assertTrue($result['success']);
		$this->assertSame(2, $result['count']);
		$this->assertSame([[42, 0, 'embedding-v2'], [81, 1, 'embedding-v2']], $stored);
		$this->assertSame('completed', $this->status['state']);
		$this->assertNull($this->status['last_error']);
		$this->assertIsInt($this->status['finished_at']);
		$this->assertSame('https://catalogue.example/api', $this->settings->getCatalogueApiEndpoint());
		$this->assertTrue($this->settings->getCatalogueEnabled());
	}

	public function testFailedArchivedFetchNeverDeletesExistingCurrentOrArchivedRecords(): void {
		$this->client->method('searchCourses')->willReturn(['courses' => [['id' => 42, 'title' => 'Physics']], 'more' => 0]);
		$this->client->method('getPastCourses')->willThrowException(new \RuntimeException('private upstream payload'));
		$this->embeddings->expects($this->never())->method('embedTexts');
		$this->mapper->expects($this->never())->method('upsert');
		$this->mapper->expects($this->never())->method('deleteStale');
		$this->settingsService->expects($this->never())->method('updateCatalogueIndexStats');

		$result = $this->service->reindex();
		$this->assertFalse($result['success']);
		$this->assertSame('failed', $this->status['state']);
		$this->assertStringNotContainsString('private upstream payload', $this->status['last_error']);
	}

	public function testIncompleteEmbeddingBatchRetainsPreviousIndexAndSuccessfulTimestamp(): void {
		$this->client->method('searchCourses')->willReturn(['courses' => [['id' => 42], ['id' => 43]], 'more' => 0]);
		$this->client->method('getPastCourses')->willReturn(['courses' => [], 'morePast' => 0]);
		$this->embeddings->method('getActiveModel')->willReturn('embedding-v2');
		$this->embeddings->method('embedTexts')->willReturn([[1.0, 0.0]]);
		$this->mapper->expects($this->never())->method('upsert');
		$this->mapper->expects($this->never())->method('deleteStale');
		$this->settingsService->expects($this->never())->method('updateCatalogueIndexStats');

		$result = $this->service->reindex();
		$this->assertFalse($result['success']);
		$this->assertSame(0, $result['count']);
		$this->assertStringContainsString('2 catalogue courses', $this->status['last_error']);
	}

	public function testDisablingDuringAnEmbeddingRequestPreventsIndexWritesAndCleanup(): void {
		$this->client->method('searchCourses')->willReturn(['courses' => [['id' => 42]], 'more' => 0]);
		$this->client->method('getPastCourses')->willReturn(['courses' => [], 'morePast' => 0]);
		$this->embeddings->method('getActiveModel')->willReturn('embedding-v2');
		$this->embeddings->method('embedTexts')->willReturnCallback(function (): array {
			$this->enabled = false;
			return [[1.0, 0.0]];
		});
		$this->mapper->expects($this->never())->method('upsert');
		$this->mapper->expects($this->never())->method('deleteStale');
		$this->settingsService->expects($this->never())->method('updateCatalogueIndexStats');

		$this->assertFalse($this->service->reindex()['success']);
		$this->assertSame('failed', $this->status['state']);
	}

	public function testPaginationLimitCannotPruneCoursesBeyondTheDownloadedSnapshot(): void {
		$this->client->expects($this->exactly(10))->method('searchCourses')
			->willReturnCallback(static fn (?string $query, array $filters, int $limit, int $offset): array => [
				'courses' => [['id' => $offset + 1]], 'more' => 1,
			]);
		$this->client->expects($this->never())->method('getPastCourses');
		$this->embeddings->expects($this->never())->method('embedTexts');
		$this->mapper->expects($this->never())->method('upsert');
		$this->mapper->expects($this->never())->method('deleteStale');

		$this->assertFalse($this->service->reindex()['success']);
		$this->assertSame('failed', $this->status['state']);
	}

	public function testLocalStatusReportsModelChangeAndStoredFailureWithoutNetworkRequests(): void {
		$this->settings->setCatalogueLastIndexed(time());
		$this->mapper->method('count')->willReturn(5);
		$this->embeddings->method('getActiveModel')->willReturn('new-model');
		$this->mapper->method('countCurrentByModel')->with('new-model')->willReturn(1);
		$this->mapper->method('countPastByModel')->with('new-model')->willReturn(0);
		$this->client->expects($this->never())->method('searchCourses');
		$this->client->expects($this->never())->method('getPastCourses');
		$this->embeddings->expects($this->never())->method('embedTexts');
		$this->status = ['state' => 'failed', 'last_error' => 'Please retry.', 'started_at' => 100, 'finished_at' => 101];

		$stats = $this->service->getStats();
		$this->assertSame(5, $stats['count']);
		$this->assertTrue($stats['needs_reindex']);
		$this->assertSame('failed', $stats['state']);
		$this->assertSame('Please retry.', $stats['last_error']);
		$this->assertSame(101, $stats['finished_at']);
	}

	public function testSearchUsesOnlyMatchingModelAndIgnoresWrongDimensionVectors(): void {
		$this->embeddings->method('getActiveModel')->willReturn('embedding-v2');
		$this->embeddings->method('embedTexts')->with(['physics'], 'embedding-v2')->willReturn([[1.0, 0.0]]);
		$matching = new CatalogueEmbedding();
		$matching->setCourseData('{"id":42,"title":"Physics"}');
		$matching->setEmbedding('[1.0,0.0]');
		$wrongDimension = new CatalogueEmbedding();
		$wrongDimension->setCourseData('{"id":99}');
		$wrongDimension->setEmbedding('[1.0]');
		$this->mapper->method('findAllCurrentByModel')->with('embedding-v2')->willReturn([$matching, $wrongDimension]);
		$this->mapper->expects($this->never())->method('findAllPastByModel');

		$result = $this->service->search('physics');
		$this->assertSame(1, $result['total']);
		$this->assertSame(42, $result['courses'][0]['id']);
	}

	public function testHistoricalSuccessfulIndexDoesNotAppearAsNeverIndexedAfterUpgrade(): void {
		$this->settings->setCatalogueLastIndexed(time());
		$this->mapper->method('count')->willReturn(1);
		$this->embeddings->method('getActiveModel')->willReturn('existing-model');
		$this->mapper->method('countCurrentByModel')->with('existing-model')->willReturn(1);
		$this->mapper->method('countPastByModel')->with('existing-model')->willReturn(0);

		$stats = $this->service->getStats();
		$this->assertSame('completed', $stats['state']);
		$this->assertFalse($stats['needs_reindex']);
		$this->assertSame([], $this->status);
	}
}
