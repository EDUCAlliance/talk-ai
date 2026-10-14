<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Db\Tool;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Service\BuiltInToolUiService;
use OCA\EducAI\Service\CatalogueClient;
use OCA\EducAI\Service\ConnectionCheckStore;
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
    private CatalogueClient $catalogueClient;
    private ?ConnectionCheckStore $connectionChecks;

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
        CatalogueClient $catalogueClient,
        ?ConnectionCheckStore $connectionChecks = null,
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
        $this->catalogueClient = $catalogueClient;
        $this->connectionChecks = $connectionChecks;
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
                    'aliases' => array_values(array_filter(
                        is_array($builtIn['aliases'] ?? null) ? $builtIn['aliases'] : [],
                        static fn ($alias): bool => is_string($alias) && $alias !== '',
                    )),
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
     * Explicitly test a saved or unsaved Catalogue endpoint, without enabling it.
     */
    public function testCatalogue(?string $catalogueApiEndpoint = null): DataResponse {
        return $this->runConnectionTest(
            'Catalogue',
            $catalogueApiEndpoint,
            fn (): array => $this->catalogueClient->testConnection($catalogueApiEndpoint),
            'catalogue_connection_failed',
            $this->l10n->t('Catalogue connection test failed'),
            check: 'catalogue',
            checkTarget: fn (array $result): array => [
                'summary' => is_string($result['version'] ?? null) && trim($result['version']) !== ''
                    ? 'Catalogue API ' . mb_substr(trim($result['version']), 0, 80) : null,
            ] + $this->catalogueClient->connectionCheckTarget($catalogueApiEndpoint),
        );
    }

    /**
     * @AdminRequired
     * Test the connection to the Docling document conversion API
     */
    public function testDocling(
        ?string $doclingApiEndpoint = null,
        ?string $doclingApiKey = null,
        ?string $doclingApiProfile = null,
        ?string $doclingAuthMode = null,
    ): DataResponse {
        return $this->runConnectionTest(
            'Docling',
            $doclingApiEndpoint,
            fn (): array => $this->doclingClient->testConnection($doclingApiEndpoint, $doclingApiKey, $doclingApiProfile, $doclingAuthMode),
            'docling_connection_failed',
            $this->l10n->t('Docling connection test failed'),
            true, // DoclingClient returns only fixed, safe diagnostics, never upstream bodies.
            check: 'docling',
            checkTarget: fn (): array => $this->doclingClient->connectionCheckTarget($doclingApiEndpoint, $doclingApiKey, $doclingApiProfile, $doclingAuthMode),
        );
    }

    /**
     * @AdminRequired
     * Detect the Docling API profile and authentication of a saved or unsaved URL
     * and prove it with one small test conversion. Settings are never changed.
     *
     * HTTP 200 whenever detection ran, including a failed conversion; HTTP 400 only for invalid input.
     */
    public function detectDocling(
        ?string $doclingApiEndpoint = null,
        ?string $doclingApiKey = null,
        bool $ignoreStoredKey = false,
    ): DataResponse {
        try {
            // $ignoreStoredKey: the administrator is removing the stored key, so test without it.
            $result = $this->doclingClient->detect($doclingApiEndpoint, $doclingApiKey, $ignoreStoredKey);
        } catch (\InvalidArgumentException $e) {
            // DoclingClient validation messages are fixed and never echo the URL or key.
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'errorCode' => 'docling_detection_invalid',
            ], 400);
        } catch (\Throwable $e) {
            // Only the type: traces of the conversion adapter could carry credentials.
            $this->logger->error('Docling detection failed unexpectedly', [
                'exception_class' => get_class($e),
            ]);
            return new DataResponse([
                'success' => false,
                'error' => $this->l10n->t('Docling detection failed'),
                'errorCode' => 'docling_detection_failed',
            ], 500);
        }

        if (!$result['success']) {
            $this->logger->warning('Docling detection did not complete a test conversion', [
                'profile' => $result['profile'],
                'source' => $result['source'],
                'details' => $result['error'],
            ]);
        }
        $this->rememberDetection($result, $doclingApiKey, $ignoreStoredKey);

        return new DataResponse($result);
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
            check: 'vision',
            checkTarget: fn (): array => $this->visionClient->connectionCheckTarget($visionApiEndpoint, $visionApiKey, $visionModel),
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
            check: 'speech',
            checkTarget: fn (): array => $this->speechToTextClient->connectionCheckTarget($speechApiEndpoint, $speechApiKey, $speechModel),
        );
    }

    /**
     * @param callable():array{success:bool,error?:string} $testConnection
     * @param (callable(array<string,mixed>):array{fingerprint?:?string,summary?:?string})|null $checkTarget
     */
    private function runConnectionTest(
        string $serviceName,
        ?string $endpoint,
        callable $testConnection,
        string $errorCode,
        string $failureMessage,
        bool $useSafeError = false,
        ?string $check = null,
        ?callable $checkTarget = null,
    ): DataResponse {
        $started = microtime(true);
        try {
            $result = $testConnection();
            if ($result['success']) {
                $this->rememberCheck($check, $checkTarget, $result, null, $started);
                return new DataResponse([
                    'success' => true,
                    'error' => null,
                ]);
            }

            $this->logger->warning($serviceName . ' connection test failed', [
                'endpoint' => $endpoint ?? 'null',
                'details' => $result['error'] ?? null,
            ]);
            $error = $useSafeError ? ($result['error'] ?? $failureMessage) : $failureMessage;
            $this->rememberCheck($check, $checkTarget, $result, $error, $started);
            return new DataResponse([
                'success' => false,
                'error' => $error,
                'errorCode' => $errorCode,
            ], 400);
        } catch (Exception $e) {
            $this->logger->error($serviceName . ' connection test failed', [
                'endpoint' => $endpoint ?? 'null',
                'exception' => $e,
            ]);
            $this->rememberCheck($check, $checkTarget, ['success' => false], $failureMessage, $started);
            return new DataResponse([
                'success' => false,
                'error' => $failureMessage,
                'errorCode' => $errorCode,
            ], 400);
        }
    }

    /**
     * Remember an explicit check for the administrator overview. Only the error
     * already returned to the administrator is stored, never upstream details.
     *
     * @param (callable(array<string,mixed>):array{fingerprint?:?string,summary?:?string})|null $checkTarget
     * @param array<string,mixed> $result
     */
    private function rememberCheck(?string $check, ?callable $checkTarget, array $result, ?string $error, float $started): void {
        if ($check === null || $this->connectionChecks === null) {
            return;
        }
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        try {
            $target = $checkTarget !== null ? $checkTarget($result) : [];
        } catch (\Throwable $e) {
            // Without a fingerprint the check is remembered, but never reported as current.
            $target = [];
        }
        try {
            $this->connectionChecks->record(
                $check,
                ($result['success'] ?? false) === true,
                $durationMs,
                $target['summary'] ?? null,
                $error,
                $target['fingerprint'] ?? null,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('The connection check result could not be remembered', ['service' => $check]);
        }
    }

    /**
     * @param array{success: bool, profile: ?string, auth_mode: ?string, service: ?string, version: ?string, endpoint: string, duration_ms: ?int, error: ?string} $result
     */
    private function rememberDetection(array $result, ?string $typedKey, bool $ignoreStoredKey = false): void {
        if ($this->connectionChecks === null) {
            return;
        }
        $fingerprint = null;
        $summary = null;
        if ($result['profile'] !== null) {
            $summary = $result['service'] . ($result['version'] !== null ? ' ' . $result['version'] : '')
                . ' · ' . $result['auth_mode'];
            try {
                // Same precedence as the detection: a typed key, otherwise the stored key.
                $typedKey = trim((string)$typedKey);
                $fingerprint = $this->doclingClient->connectionCheckTarget(
                    $result['endpoint'],
                    $typedKey !== '' ? $typedKey : null,
                    $result['profile'],
                    $result['auth_mode'],
                    $ignoreStoredKey,
                )['fingerprint'] ?? null;
            } catch (\Throwable $e) {
                // Without a fingerprint the check is remembered, but never reported as current.
            }
        }
        try {
            $this->connectionChecks->record('docling', $result['success'], $result['duration_ms'], $summary, $result['error'], $fingerprint);
        } catch (\Throwable $e) {
            $this->logger->warning('The connection check result could not be remembered', ['service' => 'docling']);
        }
    }

    private function errorResponse(string $errorCode, string $error, int $status): DataResponse {
        return new DataResponse([
            'error' => $error,
            'errorCode' => $errorCode,
        ], $status);
    }
}
