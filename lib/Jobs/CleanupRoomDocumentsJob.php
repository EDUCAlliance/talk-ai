<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class CleanupRoomDocumentsJob extends TimedJob {
	private RoomDocumentIngestionService $roomDocumentIngestionService;
	private RoomImageIngestionService $roomImageIngestionService;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		RoomDocumentIngestionService $roomDocumentIngestionService,
		RoomImageIngestionService $roomImageIngestionService,
		LoggerInterface $logger
	) {
		parent::__construct($time);
		$this->roomDocumentIngestionService = $roomDocumentIngestionService;
		$this->roomImageIngestionService = $roomImageIngestionService;
		$this->logger = $logger;

		$this->setInterval(86400);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param array<string,mixed> $arguments
	 */
	protected function run($arguments): void {
		try {
			$deleted = $this->roomDocumentIngestionService->cleanupStaleDocuments();
			$deletedImages = $this->roomImageIngestionService->cleanupStaleImages();
			$this->logger->info('EducAI: Cleaned up stale room documents and images', [
				'deleted_documents' => $deleted,
				'deleted_images' => $deletedImages,
			]);
		} catch (\Throwable $e) {
			$this->logger->error('EducAI: Failed to cleanup room documents/images', [
				'exception' => $e,
			]);
		}
	}
}
