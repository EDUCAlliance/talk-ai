<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Service\RagIngestionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class ReindexBotSourceJob extends QueuedJob {
    private RagIngestionService $ingestionService;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        RagIngestionService $ingestionService,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->ingestionService = $ingestionService;
        $this->logger = $logger;
    }

    /**
     * @param array<string,mixed> $arguments
     */
    public function run($arguments): void {
        $sourceId = $arguments['sourceId'] ?? null;
        $force = (bool)($arguments['force'] ?? false);
        if (!is_int($sourceId)) {
            $this->logger->warning('ReindexBotSourceJob received invalid source id', [
                'arguments' => $arguments,
            ]);
            return;
        }

        $attempt = is_int($arguments['attempt'] ?? null) ? max(0, $arguments['attempt']) : 0;

        try {
            $this->ingestionService->ingestSourceById($sourceId, $force, $attempt);
        } catch (DoesNotExistException $e) {
            // The source was removed before a scheduled retry ran.
            $this->logger->debug('ReindexBotSourceJob skipped a removed source', [
                'sourceId' => $sourceId,
            ]);
        }
    }
}
