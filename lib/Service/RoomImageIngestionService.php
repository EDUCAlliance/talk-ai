<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\RoomImageEmbedding;
use OCA\EducAI\Db\RoomImageEmbeddingMapper;
use OCA\EducAI\Db\RoomImageSource;
use OCA\EducAI\Db\RoomImageSourceMapper;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

class RoomImageIngestionService {
	public const DEFAULT_RETENTION_DAYS = RoomDocumentIngestionService::DEFAULT_RETENTION_DAYS;

	private RoomImageSourceMapper $sourceMapper;
	private RoomImageEmbeddingMapper $embeddingMapper;
	private AttachmentResolver $attachmentResolver;
	private VisionClient $visionClient;
	private EmbeddingClient $embeddingClient;
	private SettingsService $settingsService;
	private LoggerInterface $logger;

	public function __construct(
		RoomImageSourceMapper $sourceMapper,
		RoomImageEmbeddingMapper $embeddingMapper,
		AttachmentResolver $attachmentResolver,
		VisionClient $visionClient,
		EmbeddingClient $embeddingClient,
		SettingsService $settingsService,
		LoggerInterface $logger,
	) {
		$this->sourceMapper = $sourceMapper;
		$this->embeddingMapper = $embeddingMapper;
		$this->attachmentResolver = $attachmentResolver;
		$this->visionClient = $visionClient;
		$this->embeddingClient = $embeddingClient;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
	}

	public function isEnabled(): bool {
		$ragConfig = $this->settingsService->getRagConfig();
		return $this->visionClient->isEnabled()
			&& (bool)$ragConfig['rag_enabled']
			&& !empty($ragConfig['embedding_model']);
	}

	/**
	 * @throws Exception
	 */
	public function ingestAttachment(
		int $botId,
		string $roomToken,
		string $actorId,
		int $messageId,
		IncomingTalkAttachment $attachment,
	): RoomImageSource {
		if (!$attachment->isImage()) {
			throw new Exception('Only image attachments can be ingested into room image search');
		}
		if (!$this->isEnabled()) {
			throw new Exception('Room image ingestion is not enabled');
		}

		$file = $this->attachmentResolver->resolveSourceFile($attachment);
		if (!$file instanceof File) {
			throw new Exception('Unable to resolve source file for image attachment');
		}

		$content = (string)$file->getContent();
		if ($content === '') {
			throw new Exception('Unable to read image attachment content');
		}

		$existing = $this->findExistingSource($botId, $roomToken, $file->getId(), $attachment);
		$source = $existing ?? new RoomImageSource();
		$now = time();
		$checksum = sha1($content);
		$visionModel = $this->getVisionModel();

		$source->setBotId($botId);
		$source->setRoomToken($roomToken);
		$source->setActorId($actorId);
		$source->setMessageId($messageId);
		$source->setNodeId($file->getId());
		$source->setAttachmentId($this->resolveAttachmentId($attachment));
		$source->setDisplayName($attachment->getDisplayName());
		$source->setMimeType($attachment->getMimeType() ?? $file->getMimeType());
		$source->setVisionModel($visionModel);
		$source->setStatus('pending');
		$source->setErrorMessage(null);
		$source->setUpdatedAt($now);

		if ($existing === null) {
			$source->setCreatedAt($now);
			$source = $this->sourceMapper->insert($source);
		} else {
			$source = $this->sourceMapper->update($source);
		}

		if (
			$source->getChecksum() === $checksum
			&& $source->getAnalysisText() !== null
			&& trim($source->getAnalysisText()) !== ''
		) {
			$this->ensureEmbedding($source, $source->getAnalysisText(), $now, false);
			$source->setStatus('ready');
			$source->setLastIndexedAt($now);
			$source->setUpdatedAt($now);
			return $this->sourceMapper->update($source);
		}

		try {
			$resolved = $this->attachmentResolver->resolveToTempFile($attachment);
			try {
				$analysis = $this->visionClient->analyzeImage(
					$resolved->getTempPath(),
					$resolved->getDisplayName(),
					$this->buildVisionPrompt($attachment)
				);
			} finally {
				$resolved->cleanup();
			}

			if (trim($analysis) === '') {
				throw new Exception('Vision analysis returned no content');
			}

			$source->setAnalysisText($analysis);
			$source->setChecksum($checksum);
			$source->setVisionModel($visionModel);
			$this->ensureEmbedding($source, $analysis, $now, true);
			$source->setStatus('ready');
			$source->setLastIndexedAt($now);
			$source->setUpdatedAt($now);

			return $this->sourceMapper->update($source);
		} catch (Exception $e) {
			$source->setStatus('error');
			$source->setErrorMessage($e->getMessage());
			$source->setUpdatedAt($now);
			$this->sourceMapper->update($source);
			throw $e;
		}
	}

	public function deleteRoomImages(int $botId, string $roomToken): void {
		$this->embeddingMapper->deleteByBotAndRoom($botId, $roomToken);
		$this->sourceMapper->deleteByBotAndRoom($botId, $roomToken);
	}

	public function deleteBotImages(int $botId): void {
		$this->embeddingMapper->deleteByBot($botId);
		$this->sourceMapper->deleteByBot($botId);
	}

	public function cleanupStaleImages(int $retentionDays = self::DEFAULT_RETENTION_DAYS): int {
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

	private function findExistingSource(int $botId, string $roomToken, int $nodeId, IncomingTalkAttachment $attachment): ?RoomImageSource {
		$existing = $this->sourceMapper->findOneByBotRoomAndNode($botId, $roomToken, $nodeId);
		if ($existing instanceof RoomImageSource) {
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

	private function getVisionModel(): ?string {
		$config = $this->settingsService->getVisionConfig();
		$model = $config['model'] ?? null;
		return is_string($model) && trim($model) !== '' ? trim($model) : null;
	}

	private function buildVisionPrompt(IncomingTalkAttachment $attachment): string {
		return 'Analyze this Talk room image for future retrieval. '
			. 'Describe the visible UI, text, errors, objects, layout, and any facts needed to answer later questions. '
			. 'Include concise OCR-style text when readable. '
			. 'Do not invent hidden details. Filename: ' . $attachment->getDisplayName();
	}

	private function ensureEmbedding(RoomImageSource $source, string $analysis, int $now, bool $rebuild): void {
		if (!$rebuild && $this->embeddingMapper->findBySource($source->getId()) !== []) {
			return;
		}

		$embeddingModel = $this->embeddingClient->getActiveModel();
		$vectors = $this->embeddingClient->embedTexts([$analysis], $embeddingModel);
		$vector = $vectors[0] ?? null;
		if (!is_array($vector)) {
			throw new Exception('Failed to generate embedding for image analysis');
		}

		$this->embeddingMapper->deleteBySource($source->getId());
		$embedding = new RoomImageEmbedding();
		$embedding->setBotId($source->getBotId());
		$embedding->setRoomToken($source->getRoomToken());
		$embedding->setSourceId($source->getId());
		$embedding->setChunkId($source->getId() . ':0');
		$embedding->setChunkText($analysis);
		$embedding->setEmbedding(json_encode($vector) ?: '[]');
		$embedding->setEmbeddingModel($embeddingModel);
		$embedding->setTokenCount(strlen($analysis));
		$embedding->setMetadata(json_encode([
			'display_name' => $source->getDisplayName(),
			'mime_type' => $source->getMimeType(),
			'node_id' => $source->getNodeId(),
			'message_id' => $source->getMessageId(),
			'actor_id' => $source->getActorId(),
			'created_at' => $source->getCreatedAt(),
			'vision_model' => $source->getVisionModel(),
		]) ?: '{}');
		$embedding->setScore(null);
		$embedding->setCreatedAt($now);
		$embedding->setUpdatedAt($now);
		$this->embeddingMapper->insert($embedding);
	}
}
