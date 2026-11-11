<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\KnowledgeSourcePresenter;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
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
    private KnowledgeSourcePresenter $sourcePresenter;
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
        KnowledgeSourcePresenter $sourcePresenter,
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
        $this->sourcePresenter = $sourcePresenter;
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
        return $this->sourcePresenter->describeStatus($source)
            + $this->sourcePresenter->describeLocation($source)
            + $source->jsonSerialize();
    }

    /**
     * Editors of the bot, Nextcloud admins (the admin settings list every bot),
     * plus reviewers while the bot is pending.
     */
    private function canViewSources(Bot $bot): bool {
        if ($this->userId === null) {
            return false;
        }

        return $this->canManageSources($bot)
            || $this->permissionService->isAdmin($this->userId)
            || $this->botService->canInspectPendingReviewContext($bot, $this->userId);
    }

    /**
     * Sources are part of the bot: whoever may save the bot may change them.
     */
    private function canManageSources(Bot $bot): bool {
        return $this->userId !== null && $this->permissionService->canEditBot($this->userId, $bot);
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
