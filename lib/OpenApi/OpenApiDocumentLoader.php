<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/**
 * Parses an OpenAPI 3.0 JSON document within fixed resource limits. The
 * document is data only; nothing in it is executed or fetched.
 */
final class OpenApiDocumentLoader {
	public const MAX_BYTES = 2_097_152;
	public const MAX_DEPTH = 64;
	public const MAX_NODES = 200_000;

	/**
	 * @return array{document: array<string,mixed>, version: string, sha256: string, bytes: string}
	 * @throws OpenApiImportException
	 */
	public function load(string $bytes): array {
		if (strlen($bytes) > self::MAX_BYTES) {
			throw new OpenApiImportException('document_too_large', 'The document is larger than the import limit.', ['limit_bytes' => self::MAX_BYTES]);
		}
		// Preserve and hash the original UTF-8 bytes, including an optional BOM.
		// Only the parser input has its leading BOM removed.
		$originalBytes = $bytes;
		if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
			$bytes = substr($bytes, 3);
		}
		if (!mb_check_encoding($bytes, 'UTF-8')) {
			throw new OpenApiImportException('document_not_utf8', 'The document is not valid UTF-8.');
		}
		$trimmed = ltrim($bytes);
		if ($trimmed === '' || $trimmed[0] !== '{') {
			if (preg_match('/^(openapi|swagger)\s*:/m', $trimmed) === 1) {
				throw new OpenApiImportException('yaml_not_supported', 'YAML documents are not supported. Convert the document to JSON.');
			}
			throw new OpenApiImportException('document_not_json', 'The document is not a JSON object.');
		}

		try {
			$document = json_decode($bytes, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
		} catch (\JsonException $e) {
			if ($e->getCode() === JSON_ERROR_DEPTH) {
				throw new OpenApiImportException('document_too_deep', 'The document is nested too deeply.', ['limit_depth' => self::MAX_DEPTH]);
			}
			throw new OpenApiImportException('document_invalid_json', 'The document is not valid JSON.', ['reason' => $e->getMessage()]);
		}
		if (!is_array($document) || ($document !== [] && array_is_list($document))) {
			throw new OpenApiImportException('document_not_json', 'The document is not a JSON object.');
		}
		self::assertNodeLimit($document);

		$version = self::version($document);
		if (!isset($document['info']) || !is_array($document['info'])
			|| !is_string($document['info']['title'] ?? null) || !is_string($document['info']['version'] ?? null)) {
			throw new OpenApiImportException('document_invalid', 'The document has no valid info object with title and version.', ['field' => 'info']);
		}
		if (!isset($document['paths']) || !is_array($document['paths']) || ($document['paths'] !== [] && array_is_list($document['paths']))) {
			throw new OpenApiImportException('document_invalid', 'The document has no paths object.', ['field' => 'paths']);
		}

		return ['document' => $document, 'version' => $version, 'sha256' => hash('sha256', $originalBytes), 'bytes' => $originalBytes];
	}

	/** @param array<string,mixed> $document */
	private static function version(array $document): string {
		if (array_key_exists('swagger', $document)) {
			throw new OpenApiImportException('unsupported_version', 'Swagger 2.0 documents are not supported. Use OpenAPI 3.0.', [
				'version' => is_scalar($document['swagger']) ? mb_substr((string)$document['swagger'], 0, 16) : null,
			]);
		}
		$version = $document['openapi'] ?? null;
		if (!is_string($version)) {
			throw new OpenApiImportException('unsupported_version', 'The document does not declare an OpenAPI version.', ['version' => null]);
		}
		if (preg_match('/^3\.0\.\d{1,3}$/D', $version) !== 1) {
			throw new OpenApiImportException('unsupported_version', 'Only OpenAPI 3.0.x documents are supported.', ['version' => mb_substr($version, 0, 16)]);
		}
		return $version;
	}

	/** @param array<mixed> $document */
	private static function assertNodeLimit(array $document): void {
		$count = 0;
		$stack = [$document];
		while ($stack !== []) {
			$node = array_pop($stack);
			foreach ($node as $value) {
				if (++$count > self::MAX_NODES) {
					throw new OpenApiImportException('document_too_complex', 'The document has more elements than the import limit.', ['limit_nodes' => self::MAX_NODES]);
				}
				if (is_array($value)) {
					$stack[] = $value;
				}
			}
		}
	}
}
