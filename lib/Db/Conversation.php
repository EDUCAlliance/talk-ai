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
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method ?int getThreadRootMessageId()
 * @method void setThreadRootMessageId(?int $threadRootMessageId)
 */
class Conversation extends Entity implements JsonSerializable {
	protected int $botId = 0;
	protected string $roomToken = '';
	protected string $userId = '';
	protected string $role = '';
	protected string $content = '';
	protected int $createdAt = 0;
	protected ?int $threadRootMessageId = null;

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'bot_id' => $this->botId,
			'room_token' => $this->roomToken,
			'thread_root_message_id' => $this->threadRootMessageId,
			'user_id' => $this->userId,
			'role' => $this->role,
			'content' => $this->content,
			'created_at' => $this->createdAt,
		];
	}
}

