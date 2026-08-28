<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCP\IL10N;

/**
 * Request-locale UI metadata for built-in tools.
 *
 * Tool names and descriptions used by the model remain stable in the provider
 * registry. Only synchronous API payloads should use this mapper.
 */
class BuiltInToolUiService {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function getLabel(string $name, ?string $fallback = null): string {
		return match ($name) {
			BuiltInToolProvider::TOOL_ROOM_SEARCH => $this->l10n->t('Room Document Search'),
			BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH => $this->l10n->t('Room Image Search'),
			BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE => $this->l10n->t('Image Attachment Analysis'),
			BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO => $this->l10n->t('Audio Attachment Transcription'),
			BuiltInToolProvider::TOOL_RAG_SEARCH => $this->l10n->t('Document Search (RAG)'),
			BuiltInToolProvider::TOOL_WIKI_SEARCH => $this->l10n->t('Wiki Search'),
			BuiltInToolProvider::TOOL_WIKI_READ_PAGE => $this->l10n->t('Wiki Read Page'),
			BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE => $this->l10n->t('Wiki Write Page'),
			BuiltInToolProvider::TOOL_WIKI_LOG_EVENT => $this->l10n->t('Wiki Log Event'),
			default => $fallback ?? ucwords(str_replace('_', ' ', $name)),
		};
	}

	public function getDescription(string $name, ?string $fallback = null): string {
		return match ($name) {
			BuiltInToolProvider::TOOL_RAG_SEARCH => $this->l10n->t('Search through indexed documents attached to this bot.'),
			BuiltInToolProvider::TOOL_ROOM_SEARCH => $this->l10n->t('Search documents uploaded in the current Nextcloud Talk room. Can be used together with the bot\'s global document search.'),
			BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH => $this->l10n->t('Search image analyses from screenshots and photos uploaded in the current Nextcloud Talk room.'),
			BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE => $this->l10n->t('Analyze image attachments from the current Talk message.'),
			BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO => $this->l10n->t('Transcribe audio or voice-message attachments from the current Talk message.'),
			BuiltInToolProvider::TOOL_WIKI_SEARCH => $this->l10n->t('Search the bot\'s persistent Markdown wiki.'),
			BuiltInToolProvider::TOOL_WIKI_READ_PAGE => $this->l10n->t('Read one page from the bot\'s persistent Markdown wiki.'),
			BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE => $this->l10n->t('Create, update, or append pages in the bot\'s persistent Markdown wiki.'),
			BuiltInToolProvider::TOOL_WIKI_LOG_EVENT => $this->l10n->t('Append a maintenance event to the bot wiki log.'),
			default => $fallback ?? '',
		};
	}
}
