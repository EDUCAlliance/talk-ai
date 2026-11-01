<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi;

use OCA\EducAI\OpenApi\ApiExecutionException;
use OCA\EducAI\OpenApi\ApiResultProjector;
use OCA\EducAI\Service\AgentExecutor;
use PHPUnit\Framework\TestCase;

final class OpenApiIdentityRegressionTest extends TestCase {
	private function project(array $records): array {
		return (new ApiResultProjector())->project($records, ApiResultProjector::normalizeMapping([
			'id' => '/id', 'title' => '/title', 'link' => ['field' => '/link', 'origins' => ['https://records.example']],
		]), ['api' => 'Fixture', 'operation' => 'List'], 1793380000);
	}

	public function testGenericIdsRemainVerbatimAndNeverCollideOnClippedPrefixes(): void {
		$ids = [str_repeat('a', 128), str_repeat('a', 129), str_repeat('a', 140), str_repeat('a', 139) . 'b', ' a  b ', "a\tb", "a\u{202E}b", 'ä_日本'];
		$records = array_map(static fn (string $id): array => ['id' => $id, 'title' => 'Record', 'link' => 'https://records.example/item?key=verified'], $ids);
		$result = $this->project($records);
		self::assertSame($ids, array_column($result['records'], 'id'));
		self::assertFalse($result['truncated']);
		self::assertSame('unknown', $result['completeness']);
		self::assertSame(array_fill(0, count($ids), 'https://records.example/item?key=verified'), array_column($result['records'], 'url'));
		$encoded = ApiResultProjector::encode($result);
		self::assertSame($ids, array_column(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)['records'], 'id'));
		$executor = (new \ReflectionClass(AgentExecutor::class))->newInstanceWithoutConstructor();
		self::assertSame($encoded, (new \ReflectionMethod(AgentExecutor::class, 'sanitizeOutput'))->invoke($executor, ['content' => [['type' => 'text', 'text' => $encoded]]]));
	}

	public function testAnIndivisibleHugeIdFailsInsteadOfClaimingTruncatedFalse(): void {
		try {
			$this->project([['id' => str_repeat('x', 6000), 'title' => 'Huge']]);
			self::fail('Expected a bounded result error');
		} catch (ApiExecutionException $e) {
			self::assertSame('result_record_too_large', $e->getErrorCode());
		}
	}

	public function testInvalidUtf8IdIsRejectedRatherThanSubstituted(): void {
		try {
			$this->project([['id' => "x\xffy", 'title' => 'Invalid']]);
			self::fail('Expected an invalid identity error');
		} catch (ApiExecutionException $e) {
			self::assertSame('result_field_type', $e->getErrorCode());
		}
	}
}
