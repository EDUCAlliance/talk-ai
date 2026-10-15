<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\Embedding;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Exception\DoclingEmptyResultException;
use OCA\EducAI\Exception\EmbeddingRateLimitedException;
use OCA\EducAI\Exception\KnowledgeSourceException;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;
use Throwable;

class RagIngestionService {
    /** Operating-system metadata that is never document content (AppleDouble, Finder, Explorer). */
    private const METADATA_FILE_NAMES = ['.ds_store', 'thumbs.db', 'ehthumbs.db', 'desktop.ini', '.localized', "icon\r"];
    private const METADATA_FOLDER_NAMES = ['__macosx', '.trashes', '.spotlight-v100', '.fseventsd', '.temporaryitems', '.apdisk'];
    /** Retries after a source lookup failed for a backend reason (storage, database). */
    private const TEMPORARY_FAILURE_RETRY_DELAYS = [600, 1800, 3600];
    /** Retries after the embedding provider asked for a pause. */
    private const MAX_RATE_LIMIT_RETRIES = 5;
    private const MAX_REPORTED_EXTENSIONS = 5;

    private BotSourceMapper $botSourceMapper;
    private EmbeddingMapper $embeddingMapper;
    private EmbeddingClient $embeddingClient;
    private DoclingClient $doclingClient;
    private UrlContentFetcher $urlContentFetcher;
    private SettingsService $settingsService;
    private IRootFolder $rootFolder;
    private IJobList $jobList;
    private LoggerInterface $logger;
    private RagSourceLocator $sourceLocator;

    public function __construct(
        BotSourceMapper $botSourceMapper,
        EmbeddingMapper $embeddingMapper,
        EmbeddingClient $embeddingClient,
        DoclingClient $doclingClient,
        UrlContentFetcher $urlContentFetcher,
        SettingsService $settingsService,
        IRootFolder $rootFolder,
        IJobList $jobList,
        LoggerInterface $logger,
        ?RagSourceLocator $sourceLocator = null
    ) {
        $this->botSourceMapper = $botSourceMapper;
        $this->embeddingMapper = $embeddingMapper;
        $this->embeddingClient = $embeddingClient;
        $this->doclingClient = $doclingClient;
        $this->urlContentFetcher = $urlContentFetcher;
        $this->settingsService = $settingsService;
        $this->rootFolder = $rootFolder;
        $this->jobList = $jobList;
        $this->logger = $logger;
        $this->sourceLocator = $sourceLocator ?? new RagSourceLocator($rootFolder);
    }

    public function enqueueSource(int $sourceId, bool $force = false): void {
        // Mark source as pending immediately so UI can show progress
        try {
            $source = $this->botSourceMapper->findById($sourceId);
            // An unreachable source stays out of answers until its job has found it again.
            if ($source->getStatus() !== KnowledgeSourceStatus::UNAVAILABLE) {
                $source->setStatus(KnowledgeSourceStatus::PENDING);
                $source->setErrorMessage(null);
            }
            $source->setProgress(0);
            $source->setProgressStage(null);
            $source->setProgressCurrent(0);
            $source->setProgressTotal(0);
            $source->setUpdatedAt(time());
            $this->botSourceMapper->update($source);
        } catch (Exception $e) {
            $this->logger->warning('Failed to mark source as pending before queuing', [
                'sourceId' => $sourceId,
                'exception' => $e,
            ]);
        }

        // This run replaces scheduled retries, so only one retry chain exists per source.
        foreach ($this->queuedJobs($sourceId) as $job) {
            $argument = $job->getArgument();
            if ((int)($argument['attempt'] ?? 0) > 0) {
                $this->jobList->removeById((int)$job->getId());
            }
        }
        $this->jobList->add(ReindexBotSourceJob::class, [
            'sourceId' => $sourceId,
            'force' => $force,
        ]);
    }

    /**
     * Whether an indexing job for the source is queued, including retries scheduled for later.
     */
    public function isQueued(int $sourceId): bool {
        return $this->queuedJobs($sourceId) !== [];
    }

    /**
     * @return list<IJob>
     */
    private function queuedJobs(int $sourceId): array {
        $jobs = [];
        foreach ($this->jobList->getJobsIterator(ReindexBotSourceJob::class, null, 0) as $job) {
            $argument = $job->getArgument();
            if (is_array($argument) && (int)($argument['sourceId'] ?? 0) === $sourceId) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    public function ingestSourceById(int $sourceId, bool $force = false, int $attempt = 0): void {
        $source = $this->botSourceMapper->findById($sourceId);
        if ($attempt > 0 && $source->getStatus() === KnowledgeSourceStatus::READY) {
            // Another run indexed the source after this retry was scheduled.
            $this->logger->debug('Skipping obsolete RAG source retry', ['sourceId' => $sourceId, 'attempt' => $attempt]);
            return;
        }
        $this->ingestSource($source, $force, $attempt);
    }

    /**
     * Update progress tracking fields on a source
     */
    private function updateProgress(BotSource $source, string $stage, int $progress, int $current, int $total): void {
        $source->setProgressStage($stage);
        $source->setProgress($progress);
        $source->setProgressCurrent($current);
        $source->setProgressTotal($total);
        $source->setUpdatedAt(time());
        $this->botSourceMapper->update($source);
    }

    /**
     * Reset progress tracking fields
     */
    private function resetProgress(BotSource $source): void {
        $source->setProgress(0);
        $source->setProgressCurrent(0);
        $source->setProgressTotal(0);
        $source->setProgressStage(null);
    }

    public function ingestSource(BotSource $source, bool $force = false, int $attempt = 0): void {
        $now = time();
        $source->setUpdatedAt($now);
        // An unreachable source keeps its status, and stays out of answers, until it is found again.
        if ($source->getStatus() !== KnowledgeSourceStatus::UNAVAILABLE) {
            $source->setStatus(KnowledgeSourceStatus::PENDING);
            $source->setErrorMessage(null);
        }
        $this->resetProgress($source);
        $this->botSourceMapper->update($source);

        $ragConfig = $this->settingsService->getRagConfig();
        if (!$ragConfig['rag_enabled']) {
            $this->markFailed($source, KnowledgeSourceStatus::ERROR, KnowledgeSourceStatus::encode(
                KnowledgeSourceStatus::RAG_DISABLED,
                [],
                'RAG is disabled by the administrator'
            ));
            return;
        }

        try {
            // Handle URL sources differently
            if ($source->getNodeType() === 'url') {
                $this->ingestUrlSource($source, $force, $ragConfig);
                return;
            }

            // Handle file/folder sources
            $this->ingestFileSource($source, $force, $ragConfig, $attempt);
        } catch (EmbeddingRateLimitedException $e) {
            // The previous index stays untouched: embeddings are replaced only after all batches succeeded.
            $this->deferAfterRateLimit($source, $force, $attempt, $e);
        } catch (KnowledgeSourceException $e) {
            $this->logger->warning('RAG source could not be indexed', [
                'sourceId' => $source->getId(),
                'code' => $e->getErrorCode(),
                'parameters' => $e->getParameters(),
                'detail' => $e->getMessage(),
            ]);
            // A file the owner may no longer read must not stay answerable through the old index.
            $status = $e->getErrorCode() === KnowledgeSourceStatus::SOURCE_ACCESS_DENIED
                ? KnowledgeSourceStatus::UNAVAILABLE
                : KnowledgeSourceStatus::ERROR;
            $this->markFailed($source, $status, KnowledgeSourceStatus::encode(
                $e->getErrorCode(),
                $e->getParameters(),
                $e->getMessage()
            ));
        } catch (Exception $e) {
            $this->logger->error('RAG ingestion failed', [
                'sourceId' => $source->getId(),
                'exception' => $e,
            ]);
            $this->markFailed($source, KnowledgeSourceStatus::ERROR, KnowledgeSourceStatus::encode(
                KnowledgeSourceStatus::INDEXING_FAILED,
                [],
                $e->getMessage()
            ));
        }
    }

    private function markFailed(BotSource $source, string $status, string $errorMessage): void {
        if ($source->getStatus() === KnowledgeSourceStatus::UNAVAILABLE && $status === KnowledgeSourceStatus::ERROR) {
            // Not found again in this run: its old index must stay out of answers.
            $status = KnowledgeSourceStatus::UNAVAILABLE;
        }
        $source->setStatus($status);
        $source->setErrorMessage($errorMessage);
        $source->setUpdatedAt(time());
        $this->resetProgress($source);
        $this->botSourceMapper->update($source);
    }

    private function deferAfterRateLimit(BotSource $source, bool $force, int $attempt, EmbeddingRateLimitedException $e): void {
        $retryAfter = max(1, $e->getRetryAfterSeconds());
        $retryAt = time() + $retryAfter + 5;
        $willRetry = $attempt < self::MAX_RATE_LIMIT_RETRIES;
        $this->logger->warning('Embedding provider is rate limiting; RAG source indexing deferred', [
            'sourceId' => $source->getId(),
            'retry_after_seconds' => $retryAfter,
            'attempt' => $attempt,
            'will_retry' => $willRetry,
        ]);
        $message = KnowledgeSourceStatus::encode(
            KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED,
            ['retry_at' => $willRetry ? $retryAt : 0],
            $e->getMessage()
        );
        if (!$willRetry) {
            $this->markFailed($source, KnowledgeSourceStatus::ERROR, $message);
            return;
        }

        $this->markFailed($source, KnowledgeSourceStatus::PENDING, $message);
        $source->setProgressStage(KnowledgeSourceStatus::STAGE_WAITING);
        $this->botSourceMapper->update($source);
        $this->jobList->scheduleAfter(ReindexBotSourceJob::class, $retryAt, [
            'sourceId' => $source->getId(),
            'force' => $force,
            'attempt' => $attempt + 1,
        ]);
    }

    private function httpStatusFromThrowable(Throwable $exception): ?int {
        for ($candidate = $exception; $candidate instanceof Throwable; $candidate = $candidate->getPrevious()) {
            if (method_exists($candidate, 'hasResponse') && method_exists($candidate, 'getResponse') && $candidate->hasResponse()) {
                $response = $candidate->getResponse();
                if (is_object($response) && method_exists($response, 'getStatusCode')) {
                    $status = (int)$response->getStatusCode();
                    return $status >= 100 && $status <= 599 ? $status : null;
                }
            }
        }

        return null;
    }

    /**
     * Ingest a URL source
     * 
     * @param array<string,mixed> $ragConfig
     */
    private function ingestUrlSource(BotSource $source, bool $force, array $ragConfig): void {
        $url = $source->getSourceUrl();
        if ($url === null || $url === '') {
            throw new Exception('URL source has no URL configured');
        }

        $this->logger->info('Ingesting URL source', [
            'sourceId' => $source->getId(),
            'url' => $url,
        ]);

        // Stage 1: Fetching URL (0-20%)
        $this->updateProgress($source, 'collecting', 0, 0, 1);
        
        try {
            $fetchResult = $this->urlContentFetcher->fetchAndExtract($url);
        } catch (Exception $e) {
            throw new KnowledgeSourceException(KnowledgeSourceStatus::URL_FETCH_FAILED, [], $e->getMessage(), $e);
        }
        $content = $fetchResult['content'];
        $contentType = $fetchResult['content_type'];
        
        if (trim($content) === '') {
            throw new KnowledgeSourceException(KnowledgeSourceStatus::NO_TEXT, ['empty' => 1], 'No content could be extracted from URL');
        }

        $this->updateProgress($source, 'collecting', 20, 1, 1);

        // Stage 2: Chunking (20-30%)
        $chunkSize = $ragConfig['rag_chunk_size'] ?? 750;
        if ($chunkSize <= 0) {
            $chunkSize = 750;
        }
        $chunkOverlap = $ragConfig['rag_chunk_overlap'] ?? 50;
        if ($chunkOverlap < 0) {
            $chunkOverlap = 0;
        }

        $this->updateProgress($source, 'extracting', 20, 0, 1);
        
        $checksumSeed = sha1($content);
        $segments = $this->chunkText($content, $chunkSize, $chunkOverlap);
        
        $chunks = [];
        foreach ($segments as $idx => $segment) {
            $chunks[] = [
                'text' => $segment,
                'metadata' => [
                    'url' => $url,
                    'content_type' => $contentType,
                    'offset' => $idx,
                    'size' => strlen($segment),
                ],
            ];
        }

        if (count($chunks) === 0) {
            throw new KnowledgeSourceException(KnowledgeSourceStatus::NO_TEXT, ['empty' => 1], 'Unable to extract text content from URL');
        }

        $this->updateProgress($source, 'extracting', 30, 1, 1);

        // Check checksum
        $newChecksum = sha1($checksumSeed);
        if (!$force && $source->getChecksum() !== null && $source->getChecksum() === $newChecksum) {
            $source->setStatus(KnowledgeSourceStatus::READY);
            $source->setUpdatedAt(time());
            $source->setLastIndexedAt(time());
            $source->setProgress(100);
            $source->setProgressStage('ready');
            $this->botSourceMapper->update($source);
            return;
        }

        // Stage 3: Generate and store embeddings
        $this->generateAndStoreEmbeddings($source, $chunks, $newChecksum);
    }

    /**
     * Ingest a file/folder source
     * 
     * @param array<string,mixed> $ragConfig
     */
    private function ingestFileSource(BotSource $source, bool $force, array $ragConfig, int $attempt = 0): void {
        // Stage 1: Collecting files (0-5%)
        $this->updateProgress($source, 'collecting', 0, 0, 0);

        $location = $this->sourceLocator->locate($source);
        if ($location['state'] !== RagSourceLocator::FOUND || $location['node'] === null || $location['folder'] === null) {
            $this->handleUnresolvedSource($source, $location['state'], $location['exception'], $force, $attempt);
            return;
        }
        $targetNode = $location['node'];
        $ownerFolder = $location['folder'];

        $stats =['metadata' => 0, 'unsupported' => 0, 'needs_converter' => 0, 'empty' => 0, 'failed' => 0, 'indexed' => 0];
        $unsupportedExtensions = [];
        $files = $this->collectFiles($targetNode, $ownerFolder->getPath(), $stats);
        $totalFiles = count($files);

        if ($totalFiles === 0) {
            throw $stats['metadata'] > 0
                ? new KnowledgeSourceException(KnowledgeSourceStatus::METADATA_ONLY, ['metadata' => $stats['metadata']], 'Only operating-system metadata files were found')
                : new KnowledgeSourceException(KnowledgeSourceStatus::NO_FILES, [], 'No readable files found for source');
        }
        
        $this->updateProgress($source, 'collecting', 5, $totalFiles, $totalFiles);

        $chunkSize = $ragConfig['rag_chunk_size'] ?? 750;
        if ($chunkSize <= 0) {
            $chunkSize = 750;
        }
        $chunkOverlap = $ragConfig['rag_chunk_overlap'] ?? 50;
        if ($chunkOverlap < 0) {
            $chunkOverlap = 0;
        }

        $chunks = [];
        $checksumSeed = '';

        // Stage 2: Extracting text (5-30%)
        $this->updateProgress($source, 'extracting', 5, 0, $totalFiles);
        $extractionErrors = [];
        
        foreach ($files as $fileIndex => $fileInfo) {
            [$file, $relativePath] = $fileInfo;
            $extraction = $this->extractText($file);
            switch ($extraction['status']) {
                case 'denied':
                    throw new KnowledgeSourceException(
                        KnowledgeSourceStatus::SOURCE_ACCESS_DENIED,
                        ['files_denied' => true],
                        'Reading a file was denied: ' . $relativePath
                    );
                case 'failed':
                    $stats['failed']++;
                    $extractionErrors[] = $relativePath . ': ' . ($extraction['error'] ?? 'unknown error');
                    break;
                case 'unsupported':
                case 'needs_converter':
                    $stats[$extraction['status']]++;
                    $extension = strtolower(pathinfo((string)$file->getName(), PATHINFO_EXTENSION));
                    if ($extension !== '' && preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1) {
                        $unsupportedExtensions[$extension] = true;
                    }
                    break;
                case 'empty':
                    $stats['empty']++;
                    break;
                default:
                    $stats['indexed']++;
                    $content = $extraction['text'];
                    $checksumSeed .= sha1($content);
                    $segments = $this->chunkText($content, $chunkSize, $chunkOverlap);
                    foreach ($segments as $idx => $segment) {
                        $chunks[] = [
                            'text' => $segment,
                            'metadata' => [
                                'path' => $relativePath,
                                'offset' => $idx,
                                'size' => strlen($segment),
                            ],
                        ];
                    }
            }
            
            // Update progress: 5% to 30% during extraction
            $extractProgress = 5 + (int)(($fileIndex + 1) / $totalFiles * 25);
            $this->updateProgress($source, 'extracting', $extractProgress, $fileIndex + 1, $totalFiles);
        }

        if ($source->getStatus() === KnowledgeSourceStatus::UNAVAILABLE) {
            // Found again and every file was readable: index it like any other source.
            $source->setStatus(KnowledgeSourceStatus::PENDING);
            $source->setErrorMessage(null);
        }

        $this->logger->info('RAG source files classified',['sourceId' => $source->getId(), 'files' => $totalFiles] + $stats);
        $extensions = array_slice(array_keys($unsupportedExtensions), 0, self::MAX_REPORTED_EXTENSIONS);

        if (count($extractionErrors) > 0) {
            // Keep the previous complete index instead of replacing it with a partial one.
            $lastExtractionError = $extractionErrors[count($extractionErrors) - 1];
            throw new KnowledgeSourceException(
                KnowledgeSourceStatus::EXTRACTION_FAILED,
                ['failed' => $stats['failed'], 'total' => $totalFiles],
                'Unable to extract text content for ingestion. Last extraction error: ' . $lastExtractionError
            );
        }
        if (count($chunks) === 0) {
            $unsupported = $stats['unsupported'] + $stats['needs_converter'];
            if ($unsupported > 0 && $stats['empty'] === 0) {
                throw new KnowledgeSourceException(KnowledgeSourceStatus::NO_SUPPORTED_FILES, [
                    'unsupported' => $unsupported,
                    'extensions' => $extensions,
                    'converter_disabled' => $stats['needs_converter'] > 0,
                ], 'No supported files found for source');
            }
            throw new KnowledgeSourceException(KnowledgeSourceStatus::NO_TEXT, [
                'empty' => $stats['empty'],
                'unsupported' => $unsupported,
            ], 'Unable to extract text content for ingestion');
        }

        $skipped = $stats['unsupported'] + $stats['needs_converter'] + $stats['empty'];
        $notice = $skipped > 0 ? KnowledgeSourceStatus::encode(KnowledgeSourceStatus::SKIPPED_FILES, [
            'indexed' => $stats['indexed'],
            'unsupported' => $stats['unsupported'] + $stats['needs_converter'],
            'empty' => $stats['empty'],
            'extensions' => $extensions,
            'converter_disabled' => $stats['needs_converter'] > 0,
        ]) : null;

        // Stage 3: Chunking complete, check checksum (30-35%)
        $this->updateProgress($source, 'chunking', 30, count($chunks), count($chunks));
        
        $newChecksum = sha1($checksumSeed);
        if (!$force && $source->getChecksum() !== null && $source->getChecksum() === $newChecksum) {
            // Content unchanged, skip embedding
            $source->setStatus(KnowledgeSourceStatus::READY);
            $source->setErrorMessage($notice);
            $source->setUpdatedAt(time());
            $source->setLastIndexedAt(time());
            $source->setProgress(100);
            $source->setProgressStage('ready');
            $this->botSourceMapper->update($source);
            return;
        }
        
        $this->updateProgress($source, 'chunking', 35, count($chunks), count($chunks));

        // Generate and store embeddings
        $this->generateAndStoreEmbeddings($source, $chunks, $newChecksum, $notice);
    }

    /**
     * The owner can no longer reach the node, or the lookup failed.
     *
     * Missing or denied sources keep their embeddings but are excluded from answers
     * (status "unavailable") until the node becomes reachable again or the source
     * is removed. Backend failures keep the previous index usable and retry later.
     */
    private function handleUnresolvedSource(BotSource $source, string $state, ?Throwable $exception, bool $force, int $attempt): void {
        $context = [
            'sourceId' => $source->getId(),
            'nodeId' => $source->getNodeId(),
            'state' => $state,
            'exception_class' => $exception !== null ? get_class($exception) : null,
        ];

        if ($state === RagSourceLocator::MISSING || $state === RagSourceLocator::DENIED) {
            $this->logger->info('RAG source is not reachable for its owner; excluding it from answers', $context);
            $code = $state === RagSourceLocator::DENIED
                ? KnowledgeSourceStatus::SOURCE_ACCESS_DENIED
                : KnowledgeSourceStatus::SOURCE_MISSING;
            $this->markFailed($source, KnowledgeSourceStatus::UNAVAILABLE, KnowledgeSourceStatus::encode(
                $code,
                [],
                'File or folder is not reachable for its owner (' . $state . ')'
            ));
            return;
        }

        $delay = self::TEMPORARY_FAILURE_RETRY_DELAYS[$attempt] ?? null;
        $this->logger->warning('RAG source lookup failed temporarily; keeping the previous index', $context + [
            'retry_in_seconds' => $delay,
        ]);
        $this->markFailed($source, KnowledgeSourceStatus::ERROR, KnowledgeSourceStatus::encode(
            KnowledgeSourceStatus::SOURCE_TEMPORARILY_UNAVAILABLE,
            ['retry_scheduled' => $delay !== null],
            'Source lookup failed: ' . ($exception !== null ? get_class($exception) : 'unknown')
        ));
        if ($delay !== null) {
            $this->jobList->scheduleAfter(ReindexBotSourceJob::class, time() + $delay, [
                'sourceId' => $source->getId(),
                'force' => $force,
                'attempt' => $attempt + 1,
            ]);
        }
    }

    /**
     * Generate embeddings and store them in the database
     * 
     * @param array<int,array{text:string,metadata:array<string,mixed>}> $chunks
     */
    private function generateAndStoreEmbeddings(BotSource $source, array $chunks, string $newChecksum, ?string $notice = null): void {
        // Stage 4: Generating embeddings (35-90%)
        $totalChunks = count($chunks);
        $this->updateProgress($source, 'embedding', 35, 0, $totalChunks);
        $embeddingModel = $this->embeddingClient->getActiveModel();
        
        // Process embeddings in batches to provide more granular progress updates
        $batchSize = 50;
        $allEmbeddings = [];
        $chunkTexts = array_column($chunks, 'text');
        
        for ($i = 0; $i < $totalChunks; $i += $batchSize) {
            $batchTexts = array_slice($chunkTexts, $i, $batchSize);
            try {
                $batchEmbeddings = $this->embeddingClient->embedTexts($batchTexts, $embeddingModel, [
                    'purpose' => 'rag_source',
                    'source_id' => $source->getId(),
                    'bot_id' => $source->getBotId(),
                ]);
            } catch (EmbeddingRateLimitedException $e) {
                throw $e;
            } catch (Exception $e) {
                $status = $this->httpStatusFromThrowable($e);
                throw new KnowledgeSourceException(
                    KnowledgeSourceStatus::EMBEDDING_FAILED,
                    $status !== null ? ['http_status' => $status] : [],
                    'Embedding request failed (' . get_class($e) . ')',
                    $e
                );
            }
            $allEmbeddings = array_merge($allEmbeddings, $batchEmbeddings);
            
            // Update progress: 35% to 90% during embedding
            $processedCount = min($i + $batchSize, $totalChunks);
            $embeddingProgress = 35 + (int)(($processedCount / $totalChunks) * 55);
            $this->updateProgress($source, 'embedding', $embeddingProgress, $processedCount, $totalChunks);
        }

        // Stage 5: Storing embeddings (90-100%)
        $this->updateProgress($source, 'storing', 90, 0, $totalChunks);
        
        $this->embeddingMapper->deleteBySource($source->getId());

        foreach ($allEmbeddings as $index => $vector) {
            $chunk = $chunks[$index];
            $embedding = new Embedding();
            $embedding->setBotId($source->getBotId());
            $embedding->setSourceId($source->getId());
            $embedding->setChunkId($source->getId() . ':' . $index);
            $embedding->setChunkText($chunk['text']);
            $embedding->setEmbedding(json_encode($vector) ?: '[]');
            $embedding->setEmbeddingModel($embeddingModel);
            $embedding->setTokenCount(strlen($chunk['text']));
            $embedding->setMetadata(json_encode($chunk['metadata']) ?: '{}');
            $embedding->setScore(null);
            $embedding->setCreatedAt(time());
            $embedding->setUpdatedAt(time());
            $this->embeddingMapper->insert($embedding);
            
            // Update progress during storing (90-99%)
            if ($index % 10 === 0 || $index === $totalChunks - 1) {
                $storeProgress = 90 + (int)(($index + 1) / $totalChunks * 9);
                $this->updateProgress($source, 'storing', $storeProgress, $index + 1, $totalChunks);
            }
        }

        // Complete
        $source->setChecksum($newChecksum);
        $source->setStatus(KnowledgeSourceStatus::READY);
        $source->setErrorMessage($notice);
        $source->setLastIndexedAt(time());
        $source->setUpdatedAt(time());
        $source->setProgress(100);
        $source->setProgressStage('ready');
        $source->setProgressCurrent($totalChunks);
        $source->setProgressTotal($totalChunks);
        $this->botSourceMapper->update($source);
    }

    /**
     * @param array<string,int> $stats
     * @return array<int,array{0:File,1:string}>
     */
    private function collectFiles(Node $node, string $basePath, array &$stats): array {
        $result = [];
        if ($node instanceof File) {
            if ($this->isMetadataFile($node)) {
                $stats['metadata']++;
                return $result;
            }
            $result[] = [$node, $this->relativePath($node, $basePath)];
            return $result;
        }

        if ($node instanceof Folder) {
            foreach ($node->getDirectoryListing() as $child) {
                if ($child instanceof Folder) {
                    if (in_array(mb_strtolower((string)$child->getName()), self::METADATA_FOLDER_NAMES, true)) {
                        $stats['metadata']++;
                        continue;
                    }
                    $result = array_merge($result, $this->collectFiles($child, $basePath, $stats));
                } elseif ($child instanceof File) {
                    if ($this->isMetadataFile($child)) {
                        $stats['metadata']++;
                        continue;
                    }
                    $result[] = [$child, $this->relativePath($child, $basePath)];
                }
            }
        }

        return $result;
    }

    /**
     * AppleDouble companions (._name) and known Finder/Explorer files carry no document content.
     */
    private function isMetadataFile(File $file): bool {
        $name = (string)$file->getName();
        return str_starts_with($name, '._') || in_array(mb_strtolower($name), self::METADATA_FILE_NAMES, true);
    }

    /**
     * @return array{status:string,text:string,error:?string}
     */
    private function extractText(File $file): array {
        $mime = $file->getMimeType();
        $isText = strpos($mime, 'text/') === 0 || in_array($mime, ['application/json', 'application/xml'], true);
        $doclingSupported = $this->doclingClient->isSupported($file);
        $extractionError = null;
        
        // First, try Docling for supported binary formats (PDF, DOCX, etc.)
        if ($doclingSupported && $this->doclingClient->isEnabled()) {
            try {
                $this->logger->debug('Using Docling for file extraction', [
                    'file' => $file->getName(),
                    'mime' => $mime,
                ]);
                $markdown = $this->doclingClient->convertToMarkdown($file);
                // Ensure valid UTF-8
                return $this->textResult(mb_convert_encoding($markdown, 'UTF-8', 'UTF-8'));
            } catch (DoclingEmptyResultException $e) {
                return ['status' => 'empty', 'text' => '', 'error' => null];
            } catch (NotPermittedException|ForbiddenException $e) {
                return ['status' => 'denied', 'text' => '', 'error' => null];
            } catch (Exception $e) {
                $this->logger->warning('Docling conversion failed, falling back to text extraction', [
                    'file' => $file->getName(),
                    'error' => $e->getMessage(),
                ]);
                $extractionError = $e->getMessage();
                // Fall through to text extraction if Docling fails
            }
        }

        // Standard text extraction for text files
        if ($isText) {
            try {
                $content = (string)$file->getContent();
                // Ensure valid UTF-8 to avoid json_encode errors
                return $this->textResult(mb_convert_encoding($content, 'UTF-8', 'UTF-8'));
            } catch (NotPermittedException|ForbiddenException $e) {
                return ['status' => 'denied', 'text' => '', 'error' => null];
            } catch (Exception $e) {
                $this->logger->warning('Failed to read file during ingestion', [
                    'path' => $file->getPath(),
                    'exception' => $e,
                ]);
                return ['status' => 'failed', 'text' => '', 'error' => $e->getMessage()];
            }
        }

        if ($extractionError !== null) {
            return ['status' => 'failed', 'text' => '', 'error' => $extractionError];
        }

        return ['status' => $doclingSupported ? 'needs_converter' : 'unsupported', 'text' => '', 'error' => null];
    }

    /**
     * @return array{status:string,text:string,error:?string}
     */
    private function textResult(string $text): array {
        return trim($text) === ''
            ? ['status' => 'empty', 'text' => '', 'error' => null]
            : ['status' => 'text', 'text' => $text, 'error' => null];
    }

    /**
     * @return array<int,string>
     */
    private function chunkText(string $text, int $chunkSize, int $overlap): array {
        $chunks = [];
        $length = mb_strlen($text, 'UTF-8');
        $offset = 0;
        if ($chunkSize <= 0) {
            return [$text];
        }

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, $chunkSize, 'UTF-8');
            if ($chunk === '') {
                break;
            }
            $chunks[] = $chunk;
            $offset += $chunkSize - $overlap;
            if ($offset < 0 || $offset >= $length) {
                break;
            }
        }

        return $chunks;
    }

    private function relativePath(Node $node, string $rootPath): string {
        $fullPath = $node->getPath();
        if (str_starts_with($fullPath, $rootPath)) {
            return ltrim(substr($fullPath, strlen($rootPath)), '/');
        }
        return $node->getName();
    }
}
