<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method ?int getBotId()
 * @method void setBotId(?int $botId)
 * @method ?string getBotMentionName()
 * @method void setBotMentionName(?string $botMentionName)
 * @method ?string getRoomToken()
 * @method void setRoomToken(?string $roomToken)
 * @method ?int getTalkMessageId()
 * @method void setTalkMessageId(?int $talkMessageId)
 * @method ?int getReplyTargetMessageId()
 * @method void setReplyTargetMessageId(?int $replyTargetMessageId)
 * @method ?int getThreadRootMessageId()
 * @method void setThreadRootMessageId(?int $threadRootMessageId)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getUserMessagePreview()
 * @method void setUserMessagePreview(?string $userMessagePreview)
 * @method ?string getErrorSummary()
 * @method void setErrorSummary(?string $errorSummary)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method ?int getFinishedAt()
 * @method void setFinishedAt(?int $finishedAt)
 * @method ?int getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method int getToolCallCount()
 * @method void setToolCallCount(int $toolCallCount)
 * @method int getEventCount()
 * @method void setEventCount(int $eventCount)
 * @method ?int getPromptTokenCount()
 * @method void setPromptTokenCount(?int $promptTokenCount)
 * @method ?int getCompletionTokenCount()
 * @method void setCompletionTokenCount(?int $completionTokenCount)
 * @method ?int getTotalTokenCount()
 * @method void setTotalTokenCount(?int $totalTokenCount)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class TraceRun extends Entity implements JsonSerializable {
	protected string $userId = '';
	protected ?int $botId = null;
	protected ?string $botMentionName = null;
	protected ?string $roomToken = null;
	protected ?int $talkMessageId = null;
	protected ?int $replyTargetMessageId = null;
	protected ?int $threadRootMessageId = null;
	protected string $source = 'talk';
	protected string $status = 'running';
	protected ?string $userMessagePreview = null;
	protected ?string $errorSummary = null;
	protected int $startedAt = 0;
	protected ?int $finishedAt = null;
	protected ?int $durationMs = null;
	protected int $toolCallCount = 0;
	protected int $eventCount = 0;
	protected ?int $promptTokenCount = null;
	protected ?int $completionTokenCount = null;
	protected ?int $totalTokenCount = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('botId', 'integer');
		$this->addType('talkMessageId', 'integer');
		$this->addType('replyTargetMessageId', 'integer');
		$this->addType('threadRootMessageId', 'integer');
		$this->addType('startedAt', 'integer');
		$this->addType('finishedAt', 'integer');
		$this->addType('durationMs', 'integer');
		$this->addType('toolCallCount', 'integer');
		$this->addType('eventCount', 'integer');
		$this->addType('promptTokenCount', 'integer');
		$this->addType('completionTokenCount', 'integer');
		$this->addType('totalTokenCount', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'user_id' => $this->userId,
			'bot_id' => $this->botId,
			'bot_mention_name' => $this->botMentionName,
			'room_token' => $this->roomToken,
			'talk_message_id' => $this->talkMessageId,
			'reply_target_message_id' => $this->replyTargetMessageId,
			'thread_root_message_id' => $this->threadRootMessageId,
			'source' => $this->source,
			'status' => $this->status,
			'user_message_preview' => $this->userMessagePreview,
			'error_summary' => $this->errorSummary,
			'started_at' => $this->startedAt,
			'finished_at' => $this->finishedAt,
			'duration_ms' => $this->durationMs,
			'tool_call_count' => $this->toolCallCount,
			'event_count' => $this->eventCount,
			'prompt_token_count' => $this->promptTokenCount,
			'completion_token_count' => $this->completionTokenCount,
			'total_token_count' => $this->totalTokenCount,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}
}
