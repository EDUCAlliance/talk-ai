<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/**
 * Compiles an OpenAPI 3.0 parameter schema into the supported input subset:
 * primitives and arrays of primitives with type, nullable, enum, numeric and
 * length bounds and a few validated formats. Anything else blocks the
 * operation instead of being dropped, so no constraint is weakened silently.
 */
final class ParameterSchemaCompiler {
	public const MAX_ENUM_VALUES = 200;

	private const PRIMITIVES = ['string', 'integer', 'number', 'boolean'];
	private const FORMATS = [
		'integer' => ['int32', 'int64'],
		'number' => ['float', 'double'],
		'string' => ['date', 'date-time', 'uuid', 'password'],
		'boolean' => [],
	];
	private const DOC_KEYWORDS = ['description', 'title', 'example', 'examples', 'default', 'deprecated', 'readOnly', 'writeOnly', 'xml', 'externalDocs'];
	private const CONSTRAINT_KEYWORDS = [
		'type', 'format', 'nullable', 'enum', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
		'multipleOf', 'minLength', 'maxLength', 'items', 'minItems', 'maxItems', 'uniqueItems',
	];
	private const COMPOSITION_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'not', 'discriminator'];
	private const OBJECT_KEYWORDS = ['properties', 'additionalProperties', 'required', 'minProperties', 'maxProperties', 'patternProperties'];

	public function __construct(
		private RefResolver $refs,
	) {
	}

	/**
	 * @return array{schema: ?array<string,mixed>, blockers: list<array{code:string,detail:string}>}
	 */
	public function compile(mixed $schema, string $where): array {
		$blockers = [];
		$compiled = $this->compileNode($schema, $where, [], true, $blockers);
		return ['schema' => $blockers === [] ? $compiled : null, 'blockers' => $blockers];
	}

	/**
	 * @param list<string> $stack
	 * @param list<array{code:string,detail:string}> $blockers
	 * @return array<string,mixed>|null
	 */
	private function compileNode(mixed $node, string $where, array $stack, bool $allowArray, array &$blockers): ?array {
		try {
			$node = $this->refs->resolve($node, $stack, $visited);
		} catch (OpenApiImportException $e) {
			if ($e->getErrorCode() === 'document_too_complex') {
				throw $e;
			}
			$blockers[] = ['code' => $e->getErrorCode() === 'ref_cycle' ? 'schema_cycle' : $e->getErrorCode(), 'detail' => $where];
			return null;
		}
		$stack = array_merge($stack, $visited);
		if (!is_array($node) || ($node !== [] && array_is_list($node))) {
			$blockers[] = ['code' => 'schema_invalid', 'detail' => $where];
			return null;
		}

		$known = count($blockers);
		foreach (array_keys($node) as $keyword) {
			$keyword = (string)$keyword;
			if (in_array($keyword, self::COMPOSITION_KEYWORDS, true)) {
				$blockers[] = ['code' => 'schema_composition_unsupported', 'detail' => $where . ' ' . $keyword];
			} elseif (in_array($keyword, self::OBJECT_KEYWORDS, true)) {
				$blockers[] = ['code' => 'schema_object_unsupported', 'detail' => $where . ' ' . $keyword];
			} elseif ($keyword === 'pattern') {
				$blockers[] = ['code' => 'schema_pattern_unsupported', 'detail' => $where];
			} elseif (!in_array($keyword, self::CONSTRAINT_KEYWORDS, true) && !in_array($keyword, self::DOC_KEYWORDS, true) && !str_starts_with($keyword, 'x-')) {
				$blockers[] = ['code' => 'schema_keyword_unsupported', 'detail' => $where . ' ' . mb_substr($keyword, 0, 64)];
			}
		}

		$type = $node['type'] ?? null;
		if ($type === null) {
			// A composition already explains the missing type.
			if (count($blockers) === $known) {
				$blockers[] = ['code' => 'schema_type_missing', 'detail' => $where];
			}
			return null;
		}
		if ($type === 'object') {
			$blockers[] = ['code' => 'schema_object_unsupported', 'detail' => $where];
			return null;
		}
		if (!is_string($type) || (!in_array($type, self::PRIMITIVES, true) && $type !== 'array')) {
			$blockers[] = ['code' => 'schema_type_unsupported', 'detail' => $where . ' ' . (is_string($type) ? mb_substr($type, 0, 32) : 'non-string type')];
			return null;
		}
		if ($type === 'array' && !$allowArray) {
			$blockers[] = ['code' => 'schema_nested_array_unsupported', 'detail' => $where];
			return null;
		}

		$compiled = ['type' => $type];
		if (array_key_exists('nullable', $node)) {
			if (!is_bool($node['nullable'])) {
				$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' nullable'];
			} elseif ($node['nullable']) {
				$compiled['nullable'] = true;
			}
		}

		if (array_key_exists('format', $node)) {
			$format = $node['format'];
			if ($type === 'array' || !is_string($format) || !in_array($format, self::FORMATS[$type], true)) {
				$blockers[] = ['code' => 'schema_format_unsupported', 'detail' => $where . ' ' . (is_string($format) ? mb_substr($format, 0, 32) : '?')];
			} elseif ($format !== 'password' && $format !== 'float' && $format !== 'double' && $format !== 'int64') {
				$compiled['format'] = $format;
			}
		}

		if ($type === 'integer' || $type === 'number') {
			$this->compileNumeric($node, $compiled, $where, $blockers);
		} elseif (array_intersect(['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'], array_keys($node)) !== []) {
			$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' numeric bound on ' . $type];
		}

		if ($type === 'string') {
			foreach (['minLength', 'maxLength'] as $keyword) {
				if (array_key_exists($keyword, $node)) {
					if (!is_int($node[$keyword]) || $node[$keyword] < 0) {
						$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' ' . $keyword];
					} else {
						$compiled[$keyword] = $node[$keyword];
					}
				}
			}
		} elseif (array_intersect(['minLength', 'maxLength'], array_keys($node)) !== []) {
			$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' length bound on ' . $type];
		}

		if ($type === 'array') {
			if (!array_key_exists('items', $node)) {
				$blockers[] = ['code' => 'schema_items_missing', 'detail' => $where];
				return null;
			}
			$items = $this->compileNode($node['items'], $where . ' items', $stack, false, $blockers);
			if ($items !== null) {
				$compiled['items'] = $items;
			}
			foreach (['minItems', 'maxItems'] as $keyword) {
				if (array_key_exists($keyword, $node)) {
					if (!is_int($node[$keyword]) || $node[$keyword] < 0) {
						$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' ' . $keyword];
					} else {
						$compiled[$keyword] = $node[$keyword];
					}
				}
			}
			if (array_key_exists('uniqueItems', $node)) {
				if (!is_bool($node['uniqueItems'])) {
					$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' uniqueItems'];
				} elseif ($node['uniqueItems']) {
					$compiled['uniqueItems'] = true;
				}
			}
		} elseif (array_intersect(['items', 'minItems', 'maxItems', 'uniqueItems'], array_keys($node)) !== []) {
			$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' array keyword on ' . $type];
		}

		if (array_key_exists('enum', $node)) {
			$enum = $node['enum'];
			if (!is_array($enum) || $enum === [] || !array_is_list($enum) || count($enum) > self::MAX_ENUM_VALUES || $type === 'array') {
				$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' enum'];
			} else {
				foreach ($enum as $value) {
					if (!self::matchesType($value, $type, isset($compiled['nullable']))) {
						$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' enum value type'];
						break;
					}
				}
				$compiled['enum'] = array_values($enum);
			}
		}

		$description = self::text($node['description'] ?? null, 300);
		if ($description !== '') {
			$compiled['description'] = $description;
		}
		return $compiled;
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,mixed> $compiled
	 * @param list<array{code:string,detail:string}> $blockers
	 */
	private function compileNumeric(array $node, array &$compiled, string $where, array &$blockers): void {
		foreach (['minimum', 'maximum'] as $keyword) {
			if (array_key_exists($keyword, $node)) {
				if (!is_int($node[$keyword]) && !is_float($node[$keyword])) {
					$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' ' . $keyword];
				} else {
					$compiled[$keyword] = $node[$keyword];
				}
			}
		}
		foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $keyword => $bound) {
			if (array_key_exists($keyword, $node)) {
				// OpenAPI 3.0 uses booleans; a number here is 3.1/JSON Schema semantics.
				if (!is_bool($node[$keyword]) || ($node[$keyword] && !isset($compiled[$bound]))) {
					$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' ' . $keyword];
				} elseif ($node[$keyword]) {
					$compiled[$keyword] = true;
				}
			}
		}
		if (array_key_exists('multipleOf', $node)) {
			$value = $node['multipleOf'];
			if ((!is_int($value) && !is_float($value)) || $value <= 0) {
				$blockers[] = ['code' => 'schema_invalid', 'detail' => $where . ' multipleOf'];
			} else {
				$compiled['multipleOf'] = $value;
			}
		}
	}

	public static function matchesType(mixed $value, string $type, bool $nullable): bool {
		if ($value === null) {
			return $nullable;
		}
		return match ($type) {
			'string' => is_string($value),
			'integer' => is_int($value),
			'number' => is_int($value) || is_float($value),
			'boolean' => is_bool($value),
			default => false,
		};
	}

	/** Untrusted specification text, stripped of control characters and clipped. */
	public static function text(mixed $value, int $max): string {
		if (!is_string($value)) {
			return '';
		}
		$value = preg_replace('/[\x00-\x08\x0b-\x1f\x7f\x{200b}-\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', '', $value) ?? '';
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
		return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)) . '…' : $value;
	}
}
