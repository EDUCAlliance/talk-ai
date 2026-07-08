<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Service\RagIngestionService;
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

        $this->ingestionService->ingestSourceById($sourceId, $force);
    }
}
