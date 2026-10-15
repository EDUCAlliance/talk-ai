<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use Exception;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\RagSourceLocator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that checks whether file and folder knowledge sources are still
 * reachable for their owner.
 *
 * Unreachable sources are marked "unavailable": their embeddings are kept but no
 * longer used for answers. This includes sources that are queued or waiting for a
 * retry. When such a source becomes reachable again, it is reindexed. Lookup failures
 * (storage, database) change nothing. Embeddings are only deleted when the owner
 * removes the source. Sources left "pending" by an interrupted indexing run are
 * marked as failed so their owner can start them again.
 */
class CleanupOrphanedSourcesJob extends TimedJob {
    /** Twice the running lease of an indexing run. */
    private const INTERRUPTED_AFTER_SECONDS = 2 * RagIngestionService::RUNNING_LEASE_SECONDS;

    private BotSourceMapper $botSourceMapper;
    private RagSourceLocator $sourceLocator;
    private IJobList $jobList;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        BotSourceMapper $botSourceMapper,
        RagSourceLocator $sourceLocator,
        IJobList $jobList,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->botSourceMapper = $botSourceMapper;
        $this->sourceLocator = $sourceLocator;
        $this->jobList = $jobList;
        $this->logger = $logger;

        // Run every 6 hours
        $this->setInterval(6 * 60 * 60);
    }

    protected function run($arguments): void {
        $this->logger->info('EducAI: Starting knowledge source reachability check');

        $changedCount = 0;
        $errorCount = 0;

        try {
            $sources = $this->botSourceMapper->findAll();
            $queued = $this->queuedSourceIds();

            foreach ($sources as $source) {
                try {
                    if ($this->checkSource($source, isset($queued[$source->getId()]))) {
                        $changedCount++;
                    }
                } catch (Exception $e) {
                    $errorCount++;
                    $this->logger->warning('EducAI: Error checking knowledge source reachability', [
                        'sourceId' => $source->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('EducAI: Knowledge source reachability check completed', [
                'changed' => $changedCount,
                'errors' => $errorCount,
            ]);
        } catch (Exception $e) {
            $this->logger->error('EducAI: Failed to run knowledge source reachability check', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return array<int,true> Sources with a queued or scheduled indexing job
     */
    private function queuedSourceIds(): array {
        $ids = [];
        foreach ($this->jobList->getJobsIterator(ReindexBotSourceJob::class, null, 0) as $job) {
            $argument = $job->getArgument();
            if (is_array($argument) && is_int($argument['sourceId'] ?? null)) {
                $ids[$argument['sourceId']] = true;
            }
        }

        return $ids;
    }

    /**
     * @return bool Whether the source was changed or queued for reindexing
     */
    private function checkSource(BotSource $source, bool $queued): bool {
        // Rows cleaned up by the earlier deletion policy have no embeddings left.
        if ($source->getStatus() === KnowledgeSourceStatus::ERROR
            && str_contains($source->getErrorMessage() ?? '', 'no longer exists')) {
            return false;
        }

        $now = time();
        if ($source->getStatus() === KnowledgeSourceStatus::PENDING && !$queued
            && !RagIngestionService::isRunning($source, $now)
            && $source->getUpdatedAt() < $now - self::INTERRUPTED_AFTER_SECONDS) {
            // No job will finish it (worker killed, server restart): let the owner start it again.
            $source->setStatus(KnowledgeSourceStatus::ERROR);
            $source->setErrorMessage(KnowledgeSourceStatus::encode(KnowledgeSourceStatus::INDEXING_INTERRUPTED, [], 'No job finished the indexing run'));
            $source->setProgressStage(null);
            $source->setUpdatedAt($now);
            $this->botSourceMapper->update($source);
            $this->logger->warning('EducAI: Knowledge source indexing was interrupted; marked as failed', ['sourceId' => $source->getId()]);
            return true;
        }

        // URL sources have no file to check.
        if ($source->getNodeType() === 'url' || $source->getOwnerUid() === '') {
            return false;
        }

        $location = $this->sourceLocator->locate($source);
        $context = [
            'sourceId' => $source->getId(),
            'nodeId' => $source->getNodeId(),
            'state' => $location['state'],
            'exception_class' => $location['exception'] !== null ? get_class($location['exception']) : null,
        ];

        if ($location['state'] === RagSourceLocator::FOUND) {
            if ($source->getStatus() !== KnowledgeSourceStatus::UNAVAILABLE || $queued || RagIngestionService::isRunning($source, $now)) {
                return false;
            }
            // Reachable again: reindexing is cheap when the content did not change.
            $this->jobList->add(ReindexBotSourceJob::class, ['sourceId' => $source->getId(), 'force' => false]);
            $this->logger->info('EducAI: Knowledge source is reachable again, reindexing', $context);
            return true;
        }

        if ($location['state'] === RagSourceLocator::MISSING || $location['state'] === RagSourceLocator::DENIED) {
            if ($source->getStatus() === KnowledgeSourceStatus::UNAVAILABLE) {
                return false;
            }
            // Also while a retry waits: its old index must not answer until it is found again.
            $source->setStatus(KnowledgeSourceStatus::UNAVAILABLE);
            $source->setErrorMessage(KnowledgeSourceStatus::encode(
                $location['state'] === RagSourceLocator::DENIED
                    ? KnowledgeSourceStatus::SOURCE_ACCESS_DENIED
                    : KnowledgeSourceStatus::SOURCE_MISSING,
                [],
                'File or folder is not reachable for its owner (' . $location['state'] . ')'
            ));
            $source->setUpdatedAt(time());
            $this->botSourceMapper->update($source);
            $this->logger->info('EducAI: Knowledge source is not reachable for its owner; excluding it from answers', $context);
            return true;
        }

        // Storage or database failure: keep the source and its index as they are.
        $this->logger->info('EducAI: Knowledge source lookup failed temporarily; leaving it unchanged', $context);
        return false;
    }
}
