<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCA\EducAI\Jobs\ReindexCatalogueEmbeddingsJob;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/** Central administration for knowledge sources, with Catalogue as an optional scope. */
class EmbeddingAdminService {
	public function __construct(
		private BotSourceMapper $sourceMapper,
		private RagIngestionService $ingestion,
		private SettingsService $settings,
		private CatalogueEmbeddingService $catalogue,
		private IJobList $jobs,
		private EmbeddingConfigurationService $configuration,
		private LoggerInterface $logger,
	) {
	}

	public function getStatus(): array {
		$counts = ['total' => 0, 'queued' => 0, 'processing' => 0, 'ready' => 0, 'error' => 0];
		$errors = [];
		$lastIndexed = null;
		foreach ($this->sourceMapper->findAll() as $source) {
			$counts['total']++;
			$status = $source->getStatus();
			if ($status === 'pending') {
				$status = $source->getProgressStage() ? 'processing' : 'queued';
			}
			if (array_key_exists($status, $counts) && $status !== 'total') {
				$counts[$status]++;
			}
			if ($status === 'error' && count($errors) < 50) {
				// Do not expose provider errors, URLs or credentials in this overview.
				$errors[] = ['source_id' => $source->getId(), 'bot_id' => $source->getBotId()];
			}
			if ($source->getLastIndexedAt() !== null) {
				$lastIndexed = max($lastIndexed ?? 0, $source->getLastIndexedAt());
			}
		}
		$ragEnabled = (bool)$this->settings->getRagConfig()['rag_enabled'];
		return [
			'rag' => $counts + [
				'enabled' => $ragEnabled,
				'needs_reindex' => $this->configuration->isRequired('rag'),
				'last_indexed' => $lastIndexed,
				'errors' => $errors,
			],
			'catalogue' => $this->getCatalogueStatus(),
		];
	}

	public function getCatalogueStatus(): array {
		$enabled = $this->catalogue->isEnabled();
		$stats = $this->catalogue->getStats();
		$queued = $this->jobs->has(ReindexCatalogueEmbeddingsJob::class, null);
		$stats['enabled'] = $enabled;
		$stats['queued'] = $enabled && $queued;
		$stats['needs_reindex'] = $stats['needs_reindex'] || $this->configuration->isRequired('catalogue');
		if (!$enabled) {
			$stats['state'] = 'disabled';
		} elseif ($queued && ($stats['state'] ?? '') !== 'running') {
			$stats['state'] = 'queued';
		}
		return $stats;
	}

	public function queueCatalogue(): bool {
		if (!$this->catalogue->isEnabled()) {
			throw new \InvalidArgumentException('Catalogue integration is not enabled');
		}
		if ($this->jobs->has(ReindexCatalogueEmbeddingsJob::class, null)) {
			return false;
		}
		$this->jobs->add(ReindexCatalogueEmbeddingsJob::class);
		$this->configuration->markQueued('catalogue');
		return true;
	}

	public function queueAll(): array {
		$result = [
			'success' => true,
			'queued_rag_sources' => 0,
			'already_queued_rag_sources' => 0,
			'skipped_rag_sources' => 0,
			'failed_rag_sources' => 0,
			'queued_catalogue_jobs' => 0,
			'already_queued_catalogue_jobs' => 0,
			'failed_catalogue_jobs' => 0,
		];
		$ragEnabled = (bool)$this->settings->getRagConfig()['rag_enabled'];
		foreach ($this->sourceMapper->findAll() as $source) {
			if (!$ragEnabled) {
				$result['skipped_rag_sources']++;
				continue;
			}
			try {
				$id = $source->getId();
				if ($this->jobs->has(ReindexBotSourceJob::class, ['sourceId' => $id, 'force' => true])
					|| $this->jobs->has(ReindexBotSourceJob::class, ['sourceId' => $id, 'force' => false])) {
					$result['already_queued_rag_sources']++;
					continue;
				}
				$this->ingestion->enqueueSource($id, true);
				$result['queued_rag_sources']++;
			} catch (\Exception $e) {
				$result['failed_rag_sources']++;
				$this->logger->error('Failed to queue source reindex', ['sourceId' => $source->getId(), 'exception' => $e]);
			}
		}
		// Existing jobs may have been queued with the old configuration; keep the reminder.
		if ($ragEnabled && $result['failed_rag_sources'] === 0 && $result['already_queued_rag_sources'] === 0) {
			$this->configuration->markQueued('rag');
		}
		if ($this->catalogue->isEnabled()) {
			try {
				$result[$this->queueCatalogue() ? 'queued_catalogue_jobs' : 'already_queued_catalogue_jobs'] = 1;
			} catch (\Exception $e) {
				$result['failed_catalogue_jobs'] = 1;
				$this->logger->error('Failed to queue Catalogue reindex', ['exception' => $e]);
			}
		}
		$result['success'] = $result['failed_rag_sources'] === 0 && $result['failed_catalogue_jobs'] === 0;
		return $result;
	}
}
