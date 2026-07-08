<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoclingClientTest extends TestCase {
	public function testConvertBinaryUsesMultipartAndReturnsMarkdown(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')
			->willReturn(json_encode(['markdown' => '# Converted']) ?: '');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with(
				'https://docling.example/v1/documents/convert',
				$this->callback(static function (array $options): bool {
					return ($options['headers']['Authorization'] ?? '') === 'Bearer docling-key'
						&& ($options['headers']['Accept'] ?? '') === 'application/json'
						&& !isset($options['headers']['Content-Type'])
						&& ($options['multipart'][0]['name'] ?? null) === 'document'
						&& ($options['multipart'][0]['filename'] ?? null) === 'example.pdf'
						&& ($options['multipart'][0]['contents'] ?? null) === '%PDF'
						&& ($options['multipart'][0]['headers']['Content-Type'] ?? null) === 'application/pdf';
				})
			)
			->willReturn($response);

		$doclingClient = $this->createDoclingClient($client);

		$this->assertSame('# Converted', $doclingClient->convertBinaryToMarkdown('example.pdf', '%PDF', 'application/pdf'));
	}

	public function testConvertBinaryUsesLongerTimeoutForLargeDocuments(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')
			->willReturn(json_encode(['markdown' => '# Large document']) ?: '');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with(
				'https://docling.example/v1/documents/convert',
				$this->callback(static function (array $options): bool {
					return ($options['timeout'] ?? null) === 540;
				})
			)
			->willReturn($response);

		$doclingClient = $this->createDoclingClient($client);

		$this->assertSame('# Large document', $doclingClient->convertBinaryToMarkdown('manual.pdf', str_repeat('x', 9 * 1024 * 1024), 'application/pdf'));
	}

	public function testConvertBinaryRetriesRetryableServerErrors(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')
			->willReturn(json_encode(['markdown' => '# Converted after retry']) ?: '');

		$attempt = 0;
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(static function () use (&$attempt, $response): IResponse {
				$attempt++;
				if ($attempt === 1) {
					throw new Exception('Server error: `POST https://docling.example/v1/documents/convert` resulted in a `500 Internal Server Error` response');
				}

				return $response;
			});

		$doclingClient = $this->createDoclingClient($client);

		$this->assertSame('# Converted after retry', $doclingClient->convertBinaryToMarkdown('example.pdf', '%PDF', 'application/pdf'));
	}

	public function testConvertBinaryDoesNotRetryLargeDocumentTimeouts(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willThrowException(new Exception('cURL error 28: Operation timed out after 540000 milliseconds with 0 bytes received'));

		$doclingClient = $this->createDoclingClient($client);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to convert document: cURL error 28');

		$doclingClient->convertBinaryToMarkdown('manual.pdf', str_repeat('x', 9 * 1024 * 1024), 'application/pdf');
	}

	public function testConnectionRequiresMarkdownFromRealPdfConversion(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')
			->willReturn(json_encode(['markdown' => 'EducAI Docling test']) ?: '');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with(
				'https://docling.example/v1/documents/convert',
				$this->callback(static function (array $options): bool {
					return ($options['multipart'][0]['name'] ?? null) === 'document'
						&& ($options['multipart'][0]['filename'] ?? null) === 'educai-docling-test.pdf'
						&& str_starts_with((string)($options['multipart'][0]['contents'] ?? ''), '%PDF-1.4')
						&& ($options['multipart'][0]['headers']['Content-Type'] ?? null) === 'application/pdf';
				})
			)
			->willReturn($response);

		$doclingClient = $this->createDoclingClient($client);

		$this->assertSame(['success' => true], $doclingClient->testConnection());
	}

	public function testConnectionFailsWhenMarkdownIsMissing(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')
			->willReturn(json_encode(['filename' => 'educai-docling-test']) ?: '');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($response);

		$doclingClient = $this->createDoclingClient($client);

		$result = $doclingClient->testConnection();

		$this->assertFalse($result['success']);
		$this->assertSame('Docling returned empty content for document', $result['error']);
	}

	private function createDoclingClient(IClient $client): DoclingClient {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getDoclingConfig')
			->willReturn([
				'docling_enabled' => true,
				'docling_api_endpoint' => 'https://docling.example/v1/documents/convert',
				'api_key' => 'docling-key',
			]);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')
			->willReturn($client);

		return new class($clientService, $settingsService, $this->createLogger()) extends DoclingClient {
			protected function sleepBeforeRetry(int $seconds): void {}
		};
	}

	private function createLogger(): LoggerInterface {
		return new class implements LoggerInterface {
			public function emergency($message, array $context = []): void {}
			public function alert($message, array $context = []): void {}
			public function critical($message, array $context = []): void {}
			public function error($message, array $context = []): void {}
			public function warning($message, array $context = []): void {}
			public function notice($message, array $context = []): void {}
			public function info($message, array $context = []): void {}
			public function debug($message, array $context = []): void {}
			public function log($level, $message, array $context = []): void {}
		};
	}
}
