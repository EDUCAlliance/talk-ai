<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\Tool;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class McpClient {
    private IClientService $clientService;
    private CredentialService $credentialService;
    private LoggerInterface $logger;

    public function __construct(
        IClientService $clientService,
        CredentialService $credentialService,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
        $this->credentialService = $credentialService;
        $this->logger = $logger;
    }

    /**
     * @return array<int,array<string,mixed>>
     * @throws Exception
     */
    public function listTools(Tool $tool, array $context = []): array {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('mcp', true),
            'method' => 'tools/list',
        ];

        if (!empty($context)) {
            $payload['params'] = $context;
        }

        $response = $this->sendRequest($tool, $payload);
        $result = $response['result'] ?? null;
        if (!is_array($result) || !isset($result['tools']) || !is_array($result['tools'])) {
            throw new Exception('Invalid response from MCP tools/list');
        }
        return $result['tools'];
    }

    /**
     * @return array<string,mixed>
     * @throws Exception
     */
    public function callTool(Tool $tool, string $toolName, array $arguments = [], array $configOverride = []): array {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('mcp', true),
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => (object) $arguments,
            ],
        ];

        if (!empty($configOverride)) {
            $payload['params']['config'] = (object) $configOverride;
        }

        $response = $this->sendRequest($tool, $payload);
        if (isset($response['error'])) {
            throw new Exception(sprintf('Tool call failed: %s', $response['error']['message'] ?? 'unknown error'));
        }
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            throw new Exception('Invalid MCP tool response payload');
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sendRequest(Tool $tool, array $payload): array {
        $url = trim($tool->getMcpEndpointUrl());
        
        $this->logger->debug('MCP request starting', [
            'tool_id' => $tool->getId(),
            'url' => $url,
            'url_length' => strlen($url),
            'method' => $payload['method'] ?? 'unknown',
        ]);

        if (empty($url)) {
            throw new Exception('MCP endpoint URL is empty');
        }

        $client = $this->clientService->newClient();
        $options = [
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ], $this->collectHeaders($tool)),
            'json' => $payload,
            'timeout' => 60,
        ];

        try {
            $response = $client->post($url, $options);
            $rawBody = (string) $response->getBody();
            $decoded = $this->decodeResponseBody($rawBody);
            if (!is_array($decoded)) {
                throw new Exception('Unable to decode MCP response');
            }
            return $decoded;
        } catch (Exception $e) {
            $this->logger->error('MCP client request failed', [
                'tool_id' => $tool->getId(),
                'url' => $url,
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * @return array<string,string>
     */
    private function collectHeaders(Tool $tool): array {
        $raw = $tool->getAuthentication();
        if ($raw === null || $raw === '') {
            return [];
        }

        // Decrypt authentication if encrypted
        $decrypted = $this->credentialService->decrypt($raw);
        if ($decrypted === '') {
            return [];
        }

        $decoded = json_decode($decrypted, true);
        if (!is_array($decoded)) {
            return [];
        }

        $headers = [];
        if (isset($decoded['headers']) && is_array($decoded['headers'])) {
            foreach ($decoded['headers'] as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $headers[$key] = $value;
                }
            }
        }

        if (isset($decoded['bearer']) && is_string($decoded['bearer'])) {
            $headers['Authorization'] = 'Bearer ' . $decoded['bearer'];
        }

        return $headers;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeResponseBody(string $body): ?array {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }

        $firstChar = $trimmed[0] ?? '';
        if ($firstChar === '{' || $firstChar === '[') {
            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : null;
        }

        $eventAccumulator = '';
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if ($line === '') {
                if ($eventAccumulator !== '') {
                    break;
                }
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $payload = ltrim(substr($line, 5));
                $eventAccumulator .= $eventAccumulator === '' ? $payload : "\n" . $payload;
            }
        }

        $eventAccumulator = trim($eventAccumulator);
        if ($eventAccumulator === '') {
            return null;
        }

        $decoded = json_decode($eventAccumulator, true);
        return is_array($decoded) ? $decoded : null;
    }
}
