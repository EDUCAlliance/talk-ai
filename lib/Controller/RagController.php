<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

class RagController extends Controller {
    private BotService $botService;
    private BotSourceMapper $botSourceMapper;
    private EmbeddingMapper $embeddingMapper;
    private RagIngestionService $ragIngestionService;
    private UrlContentFetcher $urlContentFetcher;
    private PermissionService $permissionService;
    private IRootFolder $rootFolder;
    private ?string $userId;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        BotService $botService,
        BotSourceMapper $botSourceMapper,
        EmbeddingMapper $embeddingMapper,
        RagIngestionService $ragIngestionService,
        UrlContentFetcher $urlContentFetcher,
        PermissionService $permissionService,
        IRootFolder $rootFolder,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->botService = $botService;
        $this->botSourceMapper = $botSourceMapper;
        $this->embeddingMapper = $embeddingMapper;
        $this->ragIngestionService = $ragIngestionService;
        $this->urlContentFetcher = $urlContentFetcher;
        $this->permissionService = $permissionService;
        $this->rootFolder = $rootFolder;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * @NoAdminRequired
     */
    public function index(int $botId): DataResponse {
        try {
            $bot = $this->botService->getBot($botId);
            if (!$this->canViewSources($bot)) {
                return new DataResponse(['error' => 'Unauthorized'], 403);
            }

            $sources = $this->botSourceMapper->findByBot($botId);
            $payload = array_map(function (BotSource $source): array {
                return $this->formatSource($source);
            }, $sources);
            return new DataResponse(['sources' => $payload]);
        } catch (Exception $e) {
            $this->logger->error('Failed to list RAG sources', [
                'bot_id' => $botId,
                'exception' => $e,
            ]);
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @NoAdminRequired
     * 
     * Add a new source to a bot. Supports two types:
     * - File/folder: pass nodeId and nodeType ('file' or 'folder')
     * - URL: pass sourceUrl (nodeType will be set to 'url')
     */
    public function store(int $botId, ?int $nodeId = null, ?string $nodeType = null, ?string $sourceUrl = null): DataResponse {
        try {
            if ($this->userId === null) {
                return new DataResponse(['error' => 'Unauthorized'], 401);
            }

            $bot = $this->botService->getBot($botId);
            if (!$this->canManageSources($bot)) {
                return new DataResponse(['error' => 'Unauthorized'], 403);
            }

            // Determine source type: URL or file/folder
            if ($sourceUrl !== null && $sourceUrl !== '') {
                return $this->storeUrlSource($botId, $sourceUrl);
            }

            // File/folder source
            if ($nodeId === null || $nodeType === null) {
                return new DataResponse(['error' => 'Either sourceUrl or nodeId+nodeType must be provided'], 400);
            }

            if (!in_array($nodeType, ['file', 'folder'], true)) {
                return new DataResponse(['error' => 'Invalid node type'], 400);
            }

            $existing = $this->botSourceMapper->findOneByBotAndNode($botId, $nodeId);
            if ($existing instanceof BotSource) {
                return new DataResponse($this->formatSource($existing), 200);
            }

            $source = new BotSource();
            $source->setBotId($botId);
            $source->setOwnerUid($this->userId);
            $source->setNodeId($nodeId);
            $source->setNodeType($nodeType);
            $source->setStatus('pending');
            $source->setCreatedAt(time());
            $source->setUpdatedAt(time());

            $source = $this->botSourceMapper->insert($source);
            $this->ragIngestionService->enqueueSource($source->getId());

            return new DataResponse($this->formatSource($source), 201);
        } catch (Exception $e) {
            $this->logger->error('Failed to create RAG source', [
                'bot_id' => $botId,
                'exception' => $e,
            ]);
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Store a URL source
     */
    private function storeUrlSource(int $botId, string $sourceUrl): DataResponse {
        // Validate URL
        if (!$this->urlContentFetcher->isValidUrl($sourceUrl)) {
            return new DataResponse(['error' => 'Invalid URL. Only http:// and https:// URLs are allowed.'], 400);
        }

        // Check for duplicate
        $existing = $this->botSourceMapper->findOneByBotAndUrl($botId, $sourceUrl);
        if ($existing instanceof BotSource) {
            return new DataResponse($this->formatSource($existing), 200);
        }

        $source = new BotSource();
        $source->setBotId($botId);
        $source->setOwnerUid($this->userId ?? '');
        $source->setNodeId(0); // No node ID for URL sources
        $source->setNodeType('url');
        $source->setSourceUrl($sourceUrl);
        $source->setStatus('pending');
        $source->setCreatedAt(time());
        $source->setUpdatedAt(time());

        $source = $this->botSourceMapper->insert($source);
        $this->ragIngestionService->enqueueSource($source->getId());

        return new DataResponse($this->formatSource($source), 201);
    }

    /**
     * @return array<string,mixed>
     */
    private function formatSource(BotSource $source): array {
        $data = $source->jsonSerialize();
        $data['path'] = null;
        $data['display_name'] = null;

        // Handle URL sources differently
        if ($source->getNodeType() === 'url') {
            $url = $source->getSourceUrl();
            $data['path'] = $url;
            // Extract domain/filename as display name
            if ($url !== null) {
                $parsed = parse_url($url);
                $host = $parsed['host'] ?? '';
                $path = $parsed['path'] ?? '/';
                $filename = basename($path);
                $data['display_name'] = $filename !== '' && $filename !== '/' ? $filename : $host;
            }
            return $data;
        }

        // Handle file/folder sources
        try {
            $owner = $source->getOwnerUid();
            if ($owner !== null && $owner !== '') {
                $userFolder = $this->rootFolder->getUserFolder($owner);
                $nodes = $userFolder->getById($source->getNodeId());
                if (is_array($nodes) && count($nodes) > 0) {
                    $node = $nodes[0];
                    if ($node instanceof Node) {
                        $fullPath = $node->getPath();
                        $rootPath = $userFolder->getPath();
                        if (str_starts_with($fullPath, $rootPath)) {
                            $relative = trim(substr($fullPath, strlen($rootPath)), '/');
                            $data['path'] = $relative === '' ? '/' : $relative;
                        } else {
                            $data['path'] = $fullPath;
                        }
                        $data['display_name'] = $node->getName();
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to resolve source path', [
                'source_id' => $source->getId(),
                'exception' => $e,
            ]);
        }

        return $data;
    }

    private function canViewSources(Bot $bot): bool {
        if ($this->userId === null) {
            return false;
        }

        if ($bot->getUserId() === $this->userId) {
            return true;
        }

        if ($this->permissionService->isAdmin($this->userId)) {
            return true;
        }

        return $this->botService->canInspectPendingReviewContext($bot, $this->userId);
    }

    private function canManageSources(Bot $bot): bool {
        if ($this->userId === null) {
            return false;
        }

        if ($bot->getUserId() === $this->userId) {
            return true;
        }

        return $this->permissionService->isAdmin($this->userId);
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $botId, int $sourceId): DataResponse {
        try {
            $bot = $this->botService->getBot($botId);
            if (!$this->canManageSources($bot)) {
                return new DataResponse(['error' => 'Unauthorized'], 403);
            }

            $source = $this->getLinkedSource($botId, $sourceId);

            $this->embeddingMapper->deleteBySource($sourceId);
            $this->botSourceMapper->deleteById($sourceId);

            return new DataResponse(['success' => true]);
        } catch (Exception $e) {
            $this->logger->error('Failed to delete RAG source', [
                'bot_id' => $botId,
                'source_id' => $sourceId,
                'exception' => $e,
            ]);
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function reindex(int $botId, int $sourceId): DataResponse {
        try {
            $bot = $this->botService->getBot($botId);
            if (!$this->canManageSources($bot)) {
                return new DataResponse(['error' => 'Unauthorized'], 403);
            }

            $source = $this->getLinkedSource($botId, $sourceId);

            $this->ragIngestionService->enqueueSource($sourceId, true);

            return new DataResponse(['success' => true]);
        } catch (Exception $e) {
            $this->logger->error('Failed to queue RAG reindex', [
                'bot_id' => $botId,
                'source_id' => $sourceId,
                'exception' => $e,
            ]);
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @throws Exception
     */
    private function getLinkedSource(int $botId, int $sourceId): BotSource {
        $source = $this->botSourceMapper->findById($sourceId);
        if ($source->getBotId() !== $botId) {
            throw new Exception('Source not linked to bot');
        }

        return $source;
    }
}
