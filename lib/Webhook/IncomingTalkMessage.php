<?php

declare(strict_types=1);

namespace OCA\EducAI\Webhook;

class IncomingTalkMessage {
	/**
	 * @param array<int,IncomingTalkAttachment> $attachments
	 */
	public function __construct(
		private string $text,
		private string $rawText,
		private string $roomToken,
		private string $actorId,
		private int $messageId,
		private ?int $inReplyTo = null,
		private array $attachments = [],
		private ?int $threadRootMessageId = null
	) {
	}

	public function getText(): string {
		return $this->text;
	}

	public function getRawText(): string {
		return $this->rawText;
	}

	public function getRoomToken(): string {
		return $this->roomToken;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function getMessageId(): int {
		return $this->messageId;
	}

	public function getInReplyTo(): ?int {
		return $this->inReplyTo;
	}

	public function getThreadRootMessageId(): ?int {
		return $this->threadRootMessageId;
	}

	/**
	 * @return array<int,IncomingTalkAttachment>
	 */
	public function getAttachments(): array {
		return $this->attachments;
	}

	public function hasAttachments(): bool {
		return $this->attachments !== [];
	}
}
