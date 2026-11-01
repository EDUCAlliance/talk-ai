<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Jobs\ReindexCatalogueEmbeddingsJob;
use OCP\AppFramework\Db\DoesNotExistException;
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
		private BotMapper $botMapper,
		private KnowledgeSourcePresenter $presenter,
	) {
	}

	/**
	 * Names the bot and the file, folder or page and says what went wrong. This overview is
	 * admin-only, so the stored technical detail is included with credentials masked.
	 *
	 * @param array<int,?Bot> $bots lookup cache, keyed by bot id
	 * @return array<string,mixed>
	 */
	private function describeFailedSource(BotSource $source, array &$bots): array {
		$botId = $source->getBotId();
		if (!array_key_exists($botId, $bots)) {
			try {
				$bots[$botId] = $this->botMapper->findById($botId);
			} catch (DoesNotExistException) {
				$bots[$botId] = null;
			}
		}
		$bot = $bots[$botId];
		$status = $this->presenter->describeStatus($source);

		return [
			'source_id' => $source->getId(),
			'bot_id' => $botId,
			'bot_name' => $bot?->getBotName(),
			'bot_mention' => $bot?->getMentionName(),
			'owner_uid' => $source->getOwnerUid(),
			'source_type' => $source->getNodeType(),
			'status' => $source->getStatus(),
			'error_code' => $status['source_error_code'],
			'error_message' => $status['error_message'],
			'detail' => $this->presenter->technicalDetail($source),
		] + $this->presenter->describeLocation($source);
	}

	public function getStatus(): array {
		$counts = ['total' => 0, 'queued' => 0, 'processing' => 0, 'ready' => 0, 'error' => 0];
		$errors = [];
		/** @var array<int,?Bot> $bots */
		$bots = [];
		$lastIndexed = null;
		$oldestIndexed = null;
		foreach ($this->sourceMapper->findAll() as $source) {
			$counts['total']++;
			$status = $source->getStatus();
			if ($status === KnowledgeSourceStatus::PENDING) {
				$stage = $source->getProgressStage();
				$status = $stage && $stage !== KnowledgeSourceStatus::STAGE_WAITING ? 'processing' : 'queued';
			} elseif ($status === KnowledgeSourceStatus::UNAVAILABLE) {
				// Not used for answers until the owner can reach it again: a problem to fix.
				$status = 'error';
			}
			if (array_key_exists($status, $counts) && $status !== 'total') {
				$counts[$status]++;
			}
			if ($status === 'error' && count($errors) < 50) {
				$errors[] = $this->describeFailedSource($source, $bots);
			}
			if ($source->getLastIndexedAt() !== null) {
				$lastIndexed = max($lastIndexed ?? 0, $source->getLastIndexedAt());
				$oldestIndexed = min($oldestIndexed ?? PHP_INT_MAX, $source->getLastIndexedAt());
			}
		}
		$ragEnabled = (bool)$this->settings->getRagConfig()['rag_enabled'];
		$changedAt = $this->configuration->requiredSince('rag');
		return [
			'rag' => $counts + [
				'enabled' => $ragEnabled,
				'needs_reindex' => $changedAt !== null && $oldestIndexed !== null && $oldestIndexed <= $changedAt,
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
		$stats['source_unavailable_reason'] = $enabled ? null : $this->catalogue->sourceUnavailableReason();
		if (!$enabled) {
			$stats['state'] = $stats['source_unavailable_reason'] !== null ? 'source_unavailable' : 'disabled';
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
				// Includes retries scheduled for later and a run in progress.
				if ($this->ingestion->isBusy($source)) {
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
