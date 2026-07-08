<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity for queued LLM requests waiting due to rate limits.
 * 
 * @method int getId()
 * @method void setId(int $id)
 * @method int getBotId()
 * @method void setBotId(int $botId)
 * @method string getRoomToken()
 * @method void setRoomToken(string $roomToken)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method ?string getOriginalMessage()
 * @method void setOriginalMessage(?string $originalMessage)
 * @method ?int getReplyToMessageId()
 * @method void setReplyToMessageId(?int $replyToMessageId)
 * @method ?int getThreadRootMessageId()
 * @method void setThreadRootMessageId(?int $threadRootMessageId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getResult()
 * @method void setResult(?string $result)
 * @method ?string getError()
 * @method void setError(?string $error)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method ?int getProcessedAt()
 * @method void setProcessedAt(?int $processedAt)
 */
class QueuedRequest extends Entity implements JsonSerializable {
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected int $botId = 0;
    protected string $roomToken = '';
    protected string $userId = '';
    protected string $message = '';
    protected ?string $originalMessage = null;
    protected ?int $replyToMessageId = null;
    protected ?int $threadRootMessageId = null;
    protected string $status = self::STATUS_PENDING;
    protected ?string $result = null;
    protected ?string $error = null;
    protected int $attempts = 0;
    protected int $priority = 100;
    protected int $createdAt = 0;
    protected ?int $processedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('botId', 'integer');
        $this->addType('replyToMessageId', 'integer');
        $this->addType('threadRootMessageId', 'integer');
        $this->addType('attempts', 'integer');
        $this->addType('priority', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('processedAt', 'integer');
    }

    /**
     * Check if request can be retried
     */
    public function canRetry(int $maxAttempts = 3): bool {
        return $this->status === self::STATUS_FAILED && $this->attempts < $maxAttempts;
    }

    /**
     * Check if request is stale (older than given seconds)
     */
    public function isStale(int $maxAgeSeconds = 3600): bool {
        return (time() - $this->createdAt) > $maxAgeSeconds;
    }

    /**
     * Increment attempt counter
     */
    public function incrementAttempts(): void {
        $this->attempts++;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'bot_id' => $this->botId,
            'room_token' => $this->roomToken,
            'user_id' => $this->userId,
            'message' => $this->message,
            'original_message' => $this->originalMessage,
            'reply_to_message_id' => $this->replyToMessageId,
            'thread_root_message_id' => $this->threadRootMessageId,
            'status' => $this->status,
            'result' => $this->result,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'priority' => $this->priority,
            'created_at' => $this->createdAt,
            'processed_at' => $this->processedAt,
            'can_retry' => $this->canRetry(),
            'is_stale' => $this->isStale(),
        ];
    }
}




