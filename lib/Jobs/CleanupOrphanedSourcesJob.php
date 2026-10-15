<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use Exception;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Service\KnowledgeSourceStatus;
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
 * longer used for answers. When such a source becomes reachable again, it is
 * reindexed. Lookup failures (storage, database) change nothing. Embeddings are
 * only deleted when the owner removes the source.
 */
class CleanupOrphanedSourcesJob extends TimedJob {
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

            foreach ($sources as $source) {
                try {
                    if ($this->checkSource($source)) {
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
     * @return bool Whether the source was marked unavailable or queued for reindexing
     */
    private function checkSource(BotSource $source): bool {
        // Rows cleaned up by the earlier deletion policy have no embeddings left.
        if ($source->getStatus() === KnowledgeSourceStatus::ERROR
            && str_contains($source->getErrorMessage() ?? '', 'no longer exists')) {
            return false;
        }

        // URL sources have no file to check, and sources being indexed are handled by their job.
        if ($source->getNodeType() === 'url' || $source->getStatus() === KnowledgeSourceStatus::PENDING) {
            return false;
        }
        if ($source->getOwnerUid() === '') {
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
            if ($source->getStatus() !== KnowledgeSourceStatus::UNAVAILABLE) {
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
