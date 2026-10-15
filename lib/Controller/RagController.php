<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IRequest;
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
    private IL10N $l10n;

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
        LoggerInterface $logger,
        IL10N $l10n,
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
        $this->l10n = $l10n;
    }

    /**
     * @NoAdminRequired
     */
    public function index(int $botId): DataResponse {
        try {
            $bot = $this->botService->getBot($botId);
            if (!$this->canViewSources($bot)) {
                return $this->errorResponse('forbidden', $this->l10n->t('Unauthorized'), 403);
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
            return $this->errorResponse(
                'rag_sources_load_failed',
                $this->l10n->t('Failed to load knowledge sources'),
                400,
            );
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
                return $this->errorResponse('not_authenticated', $this->l10n->t('Unauthorized'), 401);
            }

            $bot = $this->botService->getBot($botId);
            if (!$this->canManageSources($bot)) {
                return $this->errorResponse('forbidden', $this->l10n->t('Unauthorized'), 403);
            }

            // Determine source type: URL or file/folder
            if ($sourceUrl !== null && $sourceUrl !== '') {
                return $this->storeUrlSource($botId, $sourceUrl);
            }

            // File/folder source
            if ($nodeId === null || $nodeType === null) {
                return $this->errorResponse(
                    'rag_source_required',
                    $this->l10n->t('Either a source URL or a file or folder must be provided.'),
                    400,
                );
            }

            if (!in_array($nodeType, ['file', 'folder'], true)) {
                return $this->errorResponse('rag_node_type_invalid', $this->l10n->t('Invalid source type'), 400);
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
            return $this->errorResponse(
                'rag_source_add_failed',
                $this->l10n->t('Failed to add the knowledge source'),
                400,
            );
        }
    }

    /**
     * Store a URL source
     */
    private function storeUrlSource(int $botId, string $sourceUrl): DataResponse {
        // Validate URL
        if (!$this->urlContentFetcher->isValidUrl($sourceUrl)) {
            return $this->errorResponse(
                'rag_url_invalid',
                $this->l10n->t('Invalid URL. Only http:// and https:// URLs are allowed.'),
                400,
            );
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
        $data = $this->describeSourceStatus($source) + $data;

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

    /**
     * Owner-facing status texts. Stored details (provider texts, URLs) are never shown,
     * only the category and its safe parameters.
     *
     * @return array{error_message:?string,source_error_code:?string,notice_message:?string}
     */
    private function describeSourceStatus(BotSource $source): array {
        $status = $source->getStatus();
        $stored = KnowledgeSourceStatus::decode($source->getErrorMessage());
        $code = $stored['code'] ?? null;
        $params = $stored['params'] ?? [];
        $result = ['error_message' => null, 'source_error_code' => null, 'notice_message' => null];

        if ($status === KnowledgeSourceStatus::READY && $code === KnowledgeSourceStatus::SKIPPED_FILES) {
            $result['notice_message'] = $this->l10n->t('Skipped files: %1$s not supported, %2$s without text.', [
                (int)($params['unsupported'] ?? 0),
                (int)($params['empty'] ?? 0),
            ]) . $this->unsupportedSuffix($params);
            return $result;
        }
        if ($status === KnowledgeSourceStatus::PENDING && $code === KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED) {
            $result['notice_message'] = $this->l10n->t('The embedding service asked for a pause. Indexing continues automatically.');
            return $result;
        }

        $isProblem = in_array($status, [KnowledgeSourceStatus::ERROR, KnowledgeSourceStatus::UNAVAILABLE], true)
            && $source->getErrorMessage() !== null
            && $source->getErrorMessage() !== '';
        if (!$isProblem) {
            return $result;
        }

        $message = $code !== null ? $this->sourceErrorMessage($code, $params) : null;
        $result['error_message'] = $message ?? $this->l10n->t('The knowledge source could not be processed.');
        $result['source_error_code'] = $message !== null ? $code : 'knowledge_source_processing_failed';

        return $result;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function sourceErrorMessage(string $code, array $params): ?string {
        return match ($code) {
            KnowledgeSourceStatus::NO_FILES => $this->l10n->t('The folder contains no files.'),
            KnowledgeSourceStatus::METADATA_ONLY => $this->l10n->t('Only system files were found (for example ._* or .DS_Store). There is nothing to index.'),
            KnowledgeSourceStatus::NO_SUPPORTED_FILES => $this->l10n->t('No supported files found.') . $this->unsupportedSuffix($params)
                . (($params['converter_disabled'] ?? false) === true ? ' ' . $this->l10n->t('Document conversion (Docling) is turned off; ask your administrator to enable it.') : ''),
            KnowledgeSourceStatus::NO_TEXT => $this->l10n->t('No readable text found: %1$s files without text, %2$s not supported.', [
                (int)($params['empty'] ?? 0),
                (int)($params['unsupported'] ?? 0),
            ]),
            KnowledgeSourceStatus::EXTRACTION_FAILED => $this->l10n->t('%1$s of %2$s files could not be read. The previous index is kept; check the files and reindex.', [
                (int)($params['failed'] ?? 0),
                (int)($params['total'] ?? 0),
            ]),
            KnowledgeSourceStatus::SOURCE_MISSING => $this->l10n->t('The file or folder is no longer reachable for its owner (deleted, moved or no longer shared). The bot does not use this source until it is reachable again. Check it, select it again or remove the source.'),
            KnowledgeSourceStatus::SOURCE_ACCESS_DENIED => $this->l10n->t('The owner no longer has access to this file or folder. The bot does not use this source until access is restored.'),
            KnowledgeSourceStatus::SOURCE_TEMPORARILY_UNAVAILABLE => ($params['retry_scheduled'] ?? false) === true
                ? $this->l10n->t('The source could not be read temporarily. The previous index is kept and indexing is retried automatically.')
                : $this->l10n->t('The source could not be read temporarily. The previous index is kept; reindex later.'),
            KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED => $this->l10n->t('The embedding service kept asking for a pause. The previous index is kept; reindex later.'),
            KnowledgeSourceStatus::EMBEDDING_FAILED => isset($params['http_status']) && is_int($params['http_status'])
                ? $this->l10n->t('The embedding service rejected the request (HTTP %s). The previous index is kept; administrators find details in the Nextcloud log.', [$params['http_status']])
                : $this->l10n->t('The embedding service could not be reached. The previous index is kept; administrators find details in the Nextcloud log.'),
            KnowledgeSourceStatus::URL_FETCH_FAILED => $this->l10n->t('The web page could not be loaded. Check the address and reindex.'),
            KnowledgeSourceStatus::RAG_DISABLED => $this->l10n->t('Knowledge sources are turned off by the administrator.'),
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $params
     */
    private function unsupportedSuffix(array $params): string {
        $extensions = array_values(array_filter(
            is_array($params['extensions'] ?? null) ? $params['extensions'] : [],
            static fn ($extension): bool => is_string($extension) && preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1
        ));
        if ($extensions === []) {
            return '';
        }

        return ' ' . $this->l10n->t('Unsupported file types: %s', [implode(', ', array_map(static fn (string $extension): string => '.' . $extension, $extensions))]);
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
                return $this->errorResponse('forbidden', $this->l10n->t('Unauthorized'), 403);
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
            return $this->errorResponse(
                'rag_source_delete_failed',
                $this->l10n->t('Failed to delete the knowledge source'),
                400,
            );
        }
    }

    /**
     * @NoAdminRequired
     */
    public function reindex(int $botId, int $sourceId): DataResponse {
        try {
            $bot = $this->botService->getBot($botId);
            if (!$this->canManageSources($bot)) {
                return $this->errorResponse('forbidden', $this->l10n->t('Unauthorized'), 403);
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
            return $this->errorResponse(
                'rag_source_reindex_failed',
                $this->l10n->t('Failed to reindex the knowledge source'),
                400,
            );
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

    private function errorResponse(string $errorCode, string $error, int $status): DataResponse {
        return new DataResponse([
            'error' => $error,
            'errorCode' => $errorCode,
        ], $status);
    }
}
