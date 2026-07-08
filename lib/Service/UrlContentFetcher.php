<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Service for fetching and extracting text content from URLs.
 * 
 * Supports:
 * - HTML pages (extracts readable text, strips scripts/styles)
 * - JSON (pretty-prints for readability)
 * - PDF (uses DoclingClient for conversion)
 * - Plain text (used as-is)
 */
class UrlContentFetcher {
    private const MAX_CONTENT_SIZE = 10 * 1024 * 1024; // 10MB max
    private const REQUEST_TIMEOUT = 30;
    
    private IClientService $clientService;
    private DoclingClient $doclingClient;
    private LoggerInterface $logger;

    public function __construct(
        IClientService $clientService,
        DoclingClient $doclingClient,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
        $this->doclingClient = $doclingClient;
        $this->logger = $logger;
    }

    /**
     * Validate that a URL is allowed for fetching
     * 
     * @param string $url The URL to validate
     * @return bool True if URL is valid and allowed
     */
    public function isValidUrl(string $url): bool {
        $parsed = parse_url($url);
        
        if ($parsed === false) {
            return false;
        }
        
        // Only allow http and https
        $scheme = $parsed['scheme'] ?? '';
        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }
        
        // Must have a host
        if (empty($parsed['host'])) {
            return false;
        }
        
        // Block local/private addresses
        $host = $parsed['host'];
        if ($this->isPrivateHost($host)) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if a host is a private/local address
     */
    private function isPrivateHost(string $host): bool {
        // Block localhost
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        
        // Block common private network patterns
        if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $host)) {
            return true;
        }
        
        // Block .local domains
        if (str_ends_with(strtolower($host), '.local')) {
            return true;
        }
        
        return false;
    }

    /**
     * Fetch content from a URL and extract text
     * 
     * @param string $url The URL to fetch
     * @return array{content: string, content_type: string, title: string|null}
     * @throws Exception If fetching or extraction fails
     */
    public function fetchAndExtract(string $url): array {
        if (!$this->isValidUrl($url)) {
            throw new Exception('Invalid or disallowed URL');
        }

        $this->logger->info('Fetching URL content', ['url' => $url]);

        try {
            $client = $this->clientService->newClient();
            
            $response = $client->get($url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'User-Agent' => 'EducAI-Bot/1.0 (Nextcloud RAG Indexer)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml,application/json,application/pdf,text/plain,*/*',
                ],
                'allow_redirects' => [
                    'max' => 5,
                ],
            ]);

            $contentType = $response->getHeader('Content-Type');
            $body = $response->getBody();
            
            // Check content size
            if (strlen($body) > self::MAX_CONTENT_SIZE) {
                throw new Exception('Content exceeds maximum size limit of 10MB');
            }

            $this->logger->debug('URL content fetched', [
                'url' => $url,
                'content_type' => $contentType,
                'size' => strlen($body),
            ]);

            return $this->extractText($body, $contentType, $url);

        } catch (Exception $e) {
            $this->logger->error('Failed to fetch URL', [
                'url' => $url,
                'exception' => $e,
            ]);
            throw new Exception('Failed to fetch URL: ' . $e->getMessage());
        }
    }

    /**
     * Extract text from content based on content type
     * 
     * @return array{content: string, content_type: string, title: string|null}
     */
    private function extractText(string $body, string $contentType, string $url): array {
        $mimeType = $this->parseMimeType($contentType);
        $title = null;

        // PDF handling
        if ($mimeType === 'application/pdf' || str_ends_with(strtolower($url), '.pdf')) {
            return $this->extractFromPdf($body, $url);
        }

        // JSON handling
        if ($mimeType === 'application/json' || str_ends_with(strtolower($url), '.json')) {
            $content = $this->extractFromJson($body);
            return [
                'content' => $content,
                'content_type' => 'json',
                'title' => null,
            ];
        }

        // HTML handling
        if (str_contains($mimeType, 'html') || str_contains($mimeType, 'xhtml')) {
            return $this->extractFromHtml($body);
        }

        // XML handling
        if (str_contains($mimeType, 'xml')) {
            $content = $this->extractFromXml($body);
            return [
                'content' => $content,
                'content_type' => 'xml',
                'title' => null,
            ];
        }

        // Plain text or unknown - use as-is
        $content = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        return [
            'content' => $content,
            'content_type' => 'text',
            'title' => null,
        ];
    }

    /**
     * Parse MIME type from Content-Type header
     */
    private function parseMimeType(string $contentType): string {
        // Content-Type may include charset: "text/html; charset=utf-8"
        $parts = explode(';', $contentType);
        return trim(strtolower($parts[0]));
    }

    /**
     * Extract text from HTML content
     * 
     * @return array{content: string, content_type: string, title: string|null}
     */
    private function extractFromHtml(string $html): array {
        $title = null;
        
        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);
        
        $dom = new \DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR);
        
        libxml_clear_errors();

        // Extract title
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        // Remove unwanted elements
        $this->removeElements($dom, ['script', 'style', 'nav', 'footer', 'header', 'aside', 'noscript', 'iframe', 'svg']);

        // Try to find main content area
        $mainContent = $this->findMainContent($dom);
        
        if ($mainContent !== null) {
            $text = $this->getTextContent($mainContent);
        } else {
            // Fall back to body or entire document
            $body = $dom->getElementsByTagName('body')->item(0);
            $text = $body ? $this->getTextContent($body) : $this->getTextContent($dom);
        }

        // Clean up whitespace
        $text = $this->normalizeWhitespace($text);

        return [
            'content' => $text,
            'content_type' => 'html',
            'title' => $title,
        ];
    }

    /**
     * Remove specified elements from DOM
     */
    private function removeElements(\DOMDocument $dom, array $tagNames): void {
        foreach ($tagNames as $tagName) {
            $elements = $dom->getElementsByTagName($tagName);
            // Collect elements first since removing affects the live NodeList
            $toRemove = [];
            foreach ($elements as $element) {
                $toRemove[] = $element;
            }
            foreach ($toRemove as $element) {
                $element->parentNode?->removeChild($element);
            }
        }
    }

    /**
     * Try to find main content area in HTML
     */
    private function findMainContent(\DOMDocument $dom): ?\DOMNode {
        $xpath = new \DOMXPath($dom);
        
        // Try common content selectors
        $selectors = [
            "//main",
            "//article",
            "//*[@id='content']",
            "//*[@id='main']",
            "//*[@id='main-content']",
            "//*[contains(@class, 'content')]",
            "//*[contains(@class, 'article')]",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes !== false && $nodes->length > 0) {
                return $nodes->item(0);
            }
        }

        return null;
    }

    /**
     * Get text content from a DOM node, preserving some structure
     */
    private function getTextContent(\DOMNode $node): string {
        $text = '';
        
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->textContent;
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->nodeName);
                
                // Add newlines for block elements
                if (in_array($tagName, ['p', 'div', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'td', 'th'], true)) {
                    $text .= "\n";
                }
                
                $text .= $this->getTextContent($child);
                
                if (in_array($tagName, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr'], true)) {
                    $text .= "\n";
                }
            }
        }
        
        return $text;
    }

    /**
     * Normalize whitespace in text
     */
    private function normalizeWhitespace(string $text): string {
        // Replace multiple spaces with single space
        $text = preg_replace('/[ \t]+/', ' ', $text);
        // Replace multiple newlines with double newline (paragraph break)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        // Trim lines
        $lines = array_map('trim', explode("\n", $text));
        $text = implode("\n", array_filter($lines, fn($line) => $line !== ''));
        
        return trim($text);
    }

    /**
     * Extract text from JSON content
     */
    private function extractFromJson(string $json): string {
        $data = json_decode($json, true);
        
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // Invalid JSON, return as-is
            return $json;
        }

        // Pretty-print JSON for better readability
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }

    /**
     * Extract text from XML content
     */
    private function extractFromXml(string $xml): string {
        libxml_use_internal_errors(true);
        
        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NOWARNING | LIBXML_NOERROR);
        
        libxml_clear_errors();

        // Get all text content
        $text = $dom->textContent;
        
        return $this->normalizeWhitespace($text);
    }

    /**
     * Extract text from PDF using Docling API
     * 
     * @return array{content: string, content_type: string, title: string|null}
     */
    private function extractFromPdf(string $content, string $url): array {
        // Get filename from URL
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'document.pdf');
        
        $this->logger->info('Converting PDF from URL via Docling', [
            'url' => $url,
            'filename' => $filename,
            'size' => strlen($content),
        ]);

        try {
            $markdown = $this->doclingClient->convertBinaryToMarkdown($filename, $content, 'application/pdf');

            $this->logger->info('PDF converted successfully', [
                'url' => $url,
                'markdownLength' => strlen($markdown),
            ]);

            return [
                'content' => $markdown,
                'content_type' => 'pdf',
                'title' => pathinfo($filename, PATHINFO_FILENAME),
            ];

        } catch (Exception $e) {
            $this->logger->error('PDF conversion failed', [
                'url' => $url,
                'exception' => $e,
            ]);
            throw new Exception('Failed to convert PDF: ' . $e->getMessage());
        }
    }
}
