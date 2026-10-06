<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\SettingsService;
use OCP\Files\File;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoclingClientTest extends TestCase {
	public function testBothProfilesShareTheSameUploadAndAdminTestContract(): void {
		foreach (['legacy', 'docling_serve'] as $profile) {
			foreach (['bearer', 'x_api_key', 'none'] as $auth) {
				$config = ['docling_api_profile' => $profile, 'docling_auth_mode' => $auth,
					'docling_api_endpoint' => 'https://docling.example/prefix', 'api_key' => 'docling-key'];
				$client = $this->createMock(IClient::class);
				$client->expects($this->exactly(2))->method('post')->willReturnCallback(function (string $url, array $options) use ($profile, $auth): IResponse {
					$this->assertSame('https://docling.example/prefix' . ($profile === 'docling_serve' ? '/v1/convert/file' : ''), $url);
					$headers = ['Accept' => 'application/json'];
					if ($auth !== 'none') {
						$headers[$auth === 'bearer' ? 'Authorization' : 'X-Api-Key'] = ($auth === 'bearer' ? 'Bearer ' : '') . 'docling-key';
					}
					$this->assertSame($headers, $options['headers']);
					$this->assertFalse($options['allow_redirects']);
					$this->assertFalse($options['http_errors']);
					$file = $options['multipart'][0];
					$this->assertSame($profile === 'docling_serve' ? 'files' : 'document', $file['name']);
					$this->assertSame('application/pdf', $file['headers']['Content-Type']);
					$this->assertStringStartsWith('%PDF', $file['contents']);
					if ($file['filename'] === 'educai-docling-test.pdf') {
						$this->assertStringContainsString('(EducAI Docling test)', $file['contents']);
						$this->assertStringContainsString('startxref', $file['contents']);
					} else {
						$this->assertSame('example.pdf', $file['filename']);
						$this->assertSame('%PDF synthetic bytes', $file['contents']);
					}
					$this->assertSame($profile === 'docling_serve' ? [
						['name' => 'to_formats', 'contents' => 'md'],
						['name' => 'target_type', 'contents' => 'inbody'],
					] : [], array_slice($options['multipart'], 1));
					return $this->response($profile === 'docling_serve'
						? ['status' => 'success', 'document' => ['md_content' => '# Converted 🦝'], 'errors' => []]
						: ['markdown' => '# Converted 🦝']);
				});
				$docling = $this->createDoclingClient($client, $config);
				$this->assertSame('# Converted 🦝', $docling->convertBinaryToMarkdown('example.pdf', '%PDF synthetic bytes', 'application/pdf'));
				$this->assertSame(['success' => true], $docling->testConnection());
			}
		}
	}

	public function testOldConfigurationWithoutProfileFieldsStillUsesLegacyBearer(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with(
			'https://docling.example/v1/documents/convert',
			$this->callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Bearer docling-key'
				&& $options['multipart'][0]['name'] === 'document' && count($options['multipart']) === 1)
		)->willReturn($this->response(['markdown' => '# Converted']));
		$this->assertSame('# Converted', $this->createDoclingClient($client)->convertBinaryToMarkdown('example.pdf', '%PDF', 'application/pdf'));
	}

	public function testEnabledUnauthenticatedConversionNeedsNoDummyKey(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with($this->anything(), $this->callback(
			static fn (array $options): bool => $options['headers'] === ['Accept' => 'application/json']
		))->willReturn($this->response(['status' => 'success', 'document' => ['md_content' => 'Converted']]));
		$docling = $this->createDoclingClient($client, ['api_key' => null, 'docling_auth_mode' => 'none',
			'docling_api_profile' => 'docling_serve', 'docling_api_endpoint' => 'http://docling:5001']);
		$this->assertTrue($docling->isEnabled());
		$this->assertSame('Converted', $docling->convertBinaryToMarkdown('file.pdf', '%PDF', 'application/pdf'));
	}

	public function testUnsavedProfileAndAuthAreUsedToResolveTheTestCredential(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->once())->method('getDoclingConfig')->with('docling_serve', 'none')->willReturn([
			'docling_enabled' => false, 'docling_api_profile' => 'docling_serve', 'docling_auth_mode' => 'none',
			'docling_api_endpoint' => 'https://legacy.example/v1/documents/convert', 'api_key' => null,
		]);
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with('http://new-server:5001/v1/convert/file', $this->callback(
			static fn (array $options): bool => $options['headers'] === ['Accept' => 'application/json']
		))->willReturn($this->response(['status' => 'success', 'document' => ['md_content' => 'Test document']]));
		$docling = $this->createDoclingClient($client, settings: $settings);
		$this->assertSame(['success' => true], $docling->testConnection('http://new-server:5001', 'ignored-explicit-key', 'docling_serve', 'none'));
	}

	public function testEndpointResolutionPreservesPrefixesQueriesAndLegacyRoutes(): void {
		foreach ([
			['docling_serve', 'https://d.example', 'https://d.example/v1/convert/file'],
			['docling_serve', 'https://d.example/prefix/', 'https://d.example/prefix/v1/convert/file'],
			['docling_serve', 'https://d.example/prefix/v1/', 'https://d.example/prefix/v1/convert/file'],
			['docling_serve', 'https://d.example/prefix/v1/convert/', 'https://d.example/prefix/v1/convert/file'],
			['docling_serve', 'https://d.example/source/proxy/v1/convert/file', 'https://d.example/source/proxy/v1/convert/file'],
			['docling_serve', 'http://[::1]:5001/prefix/v1/convert/file/?lang=de', 'http://[::1]:5001/prefix/v1/convert/file?lang=de'],
			['docling_serve', 'http://localhost:5001/prefix?lang=de', 'http://localhost:5001/prefix/v1/convert/file?lang=de'],
			['legacy', '', 'https://chat-ai.academiccloud.de/v1/documents/convert'],
			['legacy', 'https://d.example/', 'https://d.example/v1/documents/convert'],
			['legacy', 'https://d.example/prefix/v1/', 'https://d.example/prefix/v1/documents/convert'],
			['legacy', 'https://d.example/custom/?vision=off', 'https://d.example/custom/?vision=off'],
			['legacy', 'https://d.example/prefix/v1/documents/convert?vision=off', 'https://d.example/prefix/v1/documents/convert?vision=off'],
		] as [$profile, $configured, $expected]) {
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())->method('post')->with($expected, $this->anything())
				->willReturn($this->response(['status' => 'success', 'document' => ['md_content' => 'Test'], 'markdown' => 'Test']));
			$this->assertTrue($this->createDoclingClient($client, ['docling_api_profile' => $profile, 'docling_api_endpoint' => $configured])->testConnection()['success'], $configured);
		}
	}

	public function testUnsavedServeProfileWithoutDedicatedKeyDoesNotUpload(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->once())->method('getDoclingConfig')->with('docling_serve', 'x_api_key')->willReturn([
			'docling_enabled' => true, 'docling_api_profile' => 'docling_serve', 'docling_auth_mode' => 'x_api_key',
			'docling_api_endpoint' => 'https://legacy.example/v1/documents/convert', 'api_key' => null,
		]);
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');
		$result = $this->createDoclingClient($client, settings: $settings)->testConnection('http://new-server:5001', null, 'docling_serve', 'x_api_key');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('API key not configured', $result['error']);
	}

	public function testInvalidConfigurationFailsBeforeAnyUpload(): void {
		foreach ([
			[['docling_api_profile' => 'unknown'], 'Invalid Docling API profile'],
			[['docling_auth_mode' => 'unknown'], 'authentication mode'],
			[['api_key' => null], 'API key not configured'],
			[['api_key' => "bad\r\nkey"], 'control characters'],
			[['docling_api_profile' => 'docling_serve', 'docling_api_endpoint' => ''], 'Configure the Docling Serve'],
			[['docling_api_endpoint' => '/relative'], 'HTTP(S) URL'],
			[['docling_api_endpoint' => 'file:///tmp/document'], 'HTTP(S) URL'],
			[['docling_api_endpoint' => 'https://user:secret@d.example/'], 'embedded credentials'],
			[['docling_api_endpoint' => 'https://user@d.example/'], 'embedded credentials'],
			[['docling_api_endpoint' => 'https://d.example/#fragment'], 'fragment'],
			[['docling_api_endpoint' => 'https://d.example/prefix/v1/convert/source?param=1'], 'expects JSON'],
			[['docling_api_profile' => 'docling_serve', 'docling_api_endpoint' => 'https://d.example/v1/convert/source/async'], 'expects JSON'],
			[['docling_api_profile' => 'docling_serve', 'docling_api_endpoint' => 'https://d.example/v1/convert/file/async/'], 'Async Docling'],
			[['docling_api_profile' => 'docling_serve'], 'legacy conversion endpoint'],
			[['docling_api_endpoint' => 'https://d.example/v1/convert/file'], 'Select the Docling Serve'],
		] as [$config, $message]) {
			$client = $this->createMock(IClient::class);
			$client->expects($this->never())->method('post');
			$result = $this->createDoclingClient($client, $config)->testConnection();
			$this->assertFalse($result['success']);
			$this->assertStringContainsString($message, $result['error']);
			$this->assertStringNotContainsString('secret', $result['error']);
		}
	}

	public function testDisabledConversionDoesNotUploadButAdminCanTestBeforeEnabling(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturn($this->response(['markdown' => 'Test']));
		$docling = $this->createDoclingClient($client, ['docling_enabled' => false]);
		$this->assertFalse($docling->isEnabled());
		$this->assertTrue($docling->testConnection()['success']);
		$this->expectExceptionMessage('Docling document conversion is disabled');
		$docling->convertBinaryToMarkdown('file.pdf', '%PDF', 'application/pdf');
	}

	public function testPartialFailedMalformedAndEmptyResponsesAreRejected(): void {
		foreach ([
			['docling_serve', ['status' => 'partial_success', 'document' => ['md_content' => 'Partial']], 'partial conversion'],
			['docling_serve', ['status' => 'partial_success', 'document' => ['md_content' => 'Partial'], 'errors' => ['private-body']], 'partial conversion'],
			['docling_serve', ['status' => 'failure', 'document' => ['md_content' => 'Failed']], 'successful conversion'],
			['docling_serve', ['status' => 'started', 'document' => ['md_content' => 'Pending']], 'successful conversion'],
			['docling_serve', ['document' => ['md_content' => 'Missing status']], 'successful conversion'],
			['docling_serve', ['status' => 'success', 'errors' => [['error_message' => 'private-body']]], 'conversion errors'],
			['docling_serve', ['status' => 'success', 'document' => ['md_content' => " \n"]], 'no Markdown'],
			['docling_serve', ['status' => 'success', 'document' => 'wrong-type'], 'no Markdown'],
			['docling_serve', ['status' => 'success', 'document' => ['md_content' => ['wrong-type']]], 'no Markdown'],
			['docling_serve', ['status' => 'success', 'markdown' => 'Wrong profile'], 'no Markdown'],
			['legacy', ['status' => 'partial_success', 'markdown' => 'Partial'], 'partial conversion'],
			['legacy', ['status' => 'failure', 'markdown' => 'Failed'], 'successful conversion'],
			['legacy', ['markdown' => 'Partial', 'errors' => ['private-body']], 'conversion errors'],
			['legacy', ['markdown' => 'Partial', 'detail' => 'private-body'], 'conversion errors'],
			['legacy', ['markdown' => 'Partial', 'error' => 'private-body'], 'conversion errors'],
			['legacy', ['markdown' => 123], 'no Markdown'],
			['legacy', ['markdown' => ''], 'no Markdown'],
			['legacy', ['document' => ['md_content' => 'Wrong profile']], 'no Markdown'],
			['legacy', ['status' => 'ok'], 'successful conversion'],
			['legacy', true, 'JSON object'],
			['legacy', null, 'JSON object'],
			['legacy', [['markdown' => 'Unexpected list']], 'JSON object'],
			['legacy', '<html>private-body</html>', 'JSON object'],
		] as [$profile, $body, $message]) {
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())->method('post')->willReturn($this->response($body));
			$result = $this->createDoclingClient($client, ['docling_api_profile' => $profile, 'docling_api_endpoint' => 'https://d.example'])->testConnection();
			$this->assertFalse($result['success']);
			$this->assertStringContainsString($message, $result['error']);
			$this->assertStringNotContainsString('private-body', $result['error']);
		}
	}

	public function testHttpFailuresAreActionableAndNeverResubmitOrExposeResponseBody(): void {
		foreach ([401 => 'authentication failed', 403 => 'authentication failed', 404 => 'endpoint not found',
			422 => 'file-upload endpoint', 413 => 'document size', 408 => 'timed out', 504 => 'timed out',
			500 => 'not retried', 502 => 'not retried', 503 => 'not retried', 429 => 'not retried', 307 => 'redirect'] as $status => $message) {
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())->method('post')->willReturn($this->response(['detail' => 'private-response'], $status));
			$result = $this->createDoclingClient($client)->testConnection();
			$this->assertFalse($result['success']);
			$this->assertStringContainsString($message, $result['error']);
			$this->assertStringNotContainsString('private-response', $result['error']);
		}
	}

	public function testTransportFailuresNeverResubmitOrLeakTheirException(): void {
		foreach (['cURL error 28: timed out', 'Connection reset', 'Connection refused', 'TLS validation failed'] as $reason) {
			$client = $this->createMock(IClient::class);
			$client->expects($this->once())->method('post')->willThrowException(new Exception($reason . ': https://d.example/?key=private-secret private-document'));
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('error')->with('Docling conversion failed', $this->callback(static fn (array $context): bool => !str_contains(json_encode($context), 'private-') && !isset($context['exception'])));
			$docling = $this->createDoclingClient($client, logger: $logger);
			try {
				$docling->convertBinaryToMarkdown('test.pdf', '%PDF', 'application/pdf');
				$this->fail('Transport failure must be rejected');
			} catch (Exception $e) {
				$this->assertStringNotContainsString('private-', $e->getMessage());
				$this->assertStringNotContainsString('https://', $e->getMessage());
				$this->assertNull($e->getPrevious());
				$this->assertStringContainsString(str_contains($reason, 'timed out') ? 'no automatic retry' : 'not retried', $e->getMessage());
			}
		}
	}

	public function testLegacyInformationalWarningsAreCountedWithoutLoggingDocumentOrProviderText(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturn($this->response([
			'markdown' => 'Document', 'metadata' => ['warnings' => ['private diagnostic: lightweight parser used']],
		]));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Docling conversion returned warnings; consult the conversion server logs', ['warningCount' => 1]);
		$this->assertSame('Document', $this->createDoclingClient($client, logger: $logger)->convertBinaryToMarkdown('test.pdf', '%PDF', 'application/pdf'));
	}

	public function testFileConversionUsesTheSharedAdapterAndRetainsLargeDocumentTimeout(): void {
		$contents = str_repeat('x', 9 * 1024 * 1024);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('manual.pdf');
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getContent')->willReturn($contents);
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with($this->anything(), $this->callback(
			static fn (array $options): bool => $options['timeout'] === 540
				&& $options['multipart'][0]['filename'] === 'manual.pdf' && $options['multipart'][0]['contents'] === $contents
		))->willReturn($this->response(['markdown' => 'Long document']));
		$this->assertSame('Long document', $this->createDoclingClient($client)->convertToMarkdown($file));
	}

	private function response(mixed $payload, int $status = 200): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR));
		return $response;
	}

	private function createDoclingClient(IClient $client, array $config = [], ?SettingsService $settings = null, ?LoggerInterface $logger = null): DoclingClient {
		if ($settings === null) {
			$settings = $this->createMock(SettingsService::class);
			$settings->method('getDoclingConfig')->willReturn($config + [
				'docling_enabled' => true,
				'docling_api_endpoint' => 'https://docling.example/v1/documents/convert',
				'api_key' => 'docling-key',
			]);
		}
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		return new DoclingClient($clientService, $settings, $logger ?? $this->createMock(LoggerInterface::class));
	}
}
