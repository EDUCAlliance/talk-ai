<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Service\JsonPointer;

/**
 * Resolves local "#/..." Reference Objects within one document. External and
 * file references are refused; a global budget bounds expansion attacks.
 */
final class RefResolver {
	public const MAX_RESOLUTIONS = 20_000;

	private int $resolutions = 0;

	/** @param array<string,mixed> $document */
	public function __construct(
		private array $document,
	) {
	}

	public static function isReference(mixed $node): bool {
		return is_array($node) && array_key_exists('$ref', $node);
	}

	/**
	 * Follow a (chain of) reference(s) to the referenced node.
	 *
	 * @param list<string> $stack References already being expanded by the caller
	 * @param-out list<string> $visited References followed by this call
	 * @throws OpenApiImportException with a ref_* code (per-operation) or document_too_complex (fatal)
	 */
	public function resolve(mixed $node, array $stack = [], ?array &$visited = null): mixed {
		$visited = [];
		while (self::isReference($node)) {
			$ref = $node['$ref'];
			if (!is_string($ref) || $ref === '') {
				throw new OpenApiImportException('ref_invalid', 'A reference is not a string.');
			}
			if (in_array($ref, $stack, true) || in_array($ref, $visited, true)) {
				throw new OpenApiImportException('ref_cycle', 'A reference refers back to itself.', ['ref' => self::clip($ref)]);
			}
			if (++$this->resolutions > self::MAX_RESOLUTIONS) {
				throw new OpenApiImportException('document_too_complex', 'The document expands to more references than the import limit.', ['limit_references' => self::MAX_RESOLUTIONS]);
			}
			$visited[] = $ref;
			$node = $this->lookup($ref);
		}
		return $node;
	}

	private function lookup(string $ref): mixed {
		if ($ref[0] !== '#') {
			throw new OpenApiImportException('ref_external', 'Only references within the document are supported.', ['ref' => self::clip($ref)]);
		}
		$pointer = rawurldecode(substr($ref, 1));
		if ($pointer === '' || !JsonPointer::isValid($pointer)) {
			throw new OpenApiImportException('ref_invalid', 'A reference is not a valid JSON pointer.', ['ref' => self::clip($ref)]);
		}
		$target = JsonPointer::get($this->document, $pointer, $found);
		if (!$found) {
			throw new OpenApiImportException('ref_not_found', 'A reference points to a missing element.', ['ref' => self::clip($ref)]);
		}
		return $target;
	}

	private static function clip(string $ref): string {
		return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $ref) ?? '', 0, 200);
	}
}
