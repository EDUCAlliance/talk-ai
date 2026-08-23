<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\Tool;
use OCA\EducAI\Exception\AgentRunInterruptedException;
use OCA\EducAI\Exception\IncompleteProviderStreamException;
use OCA\EducAI\Exception\ProviderAttemptBudgetExceededException;
use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\AgentRunControl;
use OCA\EducAI\Service\AgentTurn;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\McpClient;
use OCA\EducAI\Service\ProviderAttemptBudget;
use OCA\EducAI\Service\ProviderResponseNormalizer;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\ToolCallIdGenerator;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\TraceService;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AgentExecutorTest extends TestCase {
	public function testPlainFinalTextUsesOneLogicalTurnAndNoTools(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness();
		$text = " Exact answer.\n";
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns($llmClient, [$this->turn($text, [], 'stop', ['remaining' => '7'])]);

		$result = $executor->run('system', [['role' => 'user', 'content' => 'Question']], []);

		$this->assertSame('completed', $result['status']);
		$this->assertSame('final_response', $result['terminalReason']);
		$this->assertSame($text, $result['content']);
		$this->assertSame('stop', $result['finishReason']);
		$this->assertSame(AgentTurn::COMPATIBILITY_NATIVE, $result['compatibilitySource']);
		$this->assertSame(1, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
		$this->assertSame(['remaining' => '7'], $result['rateLimitHeaders']);
		$this->assertSame([], $result['toolInvocations']);
		$this->assertSame($text, $result['messages'][1]['content']);
	}

	#[DataProvider('naturalQueries')]
	public function testNaturalQueryDoesNotCauseHarnessInventedToolCall(string $query): void {
		[$executor, $llmClient, , $toolProvider, $mcpClient] = $this->createHarness([$this->searchToolDefinition()]);
		$providerAnswer = 'Plain provider answer.';
		$toolProvider->expects($this->never())->method('executeTool');
		$mcpClient->expects($this->never())->method('callTool');
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn($providerAnswer, [], 'stop')],
			function (int $index, array $messages, array $knownToolNames, array $options) use ($query): void {
				$this->assertSame(0, $index);
				$this->assertSame($query, $messages[0]['content'] ?? null);
				$this->assertSame(['search_test'], $knownToolNames);
				$this->assertNull($options['tool_choice']);
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => $query]],
			[],
			$this->builtInOptions(['search_test'])
		);

		$this->assertSame('completed', $result['status']);
		$this->assertSame('final_response', $result['terminalReason']);
		$this->assertSame($providerAnswer, $result['content']);
		$this->assertSame(1, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
		$this->assertSame([], $result['toolInvocations']);
		$this->assertArrayNotHasKey('tool_calls', $result['messages'][1]);
	}

	/** @return array<string,array{string}> */
	public static function naturalQueries(): array {
		return [
			'definition wording' => ['was ist Photosynthese?'],
			'today wording' => ['heute brauche ich eine kurze Zusammenfassung.'],
			'internet-search wording' => ['suche im Internet nach aktuellen Informationen.'],
		];
	}

	public function testPlainFinalTurnStopsWhenProviderIgnoresRequestedInitialToolChoice(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$requestedChoice = [
			'type' => 'function',
			'function' => ['name' => 'search_test'],
		];
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn('Provider chose a final answer.', [], 'stop')],
			function (int $index, array $messages, array $knownToolNames, array $options) use ($requestedChoice): void {
				$this->assertSame(0, $index);
				$this->assertSame(['search_test'], $knownToolNames);
				$this->assertSame($requestedChoice, $options['tool_choice']);
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Answer directly if no tool is needed']],
			[],
			$this->builtInOptions(['search_test']) + ['initial_tool_choice' => $requestedChoice]
		);

		$this->assertSame('completed', $result['status']);
		$this->assertSame('Provider chose a final answer.', $result['content']);
		$this->assertSame(1, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
		$this->assertSame([], $result['toolInvocations']);
	}

	public function testNativeToolCallExecutesOnceAndPreservesProviderId(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$call = $this->toolCall('provider-call-17', 'search_test', '{"query":"Berlin"}');
		$toolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Berlin'])
			->willReturn(['content' => [['type' => 'text', 'text' => 'Berlin result']]]);
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn('', [$call], 'tool_calls'), $this->turn('Final answer', [], 'stop')],
			function (int $index, array $messages, array $knownToolNames, array $options): void {
				$this->assertSame(['search_test'], $knownToolNames);
				if ($index === 0) {
					$this->assertSame([
						'type' => 'function',
						'function' => ['name' => 'search_test'],
					], $options['tool_choice']);
					return;
				}
				$this->assertSame('auto', $options['tool_choice']);
				$this->assertSame('provider-call-17', $messages[1]['tool_calls'][0]['id']);
				$this->assertSame('provider-call-17', $messages[2]['tool_call_id']);
				$this->assertArrayNotHasKey('name', $messages[2]);
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Find Berlin']],
			[],
			$this->builtInOptions(['search_test']) + [
				'tool_choice' => 'auto',
				'initial_tool_choice' => [
					'type' => 'function',
					'function' => ['name' => 'search_test'],
				],
			]
		);

		$this->assertSame('Final answer', $result['content']);
		$this->assertSame(2, $result['logicalTurns']);
		$this->assertSame(2, $result['providerAttempts']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('provider-call-17', $result['toolInvocations'][0]['toolCallId']);
		$this->assertSame('ok', $result['toolInvocations'][0]['status']);
	}

	public function testMissingProviderIdGetsNineCharacterIdAndKeepsCorrelation(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$normalizer = new ProviderResponseNormalizer(new ToolCallIdGenerator());
		$toolTurn = $normalizer->normalize([
			'content' => '',
			'tool_calls' => [[
				'type' => 'function',
				'function' => ['name' => 'search_test', 'arguments' => '{"query":"Potsdam"}'],
			]],
			'finish_reason' => 'tool_calls',
		], ['search_test']);
		$generatedId = $toolTurn->getToolCalls()[0]['id'];

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{9}$/', $generatedId);
		$toolProvider->expects($this->once())->method('executeTool')->willReturn(['text' => 'Potsdam result']);
		$this->expectSyncTurns(
			$llmClient,
			[$toolTurn, $this->turn('Done', [], 'stop')],
			function (int $index, array $messages) use ($generatedId): void {
				if ($index === 1) {
					$this->assertSame($generatedId, $messages[1]['tool_calls'][0]['id']);
					$this->assertSame($generatedId, $messages[2]['tool_call_id']);
				}
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Find Potsdam']],
			[],
			$this->builtInOptions(['search_test'])
		);
		$this->assertSame($generatedId, $result['toolInvocations'][0]['toolCallId']);
	}

	public function testInvalidArgumentJsonIsExplicitAndNeverExecutedAsEmptyObject(): void {
		$traceService = $this->createMock(TraceService::class);
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()], $traceService);
		$invalidCall = $this->toolCall('invalid-json', 'search_test', '{broken');
		$invalidCall['argument_error'] = ProviderResponseNormalizer::ARGUMENT_ERROR_INVALID_JSON;
		$toolProvider->expects($this->never())->method('executeTool');
		$traceService->expects($this->never())->method('recordToolCall');
		$traceService->expects($this->atLeastOnce())->method('recordEvent');
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn('', [$invalidCall], 'tool_calls'), $this->turn('Corrected', [], 'stop')],
			function (int $index, array $messages): void {
				if ($index === 1) {
					$this->assertSame('{broken', $messages[1]['tool_calls'][0]['function']['arguments']);
					$this->assertArrayNotHasKey('argument_error', $messages[1]['tool_calls'][0]);
					$this->assertSame('invalid_tool_arguments_json', $this->decodeToolError($messages[2]['content'])['code']);
				}
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search']],
			[],
			$this->builtInOptions(['search_test']) + ['trace_run_id' => 77]
		);
		$this->assertSame('Corrected', $result['content']);
		$this->assertSame('error', $result['toolInvocations'][0]['status']);
		$this->assertStringContainsString('invalid_tool_arguments_json', $result['toolInvocations'][0]['response']);
	}

	public function testUnknownAndMalformedToolsBecomeOrderedStructuredErrors(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness();
		$malformedCall = (new ProviderResponseNormalizer(new ToolCallIdGenerator()))->normalize([
			'content' => '',
			'tool_calls' => [[
				'id' => 'malformed-id',
				'type' => 'function',
				'function' => ['name' => '', 'arguments' => '{}'],
			]],
			'finish_reason' => 'tool_calls',
		], [])->getToolCalls()[0];
		$calls = [
			$this->toolCall('unknown-id', 'not_available', '{}'),
			$malformedCall,
		];
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn('', $calls, 'tool_calls'), $this->turn('Recovered', [], 'stop')],
			function (int $index, array $messages): void {
				if ($index === 1) {
					$this->assertSame(['unknown-id', 'malformed-id'], [
						$messages[2]['tool_call_id'],
						$messages[3]['tool_call_id'],
					]);
					$this->assertSame('unknown_tool', $this->decodeToolError($messages[2]['content'])['code']);
					$this->assertSame('malformed_tool_call', $this->decodeToolError($messages[3]['content'])['code']);
				}
			}
		);

		$result = $executor->run('system', [['role' => 'user', 'content' => 'Use tools']], []);
		$this->assertSame(['not_available', 'unknown'], array_column($result['toolInvocations'], 'tool'));
		$this->assertSame(['error', 'error'], array_column($result['toolInvocations'], 'status'));
	}

	public function testMissingRequiredArgumentsBecomeStructuredErrorWithoutExecution(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns(
			$llmClient,
			[
				$this->turn('', [$this->toolCall('missing-query', 'search_test', '{}')], 'tool_calls'),
				$this->turn('Please provide a query.', [], 'stop'),
			],
			function (int $index, array $messages): void {
				if ($index === 1) {
					$error = $this->decodeToolError($messages[2]['content']);
					$this->assertSame('missing_required_arguments', $error['code']);
					$this->assertSame(['query'], $error['details']['missing']);
				}
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search']],
			[],
			$this->builtInOptions(['search_test'])
		);
		$this->assertSame('Please provide a query.', $result['content']);
		$this->assertSame('error', $result['toolInvocations'][0]['status']);
	}

	public function testMultipleToolCallsExecuteSequentiallyInProviderOrder(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([
			$this->searchToolDefinition('search_one'),
			$this->searchToolDefinition('search_two'),
		]);
		$order = [];
		$toolProvider->expects($this->exactly(2))
			->method('executeTool')
			->willReturnCallback(function (string $name, array $arguments) use (&$order): array {
				$order[] = $name;
				return ['text' => $arguments['query'] . ' result'];
			});
		$this->expectSyncTurns(
			$llmClient,
			[
				$this->turn('', [
					$this->toolCall('call-one', 'search_one', '{"query":"one"}'),
					$this->toolCall('call-two', 'search_two', '{"query":"two"}'),
				], 'tool_calls'),
				$this->turn('Combined answer', [], 'stop'),
			],
			function (int $index, array $messages): void {
				if ($index === 1) {
					$this->assertSame(['call-one', 'call-two'], [$messages[2]['tool_call_id'], $messages[3]['tool_call_id']]);
				}
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search twice']],
			[],
			$this->builtInOptions(['search_one', 'search_two'])
		);
		$this->assertSame(['search_one', 'search_two'], $order);
		$this->assertSame(['search_one', 'search_two'], array_column($result['toolInvocations'], 'tool'));
	}

	public function testLengthTruncatedToolCallIsNotExecutedAndPreservesFinishReason(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns($llmClient, [
			$this->turn('', [$this->toolCall('cut-off', 'search_test', '{"query":"Ber')], 'length'),
		]);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search']],
			[],
			$this->builtInOptions(['search_test']) + ['max_turns' => 1]
		);
		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame('max_turns', $result['terminalReason']);
		$this->assertSame('length', $result['finishReason']);
		$this->assertSame('incomplete_tool_call', $this->decodeToolError($result['messages'][2]['content'])['code']);
	}

	public function testSyncAndStreamingHaveSameTerminalSemanticState(): void {
		[$syncExecutor, $syncLlm] = $this->createHarness();
		[$streamExecutor, $streamLlm] = $this->createHarness();
		$turn = $this->turn('Streamed final text', [], 'stop', ['remaining' => 3]);
		$this->expectSyncTurns($syncLlm, [$turn]);
		$this->expectStreamTurns($streamLlm, [$turn], [['Streamed ', 'final text']]);

		$sync = $syncExecutor->run('system', [['role' => 'user', 'content' => 'Question']], []);
		$partials = [];
		$stream = $streamExecutor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['on_partial_result' => static function (string $partial) use (&$partials): void {
				$partials[] = $partial;
			}]
		);
		$this->assertSame($sync, $stream);
		$this->assertSame(['Streamed ', 'final text'], $partials);
		$this->assertSame($stream['content'], implode('', $partials));
	}

	public function testStreamingFinalTextWithoutProviderDeltasUsesOneFallbackCallback(): void {
		[$executor, $llmClient] = $this->createHarness();
		$this->expectStreamTurns($llmClient, [$this->turn('Fallback final text')], [[]]);
		$partials = [];

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['on_partial_result' => static function (string $partial) use (&$partials): void {
				$partials[] = $partial;
			}]
		);

		$this->assertSame('Fallback final text', $result['content']);
		$this->assertSame(['Fallback final text'], $partials);
	}

	public function testIncompleteMutatingToolStreamReturnsProviderErrorWithoutExecution(): void {
		$toolName = BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE;
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([
			$this->toolDefinition($toolName),
		]);
		$toolProvider->expects($this->never())->method('executeTool');
		$llmClient->expects($this->once())
			->method('streamAgentTurn')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				array $knownToolNames,
				callable $onChunk,
				?string $model,
				array $options,
			) use ($toolName): AgentTurn {
				$options['provider_attempt_budget']->consume();
				$onChunk([
					'tool_calls' => [[
						'index' => 0,
						'id' => 'partial-write',
						'function' => [
							'name' => $toolName,
							'arguments' => '{"path":"unsafe.md"',
						],
					]],
				]);
				throw new IncompleteProviderStreamException();
			});
		$partials = [];

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Write']],
			[],
			$this->builtInOptions([$toolName]) + [
				'on_partial_result' => static function (string $partial) use (&$partials): void {
					$partials[] = $partial;
				},
			]
		);

		$this->assertSame('error', $result['status']);
		$this->assertSame('provider_error', $result['terminalReason']);
		$this->assertSame(0, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
		$this->assertSame([], $result['toolInvocations']);
		$this->assertSame([], $partials);
		$this->assertCount(1, $result['messages']);
	}

	public function testIncompleteSyncMutatingToolTurnReturnsProviderErrorWithoutExecution(): void {
		$toolName = BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE;
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([
			$this->toolDefinition($toolName),
		]);
		$toolProvider->expects($this->never())->method('executeTool');
		$llmClient->expects($this->once())
			->method('sendAgentTurn')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				array $knownToolNames,
				?string $model,
				array $options,
			) use ($toolName): AgentTurn {
				$this->assertContains($toolName, $knownToolNames);
				$options['provider_attempt_budget']->consume();
				// LLMClient rejects a non-empty tool-call payload with no terminal finish metadata.
				throw new IncompleteProviderStreamException();
			});

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Write']],
			[],
			$this->builtInOptions([$toolName])
		);

		$this->assertSame('error', $result['status']);
		$this->assertSame('provider_error', $result['terminalReason']);
		$this->assertSame(0, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
		$this->assertSame([], $result['toolInvocations']);
		$this->assertCount(1, $result['messages']);
	}

	public function testTerminalFinishReasonsRemainDistinguishable(): void {
		foreach (['stop', 'length', 'content_filter'] as $finishReason) {
			[$executor, $llmClient] = $this->createHarness();
			$this->expectSyncTurns($llmClient, [$this->turn('Text ' . $finishReason, [], $finishReason)]);
			$result = $executor->run('system', [['role' => 'user', 'content' => 'Question']], []);
			$this->assertSame($finishReason, $result['finishReason']);
			$this->assertSame('completed', $result['status']);
		}

		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$toolProvider->expects($this->once())->method('executeTool')->willReturn(['text' => 'result']);
		$this->expectSyncTurns($llmClient, [
			$this->turn('', [$this->toolCall('finish-tool', 'search_test', '{"query":"x"}')], 'tool_calls'),
		]);
		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			$this->builtInOptions(['search_test']) + ['max_turns' => 1]
		);
		$this->assertSame('tool_calls', $result['finishReason']);
		$this->assertSame('budget_exhausted', $result['status']);
	}

	public function testEmptySuccessfulTurnIsTypedErrorWithoutExtraRequestOrFallback(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns($llmClient, [$this->turn('', [], 'stop')]);
		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			$this->builtInOptions(['search_test'])
		);
		$this->assertSame('error', $result['status']);
		$this->assertSame('empty_response', $result['terminalReason']);
		$this->assertSame('', $result['content']);
		$this->assertSame(1, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
	}

	public function testTurnExhaustionMakesNoAdditionalModelRequest(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$toolProvider->expects($this->once())->method('executeTool')->willReturn(['text' => 'result']);
		$this->expectSyncTurns($llmClient, [
			$this->turn('', [$this->toolCall('only-turn', 'search_test', '{"query":"x"}')], 'tool_calls'),
		]);
		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			$this->builtInOptions(['search_test']) + ['max_turns' => 1]
		);
		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame('max_turns', $result['terminalReason']);
		$this->assertSame(1, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
	}

	public function testWholeBatchToolBudgetStopsBeforeAnySideEffect(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([
			$this->searchToolDefinition('search_one'),
			$this->searchToolDefinition('search_two'),
		]);
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns($llmClient, [
			$this->turn('', [
				$this->toolCall('one', 'search_one', '{"query":"one"}'),
				$this->toolCall('two', 'search_two', '{"query":"two"}'),
			], 'tool_calls'),
		]);
		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			$this->builtInOptions(['search_one', 'search_two']) + ['max_tool_calls' => 1]
		);
		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame('max_tool_calls', $result['terminalReason']);
		$this->assertSame([], $result['toolInvocations']);
	}

	public function testFourEffectivelyIdenticalMutatingCallsInOneBatchStopBeforeAnySideEffect(): void {
		$toolName = BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE;
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->never())->method('recordToolCall');
		[$executor, $llmClient, , $toolProvider] = $this->createHarness(
			[$this->toolDefinition($toolName, [
				'type' => 'object',
				'properties' => [
					'path' => ['type' => 'string'],
					'content' => ['type' => 'string'],
					'revision' => ['type' => 'integer'],
				],
				'required' => ['path', 'content'],
			])],
			$traceService
		);
		$toolProvider->expects($this->never())->method('executeTool');
		$calls = [];
		for ($index = 1; $index <= 4; $index++) {
			$calls[] = $this->toolCall(
				'write-' . $index,
				$toolName,
				(string)json_encode([
					'path' => 'same.md',
					'content' => 'same content',
					'revision' => $index % 2 === 0 ? 7 : '7',
					'nonce' => 'ignored-' . $index,
				], JSON_THROW_ON_ERROR)
			);
		}
		$this->expectSyncTurns($llmClient, [$this->turn('', $calls, 'tool_calls')]);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Write repeatedly']],
			[],
			$this->builtInOptions([$toolName])
		);

		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame('repetition_limit', $result['terminalReason']);
		$this->assertSame([], $result['toolInvocations']);
		$this->assertSame(1, $result['logicalTurns']);
		$this->assertCount(2, $result['messages']);
	}

	public function testOrdinaryJsonAndXmlAssistantProseRemainExactText(): void {
		foreach ([
			'{"name":"search_test","arguments":{"query":"Berlin"}}',
			'Before <tool_call>{"name":"search_test","arguments":{}}</tool_call> after',
		] as $content) {
			[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
			$toolProvider->expects($this->never())->method('executeTool');
			$this->expectSyncTurns($llmClient, [$this->turn($content, [], 'stop')]);
			$result = $executor->run(
				'system',
				[['role' => 'user', 'content' => 'Show syntax']],
				[],
				$this->builtInOptions(['search_test'])
			);
			$this->assertSame($content, $result['content']);
			$this->assertSame([], $result['toolInvocations']);
		}
	}

	public function testMutatingRepetitionBudgetStopsBeforeFourthSideEffect(): void {
		$toolName = BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE;
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([
			$this->toolDefinition($toolName),
		]);
		$toolProvider->expects($this->exactly(3))
			->method('executeTool')
			->with($toolName, [])
			->willReturn(['text' => 'written']);
		$this->expectSyncTurns($llmClient, [
			$this->turn('', [$this->toolCall('write-1', $toolName, '{}')], 'tool_calls'),
			$this->turn('', [$this->toolCall('write-2', $toolName, '{}')], 'tool_calls'),
			$this->turn('', [$this->toolCall('write-3', $toolName, '{}')], 'tool_calls'),
			$this->turn('', [$this->toolCall('write-4', $toolName, '{}')], 'tool_calls'),
		]);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Write repeatedly']],
			[],
			$this->builtInOptions([$toolName])
		);

		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame('repetition_limit', $result['terminalReason']);
		$this->assertSame(4, $result['logicalTurns']);
		$this->assertSame(4, $result['providerAttempts']);
		$this->assertCount(3, $result['toolInvocations']);
		$this->assertSame(['write-1', 'write-2', 'write-3'], array_column($result['toolInvocations'], 'toolCallId'));
	}

	public function testProviderAttemptBudgetExceptionReturnsTypedBudgetResult(): void {
		[$executor, $llmClient] = $this->createHarness();
		$llmClient->expects($this->once())
			->method('sendAgentTurn')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				array $knownToolNames,
				?string $model,
				array $options,
			): AgentTurn {
				/** @var ProviderAttemptBudget $budget */
				$budget = $options['provider_attempt_budget'];
				$this->assertSame(1, $budget->getLimit());
				$budget->consume();
				$budget->consume();
				$this->fail('The second provider attempt must exhaust the shared budget.');
			});

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['max_provider_attempts' => 1]
		);

		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame('provider_attempts', $result['terminalReason']);
		$this->assertSame(0, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
		$this->assertSame([], $result['toolInvocations']);
	}

	public function testProviderAttemptLimitIsHardCappedAndCompatibilitySourcePropagates(): void {
		[$executor, $llmClient] = $this->createHarness();
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn('Legacy answer', [], 'stop', [], AgentTurn::COMPATIBILITY_LEGACY_XML)],
			function (int $index, array $messages, array $knownToolNames, array $options): void {
				$this->assertSame(48, $options['provider_attempt_budget']->getLimit());
				$this->assertSame('xml', $options['legacy_tool_call_compatibility']);
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			[
				'max_provider_attempts' => 999,
				'legacy_tool_call_compatibility' => 'xml',
			]
		);

		$this->assertSame('completed', $result['status']);
		$this->assertSame(AgentTurn::COMPATIBILITY_LEGACY_XML, $result['compatibilitySource']);
	}

	public function testWallClockBudgetStopsBeforeFirstProviderCall(): void {
		$clockValues = [10.0, 15.0];
		$clock = static function () use (&$clockValues): float {
			return array_shift($clockValues) ?? 15.0;
		};
		[$executor, $llmClient] = $this->createHarness([], null, $clock);
		$llmClient->expects($this->never())->method('sendAgentTurn');
		$llmClient->expects($this->never())->method('streamAgentTurn');

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['max_wall_clock_seconds' => 5]
		);

		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame(AgentRunInterruptedException::REASON_WALL_CLOCK, $result['terminalReason']);
		$this->assertSame(0, $result['logicalTurns']);
		$this->assertSame(0, $result['providerAttempts']);
	}

	public function testWallClockBudgetStopsBeforeToolSideEffect(): void {
		$clockValues = [20.0, 20.0, 20.0, 26.0];
		$clock = static function () use (&$clockValues): float {
			return array_shift($clockValues) ?? 26.0;
		};
		[$executor, $llmClient, , $toolProvider] = $this->createHarness(
			[$this->searchToolDefinition()],
			null,
			$clock
		);
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns($llmClient, [
			$this->turn('', [$this->toolCall('timed-out-tool', 'search_test', '{"query":"x"}')], 'tool_calls'),
		]);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search']],
			[],
			$this->builtInOptions(['search_test']) + ['max_wall_clock_seconds' => 5]
		);

		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame(AgentRunInterruptedException::REASON_WALL_CLOCK, $result['terminalReason']);
		$this->assertSame(1, $result['logicalTurns']);
		$this->assertSame(1, $result['providerAttempts']);
		$this->assertSame([], $result['toolInvocations']);
	}

	public function testAbortStopsBeforeProviderCallWithTypedResult(): void {
		[$executor, $llmClient] = $this->createHarness();
		$llmClient->expects($this->never())->method('sendAgentTurn');
		$llmClient->expects($this->never())->method('streamAgentTurn');

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['abort_callback' => static fn (): bool => true]
		);

		$this->assertSame('error', $result['status']);
		$this->assertSame(AgentRunInterruptedException::REASON_ABORTED, $result['terminalReason']);
		$this->assertSame(0, $result['logicalTurns']);
		$this->assertSame(0, $result['providerAttempts']);
	}

	public function testAbortStopsBeforeMcpDiscoveryWithoutExternalCall(): void {
		$tool = $this->mcpTool();
		[$executor, $llmClient, , , $mcpClient] = $this->createHarness();
		$mcpClient->expects($this->never())->method('listTools');
		$llmClient->expects($this->never())->method('sendAgentTurn');
		$llmClient->expects($this->never())->method('streamAgentTurn');

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[['tool' => $tool, 'config' => []]],
			['abort_callback' => static fn (): bool => true]
		);

		$this->assertSame('error', $result['status']);
		$this->assertSame(AgentRunInterruptedException::REASON_ABORTED, $result['terminalReason']);
		$this->assertSame(0, $result['logicalTurns']);
		$this->assertSame(0, $result['providerAttempts']);
	}

	public function testMcpDiscoveryUsesSharedRemainingDeadlineAndStopsBeforeNextEndpoint(): void {
		$now = 100.0;
		$clock = static function () use (&$now): float {
			return $now;
		};
		$firstTool = $this->mcpTool();
		$secondTool = $this->mcpTool();
		$secondTool->setId(2);
		[$executor, $llmClient, , , $mcpClient] = $this->createHarness([], null, $clock);
		$llmClient->expects($this->never())->method('sendAgentTurn');
		$llmClient->expects($this->never())->method('streamAgentTurn');
		$mcpClient->expects($this->once())
			->method('listTools')
			->willReturnCallback(function (
				Tool $tool,
				array $context,
				?AgentRunControl $runControl,
			) use (&$now, $firstTool): array {
				$this->assertSame($firstTool, $tool);
				$this->assertSame([], $context);
				$this->assertInstanceOf(AgentRunControl::class, $runControl);
				$now = 104.0;
				$this->assertSame(1.0, $runControl->clampTimeout(60));
				$now = 105.0;
				return [];
			});

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[
				['tool' => $firstTool, 'config' => []],
				['tool' => $secondTool, 'config' => []],
			],
			['max_wall_clock_seconds' => 5]
		);

		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame(AgentRunInterruptedException::REASON_WALL_CLOCK, $result['terminalReason']);
		$this->assertSame(0, $result['logicalTurns']);
		$this->assertSame(0, $result['providerAttempts']);
	}

	public function testAbortIsRecheckedBeforeEveryToolSideEffect(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([
			$this->searchToolDefinition('search_one'),
			$this->searchToolDefinition('search_two'),
		]);
		$toolProvider->expects($this->once())
			->method('executeTool')
			->with('search_one', ['query' => 'one'])
			->willReturn(['text' => 'one result']);
		$this->expectSyncTurns($llmClient, [
			$this->turn('', [
				$this->toolCall('first', 'search_one', '{"query":"one"}'),
				$this->toolCall('second', 'search_two', '{"query":"two"}'),
			], 'tool_calls'),
		]);
		$abortChecks = 0;

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search twice']],
			[],
			$this->builtInOptions(['search_one', 'search_two']) + [
				'abort_callback' => static function () use (&$abortChecks): bool {
					$abortChecks++;
					return $abortChecks >= 4;
				},
			]
		);

		$this->assertSame('error', $result['status']);
		$this->assertSame(AgentRunInterruptedException::REASON_ABORTED, $result['terminalReason']);
		$this->assertSame(4, $abortChecks);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('first', $result['toolInvocations'][0]['toolCallId']);
	}

	public function testRunControlClampsTimeoutAndUsesHardCappedWallClockLimit(): void {
		$now = 100.0;
		$control = new AgentRunControl(900, null, static function () use (&$now): float {
			return $now;
		});
		$now = 102.5;
		$this->assertSame(897.5, $control->getRemainingSeconds());
		$this->assertSame(30.0, $control->clampTimeout(30));
		$now = 999.5;
		$this->assertSame(0.5, $control->clampTimeout(30));
		$now = 1000.0;

		try {
			$control->assertCanContinue();
			$this->fail('The wall-clock deadline must interrupt the run.');
		} catch (AgentRunInterruptedException $e) {
			$this->assertSame(AgentRunInterruptedException::REASON_WALL_CLOCK, $e->getReason());
		}

		[$executor, $llmClient] = $this->createHarness();
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn('Done')],
			function (int $index, array $messages, array $knownToolNames, array $options): void {
				$this->assertInstanceOf(AgentRunControl::class, $options['agent_run_control']);
				$this->assertSame(900, $options['agent_run_control']->getMaxWallClockSeconds());
			}
		);
		$executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['max_wall_clock_seconds' => 9999]
		);
	}

	public function testTracePreparationDoesNotSwallowRunInterruption(): void {
		$traceService = $this->createMock(TraceService::class);
		[$executor, $llmClient] = $this->createHarness([], $traceService);
		$llmClient->expects($this->once())
			->method('buildTraceChatCompletionPayload')
			->willThrowException(new AgentRunInterruptedException(AgentRunInterruptedException::REASON_WALL_CLOCK));
		$llmClient->expects($this->never())->method('sendAgentTurn');

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['trace_run_id' => 7]
		);

		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame(AgentRunInterruptedException::REASON_WALL_CLOCK, $result['terminalReason']);
		$this->assertSame(0, $result['logicalTurns']);
	}

	public function testTracePreparationDoesNotSwallowProviderAttemptExhaustion(): void {
		$traceService = $this->createMock(TraceService::class);
		[$executor, $llmClient] = $this->createHarness([], $traceService);
		$llmClient->expects($this->once())
			->method('buildTraceChatCompletionPayload')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				?string $model,
				array $options,
			): array {
				$options['provider_attempt_budget']->consume();
				throw new ProviderAttemptBudgetExceededException(1, 1);
			});
		$llmClient->expects($this->never())->method('sendAgentTurn');

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['trace_run_id' => 7, 'max_provider_attempts' => 1]
		);

		$this->assertSame('budget_exhausted', $result['status']);
		$this->assertSame('provider_attempts', $result['terminalReason']);
		$this->assertSame(1, $result['providerAttempts']);
		$this->assertSame(0, $result['logicalTurns']);
	}

	public function testTracePreflightDiscoveryErrorIsSecretSafeAndDoesNotBlockRealTurn(): void {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://primary.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('model-a');
		$settings->setLlmModelsTimeout(25);
		$settings->setLlmChatTimeout(90);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('primary-key');
		$settingsService->method('normalizePositiveInteger')
			->willReturnCallback(static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'model' => 'model-a',
			'choices' => [[
				'message' => ['content' => 'safe answer'],
				'finish_reason' => 'stop',
			]],
		]) ?: '');
		$response->method('getHeader')->willReturn('');
		$response->method('getStatusCode')->willReturn(200);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with('https://primary.example.invalid/v1/models')
			->willThrowException(new \Error('discovery failed at https://secret.example.invalid using api-key-secret'));
		$client->expects($this->once())
			->method('post')
			->with('https://primary.example.invalid/v1/chat/completions')
			->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$events = [];
		$traceService = $this->createMock(TraceService::class);
		$traceService->method('recordEvent')
			->willReturnCallback(static function (?int $runId, string $eventType, array $event = []) use (&$events): void {
				$events[] = [
					'run_id' => $runId,
					'event_type' => $eventType,
					'event' => $event,
				];
			});
		$llmClient = new LLMClient(
			$clientService,
			$settingsService,
			$this->createMock(LoggerInterface::class),
			null,
			$traceService,
		);
		$toolProvider = $this->createMock(ToolProviderRegistry::class);
		$toolProvider->method('getAvailableTools')->willReturn([]);
		$executor = new AgentExecutor(
			$llmClient,
			$this->createMock(McpClient::class),
			$this->createMock(ToolRegistry::class),
			$toolProvider,
			$this->createMock(LoggerInterface::class),
			null,
			null,
			$traceService,
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['trace_run_id' => 73, 'max_provider_attempts' => 2]
		);

		$this->assertSame('completed', $result['status']);
		$this->assertSame('safe answer', $result['content']);
		$this->assertSame(1, $result['logicalTurns']);
		$this->assertSame(2, $result['providerAttempts']);

		$llmRequestEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'llm_request'
		));
		$this->assertCount(1, $llmRequestEvents);
		$this->assertSame(
			'provider_trace_preflight_failed',
			$llmRequestEvents[0]['event']['payload']['trace_payload_error'] ?? null,
		);
		$providerAttemptEvents = array_values(array_filter(
			$events,
			static fn (array $event): bool => $event['event_type'] === 'provider_attempt'
		));
		$this->assertCount(2, $providerAttemptEvents);
		$this->assertSame(['error', 'ok'], array_column(array_column($providerAttemptEvents, 'event'), 'status'));
		$this->assertSame(['model_discovery', 'selected'], array_map(
			static fn (array $event): string => $event['event']['payload']['route'],
			$providerAttemptEvents
		));

		$traceJson = json_encode($events) ?: '';
		$this->assertStringNotContainsString('secret.example.invalid', $traceJson);
		$this->assertStringNotContainsString('api-key-secret', $traceJson);
		$this->assertStringNotContainsString('discovery failed', $traceJson);
	}

	public function testExplicitBuiltInLoadoutPreservesConfigAndOmitsUnassignedTools(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([
			$this->searchToolDefinition('configured_search'),
			$this->searchToolDefinition('not_assigned'),
		]);
		$toolProvider->expects($this->once())
			->method('executeTool')
			->with('configured_search', ['query' => 'x'], ['scope' => 'course-7'])
			->willReturn(['text' => 'result']);
		$this->expectSyncTurns(
			$llmClient,
			[
				$this->turn('', [$this->toolCall('configured', 'configured_search', '{"query":"x"}')], 'tool_calls'),
				$this->turn('Done', [], 'stop'),
			],
			function (int $index, array $messages, array $knownToolNames, array $options): void {
				$this->assertSame(['configured_search'], $knownToolNames);
				$this->assertSame(['configured_search'], array_column(array_column($options['tools'], 'function'), 'name'));
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search']],
			[],
			[
				'built_in_tools' => [[
					'name' => 'configured_search',
					'config' => ['scope' => 'course-7'],
				]],
			]
		);

		$this->assertSame('Done', $result['content']);
		$this->assertSame(['query' => 'x'], $result['toolInvocations'][0]['arguments']);
	}

	public function testExplicitEmptyBuiltInLoadoutIsAuthoritativeWithBotId(): void {
		[$executor, $llmClient, $toolRegistry, $toolProvider] = $this->createHarness([
			$this->toolDefinition(BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE),
		]);
		$toolRegistry->expects($this->never())->method('getBuiltInToolsForBot');
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn('No tools loaded')],
			function (int $index, array $messages, array $knownToolNames, array $options): void {
				$this->assertSame([], $knownToolNames);
				$this->assertSame([], $options['tools']);
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Question']],
			[],
			['bot_id' => 99, 'built_in_tools' => []]
		);

		$this->assertSame('completed', $result['status']);
		$this->assertSame('No tools loaded', $result['content']);
		$this->assertSame([], $result['toolInvocations']);
	}

	public function testUnassignedMutatingBuiltInCallBecomesStructuredErrorWithoutSideEffect(): void {
		[$executor, $llmClient, $toolRegistry, $toolProvider] = $this->createHarness([
			$this->toolDefinition(BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE),
		]);
		$call = $this->toolCall(
			'unauthorized-write',
			BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
			'{"path":"forbidden.md","content":"must not be written"}'
		);
		$toolRegistry->expects($this->never())->method('getBuiltInToolsForBot');
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns(
			$llmClient,
			[$this->turn('', [$call], 'tool_calls'), $this->turn('Write was not allowed.', [], 'stop')],
			function (int $index, array $messages, array $knownToolNames, array $options): void {
				$this->assertSame([], $knownToolNames);
				$this->assertSame([], $options['tools']);
				if ($index === 1) {
					$this->assertSame('unauthorized-write', $messages[2]['tool_call_id']);
					$this->assertSame('unknown_tool', $this->decodeToolError($messages[2]['content'])['code']);
				}
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Do not write anything']],
			[],
			['bot_id' => 99, 'built_in_tools' => []]
		);

		$this->assertSame('completed', $result['status']);
		$this->assertSame('Write was not allowed.', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('error', $result['toolInvocations'][0]['status']);
		$this->assertStringContainsString('unknown_tool', $result['toolInvocations'][0]['response']);
	}

	public function testMcpAliasUsesCanonicalInputSchemaAndOriginalInvokeName(): void {
		$tool = $this->mcpTool();
		[$executor, $llmClient, , $toolProvider, $mcpClient] = $this->createHarness([
			$this->toolDefinition('remote_search'),
		]);
		$sharedRunControl = null;
		$mcpClient->expects($this->once())
			->method('listTools')
			->with(
				$tool,
				[],
				$this->callback(function (AgentRunControl $runControl) use (&$sharedRunControl): bool {
					$sharedRunControl = $runControl;
					return true;
				})
			)
			->willReturn([[
				'name' => 'remote_search',
				'description' => 'Remote search',
				'inputSchema' => [
					'properties' => [
						'query' => ['type' => 'string'],
						'filters' => ['type' => 'object', 'properties' => []],
					],
					'required' => ['query'],
				],
			]]);
		$mcpClient->expects($this->once())
			->method('callTool')
			->with(
				$tool,
				'remote_search',
				['query' => 'Berlin'],
				['tenant' => 'one'],
				$this->callback(function (AgentRunControl $runControl) use (&$sharedRunControl): bool {
					return $runControl === $sharedRunControl;
				})
			)
			->willReturn(['text' => 'remote result']);
		$toolProvider->expects($this->never())->method('executeTool');
		$this->expectSyncTurns(
			$llmClient,
			[
				$this->turn('', [
					$this->toolCall('mcp-call', 'remote_search__mcp1', '{"query":"Berlin"}'),
				], 'tool_calls'),
				$this->turn('Remote answer', [], 'stop'),
			],
			function (int $index, array $messages, array $knownToolNames, array $options): void {
				$this->assertSame(['remote_search', 'remote_search__mcp1'], $knownToolNames);
				$mcpParameters = $options['tools'][1]['function']['parameters'];
				$this->assertSame('object', $mcpParameters['type']);
				$this->assertInstanceOf(\stdClass::class, $mcpParameters['properties']['filters']['properties']);
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search remotely']],
			[['tool' => $tool, 'config' => ['tenant' => 'one']]],
			$this->builtInOptions(['remote_search'])
		);

		$this->assertSame('Remote answer', $result['content']);
		$this->assertSame('remote_search__mcp1', $result['toolInvocations'][0]['tool']);
	}

	public function testStreamingEmitsAssistantDeltasAndHidesStructuredToolCallArtifacts(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$completedStreamTurns = 0;
		$toolProvider->expects($this->once())
			->method('executeTool')
			->willReturnCallback(function () use (&$completedStreamTurns): array {
				$this->assertSame(1, $completedStreamTurns);
				return ['text' => 'result'];
			});
		$this->expectStreamTurns(
			$llmClient,
			[
				$this->turn('Searching...', [$this->toolCall('stream-tool', 'search_test', '{"query":"x"}')], 'tool_calls'),
				$this->turn('Final streamed answer', [], 'stop'),
			],
			[
				[
					['content' => 'Searching...'],
					['tool_calls' => [['index' => 0, 'function' => ['name' => 'search_test']]]],
				],
				['Final ', 'streamed answer'],
			],
			static function () use (&$completedStreamTurns): void {
				$completedStreamTurns++;
			}
		);
		$observedPartials = [];

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search']],
			[],
			$this->builtInOptions(['search_test']) + [
				'on_partial_result' => static function (string $partial) use (&$observedPartials, &$completedStreamTurns): void {
					$observedPartials[] = [$partial, $completedStreamTurns];
				},
			]
		);

		$this->assertSame('Final streamed answer', $result['content']);
		$this->assertSame([
			['Searching...', 0],
			['🔧 _Using tool: search_test..._', 1],
			['Final ', 1],
			['streamed answer', 1],
		], $observedPartials);
		$this->assertSame(2, $completedStreamTurns);
		$this->assertStringNotContainsString('Searching...', $result['content']);
	}

	public function testToolOutputTruncationPreservesUtf8Boundary(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$toolProvider->expects($this->once())
			->method('executeTool')
			->willReturn(['text' => str_repeat('ä', 4001)]);
		$this->expectSyncTurns(
			$llmClient,
			[
				$this->turn('', [$this->toolCall('utf8', 'search_test', '{"query":"x"}')], 'tool_calls'),
				$this->turn('Done', [], 'stop'),
			],
			function (int $index, array $messages): void {
				if ($index === 1) {
					$this->assertSame(str_repeat('ä', 4000) . '...', $messages[2]['content']);
					$this->assertTrue(mb_check_encoding($messages[2]['content'], 'UTF-8'));
				}
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search']],
			[],
			$this->builtInOptions(['search_test'])
		);

		$this->assertSame(4003, mb_strlen($result['toolInvocations'][0]['response'], 'UTF-8'));
		$this->assertTrue(mb_check_encoding($result['toolInvocations'][0]['response'], 'UTF-8'));
	}

	public function testExecutionExceptionProducesSecretSafeStructuredObservation(): void {
		[$executor, $llmClient, , $toolProvider] = $this->createHarness([$this->searchToolDefinition()]);
		$toolProvider->expects($this->once())
			->method('executeTool')
			->willThrowException(new \RuntimeException('secret-token-must-not-leak'));
		$this->expectSyncTurns(
			$llmClient,
			[
				$this->turn('', [$this->toolCall('failed', 'search_test', '{"query":"x"}')], 'tool_calls'),
				$this->turn('Recovered', [], 'stop'),
			],
			function (int $index, array $messages): void {
				if ($index === 1) {
					$error = $this->decodeToolError($messages[2]['content']);
					$this->assertSame('tool_execution_failed', $error['code']);
					$this->assertStringNotContainsString('secret-token', $messages[2]['content']);
				}
			}
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search']],
			[],
			$this->builtInOptions(['search_test'])
		);

		$this->assertSame('Recovered', $result['content']);
		$this->assertSame('error', $result['toolInvocations'][0]['status']);
		$this->assertStringNotContainsString('secret-token', $result['toolInvocations'][0]['response']);
	}

	public function testValidToolExecutionRecordsCallImmediatelyBeforeExecutionAndThenResult(): void {
		$traceOrder = [];
		$traceService = $this->createMock(TraceService::class);
		$traceService->method('recordEvent');
		$traceService->expects($this->once())
			->method('recordToolCall')
			->with(41, 'search_test', ['query' => 'trace'], 'trace-call')
			->willReturnCallback(function () use (&$traceOrder): void {
				$traceOrder[] = 'call';
			});
		$traceService->expects($this->once())
			->method('recordToolResult')
			->with(
				41,
				'search_test',
				'ok',
				'trace result',
				$this->isType('int'),
				null
			)
			->willReturnCallback(function () use (&$traceOrder): void {
				$traceOrder[] = 'result';
			});
		[$executor, $llmClient, , $toolProvider] = $this->createHarness(
			[$this->searchToolDefinition()],
			$traceService
		);
		$toolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'trace'])
			->willReturnCallback(function () use (&$traceOrder): array {
				$traceOrder[] = 'execute';
				return ['text' => 'trace result'];
			});
		$this->expectSyncTurns($llmClient, [
			$this->turn('', [$this->toolCall('trace-call', 'search_test', '{"query":"trace"}')], 'tool_calls'),
			$this->turn('Done', [], 'stop'),
		]);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Trace']],
			[],
			$this->builtInOptions(['search_test']) + ['trace_run_id' => 41]
		);

		$this->assertSame('Done', $result['content']);
		$this->assertSame(['call', 'execute', 'result'], $traceOrder);
	}

	/**
	 * @param array<int,array<string,mixed>> $availableTools
	 * @return array{AgentExecutor,LLMClient,ToolRegistry,ToolProviderRegistry,McpClient}
	 */
	private function createHarness(
		array $availableTools = [],
		?TraceService $traceService = null,
		?callable $clock = null,
	): array {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolProvider = $this->createMock(ToolProviderRegistry::class);
		$toolProvider->method('getAvailableTools')->willReturn($availableTools);

		return [
			new AgentExecutor(
				$llmClient,
				$mcpClient,
				$toolRegistry,
				$toolProvider,
				$this->createMock(LoggerInterface::class),
				null,
				null,
				$traceService,
				$clock,
			),
			$llmClient,
			$toolRegistry,
			$toolProvider,
			$mcpClient,
		];
	}

	/** @param array<int,AgentTurn> $turns */
	private function expectSyncTurns(LLMClient $llmClient, array $turns, ?callable $inspect = null): void {
		$index = 0;
		$llmClient->expects($this->exactly(count($turns)))
			->method('sendAgentTurn')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				array $knownToolNames,
				?string $model,
				array $options,
			) use (&$index, $turns, $inspect): AgentTurn {
				$this->assertInstanceOf(ProviderAttemptBudget::class, $options['provider_attempt_budget'] ?? null);
				$options['provider_attempt_budget']->consume();
				if ($inspect !== null) {
					$inspect($index, $messages, $knownToolNames, $options);
				}
				return $turns[$index++];
			});
	}

	/**
	 * @param array<int,AgentTurn> $turns
	 * @param array<int,array<int,array<string,mixed>|string>> $chunks
	 */
	private function expectStreamTurns(
		LLMClient $llmClient,
		array $turns,
		array $chunks,
		?callable $beforeReturn = null,
	): void {
		$index = 0;
		$llmClient->expects($this->exactly(count($turns)))
			->method('streamAgentTurn')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				array $knownToolNames,
				callable $onChunk,
				?string $model,
				array $options,
			) use (&$index, $turns, $chunks, $beforeReturn): AgentTurn {
				$this->assertInstanceOf(ProviderAttemptBudget::class, $options['provider_attempt_budget'] ?? null);
				$options['provider_attempt_budget']->consume();
				foreach ($chunks[$index] ?? [] as $chunk) {
					$onChunk(is_array($chunk) ? $chunk : ['content' => $chunk]);
				}
				if ($beforeReturn !== null) {
					$beforeReturn($index);
				}
				return $turns[$index++];
			});
	}

	/**
	 * @param array<int,array<string,mixed>> $toolCalls
	 * @param array<string,int|string> $rateLimitHeaders
	 */
	private function turn(
		string $text,
		array $toolCalls = [],
		?string $finishReason = 'stop',
		array $rateLimitHeaders = [],
		string $compatibilitySource = AgentTurn::COMPATIBILITY_NATIVE,
	): AgentTurn {
		return new AgentTurn(
			$text,
			$toolCalls,
			$finishReason,
			'test-model',
			'primary:test-model',
			'primary',
			['total_tokens' => 10],
			$rateLimitHeaders,
			$compatibilitySource
		);
	}

	/** @return array<string,mixed> */
	private function toolCall(string $id, string $name, string $arguments): array {
		return [
			'id' => $id,
			'type' => 'function',
			'function' => ['name' => $name, 'arguments' => $arguments],
		];
	}

	/** @param array<int,string> $names */
	private function builtInOptions(array $names): array {
		return [
			'built_in_tools' => array_map(
				static fn (string $name): array => ['name' => $name, 'config' => []],
				$names
			),
		];
	}

	/** @return array<string,mixed> */
	private function searchToolDefinition(string $name = 'search_test'): array {
		return $this->toolDefinition($name, [
			'type' => 'object',
			'properties' => [
				'query' => ['type' => 'string'],
				'limit' => ['type' => 'integer'],
			],
			'required' => ['query'],
		]);
	}

	/**
	 * @param array<string,mixed>|null $schema
	 * @return array<string,mixed>
	 */
	private function toolDefinition(string $name, ?array $schema = null): array {
		return [
			'name' => $name,
			'description' => 'Test tool ' . $name,
			'schema' => $schema ?? ['type' => 'object', 'properties' => new \stdClass()],
		];
	}

	private function mcpTool(): Tool {
		$tool = new Tool();
		$tool->setId(1);
		$tool->setName('Web search');
		$tool->setMcpEndpointUrl('https://mcp.example.test');
		$tool->setEnabled(true);
		return $tool;
	}

	/** @return array<string,mixed> */
	private function decodeToolError(string $content): array {
		$decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		$this->assertFalse($decoded['ok']);
		return $decoded['error'];
	}
}
