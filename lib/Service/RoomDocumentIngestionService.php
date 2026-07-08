<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\RoomDocumentEmbedding;
use OCA\EducAI\Db\RoomDocumentEmbeddingMapper;
use OCA\EducAI\Db\RoomDocumentSource;
use OCA\EducAI\Db\RoomDocumentSourceMapper;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

class RoomDocumentIngestionService {
	public const DEFAULT_RETENTION_DAYS = 30;

	private RoomDocumentSourceMapper $sourceMapper;
	private RoomDocumentEmbeddingMapper $embeddingMapper;
	private AttachmentResolver $attachmentResolver;
	private EmbeddingClient $embeddingClient;
	private DoclingClient $doclingClient;
	private SettingsService $settingsService;
	private LoggerInterface $logger;

	public function __construct(
		RoomDocumentSourceMapper $sourceMapper,
		RoomDocumentEmbeddingMapper $embeddingMapper,
		AttachmentResolver $attachmentResolver,
		EmbeddingClient $embeddingClient,
		DoclingClient $doclingClient,
		SettingsService $settingsService,
		LoggerInterface $logger
	) {
		$this->sourceMapper = $sourceMapper;
		$this->embeddingMapper = $embeddingMapper;
		$this->attachmentResolver = $attachmentResolver;
		$this->embeddingClient = $embeddingClient;
		$this->doclingClient = $doclingClient;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
	}

	public function isEnabled(): bool {
		$ragConfig = $this->settingsService->getRagConfig();
		$doclingConfig = $this->settingsService->getDoclingConfig();

		return (bool)$ragConfig['rag_enabled']
			&& !empty($ragConfig['embedding_model'])
			&& (bool)$doclingConfig['docling_enabled'];
	}

	/**
	 * @throws Exception
	 */
	public function ingestAttachment(
		int $botId,
		string $roomToken,
		string $actorId,
		int $messageId,
		IncomingTalkAttachment $attachment
	): RoomDocumentSource {
		if (!$attachment->isDocument()) {
			throw new Exception('Only document attachments can be ingested into room search');
		}
		if (!$this->isEnabled()) {
			throw new Exception('Room document ingestion is not enabled');
		}

		$file = $this->attachmentResolver->resolveSourceFile($attachment);
		if (!$file instanceof File) {
			throw new Exception('Unable to resolve source file for document attachment');
		}

		$existing = $this->findExistingSource($botId, $roomToken, $file->getId(), $attachment);
		$source = $existing ?? new RoomDocumentSource();
		$now = time();
		$source->setBotId($botId);
		$source->setRoomToken($roomToken);
		$source->setActorId($actorId);
		$source->setMessageId($messageId);
		$source->setNodeId($file->getId());
		$source->setAttachmentId($this->resolveAttachmentId($attachment));
		$source->setDisplayName($attachment->getDisplayName());
		$source->setMimeType($attachment->getMimeType() ?? $file->getMimeType());
		$source->setStatus('pending');
		$source->setErrorMessage(null);
		$source->setUpdatedAt($now);
		if ($existing === null) {
			$source->setCreatedAt($now);
			$source = $this->sourceMapper->insert($source);
		} else {
			$source = $this->sourceMapper->update($source);
		}

		$content = $this->extractText($file);
		if ($content === '') {
			throw new Exception('Unable to extract text from uploaded document');
		}

		$ragConfig = $this->settingsService->getRagConfig();
		$chunkSize = $ragConfig['rag_chunk_size'] ?? 750;
		if (!is_int($chunkSize) || $chunkSize <= 0) {
			$chunkSize = 750;
		}
		$chunkOverlap = $ragConfig['rag_chunk_overlap'] ?? 50;
		if (!is_int($chunkOverlap) || $chunkOverlap < 0) {
			$chunkOverlap = 50;
		}

		$chunks = $this->chunkText($content, $chunkSize, $chunkOverlap);
		if ($chunks === []) {
			throw new Exception('Unable to create document chunks for room ingestion');
		}

		$checksum = sha1($content);
		if ($source->getChecksum() !== null && $source->getChecksum() === $checksum) {
			$source->setStatus('ready');
			$source->setLastIndexedAt($now);
			$source->setUpdatedAt($now);
			return $this->sourceMapper->update($source);
		}

		$embeddingModel = $this->embeddingClient->getActiveModel();
		$vectors = $this->embeddingClient->embedTexts(array_values($chunks), $embeddingModel);

		$this->embeddingMapper->deleteBySource($source->getId());
		foreach ($vectors as $index => $vector) {
			$chunkText = $chunks[$index] ?? null;
			if (!is_string($chunkText) || $chunkText === '') {
				continue;
			}

			$embedding = new RoomDocumentEmbedding();
			$embedding->setBotId($botId);
			$embedding->setRoomToken($roomToken);
			$embedding->setSourceId($source->getId());
			$embedding->setChunkId($source->getId() . ':' . $index);
			$embedding->setChunkText($chunkText);
			$embedding->setEmbedding(json_encode($vector) ?: '[]');
			$embedding->setEmbeddingModel($embeddingModel);
			$embedding->setTokenCount(strlen($chunkText));
			$embedding->setMetadata(json_encode([
				'display_name' => $source->getDisplayName(),
				'mime_type' => $source->getMimeType(),
				'node_id' => $source->getNodeId(),
				'chunk_index' => $index,
			]) ?: '{}');
			$embedding->setScore(null);
			$embedding->setCreatedAt($now);
			$embedding->setUpdatedAt($now);
			$this->embeddingMapper->insert($embedding);
		}

		$source->setChecksum($checksum);
		$source->setStatus('ready');
		$source->setLastIndexedAt($now);
		$source->setUpdatedAt($now);

		return $this->sourceMapper->update($source);
	}

	public function deleteRoomDocuments(int $botId, string $roomToken): void {
		$this->embeddingMapper->deleteByBotAndRoom($botId, $roomToken);
		$this->sourceMapper->deleteByBotAndRoom($botId, $roomToken);
	}

	public function deleteBotDocuments(int $botId): void {
		$this->embeddingMapper->deleteByBot($botId);
		$this->sourceMapper->deleteByBot($botId);
	}

	public function cleanupStaleDocuments(int $retentionDays = self::DEFAULT_RETENTION_DAYS): int {
		$cutoff = time() - max(1, $retentionDays) * 86400;
		$sources = $this->sourceMapper->findOlderThan($cutoff);
		$deleted = 0;

		foreach ($sources as $source) {
			$this->embeddingMapper->deleteBySource($source->getId());
			$this->sourceMapper->deleteById($source->getId());
			$deleted++;
		}

		return $deleted;
	}

	private function findExistingSource(int $botId, string $roomToken, int $nodeId, IncomingTalkAttachment $attachment): ?RoomDocumentSource {
		$existing = $this->sourceMapper->findOneByBotRoomAndNode($botId, $roomToken, $nodeId);
		if ($existing instanceof RoomDocumentSource) {
			return $existing;
		}

		$attachmentId = $this->resolveAttachmentId($attachment);
		if ($attachmentId !== null && $attachmentId !== '') {
			return $this->sourceMapper->findOneByBotRoomAndAttachment($botId, $roomToken, $attachmentId);
		}

		return null;
	}

	private function resolveAttachmentId(IncomingTalkAttachment $attachment): ?string {
		$fileRef = $attachment->getFileRef();
		$candidates = [
			$fileRef['fileId'] ?? null,
			$fileRef['file_id'] ?? null,
			$fileRef['id'] ?? null,
		];

		foreach ($candidates as $candidate) {
			if (is_scalar($candidate)) {
				return (string)$candidate;
			}
		}

		return null;
	}

	private function extractText(File $file): string {
		$mime = $file->getMimeType();
		if ($this->doclingClient->isEnabled() && $this->doclingClient->isSupported($file)) {
			try {
				return mb_convert_encoding($this->doclingClient->convertToMarkdown($file), 'UTF-8', 'UTF-8');
			} catch (Exception $e) {
				$this->logger->warning('Room document Docling conversion failed, falling back to plain text', [
					'file' => $file->getName(),
					'exception' => $e,
				]);
			}
		}

		if (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml'], true)) {
			return mb_convert_encoding((string)$file->getContent(), 'UTF-8', 'UTF-8');
		}

		return '';
	}

	/**
	 * @return array<int,string>
	 */
	private function chunkText(string $text, int $chunkSize, int $overlap): array {
		$chunks = [];
		$length = mb_strlen($text, 'UTF-8');
		$offset = 0;
		while ($offset < $length) {
			$chunk = mb_substr($text, $offset, $chunkSize, 'UTF-8');
			if ($chunk === '') {
				break;
			}
			$chunks[] = $chunk;
			$offset += max(1, $chunkSize - $overlap);
		}

		return $chunks;
	}
}
