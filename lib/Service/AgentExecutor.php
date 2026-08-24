<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Closure;
use OCA\EducAI\Exception\AgentRunInterruptedException;
use OCA\EducAI\Exception\ProviderAttemptBudgetExceededException;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type BuiltInToolLoadoutEntry from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type LlmToolCall from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type McpToolLoadoutEntry from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type ToolDefinitionBuildResult from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type ToolMapEntry from \OCA\EducAI\TypeDefinitions
 * @psalm-type PreparedToolCall=array{
 *     toolCall:LlmToolCall,
 *     toolName:?string,
 *     toolContext:?ToolMapEntry,
 *     arguments:?array<string,mixed>,
 *     rejection:?array{code:string,message:string,details:array<string,mixed>}
 * }
 */
class AgentExecutor {
	private const DEFAULT_MAX_TURNS = 12;
	private const HARD_MAX_TURNS = 16;
	private const DEFAULT_MAX_TOOL_CALLS = 24;
	private const HARD_MAX_TOOL_CALLS = 24;
	private const DEFAULT_MAX_WALL_CLOCK_SECONDS = 300;
	/**
	 * Queue processing leases must exceed this hard execution bound so a live
	 * agent run cannot be reclaimed merely because it uses its allowed budget.
	 */
	public const HARD_MAX_WALL_CLOCK_SECONDS = 900;
	private const TRACE_PREFLIGHT_ERROR = 'provider_trace_preflight_failed';

	private LLMClient $llmClient;
	private McpClient $mcpClient;
	private ToolRegistry $toolRegistry;
	private ToolProviderRegistry $toolProviderRegistry;
	private ToolExecutionPolicyService $toolExecutionPolicyService;
	private ToolArgumentNormalizer $toolArgumentNormalizer;
	private ?TraceService $traceService;
	private LoggerInterface $logger;

	/** @var Closure():float */
	private Closure $clock;

	public function __construct(
		LLMClient $llmClient,
		McpClient $mcpClient,
		ToolRegistry $toolRegistry,
		ToolProviderRegistry $toolProviderRegistry,
		LoggerInterface $logger,
		?ToolExecutionPolicyService $toolExecutionPolicyService = null,
		?ToolArgumentNormalizer $toolArgumentNormalizer = null,
		?TraceService $traceService = null,
		?callable $clock = null,
	) {
		$this->llmClient = $llmClient;
		$this->mcpClient = $mcpClient;
		$this->toolRegistry = $toolRegistry;
		$this->toolProviderRegistry = $toolProviderRegistry;
		$this->toolExecutionPolicyService = $toolExecutionPolicyService ?? new ToolExecutionPolicyService();
		$this->toolArgumentNormalizer = $toolArgumentNormalizer ?? new ToolArgumentNormalizer();
		$this->traceService = $traceService;
		$this->logger = $logger;
		$this->clock = $clock !== null
			? Closure::fromCallable($clock)
			: static fn (): float => hrtime(true) / 1_000_000_000;
	}

	/**
	 * @param string $systemPrompt
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,McpToolLoadoutEntry> $toolLoadout MCP tools assigned to the bot
	 * @param array<string,mixed> $llmOptions
	 * @return array<string,mixed>
	 */
	public function run(string $systemPrompt, array $messages, array $toolLoadout, array $llmOptions = []): array {
		$botId = isset($llmOptions['bot_id']) && is_int($llmOptions['bot_id']) ? $llmOptions['bot_id'] : null;
		$traceRunId = isset($llmOptions['trace_run_id']) && is_numeric($llmOptions['trace_run_id'])
			? (int)$llmOptions['trace_run_id']
			: null;
		$explicitBuiltInTools = array_key_exists('built_in_tools', $llmOptions) && is_array($llmOptions['built_in_tools'])
			? $llmOptions['built_in_tools']
			: null;
		$onPartialResult = isset($llmOptions['on_partial_result']) && is_callable($llmOptions['on_partial_result'])
			? $llmOptions['on_partial_result']
			: null;
		$onToolProgress = array_key_exists('on_tool_progress', $llmOptions)
			? (is_callable($llmOptions['on_tool_progress']) ? $llmOptions['on_tool_progress'] : null)
			: $onPartialResult;
		$toolChoice = $llmOptions['tool_choice'] ?? null;
		$initialToolChoice = $llmOptions['initial_tool_choice'] ?? null;
		$model = isset($llmOptions['model']) && is_string($llmOptions['model']) ? $llmOptions['model'] : null;
		$maxTurns = $this->resolveBoundedLimit(
			$llmOptions['max_turns'] ?? self::DEFAULT_MAX_TURNS,
			self::DEFAULT_MAX_TURNS,
			self::HARD_MAX_TURNS
		);
		$maxToolCalls = $this->resolveBoundedLimit(
			$llmOptions['max_tool_calls'] ?? self::DEFAULT_MAX_TOOL_CALLS,
			self::DEFAULT_MAX_TOOL_CALLS,
			self::HARD_MAX_TOOL_CALLS
		);
		$maxProviderAttempts = $this->resolveBoundedLimit(
			$llmOptions['max_provider_attempts'] ?? ProviderAttemptBudget::DEFAULT_LIMIT,
			ProviderAttemptBudget::DEFAULT_LIMIT,
			ProviderAttemptBudget::HARD_LIMIT
		);
		$providerAttemptBudget = new ProviderAttemptBudget($maxProviderAttempts);
		$maxWallClockSeconds = $this->resolveBoundedLimit(
			$llmOptions['max_wall_clock_seconds'] ?? self::DEFAULT_MAX_WALL_CLOCK_SECONDS,
			self::DEFAULT_MAX_WALL_CLOCK_SECONDS,
			self::HARD_MAX_WALL_CLOCK_SECONDS
		);
		$abortCallback = isset($llmOptions['abort_callback']) && is_callable($llmOptions['abort_callback'])
			? $llmOptions['abort_callback']
			: null;
		$runControl = new AgentRunControl($maxWallClockSeconds, $abortCallback, $this->clock);

		try {
			$runControl->assertCanContinue();
			$toolDefinitions = $this->buildToolDefinitions(
				$toolLoadout,
				$botId,
				$explicitBuiltInTools,
				$runControl
			);
		} catch (AgentRunInterruptedException $e) {
			return $this->buildInterruptedRunResult(
				$e,
				$messages,
				[],
				null,
				AgentTurn::COMPATIBILITY_NATIVE,
				0,
				$providerAttemptBudget,
				[]
			);
		}
		$toolMap = $toolDefinitions['map'];
		$toolsForLlm = $toolDefinitions['definitions'];
		$builtInTools = $toolDefinitions['builtIn'];
		$knownToolNames = array_keys($toolMap);

		$this->logger->info('EducAI: Agent starting', [
			'tools_requested' => count($toolLoadout),
			'tools_loaded' => count($toolsForLlm),
			'built_in_tools' => count($builtInTools),
			'tool_names' => $knownToolNames,
			'trace_run_id' => $traceRunId,
			'max_turns' => $maxTurns,
			'max_tool_calls' => $maxToolCalls,
			'max_provider_attempts' => $maxProviderAttempts,
			'max_wall_clock_seconds' => $maxWallClockSeconds,
		]);

		if (count($toolLoadout) > 0 && count($toolsForLlm) === 0) {
			$this->logger->warning('EducAI: No tools could be loaded from MCP endpoints and no built-in tools are available');
		}

		if (count($toolsForLlm) > 0) {
			$systemPrompt .= "\n\n## Tool Calling Instructions\n";
			$systemPrompt .= "Use structured tool calls when a tool is needed. Read each tool result before answering, and do not invent tool output.\n";
		}

		$requestOptions = array_intersect_key($llmOptions, array_flip([
			'temperature',
			'max_tokens',
			'presence_penalty',
			'frequency_penalty',
			'top_p',
			'stream_options',
			'legacy_tool_call_compatibility',
		]));
		$requestOptions['tools'] = $toolsForLlm;
		$requestOptions['temperature'] = $this->resolveTemperatureOption(
			$requestOptions['temperature'] ?? SettingsService::DEFAULT_TEMPERATURE
		);
		$requestOptions['max_tokens'] = $requestOptions['max_tokens'] ?? 800;
		$requestOptions['provider_attempt_budget'] = $providerAttemptBudget;
		$requestOptions['agent_run_control'] = $runControl;
		if ($traceRunId !== null) {
			$requestOptions['trace_run_id'] = $traceRunId;
		}

		$toolInvocations = [];
		$logicalTurns = 0;
		$toolCallCount = 0;
		$recentToolCalls = [];
		$recentToolBatches = [];
		$finishReason = null;
		$compatibilitySource = AgentTurn::COMPATIBILITY_NATIVE;
		$rateLimitHeaders = [];

		while (true) {
			try {
				$runControl->assertCanContinue();
			} catch (AgentRunInterruptedException $e) {
				return $this->buildInterruptedRunResult(
					$e,
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			}

			if ($logicalTurns >= $maxTurns) {
				return $this->buildRunResult(
					'budget_exhausted',
					'max_turns',
					'',
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			}

			if ($providerAttemptBudget->getRemaining() <= 0) {
				return $this->buildRunResult(
					'budget_exhausted',
					'provider_attempts',
					'',
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			}

			$turnNumber = $logicalTurns + 1;
			$turnOptions = $requestOptions;
			$turnOptions['tool_choice'] = $logicalTurns === 0 && $initialToolChoice !== null
				? $initialToolChoice
				: $toolChoice;

			$streamedAssistantDeltaEmitted = false;
			try {
				$this->recordLlmRequestTrace(
					$traceRunId,
					'agent_loop',
					$systemPrompt,
					$messages,
					$model,
					$turnOptions,
					$onPartialResult !== null,
					['turn' => $turnNumber]
				);

				if ($onPartialResult !== null) {
					$turn = $this->llmClient->streamAgentTurn(
						$systemPrompt,
						$messages,
						$knownToolNames,
						static function (array $delta) use ($onPartialResult, &$streamedAssistantDeltaEmitted): void {
							if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
								$streamedAssistantDeltaEmitted = true;
								$onPartialResult($delta['content']);
							}
						},
						$model,
						$turnOptions
					);
				} else {
					$turn = $this->llmClient->sendAgentTurn(
						$systemPrompt,
						$messages,
						$knownToolNames,
						$model,
						$turnOptions
					);
				}
			} catch (AgentRunInterruptedException $e) {
				return $this->buildInterruptedRunResult(
					$e,
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			} catch (ProviderAttemptBudgetExceededException $e) {
				return $this->buildRunResult(
					'budget_exhausted',
					'provider_attempts',
					'',
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			} catch (\Throwable $e) {
				$this->logger->error('EducAI: Agent provider turn failed', ['exception' => $e]);
				$this->traceService?->recordEvent($traceRunId, 'error', [
					'status' => 'error',
					'payload' => ['stage' => 'agent_loop', 'turn' => $turnNumber],
					'error_message' => 'Provider request failed',
				]);

				return $this->buildRunResult(
					'error',
					'provider_error',
					'',
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			}

			$logicalTurns++;
			$finishReason = $turn->getStopReason();
			$compatibilitySource = $turn->getCompatibilitySource();
			$rateLimitHeaders = $turn->getRateLimitHeaders();
			$text = $turn->getText();
			$toolCalls = $turn->getToolCalls();

			$this->recordAgentTurnTrace($traceRunId, $turn, $logicalTurns);

			$assistantMessage = [
				'role' => 'assistant',
				'content' => $text,
			];
			if ($toolCalls !== []) {
				$assistantMessage['tool_calls'] = array_map(
					$this->toolCallForHistory(...),
					$toolCalls
				);
				if ($text === '') {
					$assistantMessage['content'] = null;
				}
			}
			$messages[] = $assistantMessage;

			if ($finishReason === 'content_filter') {
				return $this->buildRunResult(
					'error',
					'content_filter',
					'',
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			}

			if ($toolCalls === []) {
				if ($finishReason === 'length') {
					return $this->buildRunResult(
						'budget_exhausted',
						'length',
						'',
						$messages,
						$toolInvocations,
						$finishReason,
						$compatibilitySource,
						$logicalTurns,
						$providerAttemptBudget,
						$rateLimitHeaders
					);
				}

				if ($turn->isEmpty()) {
					return $this->buildRunResult(
						'error',
						'empty_response',
						'',
						$messages,
						$toolInvocations,
						$finishReason,
						$compatibilitySource,
						$logicalTurns,
						$providerAttemptBudget,
						$rateLimitHeaders
					);
				}

				if ($onPartialResult !== null && !$streamedAssistantDeltaEmitted) {
					$onPartialResult($text);
				}

				return $this->buildRunResult(
					'completed',
					'final_response',
					$text,
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			}

			$batchSize = count($toolCalls);
			if ($toolCallCount + $batchSize > $maxToolCalls) {
				return $this->buildRunResult(
					'budget_exhausted',
					'max_tool_calls',
					'',
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			}
			$toolCallCount += $batchSize;

			if ($finishReason === 'length') {
				foreach ($toolCalls as $toolCall) {
					$rejection = $this->rejectToolCall(
						$toolCall,
						'incomplete_tool_call',
						'The tool call was truncated by the provider and was not executed.',
						$traceRunId
					);
					$messages[] = $rejection['message'];
					$toolInvocations[] = $rejection['invocation'];
				}
				continue;
			}

			$preparedToolCalls = array_map(
				fn (array $toolCall): array => $this->prepareToolCall($toolCall, $toolMap),
				$toolCalls
			);
			$repetitionLimitReached = false;
			$prospectiveToolBatches = $recentToolBatches;
			$batchSignature = $this->buildToolCallBatchSignature($preparedToolCalls);
			if ($batchSignature !== '') {
				$prospectiveToolBatches[] = $batchSignature;
				$batchThreshold = $this->toolExecutionPolicyService->loopThresholdForToolCalls($toolCalls, $toolMap);
				$repetitionLimitReached = $this->countTrailingMatchingToolSignatures($prospectiveToolBatches) >= $batchThreshold;
			}

			$prospectiveToolCalls = $recentToolCalls;
			foreach ($preparedToolCalls as $preparedToolCall) {
				$callSignature = $this->buildToolCallBatchSignature([$preparedToolCall]);
				if ($callSignature === '') {
					continue;
				}
				$prospectiveToolCalls[] = $callSignature;
				$callThreshold = $this->toolExecutionPolicyService->loopThresholdForToolCalls(
					[$preparedToolCall['toolCall']],
					$toolMap
				);
				if ($this->countTrailingMatchingToolSignatures($prospectiveToolCalls) >= $callThreshold) {
					$repetitionLimitReached = true;
					break;
				}
			}

			if ($repetitionLimitReached) {
				return $this->buildRunResult(
					'budget_exhausted',
					'repetition_limit',
					'',
					$messages,
					$toolInvocations,
					$finishReason,
					$compatibilitySource,
					$logicalTurns,
					$providerAttemptBudget,
					$rateLimitHeaders
				);
			}
			$recentToolBatches = $prospectiveToolBatches;
			$recentToolCalls = $prospectiveToolCalls;

			if ($onToolProgress !== null) {
				$toolNames = [];
				foreach ($preparedToolCalls as $preparedToolCall) {
					if ($preparedToolCall['rejection'] !== null) {
						continue;
					}
					$displayName = $this->safeToolProgressName($preparedToolCall['toolName']);
					if ($displayName !== null) {
						$toolNames[] = $displayName;
					}
				}
				if ($toolNames !== []) {
					$onToolProgress('🔧 _Using tool' . (count($toolNames) === 1 ? '' : 's') . ': ' . implode(', ', $toolNames) . '..._');
				}
			}

			foreach ($preparedToolCalls as $preparedToolCall) {
				try {
					$runControl->assertCanContinue();
					$execution = $this->executePreparedToolCall(
						$preparedToolCall,
						$builtInTools,
						$traceRunId,
						$runControl
					);
				} catch (AgentRunInterruptedException $e) {
					return $this->buildInterruptedRunResult(
						$e,
						$messages,
						$toolInvocations,
						$finishReason,
						$compatibilitySource,
						$logicalTurns,
						$providerAttemptBudget,
						$rateLimitHeaders
					);
				}

				$messages[] = $execution['message'];
				$toolInvocations[] = $execution['invocation'];
			}
		}
	}

	private function resolveBoundedLimit(mixed $value, int $default, int $hardLimit): int {
		if (!is_numeric($value)) {
			return $default;
		}

		return max(1, min($hardLimit, (int)$value));
	}

	/**
	 * @param array<string,mixed> $toolCall
	 * @return array<string,mixed>
	 */
	private function toolCallForHistory(array $toolCall): array {
		unset($toolCall['argument_error']);
		return $toolCall;
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $toolInvocations
	 * @param array<string,string> $rateLimitHeaders
	 * @return array<string,mixed>
	 */
	private function buildRunResult(
		string $status,
		string $terminalReason,
		string $content,
		array $messages,
		array $toolInvocations,
		?string $finishReason,
		string $compatibilitySource,
		int $logicalTurns,
		ProviderAttemptBudget $providerAttemptBudget,
		array $rateLimitHeaders,
	): array {
		return [
			'status' => $status,
			'terminalReason' => $terminalReason,
			'content' => $content,
			'messages' => $messages,
			'toolInvocations' => $toolInvocations,
			'finishReason' => $finishReason,
			'compatibilitySource' => $compatibilitySource,
			'logicalTurns' => $logicalTurns,
			'providerAttempts' => $providerAttemptBudget->getConsumed(),
			'rateLimitHeaders' => $rateLimitHeaders,
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $toolInvocations
	 * @param array<string,int|string> $rateLimitHeaders
	 * @return array<string,mixed>
	 */
	private function buildInterruptedRunResult(
		AgentRunInterruptedException $exception,
		array $messages,
		array $toolInvocations,
		?string $finishReason,
		string $compatibilitySource,
		int $logicalTurns,
		ProviderAttemptBudget $providerAttemptBudget,
		array $rateLimitHeaders,
	): array {
		return $this->buildRunResult(
			$exception->getReason() === AgentRunInterruptedException::REASON_WALL_CLOCK ? 'budget_exhausted' : 'error',
			$exception->getReason(),
			'',
			$messages,
			$toolInvocations,
			$finishReason,
			$compatibilitySource,
			$logicalTurns,
			$providerAttemptBudget,
			$rateLimitHeaders
		);
	}

	private function recordAgentTurnTrace(?int $traceRunId, AgentTurn $turn, int $logicalTurn): void {
		$this->traceService?->recordEvent($traceRunId, 'llm_response', [
			'status' => 'ok',
			'payload' => [
				'stage' => 'agent_loop',
				'logical_turn' => $logicalTurn,
				'model' => $turn->getModel(),
				'model_reference' => $turn->getModelReference(),
				'model_endpoint' => $turn->getModelEndpoint(),
				'usage' => $turn->getUsage(),
				'finish_reason' => $turn->getStopReason(),
				'content_length' => strlen($turn->getText()),
				'tool_calls' => count($turn->getToolCalls()),
				'compatibility_source' => $turn->getCompatibilitySource(),
			],
		]);
	}

	/**
	 * @param array<string,mixed> $toolCall
	 * @param array<string,ToolMapEntry> $toolMap
	 * @return PreparedToolCall
	 */
	private function prepareToolCall(array $toolCall, array $toolMap): array {
		$toolName = $toolCall['function']['name'] ?? null;
		if (!is_string($toolName) || trim($toolName) === '') {
			return [
				'toolCall' => $toolCall,
				'toolName' => null,
				'toolContext' => null,
				'arguments' => null,
				'rejection' => [
					'code' => 'malformed_tool_call',
					'message' => 'The tool call did not contain a valid tool name.',
					'details' => [],
				],
			];
		}
		$toolName = trim($toolName);

		$toolContext = $toolMap[$toolName] ?? null;
		if ($toolContext === null) {
			return [
				'toolCall' => $toolCall,
				'toolName' => $toolName,
				'toolContext' => null,
				'arguments' => null,
				'rejection' => [
					'code' => 'unknown_tool',
					'message' => 'The requested tool is not available for this run.',
					'details' => [],
				],
			];
		}

		$decodedArguments = $this->decodeToolCallArguments($toolCall);
		if ($decodedArguments['error'] !== null) {
			return [
				'toolCall' => $toolCall,
				'toolName' => $toolName,
				'toolContext' => $toolContext,
				'arguments' => null,
				'rejection' => [
					'code' => 'invalid_tool_arguments_json',
					'message' => $decodedArguments['error'],
					'details' => [],
				],
			];
		}

		$arguments = $this->toolArgumentNormalizer->filterToSchema($decodedArguments['arguments'], $toolContext);
		$arguments = $this->toolArgumentNormalizer->coerceToSchema($arguments, $toolContext);
		$missingArguments = $this->toolArgumentNormalizer->missingRequiredArguments($arguments, $toolContext);
		if ($missingArguments !== []) {
			return [
				'toolCall' => $toolCall,
				'toolName' => $toolName,
				'toolContext' => $toolContext,
				'arguments' => $arguments,
				'rejection' => [
					'code' => 'missing_required_arguments',
					'message' => 'The tool call is missing required arguments: ' . implode(', ', $missingArguments) . '.',
					'details' => ['missing' => array_values($missingArguments)],
				],
			];
		}

		return [
			'toolCall' => $toolCall,
			'toolName' => $toolName,
			'toolContext' => $toolContext,
			'arguments' => $arguments,
			'rejection' => null,
		];
	}

	private function safeToolProgressName(?string $toolName): ?string {
		if ($toolName === null || strlen($toolName) > 64) {
			return null;
		}

		return preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?\z/D', $toolName) === 1
			? $toolName
			: null;
	}

	/**
	 * @param PreparedToolCall $preparedToolCall
	 * @param array<string,mixed> $builtInTools
	 * @return array{message:array<string,mixed>,invocation:array<string,mixed>}
	 */
	private function executePreparedToolCall(
		array $preparedToolCall,
		array $builtInTools,
		?int $traceRunId,
		AgentRunControl $runControl,
	): array {
		$toolCall = $preparedToolCall['toolCall'];
		$callId = is_string($toolCall['id'] ?? null) ? $toolCall['id'] : '';
		$rejection = $preparedToolCall['rejection'];
		if ($rejection !== null) {
			return $this->rejectToolCall(
				$toolCall,
				$rejection['code'],
				$rejection['message'],
				$traceRunId,
				$rejection['details']
			);
		}

		$toolName = $preparedToolCall['toolName'];
		$toolContext = $preparedToolCall['toolContext'];
		$arguments = $preparedToolCall['arguments'];
		if ($toolName === null || $toolContext === null || $arguments === null) {
			return $this->rejectToolCall(
				$toolCall,
				'malformed_tool_call',
				'The tool call could not be prepared for execution.',
				$traceRunId
			);
		}

		$isBuiltIn = isset($builtInTools[$toolName]);
		$invokeName = is_string($toolContext['invokeName'] ?? null) ? $toolContext['invokeName'] : $toolName;
		$this->traceService?->recordToolCall($traceRunId, $toolName, $arguments, $callId);
		$start = microtime(true);
		$status = 'ok';

		try {
			if ($isBuiltIn) {
				$builtInConfig = is_array($toolContext['config'] ?? null) ? $toolContext['config'] : [];
				$result = $builtInConfig === []
					? $this->toolProviderRegistry->executeTool($toolName, $arguments)
					: $this->toolProviderRegistry->executeTool($toolName, $arguments, $builtInConfig);
			} else {
				$result = $this->mcpClient->callTool(
					$toolContext['tool'],
					$invokeName,
					$arguments,
					$toolContext['config'],
					$runControl
				);
			}

			if (($result['isError'] ?? false) === true) {
				$status = 'error';
				$output = $this->encodeToolError(
					'tool_execution_failed',
					'The tool reported an execution error.',
					$toolName
				);
			} else {
				$output = $this->sanitizeOutput($result);
			}
		} catch (AgentRunInterruptedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('EducAI: Tool execution failed', [
				'tool' => $toolName,
				'exception' => $e,
				'trace_run_id' => $traceRunId,
			]);
			$status = 'error';
			$output = $this->encodeToolError(
				'tool_execution_failed',
				'The tool could not be executed.',
				$toolName
			);
		}

		$durationMs = (int)round((microtime(true) - $start) * 1000);
		$this->traceService?->recordToolResult(
			$traceRunId,
			$toolName,
			$status,
			$output,
			$durationMs,
			$status === 'error' ? $output : null
		);

		return [
			'message' => $this->buildToolResultMessage($callId, $output),
			'invocation' => [
				'tool' => $toolName,
				'toolCallId' => $callId,
				'arguments' => $arguments,
				'status' => $status,
				'duration_ms' => $durationMs,
				'response' => $output,
			],
		];
	}

	/**
	 * @param array<string,mixed> $toolCall
	 * @param array<string,mixed> $details
	 * @return array{message:array<string,mixed>,invocation:array<string,mixed>}
	 */
	private function rejectToolCall(
		array $toolCall,
		string $code,
		string $message,
		?int $traceRunId,
		array $details = [],
	): array {
		$callId = is_string($toolCall['id'] ?? null) ? $toolCall['id'] : '';
		$toolName = $toolCall['function']['name'] ?? null;
		$safeToolName = is_string($toolName) && trim($toolName) !== '' ? trim($toolName) : 'unknown';
		$output = $this->encodeToolError($code, $message, $safeToolName, $details);

		$this->traceService?->recordEvent($traceRunId, 'tool_rejected', [
			'status' => 'error',
			'payload' => [
				'tool' => $safeToolName,
				'tool_call_id' => $callId,
				'code' => $code,
			],
		]);

		return [
			'message' => $this->buildToolResultMessage($callId, $output),
			'invocation' => [
				'tool' => $safeToolName,
				'toolCallId' => $callId,
				'arguments' => null,
				'status' => 'error',
				'duration_ms' => 0,
				'response' => $output,
			],
		];
	}

	/**
	 * @param array<string,mixed> $toolCall
	 * @return array{arguments:array<string,mixed>,error:?string}
	 */
	private function decodeToolCallArguments(array $toolCall): array {
		$argumentError = $toolCall['argument_error'] ?? null;
		if (is_string($argumentError) && $argumentError !== '') {
			return [
				'arguments' => [],
				'error' => $argumentError === ProviderResponseNormalizer::ARGUMENT_ERROR_NOT_OBJECT
					? 'Tool arguments must be a JSON object.'
					: 'Tool arguments must contain valid JSON.',
			];
		}

		$rawArguments = $toolCall['function']['arguments'] ?? '{}';
		if (!is_string($rawArguments) || trim($rawArguments) === '') {
			return ['arguments' => [], 'error' => 'Tool arguments must contain valid JSON.'];
		}

		$trimmed = trim($rawArguments);
		if (($trimmed[0] ?? '') !== '{') {
			return ['arguments' => [], 'error' => 'Tool arguments must be a JSON object.'];
		}

		try {
			$decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['arguments' => [], 'error' => 'Tool arguments must contain valid JSON.'];
		}

		if (!is_array($decoded)) {
			return ['arguments' => [], 'error' => 'Tool arguments must be a JSON object.'];
		}

		return ['arguments' => $decoded, 'error' => null];
	}

	/**
	 * @param array<string,mixed> $details
	 */
	private function encodeToolError(string $code, string $message, string $toolName, array $details = []): string {
		$error = [
			'code' => $code,
			'message' => $message,
			'tool' => $toolName,
		];
		if ($details !== []) {
			$error['details'] = $details;
		}

		return json_encode(
			['ok' => false, 'error' => $error],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		) ?: '{"ok":false,"error":{"code":"tool_error","message":"Tool error"}}';
	}

	/**
	 * Build a stable tool batch signature for loop detection.
	 *
	 * The signature includes normalized arguments so repeated searches with
	 * different queries do not count as the same loop pattern.
	 *
	 * @param array<int,PreparedToolCall> $preparedToolCalls
	 */
	private function buildToolCallBatchSignature(array $preparedToolCalls): string {
		$parts = [];
		foreach ($preparedToolCalls as $preparedToolCall) {
			$toolCall = $preparedToolCall['toolCall'];
			$name = $toolCall['function']['name'] ?? null;
			if (!is_string($name) || trim($name) === '') {
				continue;
			}

			$arguments = $preparedToolCall['arguments'];
			if ($arguments === null) {
				$payload = $toolCall['function']['arguments'] ?? '{}';
				$arguments = $this->decodeArguments($payload);
			}
			$normalizedArguments = $this->normalizeToolSignatureValue($arguments);
			$encodedArguments = json_encode($normalizedArguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($encodedArguments === false) {
				$encodedArguments = '{}';
			}

			if (strlen($encodedArguments) > 400) {
				$encodedArguments = substr($encodedArguments, 0, 400);
			}

			$parts[] = trim($name) . ':' . $encodedArguments;
		}

		return implode('|', $parts);
	}

	private function normalizeToolSignatureValue(array|bool|float|int|string|null $value): array|bool|float|int|string|null {
		if (is_array($value)) {
			if (!array_is_list($value)) {
				ksort($value);
			}

			$normalized = [];
			foreach ($value as $key => $item) {
				$normalized[$key] = $this->normalizeToolSignatureValue($item);
			}

			return $normalized;
		}

		if (is_string($value)) {
			$normalized = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
			if (strlen($normalized) > 250) {
				return substr($normalized, 0, 250);
			}

			return $normalized;
		}

		if (is_float($value)) {
			return round($value, 6);
		}

		return $value;
	}

	/**
	 * @param array<int,string> $recentToolCalls
	 */
	private function countTrailingMatchingToolSignatures(array $recentToolCalls): int {
		if (count($recentToolCalls) === 0) {
			return 0;
		}

		$lastSignature = $recentToolCalls[count($recentToolCalls) - 1];
		$count = 0;
		for ($i = count($recentToolCalls) - 1; $i >= 0; $i--) {
			if ($recentToolCalls[$i] !== $lastSignature) {
				break;
			}
			$count++;
		}

		return $count;
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<string,mixed> $options
	 * @param array<string,mixed> $metadata
	 */
	private function recordLlmRequestTrace(
		?int $traceRunId,
		string $stage,
		string $systemPrompt,
		array $messages,
		?string $model,
		array $options,
		bool $streaming,
		array $metadata = [],
	): void {
		if ($this->traceService === null) {
			return;
		}

		$tracePayload = $this->buildTraceChatCompletionPayload($systemPrompt, $messages, $model, $options, $streaming);
		$providerPayload = $tracePayload['payload'] ?? [];
		$payload = array_merge([
			'stage' => $stage,
			'model' => $model,
			'streaming' => $streaming,
			'message_count' => count($messages),
			'tool_count' => isset($providerPayload['tools']) && is_array($providerPayload['tools']) ? count($providerPayload['tools']) : 0,
			'tool_choice' => $providerPayload['tool_choice'] ?? null,
			'temperature' => $providerPayload['temperature'] ?? null,
			'max_tokens' => $providerPayload['max_tokens'] ?? null,
			'max_completion_tokens' => $providerPayload['max_completion_tokens'] ?? null,
			'request_endpoint' => $tracePayload['endpoint'] ?? null,
			'request_model_reference' => $tracePayload['model_reference'] ?? null,
			'request_payload' => $providerPayload !== [] ? $providerPayload : $tracePayload,
		], $metadata);

		if (isset($tracePayload['trace_payload_error'])) {
			$payload['trace_payload_error'] = $tracePayload['trace_payload_error'];
		}

		$this->traceService->recordEvent($traceRunId, 'llm_request', [
			'payload' => $payload,
		]);
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function buildTraceChatCompletionPayload(string $systemPrompt, array $messages, ?string $model, array $options, bool $streaming): array {
		try {
			return $this->llmClient->buildTraceChatCompletionPayload($systemPrompt, $messages, $model, $options, $streaming);
		} catch (AgentRunInterruptedException|ProviderAttemptBudgetExceededException $e) {
			throw $e;
		} catch (\Throwable $e) {
			return [
				'endpoint' => null,
				'model_reference' => $model,
				'payload' => [
					'model' => $model,
					'messages' => array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages),
					'temperature' => $options['temperature'] ?? null,
					'max_tokens' => $options['max_tokens'] ?? null,
					'stream' => $streaming ?: null,
					'tools' => $options['tools'] ?? null,
					'tool_choice' => $options['tool_choice'] ?? null,
				],
				'trace_payload_error' => self::TRACE_PREFLIGHT_ERROR,
			];
		}
	}

	/**
	 * @param array<int,McpToolLoadoutEntry> $toolLoadout
	 * @param int|null $botId Bot ID to filter built-in tools (null = no built-in tools)
	 * @param array<int,BuiltInToolLoadoutEntry>|null $explicitBuiltInTools
	 * @return ToolDefinitionBuildResult
	 */
	private function buildToolDefinitions(
		array $toolLoadout,
		?int $botId = null,
		?array $explicitBuiltInTools = null,
		?AgentRunControl $runControl = null,
	): array {
		$definitions = [];
		$map = [];
		$builtIn = [];

		$assignedBuiltInNames = [];
		$assignedBuiltInConfigs = [];
		if ($explicitBuiltInTools !== null) {
			$assignedBuiltInTools = $explicitBuiltInTools;
		} elseif ($botId !== null) {
			$assignedBuiltInTools = $this->toolRegistry->getBuiltInToolsForBot($botId);
		} else {
			$assignedBuiltInTools = [];
		}
		foreach ($assignedBuiltInTools as $entry) {
			$name = $entry['name'] ?? null;
			if (!is_string($name) || $name === '') {
				continue;
			}
			$assignedBuiltInNames[] = $name;
			$assignedBuiltInConfigs[$name] = isset($entry['config']) && is_array($entry['config']) ? $entry['config'] : [];
		}

		// Get available built-in tool definitions from provider
		$builtInToolDefs = $this->toolProviderRegistry->getAvailableTools();
		foreach ($builtInToolDefs as $toolDef) {
			$name = $toolDef['name'] ?? null;
			if (!is_string($name)) {
				continue;
			}

			$isAssigned = in_array($name, $assignedBuiltInNames, true);

			if (!$isAssigned) {
				continue;
			}

			$definition = [
				'type' => 'function',
				'function' => [
					'name' => $name,
					'description' => $toolDef['description'] ?? '',
					'parameters' => $toolDef['schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
				],
			];
			$policy = isset($toolDef['policy']) && is_array($toolDef['policy'])
				? $toolDef['policy']
				: $this->toolExecutionPolicyService->builtInPolicy($name);
			$definitions[] = $definition;
			$builtIn[$name] = [
				'name' => $name,
				'definition' => $definition,
				'config' => $assignedBuiltInConfigs[$name] ?? [],
				'policy' => $policy,
			];
			// Also add to map for schema filtering
			$map[$name] = [
				'tool' => null, // No MCP tool for built-in
				'config' => $assignedBuiltInConfigs[$name] ?? [],
				'definition' => $definition,
				'invokeName' => $name,
				'policy' => $policy,
			];
		}

		// Add MCP tools
		foreach ($toolLoadout as $entry) {
			$tool = $entry['tool'];
			$config = $entry['config'];
			try {
				$runControl?->assertCanContinue();
				$descriptors = $this->mcpClient->listTools($tool, [], $runControl);
			} catch (AgentRunInterruptedException $e) {
				throw $e;
			} catch (\Throwable $e) {
				$this->logger->error('Failed to list tools for MCP endpoint', [
					'tool_id' => $tool->getId(),
					'exception' => $e,
				]);
				continue;
			}
			foreach ($descriptors as $descriptor) {
				$name = $descriptor['name'] ?? null;
				if (!is_string($name)) {
					continue;
				}
				$exposedName = $name;
				if (isset($map[$exposedName])) {
					// Preserve all tools by assigning deterministic aliases on name collision.
					$exposedName = $this->buildMcpAliasName($name, (int)$tool->getId(), $map);
					$this->logger->warning('EducAI: MCP tool name collision detected, aliasing tool', [
						'original_name' => $name,
						'alias' => $exposedName,
						'tool_id' => $tool->getId(),
					]);
				}
				$definition = [
					'type' => 'function',
					'function' => [
						'name' => $exposedName,
						'description' => $descriptor['description'] ?? '',
						'parameters' => $this->schemaFromMcpDescriptor($descriptor),
					],
				];
				$annotations = isset($descriptor['annotations']) && is_array($descriptor['annotations'])
					? $descriptor['annotations']
					: [];
				$policy = isset($descriptor['policy']) && is_array($descriptor['policy'])
					? $descriptor['policy']
					: $this->toolExecutionPolicyService->mcpPolicy($exposedName, $name, (string)($descriptor['description'] ?? ''), $annotations);
				$definitions[] = $definition;
				$map[$exposedName] = [
					'tool' => $tool,
					'config' => $config,
					'definition' => $definition,
					'invokeName' => $name,
					'policy' => $policy,
				];
			}
		}

		return [
			'definitions' => $definitions,
			'map' => $map,
			'builtIn' => $builtIn,
		];
	}

	/**
	 * MCP's canonical field is inputSchema. Keep schema as a legacy fallback for
	 * descriptors produced by older/local adapters.
	 *
	 * @param array<string,mixed> $descriptor
	 * @return array<string,mixed>|\stdClass
	 */
	private function schemaFromMcpDescriptor(array $descriptor): array|\stdClass {
		$schema = $descriptor['inputSchema'] ?? $descriptor['schema'] ?? null;
		if (!is_array($schema) && !($schema instanceof \stdClass)) {
			return ['type' => 'object', 'properties' => new \stdClass()];
		}

		$schema = $this->normalizeJsonSchemaProperties((array)$schema);

		// OpenAI/Azure and Anthropic require tool parameters to be a JSON Schema
		// object. Guarantee that even when the MCP server omits the type.
		if (!isset($schema['type'])) {
			$schema['type'] = 'object';
		}
		if (($schema['type'] ?? null) === 'object' && !isset($schema['properties'])) {
			$schema['properties'] = new \stdClass();
		}

		return $schema;
	}

	/**
	 * json_decode($json, true) turns an empty JSON object ("properties": {}) into
	 * an empty PHP array ([]), which re-encodes to a JSON array. Azure/Anthropic
	 * then reject the tool schema ("[] is not of type 'object'"), which breaks
	 * every argument-less MCP tool (e.g. mensa_openings, list_campuses). Force any
	 * "properties" map back to an object (recursively) so empty ones serialize as
	 * {} again.
	 *
	 * @param array<string,mixed> $schema
	 * @return array<string,mixed>
	 */
	private function normalizeJsonSchemaProperties(array $schema): array {
		if (array_key_exists('properties', $schema)) {
			$properties = $schema['properties'];
			if ($properties === [] || $properties instanceof \stdClass) {
				$schema['properties'] = new \stdClass();
			} elseif (is_array($properties)) {
				$normalized = [];
				foreach ($properties as $name => $subSchema) {
					$normalized[$name] = is_array($subSchema)
						? $this->normalizeJsonSchemaProperties($subSchema)
						: $subSchema;
				}
				$schema['properties'] = $normalized;
			}
		}

		return $schema;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decodeArguments(string $payload): array {
		$decoded = json_decode($payload, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function sanitizeOutput(array $result): string {
		$textContent = $this->extractTextFromToolPayload($result);
		if ($textContent !== null) {
			return $this->truncateUtf8($textContent, 4000);
		}

		$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($encoded === false) {
			return 'Received invalid tool response';
		}
		return $this->truncateUtf8($encoded, 4000);
	}

	private function truncateUtf8(string $text, int $maxLength): string {
		$text = $this->ensureValidUtf8($text);
		if (mb_strlen($text, 'UTF-8') <= $maxLength) {
			return $text;
		}

		return mb_substr($text, 0, $maxLength, 'UTF-8') . '...';
	}

	private function ensureValidUtf8(string $text): string {
		if (mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}

		return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
	}

	/**
	 * Extract text-bearing content blocks from a tool payload.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function extractTextFromToolPayload(array $payload): ?string {
		$parts = [];

		if (isset($payload['content']) && is_array($payload['content'])) {
			foreach ($payload['content'] as $item) {
				if (is_array($item) && ($item['type'] ?? null) === 'text' && isset($item['text']) && is_string($item['text'])) {
					$text = trim($item['text']);
					if ($text !== '') {
						$parts[] = $text;
					}
				} elseif (is_string($item)) {
					$text = trim($item);
					if ($text !== '') {
						$parts[] = $text;
					}
				}
			}
		}

		if (count($parts) > 0) {
			return implode("\n\n", $parts);
		}

		if (isset($payload['text']) && is_string($payload['text']) && trim($payload['text']) !== '') {
			return trim($payload['text']);
		}

		return null;
	}

	private function resolveTemperatureOption($value): float {
		if (!is_numeric($value)) {
			return SettingsService::DEFAULT_TEMPERATURE;
		}

		$temperature = (float)$value;
		if (!is_finite($temperature) || $temperature < 0.0 || $temperature > 1.0) {
			return SettingsService::DEFAULT_TEMPERATURE;
		}

		return round($temperature, 2);
	}

	/**
	 * Build a stable alias name for MCP tools when multiple tools share the same exposed name.
	 *
	 * @param array<string,mixed> $existingMap
	 */
	private function buildMcpAliasName(string $name, int $toolId, array $existingMap): string {
		$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?? $name;
		$sanitized = trim($sanitized, '_');
		if ($sanitized === '') {
			$sanitized = 'tool';
		}
		$suffix = '__mcp' . $toolId;
		$maxBaseLength = max(1, 64 - strlen($suffix));
		$base = substr($sanitized, 0, $maxBaseLength);
		$candidate = $base . $suffix;
		$counter = 2;
		while (isset($existingMap[$candidate])) {
			$counterSuffix = $suffix . '_' . $counter;
			$maxLength = max(1, 64 - strlen($counterSuffix));
			$candidate = substr($sanitized, 0, $maxLength) . $counterSuffix;
			$counter++;
		}
		return $candidate;
	}

	/**
	 * @return array{role:string,tool_call_id:string,content:string}
	 */
	private function buildToolResultMessage(string $toolCallId, string $content): array {
		return [
			'role' => 'tool',
			'tool_call_id' => $toolCallId,
			'content' => $content,
		];
	}

}
