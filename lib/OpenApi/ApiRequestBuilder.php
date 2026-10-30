<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/**
 * Serializes validated arguments into a request URL below the approved base.
 * Supported: primitive path parameters (style simple) and primitive or array
 * query parameters (style form, explode true/false). Every value is encoded
 * per component, so no value can add path segments, a query, a fragment or
 * another host.
 */
final class ApiRequestBuilder {
	/**
	 * @param array<string,mixed> $operation Compiled manifest operation
	 * @param array{path:array<string,mixed>,query:array<string,mixed>} $arguments Output of ArgumentValidator::validate()
	 * @throws ApiExecutionException
	 */
	public function build(string $baseUrl, array $operation, array $arguments): string {
		$base = ApiUrl::parseBase($baseUrl, true);
		if ($base === null) {
			throw new ApiExecutionException('connection_base_invalid', 'The connection has no valid base address.');
		}
		$parameters = [];
		foreach ($operation['parameters'] as $parameter) {
			$parameters[$parameter['in']][$parameter['name']] = $parameter;
		}

		$path = preg_replace_callback('/\{([^{}\/]+)\}/', function (array $match) use ($arguments): string {
			$name = $match[1];
			if (!array_key_exists($name, $arguments['path'])) {
				throw new ApiExecutionException('invalid_arguments', 'A path parameter is missing.', ['parameter' => $name]);
			}
			$value = self::scalar($arguments['path'][$name]);
			if ($value === '' || $value === '.' || $value === '..') {
				throw new ApiExecutionException('invalid_arguments', 'A path parameter must not be empty or a dot segment.', ['parameter' => $name]);
			}
			return rawurlencode($value);
		}, $operation['path']);
		if (!is_string($path)) {
			throw new ApiExecutionException('request_build_failed', 'The request path could not be built.');
		}

		$pairs = [];
		foreach ($arguments['query'] as $name => $value) {
			$parameter = $parameters['query'][$name] ?? null;
			if ($parameter === null) {
				throw new ApiExecutionException('invalid_arguments', 'An unknown query parameter was supplied.', ['parameter' => (string)$name]);
			}
			$encodedName = rawurlencode((string)$name);
			if (!is_array($value)) {
				$pairs[] = $encodedName . '=' . ($value === null ? '' : rawurlencode(self::scalar($value)));
				continue;
			}
			if ($value === []) {
				$pairs[] = $encodedName . '=';
			} elseif ($parameter['explode']) {
				foreach ($value as $item) {
					$pairs[] = $encodedName . '=' . ($item === null ? '' : rawurlencode(self::scalar($item)));
				}
			} else {
				$pairs[] = $encodedName . '=' . implode(',', array_map(
					static fn ($item): string => $item === null ? '' : rawurlencode(self::scalar($item)),
					$value,
				));
			}
		}

		$url = $base['base'] . $path . ($pairs !== [] ? '?' . implode('&', $pairs) : '');
		if (strlen($url) > 8192) {
			throw new ApiExecutionException('request_too_large', 'The request address is too long.');
		}
		return $url;
	}

	private static function scalar(mixed $value): string {
		return match (true) {
			is_bool($value) => $value ? 'true' : 'false',
			is_int($value) => (string)$value,
			is_float($value) => self::number($value),
			is_string($value) => $value,
			default => throw new ApiExecutionException('invalid_arguments', 'A parameter value is not a primitive.'),
		};
	}

	private static function number(float $value): string {
		if (floor($value) === $value) {
			return number_format($value, 0, '.', '');
		}
		$shortest = json_encode($value, JSON_THROW_ON_ERROR);
		if (stripos($shortest, 'e') === false) {
			return $shortest;
		}
		return rtrim(rtrim(sprintf('%.20F', $value), '0'), '.');
	}
}
