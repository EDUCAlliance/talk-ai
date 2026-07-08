<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use Exception;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Background job to clean up orphaned RAG sources.
 * 
 * This job runs periodically to:
 * 1. Detect sources pointing to deleted files/folders
 * 2. Clean up their embeddings from the database
 * 3. Mark them as errors so users can remove them
 * 
 * Runs every 6 hours.
 */
class CleanupOrphanedSourcesJob extends TimedJob {
    private BotSourceMapper $botSourceMapper;
    private EmbeddingMapper $embeddingMapper;
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        BotSourceMapper $botSourceMapper,
        EmbeddingMapper $embeddingMapper,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->botSourceMapper = $botSourceMapper;
        $this->embeddingMapper = $embeddingMapper;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;

        // Run every 6 hours
        $this->setInterval(6 * 60 * 60);
    }

    protected function run($arguments): void {
        $this->logger->info('EducAI: Starting orphaned RAG sources cleanup');
        
        $cleanedCount = 0;
        $errorCount = 0;
        
        try {
            $sources = $this->botSourceMapper->findAll();
            
            foreach ($sources as $source) {
                try {
                    $this->checkAndCleanSource($source);
                } catch (Exception $e) {
                    $errorCount++;
                    $this->logger->warning('EducAI: Error checking source for cleanup', [
                        'sourceId' => $source->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            $this->logger->info('EducAI: Orphaned sources cleanup completed', [
                'cleaned' => $cleanedCount,
                'errors' => $errorCount,
            ]);
        } catch (Exception $e) {
            $this->logger->error('EducAI: Failed to run orphaned sources cleanup', [
                'exception' => $e,
            ]);
        }
    }

    private function checkAndCleanSource(BotSource $source): bool {
        // Skip sources already marked as error with "no longer exists" message
        if ($source->getStatus() === 'error' && 
            str_contains($source->getErrorMessage() ?? '', 'no longer exists')) {
            return false;
        }
        
        // Skip URL sources - they don't have a file to check
        // URL sources have node_type = 'url' and node_id = 0
        if ($source->getNodeType() === 'url') {
            return false;
        }
        
        $ownerUid = $source->getOwnerUid();
        if ($ownerUid === null || $ownerUid === '') {
            return false;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($ownerUid);
            $nodes = $userFolder->getById($source->getNodeId());
            
            // If nodes array is empty or node doesn't exist, the file was deleted
            if (!is_array($nodes) || count($nodes) === 0) {
                $this->cleanupDeletedSource($source);
                return true;
            }
        } catch (Exception $e) {
            // User might not exist anymore, or other filesystem issues
            // Mark as error but don't delete the source record
            $this->logger->info('EducAI: Cannot access source, marking as orphaned', [
                'sourceId' => $source->getId(),
                'nodeId' => $source->getNodeId(),
                'ownerUid' => $ownerUid,
                'error' => $e->getMessage(),
            ]);
            $this->cleanupDeletedSource($source);
            return true;
        }

        return false;
    }

    private function cleanupDeletedSource(BotSource $source): void {
        $embeddingCount = count($this->embeddingMapper->findBySource($source->getId()));
        
        // Delete all embeddings for this source
        $this->embeddingMapper->deleteBySource($source->getId());
        
        // Update source status
        $source->setStatus('error');
        $source->setErrorMessage('File or folder no longer exists. ' . $embeddingCount . ' embedding(s) cleaned up. You can remove this source.');
        $source->setChecksum(null);
        $source->setUpdatedAt(time());
        $source->setProgress(null);
        $source->setProgressCurrent(null);
        $source->setProgressTotal(null);
        $source->setProgressStage(null);
        $this->botSourceMapper->update($source);
        
        $this->logger->info('EducAI: Cleaned up orphaned source', [
            'sourceId' => $source->getId(),
            'nodeId' => $source->getNodeId(),
            'embeddingsDeleted' => $embeddingCount,
        ]);
    }
}

