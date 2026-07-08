<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

class ResolvedAttachment {
	public function __construct(
		private string $tempPath,
		private string $displayName,
		private ?string $mimeType = null
	) {
	}

	public function getTempPath(): string {
		return $this->tempPath;
	}

	public function getDisplayName(): string {
		return $this->displayName;
	}

	public function getMimeType(): ?string {
		return $this->mimeType;
	}

	public function cleanup(): void {
		if (is_file($this->tempPath)) {
			@unlink($this->tempPath);
		}
	}
}
