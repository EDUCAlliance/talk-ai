<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi;

use OCA\EducAI\OpenApi\ApiExecutionException;
use OCA\EducAI\OpenApi\ApiRequestBuilder;
use OCA\EducAI\OpenApi\ArgumentValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenApiNumberRegressionTest extends TestCase {
	private static function operation(array $schema = ['type' => 'number']): array {
		return ['path' => '/items/{p}', 'parameters' => [
			['name' => 'p', 'in' => 'path', 'required' => true, 'explode' => false, 'schema' => ['type' => 'number']],
			['name' => 'q', 'in' => 'query', 'required' => false, 'explode' => true, 'schema' => $schema],
		]];
	}

	public static function numbers(): iterable {
		yield [1e-30, '0.' . str_repeat('0', 29) . '1'];
		yield [-1e-30, '-0.' . str_repeat('0', 29) . '1'];
		yield [1.23e-19, '0.' . str_repeat('0', 18) . '123'];
		yield [1.23e-25, '0.' . str_repeat('0', 24) . '123'];
		yield [5e-324, '0.' . str_repeat('0', 323) . '5'];
		yield [1e20, '100000000000000000000'];
		yield [1.234567890123456e30, '1234567890123456000000000000000'];
		yield [PHP_FLOAT_MAX, '17976931348623157' . str_repeat('0', 292)];
		yield [0.125, '0.125'];
		yield [0.0, '0'];
		yield [-0.0, '0'];
	}

	#[DataProvider('numbers')]
	public function testSameLosslessDecimalInPathQueryAndArrays(float $value, string $expected): void {
		$operation = self::operation();
		$validator = new ArgumentValidator();
		$builder = new ApiRequestBuilder();
		$args = $validator->validate(['path' => ['p' => $value], 'query' => ['q' => $value]], $operation);
		self::assertSame('https://api.example/items/' . $expected . '?q=' . $expected, $builder->build('https://api.example', $operation, $args));
		$operation = self::operation(['type' => 'array', 'items' => ['type' => 'number']]);
		$args = $validator->validate(['path' => ['p' => 1], 'query' => ['q' => [$value, $value]]], $operation);
		self::assertSame('https://api.example/items/1?q=' . $expected . '&q=' . $expected, $builder->build('https://api.example', $operation, $args));
		self::assertSame($value, (float)$expected);
	}

	public static function exactStrings(): iterable {
		yield ['9007199254740993', 9007199254740993];
		yield ['9007199254740993.0', 9007199254740993];
		yield ['9.007199254740993e15', 9007199254740993];
		yield [(string)PHP_INT_MAX, PHP_INT_MAX];
		yield [(string)PHP_INT_MIN, PHP_INT_MIN];
		yield ['0.125', 0.125];
		yield ['-1.2500e-2', -0.0125];
		yield ['5e-324', 5e-324];
		yield ['1e20', 1e20];
		yield ['-0.00', 0];
	}

	#[DataProvider('exactStrings')]
	public function testNumberStringsAndAdminFixedValuesPreserveTheirDecimalValue(string $input, int|float $expected): void {
		$operation = self::operation();
		$validator = new ArgumentValidator();
		self::assertSame($expected, $validator->validate(['path' => ['p' => 1], 'query' => ['q' => $input]], $operation)['query']['q']);
		self::assertSame($expected, $validator->validateFixed($operation, ['query' => ['q' => $input]])['query']['q']);
		$operation = self::operation(['type' => 'array', 'items' => ['type' => 'number']]);
		self::assertSame([$expected], $validator->validate(['path' => ['p' => 1], 'query' => ['q' => [$input]]], $operation)['query']['q']);
	}

	public static function lossyStrings(): iterable {
		foreach (['1e-400', '-1e-400', '1e400', '9223372036854775808', '0.10000000000000001', '9007199254740993.1', '1.0000000000000000001'] as $input) {
			yield [$input];
		}
	}

	#[DataProvider('lossyStrings')]
	public function testLossyNumericStringsFailExplicitly(string $input): void {
		$this->expectException(ApiExecutionException::class);
		$this->expectExceptionMessage('expected_number');
		(new ArgumentValidator())->validate(['path' => ['p' => 1], 'query' => ['q' => $input]], self::operation());
	}

	public function testLargeIntegerEnumDoesNotRoundBothSidesBeforeComparing(): void {
		$this->expectException(ApiExecutionException::class);
		$this->expectExceptionMessage('not_in_enum');
		(new ArgumentValidator())->validate(['path' => ['p' => 1], 'query' => ['q' => '9007199254740993']], self::operation(['type' => 'number', 'enum' => [9007199254740992]]));
	}

	public function testExpansionStillEnforcesRequestSizeLimit(): void {
		$this->expectException(ApiExecutionException::class);
		$this->expectExceptionMessage('too long');
		$operation = self::operation(['type' => 'array', 'items' => ['type' => 'number']]);
		$args = (new ArgumentValidator())->validate(['path' => ['p' => 1], 'query' => ['q' => array_fill(0, 30, 5e-324)]], $operation);
		(new ApiRequestBuilder())->build('https://api.example', $operation, $args);
	}
	public static function roundedBounds(): iterable {
		yield ['9007199254740993', ['maximum' => 9007199254740992.0], 'maximum'];
		yield ['9007199254740995', ['minimum' => 9007199254740996.0], 'minimum'];
		yield ['-9007199254740993', ['minimum' => -9007199254740992.0], 'minimum'];
		yield ['-9007199254740995', ['maximum' => -9007199254740996.0], 'maximum'];
	}

	#[DataProvider('roundedBounds')]
	public function testMixedIntegerFloatBoundsDoNotDiscardPreservedPrecision(string $value, array $bounds, string $failure): void {
		$this->expectException(ApiExecutionException::class);
		$this->expectExceptionMessage($failure);
		(new ArgumentValidator())->validate(['path' => ['p' => 1], 'query' => ['q' => $value]], self::operation(['type' => 'number'] + $bounds));
	}

	public function testExclusiveBoundsAcceptAnIntegerStrictlyInsideEvenWhenItsFloatRoundsToTheBoundary(): void {
		$value = '9007199254740993';
		$schema = ['type' => 'number', 'minimum' => 9007199254740992.0, 'exclusiveMinimum' => true, 'maximum' => 9007199254740994.0, 'exclusiveMaximum' => true];
		self::assertSame(9007199254740993, (new ArgumentValidator())->validate(['path' => ['p' => 1], 'query' => ['q' => $value]], self::operation($schema))['query']['q']);
	}

}
