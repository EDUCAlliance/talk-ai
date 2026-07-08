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
 * @method string getActorId()
 * @method void setActorId(string $actorId)
 * @method int getMessageId()
 * @method void setMessageId(int $messageId)
 * @method int getNodeId()
 * @method void setNodeId(int $nodeId)
 * @method ?string getAttachmentId()
 * @method void setAttachmentId(?string $attachmentId)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method ?string getMimeType()
 * @method void setMimeType(?string $mimeType)
 * @method ?string getChecksum()
 * @method void setChecksum(?string $checksum)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method ?int getLastIndexedAt()
 * @method void setLastIndexedAt(?int $lastIndexedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class RoomDocumentSource extends Entity implements JsonSerializable {
	protected int $botId = 0;
	protected string $roomToken = '';
	protected string $actorId = '';
	protected int $messageId = 0;
	protected int $nodeId = 0;
	protected ?string $attachmentId = null;
	protected string $displayName = '';
	protected ?string $mimeType = null;
	protected ?string $checksum = null;
	protected string $status = 'pending';
	protected ?string $errorMessage = null;
	protected ?int $lastIndexedAt = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('botId', 'integer');
		$this->addType('messageId', 'integer');
		$this->addType('nodeId', 'integer');
		$this->addType('lastIndexedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'bot_id' => $this->botId,
			'room_token' => $this->roomToken,
			'actor_id' => $this->actorId,
			'message_id' => $this->messageId,
			'node_id' => $this->nodeId,
			'attachment_id' => $this->attachmentId,
			'display_name' => $this->displayName,
			'mime_type' => $this->mimeType,
			'checksum' => $this->checksum,
			'status' => $this->status,
			'error_message' => $this->errorMessage,
			'last_indexed_at' => $this->lastIndexedAt,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}
}
