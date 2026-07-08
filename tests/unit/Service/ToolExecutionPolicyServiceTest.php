<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\Service\ToolExecutionPolicyService;
use PHPUnit\Framework\TestCase;

class ToolExecutionPolicyServiceTest extends TestCase {
	public function testBuiltInRagSearchGetsSearchLoopPolicy(): void {
		$service = new ToolExecutionPolicyService();

		$policy = $service->builtInPolicy(BuiltInToolProvider::TOOL_RAG_SEARCH);

		$this->assertSame(ToolExecutionPolicyService::KIND_SEARCH, $policy['kind']);
		$this->assertTrue($policy['read_only']);
		$this->assertSame(ToolExecutionPolicyService::SEARCH_TOOL_LOOP_THRESHOLD, $policy['loop_threshold']);
		$this->assertSame(10, $service->loopThresholdForToolCalls([$this->toolCall(BuiltInToolProvider::TOOL_RAG_SEARCH)], [
			BuiltInToolProvider::TOOL_RAG_SEARCH => ['policy' => $policy],
		]));
	}

	public function testBuiltInWikiWriteGetsMutatingLoopPolicy(): void {
		$service = new ToolExecutionPolicyService();

		$policy = $service->builtInPolicy(BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE);

		$this->assertSame(ToolExecutionPolicyService::KIND_WRITE, $policy['kind']);
		$this->assertFalse($policy['read_only']);
		$this->assertSame(ToolExecutionPolicyService::MUTATING_TOOL_LOOP_THRESHOLD, $policy['loop_threshold']);
		$this->assertSame(4, $service->loopThresholdForToolCalls([$this->toolCall(BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE)], [
			BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE => ['policy' => $policy],
		]));
	}

	public function testUnknownToolsFallBackToNameHeuristics(): void {
		$service = new ToolExecutionPolicyService();

		$this->assertSame(10, $service->loopThresholdForToolCalls([$this->toolCall('custom_search')]));
		$this->assertSame(4, $service->loopThresholdForToolCalls([$this->toolCall('custom_write')]));
		$this->assertSame(8, $service->loopThresholdForToolCalls([$this->toolCall('custom_inspect')]));
	}

	public function testMcpDestructiveHintWinsOverSearchLikeName(): void {
		$service = new ToolExecutionPolicyService();

		$policy = $service->mcpPolicy('search_delete', 'search_delete', 'Search and delete things.', [
			'destructiveHint' => true,
			'readOnlyHint' => true,
		]);

		$this->assertSame(ToolExecutionPolicyService::KIND_WRITE, $policy['kind']);
		$this->assertFalse($policy['read_only']);
		$this->assertTrue($policy['destructive']);
		$this->assertSame(ToolExecutionPolicyService::MUTATING_TOOL_LOOP_THRESHOLD, $policy['loop_threshold']);
		$this->assertSame('mcp_annotations', $policy['source']);
	}

	public function testMcpReadOnlyHintWinsOverMutatingName(): void {
		$service = new ToolExecutionPolicyService();

		$policy = $service->mcpPolicy('delete_preview', 'delete_preview', 'Preview what would be deleted.', [
			'readOnlyHint' => true,
		]);

		$this->assertSame(ToolExecutionPolicyService::KIND_READ, $policy['kind']);
		$this->assertTrue($policy['read_only']);
		$this->assertFalse($policy['destructive']);
		$this->assertSame(ToolExecutionPolicyService::GENERAL_TOOL_LOOP_THRESHOLD, $policy['loop_threshold']);
		$this->assertSame('mcp_annotations', $policy['source']);
	}

	public function testUnknownMcpToolWithoutAnnotationsIsConservative(): void {
		$service = new ToolExecutionPolicyService();

		$policy = $service->mcpPolicy('custom_inspect', 'custom_inspect', 'Inspect something.');

		$this->assertSame(ToolExecutionPolicyService::KIND_UNKNOWN, $policy['kind']);
		$this->assertFalse($policy['read_only']);
		$this->assertFalse($policy['idempotent']);
		$this->assertSame(ToolExecutionPolicyService::MUTATING_TOOL_LOOP_THRESHOLD, $policy['loop_threshold']);
	}

	public function testForcedSearchScoringUsesGenericSearchSchemaWithoutTavilyBonus(): void {
		$service = new ToolExecutionPolicyService();

		$tavilyScore = $service->scoreForcedSearchToolCandidate('tavily_search', $this->searchContext('tavily_search', 'Search the web and internet using Tavily.'));
		$genericScore = $service->scoreForcedSearchToolCandidate('web_search', $this->searchContext('web_search', 'Search the web and internet.'));
		$extractScore = $service->scoreForcedSearchToolCandidate('tavily_extract', $this->searchContext('tavily_extract', 'Extract page content from URLs.'));

		$this->assertSame($genericScore, $tavilyScore);
		$this->assertGreaterThan(0, $genericScore);
		$this->assertSame(0, $extractScore);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function toolCall(string $name): array {
		return [
			'function' => [
				'name' => $name,
				'arguments' => '{}',
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function searchContext(string $name, string $description): array {
		return [
			'invokeName' => $name,
			'policy' => [
				'kind' => ToolExecutionPolicyService::KIND_SEARCH,
				'read_only' => true,
				'idempotent' => true,
				'destructive' => false,
			],
			'definition' => [
				'function' => [
					'description' => $description,
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'query' => ['type' => 'string'],
						],
						'required' => ['query'],
					],
				],
			],
		];
	}
}
