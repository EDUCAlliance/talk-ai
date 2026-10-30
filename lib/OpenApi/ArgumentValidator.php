<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/**
 * Recursive validation of model arguments against a compiled operation,
 * directly before a request is built. Only lossless coercions are applied
 * (canonical numeric strings, "true"/"false"); everything else is an error.
 */
final class ArgumentValidator {
	public const MAX_STRING_LENGTH = 2048;
	public const MAX_ARRAY_ITEMS = 100;
	private const MAX_REPORTED_ERRORS = 5;

	/**
	 * @param array<string,mixed> $arguments Decoded tool arguments
	 * @param array<string,mixed> $operation Compiled manifest operation
	 * @param array<string,array<string,mixed>> $fixed Server-fixed values by location and name
	 * @return array{path:array<string,mixed>,query:array<string,mixed>}
	 * @throws ApiExecutionException
	 */
	public function validate(array $arguments, array $operation, array $fixed = []): array {
		$errors = [];
		foreach (array_keys($arguments) as $key) {
			if ($key !== 'path' && $key !== 'query') {
				$errors[] = '/' . self::token((string)$key) . ': unknown_argument_group';
			}
		}

		$result = ['path' => [], 'query' => []];
		foreach (['path', 'query'] as $in) {
			$group = $arguments[$in] ?? [];
			if (!is_array($group) || ($group !== [] && array_is_list($group))) {
				$errors[] = '/' . $in . ': expected_object';
				continue;
			}
			$known = [];
			foreach ($operation['parameters'] as $parameter) {
				if ($parameter['in'] === $in) {
					$known[$parameter['name']] = $parameter;
				}
			}
			foreach ($group as $name => $value) {
				$name = (string)$name;
				$pointer = '/' . $in . '/' . self::token($name);
				if (!isset($known[$name])) {
					$errors[] = $pointer . ': unknown_parameter';
				} elseif (array_key_exists($name, $fixed[$in] ?? [])) {
					$errors[] = $pointer . ': parameter_fixed';
				} else {
					$normalized = $this->value($value, $known[$name]['schema'], $pointer, $errors);
					if ($normalized !== null || $value === null) {
						$result[$in][$name] = $normalized;
					}
				}
			}
			foreach ($known as $name => $parameter) {
				if (array_key_exists($name, $fixed[$in] ?? [])) {
					$result[$in][$name] = $this->value($fixed[$in][$name], $parameter['schema'], '/' . $in . '/' . self::token($name), $errors);
				} elseif ($parameter['required'] && !array_key_exists($name, $group)) {
					$errors[] = '/' . $in . '/' . self::token($name) . ': required';
				}
			}
		}

		if ($errors !== []) {
			throw new ApiExecutionException(
				'invalid_arguments',
				'The tool arguments do not match the operation: ' . implode('; ', array_slice($errors, 0, self::MAX_REPORTED_ERRORS)) . '.',
				['error_count' => count($errors)],
			);
		}
		$ordered = ['path' => [], 'query' => []];
		foreach ($operation['parameters'] as $parameter) {
			if (array_key_exists($parameter['name'], $result[$parameter['in']])) {
				$ordered[$parameter['in']][$parameter['name']] = $result[$parameter['in']][$parameter['name']];
			}
		}
		return $ordered;
	}

	/**
	 * Validates admin-fixed values on their own, without required checks.
	 *
	 * @param array<string,mixed> $operation
	 * @param array<string,array<string,mixed>> $fixed
	 * @return array{path:array<string,mixed>,query:array<string,mixed>}
	 * @throws ApiExecutionException
	 */
	public function validateFixed(array $operation, array $fixed): array {
		$errors = [];
		$result = ['path' => [], 'query' => []];
		foreach ($operation['parameters'] as $parameter) {
			if (array_key_exists($parameter['name'], $fixed[$parameter['in']] ?? [])) {
				$result[$parameter['in']][$parameter['name']] = $this->value(
					$fixed[$parameter['in']][$parameter['name']],
					$parameter['schema'],
					'/' . $parameter['in'] . '/' . self::token($parameter['name']),
					$errors,
				);
			}
		}
		if ($errors !== []) {
			throw new ApiExecutionException('invalid_fixed_values', 'Fixed values do not match the operation: ' . implode('; ', array_slice($errors, 0, self::MAX_REPORTED_ERRORS)) . '.');
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $schema
	 * @param list<string> $errors
	 */
	private function value(mixed $value, array $schema, string $pointer, array &$errors): mixed {
		if ($value === null) {
			if (($schema['nullable'] ?? false) !== true) {
				$errors[] = $pointer . ': null_not_allowed';
			}
			return null;
		}
		$type = $schema['type'];
		if ($type === 'array') {
			if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
				$errors[] = $pointer . ': expected_array';
				return null;
			}
			if (count($value) > self::MAX_ARRAY_ITEMS) {
				$errors[] = $pointer . ': too_many_items';
				return null;
			}
			if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
				$errors[] = $pointer . ': min_items';
			}
			if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
				$errors[] = $pointer . ': max_items';
			}
			$items = [];
			foreach ($value as $index => $item) {
				$normalized = $this->value($item, $schema['items'], $pointer . '/' . $index, $errors);
				if ($normalized === null && $item !== null) {
					return null;
				}
				$items[] = $normalized;
			}
			if (($schema['uniqueItems'] ?? false) === true
				&& count(array_unique(array_map(static fn ($item): string => json_encode($item, JSON_THROW_ON_ERROR), $items))) !== count($items)) {
				$errors[] = $pointer . ': unique_items';
			}
			return $items;
		}

		$normalized = self::coerce($value, $type);
		if ($normalized === null) {
			$errors[] = $pointer . ': expected_' . $type;
			return null;
		}
		if (isset($schema['enum']) && !self::inEnum($normalized, $schema['enum'], $type)) {
			$errors[] = $pointer . ': not_in_enum';
			return null;
		}
		if ($type === 'string') {
			$length = mb_strlen($normalized, 'UTF-8');
			if ($length > self::MAX_STRING_LENGTH) {
				$errors[] = $pointer . ': too_long';
			}
			if (isset($schema['minLength']) && $length < $schema['minLength']) {
				$errors[] = $pointer . ': min_length';
			}
			if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
				$errors[] = $pointer . ': max_length';
			}
			if (isset($schema['format']) && !self::matchesFormat($normalized, $schema['format'])) {
				$errors[] = $pointer . ': format_' . $schema['format'];
			}
		}
		if ($type === 'integer' || $type === 'number') {
			$this->bounds($normalized, $schema, $pointer, $errors);
		}
		return $normalized;
	}

	/**
	 * @param array<string,mixed> $schema
	 * @param list<string> $errors
	 */
	private function bounds(int|float $value, array $schema, string $pointer, array &$errors): void {
		if (($schema['format'] ?? null) === 'int32' && ($value < -2147483648 || $value > 2147483647)) {
			$errors[] = $pointer . ': format_int32';
		}
		if (isset($schema['minimum'])) {
			$exclusive = ($schema['exclusiveMinimum'] ?? false) === true;
			if ($exclusive ? $value <= $schema['minimum'] : $value < $schema['minimum']) {
				$errors[] = $pointer . ': minimum';
			}
		}
		if (isset($schema['maximum'])) {
			$exclusive = ($schema['exclusiveMaximum'] ?? false) === true;
			if ($exclusive ? $value >= $schema['maximum'] : $value > $schema['maximum']) {
				$errors[] = $pointer . ': maximum';
			}
		}
		if (isset($schema['multipleOf'])) {
			$quotient = $value / $schema['multipleOf'];
			if (abs($quotient - round($quotient)) > 1e-9 * max(1.0, abs($quotient))) {
				$errors[] = $pointer . ': multiple_of';
			}
		}
	}

	private static function coerce(mixed $value, string $type): string|int|float|bool|null {
		return match ($type) {
			'string' => is_string($value) ? $value : null,
			'boolean' => is_bool($value) ? $value : ($value === 'true' ? true : ($value === 'false' ? false : null)),
			'integer' => match (true) {
				is_int($value) => $value,
				is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 9007199254740991 => (int)$value,
				is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]{0,17})$/D', $value) === 1 => (int)$value,
				default => null,
			},
			'number' => match (true) {
				is_int($value) => $value,
				is_float($value) && is_finite($value) => $value,
				is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value) === 1 && is_finite((float)$value) => (float)$value,
				default => null,
			},
			default => null,
		};
	}

	/** @param list<mixed> $enum */
	private static function inEnum(string|int|float|bool $value, array $enum, string $type): bool {
		foreach ($enum as $candidate) {
			if ($type === 'number' && (is_int($candidate) || is_float($candidate)) && (float)$candidate === (float)$value) {
				return true;
			}
			if ($candidate === $value) {
				return true;
			}
		}
		return false;
	}

	private static function matchesFormat(string $value, string $format): bool {
		return match ($format) {
			'date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]),
			'date-time' => preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:[Zz]|[+-](\d{2}):(\d{2}))$/D', $value, $m) === 1
				&& checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[4] < 24 && (int)$m[5] < 60 && (int)$m[6] < 61
				&& (!isset($m[7]) || ((int)$m[7] < 24 && (int)$m[8] < 60)),
			'uuid' => preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $value) === 1,
			default => false,
		};
	}

	private static function token(string $name): string {
		$name = preg_replace('/[^A-Za-z0-9_.\-]/', '?', $name) ?? '';
		return mb_substr($name, 0, 64);
	}
}
