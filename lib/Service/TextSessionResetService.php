<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

class TextSessionResetService {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function resetFileSession(File $file): void {
		try {
			if (!class_exists(\OCA\Text\Service\DocumentService::class)
				|| !class_exists(\OCP\Server::class)
				|| !method_exists(\OCP\Server::class, 'get')) {
				return;
			}

			$documentService = \OCP\Server::get(\OCA\Text\Service\DocumentService::class);
			if (!is_object($documentService) || !method_exists($documentService, 'resetDocument')) {
				return;
			}

			$documentService->resetDocument((int)$file->getId(), true);
		} catch (\Throwable $e) {
			$this->logger->debug('Unable to reset Text document session after wiki write', [
				'exception' => $e,
				'file_id' => $file->getId(),
			]);
		}
	}
}
