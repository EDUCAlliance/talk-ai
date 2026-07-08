<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Service\WikiRootRegistryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

class RebuildWikiRootRegistryJob extends QueuedJob {
	private WikiRootRegistryService $registryService;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		WikiRootRegistryService $registryService,
		LoggerInterface $logger
	) {
		parent::__construct($time);
		$this->registryService = $registryService;
		$this->logger = $logger;
	}

	public function run($arguments): void {
		try {
			$result = $this->registryService->rebuildAll();
			$this->logger->info('Rebuilt wiki root registry', [
				'refreshed' => $result['refreshed'],
				'failed' => $result['failed'],
				'arguments' => $arguments,
			]);
		} catch (Throwable $e) {
			$this->logger->warning('Failed to rebuild wiki root registry', [
				'arguments' => $arguments,
				'exception' => $e,
			]);
		}
	}
}
