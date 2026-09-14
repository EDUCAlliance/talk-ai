<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\EmbeddingConfigurationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job to refresh catalogue course embeddings periodically
 */
class RefreshCatalogueEmbeddingsJob extends TimedJob {
	private CatalogueEmbeddingService $catalogueEmbeddingService;
	private IJobList $jobList;
	private LoggerInterface $logger;
	private EmbeddingConfigurationService $configuration;

	public function __construct(
		ITimeFactory $time,
		CatalogueEmbeddingService $catalogueEmbeddingService,
		IJobList $jobList,
		LoggerInterface $logger,
		EmbeddingConfigurationService $configuration,
	) {
		parent::__construct($time);

		// Run every hour, but the job will check if reindex is actually needed
		$this->setInterval(3600);

		$this->catalogueEmbeddingService = $catalogueEmbeddingService;
		$this->jobList = $jobList;
		$this->logger = $logger;
		$this->configuration = $configuration;
	}

	/**
	 * @param array<string,mixed> $arguments
	 */
	protected function run($arguments): void {
		// Check if catalogue is enabled
		if (!$this->catalogueEmbeddingService->isEnabled()) {
			$this->logger->debug('RefreshCatalogueEmbeddingsJob: Catalogue not enabled, skipping');
			return;
		}

		// Check if reindex is needed based on interval
		$stats = $this->catalogueEmbeddingService->getStats();
		if (!$stats['needs_reindex'] && !$this->configuration->isRequired('catalogue')) {
			$this->logger->debug('RefreshCatalogueEmbeddingsJob: Reindex not needed yet', [
				'last_indexed' => $stats['last_indexed'],
				'count' => $stats['count'],
			]);
			return;
		}

		// The same queue identity is used by manual reindexing, coalescing clicks
		// and scheduled work while keeping slow network calls out of this check.
		if (!$this->jobList->has(ReindexCatalogueEmbeddingsJob::class, null)) {
			$this->jobList->add(ReindexCatalogueEmbeddingsJob::class);
			$this->configuration->markQueued('catalogue');
		}
	}
}
