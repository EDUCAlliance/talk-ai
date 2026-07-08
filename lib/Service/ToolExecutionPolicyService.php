<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

/**
 * Centralizes agent safety and loop-budget decisions for tools.
 */
class ToolExecutionPolicyService {
	public const KIND_SEARCH = 'search';
	public const KIND_READ = 'read';
	public const KIND_WRITE = 'write';
	public const KIND_ANALYZE = 'analyze';
	public const KIND_TRANSCRIBE = 'transcribe';
	public const KIND_UNKNOWN = 'unknown';

	public const MUTATING_TOOL_LOOP_THRESHOLD = 4;
	public const GENERAL_TOOL_LOOP_THRESHOLD = 8;
	public const SEARCH_TOOL_LOOP_THRESHOLD = 10;

	private const MUTATING_TOKENS = [
		'add',
		'append',
		'commit',
		'create',
		'delete',
		'deploy',
		'disable',
		'enable',
		'execute',
		'install',
		'log',
		'merge',
		'move',
		'patch',
		'post',
		'push',
		'put',
		'remove',
		'rename',
		'run',
		'save',
		'send',
		'set',
		'store',
		'uninstall',
		'update',
		'upload',
		'write',
	];

	private const FORCED_SEARCH_UNSAFE_TERMS = [
		'create',
		'update',
		'delete',
		'remove',
		'write',
		'insert',
		'post',
		'put',
		'patch',
		'destroy',
		'extract',
		'crawl',
		'scrape',
		'fetch',
		'map',
		'analyze',
		'transcribe',
		'read',
		'log',
	];

	/**
	 * @return array<string,mixed>
	 */
	public function builtInPolicy(string $toolName): array {
		return match ($toolName) {
			BuiltInToolProvider::TOOL_RAG_SEARCH,
			BuiltInToolProvider::TOOL_ROOM_SEARCH,
			BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH,
			BuiltInToolProvider::TOOL_WIKI_SEARCH => $this->policy(self::KIND_SEARCH, true, true, false, self::SEARCH_TOOL_LOOP_THRESHOLD, 'builtin'),

			BuiltInToolProvider::TOOL_WIKI_READ_PAGE => $this->policy(self::KIND_READ, true, true, false, self::GENERAL_TOOL_LOOP_THRESHOLD, 'builtin'),

			BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE => $this->policy(self::KIND_ANALYZE, true, true, false, self::GENERAL_TOOL_LOOP_THRESHOLD, 'builtin'),
			BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO => $this->policy(self::KIND_TRANSCRIBE, true, true, false, self::GENERAL_TOOL_LOOP_THRESHOLD, 'builtin'),

			BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
			BuiltInToolProvider::TOOL_WIKI_LOG_EVENT => $this->policy(self::KIND_WRITE, false, false, false, self::MUTATING_TOOL_LOOP_THRESHOLD, 'builtin'),

			default => $this->inferPolicy($toolName, $toolName, '', [], 'builtin_fallback'),
		};
	}

	/**
	 * Read-only search policy for tools contributed by external tool providers.
	 *
	 * @return array<string,mixed>
	 */
	public function searchToolPolicy(string $source = 'provider'): array {
		return $this->policy(self::KIND_SEARCH, true, true, false, self::SEARCH_TOOL_LOOP_THRESHOLD, $source);
	}

	/**
	 * Read-only lookup policy for tools contributed by external tool providers.
	 *
	 * @return array<string,mixed>
	 */
	public function readToolPolicy(string $source = 'provider'): array {
		return $this->policy(self::KIND_READ, true, true, false, self::GENERAL_TOOL_LOOP_THRESHOLD, $source);
	}

	/**
	 * @param array<string,mixed> $annotations
	 * @return array<string,mixed>
	 */
	public function mcpPolicy(string $exposedName, string $invokeName, string $description, array $annotations = []): array {
		return $this->inferPolicy($exposedName, $invokeName, $description, $annotations, 'mcp_heuristic');
	}

	/**
	 * @param array<int,array<string,mixed>> $toolCalls
	 * @param array<string,array<string,mixed>> $toolMap
	 */
	public function loopThresholdForToolCalls(array $toolCalls, array $toolMap = []): int {
		foreach ($toolCalls as $toolCall) {
			$name = (string)($toolCall['function']['name'] ?? '');
			$policy = $this->policyForToolName($name, $toolMap);
			if (!empty($policy['destructive']) || empty($policy['read_only'])) {
				return self::MUTATING_TOOL_LOOP_THRESHOLD;
			}
		}

		if ($this->isSearchLikeToolBatch($toolCalls, $toolMap)) {
			return self::SEARCH_TOOL_LOOP_THRESHOLD;
		}

		return self::GENERAL_TOOL_LOOP_THRESHOLD;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function scoreForcedSearchToolCandidate(string $name, array $context): int {
		$lcName = strtolower($name);
		$rawName = strtolower((string)($context['invokeName'] ?? $name));
		$description = strtolower((string)($context['definition']['function']['description'] ?? ''));
		$combined = $lcName . ' ' . $rawName . ' ' . $description;
		$policy = $context['policy'] ?? null;

		if (is_array($policy) && (!($policy['read_only'] ?? false) || !empty($policy['destructive']))) {
			return 0;
		}
		if (!$this->hasQuerySchema($context)) {
			return 0;
		}

		foreach (self::FORCED_SEARCH_UNSAFE_TERMS as $term) {
			if ($term !== '' && str_contains($combined, $term)) {
				return 0;
			}
		}

		$score = 0;
		if (str_contains($lcName, 'search') || str_contains($rawName, 'search')) {
			$score += 60;
		}
		if (str_contains($description, 'search')) {
			$score += 40;
		}
		foreach (['web', 'internet', 'lookup', 'browser'] as $term) {
			if (str_contains($combined, $term)) {
				$score += 20;
			}
		}
		if (str_contains($combined, 'research')) {
			$score += 10;
		}

		return $score;
	}

	/**
	 * @param array<int,array<string,mixed>> $toolCalls
	 * @param array<string,array<string,mixed>> $toolMap
	 */
	private function isSearchLikeToolBatch(array $toolCalls, array $toolMap): bool {
		if (count($toolCalls) === 0) {
			return false;
		}

		foreach ($toolCalls as $toolCall) {
			$name = (string)($toolCall['function']['name'] ?? '');
			$policy = $this->policyForToolName($name, $toolMap);
			if (($policy['kind'] ?? self::KIND_UNKNOWN) !== self::KIND_SEARCH) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string,array<string,mixed>> $toolMap
	 * @return array<string,mixed>
	 */
	private function policyForToolName(string $name, array $toolMap): array {
		$context = $toolMap[$name] ?? null;
		if (is_array($context) && isset($context['policy']) && is_array($context['policy'])) {
			return $context['policy'];
		}

		return $this->inferPolicy($name, $name, '', [], 'name_heuristic');
	}

	/**
	 * @param array<string,mixed> $annotations
	 * @return array<string,mixed>
	 */
	private function inferPolicy(string $name, string $invokeName, string $description, array $annotations, string $source): array {
		$combined = strtolower($name . ' ' . $invokeName . ' ' . $description);
		if (array_key_exists('destructiveHint', $annotations) && $annotations['destructiveHint'] === true) {
			return $this->policy(self::KIND_WRITE, false, false, true, self::MUTATING_TOOL_LOOP_THRESHOLD, 'mcp_annotations');
		}

		if (array_key_exists('readOnlyHint', $annotations) && $annotations['readOnlyHint'] === true) {
			return $this->policy(self::KIND_READ, true, true, false, self::GENERAL_TOOL_LOOP_THRESHOLD, 'mcp_annotations');
		}

		$isSearch = $this->isSearchLikeName($combined);
		$isMutating = $this->isMutatingName($combined);

		if ($isSearch) {
			return $this->policy(self::KIND_SEARCH, true, true, false, self::SEARCH_TOOL_LOOP_THRESHOLD, $source);
		}

		if ($isMutating) {
			return $this->policy(self::KIND_WRITE, false, false, false, self::MUTATING_TOOL_LOOP_THRESHOLD, $source);
		}

		if ($source === 'mcp_heuristic') {
			return $this->policy(self::KIND_UNKNOWN, false, false, false, self::MUTATING_TOOL_LOOP_THRESHOLD, $source);
		}

		return $this->policy(self::KIND_UNKNOWN, true, true, false, self::GENERAL_TOOL_LOOP_THRESHOLD, $source);
	}

	private function isSearchLikeName(string $combined): bool {
		return str_contains($combined, 'search')
			|| str_contains($combined, 'lookup')
			|| str_contains($combined, 'query')
			|| str_contains($combined, 'find')
			|| str_contains($combined, 'rag');
	}

	private function isMutatingName(string $combined): bool {
		$tokens = preg_split('/[^a-z0-9]+/', $combined, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($tokens)) {
			return false;
		}

		foreach ($tokens as $token) {
			if (in_array($token, self::MUTATING_TOKENS, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function hasQuerySchema(array $context): bool {
		$schema = $context['definition']['function']['parameters'] ?? null;
		if ($schema instanceof \stdClass) {
			$schema = (array)$schema;
		}
		if (!is_array($schema)) {
			return false;
		}
		$properties = $schema['properties'] ?? null;
		if ($properties instanceof \stdClass) {
			$properties = (array)$properties;
		}
		if (!is_array($properties) || !array_key_exists('query', $properties)) {
			return false;
		}
		$querySchema = $properties['query'];
		if ($querySchema instanceof \stdClass) {
			$querySchema = (array)$querySchema;
		}
		if (!is_array($querySchema)) {
			return true;
		}
		$type = $querySchema['type'] ?? null;
		return $type === null || $type === 'string' || (is_array($type) && in_array('string', $type, true));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function policy(string $kind, bool $readOnly, bool $idempotent, bool $destructive, int $loopThreshold, string $source): array {
		return [
			'kind' => $kind,
			'read_only' => $readOnly,
			'idempotent' => $idempotent,
			'destructive' => $destructive,
			'loop_threshold' => $loopThreshold,
			'source' => $source,
		];
	}
}
