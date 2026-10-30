<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi;

require_once __DIR__ . '/Support/OpenApiTestDoubles.php';

use OCA\EducAI\OpenApi\ApiExecutionException;
use OCA\EducAI\OpenApi\ApiRequestBuilder;
use OCA\EducAI\OpenApi\ArgumentValidator;
use OCA\EducAI\OpenApi\OpenApiCompiler;
use OCA\EducAI\OpenApi\OpenApiDocumentLoader;
use OCA\EducAI\Tests\Unit\OpenApi\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenApiRequestTest extends TestCase {
	private const BASE = 'https://api.courses.example/v1';

	/** @return array<string,mixed> */
	private static function operation(string $matchKey, string $fixture = 'courses-api.json'): array {
		$loaded = (new OpenApiDocumentLoader())->load(Fixture::read($fixture));
		foreach ((new OpenApiCompiler())->compile($loaded['document'], $loaded['version'])['operations'] as $operation) {
			if ($operation['match_key'] === $matchKey) {
				return $operation;
			}
		}
		throw new \LogicException('Missing ' . $matchKey);
	}

	/** @param array<string,mixed> $parameters */
	private static function inline(array $parameters, string $path = '/items'): array {
		return ['path' => $path, 'parameters' => $parameters];
	}

	private static function errorMessage(callable $call): string {
		try {
			$call();
		} catch (ApiExecutionException $e) {
			self::assertSame('invalid_arguments', $e->getErrorCode());
			return $e->getMessage();
		}
		self::fail('Expected invalid_arguments');
	}

	public function testValidArgumentsAreNormalizedInDeclarationOrder(): void {
		$operation = self::operation('GET /courses');
		$values = (new ArgumentValidator())->validate([
			'query' => ['limit' => '10', 'q' => 'robotics', 'tags' => ['ai', 'ml'], 'language' => null, 'ects' => [5.0, '10']],
		], $operation);

		self::assertSame(['q' => 'robotics', 'language' => null, 'tags' => ['ai', 'ml'], 'ects' => [5, 10], 'limit' => 10], $values['query']);
		self::assertSame([], $values['path']);
	}

	public function testSameNameInPathAndQueryDoesNotCollide(): void {
		$schema = ['type' => 'string'];
		$operation = self::inline([
			['name' => 'id', 'in' => 'path', 'required' => true, 'style' => 'simple', 'explode' => false, 'schema' => $schema, 'description' => ''],
			['name' => 'id', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true, 'schema' => $schema, 'description' => ''],
		], '/items/{id}');
		$values = (new ArgumentValidator())->validate(['path' => ['id' => 'p'], 'query' => ['id' => 'q']], $operation);

		self::assertSame(['path' => ['id' => 'p'], 'query' => ['id' => 'q']], $values);
		self::assertSame(self::BASE . '/items/p?id=q', (new ApiRequestBuilder())->build(self::BASE, $operation, $values));
	}

	/** @return iterable<string,array{array<string,mixed>,string}> */
	public static function invalidCourseSearches(): iterable {
		yield 'unknown group' => [['header' => ['X-Trace' => '1']], '/header: unknown_argument_group'];
		yield 'group not an object' => [['query' => ['robotics']], '/query: expected_object'];
		yield 'unknown parameter' => [['query' => ['secret' => 'x']], '/query/secret: unknown_parameter'];
		yield 'header parameter not offered' => [['query' => ['X-Trace' => 'x']], '/query/X-Trace: unknown_parameter'];
		yield 'enum' => [['query' => ['state' => 'open']], '/query/state: not_in_enum'];
		yield 'null not allowed' => [['query' => ['state' => null]], '/query/state: null_not_allowed'];
		yield 'max length' => [['query' => ['q' => str_repeat('a', 201)]], '/query/q: max_length'];
		yield 'minimum' => [['query' => ['limit' => 0]], '/query/limit: minimum'];
		yield 'maximum' => [['query' => ['limit' => 51]], '/query/limit: maximum'];
		yield 'not an integer' => [['query' => ['limit' => 2.5]], '/query/limit: expected_integer'];
		yield 'no lossy string coercion' => [['query' => ['limit' => '10 ']], '/query/limit: expected_integer'];
		yield 'array expected' => [['query' => ['tags' => 'ai']], '/query/tags: expected_array'];
		yield 'array items' => [['query' => ['tags' => ['']]], '/query/tags/0: min_length'];
		yield 'unique items' => [['query' => ['tags' => ['a', 'a']]], '/query/tags: unique_items'];
		yield 'max items' => [['query' => ['tags' => ['a', 'b', 'c', 'd', 'e', 'f']]], '/query/tags: max_items'];
		yield 'item bounds' => [['query' => ['ects' => [31]]], '/query/ects/0: maximum'];
	}

	#[DataProvider('invalidCourseSearches')]
	public function testInvalidArgumentsAreRejectedBeforeAnyRequest(array $arguments, string $expected): void {
		$operation = self::operation('GET /courses');
		self::assertStringContainsString($expected, self::errorMessage(static fn () => (new ArgumentValidator())->validate($arguments, $operation)));
	}

	public function testRequiredPathParametersAndFormatsAreEnforced(): void {
		$operation = self::operation('GET /courses/{courseId}');
		$validator = new ArgumentValidator();

		self::assertStringContainsString('/path/courseId: required', self::errorMessage(static fn () => $validator->validate([], $operation)));
		self::assertStringContainsString('/path/courseId: format_int32', self::errorMessage(static fn () => $validator->validate(['path' => ['courseId' => 3_000_000_000]], $operation)));
		self::assertSame(['courseId' => 42], $validator->validate(['path' => ['courseId' => '42']], $operation)['path']);
	}

	public function testFixedValuesCannotBeOverriddenAndAreAlwaysSent(): void {
		$operation = self::operation('GET /courses');
		$validator = new ArgumentValidator();
		$fixed = ['query' => ['state' => 'OPEN']];

		self::assertStringContainsString('/query/state: parameter_fixed', self::errorMessage(static fn () => $validator->validate(['query' => ['state' => 'CLOSED']], $operation, $fixed)));
		self::assertSame('OPEN', $validator->validate(['query' => ['q' => 'x']], $operation, $fixed)['query']['state']);
	}

	public function testStringIdsAndDateFormatsOfASecondApi(): void {
		$operation = self::operation('GET /books', 'library-api.json');
		$validator = new ArgumentValidator();

		self::assertStringContainsString('/query/author: required', self::errorMessage(static fn () => $validator->validate(['query' => []], $operation)));
		self::assertStringContainsString('/query/after: format_date', self::errorMessage(static fn () => $validator->validate(['query' => ['author' => 'Le Guin', 'after' => '2026-02-30']], $operation)));
		self::assertStringContainsString('/query/year: maximum', self::errorMessage(static fn () => $validator->validate(['query' => ['author' => 'Le Guin', 'year' => 2100]], $operation)));
		$values = $validator->validate(['query' => ['author' => 'Le Guin', 'formats' => ['print', 'ebook'], 'after' => '2020-01-31']], $operation);

		self::assertSame(
			'https://eu.library.example/api/v2/books?author=Le%20Guin&after=2020-01-31&formats=print&formats=ebook',
			(new ApiRequestBuilder())->build('https://eu.library.example/api/v2', $operation, $values),
		);
	}

	public function testQuerySerializationFollowsTheDeclaredStyle(): void {
		$operation = self::operation('GET /courses');
		$values = (new ArgumentValidator())->validate([
			'query' => ['q' => 'a&b=c#d', 'tags' => ['x,y', 'z'], 'ects' => [5, 10], 'language' => null],
		], $operation);

		self::assertSame(
			self::BASE . '/courses?q=a%26b%3Dc%23d&language=&tags=x%2Cy,z&ects=5&ects=10',
			(new ApiRequestBuilder())->build(self::BASE, $operation, $values),
		);
	}

	/** @return iterable<string,array{string}> */
	public static function pathBreakouts(): iterable {
		yield 'slash' => ['../admin'];
		yield 'encoded slash' => ['%2F..%2Fadmin'];
		yield 'query' => ['1?admin=1'];
		yield 'fragment' => ['1#x'];
		yield 'host' => ['//evil.example'];
		yield 'crlf' => ["1\r\nX-Evil: 1"];
		yield 'backslash' => ['..\\admin'];
	}

	#[DataProvider('pathBreakouts')]
	public function testPathValuesCannotLeaveTheirSegment(string $value): void {
		$operation = self::inline([
			['name' => 'id', 'in' => 'path', 'required' => true, 'style' => 'simple', 'explode' => false, 'schema' => ['type' => 'string'], 'description' => ''],
		], '/items/{id}');
		$url = (new ApiRequestBuilder())->build(self::BASE, $operation, ['path' => ['id' => $value], 'query' => []]);

		self::assertStringStartsWith(self::BASE . '/items/', $url);
		$segment = substr($url, strlen(self::BASE . '/items/'));
		self::assertSame(rawurlencode($value), $segment);
		self::assertDoesNotMatchRegularExpression('#[/?\#\\\\\r\n]#', $segment);
	}

	public function testDotSegmentsAndEmptyPathValuesAreRejected(): void {
		$operation = self::inline([
			['name' => 'id', 'in' => 'path', 'required' => true, 'style' => 'simple', 'explode' => false, 'schema' => ['type' => 'string'], 'description' => ''],
		], '/items/{id}');
		foreach (['', '.', '..'] as $value) {
			try {
				(new ApiRequestBuilder())->build(self::BASE, $operation, ['path' => ['id' => $value], 'query' => []]);
				self::fail('Accepted ' . var_export($value, true));
			} catch (ApiExecutionException $e) {
				self::assertSame('invalid_arguments', $e->getErrorCode());
			}
		}
	}

	public function testNumbersAreSerializedWithoutExponentsOrPrecisionNoise(): void {
		$operation = self::inline([
			['name' => 'n', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'number']], 'description' => ''],
		]);
		$url = (new ApiRequestBuilder())->build(self::BASE, $operation, ['path' => [], 'query' => ['n' => [0.1, 1.0e20, 1.5e-7, -2.0, true]]]);

		self::assertSame(self::BASE . '/items?n=0.1&n=100000000000000000000&n=0.00000015&n=-2&n=true', $url);
	}

	public function testOverlongUrlsAreRejected(): void {
		$operation = self::inline([
			['name' => 'q', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => ''],
		]);
		try {
			(new ApiRequestBuilder())->build(self::BASE, $operation, ['path' => [], 'query' => ['q' => array_fill(0, 100, str_repeat('ü', 30))]]);
			self::fail('Expected request_too_large');
		} catch (ApiExecutionException $e) {
			self::assertSame('request_too_large', $e->getErrorCode());
		}
	}
}
