<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Db\ApiConnection;

/**
 * The admin's per-operation decision and the rules it must satisfy. The same
 * check runs on approval and again before every call, so a later change to
 * the connection (base URL, authentication) cannot widen an approval.
 *
 * Stored per operation ID in the revision:
 *   enabled, read_confirmed, label, description (model-facing, reviewed),
 *   auth ("none" | "anonymous" | "scheme:<name>"),
 *   fixed {query: {...}}, mapping (see ApiResultProjector::normalizeMapping),
 *   confirmed_by, confirmed_at, revoked_at, revoked_by
 */
final class ApiOperationReview {
	public const MAX_LABEL = 80;
	public const MAX_DESCRIPTION = 600;
	public const AUTH_NONE = 'none';
	public const AUTH_ANONYMOUS = 'anonymous';

	/**
	 * Normalizes an admin edit for one operation. Server-owned fields are kept
	 * from the previous state.
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $previous
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException with the reason code as message
	 */
	public static function normalizeEdit(array $input, array $previous, array $operation, string $userId, int $now): array {
		$config = $previous;
		if (array_key_exists('enabled', $input)) {
			$config['enabled'] = $input['enabled'] === true;
		}
		if (array_key_exists('read_confirmed', $input)) {
			$confirmed = $input['read_confirmed'] === true;
			if ($confirmed && ($previous['read_confirmed'] ?? false) !== true) {
				$config['confirmed_by'] = $userId;
				$config['confirmed_at'] = $now;
			}
			$config['read_confirmed'] = $confirmed;
		}
		if (array_key_exists('label', $input)) {
			if (!is_string($input['label'])) {
				throw new \InvalidArgumentException('label_invalid');
			}
			$config['label'] = ParameterSchemaCompiler::text($input['label'], self::MAX_LABEL);
		}
		if (array_key_exists('description', $input)) {
			if (!is_string($input['description'])) {
				throw new \InvalidArgumentException('description_invalid');
			}
			$config['description'] = ParameterSchemaCompiler::text($input['description'], self::MAX_DESCRIPTION);
		}
		if (array_key_exists('auth', $input)) {
			if (!is_string($input['auth']) || $input['auth'] === '' || strlen($input['auth']) > 200) {
				throw new \InvalidArgumentException('auth_choice_invalid');
			}
			$config['auth'] = $input['auth'];
		}
		if (array_key_exists('fixed', $input)) {
			$config['fixed'] = self::normalizeFixed($input['fixed'], $operation);
		}
		if (array_key_exists('mapping', $input)) {
			if (!is_array($input['mapping'])) {
				throw new \InvalidArgumentException('mapping_invalid');
			}
			$config['mapping'] = ApiResultProjector::normalizeMapping($input['mapping']);
		}
		return $config;
	}

	/**
	 * Draft defaults: nothing enabled, no authentication chosen.
	 *
	 * @param array<string,mixed> $operation
	 * @return array<string,mixed>
	 */
	public static function defaults(array $operation): array {
		$label = $operation['summary'] !== '' ? $operation['summary'] : ($operation['operation_id'] ?? $operation['match_key']);
		$description = trim($operation['summary'] . ($operation['description'] !== '' && $operation['description'] !== $operation['summary'] ? ' ' . $operation['description'] : ''));
		return [
			'enabled' => false,
			'read_confirmed' => false,
			'label' => ParameterSchemaCompiler::text((string)$label, self::MAX_LABEL),
			'description' => ParameterSchemaCompiler::text($description, self::MAX_DESCRIPTION),
			'auth' => null,
			'fixed' => ['query' => []],
			'mapping' => ApiResultProjector::mappingFromSuggestion($operation['response']['suggestion'] ?? null),
		];
	}

	/**
	 * Reasons why the operation cannot be offered or called; empty when it may.
	 *
	 * @param array<string,mixed> $operation Manifest entry
	 * @param array<string,mixed> $config Review state
	 * @return list<string>
	 */
	public static function problems(array $operation, array $config, ApiConnection $connection, bool $credentialPresent): array {
		$problems = [];
		if (($operation['supported'] ?? false) !== true || ($operation['method'] ?? null) !== 'GET') {
			$problems[] = 'operation_not_supported';
		}
		if (($config['enabled'] ?? false) !== true) {
			$problems[] = 'operation_not_enabled';
		}
		if (($config['read_confirmed'] ?? false) !== true) {
			$problems[] = 'read_not_confirmed';
		}
		if (isset($config['revoked_at'])) {
			$problems[] = 'operation_revoked';
		}
		if (!is_string($config['label'] ?? null) || trim($config['label']) === '') {
			$problems[] = 'label_missing';
		}

		$base = $connection->getBaseUrl();
		if ($base === null || $base === '') {
			$problems[] = 'base_url_missing';
		} elseif (($operation['servers']['source'] ?? 'root') !== 'root') {
			$resolved = array_column($operation['servers']['urls'] ?? [], 'resolved');
			if (!in_array($base, $resolved, true)) {
				$problems[] = 'server_override_not_approved';
			}
		}

		$alternative = self::chosenAlternative($operation, $config['auth'] ?? null);
		if ($alternative === null) {
			$problems[] = 'auth_not_chosen';
		} elseif ($alternative['mode'] === 'none') {
			// Anonymous calls never carry the connection credential.
		} elseif ($alternative['mode'] !== $connection->getAuthMode()) {
			$problems[] = 'auth_mode_mismatch';
		} elseif ($alternative['mode'] === ApiConnection::AUTH_API_KEY_HEADER
			&& strcasecmp((string)$alternative['header'], (string)$connection->getAuthHeader()) !== 0) {
			$problems[] = 'auth_header_mismatch';
		} elseif (!$credentialPresent) {
			$problems[] = 'credential_missing';
		}

		try {
			self::normalizeFixed($config['fixed'] ?? [], $operation);
		} catch (\InvalidArgumentException $e) {
			$problems[] = $e->getMessage();
		}
		try {
			ApiResultProjector::normalizeMapping(is_array($config['mapping'] ?? null) ? $config['mapping'] : []);
		} catch (\InvalidArgumentException $e) {
			$problems[] = $e->getMessage();
		}
		return array_values(array_unique($problems));
	}

	/**
	 * The security alternative chosen by the admin. "none" is only valid when
	 * the operation declares no security at all; a declared `{}` must be chosen
	 * explicitly as "anonymous". There is never a fallback between alternatives.
	 *
	 * @param array<string,mixed> $operation
	 * @return array<string,mixed>|null
	 */
	public static function chosenAlternative(array $operation, mixed $choice): ?array {
		if (!is_string($choice)) {
			return null;
		}
		$declared = ($operation['security']['declared'] ?? false) === true;
		$alternatives = $operation['security']['alternatives'] ?? [];
		if (!$declared || $alternatives === []) {
			// "security: []" or none declared: the operation is public by its own description.
			return $choice === self::AUTH_NONE ? ['key' => self::AUTH_NONE, 'mode' => 'none', 'header' => null] : null;
		}
		foreach ($alternatives as $alternative) {
			if (($alternative['key'] ?? null) === $choice && ($alternative['supported'] ?? false) === true) {
				return $alternative;
			}
		}
		return null;
	}

	/**
	 * Server-fixed query values the model cannot see or override.
	 *
	 * @param array<string,mixed> $operation
	 * @return array{query:array<string,mixed>}
	 * @throws \InvalidArgumentException with the reason code as message
	 */
	public static function normalizeFixed(mixed $fixed, array $operation): array {
		if (!is_array($fixed) || array_diff(array_keys($fixed), ['query']) !== []) {
			throw new \InvalidArgumentException('fixed_invalid');
		}
		$query = $fixed['query'] ?? [];
		if (!is_array($query) || ($query !== [] && array_is_list($query)) || count($query) > 16) {
			throw new \InvalidArgumentException('fixed_invalid');
		}
		if ($query === []) {
			return ['query' => []];
		}
		$known = [];
		foreach ($operation['parameters'] ?? [] as $parameter) {
			if ($parameter['in'] === 'query') {
				$known[$parameter['name']] = true;
			}
		}
		foreach (array_keys($query) as $name) {
			if (!isset($known[(string)$name])) {
				throw new \InvalidArgumentException('fixed_parameter_unknown');
			}
		}
		try {
			$validated = (new ArgumentValidator())->validateFixed($operation, ['query' => $query]);
		} catch (ApiExecutionException) {
			throw new \InvalidArgumentException('fixed_value_invalid');
		}
		return ['query' => $validated['query']];
	}

	/**
	 * "in:name" keys hidden from the model.
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,bool>
	 */
	public static function fixedKeys(array $config): array {
		$keys = [];
		foreach (array_keys($config['fixed']['query'] ?? []) as $name) {
			$keys['query:' . $name] = true;
		}
		return $keys;
	}
}
