<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class ReindexCatalogueEmbeddingsJob extends QueuedJob {
	private CatalogueEmbeddingService $catalogueEmbeddingService;
	private LoggerInterface $logger;
	private IJobList $jobList;

	public function __construct(
		ITimeFactory $time,
		CatalogueEmbeddingService $catalogueEmbeddingService,
		LoggerInterface $logger,
		IJobList $jobList,
	) {
		parent::__construct($time);
		$this->catalogueEmbeddingService = $catalogueEmbeddingService;
		$this->logger = $logger;
		$this->jobList = $jobList;
	}

	/**
	 * @param array<string,mixed> $arguments
	 */
	public function run($arguments): void {
		if (!$this->catalogueEmbeddingService->isEnabled()) {
			$this->logger->info('ReindexCatalogueEmbeddingsJob skipped: catalogue integration disabled');
			return;
		}

		$this->logger->info('ReindexCatalogueEmbeddingsJob: starting manual reindex');
		$result = $this->catalogueEmbeddingService->reindex();
		if (!$result['success']) {
			$this->logger->warning('ReindexCatalogueEmbeddingsJob: reindex finished with warnings', $result);
		}
		// Continue after the pause the provider asked for; earlier runs would only be refused again.
		if (isset($result['retry_at']) && !$this->jobList->has(self::class, null)) {
			$this->jobList->scheduleAfter(self::class, $result['retry_at']);
		}
	}
}
