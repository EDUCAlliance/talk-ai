<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Db\Tool;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Service\BuiltInToolUiService;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\McpClient;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\VisionClient;
use OCA\EducAI\Service\WikiLocationService;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
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
    private IL10N $l10n;
    private BuiltInToolUiService $builtInToolUiService;

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
        LoggerInterface $logger,
        IL10N $l10n,
        BuiltInToolUiService $builtInToolUiService,
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
        $this->l10n = $l10n;
        $this->builtInToolUiService = $builtInToolUiService;
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
                $fallbackLabel = isset($builtIn['label']) && is_string($builtIn['label']) && $builtIn['label'] !== ''
                    ? $builtIn['label']
                    : null;
                $tools[] = [
                    'id' => null, // Built-in tools don't have DB IDs
                    'name' => $this->builtInToolUiService->getLabel($builtIn['name'], $fallbackLabel),
                    'description' => $this->builtInToolUiService->getDescription(
                        $builtIn['name'],
                        isset($builtIn['description']) && is_string($builtIn['description']) ? $builtIn['description'] : '',
                    ),
                    'is_builtin' => true,
                    'builtin_name' => $builtIn['name'],
                ];
            }

            return new DataResponse(['tools' => $tools]);
        } catch (Exception $e) {
            $this->logger->error('Failed to list available tools', [
                'exception' => $e,
            ]);
            return $this->errorResponse('tools_load_failed', $this->l10n->t('Failed to load available tools'), 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function wikiLocations(): DataResponse {
        try {
            return new DataResponse([
                'collectives' => $this->wikiLocationService->listEditableCollectives($this->userId),
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to list editable wiki locations', [
                'user_id' => $this->userId,
                'exception' => $e,
            ]);
            return $this->errorResponse(
                'wiki_locations_load_failed',
                $this->l10n->t('Failed to load wiki locations'),
                500,
            );
        }
    }

    /**
     * @AdminRequired
     */
    public function index(): DataResponse {
        try {
            $tools = $this->toolMapper->findAllTools();
            return new DataResponse(['tools' => $tools]);
        } catch (Exception $e) {
            $this->logger->error('Failed to list configured tools', [
                'exception' => $e,
            ]);
            return $this->errorResponse('tools_load_failed', $this->l10n->t('Failed to load configured tools'), 500);
        }
    }

    /**
     * @AdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $tool = $this->toolMapper->findById($id);
            return new DataResponse($tool);
        } catch (Exception $e) {
            $this->logger->warning('Failed to load tool', [
                'tool_id' => $id,
                'exception' => $e,
            ]);
            return $this->errorResponse('tool_not_found', $this->l10n->t('Tool not found'), 404);
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
        bool $enabled = false,
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
            return $this->errorResponse('tool_create_failed', $this->l10n->t('Failed to create the tool'), 400);
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
        ?bool $enabled = null,
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
            return $this->errorResponse('tool_update_failed', $this->l10n->t('Failed to update the tool'), 400);
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
            $this->logger->error('Failed to delete tool', [
                'tool_id' => $id,
                'exception' => $e,
            ]);
            return $this->errorResponse('tool_delete_failed', $this->l10n->t('Failed to delete the tool'), 400);
        }
    }

    /**
     * @AdminRequired
     */
    public function test(
        ?string $mcpEndpointUrl = null,
        ?array $authentication = null,
    ): DataResponse {
        try {
            $this->logger->debug('Testing tool connection - raw params', [
                'mcpEndpointUrl_param' => $mcpEndpointUrl,
                'mcpEndpointUrl_type' => gettype($mcpEndpointUrl),
                'has_auth' => $authentication !== null,
            ]);

            if (empty($mcpEndpointUrl)) {
                return $this->errorResponse('mcp_endpoint_required', $this->l10n->t('MCP endpoint URL is required'), 400);
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
            return $this->errorResponse('tool_connection_failed', $this->l10n->t('Tool connection test failed'), 400);
        }
    }

    /**
     * @AdminRequired
     * Test the connection to the Docling document conversion API
     */
    public function testDocling(
        ?string $doclingApiEndpoint = null,
        ?string $doclingApiKey = null,
    ): DataResponse {
        return $this->runConnectionTest(
            'Docling',
            $doclingApiEndpoint,
            fn (): array => $this->doclingClient->testConnection($doclingApiEndpoint, $doclingApiKey),
            'docling_connection_failed',
            $this->l10n->t('Docling connection test failed'),
        );
    }

    /**
     * @AdminRequired
     */
    public function testVision(
        ?string $visionApiEndpoint = null,
        ?string $visionApiKey = null,
        ?string $visionModel = null,
    ): DataResponse {
        return $this->runConnectionTest(
            'Vision',
            $visionApiEndpoint,
            fn (): array => $this->visionClient->testConnection($visionApiEndpoint, $visionApiKey, $visionModel),
            'vision_connection_failed',
            $this->l10n->t('Vision connection test failed'),
        );
    }

    /**
     * @AdminRequired
     */
    public function testSpeech(
        ?string $speechApiEndpoint = null,
        ?string $speechApiKey = null,
        ?string $speechModel = null,
    ): DataResponse {
        return $this->runConnectionTest(
            'Speech',
            $speechApiEndpoint,
            fn (): array => $this->speechToTextClient->testConnection($speechApiEndpoint, $speechApiKey, $speechModel),
            'speech_connection_failed',
            $this->l10n->t('Speech connection test failed'),
        );
    }

    /**
     * @param callable():array{success:bool,error?:string} $testConnection
     */
    private function runConnectionTest(
        string $serviceName,
        ?string $endpoint,
        callable $testConnection,
        string $errorCode,
        string $failureMessage,
    ): DataResponse {
        try {
            $result = $testConnection();
            if ($result['success']) {
                return new DataResponse([
                    'success' => true,
                    'error' => null,
                ]);
            }

            $this->logger->warning($serviceName . ' connection test failed', [
                'endpoint' => $endpoint ?? 'null',
                'details' => $result['error'] ?? null,
            ]);
            return new DataResponse([
                'success' => false,
                'error' => $failureMessage,
                'errorCode' => $errorCode,
            ], 400);
        } catch (Exception $e) {
            $this->logger->error($serviceName . ' connection test failed', [
                'endpoint' => $endpoint ?? 'null',
                'exception' => $e,
            ]);
            return new DataResponse([
                'success' => false,
                'error' => $failureMessage,
                'errorCode' => $errorCode,
            ], 400);
        }
    }

    private function errorResponse(string $errorCode, string $error, int $status): DataResponse {
        return new DataResponse([
            'error' => $error,
            'errorCode' => $errorCode,
        ], $status);
    }
}
