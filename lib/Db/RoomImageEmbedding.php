<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getBotId()
 * @method void setBotId(int $botId)
 * @method string getRoomToken()
 * @method void setRoomToken(string $roomToken)
 * @method int getSourceId()
 * @method void setSourceId(int $sourceId)
 * @method string getChunkId()
 * @method void setChunkId(string $chunkId)
 * @method string getChunkText()
 * @method void setChunkText(string $chunkText)
 * @method string getEmbedding()
 * @method void setEmbedding(string $embedding)
 * @method ?string getEmbeddingModel()
 * @method void setEmbeddingModel(?string $embeddingModel)
 * @method ?int getTokenCount()
 * @method void setTokenCount(?int $tokenCount)
 * @method ?string getMetadata()
 * @method void setMetadata(?string $metadata)
 * @method ?string getScore()
 * @method void setScore(?string $score)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class RoomImageEmbedding extends Entity implements JsonSerializable {
	protected int $botId = 0;
	protected string $roomToken = '';
	protected int $sourceId = 0;
	protected string $chunkId = '';
	protected string $chunkText = '';
	protected string $embedding = '';
	protected ?string $embeddingModel = null;
	protected ?int $tokenCount = null;
	protected ?string $metadata = null;
	protected ?string $score = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('botId', 'integer');
		$this->addType('sourceId', 'integer');
		$this->addType('tokenCount', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'bot_id' => $this->botId,
			'room_token' => $this->roomToken,
			'source_id' => $this->sourceId,
			'chunk_id' => $this->chunkId,
			'chunk_text' => $this->chunkText,
			'embedding' => $this->embedding,
			'embedding_model' => $this->embeddingModel,
			'token_count' => $this->tokenCount,
			'metadata' => $this->metadata,
			'score' => $this->score,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}
}
