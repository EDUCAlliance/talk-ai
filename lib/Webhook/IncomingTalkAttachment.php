<?php

declare(strict_types=1);

namespace OCA\EducAI\Webhook;

class IncomingTalkAttachment {
	public const KIND_IMAGE = 'image';
	public const KIND_AUDIO = 'audio';
	public const KIND_DOCUMENT = 'document';
	public const KIND_UNSUPPORTED = 'unsupported';

	/**
	 * @param array<string,mixed> $fileRef
	 */
	public function __construct(
		private string $kind,
		private string $sharedItemType,
		private ?string $mimeType,
		private string $displayName,
		private string $parameterKey,
		private array $fileRef = [],
		private ?string $unsupportedReason = null
	) {
	}

	public function getKind(): string {
		return $this->kind;
	}

	public function getSharedItemType(): string {
		return $this->sharedItemType;
	}

	public function getMimeType(): ?string {
		return $this->mimeType;
	}

	public function getDisplayName(): string {
		return $this->displayName;
	}

	public function getParameterKey(): string {
		return $this->parameterKey;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getFileRef(): array {
		return $this->fileRef;
	}

	public function getUnsupportedReason(): ?string {
		return $this->unsupportedReason;
	}

	public function isImage(): bool {
		return $this->kind === self::KIND_IMAGE;
	}

	public function isAudio(): bool {
		return $this->kind === self::KIND_AUDIO;
	}

	public function isDocument(): bool {
		return $this->kind === self::KIND_DOCUMENT;
	}

	public function isSupported(): bool {
		return $this->kind !== self::KIND_UNSUPPORTED;
	}
}
