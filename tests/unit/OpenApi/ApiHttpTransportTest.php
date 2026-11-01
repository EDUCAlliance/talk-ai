<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi;

require_once __DIR__ . '/Support/OpenApiTestDoubles.php';

use OCA\EducAI\OpenApi\ApiExecutionException;
use OCA\EducAI\OpenApi\ApiHttpTransport;
use OCA\EducAI\OpenApi\ApiNetworkPolicy;
use OCA\EducAI\OpenApi\OpenApiDocumentLoader;
use OCA\EducAI\Tests\Unit\OpenApi\Support\FakeResponse;
use OCA\EducAI\Tests\Unit\OpenApi\Support\RecordingClient;
use OCA\EducAI\Tests\Unit\OpenApi\Support\RecordingClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class ApiHttpTransportTest extends TestCase {
	private const BASE = 'https://api.courses.example/v1';
	private const SECRET = 'synthetic-test-token';

	/** @var list<array{string,string,array<string,mixed>}> */
	private array $logs = [];

	private function policy(bool $testTargets = false): ApiNetworkPolicy {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === ApiNetworkPolicy::CONFIG_ALLOW_TEST_TARGETS && $testTargets ? 'yes' : $default,
		);
		$config->method('getSystemValueBool')->willReturn(false);
		return new ApiNetworkPolicy($config);
	}

	/** @param callable(string,array<string,mixed>):IResponse $responder */
	private function transport(callable $responder, bool $testTargets = false): array {
		$client = new RecordingClient($responder);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logs[] = [$level, $message, $context];
			});
		}
		return [new ApiHttpTransport(new RecordingClientService($client), $this->policy($testTargets), $logger), $client];
	}

	private static function json(string $body, int $status = 200, array $headers = []): FakeResponse {
		return new FakeResponse($status, $headers + ['Content-Type' => 'application/json; charset=utf-8'], $body);
	}

	private function assertFailure(string $code, callable $responder, ?string $url = null): ApiExecutionException {
		[$transport] = $this->transport($responder);
		try {
			$transport->getJson($url ?? self::BASE . '/courses', self::BASE, ['Authorization' => 'Bearer ' . self::SECRET], 10.0, ['connection_id' => 1]);
			self::fail('Expected ' . $code);
		} catch (ApiExecutionException $e) {
			self::assertSame($code, $e->getErrorCode());
			$this->assertSecretNotLeaked($e);
			return $e;
		}
	}

	private function assertSecretNotLeaked(?ApiExecutionException $e = null): void {
		$haystack = json_encode([$this->logs, $e?->getMessage(), $e?->getDetails()]);
		self::assertStringNotContainsString(self::SECRET, (string)$haystack);
		self::assertStringNotContainsString('/courses?', (string)$haystack);
	}

	public function testRequestsAreBoundedAndNeverFollowRedirects(): void {
		[$transport, $client] = $this->transport(static fn (): IResponse => self::json('{"data":[]}'));
		$result = $transport->getJson(self::BASE . '/courses?q=x', self::BASE, ['Authorization' => 'Bearer ' . self::SECRET], 60.0, ['connection_id' => 1]);

		self::assertSame(['data' => []], $result['data']);
		$options = $client->requests[0]['options'];
		self::assertFalse($options['allow_redirects']);
		self::assertFalse($options['http_errors']);
		self::assertTrue($options['stream']);
		self::assertFalse($options['decode_content']);
		self::assertSame('identity', $options['headers']['Accept-Encoding']);
		self::assertSame(ApiHttpTransport::MAX_TIMEOUT_SECONDS, $options['timeout']);
		self::assertSame(ApiHttpTransport::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout']);
		self::assertFalse($options['nextcloud']['allow_local_address']);
		self::assertSame('application/json', $options['headers']['Accept']);
		$this->assertSecretNotLeaked();
	}

	/** @return iterable<string,array{int,string}> */
	public static function statusFailures(): iterable {
		yield 'redirect' => [302, 'redirect_blocked'];
		yield 'not modified' => [304, 'redirect_blocked'];
		yield 'unauthorized' => [401, 'auth_rejected'];
		yield 'forbidden' => [403, 'auth_rejected'];
		yield 'not found' => [404, 'not_found'];
		yield 'bad request' => [400, 'request_rejected'];
		yield 'rate limited' => [429, 'rate_limited'];
		yield 'server error' => [503, 'upstream_error'];
	}

	#[DataProvider('statusFailures')]
	public function testErrorStatusesAreStructured(int $status, string $code): void {
		$e = $this->assertFailure($code, static fn (): IResponse => self::json('{"error":"' . self::SECRET . '"}', $status, ['Location' => 'https://evil.example/', 'Retry-After' => '30']));

		self::assertSame($status, $e->getDetails()['http_status']);
		self::assertTrue($e->wasRequestSent());
		if ($status === 429) {
			self::assertSame(30, $e->getDetails()['retry_after_seconds']);
		}
	}

	public function testBodiesMustBeBoundedJson(): void {
		$this->assertFailure('response_not_json', static fn (): IResponse => new FakeResponse(200, ['Content-Type' => 'text/html'], '<html>'));
		$this->assertFailure('response_invalid_json', static fn (): IResponse => self::json('{"data": ['));
		$this->assertFailure('response_too_deep', static fn (): IResponse => self::json(str_repeat('[', 200) . str_repeat(']', 200)));
		$this->assertFailure('response_too_large', static fn (): IResponse => self::json('{}', 200, ['Content-Length' => (string)(ApiHttpTransport::MAX_RESPONSE_BYTES + 1)]));
		$this->assertFailure('response_too_large', static fn (): IResponse => self::json('"' . str_repeat('a', ApiHttpTransport::MAX_RESPONSE_BYTES) . '"'));
		$this->assertFailure('response_compressed', static fn (): IResponse => self::json('{}', 200, ['Content-Encoding' => 'gzip']));
	}

	/** @param array<string,string> $headers */
	private function headers(int $status, array $headers): ResponseInterface {
		$response = $this->createMock(ResponseInterface::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getHeaderLine')->willReturnCallback(static fn (string $name): string => array_change_key_case($headers)[strtolower($name)] ?? '');
		return $response;
	}

	public function testRefusalsAbortTheTransferInsideCurl(): void {
		[$transport, $client] = $this->transport(static fn (): IResponse => self::json('{}'));
		$transport->getJson(self::BASE . '/courses', self::BASE, [], 5.0);
		$options = $client->requests[0]['options'];

		self::assertFalse($options['curl'][CURLOPT_NOPROGRESS]);
		self::assertIsCallable($options['curl'][CURLOPT_PROGRESSFUNCTION]);
		$curl = $options['curl'];
		unset($curl[CURLOPT_NOPROGRESS], $curl[CURLOPT_PROGRESSFUNCTION]);
		self::assertSame([
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
			CURLOPT_MAXFILESIZE => ApiHttpTransport::MAX_RESPONSE_BYTES,
			CURLOPT_ENCODING => 'identity',
			CURLOPT_HTTP_CONTENT_DECODING => false,
		], $curl);
		$onHeaders = $options['on_headers'];
		$onHeaders($this->headers(200, ['Content-Type' => 'application/json', 'Content-Length' => (string)ApiHttpTransport::MAX_RESPONSE_BYTES]));
		$refusals = [
			'response_not_json' => $this->headers(200, ['Content-Type' => 'text/html']),
			'response_too_large' => $this->headers(200, ['Content-Type' => 'application/json', 'Content-Length' => (string)(ApiHttpTransport::MAX_RESPONSE_BYTES + 1)]),
			'upstream_error' => $this->headers(502, ['Content-Type' => 'text/html']),
			'rate_limited' => $this->headers(429, ['Retry-After' => '9']),
		];
		foreach ($refusals as $code => $response) {
			try {
				$onHeaders($response);
				self::fail('Expected ' . $code);
			} catch (ApiExecutionException $e) {
				self::assertSame($code, $e->getErrorCode());
				self::assertTrue($e->wasRequestSent());
			}
		}
		self::assertSame(9, $e->getDetails()['retry_after_seconds']);
	}

	public function testDocumentDownloadsUseTheDocumentLimitAndAcceptAnyType(): void {
		[$transport, $client] = $this->transport(static fn (): IResponse => new FakeResponse(200, ['Content-Type' => 'text/yaml'], 'openapi: 3.0.3'), true);
		$transport->fetchDocument('http://127.0.0.1/openapi.yaml');
		$options = $client->requests[0]['options'];

		self::assertSame(OpenApiDocumentLoader::MAX_BYTES, $options['curl'][CURLOPT_MAXFILESIZE]);
		$options['on_headers']($this->headers(200, ['Content-Type' => 'text/yaml']));
		$this->expectExceptionObject(new ApiExecutionException('response_too_large', 'The response is larger than allowed.'));
		$options['on_headers']($this->headers(200, ['Content-Length' => (string)(OpenApiDocumentLoader::MAX_BYTES + 1)]));
	}

	public function testRefusalsRaisedInsideTheClientKeepTheirClassification(): void {
		$wrapped = new ApiExecutionException('response_not_json', 'The server did not answer with JSON.', ['http_status' => 200], true);
		$e = $this->assertFailure('response_not_json', static fn (): IResponse => throw new \RuntimeException('An error was encountered during the on_headers event', 0, $wrapped));
		self::assertSame($wrapped, $e);
		self::assertSame(['http_status' => 200], array_intersect_key($this->logs[0][2], ['http_status' => true]));

		$this->assertFailure('response_too_large', static fn (): IResponse => throw new \RuntimeException('cURL error 63: Maximum file size exceeded (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)'));
		$this->assertFailure('response_too_large', static fn (): IResponse => throw new class('Transfer aborted') extends \RuntimeException {
			public function getHandlerContext(): array {
				return ['errno' => CURLE_FILESIZE_EXCEEDED];
			}
		});
	}

	public function testUnknownLengthTransferAbortsWithoutTheNativeCurlSizeLimit(): void {
		if (!function_exists('proc_open')) {
			self::markTestSkipped('The local HTTP fixture cannot start without proc_open.');
		}
		$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if ($socket === false) {
			self::markTestSkipped('The local HTTP fixture cannot bind a loopback port.');
		}
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$serverLog = tempnam(sys_get_temp_dir(), 'educai-transfer-');
		$server = @proc_open(
			[PHP_BINARY, '-d', 'output_buffering=0', '-S', $address, dirname(__DIR__, 2) . '/integration/openapi-http-fixture.php'],
			[0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
			$pipes,
		);
		if (!is_resource($server)) {
			unlink($serverLog);
			self::markTestSkipped('The local HTTP fixture process could not start.');
		}
		fclose($pipes[0]);
		try {
			$ready = false;
			for ($attempt = 0; $attempt < 100; $attempt++) {
				$probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
				if (is_resource($probe)) {
					fclose($probe);
					$ready = true;
					break;
				}
				usleep(10000);
			}
			if (!$ready) {
				self::markTestSkipped('The local HTTP fixture did not become ready.');
			}
			$received = 0;
			$declaredLength = null;
			$curlError = null;
			[$transport] = $this->transport(static function (string $url, array $options) use (&$received, &$declaredLength, &$curlError): IResponse {
				$handle = curl_init($url);
				$curl = $options['curl'];
				// Emulate pre-8.4 cURL: it does not enforce MAXFILESIZE for
				// unknown-length bodies. Only the progress guard can abort.
				unset($curl[CURLOPT_MAXFILESIZE]);
				curl_setopt_array($handle, $curl + [
					CURLOPT_TIMEOUT => 5,
					CURLOPT_PROXY => '',
					CURLOPT_FOLLOWLOCATION => $options['allow_redirects'],
					CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$declaredLength): int {
						if (stripos($line, 'Content-Length:') === 0) {
							$declaredLength = trim(substr($line, 15));
						}
						return strlen($line);
					},
					CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
						$received += strlen($chunk);
						return strlen($chunk);
					},
				]);
				try {
					$result = curl_exec($handle);
					$curlError = curl_errno($handle);
					if ($result === false) {
						throw new \RuntimeException('cURL error ' . $curlError);
					}
					return self::json('{}');
				} finally {
					curl_close($handle);
				}
			}, true);
			$base = 'http://' . $address . '/v1';
			$limit = 65_536;
			try {
				$transport->getJson($base . '/streamed-large', $base, [], 5.0, [], $limit);
				self::fail('Expected the unknown-length transfer to abort.');
			} catch (ApiExecutionException $e) {
				self::assertSame('response_too_large', $e->getErrorCode());
				self::assertSame(['limit_bytes' => $limit], $e->getDetails());
				self::assertTrue($e->wasRequestSent());
			}
			self::assertNull($declaredLength, 'The fixture must not declare its body length.');
			self::assertSame(CURLE_ABORTED_BY_CALLBACK, $curlError);
			self::assertGreaterThan($limit, $received);
			self::assertLessThanOrEqual($limit + 65_536, $received, 'Abort within one server chunk, long before the 2 MiB body.');
		} finally {
			proc_terminate($server);
			proc_close($server);
			unlink($serverLog);
		}
	}

	public function testVendorJsonMediaTypesAreAccepted(): void {
		[$transport] = $this->transport(static fn (): IResponse => new FakeResponse(200, ['Content-Type' => 'application/vnd.library+json'], '[1]'));
		self::assertSame([1], $transport->getJson(self::BASE . '/books', self::BASE, [], 5.0)['data']);
	}

	public function testTransportFailuresAreClassified(): void {
		$this->assertFailure('timeout', static fn (): IResponse => throw new \RuntimeException('cURL error 28: Operation timed out after 10001 milliseconds with ' . self::SECRET));
		$this->assertFailure('transport_error', static fn (): IResponse => throw new \RuntimeException('cURL error 6: Could not resolve host'));
		$this->assertFailure('target_not_allowed', static fn (): IResponse => throw new \RuntimeException('wrapped', 0, new LocalServerException('Host violates local access rules')));
	}

	/** @return iterable<string,array{string}> */
	public static function escapes(): iterable {
		yield 'other host' => ['https://evil.example/v1/courses'];
		yield 'sibling path' => [self::BASE . 'x/courses'];
		yield 'parent path' => ['https://api.courses.example/admin'];
		yield 'userinfo' => ['https://api.courses.example/v1@evil.example/'];
		yield 'fragment' => [self::BASE . '/courses#x'];
		yield 'whitespace' => [self::BASE . '/courses x'];
		yield 'backslash' => [self::BASE . '\\..\\admin'];
		yield 'scheme downgrade' => ['http://api.courses.example/v1/courses'];
	}

	#[DataProvider('escapes')]
	public function testRequestsCannotLeaveTheApprovedBase(string $url): void {
		$client = null;
		$e = $this->assertFailure('target_outside_base', static function () use (&$client): IResponse {
			$client = 'called';
			return self::json('{}');
		}, $url);

		self::assertNull($client);
		self::assertFalse($e->wasRequestSent());
	}

	public function testHeaderInjectionThroughTheCredentialIsRefused(): void {
		[$transport, $client] = $this->transport(static fn (): IResponse => self::json('{}'));
		try {
			$transport->getJson(self::BASE . '/courses', self::BASE, ['Authorization' => "Bearer x\r\nX-Evil: 1"], 5.0);
			self::fail('Expected credential_invalid');
		} catch (ApiExecutionException $e) {
			self::assertSame('credential_invalid', $e->getErrorCode());
		}
		self::assertSame([], $client->requests);
	}

	public function testPrivateTargetsNeedTheExplicitTestFlag(): void {
		[$blocked, $client] = $this->transport(static fn (): IResponse => self::json('{}'));
		try {
			$blocked->getJson('http://127.0.0.1:8080/api/x', 'http://127.0.0.1:8080/api', [], 5.0);
			self::fail('Expected target_not_allowed');
		} catch (ApiExecutionException $e) {
			self::assertSame('target_not_allowed', $e->getErrorCode());
		}
		self::assertSame([], $client->requests);

		[$allowed, $testClient] = $this->transport(static fn (): IResponse => self::json('{"ok":true}'), true);
		self::assertSame(['ok' => true], $allowed->getJson('http://127.0.0.1:8080/api/x', 'http://127.0.0.1:8080/api', [], 5.0)['data']);
		self::assertTrue($testClient->requests[0]['options']['nextcloud']['allow_local_address']);
	}

	public function testDocumentsAreFetchedWithoutCredentialsAndWithinTheImportLimit(): void {
		[$transport, $client] = $this->transport(static fn (): IResponse => new FakeResponse(200, ['Content-Type' => 'text/plain'], '{"openapi":"3.0.3"}'));

		self::assertSame('{"openapi":"3.0.3"}', $transport->fetchDocument('https://specs.courses.example/openapi.json'));
		self::assertArrayNotHasKey('Authorization', $client->requests[0]['options']['headers']);
		self::assertFalse($client->requests[0]['options']['allow_redirects']);
		foreach (['http://specs.courses.example/openapi.json', 'https://localhost/openapi.json', 'https://10.0.0.5/openapi.json', 'https://specs.courses.example/openapi.json#x'] as $url) {
			try {
				$transport->fetchDocument($url);
				self::fail('Fetched ' . $url);
			} catch (ApiExecutionException $e) {
				self::assertSame('target_not_allowed', $e->getErrorCode(), $url);
			}
		}
		self::assertCount(1, $client->requests);
	}

	/** @return iterable<string,array{string,bool}> */
	public static function hosts(): iterable {
		yield 'public name' => ['api.courses.example', false];
		yield 'public ipv4' => ['93.184.216.34', false];
		yield 'public ipv6' => ['[2606:2800:220:1:248:1893:25c8:1946]', false];
		yield 'localhost' => ['localhost', true];
		yield 'localhost subdomain' => ['api.localhost', true];
		yield 'single label' => ['intranet', true];
		yield 'mdns' => ['printer.local', true];
		yield 'internal' => ['db.internal', true];
		yield 'loopback' => ['127.0.0.1', true];
		yield 'private' => ['10.1.2.3', true];
		yield 'link local' => ['169.254.169.254', true];
		yield 'cgnat' => ['100.64.0.1', true];
		yield 'unspecified' => ['0.0.0.0', true];
		yield 'ipv6 loopback' => ['[::1]', true];
		yield 'ipv6 ula' => ['[fd00::1]', true];
		yield 'ipv6 link local' => ['[fe80::1]', true];
		yield 'ipv4 mapped' => ['[::ffff:127.0.0.1]', true];
		yield 'decimal ipv4' => ['2130706433', true];
		yield 'hex ipv4' => ['0x7f.1', true];
		yield 'short ipv4' => ['127.1', true];
	}

	#[DataProvider('hosts')]
	public function testHostClassification(string $host, bool $blocked): void {
		self::assertSame($blocked, ApiNetworkPolicy::isBlockedHost($host));
	}
}
