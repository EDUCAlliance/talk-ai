<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Service\ToolArgumentNormalizer;
use PHPUnit\Framework\TestCase;

class ToolArgumentNormalizerTest extends TestCase {
	public function testTavilyExtractUrlIsNotRenamedToUrls(): void {
		$normalizer = $this->createNormalizer();
		$context = $this->toolContext(['urls' => ['type' => 'array']], ['urls']);

		$result = $normalizer->filterToSchema(['url' => 'https://example.org'], $context);

		$this->assertSame([], $result);
		$this->assertSame(['urls'], $normalizer->missingRequiredArguments($result, $context));
	}

	public function testCatalogueOpportunityIdIsNotCorrectedFromExplicitUserId(): void {
		$normalizer = $this->createNormalizer();
		$context = $this->toolContext(['opportunity_id' => ['type' => 'integer']], ['opportunity_id']);

		$result = $normalizer->coerceToSchema(
			$normalizer->filterToSchema(['opportunity_id' => 2], $context),
			$context
		);

		$this->assertSame(2, $result['opportunity_id']);
	}

	public function testCatalogueOpportunityIdIsNotCorrectedFromYearInUserQuery(): void {
		$normalizer = $this->createNormalizer();
		$context = $this->toolContext(['opportunity_id' => ['type' => 'integer']], ['opportunity_id']);

		$result = $normalizer->coerceToSchema(
			$normalizer->filterToSchema(['opportunity_id' => 2], $context),
			$context
		);

		$this->assertSame(2, $result['opportunity_id']);
	}

	public function testCoerceToSchemaWrapsNamedArrayScalar(): void {
		$normalizer = $this->createNormalizer();

		$result = $normalizer->coerceToSchema(
			['urls' => 'https://example.org'],
			$this->toolContext([
				'urls' => ['type' => 'array'],
			])
		);

		$this->assertSame(['urls' => ['https://example.org']], $result);
	}

	public function testCoerceToSchemaCastsStringScalarsLosslessly(): void {
		$normalizer = $this->createNormalizer();

		$result = $normalizer->coerceToSchema(
			['limit' => '42', 'score' => '0.75', 'include_past' => 'false'],
			$this->toolContext([
				'limit' => ['type' => 'integer'],
				'score' => ['type' => 'number'],
				'include_past' => ['type' => 'boolean'],
			])
		);

		$this->assertSame(['limit' => 42, 'score' => 0.75, 'include_past' => false], $result);
	}

	public function testMissingRequiredArgumentsAreReported(): void {
		$normalizer = $this->createNormalizer();

		$result = $normalizer->missingRequiredArguments(
			['limit' => 5],
			$this->toolContext(['query' => ['type' => 'string']], ['query'])
		);

		$this->assertSame(['query'], $result);
	}

	private function createNormalizer(): ToolArgumentNormalizer {
		return new ToolArgumentNormalizer();
	}

	/**
	 * @param array<string,mixed> $properties
	 * @param list<string> $required
	 * @return array<string,mixed>
	 */
	private function toolContext(array $properties, array $required = []): array {
		return [
			'definition' => [
				'function' => [
					'parameters' => [
						'type' => 'object',
						'properties' => $properties,
						'required' => $required,
					],
				],
			],
		];
	}
}
