<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi;

require_once __DIR__ . '/Support/OpenApiTestDoubles.php';

use OCA\EducAI\OpenApi\OpenApiCompiler;
use OCA\EducAI\OpenApi\OpenApiDocumentLoader;
use OCA\EducAI\OpenApi\OpenApiImportException;
use OCA\EducAI\OpenApi\RefResolver;
use OCA\EducAI\Tests\Unit\OpenApi\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenApiImportTest extends TestCase {
	/** @return array<string,array<string,mixed>> Keyed by match key */
	private static function compileFixture(string $name, ?string $sourceUrl = null): array {
		$loaded = (new OpenApiDocumentLoader())->load(Fixture::read($name));
		$manifest = (new OpenApiCompiler())->compile($loaded['document'], $loaded['version'], $sourceUrl);
		$operations = [];
		foreach ($manifest['operations'] as $operation) {
			$operations[$operation['match_key']] = $operation;
		}
		return ['manifest' => $manifest, 'operations' => $operations];
	}

	/** @param array<string,mixed> $operation */
	private static function blockerCodes(array $operation): array {
		return array_column($operation['blockers'], 'code');
	}

	/** @param array<string,mixed> $operation */
	private static function noteCodes(array $operation): array {
		return array_column($operation['notes'], 'code');
	}

	private static function document(array $overrides = []): string {
		return json_encode(array_replace([
			'openapi' => '3.0.3',
			'info' => ['title' => 'T', 'version' => '1'],
			'paths' => new \stdClass(),
		], $overrides), JSON_THROW_ON_ERROR);
	}

	private static function assertImportError(string $code, string $bytes): void {
		try {
			(new OpenApiDocumentLoader())->load($bytes);
			self::fail('Expected ' . $code);
		} catch (OpenApiImportException $e) {
			self::assertSame($code, $e->getErrorCode());
		}
	}

	public function testLoadsSupportedVersionsAndHashesTheBytes(): void {
		$bytes = Fixture::read('courses-api.json');
		$loaded = (new OpenApiDocumentLoader())->load("\xEF\xBB\xBF" . $bytes);

		self::assertSame('3.0.3', $loaded['version']);
		self::assertSame(hash('sha256', $bytes), $loaded['sha256']);
		self::assertSame('3.0.1', (new OpenApiDocumentLoader())->load(Fixture::read('library-api.json'))['version']);
	}

	/** @return iterable<string,array{string,string}> */
	public static function rejectedDocuments(): iterable {
		yield 'swagger 2' => ['unsupported_version', json_encode(['swagger' => '2.0', 'info' => ['title' => 'T', 'version' => '1'], 'paths' => new \stdClass()])];
		yield 'openapi 3.1' => ['unsupported_version', self::document(['openapi' => '3.1.0'])];
		yield 'openapi 3' => ['unsupported_version', self::document(['openapi' => '3'])];
		yield 'yaml' => ['yaml_not_supported', "openapi: 3.0.3\ninfo:\n  title: T\n"];
		yield 'not json' => ['document_not_json', '<html></html>'];
		yield 'broken json' => ['document_invalid_json', '{"openapi": "3.0.3",'];
		yield 'invalid utf8' => ['document_not_utf8', "{\"openapi\": \"3.0.3\xff\"}"];
		yield 'missing info' => ['document_invalid', json_encode(['openapi' => '3.0.3', 'paths' => new \stdClass()])];
		yield 'missing paths' => ['document_invalid', json_encode(['openapi' => '3.0.3', 'info' => ['title' => 'T', 'version' => '1']])];
		yield 'too deep' => ['document_too_deep', str_repeat('{"a":', 70) . '1' . str_repeat('}', 70)];
		yield 'json array' => ['document_not_json', '[]'];
		yield 'too large' => ['document_too_large', '{"x":"' . str_repeat('a', OpenApiDocumentLoader::MAX_BYTES) . '"}'];
	}

	#[DataProvider('rejectedDocuments')]
	public function testRejectsUnsupportedDocumentsWithPreciseCodes(string $code, string $bytes): void {
		self::assertImportError($code, $bytes);
	}

	public function testRejectsDocumentsWithTooManyNodes(): void {
		$items = array_fill(0, 50_000, [1, 2, 3, 4]);
		self::assertImportError('document_too_complex', self::document(['x-bulk' => $items]));
	}

	public function testResolvesLocalReferenceChainsAndRejectsCyclesAndExternalReferences(): void {
		$refs = new RefResolver([
			'components' => [
				'parameters' => [
					'A' => ['$ref' => '#/components/parameters/B'],
					'B' => ['name' => 'b', 'in' => 'query'],
					'Self' => ['$ref' => '#/components/parameters/Self'],
					'a/b~c' => ['name' => 'escaped', 'in' => 'query'],
				],
			],
		]);

		self::assertSame('b', $refs->resolve(['$ref' => '#/components/parameters/A'])['name']);
		self::assertSame('escaped', $refs->resolve(['$ref' => '#/components/parameters/a~1b~0c'])['name']);
		self::assertSame('escaped', $refs->resolve(['$ref' => '#/components/parameters/a~1b%7E0c'])['name']);
		foreach ([
			'ref_cycle' => '#/components/parameters/Self',
			'ref_not_found' => '#/components/parameters/Missing',
			'ref_external' => 'https://schemas.example/params.json#/A',
			'ref_external ' => 'params.json#/A',
		] as $code => $ref) {
			try {
				$refs->resolve(['$ref' => $ref]);
				self::fail('Expected ' . $code);
			} catch (OpenApiImportException $e) {
				self::assertSame(trim($code), $e->getErrorCode());
			}
		}
	}

	public function testReferenceExpansionIsBudgeted(): void {
		$refs = new RefResolver(['components' => ['schemas' => ['S' => ['type' => 'string']]]]);
		try {
			for ($i = 0; $i <= RefResolver::MAX_RESOLUTIONS; $i++) {
				$refs->resolve(['$ref' => '#/components/schemas/S']);
			}
			self::fail('Expected the reference budget to be exhausted');
		} catch (OpenApiImportException $e) {
			self::assertSame('document_too_complex', $e->getErrorCode());
		}
	}

	public function testCompilesEveryOperationWithConcreteReasons(): void {
		$operations = self::compileFixture('courses-api.json')['operations'];

		self::assertTrue($operations['GET /courses']['supported']);
		self::assertSame(['method_not_supported'], self::blockerCodes($operations['POST /courses']));
		self::assertSame(['method_not_supported'], self::blockerCodes($operations['DELETE /courses/{courseId}']));
		self::assertSame(['parameter_location_unsupported'], self::blockerCodes($operations['GET /reports']));
		self::assertSame(['parameter_style_unsupported'], self::blockerCodes($operations['GET /search']));
		self::assertSame(['schema_composition_unsupported'], self::blockerCodes($operations['GET /filtered']));
		self::assertSame(['response_not_json'], self::blockerCodes($operations['GET /plain']));
		self::assertSame(['security_unsupported'], self::blockerCodes($operations['GET /admin/export']));
		self::assertSame(['security_unsupported'], self::blockerCodes($operations['GET /combined']));
		self::assertSame('security_and_unsupported', $operations['GET /combined']['security']['alternatives'][0]['reason']);
		self::assertContains('optional_parameter_not_offered', self::noteCodes($operations['GET /courses']));
	}

	public function testSecurityIsInheritedOrReplacedAndAnonymousIsAnExplicitAlternative(): void {
		$operations = self::compileFixture('courses-api.json')['operations'];

		self::assertSame(['scheme:bearerAuth'], array_column($operations['GET /courses']['security']['alternatives'], 'key'));
		self::assertSame(['anonymous', 'scheme:bearerAuth'], array_column($operations['GET /courses/{courseId}']['security']['alternatives'], 'key'));
		self::assertSame(['declared' => true, 'alternatives' => []], $operations['GET /open-data']['security']);
		self::assertSame('bearer', $operations['GET /courses']['security']['alternatives'][0]['mode']);
	}

	public function testReportsUnsupportedSecuritySchemesAndDuplicateOperationIds(): void {
		$compiled = self::compileFixture('courses-api.json');
		$codes = array_column($compiled['manifest']['diagnostics'], 'code');

		self::assertContains('security_api_key_location_unsupported', $codes);
		self::assertContains('security_http_scheme_unsupported', $codes);
		self::assertContains('security_oauth2_unsupported', $codes);
		self::assertContains('operation_id_duplicate', $codes);
		self::assertContains('operation_id_duplicate', self::noteCodes($compiled['operations']['GET /things/a']));
		self::assertContains('operation_id_duplicate', self::noteCodes($compiled['operations']['GET /things/b']));
	}

	public function testMarksPossiblyMutatingReadsAndServerOverrides(): void {
		$operations = self::compileFixture('courses-api.json')['operations'];

		self::assertContains('possibly_mutating', self::noteCodes($operations['GET /courses/{courseId}/subscribe']));
		self::assertSame('path', $operations['GET /legacy']['servers']['source']);
		self::assertSame('https://legacy.courses.example/api', $operations['GET /legacy']['servers']['urls'][0]['resolved']);
		self::assertContains('server_override', self::noteCodes($operations['GET /legacy']));
	}

	public function testInputSchemaSeparatesPathAndQueryArguments(): void {
		$operations = self::compileFixture('courses-api.json')['operations'];
		$schema = $operations['GET /courses/{courseId}']['input_schema'];

		self::assertSame(['path'], $schema['required']);
		self::assertSame(['courseId'], $schema['properties']['path']['required']);
		self::assertFalse($schema['additionalProperties']);
		self::assertSame(['integer'], [$schema['properties']['path']['properties']['courseId']['type']]);

		$query = $operations['GET /courses']['input_schema']['properties']['query']['properties'];
		self::assertSame(['string', 'null'], $query['language']['type']);
		self::assertSame(['de', 'en', 'fr', null], $query['language']['enum']);
		self::assertArrayNotHasKey('X-Trace', $query);
		self::assertSame(1, $query['limit']['minimum']);
	}

	public function testSuggestsAResultMappingFromTheResponseSchema(): void {
		$suggestion = self::compileFixture('courses-api.json')['operations']['GET /courses']['response']['suggestion'];

		self::assertSame('/data', $suggestion['records']);
		self::assertSame('/id', $suggestion['id']);
		self::assertSame('/title', $suggestion['title']);
		self::assertSame('/permalink', $suggestion['link_field']);
		self::assertSame(['has_more' => '/has_more', 'next_cursor' => '/next_cursor', 'total' => '/total'], $suggestion['paging']);
	}

	public function testStructurallyDifferentApiCompilesWithoutSpecialCases(): void {
		$operations = self::compileFixture('library-api.json')['operations'];

		self::assertTrue($operations['GET /books']['supported']);
		self::assertSame('https://eu.library.example/api/v2', $operations['GET /books']['servers']['urls'][0]['resolved']);
		self::assertSame('api_key_header', $operations['GET /books']['security']['alternatives'][0]['mode']);
		self::assertSame('X-Library-Key', $operations['GET /books']['security']['alternatives'][0]['header']);
		self::assertSame(['author'], $operations['GET /books']['input_schema']['properties']['query']['required']);
		self::assertSame(2100, $operations['GET /books']['input_schema']['properties']['query']['properties']['year']['exclusiveMaximum']);
		self::assertSame(['schema_pattern_unsupported'], self::blockerCodes($operations['GET /books/{isbn}']));
		self::assertSame(['ref_cycle'], self::blockerCodes($operations['GET /loop']));
		self::assertSame(['ref_external'], self::blockerCodes($operations['GET /external']));

		$shelf = $operations['GET /shelves/{shelf}/books']['response']['suggestion'];
		self::assertSame('/items', $shelf['records']);
		self::assertSame(['next_cursor' => '/cursor', 'total' => '/count'], $shelf['paging']);
		self::assertSame('', $operations['GET /books']['response']['suggestion']['records']);
	}

	public function testRelativeServersResolveAgainstTheSourceUrl(): void {
		$bytes = self::document([
			'servers' => [['url' => '../v2']],
			'paths' => ['/x' => ['get' => ['responses' => ['200' => ['description' => 'x', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]]]]],
		]);
		$loaded = (new OpenApiDocumentLoader())->load($bytes);
		$manifest = (new OpenApiCompiler())->compile($loaded['document'], $loaded['version'], 'https://api.example/docs/v1/openapi.json');

		self::assertSame('https://api.example/docs/v2', $manifest['servers'][0]['resolved']);
	}

	public function testOperationCountIsLimited(): void {
		$paths = [];
		for ($i = 0; $i <= OpenApiCompiler::MAX_OPERATIONS; $i++) {
			$paths['/p' . $i] = ['get' => ['responses' => ['200' => ['description' => 'x']]]];
		}
		$loaded = (new OpenApiDocumentLoader())->load(self::document(['paths' => $paths]));

		$this->expectException(OpenApiImportException::class);
		(new OpenApiCompiler())->compile($loaded['document'], $loaded['version']);
	}

	public function testPathTemplatesAndParametersMustAgree(): void {
		$response = ['200' => ['description' => 'x', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]];
		$loaded = (new OpenApiDocumentLoader())->load(self::document(['paths' => [
			'/a/{missing}' => ['get' => ['responses' => $response]],
			'/b/{id}' => ['get' => ['responses' => $response, 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => false, 'schema' => ['type' => 'string']]]]],
			'/c/{id}' => ['get' => ['responses' => $response, 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]]]]],
			'/d' => ['get' => ['responses' => $response, 'parameters' => [['name' => 'q', 'in' => 'query', 'allowReserved' => true, 'schema' => ['type' => 'string']]]]],
			'/e' => ['get' => ['responses' => $response, 'parameters' => [['name' => 'q', 'in' => 'query', 'content' => ['application/json' => ['schema' => ['type' => 'string']]]]]]],
			'/f' => ['get' => ['responses' => $response, 'parameters' => [['name' => 'q', 'in' => 'query', 'style' => 'pipeDelimited', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]]]]],
			'/g' => ['get' => ['responses' => $response, 'requestBody' => ['content' => []]]],
			'/h' => ['get' => ['responses' => $response, 'parameters' => [['name' => 'q', 'in' => 'query', 'allowEmptyValue' => true, 'schema' => ['type' => 'string']]]]],
		]]));
		$operations = [];
		foreach ((new OpenApiCompiler())->compile($loaded['document'], $loaded['version'])['operations'] as $operation) {
			$operations[$operation['path']] = $operation;
		}

		foreach (['/a/{missing}', '/b/{id}', '/c/{id}', '/d', '/e', '/f', '/g', '/h'] as $path) {
			self::assertFalse($operations[$path]['supported'], $path);
		}
		self::assertSame(['request_body_unsupported'], self::blockerCodes($operations['/g']));
	}
}
