<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Exception\UnexpectedContentEncodingException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Throwable;

/**
 * App-local HTTP boundary: never pass remote content to a native decompressor.
 *
 * Keep Nextcloud's client (TLS, proxy, SSRF protection, cookies and redirects),
 * but override its compression negotiation AND Guzzle's automatic decoding.
 * Header negotiation alone does not prevent a server from sending Brotli.
 * This intentionally exposes only the verbs used by Talk AI, not the evolving
 * complete IClient interface, and never consumes or buffers response bodies.
 */
final class IdentityHttpClient {
	private function __construct(private IClient $client) {
	}

	public static function fromService(IClientService $clientService): self {
		return new self($clientService->newClient());
	}

	public function get(string $uri, array $options = []): IResponse {
		return $this->request('get', $uri, $options);
	}

	public function post(string $uri, array $options = []): IResponse {
		return $this->request('post', $uri, $options);
	}

	public function delete(string $uri, array $options = []): IResponse {
		return $this->request('delete', $uri, $options);
	}

	private function request(string $method, string $uri, array $options): IResponse {
		$options['decode_content'] = false;
		$headers = $options['headers'] ?? [];
		foreach (array_keys($headers) as $name) {
			if (strcasecmp((string)$name, 'Accept-Encoding') === 0) {
				unset($headers[$name]);
			}
		}
		// Exact spelling also prevents Nextcloud from adding its default br/gzip.
		$headers['Accept-Encoding'] = 'identity';
		$options['headers'] = $headers;

		// Do not introduce a curl array: Nextcloud merges it shallowly, so doing
		// so would replace unrelated transport defaults (e.g. HTTP/2). If the
		// caller supplies low-level overrides, they must not re-enable decoding.
		if (isset($options['curl'])) {
			if (defined('CURLOPT_ENCODING')) {
				$options['curl'][CURLOPT_ENCODING] = 'identity';
			}
			if (defined('CURLOPT_HTTP_CONTENT_DECODING')) {
				$options['curl'][CURLOPT_HTTP_CONTENT_DECODING] = false;
			}
		}

		$onHeaders = $options['on_headers'] ?? null;
		$options['on_headers'] = static function ($response) use ($onHeaders): void {
			// Guzzle invokes this for intermediate redirects and error responses
			// too, before processing a body. getHeaderLine includes ALL values.
			self::assertIdentity($response->getHeaderLine('Content-Encoding'));
			if ($onHeaders !== null) {
				$onHeaders($response);
			}
		};

		try {
			$response = $this->client->$method($uri, $options);
		} catch (Throwable $exception) {
			// Guzzle wraps on_headers errors. Preserve our terminal classification
			// instead of letting provider retry/fallback logic treat it as I/O.
			for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
				if ($cause instanceof UnexpectedContentEncodingException) {
					throw $cause;
				}
			}
			throw $exception;
		}

		// Defensive check for injected clients that do not invoke on_headers.
		self::assertIdentity($response->getHeader('Content-Encoding'));
		return $response;
	}

	private static function assertIdentity(string $encoding): void {
		$encoding = trim($encoding);
		if ($encoding !== '' && strcasecmp($encoding, 'identity') !== 0) {
			throw new UnexpectedContentEncodingException();
		}
	}
}
