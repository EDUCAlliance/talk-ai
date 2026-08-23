<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use JsonException;
use OCA\EducAI\Exception\ProviderContractException;
use stdClass;

final class ProviderResponseNormalizer {
	public const COMPATIBILITY_OFF = 'off';
	public const COMPATIBILITY_JSON = 'json';
	public const COMPATIBILITY_XML = 'xml';
	public const COMPATIBILITY_JSON_XML = 'json_xml';

	public const ARGUMENT_ERROR_INVALID_JSON = 'invalid_json';
	public const ARGUMENT_ERROR_NOT_OBJECT = 'not_object';
	public const ARGUMENT_ERROR_ENCODING_FAILED = 'encoding_failed';

	public function __construct(
		private ToolCallIdGenerator $idGenerator,
	) {
	}

	/**
	 * @param array<string,mixed> $response
	 * @param array<int,string> $knownToolNames
	 */
	public function normalize(array $response, array $knownToolNames, string $compatibilityMode = self::COMPATIBILITY_OFF): AgentTurn {
		$content = $response['content'] ?? null;
		$this->assertContentShape($content);
		$text = $content ?? '';
		$nativeToolCalls = $response['tool_calls'] ?? [];
		$this->assertNativeToolCallsShape($nativeToolCalls);
		$toolCalls = $this->normalizeNativeToolCalls($nativeToolCalls);
		$compatibilitySource = AgentTurn::COMPATIBILITY_NATIVE;

		if ($toolCalls === [] && trim($text) !== '') {
			$compatibilityMode = $this->normalizeCompatibilityMode($compatibilityMode);
			$compatibilityCalls = null;
			if ($compatibilityMode === self::COMPATIBILITY_JSON || $compatibilityMode === self::COMPATIBILITY_JSON_XML) {
				$compatibilityCalls = $this->parseLegacyJson($text, $knownToolNames);
				if ($compatibilityCalls !== null) {
					$compatibilitySource = AgentTurn::COMPATIBILITY_LEGACY_JSON;
				}
			}

			if (
				$compatibilityCalls === null
				&& ($compatibilityMode === self::COMPATIBILITY_XML || $compatibilityMode === self::COMPATIBILITY_JSON_XML)
			) {
				$compatibilityCalls = $this->parseLegacyXml($text, $knownToolNames);
				if ($compatibilityCalls !== null) {
					$compatibilitySource = AgentTurn::COMPATIBILITY_LEGACY_XML;
				}
			}

			if ($compatibilityCalls !== null) {
				$toolCalls = $compatibilityCalls;
				$text = '';
			}
		}

		return new AgentTurn(
			$text,
			$toolCalls,
			is_string($response['finish_reason'] ?? null) ? $response['finish_reason'] : null,
			is_string($response['model'] ?? null) ? $response['model'] : null,
			is_string($response['model_reference'] ?? null) ? $response['model_reference'] : null,
			is_string($response['model_endpoint'] ?? null) ? $response['model_endpoint'] : null,
			is_array($response['usage'] ?? null) ? $response['usage'] : null,
			is_array($response['rate_limit_headers'] ?? null) ? $response['rate_limit_headers'] : [],
			$compatibilitySource,
		);
	}

	public function assertContentShape(mixed $content): void {
		if ($content !== null && !is_string($content)) {
			throw new ProviderContractException();
		}
	}

	public function assertNativeToolCallsShape(mixed $toolCalls, bool $partial = false): void {
		if ($toolCalls === null) {
			return;
		}
		if (!is_array($toolCalls) || !array_is_list($toolCalls)) {
			throw new ProviderContractException();
		}

		foreach ($toolCalls as $toolCall) {
			if (!is_array($toolCall)) {
				throw new ProviderContractException();
			}
			if ($partial && array_key_exists('index', $toolCall)) {
				$index = $toolCall['index'];
				if (
					(!is_int($index) && !(is_string($index) && ctype_digit($index)))
					|| (int)$index < 0
				) {
					throw new ProviderContractException();
				}
			}
			if (array_key_exists('id', $toolCall) && !is_string($toolCall['id'])) {
				throw new ProviderContractException();
			}
			if (array_key_exists('type', $toolCall) && $toolCall['type'] !== 'function') {
				throw new ProviderContractException();
			}

			$hasFunction = array_key_exists('function', $toolCall);
			if (!$hasFunction) {
				continue;
			}
			if (!is_array($toolCall['function'])) {
				throw new ProviderContractException();
			}

			$function = $toolCall['function'];
			if (array_key_exists('name', $function) && !is_string($function['name'])) {
				throw new ProviderContractException();
			}
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $toolCalls
	 * @return array<int,array<string,mixed>>
	 */
	private function normalizeNativeToolCalls(array $toolCalls): array {
		$normalized = [];
		foreach ($toolCalls as $toolCall) {
			$normalized[] = $this->normalizeToolCall($toolCall);
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $toolCall
	 * @return array<string,mixed>
	 */
	private function normalizeToolCall(array $toolCall): array {
		$function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
		$id = is_string($toolCall['id'] ?? null) ? $toolCall['id'] : '';
		if (trim($id) === '') {
			$id = $this->idGenerator->generate();
		}

		$hasArguments = array_key_exists('arguments', $function);
		[$arguments, $argumentError] = $this->normalizeArguments(
			$hasArguments ? $function['arguments'] : null,
			$hasArguments,
		);

		$normalized = [
			'id' => $id,
			'type' => 'function',
			'function' => [
				'name' => is_string($function['name'] ?? null) ? $function['name'] : '',
				'arguments' => $arguments,
			],
		];
		if ($argumentError !== null) {
			$normalized['argument_error'] = $argumentError;
		}

		return $normalized;
	}

	/**
	 * @return array{0:string,1:?string}
	 */
	private function normalizeArguments(mixed $arguments, bool $present): array {
		if (!$present) {
			return ['{}', null];
		}

		if (is_string($arguments)) {
			if (trim($arguments) === '') {
				return [$arguments, self::ARGUMENT_ERROR_INVALID_JSON];
			}

			try {
				json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
			} catch (JsonException) {
				return [$arguments, self::ARGUMENT_ERROR_INVALID_JSON];
			}

			return [
				$arguments,
				str_starts_with(ltrim($arguments), '{') ? null : self::ARGUMENT_ERROR_NOT_OBJECT,
			];
		}

		$encoded = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			return ['', self::ARGUMENT_ERROR_ENCODING_FAILED];
		}

		$isObject = $arguments instanceof stdClass || (is_array($arguments) && !array_is_list($arguments));
		return [$encoded, $isObject ? null : self::ARGUMENT_ERROR_NOT_OBJECT];
	}

	private function normalizeCompatibilityMode(string $mode): string {
		return in_array($mode, [
			self::COMPATIBILITY_OFF,
			self::COMPATIBILITY_JSON,
			self::COMPATIBILITY_XML,
			self::COMPATIBILITY_JSON_XML,
		], true) ? $mode : self::COMPATIBILITY_OFF;
	}

	/**
	 * @param array<int,string> $knownToolNames
	 * @return array<int,array<string,mixed>>|null
	 */
	private function parseLegacyJson(string $content, array $knownToolNames): ?array {
		try {
			$decoded = json_decode(trim($content), false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}
		$entries = $this->legacyJsonEntries($decoded);
		if ($entries === null) {
			return null;
		}

		return $this->normalizeCompatibilityEntries($entries, $knownToolNames);
	}

	/**
	 * @param array<int,string> $knownToolNames
	 * @return array<int,array<string,mixed>>|null
	 */
	private function parseLegacyXml(string $content, array $knownToolNames): ?array {
		$trimmed = trim($content);
		if (preg_match(
			'/^<((?:[a-z0-9_-]+:)?(?:tool_call|function_call))\s*>(.*)<\/\1>$/is',
			$trimmed,
			$matches,
		) !== 1) {
			return null;
		}

		$inner = trim($matches[2]);
		if ($inner === '') {
			return null;
		}

		try {
			$decoded = json_decode($inner, false, 512, JSON_THROW_ON_ERROR);
			$entries = $this->legacyJsonEntries($decoded);
			if ($entries !== null) {
				return $this->normalizeCompatibilityEntries($entries, $knownToolNames);
			}
		} catch (JsonException) {
		}

		if (preg_match(
			'/^\s*<name>(.*?)<\/name>\s*(?:<(arguments|parameters)>(.*?)<\/\2>)?\s*$/is',
			$inner,
			$parts,
		) === 1) {
			$name = html_entity_decode(trim($parts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
			$entry = ['name' => $name];
			if (isset($parts[2]) && $parts[2] !== '') {
				$entry['arguments'] = html_entity_decode($parts[3] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
			}

			return $this->normalizeCompatibilityEntries([$entry], $knownToolNames);
		}

		$invokeEntries = $this->parseLegacyInvokeEntries($inner);
		if ($invokeEntries !== null) {
			return $this->normalizeCompatibilityEntries($invokeEntries, $knownToolNames);
		}

		$argumentPairEntry = $this->parseLegacyArgumentPairEntry($inner);
		return $argumentPairEntry === null
			? null
			: $this->normalizeCompatibilityEntries([$argumentPairEntry], $knownToolNames);
	}

	/**
	 * @return array<int,array<string,mixed>>|null
	 */
	private function legacyJsonEntries(mixed $decoded): ?array {
		$values = $decoded instanceof stdClass ? [$decoded] : $decoded;
		if (!is_array($values) || $values === []) {
			return null;
		}

		$entries = [];
		foreach ($values as $value) {
			if (!$value instanceof stdClass) {
				return null;
			}
			$entry = get_object_vars($value);
			if (array_key_exists('function', $entry)) {
				if (!$entry['function'] instanceof stdClass) {
					return null;
				}
				$entry['function'] = get_object_vars($entry['function']);
			}
			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * @return array<int,array<string,mixed>>|null
	 */
	private function parseLegacyInvokeEntries(string $inner): ?array {
		$pattern = '/<invoke\s+name=(["\'])(.*?)\1\s*>(.*?)<\/invoke>/is';
		if (preg_match_all($pattern, $inner, $matches, PREG_SET_ORDER) < 1) {
			return null;
		}
		if (trim((string)preg_replace($pattern, '', $inner)) !== '') {
			return null;
		}

		$entries = [];
		foreach ($matches as $match) {
			$name = html_entity_decode(trim($match[2] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
			$parameterContent = $match[3] ?? '';
			$parameterPattern = '/<parameter\s+name=(["\'])(.*?)\1\s*>(.*?)<\/parameter>/is';
			$arguments = [];
			if (trim($parameterContent) !== '') {
				if (preg_match_all($parameterPattern, $parameterContent, $parameters, PREG_SET_ORDER) < 1) {
					return null;
				}
				if (trim((string)preg_replace($parameterPattern, '', $parameterContent)) !== '') {
					return null;
				}

				foreach ($parameters as $parameter) {
					$key = html_entity_decode(trim($parameter[2] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
					if ($key === '') {
						return null;
					}
					$rawValue = html_entity_decode(trim($parameter[3] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
					$arguments[$key] = $this->decodeLegacyXmlValue($rawValue);
				}
			}

			$entry = ['name' => $name];
			if ($arguments !== []) {
				$entry['arguments'] = $arguments;
			}
			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function parseLegacyArgumentPairEntry(string $inner): ?array {
		if (preg_match('/^\s*([^\s<]+)\s*(.*)$/s', $inner, $parts) !== 1) {
			return null;
		}

		$name = html_entity_decode(trim($parts[1] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
		$argumentContent = $parts[2] ?? '';
		$argumentPattern = '/<arg_key>(.*?)<\/arg_key>\s*<arg_value>(.*?)<\/arg_value>/is';
		$arguments = [];
		if (trim($argumentContent) !== '') {
			if (preg_match_all($argumentPattern, $argumentContent, $pairs, PREG_SET_ORDER) < 1) {
				return null;
			}
			if (trim((string)preg_replace($argumentPattern, '', $argumentContent)) !== '') {
				return null;
			}

			foreach ($pairs as $pair) {
				$key = html_entity_decode(trim($pair[1] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
				if ($key === '') {
					return null;
				}
				$rawValue = html_entity_decode(trim($pair[2] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
				$arguments[$key] = $this->decodeLegacyXmlValue($rawValue);
			}
		}

		$entry = ['name' => $name];
		if ($arguments !== []) {
			$entry['arguments'] = $arguments;
		}
		return $entry;
	}

	private function decodeLegacyXmlValue(string $value): mixed {
		try {
			return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return $value;
		}
	}

	/**
	 * @param array<int,mixed> $entries
	 * @param array<int,string> $knownToolNames
	 * @return array<int,array<string,mixed>>|null
	 */
	private function normalizeCompatibilityEntries(array $entries, array $knownToolNames): ?array {
		$known = array_fill_keys(array_values(array_filter(
			$knownToolNames,
			static fn (mixed $name): bool => is_string($name) && trim($name) !== '',
		)), true);
		$normalized = [];
		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				return null;
			}

			$hasFunction = array_key_exists('function', $entry);
			if ($hasFunction) {
				if (!is_array($entry['function']) || array_diff(array_keys($entry), ['id', 'type', 'function']) !== []) {
					return null;
				}
				$function = $entry['function'];
				if (array_diff(array_keys($function), ['name', 'arguments']) !== []) {
					return null;
				}
				$name = $function['name'] ?? null;
			} else {
				if (
					array_diff(array_keys($entry), ['id', 'type', 'name', 'tool', 'arguments', 'parameters']) !== []
					|| (array_key_exists('name', $entry) && array_key_exists('tool', $entry))
					|| (array_key_exists('arguments', $entry) && array_key_exists('parameters', $entry))
				) {
					return null;
				}
				$function = [];
				$name = $entry['name'] ?? $entry['tool'] ?? null;
			}

			if (array_key_exists('type', $entry) && $entry['type'] !== 'function') {
				return null;
			}
			if (!is_string($name) || !isset($known[$name])) {
				return null;
			}

			$toolCall = [
				'id' => $entry['id'] ?? null,
				'function' => ['name' => $name],
			];
			if (array_key_exists('arguments', $entry)) {
				$toolCall['function']['arguments'] = $entry['arguments'];
			} elseif (array_key_exists('parameters', $entry)) {
				$toolCall['function']['arguments'] = $entry['parameters'];
			} elseif (array_key_exists('arguments', $function)) {
				$toolCall['function']['arguments'] = $function['arguments'];
			}

			$normalized[] = $this->normalizeToolCall($toolCall);
		}

		return $normalized === [] ? null : $normalized;
	}
}
