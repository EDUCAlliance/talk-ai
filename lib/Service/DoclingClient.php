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
        return $config['docling_enabled']
            && (($config['docling_auth_mode'] ?? 'bearer') === 'none' || !empty($config['api_key']));
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

        $this->logger->info('Converting document via Docling', [
            'file' => $filename,
            'mimeType' => $mimeType,
            'size' => strlen($content),
        ]);

        try {
            $markdown = $this->convertContent($config, $filename, $content, $mimeType, $this->getConversionTimeout(strlen($content)));

            $this->logger->info('Document converted successfully', [
                'file' => $filename,
                'markdownLength' => strlen($markdown),
            ]);

            return $markdown;

        } catch (Exception $e) {
            $this->logger->error('Docling conversion failed', [
                'file' => $filename,
                'error' => $e->getMessage(),
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
     * @param string|null $profile Optional unsaved API profile
     * @param string|null $authMode Optional unsaved authentication mode
     * @return array{success: bool, error?: string}
     */
    public function testConnection(?string $endpoint = null, ?string $apiKey = null, ?string $profile = null, ?string $authMode = null): array {
        try {
            $config = $this->settingsService->getDoclingConfig($profile, $authMode);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Unable to read Docling settings. Check the saved configuration.'];
        }
        $config['docling_api_endpoint'] = $endpoint ?? $config['docling_api_endpoint'] ?? '';
        $config['api_key'] = $apiKey ?? $config['api_key'] ?? '';

        try {
            $this->convertContent(
                $config,
                'educai-docling-test.pdf',
                $this->buildTestPdf(),
                'application/pdf',
                60
            );
            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function convertContent(array $config, string $filename, string $content, string $mimeType, int $timeout): string {
        $profile = $config['docling_api_profile'] ?? 'legacy';
        $authMode = $config['docling_auth_mode'] ?? 'bearer';
        if (!in_array($profile, ['legacy', 'docling_serve'], true)
            || !in_array($authMode, ['bearer', 'x_api_key', 'none'], true)) {
            throw new Exception('Invalid Docling API profile or authentication mode. Check the Docling settings.');
        }
        $endpoint = $this->resolveEndpoint((string)($config['docling_api_endpoint'] ?? ''), $profile);
        $headers = ['Accept' => 'application/json'];
        if ($authMode !== 'none') {
            $apiKey = trim((string)($config['api_key'] ?? ''));
            if ($apiKey === '') {
                throw new Exception('API key not configured for Docling. Supply a Docling key or select no authentication.');
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $apiKey)) {
                throw new Exception('The Docling API key contains invalid control characters.');
            }
            $headers[$authMode === 'bearer' ? 'Authorization' : 'X-Api-Key'] = ($authMode === 'bearer' ? 'Bearer ' : '') . $apiKey;
        }
        $multipart = [[
            'name' => $profile === 'docling_serve' ? 'files' : 'document',
            'contents' => $content,
            'filename' => $filename,
            'headers' => ['Content-Type' => $mimeType],
        ]];
        if ($profile === 'docling_serve') {
            $multipart[] = ['name' => 'to_formats', 'contents' => 'md'];
            $multipart[] = ['name' => 'target_type', 'contents' => 'inbody'];
        }

        try {
            // A timeout/5xx can leave a conversion running; never resubmit or follow redirects.
            $response = $this->clientService->newClient()->post($endpoint, [
                'headers' => $headers,
                'multipart' => $multipart,
                'timeout' => $timeout,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $body = (string)$response->getBody();
        } catch (Exception $e) {
            // HTTP-client exceptions can contain credentials, URLs and document content.
            if (preg_match('/cURL error 28|timed out/i', $e->getMessage())) {
                throw new Exception('Docling conversion timed out. The server may still be processing it; no automatic retry was made. Check its synchronous conversion limit.');
            }
            throw new Exception('Docling request failed. Check the server address, connectivity and TLS configuration. The conversion was not retried.');
        }
        if ($status < 200 || $status >= 300) {
            throw new Exception(match (true) {
                $status === 401 || $status === 403 => 'Docling authentication failed (HTTP ' . $status . '). Check the authentication mode and Docling API key.',
                $status === 404 => 'Docling endpoint not found (HTTP 404). Check the API profile and conversion URL.',
                $status === 422 => 'Docling rejected the upload (HTTP 422). Check the API profile and use the file-upload endpoint, not /v1/convert/source.',
                $status === 408 || $status === 504 => 'Docling conversion timed out (HTTP ' . $status . '). The server may still be processing it; no automatic retry was made. Check its synchronous conversion limit.',
                $status === 413 => 'Docling rejected the document size (HTTP 413). Check the server upload limit.',
                $status >= 300 && $status < 400 => 'Docling returned a redirect. Configure the final conversion URL; redirects are not followed.',
                default => 'Docling returned HTTP ' . $status . '. Check the server logs; the conversion was not retried.',
            });
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new Exception('Docling did not return a JSON object. Check the API profile and inline output configuration.');
        }
        $conversionStatus = $payload['status'] ?? null;
        if ($conversionStatus === 'partial_success') {
            throw new Exception('Docling returned a partial conversion. Incomplete text was rejected; check the server logs.');
        }
        if (isset($payload['detail']) || isset($payload['error']) || ($payload['errors'] ?? []) !== []) {
            throw new Exception('Docling reported conversion errors. Incomplete text was rejected; check the server logs.');
        }
        if (($profile === 'docling_serve' || isset($payload['status'])) && $conversionStatus !== 'success') {
            throw new Exception('Docling did not report a successful conversion. Check the API profile and server logs.');
        }
        $markdown = $profile === 'docling_serve' ? ($payload['document']['md_content'] ?? null) : ($payload['markdown'] ?? null);
        if (!is_string($markdown) || trim($markdown) === '') {
            throw new Exception('Docling returned no Markdown content. Check the API profile and inline output configuration.');
        }

        // Legacy EDUC warnings include informational backend notices, not just failures.
        $warnings = $payload['warnings'] ?? $payload['metadata']['warnings'] ?? [];
        if (!empty($warnings)) {
            $this->logger->warning('Docling conversion returned warnings; consult the conversion server logs', [
                'warningCount' => is_array($warnings) ? count($warnings) : 1,
            ]);
        }

        return $markdown;
    }

    private function resolveEndpoint(string $endpoint, string $profile): string {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            if ($profile === 'legacy') {
                return self::DEFAULT_ENDPOINT;
            }
            throw new Exception('Configure the Docling Serve server URL or its /v1/convert/file endpoint.');
        }
        $parts = parse_url($endpoint);
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new Exception('Docling endpoint must be an HTTP(S) URL without embedded credentials or a fragment.');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        $decodedPath = rawurldecode($path);
        if (preg_match('~/v1/convert/source(?:/(?:async|batch))?$~', $decodedPath)) {
            throw new Exception('The Docling /source endpoint expects JSON. Select Docling Serve and use /v1/convert/file for file uploads.');
        }
        if (str_ends_with($decodedPath, '/async')) {
            throw new Exception('Async Docling endpoints are not supported. Use the synchronous /v1/convert/file endpoint.');
        }
        if ($profile === 'docling_serve') {
            if (str_ends_with($decodedPath, '/v1/documents/convert')) {
                throw new Exception('This URL uses the legacy conversion endpoint. Select the AcademicCloud / EDUC profile or use /v1/convert/file.');
            }
            if (!str_ends_with($path, '/v1/convert/file')) {
                $path .= str_ends_with($path, '/v1/convert') ? '/file'
                    : (str_ends_with($path, '/v1') ? '/convert/file' : '/v1/convert/file');
            }
        } else {
            if (str_ends_with($decodedPath, '/v1/convert/file')) {
                throw new Exception('This URL uses Docling Serve. Select the Docling Serve API profile.');
            }
            // Preserve existing custom legacy routes; only expand an origin or /v1 base.
            if ($path === '' || str_ends_with($path, '/v1')) {
                $path .= $path === '' ? '/v1/documents/convert' : '/documents/convert';
            } else {
                $path = $parts['path'];
            }
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function getConversionTimeout(int $contentSize): int {
        $megabytes = (int)ceil($contentSize / (1024 * 1024));
        $timeout = self::BASE_CONVERSION_TIMEOUT + max(0, $megabytes - 2) * 60;
        return min(self::MAX_CONVERSION_TIMEOUT, $timeout);
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
