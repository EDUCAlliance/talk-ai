<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type BuiltInToolLoadoutEntry from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type LlmToolCall from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type McpToolLoadoutEntry from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type ToolDefinitionBuildResult from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type ToolMapEntry from \OCA\EducAI\TypeDefinitions
 */
class AgentExecutor {
    private const MAX_EMERGENCY_FALLBACK_TEMPERATURE = 0.3;
    private const MAX_TOOL_ITERATIONS = 50;

    private LLMClient $llmClient;
    private McpClient $mcpClient;
    private ToolRegistry $toolRegistry;
    private ToolProviderRegistry $toolProviderRegistry;
    private ToolExecutionPolicyService $toolExecutionPolicyService;
    private ToolArgumentNormalizer $toolArgumentNormalizer;
    private ToolResultFallbackService $toolResultFallbackService;
    private ?TraceService $traceService;
    private LoggerInterface $logger;

    public function __construct(
        LLMClient $llmClient,
        McpClient $mcpClient,
        ToolRegistry $toolRegistry,
        ToolProviderRegistry $toolProviderRegistry,
        LoggerInterface $logger,
        ?ToolExecutionPolicyService $toolExecutionPolicyService = null,
        ?ToolArgumentNormalizer $toolArgumentNormalizer = null,
        ?ToolResultFallbackService $toolResultFallbackService = null,
        ?TraceService $traceService = null
    ) {
        $this->llmClient = $llmClient;
        $this->mcpClient = $mcpClient;
        $this->toolRegistry = $toolRegistry;
        $this->toolProviderRegistry = $toolProviderRegistry;
        $this->toolExecutionPolicyService = $toolExecutionPolicyService ?? new ToolExecutionPolicyService();
        $this->toolArgumentNormalizer = $toolArgumentNormalizer ?? new ToolArgumentNormalizer();
        $this->toolResultFallbackService = $toolResultFallbackService ?? new ToolResultFallbackService();
        $this->traceService = $traceService;
        $this->logger = $logger;
    }

    /**
     * @param string $systemPrompt
     * @param array<int,array<string,mixed>> $messages
     * @param array<int,McpToolLoadoutEntry> $toolLoadout MCP tools assigned to the bot
     * @param array<string,mixed> $llmOptions Supported keys: model, temperature, max_tokens,
     *        tool_choice, initial_tool_choice, force_tool_call (bool - require at least one tool call before answering),
     *        user_query (string - sanitized user request for explicit harness-created forced tool calls),
     *        bot_id (int - the bot ID to get assigned built-in tools),
     *        built_in_tools (array<int,BuiltInToolLoadoutEntry> - explicit built-in tool assignments),
     *        rag_enabled (bool - whether to include RAG search tool for this bot),
     *        trace_run_id (int - app-owned trace run for best-effort activity capture)
     * @return array{content:string,messages:array<int,array<string,mixed>>,toolInvocations:array<int,array<string,mixed>>}
     */
    public function run(string $systemPrompt, array $messages, array $toolLoadout, array $llmOptions = []): array {
        // Get bot ID for built-in tool filtering
        $botId = isset($llmOptions['bot_id']) && is_int($llmOptions['bot_id']) ? $llmOptions['bot_id'] : null;
        unset($llmOptions['bot_id']);

        $traceRunId = isset($llmOptions['trace_run_id']) && is_numeric($llmOptions['trace_run_id']) ? (int)$llmOptions['trace_run_id'] : null;
        unset($llmOptions['trace_run_id']);

        $explicitBuiltInTools = [];
        if (isset($llmOptions['built_in_tools']) && is_array($llmOptions['built_in_tools'])) {
            $explicitBuiltInTools = $llmOptions['built_in_tools'];
        }
        unset($llmOptions['built_in_tools']);
        
        // Check if RAG tool should be included
        $ragEnabled = isset($llmOptions['rag_enabled']) && $llmOptions['rag_enabled'] === true;
        unset($llmOptions['rag_enabled']);
        
        $toolDefs = $this->buildToolDefinitions($toolLoadout, $botId, $ragEnabled, $explicitBuiltInTools);
        $toolMap = $toolDefs['map'];
        $toolsForLlm = $toolDefs['definitions'];
        $builtInTools = $toolDefs['builtIn'];
        
        $this->logger->info('EducAI: Agent starting', [
            'tools_requested' => count($toolLoadout),
            'tools_loaded' => count($toolsForLlm),
            'built_in_tools' => count($builtInTools),
            'tool_names' => array_keys($toolMap),
            'trace_run_id' => $traceRunId,
        ]);
        
        if (count($toolLoadout) > 0 && count($toolsForLlm) === 0 && count($builtInTools) === 0) {
            $this->logger->warning('EducAI: No tools could be loaded from MCP endpoints and no built-in tools available');
        }
        
        $userQuery = isset($llmOptions['user_query']) && is_string($llmOptions['user_query']) ? trim($llmOptions['user_query']) : null;
        unset($llmOptions['user_query']);
        $forceToolCall = !empty($llmOptions['force_tool_call']) && count($toolsForLlm) > 0;
        unset($llmOptions['force_tool_call']);
        $toolChoicePreference = $llmOptions['tool_choice'] ?? null;
        $initialToolChoice = $llmOptions['initial_tool_choice'] ?? null;
        unset($llmOptions['initial_tool_choice']);
        $onPartialResult = isset($llmOptions['on_partial_result']) && is_callable($llmOptions['on_partial_result']) ? $llmOptions['on_partial_result'] : null;
        unset($llmOptions['on_partial_result']);
        $toolCallSatisfied = false;
        $resolvedTemperature = $this->resolveTemperatureOption($llmOptions['temperature'] ?? SettingsService::DEFAULT_TEMPERATURE);

        if (count($toolsForLlm) > 0) {
            $systemPrompt .= "\n\n## Tool Calling Instructions\n";
            $systemPrompt .= "You have access to tools. Follow these rules strictly:\n\n";
            $systemPrompt .= "1. **Use structured tool calls**: When you need to use a tool, emit a proper tool call - NEVER write JSON directly in your response.\n";
            $systemPrompt .= "2. **Workflow**: Think → Call tool(s) → Read response → Answer user OR call more tools.\n";
            $systemPrompt .= "3. **Multiple calls allowed**: You can call tools multiple times until you have all the information you need.\n";
            $systemPrompt .= "4. **Don't invent data**: If information should come from a tool, call the tool. Don't make up facts.\n";
            $systemPrompt .= "5. **Synthesize naturally**: After receiving tool results, write a natural response for the user based on the information. Never mention 'tool calls' or show raw JSON to the user.\n";
            $systemPrompt .= "6. **Empty results**: If a tool returns no results, explain this to the user rather than guessing.\n";
        }

        $trace = [];
        $iterations = 0;
        $requestedMaxIterations = $llmOptions['max_iterations'] ?? self::MAX_TOOL_ITERATIONS;
        unset($llmOptions['max_iterations']);
        $maxIterations = is_numeric($requestedMaxIterations)
            ? max(1, min(self::MAX_TOOL_ITERATIONS, (int)$requestedMaxIterations))
            : self::MAX_TOOL_ITERATIONS;
        $recentToolCalls = []; // Track recent tool calls to detect loops

        while (true) {
            $iterations++;
            
            $this->logger->debug('EducAI: Agent loop iteration', [
                'iteration' => $iterations,
                'max_iterations' => $maxIterations,
                'tool_call_satisfied' => $toolCallSatisfied,
                'force_tool_call' => $forceToolCall,
                'message_count' => count($messages),
            ]);
            
            $toolChoice = $toolChoicePreference ?? null;
            if (!$toolCallSatisfied && $initialToolChoice !== null) {
                $toolChoice = $initialToolChoice;
            } elseif ($forceToolCall && !$toolCallSatisfied) {
                $toolChoice = 'required';
            }

            $requestOptions = [
                'tools' => $toolsForLlm,
                'tool_choice' => $toolChoice,
                'temperature' => $resolvedTemperature,
                'max_tokens' => $llmOptions['max_tokens'] ?? 800,
            ];

            $this->recordLlmRequestTrace(
                $traceRunId,
                'agent_loop',
                $systemPrompt,
                $messages,
                $llmOptions['model'] ?? null,
                $requestOptions,
                $onPartialResult !== null,
                ['iteration' => $iterations]
            );

            try {
            if ($onPartialResult) {
                $buffer = '';
                $lastFlushTime = microtime(true);
                $streamArtifactBuffer = '';
                $isInsideXmlToolCall = false;
                $leadingStructuredProbe = '';
                $suppressStreamingForStructuredResponse = false;
                $hasSeenVisibleStreamContent = false;
                $response = $this->llmClient->streamChatCompletion(
                    $systemPrompt,
                    $messages,
                    function ($delta) use (
                        &$buffer,
                        &$lastFlushTime,
                        $onPartialResult,
                        &$streamArtifactBuffer,
                        &$isInsideXmlToolCall,
                        &$leadingStructuredProbe,
                        &$suppressStreamingForStructuredResponse,
                        &$hasSeenVisibleStreamContent
                    ) {
                        if (isset($delta['content'])) {
                            $contentDelta = is_string($delta['content']) ? $delta['content'] : '';
                            if ($contentDelta === '') {
                                return;
                            }

                            if (!$hasSeenVisibleStreamContent && !$suppressStreamingForStructuredResponse) {
                                $leadingStructuredProbe .= $contentDelta;
                                $trimmedProbe = ltrim($leadingStructuredProbe);
                                if ($trimmedProbe === '') {
                                    return;
                                }

                                $firstChar = $trimmedProbe[0] ?? '';
                                if ($firstChar === '{' || $firstChar === '[') {
                                    $suppressStreamingForStructuredResponse = true;
                                    return;
                                }

                                $contentDelta = $leadingStructuredProbe;
                                $leadingStructuredProbe = '';
                                $hasSeenVisibleStreamContent = true;
                            } elseif ($suppressStreamingForStructuredResponse) {
                                return;
                            }

                            $visibleDelta = $this->filterStreamingToolCallContent(
                                $contentDelta,
                                $streamArtifactBuffer,
                                $isInsideXmlToolCall
                            );
                            if ($visibleDelta === '') {
                                return;
                            }

                            $buffer .= $visibleDelta;
                            $currentTime = microtime(true);
                            
                            // Flush on paragraph boundaries OR after 3 seconds of content accumulation
                            // This ensures content is sent even without double newlines
                            $shouldFlush = str_contains($buffer, "\n\n") 
                                || ($currentTime - $lastFlushTime > 3.0 && strlen($buffer) > 100);
                            
                            if ($shouldFlush && str_contains($buffer, "\n\n")) {
                                $parts = explode("\n\n", $buffer);
                                $remainder = array_pop($parts);
                                foreach ($parts as $part) {
                                    if (trim($part) !== '') {
                                        $onPartialResult($part);
                                    }
                                }
                                $buffer = $remainder;
                                $lastFlushTime = $currentTime;
                            } elseif ($shouldFlush) {
                                // Time-based flush without double newline
                                // Try to flush at sentence boundaries
                                $flushPoint = $this->findSentenceBoundary($buffer);
                                if ($flushPoint > 50) {
                                    $toSend = substr($buffer, 0, $flushPoint);
                                    $buffer = substr($buffer, $flushPoint);
                                    if (trim($toSend) !== '') {
                                        $onPartialResult(trim($toSend));
                                    }
                                    $lastFlushTime = $currentTime;
                                }
                            }
                        }
                    },
                    $llmOptions['model'] ?? null,
                    $requestOptions
                );

                if (!$suppressStreamingForStructuredResponse && $leadingStructuredProbe !== '') {
                    $buffer .= $this->filterStreamingToolCallContent(
                        $leadingStructuredProbe,
                        $streamArtifactBuffer,
                        $isInsideXmlToolCall
                    );
                    $leadingStructuredProbe = '';
                }

                if (!$suppressStreamingForStructuredResponse) {
                    $buffer .= $this->filterStreamingToolCallContent(
                        '',
                        $streamArtifactBuffer,
                        $isInsideXmlToolCall,
                        true
                    );
                }

                // Always flush remaining buffer after stream ends
                if ($buffer !== '' && trim($buffer) !== '') {
                    $onPartialResult($buffer);
                }
            } else {
                $response = $this->llmClient->sendChatCompletion($systemPrompt, $messages, $llmOptions['model'] ?? null, $requestOptions);
            }
            } catch (\Throwable $e) {
                $this->traceService?->recordEvent($traceRunId, 'error', [
                    'status' => 'error',
                    'payload' => [
                        'stage' => 'llm_request',
                        'iteration' => $iterations,
                    ],
                    'error_message' => $e->getMessage(),
                ]);
                throw $e;
            }

            // Log LLM response for debugging
            $this->logger->debug('EducAI: LLM response received', [
                'iteration' => $iterations,
                'has_content' => !empty($response['content']),
                'content_length' => strlen((string)($response['content'] ?? '')),
                'native_tool_calls' => count($response['tool_calls'] ?? []),
                'finish_reason' => $response['finish_reason'] ?? 'unknown',
                'trace_run_id' => $traceRunId,
            ]);

            $this->recordLlmResponseTrace(
                $traceRunId,
                'agent_loop',
                $response,
                (string)($response['content'] ?? ''),
                ['iteration' => $iterations]
            );

            $toolCalls = $this->extractToolCallsFromResponse($response);
            $assistantMessage = [
                'role' => 'assistant',
                'content' => $response['content'] ?? '',
            ];
            if (count($toolCalls) > 0) {
                // Assistant message must carry the tool calls that led to subsequent tool messages.
                $assistantMessage['content'] = null;
                $assistantMessage['tool_calls'] = $toolCalls;
            }
            $messages[] = $assistantMessage;

            if (is_array($toolCalls) && count($toolCalls) > 0) {
                $toolCallSatisfied = true;
            }

            // Fallback: Check if content is a JSON tool call (for models that don't support native tool calling)
            if ((!is_array($toolCalls) || count($toolCalls) === 0) && !empty($response['content'])) {
                $content = trim($response['content']);
                
                // Try to extract JSON from the response (could be wrapped in markdown code blocks)
                $jsonContent = $this->extractJsonFromContent($content);
                
                if ($jsonContent !== null) {
                    $normalizedCalls = $this->normalizeFallbackToolCalls($jsonContent, $toolMap);
                    if ($normalizedCalls !== null) {
                        $this->logger->info('EducAI: Fallback constructed tool calls from JSON', [
                            'call_count' => count($normalizedCalls),
                            'tools' => array_map(static fn($c) => $c['name'], $normalizedCalls),
                        ]);

                        $toolCalls = [];
                        foreach ($normalizedCalls as $call) {
                            $encodedArgs = json_encode($call['arguments'], JSON_UNESCAPED_UNICODE);
                            if ($encodedArgs === false) {
                                $encodedArgs = '{}';
                            }
                            $toolCalls[] = [
                                'id' => uniqid('call_', true),
                                'type' => 'function',
                                'function' => [
                                    'name' => $call['name'],
                                    'arguments' => $encodedArgs,
                                ],
                            ];
                        }

                        // Mark tool call as satisfied since we successfully parsed JSON tool calls
                        $toolCallSatisfied = true;

                        // Replace the assistant message with proper tool_calls format (no content)
                        array_pop($messages);
                        $messages[] = [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => $toolCalls,
                        ];
                    }
                }
            }

            // Fallback: Check if content contains XML tool calls (<tool_call>...</tool_call>)
            if ((!is_array($toolCalls) || count($toolCalls) === 0) && !empty($response['content'])) {
                $xmlResult = $this->extractXmlToolCalls($response['content'], $toolMap);
                if ($xmlResult !== null && count($xmlResult['calls']) > 0) {
                    $this->logger->info('EducAI: Extracted tool calls from XML in content', [
                        'call_count' => count($xmlResult['calls']),
                        'tools' => array_map(static fn($call) => $call['name'], $xmlResult['calls']),
                        'remaining_content_length' => strlen($xmlResult['remainingContent']),
                    ]);

                    $toolCalls = [];
                    foreach ($xmlResult['calls'] as $call) {
                        $encodedArgs = json_encode($call['arguments'], JSON_UNESCAPED_UNICODE);
                        if ($encodedArgs === false) {
                            $encodedArgs = '{}';
                        }

                        $toolCalls[] = [
                            'id' => uniqid('xml_call_', true),
                            'type' => 'function',
                            'function' => [
                                'name' => $call['name'],
                                'arguments' => $encodedArgs,
                            ],
                        ];
                    }

                    $toolCallSatisfied = true;

                    array_pop($messages);
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $xmlResult['remainingContent'] !== '' ? $xmlResult['remainingContent'] : null,
                        'tool_calls' => $toolCalls,
                    ];
                }
            }

            if ((!is_array($toolCalls) || count($toolCalls) === 0) && !$toolCallSatisfied && $initialToolChoice !== null) {
                $forcedCall = $this->buildPreferredToolCall($initialToolChoice, $toolMap, $userQuery);
                if ($forcedCall !== null) {
                    $this->logger->warning('EducAI: Forcing preferred initial tool call because model returned no calls', [
                        'tool' => $forcedCall['function']['name'] ?? 'unknown',
                    ]);
                    $toolCalls = [$forcedCall];
                    $toolCallSatisfied = true;
                    array_pop($messages);
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => $toolCalls,
                    ];
                } else {
                    $initialToolChoice = null;
                }
            }

            if ((!is_array($toolCalls) || count($toolCalls) === 0) && $forceToolCall && !$toolCallSatisfied) {
                $forcedCall = $this->buildForcedToolCall($toolMap, $userQuery);
                if ($forcedCall !== null) {
                    $this->logger->warning('EducAI: Forcing tool call because model returned no calls', [
                        'tool' => $forcedCall['function']['name'] ?? 'unknown',
                    ]);
                    $toolCalls = [$forcedCall];
                    $toolCallSatisfied = true; // Prevent forcing again after this executes
                    array_pop($messages);
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => $toolCalls,
                    ];
                } else {
                    $this->logger->warning('EducAI: Unable to force tool call automatically (missing query or tools).');
                    $forceToolCall = false;
                }
            }

            if (!is_array($toolCalls) || count($toolCalls) === 0) {
                $responseContent = (string)($response['content'] ?? '');
                
                // Check if the response looks like it contains JSON tool call output that should have been parsed
                // This is a safety net - if we get here with JSON content, something went wrong
                $trimmedContent = trim($responseContent);
                if ($trimmedContent !== '' && ($trimmedContent[0] === '[' || $trimmedContent[0] === '{')) {
                    $jsonCheck = $this->extractJsonFromContent($trimmedContent);
                    if ($jsonCheck !== null && isset($jsonCheck['name'])) {
                        // This looks like a tool call that wasn't properly handled
                        // Log it and return a generic message instead of the JSON
                        $this->logger->warning('EducAI: Response contains unparsed tool call JSON, suppressing', [
                            'content_length' => strlen($trimmedContent),
                            'trace_run_id' => $traceRunId,
                        ]);
                        
                        // If we have tool results from previous iterations, try to synthesize
                        if (count($trace) > 0) {
                            $responseContent = $this->toolResultFallbackService->generateFromTrace($trace);
                        } else {
                            $responseContent = 'I encountered an issue processing your request. Please try again.';
                        }
                    }
                }

                $responseContent = $this->sanitizeAssistantTextForUser($responseContent);
                
                // CRITICAL: If response is empty on first iteration and RAG tool is available, force a RAG search
                if (trim($responseContent) === '' && $iterations === 1 && count($trace) === 0 && isset($builtInTools['rag_search_documents'])) {
                    $this->logger->warning('EducAI: Empty response on first iteration with RAG available, forcing RAG search', [
                        'query_length' => strlen((string)$userQuery),
                        'trace_run_id' => $traceRunId,
                    ]);
                    
                    // Force a RAG search using the user's query
                    $ragQuery = $userQuery ?? '';
                    if ($ragQuery !== '') {
                        $forcedRagCall = [
                            'id' => uniqid('forced_rag_', true),
                            'type' => 'function',
                            'function' => [
                                'name' => 'rag_search_documents',
                                'arguments' => json_encode(['query' => $ragQuery, 'limit' => 5], JSON_UNESCAPED_UNICODE) ?: '{}',
                            ],
                        ];
                        
                        // Execute the RAG search (bot context should already be set by BotService)
                        try {
                            $this->traceService?->recordToolCall($traceRunId, 'rag_search_documents', ['query' => $ragQuery, 'limit' => 5], $forcedRagCall['id']);
                            $ragStart = microtime(true);
                            $ragResult = $this->toolProviderRegistry->executeTool('rag_search_documents', ['query' => $ragQuery, 'limit' => 5]);
                            $ragOutput = $this->sanitizeOutput($ragResult);
                            $ragDurationMs = (int)round((microtime(true) - $ragStart) * 1000);
                            $this->traceService?->recordToolResult($traceRunId, 'rag_search_documents', 'ok', $ragOutput, $ragDurationMs);
                            
                            $trace[] = [
                                'tool' => 'rag_search_documents',
                                'status' => 'ok',
                                'duration_ms' => $ragDurationMs,
                                'response' => $ragOutput,
                            ];
                            
                            // Add the tool call and response to messages
                            array_pop($messages); // Remove empty assistant message
                            $messages[] = [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [$forcedRagCall],
                            ];
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $forcedRagCall['id'],
                                'name' => 'rag_search_documents',
                                'content' => $ragOutput,
                            ];
                            
                            // Continue the loop to let the model synthesize
                            continue;
                        } catch (\Throwable $e) {
                            $this->logger->error('EducAI: Forced RAG search failed', [
                                'exception' => $e->getMessage(),
                                'trace_run_id' => $traceRunId,
                            ]);
                            $this->traceService?->recordToolResult($traceRunId, 'rag_search_documents', 'error', null, 0, $e->getMessage());
                        }
                    }
                }
                
                // CRITICAL: If response is empty but we have tool results, force synthesis
                if (trim($responseContent) === '' && count($trace) > 0) {
                    $this->logger->warning('EducAI: Empty response after tool execution, forcing synthesis', [
                        'iterations' => $iterations,
                        'tool_invocations' => count($trace),
                    ]);
                    
                    // Force a final synthesis without tools
                    $synthesisPrompt = $this->buildToollessSynthesisPrompt($systemPrompt);
                    
                    try {
                        $synthesisOptions = [
                            'temperature' => min($resolvedTemperature, self::MAX_EMERGENCY_FALLBACK_TEMPERATURE),
                            'max_tokens' => 1500,
                            // No tools - force text response
                        ];
                        $this->recordLlmRequestTrace(
                            $traceRunId,
                            'empty_tool_synthesis',
                            $synthesisPrompt,
                            $messages,
                            $llmOptions['model'] ?? null,
                            $synthesisOptions,
                            false,
                            ['iteration' => $iterations, 'tool_invocations' => count($trace)]
                        );

                        $finalResponse = $this->llmClient->sendChatCompletion(
                            $synthesisPrompt,
                            $messages,
                            $llmOptions['model'] ?? null,
                            $synthesisOptions
                        );
                        
                        $responseContent = $this->sanitizeAssistantTextForUser($finalResponse['content'] ?? '');
                        $this->recordLlmResponseTrace($traceRunId, 'empty_tool_synthesis', $finalResponse, $responseContent, [
                            'iteration' => $iterations,
                            'tool_invocations' => count($trace),
                        ]);
                        
                        if (trim($responseContent) === '') {
                            // Still empty - use fallback
                            $responseContent = $this->toolResultFallbackService->generateFromTrace($trace);
                        }
                        
                        // Stream the final content if streaming is enabled
                        if ($onPartialResult && !empty($responseContent)) {
                            $onPartialResult($responseContent);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('EducAI: Failed to generate synthesis after empty response', [
                            'exception' => $e->getMessage(),
                        ]);
                        $responseContent = $this->toolResultFallbackService->generateFromTrace($trace);
                    }
                }
                
                // Final fallback: If response is still empty, try to answer from conversation context
                if (trim($responseContent) === '' && count($messages) > 2) {
                    $this->logger->warning('EducAI: Empty response with conversation context, forcing direct answer', [
                        'iterations' => $iterations,
                        'message_count' => count($messages),
                    ]);
                    
                    // Force the model to answer without tools
                    $directPrompt = $this->buildToollessSynthesisPrompt($systemPrompt) . "\n";
                    $directPrompt .= "Based on the conversation history, please provide a helpful answer. ";
                    $directPrompt .= "If you don't know the answer, say so - but do NOT return an empty response.\n";
                    
                    try {
                        $directOptions = [
                            'temperature' => min($resolvedTemperature, self::MAX_EMERGENCY_FALLBACK_TEMPERATURE),
                            'max_tokens' => 1000,
                            // No tools - force text response
                        ];
                        $this->recordLlmRequestTrace(
                            $traceRunId,
                            'direct_answer_fallback',
                            $directPrompt,
                            $messages,
                            $llmOptions['model'] ?? null,
                            $directOptions,
                            false,
                            ['iteration' => $iterations, 'message_count' => count($messages)]
                        );

                        $directResponse = $this->llmClient->sendChatCompletion(
                            $directPrompt,
                            $messages,
                            $llmOptions['model'] ?? null,
                            $directOptions
                        );
                        
                        $responseContent = $this->sanitizeAssistantTextForUser($directResponse['content'] ?? '');
                        $this->recordLlmResponseTrace($traceRunId, 'direct_answer_fallback', $directResponse, $responseContent, [
                            'iteration' => $iterations,
                            'message_count' => count($messages),
                        ]);
                        
                        if ($onPartialResult && !empty($responseContent)) {
                            $onPartialResult($responseContent);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('EducAI: Failed to generate direct answer', [
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
                
                return [
                    'content' => $responseContent,
                    'messages' => $messages,
                    'toolInvocations' => $trace,
                ];
            }

            // Notify user that tools are being executed (only if streaming is enabled)
            if ($onPartialResult && count($toolCalls) > 0) {
                $toolNames = array_map(fn($tc) => $tc['function']['name'] ?? 'unknown', $toolCalls);
                $toolList = implode(', ', $toolNames);
                $onPartialResult("🔧 _Using tool" . (count($toolCalls) > 1 ? "s" : "") . ": " . $toolList . "..._");
            }

            $executedToolCallsForSignature = [];
            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'] ?? null;
                $callId = $toolCall['id'] ?? '';
                if (!is_string($callId) || trim($callId) === '') {
                    $callId = uniqid('toolcall', true);
                }
                $payload = $toolCall['function']['arguments'] ?? '{}';
                $arguments = $this->decodeArguments($payload);
                $toolContext = $toolMap[$toolName] ?? null;
                $isBuiltIn = isset($builtInTools[$toolName]);
                $invokeName = $toolContext['invokeName'] ?? $toolName;
                $arguments = $this->toolArgumentNormalizer->filterToSchema($arguments, $toolContext);
                $arguments = $this->toolArgumentNormalizer->coerceToSchema($arguments, $toolContext);
                $missingArguments = $this->toolArgumentNormalizer->missingRequiredArguments($arguments, $toolContext);
                $this->traceService?->recordToolCall($traceRunId, (string)$toolName, $arguments, $callId);
                $executedToolCallsForSignature[] = [
                    'function' => [
                        'name' => $toolName,
                        'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    ],
                ];
                $start = microtime(true);
                $status = 'ok';
                $output = '';

                if ($missingArguments !== []) {
                    $status = 'error';
                    $output = $this->buildInvalidToolArgumentsOutput((string)$toolName, $missingArguments);
                    $this->logger->warning('EducAI: Tool call rejected due to invalid arguments', [
                        'tool' => $toolName,
                        'missing_required_arguments' => $missingArguments,
                        'trace_run_id' => $traceRunId,
                    ]);
                } elseif ($isBuiltIn) {
                    // Execute built-in tool
                    try {
                        $builtInConfig = is_array($toolContext['config'] ?? null) ? $toolContext['config'] : [];
                        $result = count($builtInConfig) > 0
                            ? $this->toolProviderRegistry->executeTool($toolName, $arguments, $builtInConfig)
                            : $this->toolProviderRegistry->executeTool($toolName, $arguments);
                        $output = $this->sanitizeOutput($result);
                        $this->logger->info('EducAI: Built-in tool execution result', [
                            'tool' => $toolName,
                            'trace_run_id' => $traceRunId,
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->error('Built-in tool invocation failed', [
                            'tool' => $toolName,
                            'exception' => $e,
                        ]);
                        $status = 'error';
                        $output = 'ERROR: ' . $e->getMessage();
                    }
                } elseif ($toolContext === null) {
                    $status = 'error';
                    $output = 'Tool not available';
                } else {
                    try {
                        $result = $this->mcpClient->callTool(
                            $toolContext['tool'],
                            $invokeName,
                            $arguments,
                            $toolContext['config']
                        );
                        $output = $this->sanitizeOutput($result);
                        $this->logger->info('EducAI: Tool execution result', [
                            'tool' => $toolName,
                            'trace_run_id' => $traceRunId,
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->error('Tool invocation failed', [
                            'tool' => $toolName,
                            'exception' => $e,
                        ]);
                        $status = 'error';
                        $output = 'ERROR: ' . $e->getMessage();
                    }
                }

                $durationMs = (int)round((microtime(true) - $start) * 1000);
                $this->traceService?->recordToolResult($traceRunId, (string)$toolName, $status, $output, $durationMs, $status === 'error' ? $output : null);

                $this->logger->info('EducAI: Tool execution completed', [
                    'tool' => $toolName,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                    'trace_run_id' => $traceRunId,
                    'is_builtin' => $isBuiltIn,
                ]);

                $trace[] = [
                    'tool' => $toolName,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                    'response' => $output,
                ];

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'name' => $toolName,
                    'content' => $output,
                ];
            }

            $currentCallSignature = $this->buildToolCallBatchSignature($executedToolCallsForSignature);
            if ($currentCallSignature !== '') {
                $recentToolCalls[] = $currentCallSignature;
                if (count($recentToolCalls) > $maxIterations) {
                    $recentToolCalls = array_slice($recentToolCalls, -$maxIterations);
                }

                $repetitionCount = $this->countTrailingMatchingToolSignatures($recentToolCalls);
                $loopThreshold = $this->toolExecutionPolicyService->loopThresholdForToolCalls($toolCalls, $toolMap);
                if ($repetitionCount >= $loopThreshold) {
                    $this->logger->warning('EducAI: Detected repetitive tool calling loop, forcing synthesis', [
                        'pattern' => implode(',', array_map(static fn($tc) => $tc['function']['name'] ?? 'unknown', $toolCalls)),
                        'iterations' => $iterations,
                        'repetitions' => $repetitionCount,
                        'threshold' => $loopThreshold,
                        'signature_length' => strlen($currentCallSignature),
                        'trace_run_id' => $traceRunId,
                    ]);

                    return $this->synthesizeFinalAnswer(
                        $systemPrompt,
                        $messages,
                        $llmOptions['model'] ?? null,
                        $trace,
                        $onPartialResult,
                        $resolvedTemperature,
                        $iterations,
                        $maxIterations,
                        false,
                        $traceRunId
                    );
                }
            }

            if ($iterations >= $maxIterations) {
                return $this->synthesizeFinalAnswer(
                    $systemPrompt,
                    $messages,
                    $llmOptions['model'] ?? null,
                    $trace,
                    $onPartialResult,
                    $resolvedTemperature,
                    $iterations,
                    $maxIterations,
                    true,
                    $traceRunId
                );
            }
        }
    }

    /**
     * Build a stable tool batch signature for loop detection.
     *
     * The signature includes normalized arguments so repeated searches with
     * different queries do not count as the same loop pattern.
     *
     * @param array<int,LlmToolCall> $toolCalls
     */
    private function buildToolCallBatchSignature(array $toolCalls): string {
        $parts = [];
        foreach ($toolCalls as $toolCall) {
            $name = $toolCall['function']['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            $payload = $toolCall['function']['arguments'] ?? '{}';
            $arguments = $this->decodeArguments($payload);
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
        array $metadata = []
    ): void {
        if ($this->traceService === null) {
            return;
        }

        $tracePayload = $this->buildTraceChatCompletionPayload($systemPrompt, $messages, $model, $options, $streaming);
        $payload = array_merge([
            'stage' => $stage,
            'model' => $model,
            'streaming' => $streaming,
            'message_count' => count($messages),
            'tool_count' => isset($options['tools']) && is_array($options['tools']) ? count($options['tools']) : 0,
            'tool_choice' => $options['tool_choice'] ?? null,
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
            'request_endpoint' => $tracePayload['endpoint'] ?? null,
            'request_model_reference' => $tracePayload['model_reference'] ?? null,
            'request_payload' => $tracePayload['payload'] ?? $tracePayload,
        ], $metadata);

        if (isset($tracePayload['trace_payload_error'])) {
            $payload['trace_payload_error'] = $tracePayload['trace_payload_error'];
        }

        $this->traceService->recordEvent($traceRunId, 'llm_request', [
            'payload' => $payload,
        ]);
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $metadata
     */
    private function recordLlmResponseTrace(?int $traceRunId, string $stage, array $response, string $content, array $metadata = []): void {
        $this->traceService?->recordEvent($traceRunId, 'llm_response', [
            'status' => 'ok',
            'payload' => array_merge([
                'stage' => $stage,
                'model' => $response['model'] ?? null,
                'model_reference' => $response['model_reference'] ?? null,
                'model_endpoint' => $response['model_endpoint'] ?? null,
                'usage' => $response['usage'] ?? null,
                'finish_reason' => $response['finish_reason'] ?? null,
                'content_length' => strlen($content),
                'native_tool_calls' => count($response['tool_calls'] ?? []),
                'raw_response' => $response['raw'] ?? null,
            ], $metadata),
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
                'trace_payload_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<int,McpToolLoadoutEntry> $toolLoadout
     * @param int|null $botId Bot ID to filter built-in tools (null = no built-in tools)
     * @param bool $ragEnabled Whether to include the RAG search tool for this bot
     * @param array<int,BuiltInToolLoadoutEntry> $explicitBuiltInTools
     * @return ToolDefinitionBuildResult
     */
    private function buildToolDefinitions(array $toolLoadout, ?int $botId = null, bool $ragEnabled = false, array $explicitBuiltInTools = []): array {
        $definitions = [];
        $map = [];
        $builtIn = [];

        // Add built-in tools - either assigned to this bot OR the RAG tool when RAG is enabled
        $assignedBuiltInNames = [];
        $assignedBuiltInConfigs = [];
        if (count($explicitBuiltInTools) > 0) {
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
            
            // Include tool if: it's assigned to the bot OR it's the RAG tool and RAG is enabled
            $isRagTool = $name === 'rag_search_documents';
            $isAssigned = in_array($name, $assignedBuiltInNames, true);
            
            if (!$isAssigned && !($isRagTool && $ragEnabled)) {
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
                $descriptors = $this->mcpClient->listTools($tool);
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

    /**
     * @return array<string,mixed>|null
     */
    private function tryParseJson(string $content): ?array {
        $content = trim($content);
        $firstChar = $content[0] ?? '';
        if ($firstChar !== '{' && $firstChar !== '[') {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Extract JSON from content that might be wrapped in markdown code blocks or have surrounding text
     * 
     * @return array<string,mixed>|null
     */
    private function extractJsonFromContent(string $content): ?array {
        $content = trim($content);
        
        // First try direct parsing
        $direct = $this->tryParseJson($content);
        if ($direct !== null) {
            return $direct;
        }
        
        // Try to extract from markdown code blocks (```json ... ``` or ``` ... ```)
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $jsonStr = trim($matches[1]);
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        
        // Try to find JSON object or array in the content
        // Look for patterns like [{ or {"
        if (preg_match('/(\[\s*\{[\s\S]*\}\s*\]|\{[\s\S]*\})/', $content, $matches)) {
            $jsonStr = trim($matches[1]);
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        
        return null;
    }

    /**
     * Extract XML-formatted tool calls from the content string.
     *
     * Supports:
     * - <tool_call>{"name":"...", "arguments":{...}}</tool_call>
     * - <tool_call><name>...</name><arguments>{"query":"..."}</arguments></tool_call>
     * - <minimax:tool_call><invoke name="..."><parameter name="...">...</parameter></invoke></minimax:tool_call>
     * - <function_call>...</function_call>
     *
     * @param array<string,mixed> $toolMap
     * @return array{calls:array<int,array{name:string,arguments:array<string,mixed>}>,remainingContent:string}|null
     */
    private function extractXmlToolCalls(string $content, array $toolMap): ?array {
        $pattern = '/<((?:[a-z0-9_-]+:)?(?:tool_call|function_call))\b[^>]*>(.*?)<\/\1>/is';
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $calls = [];
        foreach ($matches as $match) {
            $innerContent = trim($match[2] ?? '');
            if ($innerContent === '') {
                continue;
            }

            $call = null;
            $jsonDecoded = json_decode($innerContent, true);
            if (is_array($jsonDecoded)) {
                $call = $this->buildFallbackCall($jsonDecoded, $toolMap);
            }

            if ($call === null) {
                $invokeCalls = $this->extractInvokeXmlToolCalls($innerContent, $toolMap);
                if (count($invokeCalls) > 0) {
                    foreach ($invokeCalls as $invokeCall) {
                        $calls[] = $invokeCall;
                    }
                    continue;
                }
            }

            if ($call === null && preg_match('/<name>(.*?)<\/name>/is', $innerContent, $nameMatch)) {
                $toolName = trim($nameMatch[1]);
                $arguments = [];
                $rawArguments = null;

                if (preg_match('/<arguments>(.*?)<\/arguments>/is', $innerContent, $argumentsMatch)) {
                    $rawArguments = trim($argumentsMatch[1]);
                } elseif (preg_match('/<parameters>(.*?)<\/parameters>/is', $innerContent, $parametersMatch)) {
                    $rawArguments = trim($parametersMatch[1]);
                }

                if (is_string($rawArguments) && $rawArguments !== '') {
                    $decodedArguments = json_decode($rawArguments, true);
                    if (is_array($decodedArguments)) {
                        $arguments = $decodedArguments;
                    }
                }

                if (isset($toolMap[$toolName])) {
                    $call = [
                        'name' => $toolName,
                        'arguments' => $arguments,
                    ];
                }
            }

            if ($call === null) {
                $call = $this->extractLegacyXmlArgumentPairs($innerContent, $toolMap);
            }

            if ($call !== null) {
                $calls[] = $call;
            }
        }

        if (count($calls) === 0) {
            return null;
        }

        $remainingContent = preg_replace($pattern, '', $content);
        return [
            'calls' => $calls,
            'remainingContent' => trim($remainingContent ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $toolMap
     * @return array<int,array{name:string,arguments:array<string,mixed>}>
     */
    private function extractInvokeXmlToolCalls(string $innerContent, array $toolMap): array {
        if (!preg_match_all(
            '/<invoke\s+name=(["\'])(.*?)\1\s*>(.*?)<\/invoke>/is',
            $innerContent,
            $invokeMatches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $calls = [];
        foreach ($invokeMatches as $invokeMatch) {
            $toolName = html_entity_decode(trim($invokeMatch[2] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($toolName === '' || !isset($toolMap[$toolName])) {
                continue;
            }

            $arguments = [];
            $argumentSection = $invokeMatch[3] ?? '';
            if (preg_match_all(
                '/<parameter\s+name=(["\'])(.*?)\1\s*>(.*?)<\/parameter>/is',
                $argumentSection,
                $parameterMatches,
                PREG_SET_ORDER
            )) {
                foreach ($parameterMatches as $parameterMatch) {
                    $key = html_entity_decode(trim($parameterMatch[2] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    if ($key === '') {
                        continue;
                    }
                    $rawValue = html_entity_decode(trim($parameterMatch[3] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $arguments[$key] = $this->coerceLegacyXmlArgumentValue(
                        $rawValue,
                        $toolMap[$toolName]['definition']['function']['parameters'] ?? null,
                        $key
                    );
                }
            }

            $calls[] = [
                'name' => $toolName,
                'arguments' => $arguments,
            ];
        }

        return $calls;
    }

    /**
     * Remove XML-style tool call artifacts from streamed content before they can be emitted to Talk.
     */
    private function filterStreamingToolCallContent(
        string $partial,
        string &$artifactBuffer,
        bool &$isInsideXmlToolCall,
        bool $finalize = false
    ): string {
        $artifactBuffer .= $partial;
        if ($artifactBuffer === '') {
            return '';
        }

        $visible = '';

        while ($artifactBuffer !== '') {
            if ($isInsideXmlToolCall) {
                $closingMatch = $this->findFirstXmlTagOccurrence($artifactBuffer, ['</tool_call>', '</function_call>', '</minimax:tool_call>']);
                if ($closingMatch !== null) {
                    $artifactBuffer = substr(
                        $artifactBuffer,
                        $closingMatch['position'] + strlen($closingMatch['tag'])
                    );
                    $isInsideXmlToolCall = false;
                    continue;
                }

                $artifactBuffer = $finalize
                    ? ''
                    : $this->extractTrailingPartialXmlTag($artifactBuffer, ['</tool_call>', '</function_call>', '</minimax:tool_call>']);
                break;
            }

            $openingMatch = $this->findFirstXmlTagOccurrence($artifactBuffer, ['<tool_call>', '<function_call>', '<minimax:tool_call>']);
            if ($openingMatch !== null) {
                $visible .= substr($artifactBuffer, 0, $openingMatch['position']);
                $artifactBuffer = substr(
                    $artifactBuffer,
                    $openingMatch['position'] + strlen($openingMatch['tag'])
                );
                $isInsideXmlToolCall = true;
                continue;
            }

            $partialOpeningTag = $finalize
                ? ''
                : $this->extractTrailingPartialXmlTag($artifactBuffer, ['<tool_call>', '<function_call>', '<minimax:tool_call>']);
            if ($partialOpeningTag !== '') {
                $visibleLength = strlen($artifactBuffer) - strlen($partialOpeningTag);
                if ($visibleLength > 0) {
                    $visible .= substr($artifactBuffer, 0, $visibleLength);
                }
                $artifactBuffer = $partialOpeningTag;
                break;
            }

            $visible .= $artifactBuffer;
            $artifactBuffer = '';
        }

        return $visible;
    }

    /**
     * @param array<int,string> $tags
     * @return array{tag:string,position:int}|null
     */
    private function findFirstXmlTagOccurrence(string $content, array $tags): ?array {
        $firstMatch = null;
        foreach ($tags as $tag) {
            $position = stripos($content, $tag);
            if ($position === false) {
                continue;
            }
            if ($firstMatch === null || $position < $firstMatch['position']) {
                $firstMatch = [
                    'tag' => $tag,
                    'position' => $position,
                ];
            }
        }

        return $firstMatch;
    }

    /**
     * Preserve only the trailing suffix that could complete an XML tag in the next streamed chunk.
     *
     * @param array<int,string> $tags
     */
    private function extractTrailingPartialXmlTag(string $content, array $tags): string {
        $contentLower = strtolower($content);
        $bestMatch = '';

        foreach ($tags as $tag) {
            $tagLower = strtolower($tag);
            $maxPrefixLength = min(strlen($contentLower), strlen($tagLower) - 1);
            for ($prefixLength = $maxPrefixLength; $prefixLength >= 1; $prefixLength--) {
                $prefix = substr($tagLower, 0, $prefixLength);
                if (str_ends_with($contentLower, $prefix) && $prefixLength > strlen($bestMatch)) {
                    $bestMatch = substr($content, -$prefixLength);
                    break;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Build a final text-only answer from the tool trace.
     *
     * @param array<int,array<string,mixed>> $messages
     * @param array<int,array<string,mixed>> $trace
     * @return array{content:string,messages:array<int,array<string,mixed>>,toolInvocations:array<int,array<string,mixed>>}
     */
    private function synthesizeFinalAnswer(
        string $systemPrompt,
        array $messages,
        ?string $model,
        array $trace,
        ?callable $onPartialResult,
        float $temperature,
        int $iterations,
        int $maxIterations,
        bool $logLimitReached,
        ?int $traceRunId = null
    ): array {
        if ($logLimitReached) {
            $this->logger->warning('EducAI: Tool interaction limit reached, generating final synthesis', [
                'iterations' => $iterations,
                'max_iterations' => $maxIterations,
                'tool_invocations' => count($trace),
                'last_tools' => array_map(static fn($t) => $t['tool'] ?? 'unknown', array_slice($trace, -3)),
            ]);
        }

        $synthesisPrompt = $this->buildToollessSynthesisPrompt($systemPrompt);

        try {
            $synthesisOptions = [
                'temperature' => $temperature,
                'max_tokens' => 1500,
            ];
            $this->recordLlmRequestTrace(
                $traceRunId,
                'final_synthesis',
                $synthesisPrompt,
                $messages,
                $model,
                $synthesisOptions,
                false,
                [
                    'iterations' => $iterations,
                    'max_iterations' => $maxIterations,
                    'tool_invocations' => count($trace),
                    'limit_reached' => $logLimitReached,
                ]
            );

            $finalResponse = $this->llmClient->sendChatCompletion(
                $synthesisPrompt,
                $messages,
                $model,
                $synthesisOptions
            );

            $finalContent = $this->sanitizeAssistantTextForUser($finalResponse['content'] ?? '');
            $this->recordLlmResponseTrace($traceRunId, 'final_synthesis', $finalResponse, $finalContent, [
                'iterations' => $iterations,
                'max_iterations' => $maxIterations,
                'tool_invocations' => count($trace),
                'limit_reached' => $logLimitReached,
            ]);
            if (trim($finalContent) === '') {
                $finalContent = $this->toolResultFallbackService->generateFromTrace($trace);
            }

            if ($onPartialResult && $finalContent !== '') {
                $onPartialResult($finalContent);
            }

            return [
                'content' => $finalContent,
                'messages' => $messages,
                'toolInvocations' => $trace,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('EducAI: Failed to generate final synthesis', [
                'exception' => $e->getMessage(),
            ]);

            $fallback = $this->toolResultFallbackService->generateFromTrace($trace);
            if ($onPartialResult && $fallback !== '') {
                $onPartialResult($fallback);
            }

            $this->traceService?->recordEvent($traceRunId, 'error', [
                'status' => 'error',
                'payload' => [
                    'stage' => 'final_synthesis',
                    'iterations' => $iterations,
                    'tool_invocations' => count($trace),
                ],
                'error_message' => $e->getMessage(),
            ]);
            return [
                'content' => $fallback,
                'messages' => $messages,
                'toolInvocations' => $trace,
            ];
        }
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

    private function buildToollessSynthesisPrompt(string $systemPrompt): string {
        $prompt = preg_replace('/\n\n## Tool Calling Instructions[\s\S]*$/', '', $systemPrompt) ?? $systemPrompt;
        $prompt = preg_replace('/\n\n## CRITICAL: Document Search Instructions[\s\S]*$/', '', $prompt) ?? $prompt;

        $prompt .= "\n\n## IMPORTANT: Final Response Required\n";
        $prompt .= "You have already received tool results in the conversation.\n";
        $prompt .= "Write a final answer for the user now.\n";
        $prompt .= "Do NOT call tools again.\n";
        $prompt .= "Do NOT output JSON, XML, <tool_call>, <function_call>, or any other structured tool markup.\n";
        $prompt .= "Summarize the relevant information from the tool results and answer in the user's language.\n";

        return $prompt;
    }

    private function sanitizeAssistantTextForUser(string $content): string {
        $cleaned = preg_replace('/<think>.*?<\/think>/is', '', $content);
        $cleaned = preg_replace('/<think>.*$/is', '', $cleaned ?? $content);
        $cleaned = str_ireplace(['<think>', '</think>'], '', $cleaned ?? $content);
        $cleaned = preg_replace('/<((?:[a-z0-9_-]+:)?(?:tool_call|function_call))\b[^>]*>.*?<\/\1>/is', '', $cleaned ?? $content);
        $cleaned = preg_replace('/<(?:[a-z0-9_-]+:)?(?:tool_call|function_call)\b[^>]*>.*$/is', '', $cleaned ?? $content);
        $cleaned = preg_replace('/<\/(?:[a-z0-9_-]+:)?(?:tool_call|function_call)>/i', '', $cleaned ?? $content);
        $cleaned = preg_replace('/[ \t]{2,}/', ' ', $cleaned ?? $content);
        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned ?? $content);

        $trimmed = trim($cleaned ?? $content);
        $deduplicated = preg_replace('/^(.{20,}?)\s*\1$/us', '$1', $trimmed);

        return trim($deduplicated ?? $trimmed);
    }

    /**
     * @param array<string,mixed> $toolMap
     * @return array{name:string,arguments:array<string,mixed>}|null
     */
    private function extractLegacyXmlArgumentPairs(string $innerContent, array $toolMap): ?array {
        if (!preg_match('/^\s*([^\s<]+)\s*(.*)$/s', $innerContent, $matches)) {
            return null;
        }

        $toolName = trim($matches[1] ?? '');
        if ($toolName === '' || !isset($toolMap[$toolName])) {
            return null;
        }

        $argumentSection = $matches[2] ?? '';
        $arguments = [];
        if (preg_match_all(
            '/<arg_key>(.*?)<\/arg_key>\s*<arg_value>(.*?)<\/arg_value>/is',
            $argumentSection,
            $argMatches,
            PREG_SET_ORDER
        )) {
            foreach ($argMatches as $argMatch) {
                $key = trim($argMatch[1] ?? '');
                if ($key === '') {
                    continue;
                }
                $rawValue = trim($argMatch[2] ?? '');
                $arguments[$key] = $this->coerceLegacyXmlArgumentValue(
                    $rawValue,
                    $toolMap[$toolName]['definition']['function']['parameters'] ?? null,
                    $key
                );
            }
        }

        return [
            'name' => $toolName,
            'arguments' => $arguments,
        ];
    }

    private function coerceLegacyXmlArgumentValue(string $rawValue, array|\stdClass|null $schema, string $key): array|bool|float|int|string {
        $trimmed = trim($rawValue);
        if ($trimmed === '') {
            return '';
        }

        $decodedJson = json_decode($trimmed, true);
        if (is_array($decodedJson)) {
            return $decodedJson;
        }

        if ($schema instanceof \stdClass) {
            $schema = (array)$schema;
        }
        $properties = is_array($schema) ? ($schema['properties'] ?? []) : [];
        if ($properties instanceof \stdClass) {
            $properties = (array)$properties;
        }
        $propertySchema = is_array($properties) ? ($properties[$key] ?? null) : null;
        if ($propertySchema instanceof \stdClass) {
            $propertySchema = (array)$propertySchema;
        }

        $type = is_array($propertySchema) ? ($propertySchema['type'] ?? null) : null;
        if ($type === 'integer' && is_numeric($trimmed)) {
            return (int)$trimmed;
        }
        if ($type === 'number' && is_numeric($trimmed)) {
            return (float)$trimmed;
        }
        if ($type === 'boolean') {
            $lower = strtolower($trimmed);
            if (in_array($lower, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        return $trimmed;
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $toolMap
     */
    private function findMatchingTool(array $args, array $toolMap): ?string {
        // If there's only one tool, and the args look reasonable, assume it's that one.
        if (count($toolMap) === 1) {
            return array_key_first($toolMap);
        }
        
        $bestMatch = null;
        $maxOverlap = -1;
        $minExtra = PHP_INT_MAX;

        foreach ($toolMap as $name => $data) {
            $schema = $data['definition']['function']['parameters'] ?? [];
            if ($schema instanceof \stdClass) {
                $schema = (array)$schema;
            }
            $properties = $schema['properties'] ?? [];
            if ($properties instanceof \stdClass) {
                $properties = (array)$properties;
            }
            
            $toolParams = array_keys($properties);
            $argKeys = array_keys($args);
            
            // Calculate overlap: count of keys in args that exist in tool params
            $overlap = count(array_intersect($argKeys, $toolParams));
            
            // Calculate extra: count of keys in args that do NOT exist in tool params
            $extra = count(array_diff($argKeys, $toolParams));
            
            // If args are empty, overlap is 0. We prioritize tools that have 0 extra params.
            // But generally we want to maximize overlap.
            
            if ($overlap > $maxOverlap) {
                $maxOverlap = $overlap;
                $minExtra = $extra;
                $bestMatch = $name;
            } elseif ($overlap === $maxOverlap) {
                // Tie-breaker: prefer fewer extra params
                if ($extra < $minExtra) {
                    $minExtra = $extra;
                    $bestMatch = $name;
                }
            }
        }
        
        // Only return a match if there is at least some overlap, or if args is empty and we found something
        if ($maxOverlap > 0 || (empty($args) && $bestMatch !== null)) {
            return $bestMatch;
        }
        
        return null;
    }

    /**
     * @param array<string,ToolMapEntry> $toolMap
     */
    private function buildForcedToolCall(array $toolMap, ?string $userQuery): ?array {
        if ($userQuery === null || trim($userQuery) === '' || count($toolMap) === 0) {
            return null;
        }

        $toolName = $this->pickToolForForcedCall($toolMap);
        if ($toolName === null) {
            return null;
        }

        $toolContext = $toolMap[$toolName] ?? null;
        if ($toolContext === null) {
            return null;
        }

        $arguments = $this->buildHarnessToolArguments($toolName, $toolContext, $userQuery);
        $encodedArgs = json_encode($arguments, JSON_UNESCAPED_UNICODE);
        if ($encodedArgs === false) {
            $encodedArgs = '{}';
        }

        return [
            'id' => uniqid('forced_', true),
            'type' => 'function',
            'function' => [
                'name' => $toolName,
                'arguments' => $encodedArgs,
            ],
        ];
    }

    /**
     * @param array<string,mixed>|string $toolChoice
     * @param array<string,ToolMapEntry> $toolMap
     */
    private function buildPreferredToolCall(array|string $toolChoice, array $toolMap, ?string $userQuery): ?array {
        $toolName = $this->extractSpecificToolNameFromChoice($toolChoice);
        if ($toolName === null) {
            return null;
        }

        $toolContext = $toolMap[$toolName] ?? null;
        if ($toolContext === null) {
            return null;
        }

        $arguments = $this->buildHarnessToolArguments($toolName, $toolContext, $userQuery);
        $encodedArgs = json_encode($arguments, JSON_UNESCAPED_UNICODE);
        if ($encodedArgs === false) {
            $encodedArgs = '{}';
        }

        return [
            'id' => uniqid('preferred_', true),
            'type' => 'function',
            'function' => [
                'name' => $toolName,
                'arguments' => $encodedArgs,
            ],
        ];
    }

    private function extractSpecificToolNameFromChoice(array|string $toolChoice): ?string {
        if (is_string($toolChoice)) {
            $normalized = trim($toolChoice);
            if ($normalized === '' || $normalized === 'auto' || $normalized === 'required') {
                return null;
            }

            return $normalized;
        }

        if (!is_array($toolChoice)) {
            return null;
        }

        $type = $toolChoice['type'] ?? null;
        $name = $toolChoice['function']['name'] ?? null;
        if ($type !== 'function' || !is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }

    /**
     * @param ToolMapEntry $toolContext
     * @return array<string,mixed>
     */
    private function buildHarnessToolArguments(string $toolName, array $toolContext, ?string $userQuery): array {
        $arguments = [];
        $schema = $toolContext['definition']['function']['parameters'] ?? [];
        if ($schema instanceof \stdClass) {
            $schema = (array)$schema;
        }
        $properties = is_array($schema) ? ($schema['properties'] ?? []) : [];
        if ($properties instanceof \stdClass) {
            $properties = (array)$properties;
        }

        if (is_array($properties) && array_key_exists('query', $properties) && $userQuery !== null && trim($userQuery) !== '') {
            $arguments['query'] = $this->buildForcedQuery($userQuery);
        }

        return $this->toolArgumentNormalizer->coerceToSchema($arguments, $toolContext);
    }

    private function buildForcedQuery(string $userQuery): string {
        $query = trim($userQuery);
        $query = preg_replace('/^@[a-z0-9_-]+\s+/i', '', $query) ?? $query;
        return trim($query) === '' ? $userQuery : trim($query);
    }

    /**
     * @param array<string,ToolMapEntry> $toolMap
     */
    private function pickToolForForcedCall(array $toolMap): ?string {
        $bestName = null;
        $bestScore = 0;
        foreach ($toolMap as $name => $context) {
            if (($context['tool'] ?? null) === null) {
                continue;
            }

            $score = $this->toolExecutionPolicyService->scoreForcedSearchToolCandidate($name, $context);
            if ($score > $bestScore) {
                $bestName = $name;
                $bestScore = $score;
            }
        }

        // No safe, search-like candidate found: skip forced call entirely.
        return $bestName;
    }

    /**
     * Normalize tool calls from model response and ensure each call has a usable id/name/arguments shape.
     *
     * @param array<string,mixed> $response
     * @return array<int,LlmToolCall>
     */
    private function extractToolCallsFromResponse(array $response): array {
        $toolCalls = $response['tool_calls'] ?? [];
        if (!is_array($toolCalls) || count($toolCalls) === 0) {
            $toolCalls = $response['raw']['choices'][0]['message']['tool_calls'] ?? [];
        }
        if (!is_array($toolCalls)) {
            return [];
        }

        $normalized = [];
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }
            $name = $toolCall['function']['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $id = $toolCall['id'] ?? '';
            if (!is_string($id) || trim($id) === '') {
                $id = uniqid('call_', true);
            }
            $arguments = $this->normalizeToolCallArguments($toolCall['function']['arguments'] ?? '{}');

            $normalized[] = [
                'id' => $id,
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => $arguments,
                ],
            ];
        }

        return $normalized;
    }

    private function normalizeToolCallArguments(mixed $arguments): string {
        if (!is_string($arguments)) {
            $encoded = json_encode($arguments, JSON_UNESCAPED_UNICODE);
            return $encoded === false ? '{}' : $encoded;
        }

        $trimmed = trim($arguments);
        if ($trimmed === '') {
            return '{}';
        }

        json_decode($trimmed, true);
        return json_last_error() === JSON_ERROR_NONE ? $trimmed : '{}';
    }

    /**
     * @param list<string> $missingArguments
     */
    private function buildInvalidToolArgumentsOutput(string $toolName, array $missingArguments): string {
        $tool = trim($toolName) !== '' ? $toolName : 'unknown';
        return 'ERROR: Invalid tool arguments for ' . $tool
            . ': missing required argument(s): ' . implode(', ', $missingArguments)
            . '. Please call the tool again with valid schema arguments.';
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
     * @param array|bool|float|int|string|null $decoded
     * @param array<string,mixed> $toolMap
     * @return array<int,array{name:string,arguments:array<string,mixed>}>|null
     */
    private function normalizeFallbackToolCalls(array|bool|float|int|string|null $decoded, array $toolMap): ?array {
        if (!is_array($decoded)) {
            return null;
        }

        $entries = array_is_list($decoded) ? $decoded : [$decoded];
        $calls = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $call = $this->buildFallbackCall($entry, $toolMap);
            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return count($calls) > 0 ? $calls : null;
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $toolMap
     * @return array{name:string,arguments:array<string,mixed>}|null
     */
    private function buildFallbackCall(array $entry, array $toolMap): ?array {
        $name = null;
        if (isset($entry['name']) && is_string($entry['name'])) {
            $name = $entry['name'];
        } elseif (isset($entry['tool']) && is_string($entry['tool'])) {
            $name = $entry['tool'];
        } elseif (isset($entry['function']['name']) && is_string($entry['function']['name'])) {
            $name = $entry['function']['name'];
        }

        if ($name !== null && !isset($toolMap[$name])) {
            $name = null; // only accept known tools
        }

        if ($name === null) {
            $name = $this->findMatchingTool($entry, $toolMap);
        }

        if ($name === null || !isset($toolMap[$name])) {
            return null;
        }

        $arguments = null;
        if (array_key_exists('parameters', $entry)) {
            $arguments = $this->decodeFallbackArgumentsValue($entry['parameters']);
        }
        if ($arguments === null && array_key_exists('arguments', $entry)) {
            $arguments = $this->decodeFallbackArgumentsValue($entry['arguments']);
        }
        if ($arguments === null && isset($entry['function']) && is_array($entry['function']) && array_key_exists('arguments', $entry['function'])) {
            $arguments = $this->decodeFallbackArgumentsValue($entry['function']['arguments']);
        }
        if ($arguments === null) {
            $arguments = $this->stripMetaKeys($entry);
        }

        return [
            'name' => $name,
            'arguments' => $arguments,
        ];
    }

    /**
     * Decode fallback tool arguments from either array or JSON string representation.
     *
     * @param array<string,mixed>|bool|float|int|string|null $value
     * @return array<string,mixed>|null
     */
    private function decodeFallbackArgumentsValue(array|bool|float|int|string|null $value): ?array {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function stripMetaKeys(array $entry): array {
        $result = $entry;
        unset(
            $result['name'],
            $result['tool'],
            $result['function'],
            $result['parameters'],
            $result['arguments'],
            $result['id'],
            $result['type']
        );
        return $result;
    }

    /**
     * Find a good position to split text at a sentence boundary
     * 
     * @param string $text The text to analyze
     * @return int Position to split at, or 0 if no good boundary found
     */
    private function findSentenceBoundary(string $text): int {
        // Look for sentence endings: . ! ? followed by space or end
        $patterns = ['. ', '! ', '? ', ".\n", "!\n", "?\n"];
        $lastPos = 0;
        
        foreach ($patterns as $pattern) {
            $pos = strrpos($text, $pattern);
            if ($pos !== false && $pos > $lastPos) {
                $lastPos = $pos + strlen($pattern);
            }
        }
        
        // Also check for end of text with sentence ender
        $trimmed = rtrim($text);
        $lastChar = substr($trimmed, -1);
        if (in_array($lastChar, ['.', '!', '?'], true) && strlen($trimmed) === strlen($text)) {
            return strlen($text);
        }
        
        // If no sentence boundary, try to split at newline
        if ($lastPos === 0) {
            $newlinePos = strrpos($text, "\n");
            if ($newlinePos !== false && $newlinePos > 30) {
                return $newlinePos + 1;
            }
        }
        
        return $lastPos;
    }

}
