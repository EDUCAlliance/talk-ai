<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

/**
 * Normalizes model-generated tool arguments before execution.
 */
class ToolArgumentNormalizer {
	/**
	 * @param array<string,mixed> $arguments
	 * @param array<string,mixed>|null $toolContext
	 * @return array<string,mixed>
	 */
	public function filterToSchema(array $arguments, ?array $toolContext): array {
		if ($toolContext === null) {
			return $arguments;
		}
		$schema = $toolContext['definition']['function']['parameters'] ?? null;
		if ($schema instanceof \stdClass) {
			$schema = (array)$schema;
		}
		if (!is_array($schema)) {
			return $arguments;
		}
		$properties = $schema['properties'] ?? null;
		if ($properties instanceof \stdClass) {
			$properties = (array)$properties;
		}
		if (!is_array($properties) || count($properties) === 0) {
			return $arguments;
		}
		$allowed = array_keys($properties);
		$filtered = [];
		foreach ($arguments as $key => $value) {
			if (in_array($key, $allowed, true)) {
				$filtered[$key] = $value;
			}
		}
		return $filtered;
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @param array<string,mixed>|null $toolContext
	 * @return array<string,mixed>
	 */
	public function coerceToSchema(array $arguments, ?array $toolContext): array {
		$schema = $this->schemaFromToolContext($toolContext);
		$properties = $this->propertiesFromSchema($schema);
		if ($properties === []) {
			return $arguments;
		}

		foreach ($arguments as $key => $value) {
			$propertySchema = $properties[$key] ?? null;
			if ($propertySchema instanceof \stdClass) {
				$propertySchema = (array)$propertySchema;
			}
			if (!is_array($propertySchema)) {
				continue;
			}
			$arguments[$key] = $this->coerceValue($value, $propertySchema);
		}

		return $arguments;
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @param array<string,mixed>|null $toolContext
	 * @return list<string>
	 */
	public function missingRequiredArguments(array $arguments, ?array $toolContext): array {
		$schema = $this->schemaFromToolContext($toolContext);
		$required = $schema['required'] ?? [];
		if (!is_array($required)) {
			return [];
		}

		$missing = [];
		foreach ($required as $key) {
			if (!is_string($key) || $key === '') {
				continue;
			}
			if (!array_key_exists($key, $arguments) || $arguments[$key] === null || (is_string($arguments[$key]) && trim($arguments[$key]) === '')) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * @param array<string,mixed>|null $toolContext
	 * @return array<string,mixed>
	 */
	private function schemaFromToolContext(?array $toolContext): array {
		$schema = $toolContext['definition']['function']['parameters'] ?? null;
		if ($schema instanceof \stdClass) {
			$schema = (array)$schema;
		}
		return is_array($schema) ? $schema : [];
	}

	/**
	 * @param array<string,mixed> $schema
	 * @return array<string,mixed>
	 */
	private function propertiesFromSchema(array $schema): array {
		$properties = $schema['properties'] ?? [];
		if ($properties instanceof \stdClass) {
			$properties = (array)$properties;
		}
		return is_array($properties) ? $properties : [];
	}

	/**
	 * @param mixed $value
	 * @param array<string,mixed> $schema
	 * @return mixed
	 */
	private function coerceValue(mixed $value, array $schema): mixed {
		$type = $schema['type'] ?? null;
		if (is_array($type)) {
			if ($value === null || (is_string($value) && strtolower(trim($value)) === 'null')) {
				return in_array('null', $type, true) ? null : $value;
			}
			$nonNullTypes = array_values(array_filter($type, static fn($item): bool => $item !== 'null'));
			if (in_array('string', $nonNullTypes, true)) {
				return $value;
			}
			$type = $nonNullTypes[0] ?? null;
		}

		if ($type === 'array') {
			if (is_array($value)) {
				return $value;
			}
			if (is_string($value)) {
				$decoded = json_decode($value, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
					return $decoded;
				}
			}
			return [$value];
		}

		if ($type === 'object') {
			if (is_string($value)) {
				$decoded = json_decode($value, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && !array_is_list($decoded)) {
					return $decoded;
				}
			}
			return $value;
		}

		if (!is_string($value)) {
			return $value;
		}
		$trimmed = trim($value);

		if ($type === 'integer' && preg_match('/^-?\d+$/', $trimmed) === 1) {
			return (int)$trimmed;
		}
		if ($type === 'number' && is_numeric($trimmed)) {
			return (float)$trimmed;
		}
		if ($type === 'boolean') {
			$lower = strtolower($trimmed);
			if (in_array($lower, ['true', '1'], true)) {
				return true;
			}
			if (in_array($lower, ['false', '0'], true)) {
				return false;
			}
		}

		return $value;
	}
}
