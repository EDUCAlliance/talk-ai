<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Db\RoomDocumentEmbedding;
use OCA\EducAI\Db\RoomDocumentEmbeddingMapper;
use OCA\EducAI\Db\RoomDocumentSourceMapper;
use OCA\EducAI\Db\RoomImageEmbedding;
use OCA\EducAI\Db\RoomImageEmbeddingMapper;
use OCA\EducAI\Db\RoomImageSourceMapper;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use Psr\Log\LoggerInterface;

/**
 * Provides built-in tools that don't require external MCP servers.
 *
 * These tools are implemented directly in PHP and can be enabled/configured
 * via the admin settings. They appear alongside MCP tools in the agent executor.
 *
 * @psalm-import-type InvocationContext from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type InvocationContextInput from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type ToolDefinition from \OCA\EducAI\TypeDefinitions
 */
class BuiltInToolProvider {
    // RAG tools - require bot context to execute
    public const TOOL_RAG_SEARCH = 'rag_search_documents';
    public const TOOL_ROOM_SEARCH = 'room_search_documents';
    public const TOOL_ROOM_IMAGE_SEARCH = 'room_search_images';
    public const TOOL_ATTACHMENT_IMAGE = 'attachment_analyze_image';
    public const TOOL_ATTACHMENT_AUDIO = 'attachment_transcribe_audio';
    public const TOOL_WIKI_SEARCH = 'wiki_search';
    public const TOOL_WIKI_READ_PAGE = 'wiki_read_page';
    public const TOOL_WIKI_WRITE_PAGE = 'wiki_write_page';
    public const TOOL_WIKI_LOG_EVENT = 'wiki_log_event';
    public const WIKI_TOOLS = [
        self::TOOL_WIKI_SEARCH,
        self::TOOL_WIKI_READ_PAGE,
        self::TOOL_WIKI_WRITE_PAGE,
        self::TOOL_WIKI_LOG_EVENT,
    ];

    private SettingsService $settingsService;
    private EmbeddingMapper $embeddingMapper;
    private EmbeddingClient $embeddingClient;
    private RoomDocumentEmbeddingMapper $roomDocumentEmbeddingMapper;
    private RoomDocumentSourceMapper $roomDocumentSourceMapper;
    private RoomImageEmbeddingMapper $roomImageEmbeddingMapper;
    private RoomImageSourceMapper $roomImageSourceMapper;
    private AttachmentResolver $attachmentResolver;
    private VisionClient $visionClient;
    private SpeechToTextClient $speechToTextClient;
    private WikiService $wikiService;
    private ToolExecutionPolicyService $toolExecutionPolicyService;
    private LoggerInterface $logger;
    /** @var InvocationContext */
    private array $currentInvocationContext = [
        'bot_id' => null,
        'room_token' => null,
        'attachments' => [],
        'document_source_ids' => [],
        'image_source_ids' => [],
    ];

    public function __construct(
        SettingsService $settingsService,
        EmbeddingMapper $embeddingMapper,
        EmbeddingClient $embeddingClient,
        RoomDocumentEmbeddingMapper $roomDocumentEmbeddingMapper,
        RoomDocumentSourceMapper $roomDocumentSourceMapper,
        RoomImageEmbeddingMapper $roomImageEmbeddingMapper,
        RoomImageSourceMapper $roomImageSourceMapper,
        AttachmentResolver $attachmentResolver,
        VisionClient $visionClient,
        SpeechToTextClient $speechToTextClient,
        WikiService $wikiService,
        LoggerInterface $logger,
        ?ToolExecutionPolicyService $toolExecutionPolicyService = null
    ) {
        $this->settingsService = $settingsService;
        $this->embeddingMapper = $embeddingMapper;
        $this->embeddingClient = $embeddingClient;
        $this->roomDocumentEmbeddingMapper = $roomDocumentEmbeddingMapper;
        $this->roomDocumentSourceMapper = $roomDocumentSourceMapper;
        $this->roomImageEmbeddingMapper = $roomImageEmbeddingMapper;
        $this->roomImageSourceMapper = $roomImageSourceMapper;
        $this->attachmentResolver = $attachmentResolver;
        $this->visionClient = $visionClient;
        $this->speechToTextClient = $speechToTextClient;
        $this->wikiService = $wikiService;
        $this->toolExecutionPolicyService = $toolExecutionPolicyService ?? new ToolExecutionPolicyService();
        $this->logger = $logger;
    }

    /**
     * Set the current bot context for RAG tool execution
     */
    public function setBotContext(?int $botId): void {
        if ($botId === null) {
            $this->setInvocationContext(null);
            return;
        }

        $this->currentInvocationContext['bot_id'] = $botId;
    }

    /**
     * Get the current bot context
     */
    public function getBotContext(): ?int {
        return $this->currentInvocationContext['bot_id'];
    }

    /**
     * @param InvocationContextInput|null $context
     */
    public function setInvocationContext(?array $context): void {
        if ($context === null) {
            $this->currentInvocationContext = [
                'bot_id' => null,
                'room_token' => null,
                'attachments' => [],
                'document_source_ids' => [],
                'image_source_ids' => [],
            ];
            return;
        }

        $this->currentInvocationContext = [
            'bot_id' => isset($context['bot_id']) && is_int($context['bot_id']) ? $context['bot_id'] : null,
            'room_token' => isset($context['room_token']) && is_string($context['room_token']) && $context['room_token'] !== '' ? $context['room_token'] : null,
            'attachments' => isset($context['attachments']) && is_array($context['attachments'])
                ? array_values(array_filter(
                    $context['attachments'],
                    static fn ($attachment): bool => $attachment instanceof IncomingTalkAttachment
                ))
                : [],
            'document_source_ids' => isset($context['document_source_ids']) && is_array($context['document_source_ids']) ? array_values(array_map('intval', $context['document_source_ids'])) : [],
            'image_source_ids' => isset($context['image_source_ids']) && is_array($context['image_source_ids']) ? array_values(array_map('intval', $context['image_source_ids'])) : [],
        ];
    }

    /**
     * @return InvocationContext
     */
    public function getInvocationContext(): array {
        return $this->currentInvocationContext;
    }

    /**
     * Get all available built-in tool definitions
     *
     * @return array<int,ToolDefinition>
     */
    public function getAvailableTools(): array {
        $tools = [];

        // RAG document search tool - available when RAG is globally enabled
        $ragConfig = $this->settingsService->getRagConfig();
        if ($ragConfig['rag_enabled']) {
            $tools[] = $this->withPolicy([
                'name' => self::TOOL_RAG_SEARCH,
                'description' => 'IMPORTANT: Always use this tool FIRST when the user asks ANY question that could be answered from the knowledge base. Search through indexed documents using semantic similarity. Returns matching text chunks ranked by relevance. You MUST call this tool before answering questions about documents, policies, procedures, or any topic that might be in the knowledge base.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query - rephrase the user\'s question as keywords. Example: user asks "Was ist der Titel von Modul 3?" → query: "Modul 3 Titel"',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Number of chunks to retrieve (1-20). Use 3-5 for specific questions, 8-10 for broad topics.',
                            'default' => 5,
                        ],
                        'min_score' => [
                            'type' => 'number',
                            'description' => 'Minimum relevance score 0.0-1.0. Use 0.3 for broad searches, 0.5 for normal queries, 0.7 for precise matches only.',
                            'default' => 0.3,
                        ],
                    ],
                    'required' => ['query'],
                ],
            ]);
        }

        if ($this->isRoomSearchAvailable()) {
            $tools[] = $this->withPolicy([
                'name' => self::TOOL_ROOM_SEARCH,
                'description' => 'Search the documents uploaded inside the current Nextcloud Talk room for this bot. This can be used together with the global knowledge-base search.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query derived from the user request.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Number of chunks to retrieve (1-20).',
                            'default' => 5,
                        ],
                        'min_score' => [
                            'type' => 'number',
                            'description' => 'Minimum relevance score between 0.0 and 1.0.',
                            'default' => 0.3,
                        ],
                    ],
                    'required' => ['query'],
                ],
            ]);
        }

        if ($this->isRoomImageSearchAvailable()) {
            $tools[] = $this->withPolicy([
                'name' => self::TOOL_ROOM_IMAGE_SEARCH,
                'description' => 'Search image attachments uploaded inside the current Nextcloud Talk room for this bot. Use this for previous screenshots, photos, scans, or comparing images sent across multiple chat messages.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query derived from the user request, for example login error screenshot or red warning banner.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Number of image summaries to retrieve (1-20).',
                            'default' => 5,
                        ],
                        'min_score' => [
                            'type' => 'number',
                            'description' => 'Minimum relevance score between 0.0 and 1.0.',
                            'default' => 0.3,
                        ],
                        'image_name' => [
                            'type' => 'string',
                            'description' => 'Optional filename or display name to prioritize a specific image.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ]);
        }

        if ($this->visionClient->isEnabled()) {
            $tools[] = $this->withPolicy([
                'name' => self::TOOL_ATTACHMENT_IMAGE,
                'description' => 'Analyze the image attachment(s) from the current user message. Use this when the user asks about an uploaded image, screenshot, photo or scan in the current Talk message.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => [
                            'type' => 'string',
                            'description' => 'Optional question or focus area for the image analysis.',
                        ],
                        'attachment_name' => [
                            'type' => 'string',
                            'description' => 'Optional filename when multiple image attachments are present.',
                        ],
                    ],
                ],
            ]);
        }

        if ($this->speechToTextClient->isEnabled()) {
            $tools[] = $this->withPolicy([
                'name' => self::TOOL_ATTACHMENT_AUDIO,
                'description' => 'Transcribe the audio or voice-message attachment(s) from the current user message. Use this before answering questions about uploaded voice notes or spoken audio.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'attachment_name' => [
                            'type' => 'string',
                            'description' => 'Optional filename when multiple audio attachments are present.',
                        ],
                    ],
                ],
            ]);
        }

        $tools[] = $this->withPolicy([
            'name' => self::TOOL_WIKI_SEARCH,
            'description' => 'Search the personal bot\'s persistent Markdown wiki. Use this before answering questions about durable personal or bot knowledge stored in the wiki.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search terms for wiki pages.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Number of matching pages to return (1-20).',
                        'default' => 5,
                    ],
                    'scope' => [
                        'type' => 'string',
                        'description' => 'Search wiki pages, source summaries, or both.',
                        'enum' => ['wiki', 'sources', 'all'],
                        'default' => 'wiki',
                    ],
                ],
                'required' => ['query'],
            ],
        ]);

        $tools[] = $this->withPolicy([
            'name' => self::TOOL_WIKI_READ_PAGE,
            'description' => 'Read one Markdown, TXT, or JSON page from the bot\'s persistent wiki. Paths are relative to the wiki root. If the response has has_more=true, continue with offset=next_offset before finishing an incomplete review, or tell the user the path and next_offset needed to continue later.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Page path relative to the wiki root, for example index.md or pages/topics/rag-vs-llm-wiki.md.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'UTF-8 character offset into the page content. Use 0 for the beginning, or next_offset from a previous response.',
                        'default' => 0,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of UTF-8 characters to return. Defaults to 3000 and is capped server-side.',
                        'default' => 3000,
                    ],
                ],
                'required' => ['path'],
            ],
        ]);

        $tools[] = $this->withPolicy([
            'name' => self::TOOL_WIKI_WRITE_PAGE,
            'description' => 'Create, overwrite, or append to a Markdown/TXT/JSON page in a personal bot\'s persistent wiki. When adding durable wiki knowledge, also keep index.md useful as a curated content map without rewriting its automatically maintained Existing Files section.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Target page path relative to the wiki root, for example pages/projects/educ-ai.md.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Full text content for create/overwrite, or the text to append for append mode.',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'description' => 'Write behavior. create refuses to overwrite existing files; overwrite replaces; append appends.',
                        'enum' => ['create', 'overwrite', 'append'],
                        'default' => 'create',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Short reason for this durable wiki update.',
                    ],
                ],
                'required' => ['path', 'content'],
            ],
        ]);

        $tools[] = $this->withPolicy([
            'name' => self::TOOL_WIKI_LOG_EVENT,
            'description' => 'Append a concise maintenance event to the bot wiki log.md file.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Short log title, for example ingest | Karpathy LLM Wiki gist.',
                    ],
                    'details' => [
                        'type' => 'string',
                        'description' => 'Optional bullet list or short note to append below the log title.',
                    ],
                ],
                'required' => ['title'],
            ],
        ]);

        return $tools;
    }

    /**
     * @param array<string,mixed> $tool
     * @return array<string,mixed>
     */
    private function withPolicy(array $tool): array {
        $name = $tool['name'] ?? '';
        if (is_string($name) && $name !== '') {
            $tool['policy'] = $this->toolExecutionPolicyService->builtInPolicy($name);
        }

        return $tool;
    }

    /**
     * Check if a tool name is a built-in tool
     */
    public function isBuiltInTool(string $toolName): bool {
        return in_array($toolName, [
            self::TOOL_RAG_SEARCH,
            self::TOOL_ROOM_SEARCH,
            self::TOOL_ROOM_IMAGE_SEARCH,
            self::TOOL_ATTACHMENT_IMAGE,
            self::TOOL_ATTACHMENT_AUDIO,
            self::TOOL_WIKI_SEARCH,
            self::TOOL_WIKI_READ_PAGE,
            self::TOOL_WIKI_WRITE_PAGE,
            self::TOOL_WIKI_LOG_EVENT,
        ], true);
    }

    /**
     * Execute a built-in tool
     *
     * @param string $toolName The name of the tool to execute
     * @param array<string,mixed> $arguments The arguments passed to the tool
     * @param array<string,mixed> $config Per-bot tool configuration
     * @return array{content:array<int,array{type:string,text:string}>,isError:bool} The tool result
     * @throws Exception If the tool is not found or execution fails
     */
    public function executeTool(string $toolName, array $arguments, array $config = []): array {
        $this->logger->info('Executing built-in tool', [
            'tool' => $toolName,
            'argument_keys' => array_keys($arguments),
            'has_config' => count($config) > 0,
            'bot_context' => $this->currentInvocationContext['bot_id'],
            'room_context' => $this->currentInvocationContext['room_token'],
        ]);

        switch ($toolName) {
            case self::TOOL_RAG_SEARCH:
                return $this->executeRagSearch($arguments);

            case self::TOOL_ROOM_SEARCH:
                return $this->executeRoomSearch($arguments);

            case self::TOOL_ROOM_IMAGE_SEARCH:
                return $this->executeRoomImageSearch($arguments);

            case self::TOOL_ATTACHMENT_IMAGE:
                return $this->executeAttachmentImageAnalysis($arguments);

            case self::TOOL_ATTACHMENT_AUDIO:
                return $this->executeAttachmentAudioTranscription($arguments);

            case self::TOOL_WIKI_SEARCH:
                return $this->executeWikiSearch($arguments, $config);

            case self::TOOL_WIKI_READ_PAGE:
                return $this->executeWikiReadPage($arguments, $config);

            case self::TOOL_WIKI_WRITE_PAGE:
                return $this->executeWikiWritePage($arguments, $config);

            case self::TOOL_WIKI_LOG_EVENT:
                return $this->executeWikiLogEvent($arguments, $config);

            default:
                throw new Exception("Unknown built-in tool: $toolName");
        }
    }

    /**
     * Execute RAG document search
     */
    private function executeRagSearch(array $arguments): array {
        $botId = $this->currentInvocationContext['bot_id'];
        if ($botId === null) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Error: No bot context available for document search.',
                    ],
                ],
                'isError' => true,
            ];
        }

        $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';
        if ($query === '') {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Error: query parameter is required for document search.',
                    ],
                ],
                'isError' => true,
            ];
        }

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
            ? min(20, max(1, (int)$arguments['limit']))
            : 5;

        $minScore = isset($arguments['min_score']) && is_numeric($arguments['min_score'])
            ? max(0.0, min(1.0, (float)$arguments['min_score']))
            : 0.3;

        try {
            $embeddingModel = $this->embeddingClient->getActiveModel();
            // Compare vectors only inside the same embedding model space.
            $embeddings = $this->embeddingMapper->findByBotAndModel($botId, $embeddingModel);

            if (count($embeddings) === 0) {
                $allEmbeddingsCount = $this->embeddingMapper->countByBot($botId);
                if ($allEmbeddingsCount > 0) {
                    return [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "This assistant has indexed documents, but they were embedded with a different model than the current embedding model \"$embeddingModel\". Please reindex the knowledge sources in bot settings.",
                            ],
                        ],
                        'isError' => false,
                    ];
                }
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'No documents have been indexed for this assistant yet. Please add files to the assistant\'s knowledge base first.',
                        ],
                    ],
                    'isError' => false,
                ];
            }

            // Generate embedding for the query
            $vectorList = $this->embeddingClient->embedTexts([$query], $embeddingModel);
            if (count($vectorList) === 0) {
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Error: Failed to generate embedding for the search query.',
                        ],
                    ],
                    'isError' => true,
                ];
            }
            $queryVector = $vectorList[0];

            // Calculate similarity scores for all chunks
            $scored = [];
            foreach ($embeddings as $embedding) {
                $vector = $this->decodeVector($embedding->getEmbedding());
                if ($vector === null) {
                    continue;
                }
                $score = $this->cosineSimilarity($queryVector, $vector);

                // Apply minimum score filter
                if ($score < $minScore) {
                    continue;
                }

                $metadata = $this->decodeMetadata($embedding->getMetadata());
                $scored[] = [
                    'chunk' => $embedding,
                    'score' => $score,
                    'metadata' => $metadata,
                ];
            }

            // Sort by score descending
            usort($scored, static function (array $a, array $b): int {
                return $b['score'] <=> $a['score'];
            });

            // Take top results
            $results = array_slice($scored, 0, $limit);

            if (count($results) === 0) {
                $scoreInfo = $minScore > 0 ? " above the minimum score threshold of $minScore" : "";
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "No matching document chunks found for query: \"$query\"$scoreInfo. Try rephrasing your query or lowering the minimum score threshold.",
                        ],
                    ],
                    'isError' => false,
                ];
            }

            // Format results for LLM
            $text = $this->buildRagResultText($results, $query, count($scored) - count($results));

            $this->logger->info('RAG search completed', [
                'bot_id' => $botId,
                'query' => $query,
                'total_chunks' => count($embeddings),
                'matching_chunks' => count($scored),
                'returned_chunks' => count($results),
            ]);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
                'isError' => false,
            ];
        } catch (Exception $e) {
            $this->logger->error('RAG search failed', [
                'bot_id' => $botId,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Error searching documents: ' . $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * Build human-readable RAG search results text
     *
     * @param array<int,array{chunk:\OCA\EducAI\Db\Embedding,score:float,metadata:array}> $results
     */
    private function buildRagResultText(array $results, string $query, int $moreAvailable): string {
        $text = "Found " . count($results) . " relevant document chunk(s) for: \"$query\"";
        if ($moreAvailable > 0) {
            $text .= " ($moreAvailable more available, increase limit to see more)";
        }
        $text .= "\n\n";

        foreach ($results as $index => $result) {
            $chunk = $result['chunk'];
            $metadata = $result['metadata'];
            $score = $result['score'];

            $path = $metadata['path'] ?? 'Unknown source';
            $chunkIndex = $metadata['chunk_index'] ?? null;

            $text .= "---\n";
            $text .= "**Source " . ($index + 1) . ":** " . $path;
            if ($chunkIndex !== null) {
                $text .= " (chunk " . ($chunkIndex + 1) . ")";
            }
            $text .= sprintf(" [relevance: %.2f]\n", $score);
            $text .= "\n" . trim($chunk->getChunkText()) . "\n\n";
        }

        return $text;
    }

    private function executeRoomSearch(array $arguments): array {
        $botId = $this->currentInvocationContext['bot_id'];
        $roomToken = $this->currentInvocationContext['room_token'];
        if ($botId === null || $roomToken === null || $roomToken === '') {
            return $this->errorResponse('Error: No room context available for room document search.');
        }

        $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';
        if ($query === '') {
            return $this->errorResponse('Error: query parameter is required for room document search.');
        }

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
            ? min(20, max(1, (int)$arguments['limit']))
            : 5;
        $minScore = isset($arguments['min_score']) && is_numeric($arguments['min_score'])
            ? max(0.0, min(1.0, (float)$arguments['min_score']))
            : 0.3;
        $fallbackMinScore = min($minScore, 0.2);
        /** @var array<int,int> $currentDocumentSourceIds */
        $currentDocumentSourceIds = $this->currentInvocationContext['document_source_ids'];

        try {
            $embeddingModel = $this->embeddingClient->getActiveModel();
            $embeddings = $this->roomDocumentEmbeddingMapper->findByBotRoomAndModel($botId, $roomToken, $embeddingModel);
            if (count($embeddings) === 0) {
                $sourceCount = count($this->roomDocumentSourceMapper->findByBotAndRoom($botId, $roomToken));
                if ($sourceCount > 0) {
                    return $this->textResponse(
                        "This room has uploaded documents, but there are no vectors for the active embedding model \"$embeddingModel\". Reindex the room documents or verify the embedding configuration."
                    );
                }

                return $this->textResponse('No chat-uploaded documents have been indexed for this room yet.');
            }

            $vectorList = $this->embeddingClient->embedTexts([$query], $embeddingModel);
            if ($vectorList === []) {
                return $this->errorResponse('Error: Failed to generate embedding for the room search query.');
            }

            $queryVector = $vectorList[0];
            $scored = $this->scoreRoomEmbeddings($embeddings, $queryVector, $minScore);

            if ($scored === [] && $currentDocumentSourceIds !== [] && $fallbackMinScore < $minScore) {
                $scored = $this->scoreRoomEmbeddings(
                    $embeddings,
                    $queryVector,
                    $fallbackMinScore,
                    $currentDocumentSourceIds
                );
            }

            if ($scored === [] && $fallbackMinScore < $minScore) {
                $scored = $this->scoreRoomEmbeddings($embeddings, $queryVector, $fallbackMinScore);
            }

            $results = array_slice($scored, 0, $limit);
            if ($results === []) {
                return $this->textResponse("No matching room-document chunks found for query: \"$query\".");
            }

            return $this->textResponse(
                $this->buildRoomSearchResultText($results, $query, count($scored) - count($results))
            );
        } catch (Exception $e) {
            $this->logger->error('Room document search failed', [
                'bot_id' => $botId,
                'room_token' => $roomToken,
                'query' => $query,
                'exception' => $e,
            ]);
            return $this->errorResponse('Error searching room documents: ' . $e->getMessage());
        }
    }

    /**
     * @param array<int,RoomDocumentEmbedding> $embeddings
     * @param array<int,float|int> $queryVector
     * @param array<int,int>|null $sourceIds
     * @return array<int,array{chunk:RoomDocumentEmbedding,score:float,metadata:array<string,mixed>}>
     */
    private function scoreRoomEmbeddings(array $embeddings, array $queryVector, float $minScore, ?array $sourceIds = null): array {
        $allowedSourceIds = $sourceIds === null ? null : array_fill_keys(array_map('intval', $sourceIds), true);
        $scored = [];

        foreach ($embeddings as $embedding) {
            if ($allowedSourceIds !== null && !isset($allowedSourceIds[$embedding->getSourceId()])) {
                continue;
            }

            $vector = $this->decodeVector($embedding->getEmbedding());
            if ($vector === null) {
                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $vector);
            if ($score < $minScore) {
                continue;
            }

            $metadata = $this->decodeMetadata($embedding->getMetadata());
            $scored[] = [
                'chunk' => $embedding,
                'score' => $score,
                'metadata' => $metadata,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        return $scored;
    }

    /**
     * @param array<int,array{chunk:RoomDocumentEmbedding,score:float,metadata:array<string,mixed>}> $results
     */
    private function buildRoomSearchResultText(array $results, string $query, int $moreAvailable): string {
        $text = "Found " . count($results) . " relevant room document chunk(s) for: \"$query\"";
        if ($moreAvailable > 0) {
            $text .= " ($moreAvailable more available, increase limit to see more)";
        }
        $text .= "\n\n";

        foreach ($results as $index => $result) {
            /** @var RoomDocumentEmbedding $chunk */
            $chunk = $result['chunk'];
            $metadata = $result['metadata'];
            $score = $result['score'];
            $displayName = (string)($metadata['display_name'] ?? 'Uploaded document');
            $chunkIndex = isset($metadata['chunk_index']) && is_numeric($metadata['chunk_index']) ? (int)$metadata['chunk_index'] : null;

            $text .= "---\n";
            $text .= "**Source " . ($index + 1) . ":** " . $displayName;
            if ($chunkIndex !== null) {
                $text .= " (chunk " . ($chunkIndex + 1) . ")";
            }
            $text .= sprintf(" [relevance: %.2f]\n", $score);
            $text .= "\n" . trim($chunk->getChunkText()) . "\n\n";
        }

        return $text;
    }

    private function executeRoomImageSearch(array $arguments): array {
        $botId = $this->currentInvocationContext['bot_id'];
        $roomToken = $this->currentInvocationContext['room_token'];
        if ($botId === null || $roomToken === null || $roomToken === '') {
            return $this->errorResponse('Error: No room context available for room image search.');
        }

        $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';
        if ($query === '') {
            return $this->errorResponse('Error: query parameter is required for room image search.');
        }

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
            ? min(20, max(1, (int)$arguments['limit']))
            : 5;
        $minScore = isset($arguments['min_score']) && is_numeric($arguments['min_score'])
            ? max(0.0, min(1.0, (float)$arguments['min_score']))
            : 0.3;
        $imageName = isset($arguments['image_name']) && is_string($arguments['image_name']) ? trim($arguments['image_name']) : '';
        $fallbackMinScore = min($minScore, 0.2);
        /** @var array<int,int> $currentImageSourceIds */
        $currentImageSourceIds = $this->currentInvocationContext['image_source_ids'];

        try {
            $embeddingModel = $this->embeddingClient->getActiveModel();
            $embeddings = $this->roomImageEmbeddingMapper->findByBotRoomAndModel($botId, $roomToken, $embeddingModel);
            if (count($embeddings) === 0) {
                $sourceCount = count($this->roomImageSourceMapper->findByBotAndRoom($botId, $roomToken));
                if ($sourceCount > 0) {
                    return $this->textResponse(
                        "This room has indexed image analyses, but there are no vectors for the active embedding model \"$embeddingModel\". Reindex the room images or verify the embedding configuration."
                    );
                }

                return $this->textResponse('No image attachments have been indexed for this room yet.');
            }

            $vectorList = $this->embeddingClient->embedTexts([$query], $embeddingModel);
            if ($vectorList === []) {
                return $this->errorResponse('Error: Failed to generate embedding for the room image search query.');
            }

            $queryVector = $vectorList[0];
            $scored = $this->scoreRoomImageEmbeddings($embeddings, $queryVector, $minScore, null, $imageName);

            if ($scored === [] && $currentImageSourceIds !== [] && $fallbackMinScore < $minScore) {
                $scored = $this->scoreRoomImageEmbeddings(
                    $embeddings,
                    $queryVector,
                    $fallbackMinScore,
                    $currentImageSourceIds,
                    $imageName
                );
            }

            if ($scored === [] && $fallbackMinScore < $minScore) {
                $scored = $this->scoreRoomImageEmbeddings($embeddings, $queryVector, $fallbackMinScore, null, $imageName);
            }

            $results = array_slice($scored, 0, $limit);
            if ($results === []) {
                return $this->textResponse("No matching room-image analyses found for query: \"$query\".");
            }

            return $this->textResponse(
                $this->buildRoomImageSearchResultText($results, $query, count($scored) - count($results))
            );
        } catch (Exception $e) {
            $this->logger->error('Room image search failed', [
                'bot_id' => $botId,
                'room_token' => $roomToken,
                'query' => $query,
                'exception' => $e,
            ]);
            return $this->errorResponse('Error searching room images: ' . $e->getMessage());
        }
    }

    /**
     * @param array<int,RoomImageEmbedding> $embeddings
     * @param array<int,float|int> $queryVector
     * @param array<int,int>|null $sourceIds
     * @return array<int,array{chunk:RoomImageEmbedding,score:float,metadata:array<string,mixed>}>
     */
    private function scoreRoomImageEmbeddings(array $embeddings, array $queryVector, float $minScore, ?array $sourceIds = null, string $imageName = ''): array {
        $allowedSourceIds = $sourceIds === null ? null : array_fill_keys(array_map('intval', $sourceIds), true);
        $normalizedImageName = mb_strtolower(trim($imageName), 'UTF-8');
        $scored = [];

        foreach ($embeddings as $embedding) {
            if ($allowedSourceIds !== null && !isset($allowedSourceIds[$embedding->getSourceId()])) {
                continue;
            }

            $vector = $this->decodeVector($embedding->getEmbedding());
            if ($vector === null) {
                continue;
            }

            $metadata = $this->decodeMetadata($embedding->getMetadata());
            $displayName = mb_strtolower((string)($metadata['display_name'] ?? ''), 'UTF-8');
            $nameBoost = 0.0;
            if ($normalizedImageName !== '' && $displayName !== '') {
                if ($displayName === $normalizedImageName) {
                    $nameBoost = 0.2;
                } elseif (str_contains($displayName, $normalizedImageName)) {
                    $nameBoost = 0.1;
                }
            }

            $score = min(1.0, $this->cosineSimilarity($queryVector, $vector) + $nameBoost);
            if ($score < $minScore && $nameBoost <= 0.0) {
                continue;
            }
            if ($score < $minScore && $nameBoost > 0.0) {
                $score = $minScore;
            }

            $scored[] = [
                'chunk' => $embedding,
                'score' => $score,
                'metadata' => $metadata,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        return $scored;
    }

    /**
     * @param array<int,array{chunk:RoomImageEmbedding,score:float,metadata:array<string,mixed>}> $results
     */
    private function buildRoomImageSearchResultText(array $results, string $query, int $moreAvailable): string {
        $text = "Found " . count($results) . " relevant room image analysis result(s) for: \"$query\"";
        if ($moreAvailable > 0) {
            $text .= " ($moreAvailable more available, increase limit to see more)";
        }
        $text .= "\n\n";

        foreach ($results as $index => $result) {
            /** @var RoomImageEmbedding $chunk */
            $chunk = $result['chunk'];
            $metadata = $result['metadata'];
            $score = $result['score'];
            $displayName = (string)($metadata['display_name'] ?? 'Uploaded image');
            $messageId = isset($metadata['message_id']) && is_numeric($metadata['message_id']) ? (int)$metadata['message_id'] : null;
            $actorId = (string)($metadata['actor_id'] ?? '');
            $createdAt = isset($metadata['created_at']) && is_numeric($metadata['created_at']) ? (int)$metadata['created_at'] : null;

            $text .= "---\n";
            $text .= "**Image " . ($index + 1) . ":** " . $displayName;
            $text .= sprintf(" [relevance: %.2f]\n", $score);
            if ($messageId !== null) {
                $text .= "Talk message ID: " . $messageId . "\n";
            }
            if ($actorId !== '') {
                $text .= "Uploaded by: " . $actorId . "\n";
            }
            if ($createdAt !== null) {
                $text .= "Indexed at: " . date('Y-m-d H:i', $createdAt) . "\n";
            }
            $text .= "\n" . trim($chunk->getChunkText()) . "\n\n";
        }

        return $text;
    }

    private function executeAttachmentImageAnalysis(array $arguments): array {
        $attachment = $this->resolveAttachmentByKind(IncomingTalkAttachment::KIND_IMAGE, $arguments['attachment_name'] ?? null);
        if (!$attachment instanceof IncomingTalkAttachment) {
            return $this->errorResponse('Error: No image attachment from the current message is available for analysis.');
        }

        $question = isset($arguments['question']) && is_string($arguments['question']) && trim($arguments['question']) !== ''
            ? trim($arguments['question'])
            : 'Describe this image in detail and highlight any relevant text or visual details.';

        try {
            $resolved = $this->attachmentResolver->resolveToTempFile($attachment);
            try {
                $analysis = $this->visionClient->analyzeImage($resolved->getTempPath(), $resolved->getDisplayName(), $question);
            } finally {
                $resolved->cleanup();
            }

            return $this->textResponse("Image analysis for {$attachment->getDisplayName()}:\n\n" . $analysis);
        } catch (Exception $e) {
            $this->logger->error('Image attachment analysis failed', [
                'attachment' => $attachment->getDisplayName(),
                'exception' => $e,
            ]);
            return $this->errorResponse('Error analyzing image attachment: ' . $e->getMessage());
        }
    }

    private function executeAttachmentAudioTranscription(array $arguments): array {
        $attachment = $this->resolveAttachmentByKind(IncomingTalkAttachment::KIND_AUDIO, $arguments['attachment_name'] ?? null);
        if (!$attachment instanceof IncomingTalkAttachment) {
            return $this->errorResponse('Error: No audio attachment from the current message is available for transcription.');
        }

        try {
            $resolved = $this->attachmentResolver->resolveToTempFile($attachment);
            try {
                $transcript = $this->speechToTextClient->transcribeAudio($resolved->getTempPath(), $resolved->getDisplayName());
            } finally {
                $resolved->cleanup();
            }

            if ($transcript === '') {
                return $this->textResponse("The audio attachment {$attachment->getDisplayName()} could be processed, but no transcript text was returned.");
            }

            return $this->textResponse("Transcript for {$attachment->getDisplayName()}:\n\n" . $transcript);
        } catch (Exception $e) {
            $this->logger->error('Audio attachment transcription failed', [
                'attachment' => $attachment->getDisplayName(),
                'exception' => $e,
            ]);
            return $this->errorResponse('Error transcribing audio attachment: ' . $e->getMessage());
        }
    }

    /**
     * @param bool|float|int|string|null $attachmentName
     */
    private function resolveAttachmentByKind(string $kind, bool|float|int|string|null $attachmentName): ?IncomingTalkAttachment {
        /** @var array<int,IncomingTalkAttachment> $attachments */
        $attachments = $this->currentInvocationContext['attachments'];
        $normalizedName = is_string($attachmentName) ? mb_strtolower(trim($attachmentName), 'UTF-8') : null;
        $candidate = null;

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof IncomingTalkAttachment) {
                continue;
            }
            if ($attachment->getKind() !== $kind) {
                continue;
            }

            if ($normalizedName === null || $normalizedName === '') {
                return $attachment;
            }

            if (mb_strtolower($attachment->getDisplayName(), 'UTF-8') === $normalizedName) {
                return $attachment;
            }

            if ($candidate === null) {
                $candidate = $attachment;
            }
        }

        return $candidate;
    }

    private function isRoomSearchAvailable(): bool {
        $ragConfig = $this->settingsService->getRagConfig();
        $doclingConfig = $this->settingsService->getDoclingConfig();

        return (bool)$ragConfig['rag_enabled']
            && !empty($ragConfig['embedding_model'])
            && (bool)$doclingConfig['docling_enabled'];
    }

    private function isRoomImageSearchAvailable(): bool {
        $ragConfig = $this->settingsService->getRagConfig();

        return $this->visionClient->isEnabled()
            && (bool)$ragConfig['rag_enabled']
            && !empty($ragConfig['embedding_model']);
    }

    private function executeWikiSearch(array $arguments, array $config = []): array {
        $botId = $this->currentInvocationContext['bot_id'];
        if ($botId === null) {
            return $this->errorResponse('Error: No bot context available for wiki search.');
        }

        $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';
        if ($query === '') {
            return $this->errorResponse('Error: query parameter is required for wiki search.');
        }
        $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
            ? (int)$arguments['limit']
            : 5;
        $scope = isset($arguments['scope']) && is_string($arguments['scope']) ? $arguments['scope'] : 'wiki';

        try {
            return $this->jsonResponse($this->wikiService->search($botId, $query, $limit, $scope, $config));
        } catch (Exception $e) {
            $this->logger->warning('Wiki search failed', [
                'bot_id' => $botId,
                'exception' => $e,
            ]);
            return $this->errorResponse('Error searching wiki: ' . $e->getMessage());
        }
    }

    private function executeWikiReadPage(array $arguments, array $config = []): array {
        $botId = $this->currentInvocationContext['bot_id'];
        if ($botId === null) {
            return $this->errorResponse('Error: No bot context available for wiki page read.');
        }

        $path = isset($arguments['path']) && is_string($arguments['path']) ? trim($arguments['path']) : '';
        if ($path === '') {
            return $this->errorResponse('Error: path parameter is required for wiki page read.');
        }
        $offset = isset($arguments['offset']) && is_numeric($arguments['offset'])
            ? (int)$arguments['offset']
            : 0;
        $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
            ? (int)$arguments['limit']
            : 3000;

        try {
            return $this->jsonResponse($this->wikiService->readPage($botId, $path, $offset, $limit, $config));
        } catch (Exception $e) {
            $this->logger->warning('Wiki page read failed', [
                'bot_id' => $botId,
                'path' => $path,
                'exception' => $e,
            ]);
            return $this->errorResponse('Error reading wiki page: ' . $e->getMessage());
        }
    }

    private function executeWikiWritePage(array $arguments, array $config = []): array {
        $botId = $this->currentInvocationContext['bot_id'];
        if ($botId === null) {
            return $this->errorResponse('Error: No bot context available for wiki page write.');
        }

        $path = isset($arguments['path']) && is_string($arguments['path']) ? trim($arguments['path']) : '';
        $content = isset($arguments['content']) && is_string($arguments['content']) ? $arguments['content'] : '';
        $mode = isset($arguments['mode']) && is_string($arguments['mode']) ? $arguments['mode'] : 'create';
        $reason = isset($arguments['reason']) && is_string($arguments['reason']) ? $arguments['reason'] : null;
        if ($path === '') {
            return $this->errorResponse('Error: path parameter is required for wiki page write.');
        }
        if ($content === '') {
            return $this->errorResponse('Error: content parameter is required for wiki page write.');
        }

        try {
            return $this->jsonResponse($this->wikiService->writePage($botId, $path, $content, $mode, $reason, $config));
        } catch (Exception $e) {
            $this->logger->warning('Wiki page write failed', [
                'bot_id' => $botId,
                'path' => $path,
                'exception' => $e,
            ]);
            return $this->errorResponse('Error writing wiki page: ' . $e->getMessage());
        }
    }

    private function executeWikiLogEvent(array $arguments, array $config = []): array {
        $botId = $this->currentInvocationContext['bot_id'];
        if ($botId === null) {
            return $this->errorResponse('Error: No bot context available for wiki logging.');
        }

        $title = isset($arguments['title']) && is_string($arguments['title']) ? trim($arguments['title']) : '';
        $details = isset($arguments['details']) && is_string($arguments['details']) ? $arguments['details'] : '';
        if ($title === '') {
            return $this->errorResponse('Error: title parameter is required for wiki logging.');
        }

        try {
            return $this->jsonResponse($this->wikiService->logEvent($botId, $title, $details, $config));
        } catch (Exception $e) {
            $this->logger->warning('Wiki log write failed', [
                'bot_id' => $botId,
                'title' => $title,
                'exception' => $e,
            ]);
            return $this->errorResponse('Error writing wiki log: ' . $e->getMessage());
        }
    }

    private function textResponse(string $text): array {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
            'isError' => false,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function jsonResponse(array $payload): array {
        return $this->textResponse(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');
    }

    private function errorResponse(string $text): array {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
            'isError' => true,
        ];
    }

    /**
     * Calculate cosine similarity between two vectors
     *
     * @param array<int,float> $a
     * @param array<int,float> $b
     */
    private function cosineSimilarity(array $a, array $b): float {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /**
     * Decode embedding vector from JSON storage
     *
     * @return array<int,float>|null
     */
    private function decodeVector(?string $value): ?array {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }
        $vector = [];
        foreach ($decoded as $item) {
            if (!is_numeric($item)) {
                return null;
            }
            $vector[] = (float)$item;
        }
        return $vector;
    }

    /**
     * Decode chunk metadata from JSON
     *
     * @return array<string,mixed>
     */
    private function decodeMetadata(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
