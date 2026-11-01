<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Service\JsonPointer;

/**
 * Proposes a result mapping from a documented JSON response schema. Only a
 * suggestion for the admin review; response schemas are documentation and
 * the mapping is validated against real responses at runtime.
 */
final class ResponseMappingSuggester {
	private const MAX_DEPTH = 8;
	private const MAX_FIELDS = 8;
	private const RECORD_KEYS = ['data', 'items', 'results', 'records', 'entries', 'content', 'list'];
	private const ID_KEYS = ['id', 'uuid', 'identifier', 'key', 'code'];
	private const TITLE_KEYS = ['title', 'name', 'label', 'display_name', 'displayName', 'subject'];
	private const LINK_KEYS = ['permalink', 'html_url', 'web_url', 'url', 'link', 'href'];
	private const PAGING_KEYS = [
		'has_more' => ['has_more', 'hasMore', 'more'],
		'next_cursor' => ['next_cursor', 'nextCursor', 'cursor', 'next'],
		'total' => ['total', 'total_count', 'totalCount', 'count'],
	];

	public function __construct(
		private RefResolver $refs,
	) {
	}

	/** @return array<string,mixed>|null */
	public function suggest(mixed $schema): ?array {
		$root = $this->flatten($schema, 0);
		if ($root === null) {
			return null;
		}
		$records = '';
		$item = $root;
		$properties = $root['properties'];
		if ($root['type'] === 'array') {
			$item = $this->flatten($root['items'], 1);
		} elseif ($properties !== []) {
			$arrays = [];
			foreach ($properties as $name => $property) {
				$flat = $this->flatten($property, 1);
				if ($flat !== null && $flat['type'] === 'array') {
					$arrays[(string)$name] = $flat;
				}
			}
			$preferred = array_values(array_intersect(self::RECORD_KEYS, array_keys($arrays)));
			// Only conventional envelope keys; an array attribute of a single object is not a record list.
			$name = $preferred[0] ?? null;
			if ($name !== null) {
				$records = '/' . JsonPointer::escapeToken($name);
				$item = $this->flatten($arrays[$name]['items'], 2);
			}
		}

		$suggestion = ['records' => $records, 'id' => null, 'title' => null, 'link_field' => null, 'fields' => [], 'paging' => []];
		$itemProperties = $item['properties'] ?? [];
		$primitive = [];
		foreach ($itemProperties as $name => $property) {
			$flat = $this->flatten($property, 3);
			if ($flat !== null && in_array($flat['type'], ['string', 'integer', 'number', 'boolean'], true)) {
				$primitive[] = (string)$name;
			}
		}
		$suggestion['id'] = self::first(self::ID_KEYS, $primitive);
		$suggestion['title'] = self::first(self::TITLE_KEYS, $primitive);
		$suggestion['link_field'] = self::first(self::LINK_KEYS, $primitive);
		foreach ($primitive as $name) {
			if (count($suggestion['fields']) >= self::MAX_FIELDS) {
				break;
			}
			if (in_array('/' . JsonPointer::escapeToken($name), [$suggestion['id'], $suggestion['title'], $suggestion['link_field']], true)) {
				continue;
			}
			$key = self::fieldKey($name);
			if ($key !== null) {
				$suggestion['fields'][] = ['key' => $key, 'pointer' => '/' . JsonPointer::escapeToken($name)];
			}
		}
		if ($records !== '') {
			$rootNames = array_map('strval', array_keys($properties));
			foreach (self::PAGING_KEYS as $kind => $candidates) {
				$pointer = self::first($candidates, $rootNames);
				if ($pointer !== null) {
					$suggestion['paging'][$kind] = $pointer;
				}
			}
		}
		return $suggestion;
	}

	/**
	 * Response schemas are documentation, not a complete validation contract.
	 *
	 * @param list<string> $stack References already being expanded
	 */
	public function usesComposition(mixed $schema, array $stack = []): bool {
		try {
			$schema = $this->refs->resolve($schema, $stack, $visited);
		} catch (OpenApiImportException $e) {
			if ($e->getErrorCode() === 'document_too_complex') {
				throw $e;
			}
			return false;
		}
		if (!is_array($schema)) {
			return false;
		}
		foreach (['oneOf', 'anyOf', 'allOf', 'not', 'discriminator'] as $keyword) {
			if (array_key_exists($keyword, $schema)) {
				return true;
			}
		}
		$stack = array_merge($stack, $visited);
		foreach (['items', 'additionalProperties'] as $keyword) {
			if ($this->usesComposition($schema[$keyword] ?? null, $stack)) {
				return true;
			}
		}
		foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $property) {
			if ($this->usesComposition($property, $stack)) {
				return true;
			}
		}
		return false;
	}

	public static function fieldKey(string $name): ?string {
		$key = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name) ?? $name);
		$key = trim(preg_replace('/[^a-z0-9]+/', '_', $key) ?? '', '_');
		if ($key === '' || !ctype_alpha($key[0])) {
			return null;
		}
		return substr($key, 0, 40);
	}

	/**
	 * @return array{type:?string,properties:array<string,mixed>,items:mixed}|null
	 */
	private function flatten(mixed $schema, int $depth): ?array {
		if ($depth > self::MAX_DEPTH) {
			return null;
		}
		try {
			$schema = $this->refs->resolve($schema);
		} catch (OpenApiImportException $e) {
			if ($e->getErrorCode() === 'document_too_complex') {
				throw $e;
			}
			return null;
		}
		if (!is_array($schema)) {
			return null;
		}
		$type = is_string($schema['type'] ?? null) ? $schema['type'] : null;
		$properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
		$items = $schema['items'] ?? null;
		if (isset($schema['allOf']) && is_array($schema['allOf'])) {
			foreach ($schema['allOf'] as $part) {
				$flat = $this->flatten($part, $depth + 1);
				if ($flat !== null) {
					$type ??= $flat['type'];
					$properties += $flat['properties'];
					$items ??= $flat['items'];
				}
			}
		}
		if ($type === null) {
			$type = $items !== null ? 'array' : ($properties !== [] ? 'object' : null);
		}
		return ['type' => $type, 'properties' => $properties, 'items' => $items];
	}

	/**
	 * @param list<string> $candidates
	 * @param list<string> $names
	 */
	private static function first(array $candidates, array $names): ?string {
		foreach ($candidates as $candidate) {
			if (in_array($candidate, $names, true)) {
				return '/' . JsonPointer::escapeToken($candidate);
			}
		}
		return null;
	}
}
