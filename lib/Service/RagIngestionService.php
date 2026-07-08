<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\Embedding;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

class RagIngestionService {
    private BotSourceMapper $botSourceMapper;
    private EmbeddingMapper $embeddingMapper;
    private EmbeddingClient $embeddingClient;
    private DoclingClient $doclingClient;
    private UrlContentFetcher $urlContentFetcher;
    private SettingsService $settingsService;
    private IRootFolder $rootFolder;
    private IJobList $jobList;
    private LoggerInterface $logger;

    public function __construct(
        BotSourceMapper $botSourceMapper,
        EmbeddingMapper $embeddingMapper,
        EmbeddingClient $embeddingClient,
        DoclingClient $doclingClient,
        UrlContentFetcher $urlContentFetcher,
        SettingsService $settingsService,
        IRootFolder $rootFolder,
        IJobList $jobList,
        LoggerInterface $logger
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
    }

    public function enqueueSource(int $sourceId, bool $force = false): void {
        // Mark source as pending immediately so UI can show progress
        try {
            $source = $this->botSourceMapper->findById($sourceId);
            $source->setStatus('pending');
            $source->setErrorMessage(null);
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

        $this->jobList->add(ReindexBotSourceJob::class, [
            'sourceId' => $sourceId,
            'force' => $force,
        ]);
    }

    public function ingestSourceById(int $sourceId, bool $force = false): void {
        $source = $this->botSourceMapper->findById($sourceId);
        $this->ingestSource($source, $force);
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

    public function ingestSource(BotSource $source, bool $force = false): void {
        $now = time();
        $source->setUpdatedAt($now);
        $source->setStatus('pending');
        $source->setErrorMessage(null);
        $this->resetProgress($source);
        $this->botSourceMapper->update($source);

        $ragConfig = $this->settingsService->getRagConfig();
        if (!$ragConfig['rag_enabled']) {
            $source->setStatus('error');
            $source->setErrorMessage('RAG is disabled by the administrator');
            $source->setUpdatedAt(time());
            $this->resetProgress($source);
            $this->botSourceMapper->update($source);
            return;
        }

        try {
            // Handle URL sources differently
            if ($source->getNodeType() === 'url') {
                $this->ingestUrlSource($source, $force, $ragConfig);
                return;
            }

            // Handle file/folder sources
            $this->ingestFileSource($source, $force, $ragConfig);
            
        } catch (Exception $e) {
            $this->logger->error('RAG ingestion failed', [
                'sourceId' => $source->getId(),
                'exception' => $e,
            ]);
            $source->setStatus('error');
            $source->setErrorMessage($e->getMessage());
            $source->setUpdatedAt(time());
            $this->resetProgress($source);
            $this->botSourceMapper->update($source);
        }
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
        
        $fetchResult = $this->urlContentFetcher->fetchAndExtract($url);
        $content = $fetchResult['content'];
        $contentType = $fetchResult['content_type'];
        
        if ($content === '') {
            throw new Exception('No content could be extracted from URL');
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
            throw new Exception('Unable to extract text content from URL');
        }

        $this->updateProgress($source, 'extracting', 30, 1, 1);

        // Check checksum
        $newChecksum = sha1($checksumSeed);
        if (!$force && $source->getChecksum() !== null && $source->getChecksum() === $newChecksum) {
            $source->setStatus('ready');
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
    private function ingestFileSource(BotSource $source, bool $force, array $ragConfig): void {
        // Stage 1: Collecting files (0-5%)
        $this->updateProgress($source, 'collecting', 0, 0, 0);
        
        $ownerFolder = $this->rootFolder->getUserFolder($source->getOwnerUid());
        
        // Try to resolve the target node - if the file/folder was deleted, clean up
        try {
            $targetNode = $this->resolveTargetNode($source, $ownerFolder);
        } catch (Exception $e) {
            // File/folder no longer exists - clean up embeddings and mark as deleted
            $this->logger->info('RAG source file/folder no longer exists, cleaning up embeddings', [
                'sourceId' => $source->getId(),
                'nodeId' => $source->getNodeId(),
            ]);
            $this->embeddingMapper->deleteBySource($source->getId());
            $source->setStatus('error');
            $source->setErrorMessage('File or folder no longer exists. Embeddings have been cleaned up. You can remove this source.');
            $source->setChecksum(null);
            $source->setUpdatedAt(time());
            $this->resetProgress($source);
            $this->botSourceMapper->update($source);
            return;
        }
        
        $files = $this->collectFiles($targetNode, $ownerFolder->getPath());
        $totalFiles = count($files);
        
        if ($totalFiles === 0) {
            throw new Exception('No readable files found for source');
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
            $extractionError = null;
            $content = $this->extractText($file, $extractionError);
            if ($content === '') {
                if ($extractionError !== null) {
                    $extractionErrors[] = $relativePath . ': ' . $extractionError;
                }
                continue;
            }
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
            
            // Update progress: 5% to 30% during extraction
            $extractProgress = 5 + (int)(($fileIndex + 1) / $totalFiles * 25);
            $this->updateProgress($source, 'extracting', $extractProgress, $fileIndex + 1, $totalFiles);
        }

        if (count($chunks) === 0) {
            if (count($extractionErrors) > 0) {
                $lastExtractionError = $extractionErrors[count($extractionErrors) - 1];
                throw new Exception('Unable to extract text content for ingestion. Last extraction error: ' . $lastExtractionError);
            }
            throw new Exception('Unable to extract text content for ingestion');
        }

        // Stage 3: Chunking complete, check checksum (30-35%)
        $this->updateProgress($source, 'chunking', 30, count($chunks), count($chunks));
        
        $newChecksum = sha1($checksumSeed);
        if (!$force && $source->getChecksum() !== null && $source->getChecksum() === $newChecksum) {
            // Content unchanged, skip embedding
            $source->setStatus('ready');
            $source->setUpdatedAt(time());
            $source->setLastIndexedAt(time());
            $source->setProgress(100);
            $source->setProgressStage('ready');
            $this->botSourceMapper->update($source);
            return;
        }
        
        $this->updateProgress($source, 'chunking', 35, count($chunks), count($chunks));

        // Generate and store embeddings
        $this->generateAndStoreEmbeddings($source, $chunks, $newChecksum);
    }

    /**
     * Generate embeddings and store them in the database
     * 
     * @param array<int,array{text:string,metadata:array<string,mixed>}> $chunks
     */
    private function generateAndStoreEmbeddings(BotSource $source, array $chunks, string $newChecksum): void {
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
            $batchEmbeddings = $this->embeddingClient->embedTexts($batchTexts, $embeddingModel);
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
        $source->setStatus('ready');
        $source->setLastIndexedAt(time());
        $source->setUpdatedAt(time());
        $source->setProgress(100);
        $source->setProgressStage('ready');
        $source->setProgressCurrent($totalChunks);
        $source->setProgressTotal($totalChunks);
        $this->botSourceMapper->update($source);
    }

    /**
     * @return array<int,array{0:File,1:string}>
     */
    private function collectFiles(Node $node, string $basePath): array {
        $result = [];
        if ($node instanceof File) {
            $result[] = [$node, $this->relativePath($node, $basePath)];
            return $result;
        }

        if ($node instanceof Folder) {
            foreach ($node->getDirectoryListing() as $child) {
                if ($child instanceof Folder) {
                    $result = array_merge($result, $this->collectFiles($child, $basePath));
                } elseif ($child instanceof File) {
                    $result[] = [$child, $this->relativePath($child, $basePath)];
                }
            }
        }

        return $result;
    }

    private function extractText(File $file, ?string &$extractionError = null): string {
        $mime = $file->getMimeType();
        $extractionError = null;
        
        // First, try Docling for supported binary formats (PDF, DOCX, etc.)
        if ($this->doclingClient->isEnabled() && $this->doclingClient->isSupported($file)) {
            try {
                $this->logger->debug('Using Docling for file extraction', [
                    'file' => $file->getName(),
                    'mime' => $mime,
                ]);
                $markdown = $this->doclingClient->convertToMarkdown($file);
                // Ensure valid UTF-8
                return mb_convert_encoding($markdown, 'UTF-8', 'UTF-8');
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
        try {
            if (strpos($mime, 'text/') === 0 || in_array($mime, ['application/json', 'application/xml'], true)) {
                $content = (string)$file->getContent();
                // Ensure valid UTF-8 to avoid json_encode errors
                return mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to read file during ingestion', [
                'path' => $file->getPath(),
                'exception' => $e,
            ]);
            $extractionError = $e->getMessage();
        }

        return '';
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

    private function resolveTargetNode(BotSource $source, Folder $userFolder): Node {
        $nodes = $userFolder->getById($source->getNodeId());
        if (is_array($nodes) && count($nodes) > 0) {
            $node = $nodes[0];
            if ($node instanceof Node) {
                return $node;
            }
        }

        throw new Exception('Unable to resolve file or folder for ingestion');
    }

    private function relativePath(Node $node, string $rootPath): string {
        $fullPath = $node->getPath();
        if (str_starts_with($fullPath, $rootPath)) {
            return ltrim(substr($fullPath, strlen($rootPath)), '/');
        }
        return $node->getName();
    }
}
