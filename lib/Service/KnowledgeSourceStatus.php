<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

/**
 * Status values and the stored error format of knowledge sources.
 *
 * `error_message` holds either a legacy plain text or a JSON object
 * {"code": ..., "params": {...}, "detail": ...}. Code and params are safe to
 * show to the bot owner; the detail is for administrators and logs.
 */
final class KnowledgeSourceStatus {
	public const READY = 'ready';
	public const PENDING = 'pending';
	public const ERROR = 'error';
	/** The owner can no longer reach the file or folder; its index is kept but not used for answers. */
	public const UNAVAILABLE = 'unavailable';

	/** Progress stage while a retry waits for the embedding provider. */
	public const STAGE_WAITING = 'waiting';

	public const NO_FILES = 'no_files';
	public const METADATA_ONLY = 'metadata_only';
	public const NO_SUPPORTED_FILES = 'no_supported_files';
	public const NO_TEXT = 'no_text';
	public const EXTRACTION_FAILED = 'extraction_failed';
	public const SOURCE_MISSING = 'source_missing';
	public const SOURCE_ACCESS_DENIED = 'source_access_denied';
	public const SOURCE_TEMPORARILY_UNAVAILABLE = 'source_temporarily_unavailable';
	public const EMBEDDING_RATE_LIMITED = 'embedding_rate_limited';
	public const EMBEDDING_FAILED = 'embedding_failed';
	public const URL_FETCH_FAILED = 'url_fetch_failed';
	public const INDEXING_FAILED = 'indexing_failed';
	/** A run stopped without finishing (worker killed, server restart). */
	public const INDEXING_INTERRUPTED = 'indexing_interrupted';
	public const RAG_DISABLED = 'rag_disabled';
	/** Not an error: the source is ready, but some files were skipped. */
	public const SKIPPED_FILES = 'skipped_files';

	private const MAX_DETAIL_LENGTH = 2000;

	/**
	 * @param array<string,int|bool|string|list<string>> $params
	 */
	public static function encode(string $code, array $params = [], string $detail = ''): string {
		return json_encode([
			'code' => $code,
			'params' => (object)$params,
			'detail' => mb_substr($detail, 0, self::MAX_DETAIL_LENGTH),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"code":"' . $code . '"}';
	}

	/**
	 * @return array{code:string,params:array<string,mixed>,detail:string}|null
	 */
	public static function decode(?string $stored): ?array {
		if ($stored === null || !str_starts_with(ltrim($stored), '{')) {
			return null;
		}
		$decoded = json_decode($stored, true);
		if (!is_array($decoded) || !is_string($decoded['code'] ?? null) || preg_match('/^[a-z_]{1,64}$/', $decoded['code']) !== 1) {
			return null;
		}

		return [
			'code' => $decoded['code'],
			'params' => is_array($decoded['params'] ?? null) ? $decoded['params'] : [],
			'detail' => is_string($decoded['detail'] ?? null) ? $decoded['detail'] : '',
		];
	}
}
