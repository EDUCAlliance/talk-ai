<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
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
    private const TEST_CONVERSION_TIMEOUT = 60;
    private const DISCOVERY_TIMEOUT = 10;
    private const MAX_DISCOVERY_BYTES = 2097152;
    private const MAX_LABEL_LENGTH = 80;
    private const SERVICE_LABELS = [
        'docling_serve' => 'Docling Serve',
        'legacy' => 'AcademicCloud / EDUC',
    ];
    private const SETTINGS_UNREADABLE = 'Unable to read Docling settings. Check the saved configuration.';
    private const NOT_DETECTED = 'No Docling API was detected at this address. Check the URL or enter the full conversion endpoint.';

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
        LoggerInterface $logger,
        private ?IL10N $l10n = null,
    ) {
        $this->clientService = $clientService;
        $this->settingsService = $settingsService;
        $this->logger = $logger;
    }

    /**
     * Fixed diagnostics are shown to administrators, so they are translated
     * when a translator is available. Parameters are HTTP status codes only.
     *
     * @param list<int|string> $parameters
     */
    private function message(string $text, array $parameters = []): string {
        return $this->l10n !== null ? $this->l10n->t($text, $parameters) : vsprintf($text, $parameters);
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
            return ['success' => false, 'error' => $this->message(self::SETTINGS_UNREADABLE)];
        }
        $config['docling_api_endpoint'] = $endpoint ?? $config['docling_api_endpoint'] ?? '';
        $config['api_key'] = $apiKey ?? $config['api_key'] ?? '';

        try {
            $this->convertContent(
                $config,
                'educai-docling-test.pdf',
                $this->buildTestPdf(),
                'application/pdf',
                self::TEST_CONVERSION_TIMEOUT
            );
            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fingerprint and short label of what testConnection() would test, with the
     * same precedence for unsaved values. Keys are only ever hashed.
     *
     * @return array{fingerprint: ?string, summary: ?string}
     */
    public function connectionCheckTarget(?string $endpoint = null, ?string $apiKey = null, ?string $profile = null, ?string $authMode = null, bool $ignoreStoredKey = false): array {
        try {
            $config = $this->settingsService->getDoclingConfig($profile, $authMode);
            $profile = (string)$config['docling_api_profile'];
            $authMode = (string)$config['docling_auth_mode'];
            if ($apiKey === null && $ignoreStoredKey) {
                // Without the dedicated key only legacy Bearer falls back to the main API key.
                $apiKey = $profile === 'legacy' && $authMode === 'bearer' ? $this->settingsService->getApiKey() : '';
            }
        } catch (\Throwable $e) {
            return ['fingerprint' => null, 'summary' => null];
        }
        $endpoint = (string)($endpoint ?? $config['docling_api_endpoint'] ?? '');
        $key = $authMode === 'none' ? '' : trim((string)($apiKey ?? $config['api_key'] ?? ''));
        try {
            $url = $this->resolveEndpoint($endpoint, $profile);
        } catch (Exception $e) {
            $url = trim($endpoint);
        }

        return [
            'fingerprint' => ConnectionCheckStore::fingerprint('docling', [$url, $profile, $authMode], $key),
            'summary' => (self::SERVICE_LABELS[$profile] ?? $profile) . ' · ' . $authMode,
        ];
    }

    /**
     * Detect the API profile and authentication behind a saved or unsaved
     * Docling URL, then prove the result with one small test conversion.
     * Settings are never changed. Upstream bodies, keys and query strings are
     * never returned; all errors are fixed diagnostics.
     *
     * @param string|null $endpoint URL entered by the administrator; blank selects the AcademicCloud default
     * @param string|null $apiKey Unsaved Docling key; blank uses the stored Docling key
     * @param bool $ignoreStoredKey The stored Docling key is about to be removed and must not be used
     * @return array{success: bool, profile: ?string, auth_mode: ?string, service: ?string, title: ?string, version: ?string, source: ?string, endpoint: string, conversion_url: ?string, duration_ms: ?int, error: ?string}
     * @throws \InvalidArgumentException For an invalid URL or key, before any request is made
     */
    public function detect(?string $endpoint = null, ?string $apiKey = null, bool $ignoreStoredKey = false): array {
        $input = trim((string)$endpoint);
        $typedKey = trim((string)$apiKey);
        if (preg_match('/[\x00-\x1F\x7F]/', $typedKey)) {
            throw new \InvalidArgumentException($this->message('The Docling API key contains invalid control characters.'));
        }
        $parsed = $input === '' ? null : $this->parseEndpoint($input);

        $result = [
            'success' => false, 'profile' => null, 'auth_mode' => null, 'service' => null, 'title' => null,
            'version' => null, 'source' => null, 'endpoint' => $input, 'conversion_url' => null,
            'duration_ms' => null, 'error' => null,
        ];
        try {
            // The profile-independent lookup returns only a dedicated Docling key, never the main API key.
            $serveKey = $typedKey !== '' || $ignoreStoredKey ? $typedKey
                : trim((string)($this->settingsService->getDoclingConfig('docling_serve', 'x_api_key')['api_key'] ?? ''));
        } catch (\Throwable $e) {
            return ['error' => $this->message(self::SETTINGS_UNREADABLE)] + $result;
        }
        // Legacy Bearer falls back to the main API key exactly like the saved configuration does.
        $legacyKey = fn (): string => $typedKey !== '' ? $typedKey
            : trim((string)($ignoreStoredKey
                ? $this->settingsService->getApiKey()
                : ($this->settingsService->getDoclingConfig('legacy', 'bearer')['api_key'] ?? '')));

        if ($parsed === null) {
            return $this->detectionResult($result, 'legacy', 'default', '',
                $this->attemptConversion('legacy', 'bearer', '', $legacyKey));
        }
        [$parts, $path, $decodedPath] = $parsed;
        if (str_ends_with($decodedPath, '/v1/convert/file') || str_ends_with($decodedPath, '/v1/convert')) {
            return $this->detectionResult($result, 'docling_serve', 'path', $input, $this->attemptServeConversion($input, $serveKey));
        }
        if (str_ends_with($decodedPath, '/v1/documents/convert')) {
            return $this->detectionResult($result, 'legacy', 'path', $input,
                $this->attemptConversion('legacy', 'bearer', $input, $legacyKey));
        }

        while (preg_match('~/(?:v1|docs|openapi\.json)$~', $path) === 1) {
            $path = rtrim(substr($path, 0, (int)strrpos($path, '/')), '/');
        }
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
        // Never send a key with control characters as a discovery header; the conversion reports it safely.
        $discovered = $this->discover($base, preg_match('/[\x00-\x1F\x7F]/', $serveKey) ? '' : $serveKey);
        if ($discovered === null) {
            return ['error' => $this->message('Docling could not be reached. Check the server address, connectivity and TLS configuration.')] + $result;
        }
        if ($discovered['profile'] !== null) {
            $profile = $discovered['profile'];
            $storedEndpoint = $this->detectedEndpoint($input, $base, $profile, $discovered['route'], $parts['query'] ?? null);
            $attempt = $profile === 'docling_serve'
                ? $this->attemptServeConversion($storedEndpoint, $serveKey)
                : $this->attemptConversion('legacy', 'bearer', $storedEndpoint, $legacyKey);
            return $this->detectionResult(['title' => $discovered['title'], 'version' => $discovered['version']] + $result,
                $profile, $discovered['source'], $storedEndpoint, $attempt);
        }

        // Probe Docling Serve first. Try legacy only after a definite "no such route"
        // answer, never after timeouts, 5xx or authentication failures.
        $serve = $this->attemptServeConversion($input, $serveKey);
        if ($serve['success'] || !in_array($serve['status'], [404, 405], true)) {
            return $this->detectionResult($result, $this->routeAnswered($serve) ? 'docling_serve' : null, 'probe', $input, $serve);
        }
        $legacy = $this->attemptConversion('legacy', 'bearer', $input, $legacyKey);
        return $this->detectionResult($result, $this->routeAnswered($legacy) ? 'legacy' : null, 'probe', $input, $legacy);
    }

    /**
     * Query API metadata below $base: OpenAPI paths first, then Docling Serve's /version.
     *
     * @return array{profile: ?string, source: ?string, title: ?string, version: ?string, route: string}|null Null when the server could not be reached
     */
    private function discover(string $base, string $key): ?array {
        $openApi = $this->fetchDiscoveryJson($base . '/openapi.json', $key);
        if ($openApi === false) {
            return null;
        }
        if (is_array($openApi) && is_array($openApi['paths'] ?? null)) {
            $serve = false;
            $legacyRoute = null;
            foreach (array_keys($openApi['paths']) as $route) {
                if (!is_string($route)) {
                    continue;
                }
                $route = rtrim($route, '/');
                if (str_ends_with($route, '/v1/convert/file')) {
                    $serve = true;
                } elseif ($legacyRoute === null && str_ends_with($route, '/v1/documents/convert')) {
                    $legacyRoute = $route;
                }
            }
            if ($serve || $legacyRoute !== null) {
                $info = is_array($openApi['info'] ?? null) ? $openApi['info'] : [];
                return [
                    // Docling Serve wins when a server documents both routes.
                    'profile' => $serve ? 'docling_serve' : 'legacy',
                    'source' => 'openapi',
                    'title' => $this->cleanLabel($info['title'] ?? null),
                    'version' => $this->cleanLabel($info['version'] ?? null),
                    // Only plain path segments from a remote document may become part of a URL.
                    'route' => $serve ? '/v1/convert/file'
                        : (preg_match('~^(?:/[A-Za-z0-9_\-]+)*/v1/documents/convert$~D', $legacyRoute) === 1 ? $legacyRoute : '/v1/documents/convert'),
                ];
            }
        }

        $version = $this->fetchDiscoveryJson($base . '/version', $key);
        if ($version === false) {
            return null;
        }
        if (is_array($version) && is_string($version['docling-serve'] ?? null)) {
            return [
                'profile' => 'docling_serve', 'source' => 'version', 'title' => null,
                'version' => $this->cleanLabel($version['docling-serve']), 'route' => '/v1/convert/file',
            ];
        }

        return ['profile' => null, 'source' => null, 'title' => null, 'version' => null, 'route' => ''];
    }

    /**
     * One bounded metadata GET. An authentication rejection is retried once with
     * X-Api-Key and once with a Bearer token when a key is available.
     *
     * @return array<mixed>|false|null Decoded JSON object; null when unavailable; false when unreachable
     */
    private function fetchDiscoveryJson(string $url, string $key): array|false|null {
        $authentication = [[]];
        if ($key !== '') {
            $authentication[] = ['X-Api-Key' => $key];
            $authentication[] = ['Authorization' => 'Bearer ' . $key];
        }
        foreach ($authentication as $headers) {
            try {
                $response = $this->clientService->newClient()->get($url, [
                    'headers' => ['Accept' => 'application/json'] + $headers,
                    'timeout' => self::DISCOVERY_TIMEOUT,
                    'allow_redirects' => false,
                    'http_errors' => false,
                ]);
                $status = $response->getStatusCode();
                if ($status === 401 || $status === 403) {
                    continue;
                }
                if ($status < 200 || $status >= 300) {
                    return null;
                }
                $body = (string)$response->getBody();
            } catch (\Throwable $e) {
                // Transport errors can contain URLs and credentials; they are never surfaced.
                return false;
            }
            if (strlen($body) > self::MAX_DISCOVERY_BYTES) {
                return null;
            }
            $payload = json_decode($body, true);
            return is_array($payload) && !array_is_list($payload) ? $payload : null;
        }

        return null;
    }

    /**
     * The value an administrator should store: the input, unless it would not
     * resolve to the route discovered below $base (for example a /docs URL, or
     * a legacy route behind a path prefix that is never expanded).
     */
    private function detectedEndpoint(string $input, string $base, string $profile, string $route, ?string $query): string {
        $suffix = $query !== null && $query !== '' ? '?' . $query : '';
        try {
            if ($this->resolveEndpoint($input, $profile) === $base . $route . $suffix) {
                return $input;
            }
        } catch (Exception $e) {
            // Use the discovered route below.
        }

        return ($profile === 'docling_serve' ? $base : $base . $route) . $suffix;
    }

    /**
     * Docling Serve uses X-Api-Key when a key is available. A gateway that rejects
     * it is retried once with Bearer; no request is repeated after any other failure.
     *
     * @return array{success: bool, auth_mode: string, status: ?int, error: ?string, duration_ms: ?int, conversion_url: ?string}
     */
    private function attemptServeConversion(string $endpoint, string $key): array {
        $attempt = $this->attemptConversion('docling_serve', $key !== '' ? 'x_api_key' : 'none', $endpoint, fn (): string => $key);
        if ($attempt['auth_mode'] === 'x_api_key' && in_array($attempt['status'], [401, 403], true)) {
            $bearer = $this->attemptConversion('docling_serve', 'bearer', $endpoint, fn (): string => $key);
            if ($bearer['success'] || !in_array($bearer['status'], [401, 403], true)) {
                return $bearer;
            }
        }

        return $attempt;
    }

    /**
     * One test conversion through the shared adapter. The HTTP status, when the
     * server answered, classifies a failure; the message is a fixed diagnostic.
     *
     * @param callable():string $key
     * @return array{success: bool, auth_mode: string, status: ?int, error: ?string, duration_ms: ?int, conversion_url: ?string}
     */
    private function attemptConversion(string $profile, string $authMode, string $endpoint, callable $key): array {
        $attempt = [
            'success' => false, 'auth_mode' => $authMode, 'status' => null, 'error' => null,
            'duration_ms' => null, 'conversion_url' => $this->displayUrl($endpoint, $profile),
        ];
        try {
            $apiKey = $authMode === 'none' ? '' : $key();
        } catch (\Throwable $e) {
            return ['error' => $this->message(self::SETTINGS_UNREADABLE)] + $attempt;
        }
        $started = microtime(true);
        try {
            $this->convertContent([
                'docling_api_profile' => $profile,
                'docling_auth_mode' => $authMode,
                'docling_api_endpoint' => $endpoint,
                'api_key' => $apiKey,
            ], 'educai-docling-test.pdf', $this->buildTestPdf(), 'application/pdf', self::TEST_CONVERSION_TIMEOUT);
            $attempt['success'] = true;
        } catch (Exception $e) {
            $status = $e->getCode();
            $attempt['status'] = $status >= 100 && $status < 600 ? $status : null;
            $attempt['error'] = $e->getMessage();
        }
        $attempt['duration_ms'] = (int)round((microtime(true) - $started) * 1000);

        return $attempt;
    }

    /**
     * Any HTTP answer other than "no such route" or a redirect identifies the route.
     *
     * @param array{success: bool, status: ?int} $attempt
     */
    private function routeAnswered(array $attempt): bool {
        $status = $attempt['status'];
        return $attempt['success']
            || ($status !== null && !in_array($status, [404, 405], true) && ($status < 300 || $status >= 400));
    }

    /**
     * @param array<string,mixed> $result
     * @param array{success: bool, auth_mode: string, status: ?int, error: ?string, duration_ms: ?int, conversion_url: ?string} $attempt
     * @return array{success: bool, profile: ?string, auth_mode: ?string, service: ?string, title: ?string, version: ?string, source: ?string, endpoint: string, conversion_url: ?string, duration_ms: ?int, error: ?string}
     */
    private function detectionResult(array $result, ?string $profile, string $source, string $endpoint, array $attempt): array {
        $error = null;
        if (!$attempt['success']) {
            $error = $profile === null && in_array($attempt['status'], [404, 405], true)
                ? $this->message(self::NOT_DETECTED) : ($attempt['error'] ?? $this->message(self::NOT_DETECTED));
        }

        return [
            'success' => $profile !== null && $attempt['success'],
            'profile' => $profile,
            'auth_mode' => $profile !== null ? $attempt['auth_mode'] : null,
            'service' => $profile !== null ? self::SERVICE_LABELS[$profile] : null,
            'source' => $profile !== null ? $source : null,
            'endpoint' => $endpoint,
            'conversion_url' => $attempt['conversion_url'],
            'duration_ms' => $attempt['duration_ms'],
            'error' => $error,
        ] + $result;
    }

    /** The resolved conversion URL without its query string, which may carry secrets. */
    private function displayUrl(string $endpoint, string $profile): ?string {
        try {
            $url = $this->resolveEndpoint($endpoint, $profile);
        } catch (Exception $e) {
            return null;
        }
        $query = strpos($url, '?');

        return $query === false ? $url : substr($url, 0, $query);
    }

    /** A short, printable label from remote metadata, never more than 80 characters. */
    private function cleanLabel(mixed $value): ?string {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $value = trim((string)preg_replace('/\p{C}+/u', '', (string)$value));

        return $value === '' ? null : mb_substr($value, 0, self::MAX_LABEL_LENGTH, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $config
     */
    private function convertContent(array $config, string $filename, string $content, string $mimeType, int $timeout): string {
        $profile = $config['docling_api_profile'] ?? 'legacy';
        $authMode = $config['docling_auth_mode'] ?? 'bearer';
        if (!in_array($profile, ['legacy', 'docling_serve'], true)
            || !in_array($authMode, ['bearer', 'x_api_key', 'none'], true)) {
            throw new Exception($this->message('Invalid Docling API profile or authentication mode. Check the Docling settings.'));
        }
        $endpoint = $this->resolveEndpoint((string)($config['docling_api_endpoint'] ?? ''), $profile);
        $headers = ['Accept' => 'application/json'];
        if ($authMode !== 'none') {
            $apiKey = trim((string)($config['api_key'] ?? ''));
            if ($apiKey === '') {
                throw new Exception($this->message('API key not configured for Docling. Supply a Docling key or select no authentication.'));
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $apiKey)) {
                throw new Exception($this->message('The Docling API key contains invalid control characters.'));
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
                throw new Exception($this->message('Docling conversion timed out. The server may still be processing it; no automatic retry was made. Check its synchronous conversion limit.'));
            }
            throw new Exception($this->message('Docling request failed. Check the server address, connectivity and TLS configuration. The conversion was not retried.'));
        }
        // Exception codes carry the HTTP status of an answered request (never its body).
        if ($status < 200 || $status >= 300) {
            throw new Exception(match (true) {
                $status === 401 || $status === 403 => $this->message('Docling authentication failed (HTTP %s). Check the authentication mode and Docling API key.', [$status]),
                $status === 404 => $this->message('Docling endpoint not found (HTTP 404). Check the API profile and conversion URL.'),
                $status === 422 => $this->message('Docling rejected the upload (HTTP 422). Check the API profile and use the file-upload endpoint, not /v1/convert/source.'),
                $status === 408 || $status === 504 => $this->message('Docling conversion timed out (HTTP %s). The server may still be processing it; no automatic retry was made. Check its synchronous conversion limit.', [$status]),
                $status === 413 => $this->message('Docling rejected the document size (HTTP 413). Check the server upload limit.'),
                $status >= 300 && $status < 400 => $this->message('Docling returned a redirect. Configure the final conversion URL; redirects are not followed.'),
                default => $this->message('Docling returned HTTP %s. Check the server logs; the conversion was not retried.', [$status]),
            }, $status);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new Exception($this->message('Docling did not return a JSON object. Check the API profile and inline output configuration.'), $status);
        }
        $conversionStatus = $payload['status'] ?? null;
        if ($conversionStatus === 'partial_success') {
            throw new Exception($this->message('Docling returned a partial conversion. Incomplete text was rejected; check the server logs.'), $status);
        }
        if (isset($payload['detail']) || isset($payload['error']) || ($payload['errors'] ?? []) !== []) {
            throw new Exception($this->message('Docling reported conversion errors. Incomplete text was rejected; check the server logs.'), $status);
        }
        if (($profile === 'docling_serve' || isset($payload['status'])) && $conversionStatus !== 'success') {
            throw new Exception($this->message('Docling did not report a successful conversion. Check the API profile and server logs.'), $status);
        }
        $markdown = $profile === 'docling_serve' ? ($payload['document']['md_content'] ?? null) : ($payload['markdown'] ?? null);
        if (!is_string($markdown) || trim($markdown) === '') {
            throw new Exception($this->message('Docling returned no Markdown content. Check the API profile and inline output configuration.'), $status);
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
            throw new Exception($this->message('Configure the Docling Serve server URL or its /v1/convert/file endpoint.'));
        }
        [$parts, $path, $decodedPath] = $this->parseEndpoint($endpoint);
        if ($profile === 'docling_serve') {
            if (str_ends_with($decodedPath, '/v1/documents/convert')) {
                throw new Exception($this->message('This URL uses the legacy conversion endpoint. Select the AcademicCloud / EDUC profile or use /v1/convert/file.'));
            }
            if (!str_ends_with($path, '/v1/convert/file')) {
                $path .= str_ends_with($path, '/v1/convert') ? '/file'
                    : (str_ends_with($path, '/v1') ? '/convert/file' : '/v1/convert/file');
            }
        } else {
            if (str_ends_with($decodedPath, '/v1/convert/file')) {
                throw new Exception($this->message('This URL uses Docling Serve. Select the Docling Serve API profile.'));
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

    /**
     * URL checks shared by conversion and detection. Messages never echo the URL.
     *
     * @return array{0: array<string,mixed>, 1: string, 2: string} URL parts, path without trailing slash, decoded path
     * @throws \InvalidArgumentException
     */
    private function parseEndpoint(string $endpoint): array {
        $parts = parse_url($endpoint);
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException($this->message('Docling endpoint must be an HTTP(S) URL without embedded credentials or a fragment.'));
        }
        $path = rtrim($parts['path'] ?? '', '/');
        $decodedPath = rawurldecode($path);
        if (preg_match('~/v1/convert/source(?:/(?:async|batch))?$~', $decodedPath)) {
            throw new \InvalidArgumentException($this->message('The Docling /source endpoint expects JSON. Select Docling Serve and use /v1/convert/file for file uploads.'));
        }
        if (str_ends_with($decodedPath, '/async')) {
            throw new \InvalidArgumentException($this->message('Async Docling endpoints are not supported. Use the synchronous /v1/convert/file endpoint.'));
        }

        return [$parts, $path, $decodedPath];
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
