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
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method int getNodeId()
 * @method void setNodeId(int $nodeId)
 * @method string getNodeType()
 * @method void setNodeType(string $nodeType)
 * @method ?string getSourceUrl()
 * @method void setSourceUrl(?string $sourceUrl)
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
 * @method ?int getProgress()
 * @method void setProgress(?int $progress)
 * @method ?int getProgressCurrent()
 * @method void setProgressCurrent(?int $progressCurrent)
 * @method ?int getProgressTotal()
 * @method void setProgressTotal(?int $progressTotal)
 * @method ?string getProgressStage()
 * @method void setProgressStage(?string $progressStage)
 */
class BotSource extends Entity implements JsonSerializable {
    protected int $botId = 0;
    protected string $ownerUid = '';
    // Default to -1 so that setNodeId(0) for URL sources marks the field as changed
    protected int $nodeId = -1;
    protected string $nodeType = 'file';
    protected ?string $sourceUrl = null;
    protected ?string $checksum = null;
    protected string $status = 'pending';
    protected ?string $errorMessage = null;
    protected ?int $lastIndexedAt = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;
    protected ?int $progress = 0;
    protected ?int $progressCurrent = 0;
    protected ?int $progressTotal = 0;
    protected ?string $progressStage = null;

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'bot_id' => $this->botId,
            'owner_uid' => $this->ownerUid,
            'node_id' => $this->nodeId,
            'node_type' => $this->nodeType,
            'source_url' => $this->sourceUrl,
            'checksum' => $this->checksum,
            'status' => $this->status,
            'error_message' => $this->errorMessage,
            'last_indexed_at' => $this->lastIndexedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'progress' => $this->progress,
            'progress_current' => $this->progressCurrent,
            'progress_total' => $this->progressTotal,
            'progress_stage' => $this->progressStage,
        ];
    }
}
