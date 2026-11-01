<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\BotSource;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/** Readable location and status texts of knowledge sources, shared by the bot and admin views. */
class KnowledgeSourcePresenter {
	public function __construct(
		private IRootFolder $rootFolder,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Path relative to the owner's files (or the URL) and a short name.
	 *
	 * @return array{path:?string,display_name:?string}
	 */
	public function describeLocation(BotSource $source): array {
		$data = ['path' => null, 'display_name' => null];

		// Handle URL sources differently
		if ($source->getNodeType() === 'url') {
			$url = $source->getSourceUrl();
			$data['path'] = $url;
			// Extract domain/filename as display name
			if ($url !== null) {
				$parsed = parse_url($url);
				$host = $parsed['host'] ?? '';
				$path = $parsed['path'] ?? '/';
				$filename = basename($path);
				$data['display_name'] = $filename !== '' && $filename !== '/' ? $filename : $host;
			}
			return $data;
		}

		// Handle file/folder sources
		try {
			$owner = $source->getOwnerUid();
			if ($owner !== null && $owner !== '') {
				$userFolder = $this->rootFolder->getUserFolder($owner);
				$nodes = $userFolder->getById($source->getNodeId());
				if (is_array($nodes) && count($nodes) > 0) {
					$node = $nodes[0];
					if ($node instanceof Node) {
						$fullPath = $node->getPath();
						$rootPath = $userFolder->getPath();
						if (str_starts_with($fullPath, $rootPath)) {
							$relative = trim(substr($fullPath, strlen($rootPath)), '/');
							$data['path'] = $relative === '' ? '/' : $relative;
						} else {
							$data['path'] = $fullPath;
						}
						$data['display_name'] = $node->getName();
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Unable to resolve source path', [
				'source_id' => $source->getId(),
				'exception' => $e,
			]);
		}

		return $data;
	}

	/**
	 * The stored technical detail (provider or exception text) for administrators only,
	 * with credentials masked. Null when nothing beyond the category is stored.
	 */
	public function technicalDetail(BotSource $source): ?string {
		$stored = $source->getErrorMessage();
		if ($stored === null || trim($stored) === '') {
			return null;
		}
		$decoded = KnowledgeSourceStatus::decode($stored);
		$detail = trim($decoded !== null ? $decoded['detail'] : $stored);
		if ($detail === '') {
			return null;
		}
		$detail = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 [redacted]', $detail) ?? $detail;
		$detail = preg_replace('/\b(api[_-]?key|access[_-]?token|token|password|secret)=([^\s&]{6,})/i', '$1=[redacted]', $detail) ?? $detail;
		// Credentials in URLs (https://user:password@host).
		return preg_replace('#(\b[a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@#i', '$1[redacted]@', $detail) ?? $detail;
	}

	/**
	 * Owner-facing status texts. Stored details (provider texts, URLs) are never shown,
	 * only the category and its safe parameters.
	 *
	 * @return array{error_message:?string,source_error_code:?string,notice_message:?string}
	 */
	public function describeStatus(BotSource $source): array {
		$status = $source->getStatus();
		$stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
		$code = $stored['code'] ?? null;
		$params = $stored['params'] ?? [];
		$result = ['error_message' => null, 'source_error_code' => null, 'notice_message' => null];

		if ($status === KnowledgeSourceStatus::READY && $code === KnowledgeSourceStatus::SKIPPED_FILES) {
			$result['notice_message'] = $this->l10n->t('Skipped files: %1$s not supported, %2$s without text.', [
				(int)($params['unsupported'] ?? 0),
				(int)($params['empty'] ?? 0),
			]) . $this->unsupportedSuffix($params);
			return $result;
		}
		if ($status === KnowledgeSourceStatus::PENDING && $code === KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED) {
			$result['notice_message'] = $this->l10n->t('The embedding service asked for a pause. Indexing continues automatically.');
			return $result;
		}
		if ($status === KnowledgeSourceStatus::UNAVAILABLE && $code === KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED && (int)($params['retry_at'] ?? 0) > 0) {
			$result['error_message'] = $this->l10n->t('The source is reachable again and is indexed after a pause of the embedding service. Until then the bot does not use it.');
			$result['source_error_code'] = KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED;
			return $result;
		}

		$isProblem = in_array($status, [KnowledgeSourceStatus::ERROR, KnowledgeSourceStatus::UNAVAILABLE], true)
			&& $source->getErrorMessage() !== null
			&& $source->getErrorMessage() !== '';
		if (!$isProblem) {
			return $result;
		}

		$message = $code !== null ? $this->sourceErrorMessage($code, $params) : null;
		$result['error_message'] = $message ?? $this->l10n->t('The knowledge source could not be processed.');
		$result['source_error_code'] = $message !== null ? $code : 'knowledge_source_processing_failed';

		return $result;
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function sourceErrorMessage(string $code, array $params): ?string {
		return match ($code) {
			KnowledgeSourceStatus::NO_FILES => $this->l10n->t('The folder contains no files.'),
			KnowledgeSourceStatus::METADATA_ONLY => $this->l10n->t('Only system files were found (for example ._* or .DS_Store). There is nothing to index.'),
			KnowledgeSourceStatus::NO_SUPPORTED_FILES => $this->l10n->t('No supported files found.') . $this->unsupportedSuffix($params)
				. (($params['converter_disabled'] ?? false) === true ? ' ' . $this->l10n->t('Document conversion (Docling) is turned off; ask your administrator to enable it.') : ''),
			KnowledgeSourceStatus::NO_TEXT => $this->l10n->t('No readable text found: %1$s files without text, %2$s not supported.', [
				(int)($params['empty'] ?? 0),
				(int)($params['unsupported'] ?? 0),
			]),
			KnowledgeSourceStatus::EXTRACTION_FAILED => $this->l10n->t('%1$s of %2$s files could not be read. The previous index is kept; check the files and reindex.', [
				(int)($params['failed'] ?? 0),
				(int)($params['total'] ?? 0),
			]),
			KnowledgeSourceStatus::SOURCE_MISSING => $this->l10n->t('The file or folder is no longer reachable for its owner (deleted, moved or no longer shared). The bot does not use this source until it is reachable again. Check it, select it again or remove the source.'),
			KnowledgeSourceStatus::SOURCE_ACCESS_DENIED => $this->l10n->t('The owner no longer has access to this file or folder. The bot does not use this source until access is restored.'),
			KnowledgeSourceStatus::SOURCE_TEMPORARILY_UNAVAILABLE => ($params['retry_scheduled'] ?? false) === true
				? $this->l10n->t('The source could not be read temporarily. The previous index is kept and indexing is retried automatically.')
				: $this->l10n->t('The source could not be read temporarily. The previous index is kept; reindex later.'),
			KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED => $this->l10n->t('The embedding service kept asking for a pause. The previous index is kept; reindex later.'),
			KnowledgeSourceStatus::EMBEDDING_FAILED => isset($params['http_status']) && is_int($params['http_status'])
				? $this->l10n->t('The embedding service rejected the request (HTTP %s). The previous index is kept; administrators find details in the Nextcloud log.', [$params['http_status']])
				: $this->l10n->t('The embedding service could not be reached. The previous index is kept; administrators find details in the Nextcloud log.'),
			KnowledgeSourceStatus::URL_FETCH_FAILED => $this->l10n->t('The web page could not be loaded. Check the address and reindex.'),
			KnowledgeSourceStatus::RAG_DISABLED => $this->l10n->t('Knowledge sources are turned off by the administrator.'),
			KnowledgeSourceStatus::INDEXING_INTERRUPTED => $this->l10n->t('Indexing was interrupted, for example by a server restart. The previous index is kept; start it again with Reindex.'),
			default => null,
		};
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function unsupportedSuffix(array $params): string {
		$extensions = array_values(array_filter(
			is_array($params['extensions'] ?? null) ? $params['extensions'] : [],
			static fn ($extension): bool => is_string($extension) && preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1
		));
		if ($extensions === []) {
			return '';
		}

		return ' ' . $this->l10n->t('Unsupported file types: %s', [implode(', ', array_map(static fn (string $extension): string => '.' . $extension, $extensions))]);
	}
}
