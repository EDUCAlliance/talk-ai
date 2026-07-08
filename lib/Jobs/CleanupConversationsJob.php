<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Db\ConversationMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that cleans up old conversation history.
 * 
 * Runs daily and removes conversations older than the retention period
 * to prevent unbounded database growth and reduce storage costs.
 */
class CleanupConversationsJob extends TimedJob {
    /**
     * Default retention period in days.
     * Conversations older than this will be deleted.
     */
    private const DEFAULT_RETENTION_DAYS = 30;

    private ConversationMapper $conversationMapper;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        ConversationMapper $conversationMapper,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->conversationMapper = $conversationMapper;
        $this->logger = $logger;

        // Run once per day (24 hours = 86400 seconds)
        $this->setInterval(86400);
        
        // Use random time-sensitivity to spread load across instances
        // This prevents all instances from running cleanup at the exact same time
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    protected function run($arguments): void {
        $retentionDays = $arguments['retention_days'] ?? self::DEFAULT_RETENTION_DAYS;
        
        if (!is_int($retentionDays) || $retentionDays < 1) {
            $retentionDays = self::DEFAULT_RETENTION_DAYS;
        }

        // Calculate cutoff timestamp
        $cutoffTimestamp = time() - ($retentionDays * 24 * 60 * 60);
        
        $this->logger->info('EducAI: Starting conversation cleanup job', [
            'retention_days' => $retentionDays,
            'cutoff_date' => date('Y-m-d H:i:s', $cutoffTimestamp),
        ]);

        try {
            $this->conversationMapper->deleteOlderThan($cutoffTimestamp);
            
            $this->logger->info('EducAI: Conversation cleanup completed successfully', [
                'cutoff_timestamp' => $cutoffTimestamp,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('EducAI: Conversation cleanup failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
