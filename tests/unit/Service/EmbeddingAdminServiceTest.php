<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCA\EducAI\Jobs\ReindexCatalogueEmbeddingsJob;
use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\EmbeddingAdminService;
use OCA\EducAI\Service\EmbeddingConfigurationService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmbeddingAdminServiceTest extends TestCase {
	private BotSourceMapper $sources;
	private RagIngestionService $ingestion;
	private SettingsService $settings;
	private CatalogueEmbeddingService $catalogue;
	private IJobList $jobs;
	private EmbeddingConfigurationService $configuration;
	private EmbeddingAdminService $service;

	protected function setUp(): void {
		$this->sources = $this->createMock(BotSourceMapper::class);
		$this->ingestion = $this->createMock(RagIngestionService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->catalogue = $this->createMock(CatalogueEmbeddingService::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->configuration = $this->createMock(EmbeddingConfigurationService::class);
		$this->service = new EmbeddingAdminService($this->sources, $this->ingestion, $this->settings, $this->catalogue, $this->jobs, $this->configuration, $this->createMock(LoggerInterface::class));
	}

	public function testCoreQueuesSourcesWithoutCatalogueOrNetworkWork(): void {
		$this->settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$this->catalogue->method('isEnabled')->willReturn(false);
		$this->catalogue->expects($this->never())->method('reindex');
		$this->sources->method('findAll')->willReturn([$this->source(11), $this->source(12)]);
		$queued = [];
		$this->ingestion->method('enqueueSource')->willReturnCallback(static function (int $id, bool $force) use (&$queued): void { $queued[$id] = $force; });
		$this->jobs->expects($this->never())->method('add');
		$this->configuration->expects($this->once())->method('markQueued')->with('rag');

		$result = $this->service->queueAll();

		$this->assertTrue($result['success']);
		$this->assertSame([11 => true, 12 => true], $queued);
		$this->assertSame(2, $result['queued_rag_sources']);
		$this->assertSame(0, $result['queued_catalogue_jobs']);
	}

	public function testDisabledRagKeepsSourcesUntouchedAndCatalogueQueuesAsynchronously(): void {
		$this->settings->method('getRagConfig')->willReturn(['rag_enabled' => false]);
		$this->catalogue->method('isEnabled')->willReturn(true);
		$this->sources->method('findAll')->willReturn([$this->source(11)]);
		$this->ingestion->expects($this->never())->method('enqueueSource');
		$this->catalogue->expects($this->never())->method('reindex');
		$this->jobs->expects($this->once())->method('add')->with(ReindexCatalogueEmbeddingsJob::class);
		$this->configuration->expects($this->once())->method('markQueued')->with('catalogue');

		$result = $this->service->queueAll();

		$this->assertSame(1, $result['skipped_rag_sources']);
		$this->assertSame(1, $result['queued_catalogue_jobs']);
		$this->assertSame(0, $result['queued_rag_sources']);
	}

	public function testDuplicateRequestsDoNotResetRunningSourcesOrHideConfigReminder(): void {
		$this->settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$this->catalogue->method('isEnabled')->willReturn(true);
		$this->sources->method('findAll')->willReturn([$this->source(11)]);
		$this->jobs->method('has')->willReturn(true);
		$this->ingestion->expects($this->never())->method('enqueueSource');
		$this->jobs->expects($this->never())->method('add');
		$this->configuration->expects($this->never())->method('markQueued');

		$result = $this->service->queueAll();

		$this->assertSame(1, $result['already_queued_rag_sources']);
		$this->assertSame(1, $result['already_queued_catalogue_jobs']);
		$this->assertSame(0, $result['queued_rag_sources']);
	}

	public function testPartialQueueFailureReportsAcceptedWorkAndContinuesOtherScopes(): void {
		$this->settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$this->catalogue->method('isEnabled')->willReturn(true);
		$this->sources->method('findAll')->willReturn([$this->source(11), $this->source(12)]);
		$this->ingestion->method('enqueueSource')->willReturnCallback(static function (int $id): void {
			if ($id === 11) throw new \RuntimeException('private provider detail');
		});
		$this->jobs->expects($this->once())->method('add')->with(ReindexCatalogueEmbeddingsJob::class);
		$this->configuration->expects($this->once())->method('markQueued')->with('catalogue');

		$result = $this->service->queueAll();

		$this->assertFalse($result['success']);
		$this->assertSame(1, $result['queued_rag_sources']);
		$this->assertSame(1, $result['failed_rag_sources']);
		$this->assertSame(1, $result['queued_catalogue_jobs']);
		$this->assertStringNotContainsString('private', json_encode($result));
	}

	public function testStatusSeparatesWaitingProcessingSuccessAndFailures(): void {
		$this->settings->method('getRagConfig')->willReturn(['rag_enabled' => true]);
		$this->catalogue->method('isEnabled')->willReturn(false);
		$this->catalogue->method('getStats')->willReturn(['count' => 7, 'last_indexed' => 123, 'needs_reindex' => false, 'state' => 'completed']);
		$processing = $this->source(12);
		$processing->setProgressStage('embedding');
		$ready = $this->source(13, 'ready');
		$ready->setLastIndexedAt(234);
		$error = $this->source(14, 'error');
		$error->setErrorMessage('private upstream response');
		$this->sources->method('findAll')->willReturn([$this->source(11), $processing, $ready, $error]);

		$status = $this->service->getStatus();

		$this->assertSame(4, $status['rag']['total']);
		foreach (['queued', 'processing', 'ready', 'error'] as $state) $this->assertSame(1, $status['rag'][$state]);
		$this->assertSame(234, $status['rag']['last_indexed']);
		$this->assertSame([['source_id' => 14, 'bot_id' => 3]], $status['rag']['errors']);
		$this->assertSame('disabled', $status['catalogue']['state']);
		$this->assertSame(7, $status['catalogue']['count']);
		$this->assertStringNotContainsString('private', json_encode($status));
	}

	public function testDisabledCatalogueCannotBeQueuedDirectly(): void {
		$this->catalogue->method('isEnabled')->willReturn(false);
		$this->jobs->expects($this->never())->method('add');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->queueCatalogue();
	}

	private function source(int $id, string $status = 'pending'): BotSource {
		$source = new BotSource();
		$source->setId($id);
		$source->setBotId(3);
		$source->setStatus($status);
		return $source;
	}
}
