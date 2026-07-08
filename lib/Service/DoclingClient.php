<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Client for the Docling document conversion API.
 * 
 * Converts PDF, DOCX, PPTX, and other binary documents to Markdown
 * for RAG ingestion.
 */
class DoclingClient {
    private const DEFAULT_ENDPOINT = 'https://chat-ai.academiccloud.de/v1/documents/convert';
    private const MAX_CONVERSION_ATTEMPTS = 3;
    private const RETRY_BACKOFF_SECONDS = [1, 2];
    private const BASE_CONVERSION_TIMEOUT = 120;
    private const MAX_CONVERSION_TIMEOUT = 600;
    
    /**
     * Supported MIME types for Docling conversion
     */
    private const SUPPORTED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // docx
        'application/vnd.openxmlformats-officedocument.presentationml.presentation', // pptx
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
        'application/msword', // doc
        'application/vnd.ms-powerpoint', // ppt
        'application/vnd.ms-excel', // xls
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/tiff',
        'image/bmp',
    ];

    /**
     * Supported file extensions for Docling conversion
     */
    private const SUPPORTED_EXTENSIONS = [
        'pdf',
        'docx',
        'doc',
        'pptx',
        'ppt',
        'xlsx',
        'xls',
        'png',
        'jpg',
        'jpeg',
        'tiff',
        'bmp',
    ];

    private IClientService $clientService;
    private SettingsService $settingsService;
    private LoggerInterface $logger;

    public function __construct(
        IClientService $clientService,
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
        $this->settingsService = $settingsService;
        $this->logger = $logger;
    }

    /**
     * Check if Docling document conversion is enabled
     */
    public function isEnabled(): bool {
        $config = $this->settingsService->getDoclingConfig();
        return $config['docling_enabled'] && !empty($config['api_key']);
    }

    /**
     * Check if a file is supported by Docling
     */
    public function isSupported(File $file): bool {
        $mimeType = $file->getMimeType();
        if (in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            return true;
        }

        // Also check by extension as MIME detection may not be perfect
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Get the list of supported MIME types
     * 
     * @return array<int,string>
     */
    public function getSupportedMimeTypes(): array {
        return self::SUPPORTED_MIME_TYPES;
    }

    /**
     * Get the list of supported file extensions
     * 
     * @return array<int,string>
     */
    public function getSupportedExtensions(): array {
        return self::SUPPORTED_EXTENSIONS;
    }

    /**
     * Convert binary document content to Markdown using the configured Docling API.
     *
     * @throws Exception If conversion fails
     */
    public function convertBinaryToMarkdown(string $filename, string $content, string $mimeType): string {
        $config = $this->settingsService->getDoclingConfig();

        if (!$config['docling_enabled']) {
            throw new Exception('Docling document conversion is disabled');
        }

        $apiKey = $config['api_key'];
        if (empty($apiKey)) {
            throw new Exception('API key not configured for Docling');
        }

        $endpoint = $config['docling_api_endpoint'];
        if (empty($endpoint)) {
            $endpoint = self::DEFAULT_ENDPOINT;
        }

        $this->logger->info('Converting document via Docling', [
            'file' => $filename,
            'mimeType' => $mimeType,
            'size' => strlen($content),
        ]);

        try {
            $payload = $this->convertContent($endpoint, $apiKey, $filename, $content, $mimeType, $this->getConversionTimeout(strlen($content)));
            $markdown = $this->extractMarkdown($payload, $filename);

            $this->logger->info('Document converted successfully', [
                'file' => $filename,
                'markdownLength' => strlen($markdown),
            ]);

            return $markdown;

        } catch (Exception $e) {
            $this->logger->error('Docling conversion failed', [
                'file' => $filename,
                'exception' => $e,
            ]);
            throw new Exception('Failed to convert document: ' . $e->getMessage());
        }
    }

    /**
     * Convert a document to Markdown using the Docling API
     * 
     * @param File $file The file to convert
     * @return string The markdown content
     * @throws Exception If conversion fails
     */
    public function convertToMarkdown(File $file): string {
        return $this->convertBinaryToMarkdown($file->getName(), (string)$file->getContent(), $file->getMimeType());
    }

    /**
     * Test connection to the Docling API
     * 
     * @param string|null $endpoint Optional custom endpoint to test
     * @param string|null $apiKey Optional API key to use
     * @return array{success: bool, error?: string}
     */
    public function testConnection(?string $endpoint = null, ?string $apiKey = null): array {
        $config = $this->settingsService->getDoclingConfig();
        
        $testEndpoint = $endpoint ?? $config['docling_api_endpoint'] ?? self::DEFAULT_ENDPOINT;
        $testApiKey = $apiKey ?? $config['api_key'];

        if (empty($testApiKey)) {
            return ['success' => false, 'error' => 'API key not configured'];
        }

        try {
            $payload = $this->convertContent(
                $testEndpoint,
                $testApiKey,
                'educai-docling-test.pdf',
                $this->buildTestPdf(),
                'application/pdf',
                60
            );
            $this->extractMarkdown($payload, 'educai-docling-test.pdf');
            return ['success' => true];

        } catch (Exception $e) {
            $message = $e->getMessage();

            // Auth failed or network error
            if (strpos($message, '401') !== false || strpos($message, '403') !== false) {
                return ['success' => false, 'error' => 'Authentication failed - check API key'];
            }

            return ['success' => false, 'error' => $message];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function convertContent(string $endpoint, string $apiKey, string $filename, string $content, string $mimeType, int $timeout): array {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_CONVERSION_ATTEMPTS) {
            $attempt++;

            try {
                $client = $this->clientService->newClient();
                $response = $client->post($endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Accept' => 'application/json',
                    ],
                    'multipart' => [
                        [
                            'name' => 'document',
                            'contents' => $content,
                            'filename' => $filename,
                            'headers' => [
                                'Content-Type' => $mimeType,
                            ],
                        ],
                    ],
                    'timeout' => $timeout,
                ]);

                $payload = json_decode($response->getBody(), true);
                if (!is_array($payload)) {
                    throw new Exception('Invalid response from Docling API');
                }

                if (isset($payload['detail'])) {
                    throw new Exception('Docling API error: ' . $this->stringifyDetail($payload['detail']));
                }

                return $payload;
            } catch (Exception $e) {
                $lastException = $e;

                if (!$this->shouldRetry($e, $timeout) || $attempt >= self::MAX_CONVERSION_ATTEMPTS) {
                    break;
                }

                $delay = self::RETRY_BACKOFF_SECONDS[$attempt - 1] ?? 2;
                $this->logger->warning('Docling conversion failed, retrying', [
                    'file' => $filename,
                    'attempt' => $attempt,
                    'nextAttempt' => $attempt + 1,
                    'delaySeconds' => $delay,
                    'error' => $e->getMessage(),
                ]);
                $this->sleepBeforeRetry($delay);
            }
        }

        throw $lastException ?? new Exception('Docling conversion failed');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractMarkdown(array $payload, string $filename): string {
        $markdown = $payload['markdown'] ?? null;
        if (!is_string($markdown) || trim($markdown) === '') {
            $this->logger->warning('Docling returned empty markdown', [
                'file' => $filename,
                'response' => $payload,
            ]);
            throw new Exception('Docling returned empty content for document');
        }

        return $markdown;
    }

    private function shouldRetry(Exception $e, int $timeout): bool {
        $message = $e->getMessage();
        if (preg_match('/\\b(500|502|503|504)\\b/', $message) === 1) {
            return true;
        }

        if (strpos($message, 'cURL error 28') !== false || strpos($message, 'Operation timed out') !== false) {
            return $timeout < 300;
        }

        return strpos($message, 'Connection refused') !== false
            || strpos($message, 'Connection reset') !== false;
    }

    private function stringifyDetail(mixed $detail): string {
        if (is_string($detail)) {
            return $detail;
        }

        $encoded = json_encode($detail);
        return $encoded !== false ? $encoded : 'unknown error';
    }

    private function getConversionTimeout(int $contentSize): int {
        $megabytes = (int)ceil($contentSize / (1024 * 1024));
        $timeout = self::BASE_CONVERSION_TIMEOUT + max(0, $megabytes - 2) * 60;
        return min(self::MAX_CONVERSION_TIMEOUT, $timeout);
    }

    protected function sleepBeforeRetry(int $seconds): void {
        sleep($seconds);
    }

    private function buildTestPdf(): string {
        $stream = "BT /F1 18 Tf 72 100 Td (EducAI Docling test) Tj ET";
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 160] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }
}
