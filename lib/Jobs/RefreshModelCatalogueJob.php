<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ModelReferenceMigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Refresh discovery only; never send a paid inference request from cron. */
class RefreshModelCatalogueJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private LLMClient $llmClient,
		private ModelReferenceMigrationService $migration,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($arguments): void {
		try {
			// Respect per-provider TTL and retry cooldowns, even after a cron delay.
			$this->llmClient->getModelCatalogue();
			$this->migration->migrate();
		} catch (\Throwable $e) {
			$this->logger->warning('Automatic model catalogue refresh failed');
		}
	}
}
