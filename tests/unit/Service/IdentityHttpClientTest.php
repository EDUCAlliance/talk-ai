<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Exception\UnexpectedContentEncodingException;
use OCA\EducAI\Service\IdentityHttpClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IdentityHttpClientTest extends TestCase {
	#[DataProvider('requestMethods')]
	public function testPolicyCannotBeOverriddenButOtherRequestOptionsArePreserved(string $method): void {
		$body = fopen('php://temp', 'r+');
		$sink = fopen('php://temp', 'r+');
		$this->assertIsResource($body);
		$this->assertIsResource($sink);
		fwrite($body, 'binary upload');
		rewind($body);
		$cookies = new \stdClass();
		$options = [
			'headers' => [
				'Authorization' => 'Bearer fixture-token',
				'Content-Type' => 'multipart/form-data',
				'Accept-Encoding' => 'gzip',
				'aCcEpT-eNcOdInG' => ['br', 'gzip'],
				'X-Fixture' => ['one', 'two'],
			],
			'decode_content' => true,
			'verify' => '/fixture/custom-ca.pem',
			'cert' => ['/fixture/client.pem', 'fixture-passphrase'],
			'timeout' => 17.5,
			'connect_timeout' => 4,
			'allow_redirects' => false,
			'http_errors' => false,
			'stream' => true,
			'sink' => $sink,
			'cookies' => $cookies,
			'multipart' => [['name' => 'document', 'contents' => $body]],
		];
		if (defined('CURLOPT_ENCODING') && defined('CURLOPT_HTTP_CONTENT_DECODING')) {
			$options['curl'] = [
				CURLOPT_ENCODING => 'br',
				CURLOPT_HTTP_CONTENT_DECODING => true,
				CURLOPT_CONNECTTIMEOUT_MS => 750,
			];
		}
		$original = $options;
		$response = $this->response('');
		$httpClient = $this->httpClient($method);
		$httpClient->expects($this->once())->method($method)
			->with('https://service.example.test/upload', $this->callback(function (array $actual) use ($options): bool {
				$this->assertFalse($actual['decode_content']);
				$this->assertSame('identity', $actual['headers']['Accept-Encoding']);
				$this->assertCount(1, array_filter(array_keys($actual['headers']), static fn (string $name): bool => strtolower($name) === 'accept-encoding'));
				$expectedHeaders = $options['headers'];
				unset($expectedHeaders['Accept-Encoding'], $expectedHeaders['aCcEpT-eNcOdInG']);
				$actualHeaders = $actual['headers'];
				unset($actualHeaders['Accept-Encoding']);
				$this->assertSame($expectedHeaders, $actualHeaders);
				foreach ($options as $key => $value) {
					if (in_array($key, ['headers', 'decode_content', 'curl'], true)) {
						continue;
					}
					$this->assertSame($value, $actual[$key], $key . ' must not be changed by the encoding policy.');
				}
				if (isset($options['curl'])) {
					$this->assertArrayHasKey(CURLOPT_ENCODING, $actual['curl']);
					$this->assertSame('identity', $actual['curl'][CURLOPT_ENCODING]);
					$this->assertFalse($actual['curl'][CURLOPT_HTTP_CONTENT_DECODING]);
					$this->assertSame(750, $actual['curl'][CURLOPT_CONNECTTIMEOUT_MS]);
				}
				$this->assertIsCallable($actual['on_headers']);
				return true;
			}))
			->willReturn($response);

		try {
			$this->assertSame($response, $this->client($httpClient)->$method('https://service.example.test/upload', $options));
			$this->assertSame($original, $options, 'Caller-owned options must not be mutated.');
			$this->assertSame(0, ftell($body), 'Preparing the request must not consume the upload stream.');
			$this->assertSame(0, ftell($sink), 'The guard must not write to the response sink.');
		} finally {
			fclose($body);
			fclose($sink);
		}
	}

	public static function requestMethods(): iterable {
		yield 'GET external source' => ['get'];
		yield 'POST provider or upload' => ['post'];
		yield 'DELETE bot registration' => ['delete'];
	}

	public function testDefaultTransportOptionsAreNotReplacedByAnAppCurlArray(): void {
		$response = $this->response('');
		$httpClient = $this->httpClient('get');
		$httpClient->expects($this->once())->method('get')->willReturnCallback(function (string $url, array $options) use ($response): IResponse {
			$this->assertArrayNotHasKey('curl', $options, 'Nextcloud shallow-merges this array with its HTTP/2 transport defaults.');
			$this->assertSame('identity', $options['headers']['Accept-Encoding']);
			$this->assertFalse($options['decode_content']);
			return $response;
		});

		$this->assertSame($response, $this->client($httpClient)->get('https://service.example.test/file'));
	}

	#[DataProvider('encodedResponses')]
	public function testEncodedHeadersAreRejectedBeforeCallerCallbackOrBody(string $encoding): void {
		$callerWasInvoked = false;
		$headers = $this->headers($encoding);
		$response = $this->response($encoding);
		$httpClient = $this->httpClient('get');
		$httpClient->expects($this->once())->method('get')
			->willReturnCallback(function (string $url, array $options) use ($headers, $response): IResponse {
				$options['on_headers']($headers);
				return $response;
			});

		try {
			$this->client($httpClient)->get('https://service.example.test/response', [
				'on_headers' => static function () use (&$callerWasInvoked): void {
					$callerWasInvoked = true;
				},
			]);
			$this->fail('Compressed responses must not reach response consumers.');
		} catch (UnexpectedContentEncodingException $e) {
			$this->assertFalse($callerWasInvoked, 'The guard must run before an existing callback.');
			$this->assertNotSame('', $e->getMessage());
		}
	}

	#[DataProvider('encodedResponses')]
	public function testReturnedResponseIsCheckedWhenTransportDoesNotCallOnHeaders(string $encoding): void {
		$response = $this->response($encoding);
		$httpClient = $this->httpClient('post');
		$httpClient->expects($this->once())->method('post')->willReturn($response);

		$this->expectException(UnexpectedContentEncodingException::class);
		$this->client($httpClient)->post('https://service.example.test/completions', ['stream' => true]);
	}

	public static function encodedResponses(): iterable {
		yield 'Brotli' => ['br'];
		yield 'Brotli with surrounding whitespace and case' => [" \tBR \t"];
		yield 'gzip is not identity' => ['gzip'];
		yield 'deflate is not identity' => ['deflate'];
		yield 'unknown coding' => ['x-fixture-coding'];
		yield 'stacked codings' => ['gzip, br'];
		yield 'identity does not hide Brotli' => ['identity, br'];
		yield 'Brotli does not become safe with identity' => ['br, identity'];
	}

	#[DataProvider('identityResponses')]
	public function testIdentityResponsesKeepCallbackAndReturnOriginalResponseWithoutReadingBody(string $encoding): void {
		$headers = $this->headers($encoding);
		$response = $this->response($encoding);
		$callbackCalls = [];
		$httpClient = $this->httpClient('get');
		$httpClient->expects($this->once())->method('get')
			->willReturnCallback(function (string $url, array $options) use ($headers, $response): IResponse {
				$this->assertSame('identity', $options['headers']['Accept-Encoding']);
				$this->assertFalse($options['decode_content']);
				$options['on_headers']($headers);
				return $response;
			});

		$result = $this->client($httpClient)->get('https://service.example.test/events', [
			'stream' => true,
			'on_headers' => static function (object $received) use (&$callbackCalls): void {
				$callbackCalls[] = $received;
			},
		]);

		$this->assertSame($response, $result, 'Streams must not be replaced with a buffered response.');
		$this->assertSame([$headers], $callbackCalls);
	}

	public static function identityResponses(): iterable {
		yield 'absent' => [''];
		yield 'empty whitespace' => [" \t"];
		yield 'identity' => ['identity'];
		yield 'case-insensitive identity' => [' IDENTITY '];
	}

	public function testGuardExceptionWrappedByTransportIsUnwrappedWithoutRetry(): void {
		$guardException = new UnexpectedContentEncodingException();
		$wrapped = new \RuntimeException('Outer transport failure', 0, new \RuntimeException('Header callback failure', 0, $guardException));
		$httpClient = $this->httpClient('post');
		$httpClient->expects($this->once())->method('post')->willThrowException($wrapped);

		try {
			$this->client($httpClient)->post('https://service.example.test/completions');
			$this->fail('The guard exception must survive transport wrapping.');
		} catch (UnexpectedContentEncodingException $e) {
			$this->assertSame($guardException, $e);
		}
	}

	public function testUnrelatedTransportFailureIsPreservedWithoutRetry(): void {
		$transportException = new \RuntimeException('TLS verification failed', 0, new \LogicException('Certificate mismatch'));
		$httpClient = $this->httpClient('get');
		$httpClient->expects($this->once())->method('get')->willThrowException($transportException);

		try {
			$this->client($httpClient)->get('https://service.example.test/file');
			$this->fail('Unrelated transport failures must not be swallowed or rewritten.');
		} catch (\RuntimeException $e) {
			$this->assertSame($transportException, $e);
		}
	}

	public function testOriginalHeaderCallbackFailureIsPreserved(): void {
		$callbackException = new \RuntimeException('Application rejected the response headers');
		$httpClient = $this->httpClient('get');
		$httpClient->expects($this->once())->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
			$options['on_headers']($this->headers(''));
			$this->fail('A failed header callback must abort the request.');
		});

		try {
			$this->client($httpClient)->get('https://service.example.test/file', [
				'on_headers' => static function () use ($callbackException): void {
					throw $callbackException;
				},
			]);
			$this->fail('The original header callback must still be honored.');
		} catch (\RuntimeException $e) {
			$this->assertSame($callbackException, $e);
		}
	}

	private function client(IClient $httpClient): IdentityHttpClient {
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->once())->method('newClient')->willReturn($httpClient);
		return IdentityHttpClient::fromService($clientService);
	}

	private function httpClient(string $method): IClient&MockObject {
		$builder = $this->getMockBuilder(IClient::class)->onlyMethods(get_class_methods(IClient::class));
		if (!method_exists(IClient::class, $method)) {
			$builder->addMethods([$method]);
		}
		return $builder->getMock();
	}

	private function response(string $encoding): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getHeader')->with('Content-Encoding')->willReturn($encoding);
		$response->expects($this->never())->method('getBody');
		return $response;
	}

	private function headers(string $encoding): object {
		return new class($encoding) {
			public function __construct(private string $encoding) {
			}

			public function getHeaderLine(string $name): string {
				return strtolower($name) === 'content-encoding' ? $this->encoding : '';
			}
		};
	}
}
