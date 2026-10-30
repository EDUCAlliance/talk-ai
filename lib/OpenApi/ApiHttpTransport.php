<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Exception\UnexpectedContentEncodingException;
use OCA\EducAI\Service\IdentityHttpClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use Psr\Log\LoggerInterface;

/**
 * Bounded GET through Nextcloud's HTTP client (TLS, proxy, DNS pinning,
 * local-address protection) without redirects, decompression or unbounded
 * bodies. Errors are structured and never echo URLs, headers or bodies.
 */
class ApiHttpTransport {
	public const MAX_RESPONSE_BYTES = 1_048_576;
	public const MAX_RESPONSE_DEPTH = 128;
	public const MAX_TIMEOUT_SECONDS = 30.0;
	public const CONNECT_TIMEOUT_SECONDS = 5.0;
	public const DOCUMENT_TIMEOUT_SECONDS = 20.0;
	private const READ_CHUNK = 65_536;
	private const JSON_TYPE = '#^\s*application/(?:[A-Za-z0-9.+-]+\+)?json\s*(?:;.*)?$#i';

	public function __construct(
		private IClientService $clientService,
		private ApiNetworkPolicy $networkPolicy,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,string> $headers Request headers (credentials included); never logged
	 * @param array<string,scalar|null> $logContext Identifiers only
	 * @return array{status:int,data:mixed,bytes:int,duration_ms:int}
	 * @throws ApiExecutionException
	 */
	public function getJson(string $url, string $approvedBase, array $headers, float $timeout, array $logContext = []): array {
		$this->assertBelowBase($url, $approvedBase);
		$start = microtime(true);
		[$status, $body] = $this->send($url, ['Accept' => 'application/json'] + $headers, $timeout, self::MAX_RESPONSE_BYTES, true, $logContext, $start);
		try {
			$data = json_decode($body, true, self::MAX_RESPONSE_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
		} catch (\JsonException $e) {
			$code = $e->getCode() === JSON_ERROR_DEPTH ? 'response_too_deep' : 'response_invalid_json';
			$this->log($code, $logContext, $start);
			throw new ApiExecutionException($code, 'The API answered with JSON that cannot be used.', [], true);
		}
		$durationMs = self::elapsedMs($start);
		$this->logger->info('EducAI: OpenAPI request completed', $logContext + ['http_status' => $status, 'bytes' => strlen($body), 'duration_ms' => $durationMs]);
		return ['status' => $status, 'data' => $data, 'bytes' => strlen($body), 'duration_ms' => $durationMs];
	}

	/**
	 * Downloads an OpenAPI document from a public HTTPS address without
	 * credentials. The content is parsed by OpenApiDocumentLoader.
	 *
	 * @throws ApiExecutionException
	 */
	public function fetchDocument(string $url): string {
		try {
			$normalized = $this->networkPolicy->normalizeTarget($url);
		} catch (\InvalidArgumentException $e) {
			throw new ApiExecutionException('target_not_allowed', 'The document address is not allowed.', ['reason' => $e->getMessage()]);
		}
		if (preg_match('/[\x00-\x20\x7f-\xff#\\\\]/', $url) === 1 || !str_starts_with($url, $normalized)) {
			throw new ApiExecutionException('target_not_allowed', 'The document address is not allowed.', ['reason' => 'base_url_invalid']);
		}
		$start = microtime(true);
		[, $body] = $this->send(
			$url,
			['Accept' => 'application/vnd.oai.openapi+json, application/json;q=0.9'],
			self::DOCUMENT_TIMEOUT_SECONDS,
			OpenApiDocumentLoader::MAX_BYTES,
			false,
			['purpose' => 'openapi_document'],
			$start,
		);
		return $body;
	}

	/**
	 * @param array<string,string> $headers
	 * @param array<string,scalar|null> $logContext
	 * @return array{0:int,1:string}
	 * @throws ApiExecutionException
	 */
	private function send(string $url, array $headers, float $timeout, int $maxBytes, bool $requireJson, array $logContext, float $start): array {
		foreach ($headers as $name => $value) {
			if (preg_match('/[\r\n\x00]/', $name . $value) === 1) {
				throw new ApiExecutionException('credential_invalid', 'The stored credential cannot be sent as a header.');
			}
		}
		$timeout = max(0.5, min($timeout, self::MAX_TIMEOUT_SECONDS));
		$options = [
			'headers' => $headers,
			'timeout' => $timeout,
			'connect_timeout' => min(self::CONNECT_TIMEOUT_SECONDS, $timeout),
			'allow_redirects' => false,
			'http_errors' => false,
			'stream' => true,
			'nextcloud' => ['allow_local_address' => $this->networkPolicy->allowsTestTargets()],
		];

		try {
			$response = IdentityHttpClient::fromService($this->clientService)->get($url, $options);
		} catch (UnexpectedContentEncodingException) {
			$this->log('response_compressed', $logContext, $start);
			throw new ApiExecutionException('response_compressed', 'The server sent a compressed response, which is refused.', [], true);
		} catch (\Throwable $e) {
			for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
				if ($cause instanceof LocalServerException) {
					$this->log('target_not_allowed', $logContext, $start);
					throw new ApiExecutionException('target_not_allowed', 'The address is not allowed by the server network policy.');
				}
			}
			$timedOut = str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;
			$code = $timedOut ? 'timeout' : 'transport_error';
			$this->log($code, $logContext + ['exception_class' => get_class($e)], $start);
			throw new ApiExecutionException($code, $timedOut ? 'The server did not answer in time.' : 'The server could not be reached.', [], true);
		}

		$status = $response->getStatusCode();
		$failure = match (true) {
			$status >= 300 && $status < 400 => ['redirect_blocked', 'The server answered with a redirect, which is not followed.'],
			$status === 401 || $status === 403 => ['auth_rejected', 'The server rejected the configured authentication.'],
			$status === 404 => ['not_found', 'The server found no matching resource.'],
			$status === 429 => ['rate_limited', 'The server rate limit is reached. Try again later.'],
			$status >= 500 => ['upstream_error', 'The server reported an error.'],
			$status < 200 || $status >= 300 => ['request_rejected', 'The server rejected the request.'],
			default => null,
		};
		if ($failure !== null) {
			self::close($response);
			$details = ['http_status' => $status];
			$retryAfter = trim($response->getHeader('Retry-After'));
			if ($status === 429 && preg_match('/^[0-9]{1,6}$/D', $retryAfter) === 1) {
				$details['retry_after_seconds'] = (int)$retryAfter;
			}
			$this->log($failure[0], $logContext + ['http_status' => $status], $start);
			throw new ApiExecutionException($failure[0], $failure[1], $details, true);
		}

		if ($requireJson && preg_match(self::JSON_TYPE, $response->getHeader('Content-Type')) !== 1) {
			self::close($response);
			$this->log('response_not_json', $logContext + ['http_status' => $status], $start);
			throw new ApiExecutionException('response_not_json', 'The server did not answer with JSON.', ['http_status' => $status], true);
		}
		$declared = trim($response->getHeader('Content-Length'));
		$body = preg_match('/^[0-9]{1,15}$/D', $declared) === 1 && (int)$declared > $maxBytes
			? null
			: self::read($response->getBody(), $maxBytes);
		if ($body === null) {
			self::close($response);
			$this->log('response_too_large', $logContext, $start);
			throw new ApiExecutionException('response_too_large', 'The response is larger than allowed.', ['limit_bytes' => $maxBytes], true);
		}
		return [$status, $body];
	}

	/**
	 * The builder only appends to the approved base; this re-checks it at the
	 * last point before the network.
	 *
	 * @throws ApiExecutionException
	 */
	private function assertBelowBase(string $url, string $approvedBase): void {
		$next = substr($url, strlen($approvedBase), 1);
		if ($approvedBase === '' || !str_starts_with($url, $approvedBase) || !in_array($next, ['', '/', '?'], true)
			|| preg_match('/[\x00-\x20\x7f-\xff#\\\\]/', $url) === 1) {
			throw new ApiExecutionException('target_outside_base', 'The request would leave the approved API address.');
		}
		try {
			$this->networkPolicy->normalizeTarget($approvedBase);
		} catch (\InvalidArgumentException $e) {
			throw new ApiExecutionException('target_not_allowed', 'The API address is not allowed by the server network policy.', ['reason' => $e->getMessage()]);
		}
	}

	/** @param resource|string|mixed $body */
	private static function read(mixed $body, int $maxBytes): ?string {
		if (is_string($body)) {
			return strlen($body) > $maxBytes ? null : $body;
		}
		if (!is_resource($body)) {
			return '';
		}
		$buffer = '';
		try {
			while (!feof($body)) {
				$chunk = fread($body, self::READ_CHUNK);
				if ($chunk === false) {
					break;
				}
				$buffer .= $chunk;
				if (strlen($buffer) > $maxBytes) {
					return null;
				}
			}
		} finally {
			fclose($body);
		}
		return $buffer;
	}

	private static function close(IResponse $response): void {
		$body = $response->getBody();
		if (is_resource($body)) {
			fclose($body);
		}
	}

	private static function elapsedMs(float $start): int {
		return (int)round((microtime(true) - $start) * 1000);
	}

	/** @param array<string,scalar|null> $context */
	private function log(string $code, array $context, float $start): void {
		$this->logger->warning('EducAI: OpenAPI request failed', $context + [
			'error_code' => $code,
			'duration_ms' => self::elapsedMs($start),
		]);
	}
}
