<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\Tool;
use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\McpClient;
use OCA\EducAI\Service\TraceService;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\ToolResultFallbackService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type ToolDefinition from \OCA\EducAI\TypeDefinitions
 */
class AgentExecutorTest extends TestCase {
	public function testGenerateFallbackFromToolResultsUsesRoomSearchResults(): void {
		$fallbackService = new ToolResultFallbackService();

		$result = $fallbackService->generateFromTrace([
			[
				'tool' => 'room_search_documents',
				'status' => 'ok',
				'response' => "Found 1 relevant room document chunk(s) for: \"ambek Studiengang\"\n\n---\n**Source 1:** ambek.pdf (chunk 1) [relevance: 0.91]\n\nBachelor of Science Angewandte Bewegungswissenschaften\n",
			],
		]);

		$this->assertStringStartsWith("Based on the documents found:\n\n", $result);
		$this->assertStringContainsString('Bachelor of Science Angewandte Bewegungswissenschaften', $result);
	}

	public function testSanitizeAssistantTextForUserRemovesThinkArtifactsAndAdjacentDuplicates(): void {
		$executor = $this->createExecutor();

		$result = $this->invokePrivateMethod(
			$executor,
			'sanitizeAssistantTextForUser',
			['<think>Interne Analyse</think>Ich habe die Dokumente durchsucht.</think>Ich habe die Dokumente durchsucht.']
		);

		$this->assertSame('Ich habe die Dokumente durchsucht.', $result);
	}

	public function testSanitizeAssistantTextForUserRemovesNamespacedToolCallArtifacts(): void {
		$executor = $this->createExecutor();

		$result = $this->invokePrivateMethod(
			$executor,
			'sanitizeAssistantTextForUser',
			['Vorher <minimax:tool_call><invoke name="wiki_write_page"><parameter name="path">index.md</parameter></invoke></minimax:tool_call> Nachher']
		);

		$this->assertSame('Vorher Nachher', $result);
	}

	public function testSanitizeOutputKeepsUtf8ValidWhenTruncatingAtEmojiBoundary(): void {
		$executor = $this->createExecutor();
		$text = str_repeat('a', 3999) . '💡tail';

		$result = $this->invokePrivateMethod(
			$executor,
			'sanitizeOutput',
			[['content' => [['type' => 'text', 'text' => $text]]]]
		);

		$this->assertTrue(mb_check_encoding($result, 'UTF-8'));
		$this->assertNotFalse(json_encode(['content' => $result]));
		$this->assertStringEndsWith('💡...', $result);
	}

	public function testSanitizeOutputJsonFallbackSubstitutesInvalidUtf8(): void {
		$executor = $this->createExecutor();
		$invalidUtf8 = substr(str_repeat('b', 3999) . '💡', 0, 4000);
		$this->assertFalse(mb_check_encoding($invalidUtf8, 'UTF-8'));

		$result = $this->invokePrivateMethod(
			$executor,
			'sanitizeOutput',
			[['raw' => $invalidUtf8]]
		);

		$this->assertTrue(mb_check_encoding($result, 'UTF-8'));
		$this->assertNotFalse(json_encode(['content' => $result]));
		$this->assertStringNotContainsString('Received invalid tool response', $result);
	}

	public function testRunExecutesXmlWrappedJsonToolCall(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(42)
			->willReturn([['name' => 'search_test']]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);

		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnOnConsecutiveCalls(
				[
					'content' => '<tool_call>{"name":"search_test","arguments":{"query":"Berlin"}}</tool_call>',
					'tool_calls' => [],
				],
				[
					'content' => 'Final answer',
					'tool_calls' => [],
				]
			);

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Berlin'])
			->willReturn(['results' => ['Berlin']]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run('system', [['role' => 'user', 'content' => 'Find Berlin']], [], ['bot_id' => 42]);

		$this->assertSame('Final answer', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('search_test', $result['toolInvocations'][0]['tool']);
		$this->assertSame('ok', $result['toolInvocations'][0]['status']);
	}

	public function testRunRecordsBuiltInToolTraceEvents(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);
		$traceService = $this->createMock(TraceService::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(42)
			->willReturn([['name' => 'search_test']]);
		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);
		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnOnConsecutiveCalls(
				[
					'content' => '',
					'tool_calls' => [[
						'id' => 'call-1',
						'type' => 'function',
						'function' => [
							'name' => 'search_test',
							'arguments' => '{"query":"Berlin"}',
						],
					]],
					'finish_reason' => 'tool_calls',
				],
				[
					'content' => 'Final answer',
					'tool_calls' => [],
					'finish_reason' => 'stop',
				]
			);
		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Berlin'])
			->willReturn(['content' => [['type' => 'text', 'text' => 'Found Berlin']]]);

		$traceService->expects($this->atLeastOnce())->method('recordEvent');
		$traceService->expects($this->once())
			->method('recordToolCall')
			->with(77, 'search_test', ['query' => 'Berlin'], 'call-1');
		$traceService->expects($this->once())
			->method('recordToolResult')
			->with(
				77,
				'search_test',
				'ok',
				'Found Berlin',
				$this->isType('int'),
				null
			);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class),
			null,
			null,
			null,
			$traceService
		);

		$result = $executor->run('system', [['role' => 'user', 'content' => 'Find Berlin']], [], [
			'bot_id' => 42,
			'trace_run_id' => 77,
		]);

		$this->assertSame('Final answer', $result['content']);
	}

	public function testRunExecutesXmlSubElementToolCall(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(7)
			->willReturn([['name' => 'search_test']]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);

		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnOnConsecutiveCalls(
				[
					'content' => '<function_call><name>search_test</name><arguments>{"query":"Potsdam"}</arguments></function_call>',
					'tool_calls' => [],
				],
				[
					'content' => 'Antwort fertig',
					'tool_calls' => [],
				]
			);

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Potsdam'])
			->willReturn(['results' => ['Potsdam']]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run('system', [['role' => 'user', 'content' => 'Find Potsdam']], [], ['bot_id' => 7]);

		$this->assertSame('Antwort fertig', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('search_test', $result['toolInvocations'][0]['tool']);
	}

	public function testRunRejectsMiniMaxInvokeToolCallMissingRequiredWikiPath(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(8)
			->willReturn([['name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE]]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildWikiWriteToolDefinition()]);

		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnOnConsecutiveCalls(
				[
					'content' => '<minimax:tool_call><invoke name="wiki_write_page"><parameter name="title">Universität Potsdam</parameter><parameter name="content"># Universität Potsdam</parameter></invoke></minimax:tool_call>',
					'tool_calls' => [],
				],
				[
					'content' => 'Bitte gib einen Zielpfad fuer die Wiki-Seite an.',
					'tool_calls' => [],
				]
			);

		$builtInToolProvider->expects($this->never())
			->method('executeTool');

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Schreibe etwas über die Universität Potsdam ins Wiki']],
			[],
			[
				'bot_id' => 8,
				'user_query' => 'Schreibe etwas über die Universität Potsdam ins Wiki',
			]
		);

		$this->assertSame('Bitte gib einen Zielpfad fuer die Wiki-Seite an.', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame(BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE, $result['toolInvocations'][0]['tool']);
		$this->assertSame('error', $result['toolInvocations'][0]['status']);
		$this->assertStringContainsString('missing required argument(s): path', $result['toolInvocations'][0]['response']);
	}

	public function testRunUsesExplicitBuiltInToolsWithoutRegistryLookup(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->never())
			->method('getBuiltInToolsForBot');

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);

		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnOnConsecutiveCalls(
				[
					'content' => '<tool_call>{"name":"search_test","arguments":{"query":"Leipzig"}}</tool_call>',
					'tool_calls' => [],
				],
				[
					'content' => 'Explicit built-in answer',
					'tool_calls' => [],
				]
			);

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Leipzig'])
			->willReturn(['results' => ['Leipzig']]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Find Leipzig']],
			[],
			[
				'built_in_tools' => [['name' => 'search_test', 'config' => []]],
			]
		);

		$this->assertSame('Explicit built-in answer', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('search_test', $result['toolInvocations'][0]['tool']);
	}

	public function testRunPassesExplicitBuiltInToolConfigToProvider(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->never())
			->method('getBuiltInToolsForBot');

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);

		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnOnConsecutiveCalls(
				[
					'content' => '<tool_call>{"name":"search_test","arguments":{"query":"Leipzig"}}</tool_call>',
					'tool_calls' => [],
				],
				[
					'content' => 'Configured built-in answer',
					'tool_calls' => [],
				]
			);

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Leipzig'], ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study'])
			->willReturn(['results' => ['Leipzig']]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Find Leipzig']],
			[],
			[
				'built_in_tools' => [[
					'name' => 'search_test',
					'config' => ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study'],
				]],
			]
		);

		$this->assertSame('Configured built-in answer', $result['content']);
	}

	public function testRunExecutesLegacyXmlArgumentPairToolCall(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(99)
			->willReturn([['name' => 'search_test']]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);

		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnOnConsecutiveCalls(
				[
					'content' => '<tool_call>search_test<arg_key>query</arg_key><arg_value>European Universities call 2026</arg_value><arg_key>limit</arg_key><arg_value>10</arg_value></tool_call>',
					'tool_calls' => [],
				],
				[
					'content' => 'Legacy XML answer',
					'tool_calls' => [],
				]
			);

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'European Universities call 2026', 'limit' => 10])
			->willReturn(['results' => ['European Universities call 2026']]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run('system', [['role' => 'user', 'content' => 'Find call 2026']], [], ['bot_id' => 99]);

		$this->assertSame('Legacy XML answer', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('search_test', $result['toolInvocations'][0]['tool']);
	}

	public function testRunDoesNotStreamXmlToolArtifacts(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(5)
			->willReturn([['name' => 'search_test']]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);

		$streamCall = 0;
		$llmClient->expects($this->exactly(2))
			->method('streamChatCompletion')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				callable $onChunk
			) use (&$streamCall): array {
				$streamCall++;
				if ($streamCall === 1) {
					$onChunk(['content' => '<tool_']);
					$onChunk(['content' => 'call>search_test']);
					$onChunk(['content' => '<arg_key>query</arg_key>']);
					$onChunk(['content' => '<arg_value>Berlin</arg_value>']);
					$onChunk(['content' => '</tool_call>']);

					return [
						'content' => '<tool_call>search_test<arg_key>query</arg_key><arg_value>Berlin</arg_value></tool_call>',
						'tool_calls' => [],
					];
				}

				$onChunk(['content' => 'Final answer streamed.']);

				return [
					'content' => 'Final answer streamed.',
					'tool_calls' => [],
				];
			});

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Berlin'])
			->willReturn(['results' => ['Berlin']]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$partials = [];
		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Find Berlin']],
			[],
			[
				'bot_id' => 5,
				'on_partial_result' => static function (string $partial) use (&$partials): void {
					$partials[] = $partial;
				},
			]
		);

		$this->assertSame('Final answer streamed.', $result['content']);
		$this->assertSame(
			['🔧 _Using tool: search_test..._', 'Final answer streamed.'],
			$partials
		);
	}

	public function testRunDoesNotStreamMiniMaxXmlToolArtifacts(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(5)
			->willReturn([['name' => 'search_test']]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);

		$streamCall = 0;
		$llmClient->expects($this->exactly(2))
			->method('streamChatCompletion')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				callable $onChunk
			) use (&$streamCall): array {
				$streamCall++;
				if ($streamCall === 1) {
					$onChunk(['content' => '<minimax:']);
					$onChunk(['content' => 'tool_call><invoke name="search_test">']);
					$onChunk(['content' => '<parameter name="query">Berlin</parameter>']);
					$onChunk(['content' => '</invoke></minimax:tool_call>']);

					return [
						'content' => '<minimax:tool_call><invoke name="search_test"><parameter name="query">Berlin</parameter></invoke></minimax:tool_call>',
						'tool_calls' => [],
					];
				}

				$onChunk(['content' => 'Final MiniMax answer.']);

				return [
					'content' => 'Final MiniMax answer.',
					'tool_calls' => [],
				];
			});

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Berlin'])
			->willReturn(['results' => ['Berlin']]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$partials = [];
		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Find Berlin']],
			[],
			[
				'bot_id' => 5,
				'on_partial_result' => static function (string $partial) use (&$partials): void {
					$partials[] = $partial;
				},
			]
		);

		$this->assertSame('Final MiniMax answer.', $result['content']);
		$this->assertSame(
			['🔧 _Using tool: search_test..._', 'Final MiniMax answer.'],
			$partials
		);
	}

	public function testRunUsesInitialToolChoiceOnlyUntilAudioToolCallIsSatisfied(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(5)
			->willReturn([['name' => 'attachment_transcribe_audio']]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildAudioToolDefinition()]);

		$callIndex = 0;
		$expectedChoice = [
			'type' => 'function',
			'function' => ['name' => 'attachment_transcribe_audio'],
		];
		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				?string $modelOverride,
				array $options
			) use (&$callIndex, $expectedChoice): array {
				$callIndex++;
				if ($callIndex === 1) {
					$this->assertSame($expectedChoice, $options['tool_choice'] ?? null);
					return [
						'content' => 'I will transcribe the audio now.',
						'tool_calls' => [],
					];
				}

				$this->assertArrayHasKey('tool_choice', $options);
				$this->assertNull($options['tool_choice']);

				return [
					'content' => 'Final audio answer',
					'tool_calls' => [],
				];
			});

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('attachment_transcribe_audio', [])
			->willReturn([
				'content' => [
					['type' => 'text', 'text' => 'Transcript for voice.wav: Hello world.'],
				],
			]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Decode this voice note']],
			[],
			[
				'bot_id' => 5,
				'user_query' => 'Decode this voice note',
				'initial_tool_choice' => $expectedChoice,
			]
		);

		$this->assertSame('Final audio answer', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('attachment_transcribe_audio', $result['toolInvocations'][0]['tool']);
	}

	public function testRunNormalizesEmptyNativeToolArgumentsBeforeReplayingHistory(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(5)
			->willReturn([['name' => 'attachment_transcribe_audio']]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildAudioToolDefinition()]);

		$callIndex = 0;
		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				?string $modelOverride,
				array $options
			) use (&$callIndex): array {
				$callIndex++;
				if ($callIndex === 1) {
					return [
						'content' => '',
						'tool_calls' => [[
							'id' => 'call_audio',
							'type' => 'function',
							'function' => [
								'name' => 'attachment_transcribe_audio',
								'arguments' => '',
							],
						]],
					];
				}

				$assistantMessages = array_values(array_filter(
					$messages,
					static fn (array $message): bool => ($message['role'] ?? null) === 'assistant' && isset($message['tool_calls'])
				));
				$this->assertSame('{}', $assistantMessages[0]['tool_calls'][0]['function']['arguments']);

				return [
					'content' => 'Final audio answer',
					'tool_calls' => [],
				];
			});

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('attachment_transcribe_audio', [])
			->willReturn([
				'content' => [
					['type' => 'text', 'text' => 'Transcript for voice.wav: Hello world.'],
				],
			]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Decode this voice note']],
			[],
			[
				'bot_id' => 5,
				'user_query' => 'Decode this voice note',
			]
		);

		$this->assertSame('Final audio answer', $result['content']);
	}

	public function testRunSynthesizesFinalAnswerWhenToolRunEndsWithThinkOnlyContent(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);

		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(5)
			->willReturn([['name' => 'search_test']]);

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildSearchToolDefinition()]);

		$callIndex = 0;
		$llmClient->expects($this->exactly(3))
			->method('sendChatCompletion')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				?string $modelOverride,
				array $options
			) use (&$callIndex): array {
				$callIndex++;
				if ($callIndex === 1) {
					return [
						'content' => '',
						'tool_calls' => [[
							'id' => 'call_search_1',
							'type' => 'function',
							'function' => [
								'name' => 'search_test',
								'arguments' => '{"query":"Berlin"}',
							],
						]],
					];
				}

				if ($callIndex === 2) {
					return [
						'content' => '<think>I have the facts and will write the visible answer now.</think>',
						'tool_calls' => [],
					];
				}

				$this->assertStringContainsString('Final Response Required', $systemPrompt);

				return [
					'content' => 'Visible synthesized answer.',
					'tool_calls' => [],
				];
			});

		$builtInToolProvider->expects($this->once())
			->method('executeTool')
			->with('search_test', ['query' => 'Berlin'])
			->willReturn(['content' => [['type' => 'text', 'text' => 'Berlin facts.']]]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run('system', [['role' => 'user', 'content' => 'Find Berlin']], [], ['bot_id' => 5]);

		$this->assertSame('Visible synthesized answer.', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('search_test', $result['toolInvocations'][0]['tool']);
	}

	public function testGenericForcedToolCallPrefersMcpSearchOverRoomSearch(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);
		$mcpTool = $this->buildMcpTool();

		$toolRegistry->expects($this->never())
			->method('getBuiltInToolsForBot');

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([$this->buildRoomSearchToolDefinition()]);

		$mcpClient->expects($this->once())
			->method('listTools')
			->with($mcpTool)
			->willReturn([$this->buildWebSearchDescriptor()]);

		$callIndex = 0;
		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				?string $modelOverride,
				array $options
			) use (&$callIndex): array {
				$callIndex++;
				if ($callIndex === 1) {
					$this->assertSame('required', $options['tool_choice'] ?? null);

					return [
						'content' => '<think>I should search online.</think>',
						'tool_calls' => [],
					];
				}

				return [
					'content' => 'Search complete.',
					'tool_calls' => [],
				];
			});

		$builtInToolProvider->expects($this->never())
			->method('executeTool');

		$mcpClient->expects($this->once())
			->method('callTool')
			->with(
				$mcpTool,
				'web_search',
				['query' => 'Bitte suche im Internet nach Potsdam'],
				[]
			)
			->willReturn(['content' => [['type' => 'text', 'text' => 'Potsdam search facts.']]]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Bitte suche im Internet nach Potsdam']],
			[['tool' => $mcpTool, 'config' => []]],
			[
				'built_in_tools' => [['name' => 'room_search_documents', 'config' => []]],
				'force_tool_call' => true,
				'user_query' => 'Bitte suche im Internet nach Potsdam',
			]
		);

		$this->assertSame('Search complete.', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('web_search', $result['toolInvocations'][0]['tool']);
	}

	public function testGenericForcedToolCallUsesMcpInputSchema(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);
		$mcpTool = $this->buildMcpTool();

		$toolRegistry->expects($this->never())
			->method('getBuiltInToolsForBot');

		$builtInToolProvider->expects($this->once())
			->method('getAvailableTools')
			->willReturn([]);

		$mcpClient->expects($this->once())
			->method('listTools')
			->with($mcpTool)
			->willReturn([$this->buildInputSchemaWebSearchDescriptor()]);

		$callIndex = 0;
		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				?string $modelOverride,
				array $options
			) use (&$callIndex): array {
				$callIndex++;
				if ($callIndex === 1) {
					$tools = $options['tools'] ?? [];
					$this->assertSame('required', $options['tool_choice'] ?? null);
					$this->assertSame('web_search', $tools[0]['function']['name'] ?? null);
					$this->assertArrayHasKey('query', $tools[0]['function']['parameters']['properties'] ?? []);

					return [
						'content' => '<think>I should search online.</think>',
						'tool_calls' => [],
					];
				}

				return [
					'content' => 'Search complete.',
					'tool_calls' => [],
				];
			});

		$mcpClient->expects($this->once())
			->method('callTool')
			->with(
				$mcpTool,
				'web_search',
				['query' => 'Bitte suche im Internet nach Potsdam'],
				[]
			)
			->willReturn(['content' => [['type' => 'text', 'text' => 'Potsdam search facts.']]]);

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Bitte suche im Internet nach Potsdam']],
			[['tool' => $mcpTool, 'config' => []]],
			[
				'force_tool_call' => true,
				'user_query' => 'Bitte suche im Internet nach Potsdam',
			]
		);

		$this->assertSame('Search complete.', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('web_search', $result['toolInvocations'][0]['tool']);
	}

	public function testForcedQueryOnlyStripsExplicitMention(): void {
		$executor = $this->createExecutor();

		$this->assertSame(
			'Potsdam Wetter',
			$this->invokePrivateMethod($executor, 'buildForcedQuery', ['Potsdam Wetter'])
		);
		$this->assertSame(
			'Bitte suche im Internet nach Potsdam',
			$this->invokePrivateMethod($executor, 'buildForcedQuery', ['@web Bitte suche im Internet nach Potsdam'])
		);
	}

	public function testInvalidMcpToolArgumentsAreReturnedAsToolObservation(): void {
		$llmClient = $this->createMock(LLMClient::class);
		$mcpClient = $this->createMock(McpClient::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$builtInToolProvider = $this->createMock(ToolProviderRegistry::class);
		$mcpTool = $this->buildMcpTool();

		$mcpClient->expects($this->once())
			->method('listTools')
			->with($mcpTool)
			->willReturn([$this->buildWebSearchDescriptor()]);

		$mcpClient->expects($this->never())
			->method('callTool');

		$callIndex = 0;
		$llmClient->expects($this->exactly(2))
			->method('sendChatCompletion')
			->willReturnCallback(function (
				string $systemPrompt,
				array $messages,
				?string $modelOverride,
				array $options
			) use (&$callIndex): array {
				$callIndex++;
				if ($callIndex === 1) {
					return [
						'content' => '',
						'tool_calls' => [[
							'id' => 'call-invalid',
							'type' => 'function',
							'function' => [
								'name' => 'web_search',
								'arguments' => '{}',
							],
						]],
						'finish_reason' => 'tool_calls',
					];
				}

				$lastMessage = $messages[count($messages) - 1] ?? [];
				$this->assertSame('tool', $lastMessage['role'] ?? null);
				$this->assertSame('web_search', $lastMessage['name'] ?? null);
				$this->assertStringContainsString('ERROR: Invalid tool arguments for web_search', (string)($lastMessage['content'] ?? ''));
				$this->assertStringContainsString('missing required argument(s): query', (string)($lastMessage['content'] ?? ''));

				return [
					'content' => 'Please provide a search query.',
					'tool_calls' => [],
				];
			});

		$executor = new AgentExecutor(
			$llmClient,
			$mcpClient,
			$toolRegistry,
			$builtInToolProvider,
			$this->createMock(LoggerInterface::class)
		);

		$result = $executor->run(
			'system',
			[['role' => 'user', 'content' => 'Search online']],
			[['tool' => $mcpTool, 'config' => []]],
			['user_query' => 'Search online']
		);

		$this->assertSame('Please provide a search query.', $result['content']);
		$this->assertCount(1, $result['toolInvocations']);
		$this->assertSame('error', $result['toolInvocations'][0]['status']);
		$this->assertStringContainsString('missing required argument(s): query', $result['toolInvocations'][0]['response']);
	}

	public function testGenericForcedToolSelectionSkipsBuiltInAndUnsafeTools(): void {
		$executor = $this->createExecutor();
		$toolMap = [
			'room_search_documents' => $this->buildToolMapEntry('room_search_documents', 'Search room documents.', null),
			'wiki_write_page' => $this->buildToolMapEntry('wiki_write_page', 'Write a wiki page.', null),
			'attachment_transcribe_audio' => $this->buildToolMapEntry('attachment_transcribe_audio', 'Transcribe audio.', null),
			'tavily_extract' => $this->buildToolMapEntry('tavily_extract', 'Extract page content from URLs.', $this->buildMcpTool(2, 'Extract')),
		];

		$result = $this->invokePrivateMethod($executor, 'pickToolForForcedCall', [$toolMap]);

		$this->assertNull($result);
	}

	/**
	 * @return ToolDefinition
	 */
	private function buildSearchToolDefinition(): array {
		return [
			'name' => 'search_test',
			'description' => 'Search test tool',
			'schema' => [
				'type' => 'object',
				'properties' => [
					'query' => ['type' => 'string'],
					'limit' => ['type' => 'integer'],
				],
				'required' => ['query'],
			],
		];
	}

	/**
	 * @return ToolDefinition
	 */
	private function buildAudioToolDefinition(): array {
		return [
			'name' => 'attachment_transcribe_audio',
			'description' => 'Transcribe audio or voice-message attachments.',
			'schema' => [
				'type' => 'object',
				'properties' => [
					'attachment_name' => ['type' => 'string'],
				],
			],
		];
	}

	/**
	 * @return ToolDefinition
	 */
	private function buildRoomSearchToolDefinition(): array {
		return [
			'name' => 'room_search_documents',
			'description' => 'Search documents uploaded inside the current Talk room.',
			'schema' => [
				'type' => 'object',
				'properties' => [
					'query' => ['type' => 'string'],
				],
				'required' => ['query'],
			],
		];
	}

	/**
	 * @return ToolDefinition
	 */
	private function buildWikiWriteToolDefinition(): array {
		return [
			'name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
			'description' => 'Create, overwrite, or append to a Markdown page in the bot wiki.',
			'schema' => [
				'type' => 'object',
				'properties' => [
					'path' => ['type' => 'string'],
					'content' => ['type' => 'string'],
					'mode' => ['type' => 'string'],
					'reason' => ['type' => 'string'],
				],
				'required' => ['path', 'content'],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildWebSearchDescriptor(): array {
		return [
			'name' => 'web_search',
			'description' => 'Search the web and internet.',
			'schema' => [
				'type' => 'object',
				'properties' => [
					'query' => ['type' => 'string'],
				],
				'required' => ['query'],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildInputSchemaWebSearchDescriptor(): array {
		$descriptor = $this->buildWebSearchDescriptor();
		$descriptor['inputSchema'] = $descriptor['schema'];
		unset($descriptor['schema']);

		return $descriptor;
	}

	private function buildMcpTool(int $id = 1, string $name = 'Web search'): Tool {
		$tool = new Tool();
		$tool->setId($id);
		$tool->setName($name);
		$tool->setMcpEndpointUrl('https://mcp.example.test');
		$tool->setEnabled(true);

		return $tool;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildToolMapEntry(string $name, string $description, ?Tool $tool): array {
		return [
			'tool' => $tool,
			'config' => [],
			'definition' => [
				'type' => 'function',
				'function' => [
					'name' => $name,
					'description' => $description,
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'query' => ['type' => 'string'],
						],
					],
				],
			],
			'invokeName' => $name,
		];
	}

	private function createExecutor(): AgentExecutor {
		return new AgentExecutor(
			$this->createMock(LLMClient::class),
			$this->createMock(McpClient::class),
			$this->createMock(ToolRegistry::class),
			$this->createMock(ToolProviderRegistry::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * @param array<int,mixed> $arguments
	 * @return mixed
	 */
	private function invokePrivateMethod(AgentExecutor $executor, string $method, array $arguments) {
		$reflection = new \ReflectionMethod($executor, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs($executor, $arguments);
	}
}
