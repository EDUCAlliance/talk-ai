<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Db\Tool;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\McpClient;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\VisionClient;
use OCA\EducAI\Service\WikiLocationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ToolsController extends Controller {
    private ToolMapper $toolMapper;
    private ToolRegistry $toolRegistry;
    private McpClient $mcpClient;
    private DoclingClient $doclingClient;
    private VisionClient $visionClient;
    private SpeechToTextClient $speechToTextClient;
    private ToolProviderRegistry $toolProviderRegistry;
    private CredentialService $credentialService;
    private WikiLocationService $wikiLocationService;
    private ?string $userId;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ToolMapper $toolMapper,
        ToolRegistry $toolRegistry,
        McpClient $mcpClient,
        DoclingClient $doclingClient,
        VisionClient $visionClient,
        SpeechToTextClient $speechToTextClient,
        ToolProviderRegistry $toolProviderRegistry,
        CredentialService $credentialService,
        WikiLocationService $wikiLocationService,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->toolMapper = $toolMapper;
        $this->toolRegistry = $toolRegistry;
        $this->mcpClient = $mcpClient;
        $this->doclingClient = $doclingClient;
        $this->visionClient = $visionClient;
        $this->speechToTextClient = $speechToTextClient;
        $this->toolProviderRegistry = $toolProviderRegistry;
        $this->credentialService = $credentialService;
        $this->wikiLocationService = $wikiLocationService;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * @NoAdminRequired
     * 
     * Get all available tools (MCP tools + built-in tools) for selection
     */
    public function available(): DataResponse {
        try {
            // Get enabled MCP tools
            $mcpTools = $this->toolRegistry->getEnabledTools();
            
            // Convert MCP tools to a consistent format
            $tools = [];
            foreach ($mcpTools as $tool) {
                $tools[] = [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'is_builtin' => false,
                    'builtin_name' => null,
                ];
            }
            
            // Get available built-in tools
            $builtInTools = $this->toolProviderRegistry->getAvailableTools();
            foreach ($builtInTools as $builtIn) {
                $label = isset($builtIn['label']) && is_string($builtIn['label']) && $builtIn['label'] !== ''
                    ? $builtIn['label']
                    : $this->formatBuiltInToolName($builtIn['name']);
                $tools[] = [
                    'id' => null, // Built-in tools don't have DB IDs
                    'name' => $label,
                    'description' => $builtIn['description'],
                    'is_builtin' => true,
                    'builtin_name' => $builtIn['name'],
                ];
            }
            
            return new DataResponse(['tools' => $tools]);
        } catch (Exception $e) {
            $this->logger->error('Failed to list available tools', [
                'exception' => $e,
            ]);
            return new DataResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function wikiLocations(): DataResponse {
        return new DataResponse([
            'collectives' => $this->wikiLocationService->listEditableCollectives($this->userId),
        ]);
    }

    /**
     * Format built-in tool name for display
     */
    private function formatBuiltInToolName(string $name): string {
        $mapping = [
            BuiltInToolProvider::TOOL_ROOM_SEARCH => 'Room Document Search',
            BuiltInToolProvider::TOOL_ROOM_IMAGE_SEARCH => 'Room Image Search',
            BuiltInToolProvider::TOOL_ATTACHMENT_IMAGE => 'Image Attachment Analysis',
            BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO => 'Audio Attachment Transcription',
            BuiltInToolProvider::TOOL_RAG_SEARCH => 'Document Search (RAG)',
            BuiltInToolProvider::TOOL_WIKI_SEARCH => 'Wiki Search',
            BuiltInToolProvider::TOOL_WIKI_READ_PAGE => 'Wiki Read Page',
            BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE => 'Wiki Write Page',
            BuiltInToolProvider::TOOL_WIKI_LOG_EVENT => 'Wiki Log Event',
        ];
        if (isset($mapping[$name])) {
            return $mapping[$name];
        }

        return ucwords(str_replace('_', ' ', $name));
    }

    /**
     * @AdminRequired
     */
    public function index(): DataResponse {
        $tools = $this->toolMapper->findAllTools();
        return new DataResponse(['tools' => $tools]);
    }

    /**
     * @AdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $tool = $this->toolMapper->findById($id);
            return new DataResponse($tool);
        } catch (Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @AdminRequired
     */
    public function create(
        string $name,
        string $mcpEndpointUrl,
        ?string $description = null,
        ?array $authentication = null,
        ?array $capabilities = null,
        bool $enabled = false
    ): DataResponse {
        try {
            $tool = new Tool();
            $tool->setName($name);
            $tool->setMcpEndpointUrl($mcpEndpointUrl);
            $tool->setDescription($description);

            // Encrypt authentication JSON before storing
            if ($authentication !== null) {
                $authJson = json_encode($authentication);
                if ($authJson !== false && $authJson !== '{}' && $authJson !== 'null') {
                    $tool->setAuthentication($this->credentialService->encrypt($authJson));
                } else {
                    $tool->setAuthentication(null);
                }
            } else {
                $tool->setAuthentication(null);
            }

            $tool->setCapabilities($capabilities !== null ? json_encode($capabilities) ?: null : null);
            $tool->setEnabled($enabled);
            $tool->setCreatedAt(time());
            $tool->setUpdatedAt(time());

            $tool = $this->toolMapper->insert($tool);
            $this->toolRegistry->refresh();

            return new DataResponse($tool, 201);
        } catch (Exception $e) {
            $this->logger->error('Failed to create tool', [
                'name' => $name,
                'exception' => $e,
            ]);
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @AdminRequired
     */
    public function update(
        int $id,
        ?string $name = null,
        ?string $mcpEndpointUrl = null,
        ?string $description = null,
        ?array $authentication = null,
        ?array $capabilities = null,
        ?bool $enabled = null
    ): DataResponse {
        try {
            $tool = $this->toolMapper->findById($id);
            if ($name !== null) {
                $tool->setName($name);
            }
            if ($mcpEndpointUrl !== null) {
                $tool->setMcpEndpointUrl($mcpEndpointUrl);
            }
            if ($description !== null) {
                $tool->setDescription($description);
            }
            if ($authentication !== null) {
                // Encrypt authentication JSON before storing
                $authJson = json_encode($authentication);
                if ($authJson !== false && $authJson !== '{}' && $authJson !== 'null') {
                    $tool->setAuthentication($this->credentialService->encrypt($authJson));
                } else {
                    $tool->setAuthentication(null);
                }
            }
            if ($capabilities !== null) {
                $tool->setCapabilities(json_encode($capabilities) ?: null);
            }
            if ($enabled !== null) {
                $tool->setEnabled($enabled);
            }
            $tool->setUpdatedAt(time());

            $tool = $this->toolMapper->update($tool);
            $this->toolRegistry->refresh();

            return new DataResponse($tool);
        } catch (Exception $e) {
            $this->logger->error('Failed to update tool', [
                'tool_id' => $id,
                'exception' => $e,
            ]);
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @AdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $tool = $this->toolMapper->findById($id);
            $this->toolMapper->delete($tool);
            $this->toolRegistry->refresh();
            return new DataResponse(['success' => true]);
        } catch (Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @AdminRequired
     */
    public function test(
        ?string $mcpEndpointUrl = null,
        ?array $authentication = null
    ): DataResponse {
        try {
            $this->logger->debug('Testing tool connection - raw params', [
                'mcpEndpointUrl_param' => $mcpEndpointUrl,
                'mcpEndpointUrl_type' => gettype($mcpEndpointUrl),
                'has_auth' => $authentication !== null,
            ]);

            if (empty($mcpEndpointUrl)) {
                return new DataResponse(['error' => 'MCP Endpoint URL is required'], 400);
            }

            $tool = new Tool();
            $tool->setId(0);
            $tool->setName('test');
            $tool->setMcpEndpointUrl($mcpEndpointUrl);
            $tool->setAuthentication($authentication !== null ? json_encode($authentication) ?: null : null);
            $tool->setEnabled(true);
            $tool->setCreatedAt(time());
            $tool->setUpdatedAt(time());

            $this->logger->debug('Tool object created', [
                'url_from_tool' => $tool->getMcpEndpointUrl(),
            ]);

            $tools = $this->mcpClient->listTools($tool);
            return new DataResponse(['tools' => $tools]);
        } catch (Exception $e) {
            $this->logger->error('Tool connection test failed', [
                'endpoint' => $mcpEndpointUrl ?? 'null',
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @AdminRequired
     * Test the connection to the Docling document conversion API
     */
    public function testDocling(
        ?string $doclingApiEndpoint = null,
        ?string $doclingApiKey = null
    ): DataResponse {
        return $this->runConnectionTest(
            'Docling',
            $doclingApiEndpoint,
            fn(): array => $this->doclingClient->testConnection($doclingApiEndpoint, $doclingApiKey)
        );
    }

    /**
     * @AdminRequired
     */
    public function testVision(
        ?string $visionApiEndpoint = null,
        ?string $visionApiKey = null,
        ?string $visionModel = null
    ): DataResponse {
        return $this->runConnectionTest(
            'Vision',
            $visionApiEndpoint,
            fn(): array => $this->visionClient->testConnection($visionApiEndpoint, $visionApiKey, $visionModel)
        );
    }

    /**
     * @AdminRequired
     */
    public function testSpeech(
        ?string $speechApiEndpoint = null,
        ?string $speechApiKey = null,
        ?string $speechModel = null
    ): DataResponse {
        return $this->runConnectionTest(
            'Speech',
            $speechApiEndpoint,
            fn(): array => $this->speechToTextClient->testConnection($speechApiEndpoint, $speechApiKey, $speechModel)
        );
    }

    /**
     * @param callable():array{success:bool,error?:string} $testConnection
     */
    private function runConnectionTest(string $serviceName, ?string $endpoint, callable $testConnection): DataResponse {
        try {
            $result = $testConnection();
            if ($result['success']) {
                return new DataResponse([
                    'success' => true,
                    'error' => null,
                ]);
            }

            return new DataResponse([
                'success' => false,
                'error' => $result['error'] ?? 'Connection test failed',
            ], 400);
        } catch (Exception $e) {
            $this->logger->error($serviceName . ' connection test failed', [
                'endpoint' => $endpoint ?? 'null',
                'exception' => $e,
            ]);
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
