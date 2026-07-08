<?php

declare(strict_types=1);

namespace OCA\EducAI\Webhook;

class TalkMessageParser {
	private TalkAttachmentNormalizer $attachmentNormalizer;

	public function __construct(TalkAttachmentNormalizer $attachmentNormalizer) {
		$this->attachmentNormalizer = $attachmentNormalizer;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function parse(array $payload): IncomingTalkMessage {
		$content = $payload['object']['content'] ?? '';
		$contentData = $this->decodeContentPayload($content);

		$text = $this->resolveText($content, $contentData, $payload);
		$parameters = $this->augmentAttachmentParameters(
			$this->resolveParameters($contentData, $payload),
			$payload
		);
		$attachments = $this->attachmentNormalizer->normalize($parameters);

		$roomToken = (string)($payload['target']['id'] ?? '');
		$actorId = (string)($payload['actor']['id'] ?? '');
		$messageId = (int)($payload['object']['id'] ?? 0);
		$inReplyTo = $this->resolveParentMessageId($payload, $contentData);
		$threadRootMessageId = $this->resolveThreadRootMessageId($payload, $contentData);

		return new IncomingTalkMessage(
			trim($text),
			(string)$content,
			$roomToken,
			$actorId,
			$messageId,
			$inReplyTo,
			$attachments,
			$threadRootMessageId
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decodeContentPayload(array|bool|float|int|string|null $content): array {
		if (is_array($content)) {
			return $content;
		}

		if (!is_string($content) || trim($content) === '') {
			return [];
		}

		$decoded = json_decode($content, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array|bool|float|int|string|null $rawContent
	 * @param array<string,mixed> $contentData
	 * @param array<string,mixed> $payload
	 */
	private function resolveText(array|bool|float|int|string|null $rawContent, array $contentData, array $payload): string {
		$candidates = [
			$contentData['message'] ?? null,
			$contentData['text'] ?? null,
			$payload['object']['message'] ?? null,
			$payload['object']['name'] ?? null,
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && trim($candidate) !== '') {
				return $candidate;
			}
		}

		if (is_string($rawContent) && trim($rawContent) !== '' && $contentData === []) {
			return $rawContent;
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $contentData
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function resolveParameters(array $contentData, array $payload): array {
		$candidates = [
			$contentData['parameters'] ?? null,
			$contentData['messageParameters'] ?? null,
			$payload['object']['messageParameters'] ?? null,
		];

		foreach ($candidates as $candidate) {
			if (is_array($candidate)) {
				return $candidate;
			}
		}

		return [];
	}

	/**
	 * @param array<string,mixed> $parameters
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function augmentAttachmentParameters(array $parameters, array $payload): array {
		$ownerUid = $this->resolveAttachmentOwnerUid($parameters, $payload);
		if ($ownerUid === null) {
			return $parameters;
		}

		foreach ($parameters as $parameterKey => $parameter) {
			if (!is_array($parameter)) {
				continue;
			}

			$parameter['_educai_owner_uid'] = $ownerUid;
			$parameters[$parameterKey] = $parameter;
		}

		return $parameters;
	}

	/**
	 * @param array<string,mixed> $parameters
	 * @param array<string,mixed> $payload
	 */
	private function resolveAttachmentOwnerUid(array $parameters, array $payload): ?string {
		$candidates = [
			is_array($parameters['actor'] ?? null) ? ($parameters['actor']['id'] ?? null) : null,
			is_array($parameters['actor'] ?? null) ? ($parameters['actor']['mention-id'] ?? null) : null,
			$payload['actor']['id'] ?? null,
		];

		foreach ($candidates as $candidate) {
			if (!is_string($candidate) || trim($candidate) === '') {
				continue;
			}

			$normalized = trim($candidate);
			if (str_starts_with($normalized, 'users/')) {
				$normalized = substr($normalized, strlen('users/'));
			}

			if ($normalized !== '') {
				return $normalized;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $contentData
	 */
	private function resolveParentMessageId(array $payload, array $contentData): ?int {
		$candidates = [
			$payload['object']['inReplyTo']['object']['id'] ?? null,
			$payload['object']['inReplyTo']['id'] ?? null,
			$payload['object']['parent']['id'] ?? null,
			$contentData['parent']['id'] ?? null,
			$contentData['inReplyTo']['object']['id'] ?? null,
			$contentData['inReplyTo']['id'] ?? null,
			$contentData['parentMessageId'] ?? null,
			$contentData['replyTo'] ?? null,
		];

		foreach ($candidates as $candidate) {
			if (is_numeric($candidate)) {
				return (int)$candidate;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $contentData
	 */
	private function resolveThreadRootMessageId(array $payload, array $contentData): ?int {
		$objectMetaData = $this->decodeContentPayload($payload['object']['meta_data'] ?? $payload['object']['metaData'] ?? null);
		$contentMetaData = $this->decodeContentPayload($contentData['meta_data'] ?? $contentData['metaData'] ?? null);

		$candidates = [
			$payload['object']['thread_id'] ?? null,
			$payload['object']['threadId'] ?? null,
			$payload['object']['thread']['id'] ?? null,
			$payload['object']['conversationThread']['id'] ?? null,
			$objectMetaData['thread_id'] ?? null,
			$objectMetaData['threadId'] ?? null,
			$contentData['thread_id'] ?? null,
			$contentData['threadId'] ?? null,
			$contentData['thread']['id'] ?? null,
			$contentMetaData['thread_id'] ?? null,
			$contentMetaData['threadId'] ?? null,
			$contentData['parameters']['thread'] ?? null,
		];

		foreach ($candidates as $candidate) {
			if (is_numeric($candidate)) {
				$value = (int)$candidate;
				return $value > 0 ? $value : null;
			}
		}

		return null;
	}
}
