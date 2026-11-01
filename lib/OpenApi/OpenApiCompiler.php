<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Service\JsonPointer;

/**
 * Compiles a loaded OpenAPI 3.0 document into a manifest: every operation
 * with its stable match key, effective servers, security alternatives,
 * supported parameter bindings, model-facing input schema and the concrete
 * reasons that keep it from being approved. Compiling never touches the
 * network and never approves anything.
 */
final class OpenApiCompiler {
	public const MANIFEST_FORMAT = 1;
	public const MAX_OPERATIONS = 500;
	public const MAX_PATH_LENGTH = 1024;
	public const MAX_PARAMETERS = 64;

	private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
	private const PARAMETER_NAME = '/^[A-Za-z0-9_.-]{1,64}$/D';
	private const HEADER_NAME = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,128}$/D';
	private const FORBIDDEN_HEADERS = [
		'host', 'content-length', 'content-type', 'content-encoding', 'transfer-encoding', 'connection', 'keep-alive',
		'cookie', 'set-cookie', 'accept', 'accept-encoding', 'te', 'trailer', 'upgrade', 'expect', 'user-agent',
		'proxy-authorization', 'proxy-connection', 'forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto',
	];
	private const MUTATION_HINTS = [
		'accept', 'activate', 'add', 'apply', 'approve', 'archive', 'assign', 'book', 'cancel', 'checkout', 'create',
		'deactivate', 'decline', 'delete', 'disable', 'enable', 'enrol', 'enroll', 'favorite', 'favourite', 'follow',
		'invite', 'join', 'leave', 'like', 'login', 'logout', 'mark', 'order', 'pay', 'publish', 'register', 'reject',
		'remove', 'reserve', 'reset', 'revoke', 'save', 'send', 'set', 'signin', 'signout', 'submit', 'subscribe',
		'toggle', 'update', 'upload', 'vote', 'withdraw',
	];

	/**
	 * @param array<string,mixed> $document Output of OpenApiDocumentLoader::load()
	 * @return array<string,mixed>
	 * @throws OpenApiImportException for document-wide limits
	 */
	public function compile(array $document, string $version, ?string $sourceUrl = null): array {
		$refs = new RefResolver($document);
		$schemas = new ParameterSchemaCompiler($refs);
		$diagnostics = [];

		$rootServers = $this->compileServers($document['servers'] ?? null, $sourceUrl, 'servers', $diagnostics);
		$securitySchemes = $this->compileSecuritySchemes($document, $refs, $diagnostics);
		$rootSecurity = array_key_exists('security', $document) ? $document['security'] : null;

		$operations = [];
		foreach ($document['paths'] as $path => $pathItem) {
			$path = (string)$path;
			if (str_starts_with($path, 'x-')) {
				continue;
			}
			try {
				$pathItem = $refs->resolve($pathItem);
			} catch (OpenApiImportException $e) {
				if ($e->getErrorCode() === 'document_too_complex') {
					throw $e;
				}
				$diagnostics[] = self::diagnostic('error', $e->getErrorCode(), 'paths ' . self::clip($path, 200));
				continue;
			}
			if (!self::isObject($pathItem)) {
				$diagnostics[] = self::diagnostic('error', 'path_item_invalid', 'paths ' . self::clip($path, 200));
				continue;
			}
			foreach (self::METHODS as $method) {
				if (!array_key_exists($method, $pathItem)) {
					continue;
				}
				if (count($operations) >= self::MAX_OPERATIONS) {
					throw new OpenApiImportException('document_too_many_operations', 'The document has more operations than the import limit.', ['limit_operations' => self::MAX_OPERATIONS]);
				}
				$operations[] = $this->compileOperation($method, $path, $pathItem, $pathItem[$method], $rootServers, $rootSecurity, $securitySchemes, $sourceUrl, $refs, $schemas, $diagnostics);
			}
		}

		$byOperationId = [];
		foreach ($operations as $index => $operation) {
			if ($operation['operation_id'] !== null) {
				$byOperationId[$operation['operation_id']][] = $index;
			}
		}
		foreach ($byOperationId as $operationId => $indexes) {
			if (count($indexes) > 1) {
				$diagnostics[] = self::diagnostic('warning', 'operation_id_duplicate', (string)$operationId);
				foreach ($indexes as $index) {
					$operations[$index]['notes'][] = ['code' => 'operation_id_duplicate', 'detail' => (string)$operationId];
				}
			}
		}

		usort($operations, static fn (array $a, array $b): int => [$a['path'], array_search(strtolower($a['method']), self::METHODS, true)]
			<=> [$b['path'], array_search(strtolower($b['method']), self::METHODS, true)]);
		foreach ($operations as &$operation) {
			$operation['supported'] = $operation['blockers'] === [];
			$operation['fingerprint'] = hash('sha256', json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
		unset($operation);

		return [
			'format' => self::MANIFEST_FORMAT,
			'oas_version' => $version,
			'info' => [
				'title' => ParameterSchemaCompiler::text($document['info']['title'] ?? '', 200),
				'version' => ParameterSchemaCompiler::text($document['info']['version'] ?? '', 64),
			],
			'servers' => $rootServers,
			'security_schemes' => $securitySchemes,
			'operations' => $operations,
			'diagnostics' => $diagnostics,
		];
	}

	/**
	 * @param array<string,mixed> $pathItem
	 * @param list<array{url:string,resolved:?string}> $rootServers
	 * @param array<string,array<string,mixed>> $securitySchemes
	 * @param list<array<string,string>> $diagnostics
	 * @return array<string,mixed>
	 */
	private function compileOperation(
		string $method,
		string $path,
		array $pathItem,
		mixed $operation,
		array $rootServers,
		mixed $rootSecurity,
		array $securitySchemes,
		?string $sourceUrl,
		RefResolver $refs,
		ParameterSchemaCompiler $schemas,
		array &$diagnostics,
	): array {
		$entry = [
			'match_key' => strtoupper($method) . ' ' . $path,
			'method' => strtoupper($method),
			'path' => $path,
			'operation_id' => null,
			'summary' => '',
			'description' => '',
			'deprecated' => false,
			'servers' => ['source' => 'root', 'urls' => $rootServers],
			'security' => ['declared' => false, 'alternatives' => []],
			'parameters' => [],
			'input_schema' => null,
			'response' => ['json' => null, 'suggestion' => null],
			'blockers' => [],
			'notes' => [],
		];
		if (!self::isObject($operation)) {
			$entry['blockers'][] = ['code' => 'operation_invalid', 'detail' => $entry['match_key']];
			return $entry;
		}
		if (is_string($operation['operationId'] ?? null) && $operation['operationId'] !== '') {
			$entry['operation_id'] = self::clip($operation['operationId'], 255);
		}
		$entry['summary'] = ParameterSchemaCompiler::text($operation['summary'] ?? ($pathItem['summary'] ?? ''), 300);
		$entry['description'] = ParameterSchemaCompiler::text($operation['description'] ?? ($pathItem['description'] ?? ''), 1000);
		$entry['deprecated'] = ($operation['deprecated'] ?? false) === true;

		$templateVariables = $this->checkPath($path, $entry['blockers']);
		if ($method !== 'get') {
			$entry['blockers'][] = ['code' => 'method_not_supported', 'detail' => strtoupper($method)];
			return $entry;
		}

		if (isset($operation['servers']) && is_array($operation['servers']) && $operation['servers'] !== []) {
			$entry['servers'] = ['source' => 'operation', 'urls' => $this->compileServers($operation['servers'], $sourceUrl, $entry['match_key'] . ' servers', $diagnostics)];
		} elseif (isset($pathItem['servers']) && is_array($pathItem['servers']) && $pathItem['servers'] !== []) {
			$entry['servers'] = ['source' => 'path', 'urls' => $this->compileServers($pathItem['servers'], $sourceUrl, $path . ' servers', $diagnostics)];
		}
		if ($entry['servers']['source'] !== 'root') {
			$entry['notes'][] = ['code' => 'server_override', 'detail' => $entry['servers']['source']];
		}

		$entry['security'] = $this->compileSecurity(
			array_key_exists('security', $operation) ? $operation['security'] : $rootSecurity,
			array_key_exists('security', $operation) || $rootSecurity !== null,
			$securitySchemes,
			$entry['blockers'],
		);

		if (array_key_exists('requestBody', $operation)) {
			$entry['blockers'][] = ['code' => 'request_body_unsupported', 'detail' => 'GET'];
		}
		if (array_key_exists('callbacks', $operation)) {
			$entry['notes'][] = ['code' => 'callbacks_ignored', 'detail' => ''];
		}

		$entry['parameters'] = $this->compileParameters($pathItem['parameters'] ?? [], $operation['parameters'] ?? [], $templateVariables, $refs, $schemas, $entry);
		$entry['input_schema'] = self::inputSchema($entry['parameters']);
		$this->analyzeResponses($operation['responses'] ?? null, $refs, $entry);

		$hints = self::mutationHints(self::words($path . ' ' . ($entry['operation_id'] ?? '') . ' ' . $entry['summary']));
		if ($hints !== []) {
			$entry['notes'][] = ['code' => 'possibly_mutating', 'detail' => implode(', ', array_slice($hints, 0, 5))];
		}
		if ($entry['deprecated']) {
			$entry['notes'][] = ['code' => 'operation_deprecated', 'detail' => ''];
		}
		return $entry;
	}

	/**
	 * @param list<array{code:string,detail:string}> $blockers
	 * @return list<string> Template variable names in order
	 */
	private function checkPath(string $path, array &$blockers): array {
		if ($path === '' || $path[0] !== '/' || strlen($path) > self::MAX_PATH_LENGTH
			|| preg_match('#^[A-Za-z0-9\-._~!$&\'()*+,;=:@/{}]+$#D', $path) !== 1) {
			$blockers[] = ['code' => 'path_invalid', 'detail' => self::clip($path, 200)];
			return [];
		}
		preg_match_all('/\{([^{}\/]*)\}/', $path, $matches);
		$rest = preg_replace('/\{[^{}\/]*\}/', '', $path) ?? '';
		$names = $matches[1];
		if (str_contains($rest, '{') || str_contains($rest, '}') || in_array('', $names, true) || count(array_unique($names)) !== count($names)) {
			$blockers[] = ['code' => 'path_template_invalid', 'detail' => self::clip($path, 200)];
			return [];
		}
		foreach (explode('/', substr($path, 1)) as $segment) {
			if ($segment === '.' || $segment === '..') {
				$blockers[] = ['code' => 'path_invalid', 'detail' => self::clip($path, 200)];
				return [];
			}
		}
		return $names;
	}

	/**
	 * @param list<string> $templateVariables
	 * @param array<string,mixed> $entry
	 * @return list<array<string,mixed>>
	 */
	private function compileParameters(mixed $pathLevel, mixed $operationLevel, array $templateVariables, RefResolver $refs, ParameterSchemaCompiler $schemas, array &$entry): array {
		$merged = [];
		foreach (['path item' => $pathLevel, 'operation' => $operationLevel] as $level => $list) {
			if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
				$entry['blockers'][] = ['code' => 'parameters_invalid', 'detail' => $level];
				continue;
			}
			$seen = [];
			foreach ($list as $raw) {
				try {
					$parameter = $refs->resolve($raw);
				} catch (OpenApiImportException $e) {
					if ($e->getErrorCode() === 'document_too_complex') {
						throw $e;
					}
					$entry['blockers'][] = ['code' => $e->getErrorCode(), 'detail' => $level . ' parameter'];
					continue;
				}
				if (!self::isObject($parameter) || !is_string($parameter['name'] ?? null) || $parameter['name'] === ''
					|| !in_array($parameter['in'] ?? null, ['path', 'query', 'header', 'cookie'], true)) {
					$entry['blockers'][] = ['code' => 'parameter_invalid', 'detail' => $level . ' parameter'];
					continue;
				}
				$key = $parameter['in'] . ':' . $parameter['name'];
				if (isset($seen[$key])) {
					$entry['blockers'][] = ['code' => 'parameter_duplicate', 'detail' => self::clip($parameter['name'], 64) . ' in ' . $parameter['in']];
					continue;
				}
				$seen[$key] = true;
				$merged[$key] = $parameter;
			}
		}
		if (count($merged) > self::MAX_PARAMETERS) {
			$entry['blockers'][] = ['code' => 'parameters_too_many', 'detail' => (string)self::MAX_PARAMETERS];
			return [];
		}

		$compiled = [];
		$pathNames = [];
		foreach ($merged as $parameter) {
			$name = $parameter['name'];
			$in = $parameter['in'];
			$label = self::clip($name, 64) . ' in ' . $in;
			$required = ($parameter['required'] ?? false) === true;
			if ($in === 'path') {
				$pathNames[] = $name;
			}
			if ($in === 'header' || $in === 'cookie') {
				$entry['blockers'][] = ['code' => 'parameter_location_unsupported', 'detail' => $label];
				continue;
			}
			if (preg_match(self::PARAMETER_NAME, $name) !== 1) {
				$entry['blockers'][] = ['code' => 'parameter_name_unsupported', 'detail' => $label];
				continue;
			}
			if (array_key_exists('content', $parameter)) {
				$entry['blockers'][] = ['code' => 'parameter_content_unsupported', 'detail' => $label];
				continue;
			}
			if (($parameter['allowReserved'] ?? false) === true) {
				$entry['blockers'][] = ['code' => 'parameter_allow_reserved_unsupported', 'detail' => $label];
			}
			if (($parameter['allowEmptyValue'] ?? false) === true) {
				$entry['blockers'][] = ['code' => 'parameter_allow_empty_unsupported', 'detail' => $label];
			}
			$style = $parameter['style'] ?? ($in === 'path' ? 'simple' : 'form');
			if ($style !== ($in === 'path' ? 'simple' : 'form')) {
				$entry['blockers'][] = ['code' => 'parameter_style_unsupported', 'detail' => $label . ' style ' . (is_string($style) ? self::clip($style, 32) : '?')];
				continue;
			}
			$explode = $parameter['explode'] ?? ($style === 'form');
			if (!is_bool($explode)) {
				$entry['blockers'][] = ['code' => 'parameter_invalid', 'detail' => $label . ' explode'];
				continue;
			}
			if ($in === 'path' && !$required) {
				$entry['blockers'][] = ['code' => 'path_parameter_not_required', 'detail' => $label];
				continue;
			}
			if (!array_key_exists('schema', $parameter)) {
				$entry['blockers'][] = ['code' => 'parameter_schema_missing', 'detail' => $label];
				continue;
			}
			$schema = $schemas->compile($parameter['schema'], $label);
			foreach ($schema['blockers'] as $blocker) {
				$entry['blockers'][] = $blocker;
			}
			if ($schema['schema'] === null) {
				continue;
			}
			if ($in === 'path' && $schema['schema']['type'] === 'array') {
				$entry['blockers'][] = ['code' => 'parameter_array_in_path_unsupported', 'detail' => $label];
				continue;
			}
			if ($in === 'path' && !in_array($name, $templateVariables, true)) {
				$entry['blockers'][] = ['code' => 'path_parameter_unused', 'detail' => $label];
				continue;
			}
			if (($parameter['deprecated'] ?? false) === true) {
				$entry['notes'][] = ['code' => 'parameter_deprecated', 'detail' => $label];
			}
			$description = ParameterSchemaCompiler::text($parameter['description'] ?? '', 300);
			$compiled[] = [
				'name' => $name,
				'in' => $in,
				'required' => $required,
				'style' => $style,
				'explode' => $explode,
				'schema' => $schema['schema'],
				'description' => $description,
			];
		}
		foreach ($templateVariables as $variable) {
			if (!in_array($variable, $pathNames, true)) {
				$entry['blockers'][] = ['code' => 'path_parameter_missing', 'detail' => self::clip($variable, 64)];
			}
		}
		return $compiled;
	}

	/**
	 * Model-facing arguments: separate "path" and "query" objects so equal
	 * names in different locations cannot collide.
	 *
	 * @param list<array<string,mixed>> $parameters
	 * @param array<string,bool> $exclude "in:name" of server-fixed parameters
	 * @return array<string,mixed>
	 */
	public static function inputSchema(array $parameters, array $exclude = []): array {
		$groups = [];
		foreach ($parameters as $parameter) {
			if (isset($exclude[$parameter['in'] . ':' . $parameter['name']])) {
				continue;
			}
			$property = self::jsonSchema($parameter['schema']);
			if ($parameter['description'] !== '') {
				$property['description'] = $parameter['description'];
			}
			$groups[$parameter['in']]['properties'][$parameter['name']] = $property;
			if ($parameter['required']) {
				$groups[$parameter['in']]['required'][] = $parameter['name'];
			}
		}
		$schema = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
		$properties = [];
		$required = [];
		foreach (['path', 'query'] as $in) {
			if (!isset($groups[$in])) {
				continue;
			}
			$group = ['type' => 'object', 'properties' => $groups[$in]['properties'], 'additionalProperties' => false];
			if (!empty($groups[$in]['required'])) {
				$group['required'] = $groups[$in]['required'];
				$required[] = $in;
			}
			$properties[$in] = $group;
		}
		if ($properties !== []) {
			$schema['properties'] = $properties;
		}
		if ($required !== []) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * @param array<string,mixed> $schema Compiled parameter schema
	 * @return array<string,mixed>
	 */
	private static function jsonSchema(array $schema): array {
		$nullable = ($schema['nullable'] ?? false) === true;
		$result = ['type' => $nullable ? [$schema['type'], 'null'] : $schema['type']];
		foreach (['format', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems', 'multipleOf'] as $keyword) {
			if (array_key_exists($keyword, $schema)) {
				$result[$keyword] = $schema[$keyword];
			}
		}
		foreach (['minimum' => 'exclusiveMinimum', 'maximum' => 'exclusiveMaximum'] as $bound => $exclusive) {
			if (array_key_exists($bound, $schema)) {
				$result[($schema[$exclusive] ?? false) === true ? $exclusive : $bound] = $schema[$bound];
			}
		}
		if (isset($schema['enum'])) {
			$result['enum'] = $nullable && !in_array(null, $schema['enum'], true) ? [...$schema['enum'], null] : $schema['enum'];
		}
		if (isset($schema['items'])) {
			$result['items'] = self::jsonSchema($schema['items']);
		}
		return $result;
	}

	/**
	 * @param array<string,array<string,mixed>> $schemes
	 * @param list<array{code:string,detail:string}> $blockers
	 * @return array{declared:bool,alternatives:list<array<string,mixed>>}
	 */
	private function compileSecurity(mixed $security, bool $declared, array $schemes, array &$blockers): array {
		if (!$declared || $security === null) {
			return ['declared' => false, 'alternatives' => []];
		}
		if (!is_array($security) || ($security !== [] && !array_is_list($security))) {
			$blockers[] = ['code' => 'security_invalid', 'detail' => ''];
			return ['declared' => true, 'alternatives' => []];
		}
		$alternatives = [];
		foreach ($security as $requirement) {
			if (!is_array($requirement) || ($requirement !== [] && array_is_list($requirement))) {
				$blockers[] = ['code' => 'security_invalid', 'detail' => ''];
				continue;
			}
			if ($requirement === []) {
				$alternatives[] = ['key' => 'anonymous', 'supported' => true, 'mode' => 'none', 'scheme' => null, 'header' => null, 'reason' => null];
				continue;
			}
			$names = array_map('strval', array_keys($requirement));
			if (count($names) > 1) {
				$alternatives[] = ['key' => 'and:' . implode('+', $names), 'supported' => false, 'mode' => null, 'scheme' => null, 'header' => null, 'reason' => 'security_and_unsupported'];
				continue;
			}
			$name = $names[0];
			$scheme = $schemes[$name] ?? null;
			if ($scheme === null) {
				$alternatives[] = ['key' => 'scheme:' . $name, 'supported' => false, 'mode' => null, 'scheme' => $name, 'header' => null, 'reason' => 'security_scheme_unknown'];
				continue;
			}
			$alternatives[] = [
				'key' => 'scheme:' . $name,
				'supported' => $scheme['supported'],
				'mode' => $scheme['mode'],
				'scheme' => $name,
				'header' => $scheme['header'],
				'reason' => $scheme['reason'],
			];
		}
		if ($alternatives !== [] && array_filter($alternatives, static fn (array $alternative): bool => $alternative['supported']) === []) {
			$blockers[] = ['code' => 'security_unsupported', 'detail' => implode(', ', array_map(static fn (array $alternative): string => (string)$alternative['reason'], $alternatives))];
		}
		return ['declared' => true, 'alternatives' => $alternatives];
	}

	/**
	 * @param array<string,mixed> $document
	 * @param list<array<string,string>> $diagnostics
	 * @return array<string,array<string,mixed>>
	 */
	private function compileSecuritySchemes(array $document, RefResolver $refs, array &$diagnostics): array {
		$raw = $document['components']['securitySchemes'] ?? [];
		if (!is_array($raw)) {
			$diagnostics[] = self::diagnostic('error', 'security_schemes_invalid', 'components.securitySchemes');
			return [];
		}
		$schemes = [];
		foreach ($raw as $name => $scheme) {
			$name = (string)$name;
			try {
				$scheme = $refs->resolve($scheme);
			} catch (OpenApiImportException $e) {
				if ($e->getErrorCode() === 'document_too_complex') {
					throw $e;
				}
				$scheme = null;
			}
			$entry = ['type' => null, 'supported' => false, 'mode' => null, 'header' => null, 'reason' => 'security_scheme_invalid'];
			if (self::isObject($scheme) && is_string($scheme['type'] ?? null)) {
				$entry['type'] = $scheme['type'];
				if ($scheme['type'] === 'http' && is_string($scheme['scheme'] ?? null) && strtolower($scheme['scheme']) === 'bearer') {
					$entry = ['type' => 'http', 'supported' => true, 'mode' => 'bearer', 'header' => 'Authorization', 'reason' => null];
				} elseif ($scheme['type'] === 'http') {
					$entry['reason'] = 'security_http_scheme_unsupported';
				} elseif ($scheme['type'] === 'apiKey' && ($scheme['in'] ?? null) === 'header') {
					$header = $scheme['name'] ?? null;
					if (is_string($header) && self::isSendableHeaderName($header)) {
						$entry = ['type' => 'apiKey', 'supported' => true, 'mode' => 'api_key_header', 'header' => $header, 'reason' => null];
					} else {
						$entry['reason'] = 'security_header_name_unsupported';
					}
				} elseif ($scheme['type'] === 'apiKey') {
					$entry['reason'] = 'security_api_key_location_unsupported';
				} elseif (in_array($scheme['type'], ['oauth2', 'openIdConnect', 'mutualTLS'], true)) {
					$entry['reason'] = 'security_' . strtolower($scheme['type']) . '_unsupported';
				} else {
					$entry['reason'] = 'security_scheme_unsupported';
				}
			}
			if (!$entry['supported']) {
				$diagnostics[] = self::diagnostic('warning', (string)$entry['reason'], 'securitySchemes ' . self::clip($name, 64));
			}
			$schemes[$name] = $entry;
		}
		return $schemes;
	}

	/**
	 * @param list<array<string,string>> $diagnostics
	 * @return list<array{url:string,resolved:?string}>
	 */
	private function compileServers(mixed $servers, ?string $sourceUrl, string $where, array &$diagnostics): array {
		if ($servers === null) {
			return [];
		}
		if (!is_array($servers) || ($servers !== [] && !array_is_list($servers))) {
			$diagnostics[] = self::diagnostic('error', 'servers_invalid', $where);
			return [];
		}
		$result = [];
		foreach ($servers as $server) {
			if (!self::isObject($server) || !is_string($server['url'] ?? null)) {
				$diagnostics[] = self::diagnostic('error', 'server_invalid', $where);
				continue;
			}
			$variables = is_array($server['variables'] ?? null) ? $server['variables'] : [];
			$missing = false;
			$url = preg_replace_callback('/\{([^{}]+)\}/', static function (array $match) use ($variables, &$missing): string {
				$default = $variables[$match[1]]['default'] ?? null;
				if (!is_string($default)) {
					$missing = true;
					return $match[0];
				}
				return $default;
			}, $server['url']) ?? $server['url'];
			if ($missing) {
				$diagnostics[] = self::diagnostic('warning', 'server_variable_missing', $where);
			}
			$resolved = null;
			if (!$missing) {
				$candidate = $sourceUrl !== null ? ApiUrl::resolveRelative($url === '' ? '/' : $url, $sourceUrl) : $url;
				$resolved = $candidate !== null ? ApiUrl::normalizeBase($candidate, true) : null;
			}
			$result[] = ['url' => self::clip($server['url'], 500), 'resolved' => $resolved];
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $entry
	 */
	private function analyzeResponses(mixed $responses, RefResolver $refs, array &$entry): void {
		if (!self::isObject($responses)) {
			$entry['blockers'][] = ['code' => 'no_success_response', 'detail' => ''];
			return;
		}
		$json = false;
		$unknown = false;
		$found = false;
		$schema = null;
		$complex = false;
		$suggester = new ResponseMappingSuggester($refs);
		foreach ($responses as $status => $response) {
			$status = (string)$status;
			if (preg_match('/^2(?:[0-9]{2}|XX)$/D', $status) !== 1) {
				continue;
			}
			$found = true;
			try {
				$response = $refs->resolve($response);
			} catch (OpenApiImportException $e) {
				if ($e->getErrorCode() === 'document_too_complex') {
					throw $e;
				}
				$unknown = true;
				$entry['notes'][] = ['code' => 'response_unreadable', 'detail' => $status];
				continue;
			}
			$content = self::isObject($response) ? ($response['content'] ?? null) : null;
			if (!self::isObject($content)) {
				continue;
			}
			foreach ($content as $mediaType => $media) {
				if (preg_match('#^application/(?:[A-Za-z0-9.+-]+\+)?json(?:\s*;.*)?$#i', (string)$mediaType) === 1) {
					$json = true;
					$responseSchema = self::isObject($media) ? ($media['schema'] ?? null) : null;
					$schema ??= $responseSchema;
					$complex = $suggester->usesComposition($responseSchema) || $complex;
				}
			}
		}
		if (!$found) {
			$entry['blockers'][] = ['code' => 'no_success_response', 'detail' => ''];
			return;
		}
		if (!$json && !$unknown) {
			$entry['blockers'][] = ['code' => 'response_not_json', 'detail' => ''];
			return;
		}
		$entry['response']['json'] = $json ? true : null;
		if ($complex) {
			$entry['notes'][] = ['code' => 'response_schema_complex', 'detail' => ''];
		}
		if ($schema !== null) {
			try {
				$entry['response']['suggestion'] = $suggester->suggest($schema);
			} catch (OpenApiImportException $e) {
				if ($e->getErrorCode() === 'document_too_complex') {
					throw $e;
				}
				$entry['notes'][] = ['code' => 'response_schema_unreadable', 'detail' => $e->getErrorCode()];
			}
		}
	}

	/** @return list<string> */
	/**
	 * Words that name a mutating verb, also behind an un-/re- prefix such as
	 * "unapply" or "resend".
	 *
	 * @param list<string> $words
	 * @return list<string>
	 */
	private static function mutationHints(array $words): array {
		return array_values(array_filter($words, static fn (string $word): bool => in_array($word, self::MUTATION_HINTS, true)
			|| (preg_match('/^(?:un|re)([a-z]{3,})$/', $word, $match) === 1 && in_array($match[1], self::MUTATION_HINTS, true))));
	}

	/** @return list<string> */
	private static function words(string $text): array {
		$text = preg_replace('/([a-z])([A-Z])/', '$1 $2', $text) ?? $text;
		return array_values(array_unique(preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []));
	}

	private static function isObject(mixed $value): bool {
		return is_array($value) && ($value === [] || !array_is_list($value));
	}

	/** @return array{level:string,code:string,where:string} */
	private static function diagnostic(string $level, string $code, string $where): array {
		return ['level' => $level, 'code' => $code, 'where' => $where];
	}

	private static function clip(string $value, int $max): string {
		$value = preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? '';
		return mb_substr($value, 0, $max);
	}

	/** A credential header the transport may set without clobbering protocol headers. */
	public static function isSendableHeaderName(string $name): bool {
		return preg_match(self::HEADER_NAME, $name) === 1 && !in_array(strtolower($name), self::FORBIDDEN_HEADERS, true);
	}

	public static function isValidPointer(mixed $pointer): bool {
		return is_string($pointer) && JsonPointer::isValid($pointer);
	}
}
