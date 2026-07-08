<?php

declare(strict_types=1);

namespace OCA\EducAI\Webhook;

class TalkAttachmentNormalizer {
	/**
	 * @param array<string,mixed> $parameters
	 * @return array<int,IncomingTalkAttachment>
	 */
	public function normalize(array $parameters): array {
		$attachments = [];

		foreach ($parameters as $parameterKey => $parameter) {
			if (!is_array($parameter)) {
				continue;
			}

			$normalized = $this->normalizeParameter((string)$parameterKey, $parameter);
			if ($normalized !== null) {
				$attachments[] = $normalized;
			}
		}

		return $attachments;
	}

	/**
	 * @param array<string,mixed> $parameter
	 */
	private function normalizeParameter(string $parameterKey, array $parameter): ?IncomingTalkAttachment {
		$sharedItemType = $this->resolveSharedItemType($parameter);
		$mimeType = $this->resolveMimeType($parameter);
		$displayName = $this->resolveDisplayName($parameter, $parameterKey);

		$kind = $this->mapKind($sharedItemType, $mimeType);
		if ($kind === null) {
			return null;
		}

		$unsupportedReason = null;
		if ($kind === IncomingTalkAttachment::KIND_UNSUPPORTED) {
			$unsupportedReason = $mimeType !== null && str_starts_with($mimeType, 'video/')
				? 'Video attachments are not supported yet.'
				: 'This attachment type is not supported yet.';
		}

		return new IncomingTalkAttachment(
			$kind,
			$sharedItemType,
			$mimeType,
			$displayName,
			$parameterKey,
			$parameter,
			$unsupportedReason
		);
	}

	/**
	 * @param array<string,mixed> $parameter
	 */
	private function resolveSharedItemType(array $parameter): string {
		$candidates = [
			$parameter['type'] ?? null,
			$parameter['parameterType'] ?? null,
			$parameter['sharedItemType'] ?? null,
			$parameter['shared_item_type'] ?? null,
			$parameter['itemType'] ?? null,
			$parameter['item_type'] ?? null,
			is_array($parameter['file'] ?? null) ? ($parameter['file']['type'] ?? null) : null,
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && $candidate !== '') {
				return strtolower($candidate);
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $parameter
	 */
	private function resolveMimeType(array $parameter): ?string {
		$candidates = [
			$parameter['mimetype'] ?? null,
			$parameter['mimeType'] ?? null,
			$parameter['mime_type'] ?? null,
			is_array($parameter['file'] ?? null) ? ($parameter['file']['mimetype'] ?? $parameter['file']['mimeType'] ?? null) : null,
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && $candidate !== '') {
				return strtolower($candidate);
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $parameter
	 */
	private function resolveDisplayName(array $parameter, string $fallback): string {
		$candidates = [
			$parameter['name'] ?? null,
			$parameter['title'] ?? null,
			$parameter['displayName'] ?? null,
			is_array($parameter['file'] ?? null) ? ($parameter['file']['name'] ?? null) : null,
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return $fallback;
	}

	private function mapKind(string $sharedItemType, ?string $mimeType): ?string {
		if (in_array($sharedItemType, ['audio', 'voice', 'recording'], true)) {
			return IncomingTalkAttachment::KIND_AUDIO;
		}

		if (in_array($sharedItemType, ['media', 'image'], true)) {
			if ($mimeType !== null && str_starts_with($mimeType, 'video/')) {
				return IncomingTalkAttachment::KIND_UNSUPPORTED;
			}
			if ($mimeType === null || str_starts_with($mimeType, 'image/')) {
				return IncomingTalkAttachment::KIND_IMAGE;
			}
		}

		if ($mimeType !== null) {
			if (str_starts_with($mimeType, 'image/')) {
				return IncomingTalkAttachment::KIND_IMAGE;
			}
			if (str_starts_with($mimeType, 'audio/')) {
				return IncomingTalkAttachment::KIND_AUDIO;
			}
			if (str_starts_with($mimeType, 'video/')) {
				return IncomingTalkAttachment::KIND_UNSUPPORTED;
			}
			return IncomingTalkAttachment::KIND_DOCUMENT;
		}

		if (in_array($sharedItemType, ['file', 'talk-attachment'], true)) {
			return IncomingTalkAttachment::KIND_DOCUMENT;
		}

		return null;
	}
}
