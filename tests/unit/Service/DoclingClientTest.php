<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use OCA\EducAI\Exception\DoclingEmptyResultException;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\SettingsService;
use OCP\Files\File;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoclingClientTest extends TestCase {
	/** @var list<string> */
	private array $httpViolations = [];
	/** @var list<array<string,list<IResponse|\Throwable>>> */
	private array $httpScripts = [];

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

	public function testDocumentWithoutTextIsReportedAsEmptyRatherThanAsAFailure(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturn($this->response(['status' => 'success', 'document' => ['md_content' => " \n"], 'errors' => []]));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$infos = [];
		$logger->method('info')->willReturnCallback(static function (string $message) use (&$infos): void { $infos[] = $message; });

		try {
			$this->createDoclingClient($client, ['docling_api_profile' => 'docling_serve', 'docling_api_endpoint' => 'https://d.example'], logger: $logger)
				->convertBinaryToMarkdown('photo.jpg', 'JPEG', 'image/jpeg');
			$this->fail('A document without text must be reported');
		} catch (DoclingEmptyResultException $e) {
			$this->assertStringContainsString('no Markdown', $e->getMessage());
		}
		$this->assertContains('Docling found no text in the document', $infos);
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

	public function testDetectWithBlankUrlUsesTheAcademicCloudDefaultAndMainKeyFallback(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'POST https://chat-ai.academiccloud.de/v1/documents/convert' => [$this->response(['markdown' => '# Test'])],
		], $calls);
		$result = $this->detectionClient($http)->detect(null, null);
		$this->assertDetection([
			'success' => true, 'profile' => 'legacy', 'auth_mode' => 'bearer', 'service' => 'AcademicCloud / EDUC',
			'title' => null, 'version' => null, 'source' => 'default', 'endpoint' => '',
			'conversion_url' => 'https://chat-ai.academiccloud.de/v1/documents/convert', 'error' => null,
		], $result);
		$this->assertSame([['POST', 'https://chat-ai.academiccloud.de/v1/documents/convert',
			['Accept' => 'application/json', 'Authorization' => 'Bearer main-key']]], $calls);
	}

	public function testDetectRecognisesConversionPathsWithoutDiscovery(): void {
		foreach ([
			['https://d.example/prefix/v1/convert/file/', 'docling_serve', 'https://d.example/prefix/v1/convert/file'],
			['https://d.example/v1/convert', 'docling_serve', 'https://d.example/v1/convert/file'],
			['https://d.example/prefix/v1/documents/convert', 'legacy', 'https://d.example/prefix/v1/documents/convert'],
		] as [$input, $profile, $url]) {
			$calls = [];
			$http = $this->scriptedHttp(['POST ' . $url => [$this->response($profile === 'legacy'
				? ['markdown' => '# Test'] : ['status' => 'success', 'document' => ['md_content' => '# Test']])]], $calls);
			$result = $this->detectionClient($http, 'docling-key')->detect(' ' . $input . ' ', null);
			$this->assertTrue($result['success'], $input);
			$this->assertSame($profile, $result['profile']);
			$this->assertSame($profile === 'legacy' ? 'bearer' : 'x_api_key', $result['auth_mode']);
			$this->assertSame('path', $result['source']);
			$this->assertSame($input, $result['endpoint']);
			$this->assertSame($url, $result['conversion_url']);
			$this->assertCount(1, $calls, 'A known conversion path needs no discovery request');
		}
	}

	public function testDetectReadsOpenApiForDoclingServeAndUsesTheTypedKey(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [$this->response($this->openApi(['/v1/convert/file', '/v1/convert/source', '/health']))],
			'POST https://d.example/v1/convert/file' => [$this->response($this->serveSuccess())],
		], $calls);
		$result = $this->detectionClient($http, 'stored-key')->detect('https://d.example/', 'typed-key');
		$this->assertDetection([
			'success' => true, 'profile' => 'docling_serve', 'auth_mode' => 'x_api_key', 'service' => 'Docling Serve',
			'title' => 'Docling Serve', 'version' => '1.36.0', 'source' => 'openapi', 'endpoint' => 'https://d.example/',
			'conversion_url' => 'https://d.example/v1/convert/file', 'error' => null,
		], $result);
		$this->assertSame([
			['GET', 'https://d.example/openapi.json', ['Accept' => 'application/json']],
			['POST', 'https://d.example/v1/convert/file', ['Accept' => 'application/json', 'X-Api-Key' => 'typed-key']],
		], $calls);
	}

	public function testDetectReadsOpenApiForLegacyAndSendsTheMainKeyOnlyToTheSavedEndpoint(): void {
		foreach ([
			'new address' => [null, 'none', ['Accept' => 'application/json']],
			'saved legacy endpoint' => ['https://d.example/v1', 'bearer', ['Accept' => 'application/json', 'Authorization' => 'Bearer main-key']],
		] as $case => [$savedEndpoint, $authMode, $headers]) {
			$calls = [];
			$http = $this->scriptedHttp([
				'GET https://d.example/openapi.json' => [$this->response($this->openApi(['/v1/documents/convert'], 'EDUC Docling API', '0.4'))],
				'POST https://d.example/v1/documents/convert' => [$this->response(['markdown' => '# Test'])],
			], $calls);
			$result = $this->detectionClient($http, savedEndpoint: $savedEndpoint)->detect('https://d.example/v1', null);
			$this->assertSame('legacy', $result['profile'], $case);
			$this->assertSame($authMode, $result['auth_mode'], $case);
			$this->assertSame('AcademicCloud / EDUC', $result['service'], $case);
			$this->assertSame(['EDUC Docling API', '0.4', 'openapi'], [$result['title'], $result['version'], $result['source']], $case);
			$this->assertSame('https://d.example/v1', $result['endpoint'], $case);
			$this->assertTrue($result['success'], $case);
			$this->assertSame($headers, $calls[1][2], $case);
		}
	}

	public function testDetectSendsTheMainKeyToTheTypedAcademicCloudDefaultButAsksForAKeyElsewhere(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'POST https://chat-ai.academiccloud.de/v1/documents/convert' => [$this->response(['markdown' => '# Test'])],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://chat-ai.academiccloud.de/v1/documents/convert', null);
		$this->assertTrue($result['success']);
		$this->assertSame(['Accept' => 'application/json', 'Authorization' => 'Bearer main-key'], $calls[0][2]);

		$calls = [];
		$http = $this->scriptedHttp([
			'POST https://typo.example/v1/documents/convert' => [$this->response(['detail' => 'Not authenticated'], 401)],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://typo.example/v1/documents/convert', null);
		$this->assertFalse($result['success']);
		$this->assertSame(['legacy', 'none'], [$result['profile'], $result['auth_mode']]);
		$this->assertSame(['Accept' => 'application/json'], $calls[0][2]);
	}

	public function testDetectCanIgnoreAStoredKeyThatIsBeingRemoved(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getDoclingConfig')->willReturnCallback(static fn (?string $profile = null, ?string $auth = null): array => [
			'docling_enabled' => true, 'docling_api_endpoint' => null,
			'docling_api_profile' => $profile ?? 'docling_serve', 'docling_auth_mode' => $auth ?? 'x_api_key',
			'api_key' => ($auth ?? 'x_api_key') === 'none' ? '' : 'stored-key',
		]);
		$settings->method('getApiKey')->willReturn('main-key');

		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [$this->response($this->openApi(['/v1/convert/file']))],
			'POST https://d.example/v1/convert/file' => [$this->response($this->serveSuccess())],
		], $calls);
		$result = $this->createDoclingClient($http, settings: $settings)->detect('https://d.example', null, true);
		$this->assertTrue($result['success']);
		$this->assertSame('none', $result['auth_mode'], 'An auth-free server is not credited to a key that is being removed');
		$this->assertSame(['Accept' => 'application/json'], $calls[0][2]);
		$this->assertSame(['Accept' => 'application/json'], $calls[1][2]);

		$calls = [];
		$http = $this->scriptedHttp([
			'POST https://d.example/educ/v1/documents/convert' => [$this->response(['markdown' => '# Test'])],
		], $calls);
		$result = $this->createDoclingClient($http, settings: $settings)->detect('https://d.example/educ/v1/documents/convert', '', true);
		$this->assertTrue($result['success']);
		$this->assertSame(['Accept' => 'application/json'], $calls[0][2],
			'An unsaved address gets neither the removed dedicated key nor the main key');
	}

	public function testDetectPrefersDoclingServeWhenOpenApiListsBothRoutes(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [$this->response($this->openApi(['/v1/documents/convert', '/v1/convert/file']))],
			'POST https://d.example/v1/convert/file' => [$this->response($this->serveSuccess())],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://d.example', null);
		$this->assertSame('docling_serve', $result['profile']);
		$this->assertSame('none', $result['auth_mode']);
		$this->assertSame(['Accept' => 'application/json'], $calls[1][2]);
	}

	public function testDetectUsesTheVersionEndpointWhenOpenApiIsUnavailableOrInconclusive(): void {
		foreach ([$this->response('missing', 404), $this->response($this->openApi(['/health'])), $this->response('<html>docs</html>')] as $openApi) {
			$calls = [];
			$http = $this->scriptedHttp([
				'GET https://d.example/openapi.json' => [$openApi],
				'GET https://d.example/version' => [$this->response(['docling-serve' => '1.5.0', 'docling' => '2.40.0'])],
				'POST https://d.example/v1/convert/file' => [$this->response($this->serveSuccess())],
			], $calls);
			$result = $this->detectionClient($http)->detect('https://d.example/openapi.json', null);
			$this->assertSame(['docling_serve', 'version', '1.5.0', null], [$result['profile'], $result['source'], $result['version'], $result['title']]);
			$this->assertSame('https://d.example', $result['endpoint'], 'An OpenAPI URL is stored as the server base');
			$this->assertTrue($result['success']);
			$this->assertCount(3, $calls);
		}
	}

	public function testDetectProbesDoclingServeBeforeLegacyAndOnlyAfterNotFound(): void {
		foreach ([404, 405] as $status) {
			$calls = [];
			$http = $this->scriptedHttp([
				'GET https://d.example/openapi.json' => [$this->response('missing', 404)],
				'GET https://d.example/version' => [$this->response('missing', 404)],
				'POST https://d.example/v1/convert/file' => [$this->response(['detail' => 'Not Found'], $status)],
				'POST https://d.example/v1/documents/convert' => [$this->response(['markdown' => '# Test'])],
			], $calls);
			$result = $this->detectionClient($http)->detect('https://d.example', null);
			$this->assertSame(['legacy', 'none', 'probe', true], [$result['profile'], $result['auth_mode'], $result['source'], $result['success']]);
			$this->assertSame('https://d.example/v1/documents/convert', $result['conversion_url']);
			$this->assertSame(['Accept' => 'application/json'], $calls[2][2]);
			$this->assertSame(['Accept' => 'application/json'], $calls[3][2], 'The main key never goes to an unsaved address');
		}
	}

	public function testDetectNeverProbesLegacyAfterServeTimeoutServerErrorOrAuthenticationFailure(): void {
		foreach ([
			[new Exception('cURL error 28: Operation timed out for https://d.example/?token=private-secret'), null, 'timed out'],
			[$this->response(['detail' => 'private-body'], 500), 'docling_serve', 'HTTP 500'],
			[$this->response(['detail' => 'private-body'], 503), 'docling_serve', 'HTTP 503'],
			[$this->response(['detail' => 'private-body'], 401), 'docling_serve', 'authentication failed'],
		] as [$serveAnswer, $profile, $message]) {
			$calls = [];
			$http = $this->scriptedHttp([
				'GET https://d.example/openapi.json' => [$this->response('missing', 404)],
				'GET https://d.example/version' => [$this->response('missing', 404)],
				'POST https://d.example/v1/convert/file' => [$serveAnswer],
			], $calls);
			$result = $this->detectionClient($http)->detect('https://d.example', null);
			$this->assertCount(3, $calls, 'Legacy must not be probed after ' . $message);
			$this->assertFalse($result['success']);
			$this->assertSame($profile, $result['profile']);
			$this->assertSame($profile === null ? null : 'probe', $result['source']);
			$this->assertStringContainsString($message, $result['error']);
			$this->assertStringNotContainsString('private', json_encode($result));
		}
	}

	public function testDetectReportsUndetectableServerWhenNoRouteExists(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/prefix/openapi.json' => [$this->response('missing', 404)],
			'GET https://d.example/prefix/version' => [$this->response(['version' => 'other service'])],
			'POST https://d.example/prefix/v1/convert/file' => [$this->response('missing', 404)],
			'POST https://d.example/prefix' => [$this->response('missing', 405)],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://d.example/prefix', null);
		$this->assertDetection([
			'success' => false, 'profile' => null, 'auth_mode' => null, 'service' => null, 'title' => null,
			'version' => null, 'source' => null, 'endpoint' => 'https://d.example/prefix',
			'conversion_url' => 'https://d.example/prefix',
			'error' => 'No Docling API was detected at this address. Check the URL or enter the full conversion endpoint.',
		], $result);
		$this->assertCount(4, $calls);
	}

	public function testDetectSelectsDoclingServeAuthentication(): void {
		$url = 'https://d.example/v1/convert/file';
		$noKey = ['Accept' => 'application/json'];
		$apiKey = ['Accept' => 'application/json', 'X-Api-Key' => 'docling-key'];
		$bearer = ['Accept' => 'application/json', 'Authorization' => 'Bearer docling-key'];
		foreach ([
			'no key' => [null, [$this->response($this->serveSuccess())], [$noKey], 'none', true, null],
			'stored key' => ['docling-key', [$this->response($this->serveSuccess())], [$apiKey], 'x_api_key', true, null],
			'bearer gateway' => ['docling-key', [$this->response([], 401), $this->response($this->serveSuccess())], [$apiKey, $bearer], 'bearer', true, null],
			'wrong key' => ['docling-key', [$this->response([], 403), $this->response([], 401)], [$apiKey, $bearer], 'x_api_key', false, 'authentication failed (HTTP 403)'],
			'bearer accepted, conversion failed' => ['docling-key', [$this->response([], 401), $this->response([], 500)], [$apiKey, $bearer], 'bearer', false, 'HTTP 500'],
			'no retry after other failures' => ['docling-key', [$this->response([], 500)], [$apiKey], 'x_api_key', false, 'HTTP 500'],
		] as $case => [$storedKey, $answers, $headers, $authMode, $success, $message]) {
			$calls = [];
			$result = $this->detectionClient($this->scriptedHttp(['POST ' . $url => $answers], $calls), $storedKey)->detect($url, null);
			$this->assertSame($headers, array_column($calls, 2), $case);
			$this->assertSame('docling_serve', $result['profile'], $case);
			$this->assertSame($authMode, $result['auth_mode'], $case);
			$this->assertSame($success, $result['success'], $case);
			$message === null ? $this->assertNull($result['error']) : $this->assertStringContainsString($message, $result['error'], $case);
		}
	}

	public function testDetectRetriesAnAuthenticatedOpenApiDocumentOnlyWhenAKeyIsAvailable(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [$this->response([], 401), $this->response([], 403), $this->response($this->openApi(['/v1/convert/file']))],
			'POST https://d.example/v1/convert/file' => [$this->response($this->serveSuccess())],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://d.example', 'typed-key');
		$this->assertSame(['openapi', 'x_api_key', true], [$result['source'], $result['auth_mode'], $result['success']]);
		$this->assertSame([
			['Accept' => 'application/json'],
			['Accept' => 'application/json', 'X-Api-Key' => 'typed-key'],
			['Accept' => 'application/json', 'Authorization' => 'Bearer typed-key'],
		], array_column(array_slice($calls, 0, 3), 2));
		foreach (array_slice($calls, 0, 3) as $call) {
			$this->assertSame('GET', $call[0]);
		}

		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [$this->response([], 401)],
			'GET https://d.example/version' => [$this->response([], 401)],
			'POST https://d.example/v1/convert/file' => [$this->response([], 401)],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://d.example', null);
		$this->assertCount(3, $calls, 'Without a key there is nothing to retry');
		$this->assertSame(['docling_serve', 'none', 'probe', false], [$result['profile'], $result['auth_mode'], $result['source'], $result['success']]);
		$this->assertStringContainsString('authentication failed', $result['error']);
	}

	public function testDetectSanitizesRemoteTitleAndVersion(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [$this->response($this->openApi(['/v1/convert/file'],
				"\u{0000}Docling\u{001B}[31m Serve\u{202E}\n", str_repeat('9', 200)))],
			'POST https://d.example/v1/convert/file' => [$this->response($this->serveSuccess())],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://d.example', null);
		$this->assertSame('Docling[31m Serve', $result['title']);
		$this->assertSame(str_repeat('9', 80), $result['version']);

		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [$this->response(['paths' => ['/v1/convert/file' => []], 'info' => ['title' => ['x'], 'version' => "\u{0007}"]])],
			'POST https://d.example/v1/convert/file' => [$this->response($this->serveSuccess())],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://d.example', null);
		$this->assertNull($result['title']);
		$this->assertNull($result['version']);
	}

	public function testDetectStoresAReachableEndpointForPrefixedServers(): void {
		foreach ([
			'legacy behind a prefix' => ['https://d.example/prefix', ['/v1/documents/convert'], 'https://d.example/prefix/v1/documents/convert', 'https://d.example/prefix/v1/documents/convert'],
			'legacy prefix route' => ['https://d.example/prefix/', ['/api/v1/documents/convert'], 'https://d.example/prefix/api/v1/documents/convert', 'https://d.example/prefix/api/v1/documents/convert'],
			'unsafe remote route' => ['https://d.example/prefix', ['/../evil/v1/documents/convert'], 'https://d.example/prefix/v1/documents/convert', 'https://d.example/prefix/v1/documents/convert'],
			'expandable legacy base' => ['https://d.example/prefix/v1', ['/v1/documents/convert'], 'https://d.example/prefix/v1', 'https://d.example/prefix/v1/documents/convert'],
			'serve docs page' => ['https://d.example/prefix/docs', ['/v1/convert/file'], 'https://d.example/prefix', 'https://d.example/prefix/v1/convert/file'],
			'serve prefix' => ['https://d.example/prefix', ['/v1/convert/file'], 'https://d.example/prefix', 'https://d.example/prefix/v1/convert/file'],
			'query retained' => ['https://d.example/prefix?tenant=a', ['/v1/documents/convert'], 'https://d.example/prefix/v1/documents/convert?tenant=a', 'https://d.example/prefix/v1/documents/convert?tenant=a'],
		] as $case => [$input, $routes, $endpoint, $postUrl]) {
			$calls = [];
			$legacy = str_contains($postUrl, '/documents/');
			$http = $this->scriptedHttp([
				'GET https://d.example/prefix/openapi.json' => [$this->response($this->openApi($routes))],
				'POST ' . $postUrl => [$this->response($legacy ? ['markdown' => '# Test'] : $this->serveSuccess())],
			], $calls);
			$result = $this->detectionClient($http)->detect($input, null);
			$this->assertTrue($result['success'], $case);
			$this->assertSame($endpoint, $result['endpoint'], $case);
			$this->assertSame(strtok($postUrl, '?'), $result['conversion_url'], $case);
		}
	}

	public function testDetectKeepsTheQueryForStorageButNeverReturnsItInTheConversionUrl(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'POST https://d.example/v1/convert/file?token=private-secret' => [$this->response($this->serveSuccess())],
		], $calls);
		$result = $this->detectionClient($http)->detect('https://d.example/v1/convert/file?token=private-secret', null);
		$this->assertSame('https://d.example/v1/convert/file?token=private-secret', $result['endpoint']);
		$this->assertSame('https://d.example/v1/convert/file', $result['conversion_url']);
	}

	public function testDetectRejectsInvalidInputBeforeAnyRequest(): void {
		foreach ([
			['/relative', null, 'HTTP(S) URL'],
			['file:///tmp/document', null, 'HTTP(S) URL'],
			['ftp://d.example/', null, 'HTTP(S) URL'],
			['https://user:private-secret@d.example/', null, 'embedded credentials'],
			['https://d.example/#fragment', null, 'fragment'],
			['https://d.example/v1/convert/source', null, 'expects JSON'],
			['https://d.example/v1/convert/source/async', null, 'expects JSON'],
			['https://d.example/v1/convert/file/async', null, 'Async Docling'],
			['https://d.example', "private\r\nsecret", 'control characters'],
		] as [$endpoint, $key, $message]) {
			$client = $this->createMock(IClient::class);
			$client->expects($this->never())->method('get');
			$client->expects($this->never())->method('post');
			$settings = $this->createMock(SettingsService::class);
			$settings->expects($this->never())->method('getDoclingConfig');
			try {
				$this->createDoclingClient($client, settings: $settings)->detect($endpoint, $key);
				$this->fail('Invalid input accepted: ' . $endpoint);
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
				$this->assertStringNotContainsString('private', $e->getMessage());
				$this->assertStringNotContainsString('d.example', $e->getMessage());
			}
		}
	}

	public function testDetectNeverReturnsUpstreamBodiesTransportDetailsOrKeys(): void {
		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [new Exception('Connection refused for https://d.example/?key=private-secret private-body')],
		], $calls);
		$result = $this->detectionClient($http, 'private-key')->detect('https://d.example', null);
		$this->assertCount(1, $calls, 'An unreachable server is neither queried again nor probed');
		$this->assertSame(['success' => false, 'profile' => null, 'source' => null, 'conversion_url' => null, 'duration_ms' => null],
			array_intersect_key($result, array_flip(['success', 'profile', 'source', 'conversion_url', 'duration_ms'])));
		$this->assertSame('Docling could not be reached. Check the server address, connectivity and TLS configuration.', $result['error']);

		$calls = [];
		$http = $this->scriptedHttp([
			'GET https://d.example/openapi.json' => [$this->response('private-body', 500)],
			'GET https://d.example/version' => [$this->response(['docling-serve' => ['private-body']])],
			'POST https://d.example/v1/convert/file' => [$this->response(['detail' => 'private-body', 'errors' => ['private-body']])],
		], $calls);
		$result = $this->detectionClient($http, 'private-key')->detect('https://d.example', null);
		$this->assertSame(['docling_serve', 'probe', false], [$result['profile'], $result['source'], $result['success']]);
		$this->assertStringContainsString('conversion errors', $result['error']);
		$this->assertStringNotContainsString('private', json_encode($result));
	}

	public function testDetectReportsUnreadableSettingsWithoutAnyRequest(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getDoclingConfig')->willThrowException(new \RuntimeException('cannot decrypt private-secret'));
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->never())->method('post');
		$result = $this->createDoclingClient($client, settings: $settings)->detect('https://d.example', null);
		$this->assertFalse($result['success']);
		$this->assertNull($result['profile']);
		$this->assertSame('Unable to read Docling settings. Check the saved configuration.', $result['error']);
	}

	public function testConnectionCheckTargetMatchesOnlyTheSameEffectiveTarget(): void {
		$saved = ['docling_enabled' => true, 'docling_api_endpoint' => 'https://d.example/', 'docling_api_profile' => 'docling_serve',
			'docling_auth_mode' => 'x_api_key', 'api_key' => 'saved-key'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getDoclingConfig')->willReturnCallback(static fn (?string $profile = null, ?string $auth = null): array
			=> ['docling_api_profile' => $profile ?? $saved['docling_api_profile'], 'docling_auth_mode' => $auth ?? $saved['docling_auth_mode'],
				'api_key' => ($auth ?? $saved['docling_auth_mode']) === 'none' ? '' : $saved['api_key']] + $saved);
		$docling = $this->createDoclingClient($this->createMock(IClient::class), settings: $settings);
		$current = $docling->connectionCheckTarget();
		$this->assertSame('Docling Serve · x_api_key', $current['summary']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $current['fingerprint']);
		$this->assertSame($current, $docling->connectionCheckTarget('https://d.example/v1/convert/file', 'saved-key', 'docling_serve', 'x_api_key'));
		$this->assertNotSame($current['fingerprint'], $docling->connectionCheckTarget(null, 'typed-key')['fingerprint']);
		$this->assertNotSame($current['fingerprint'], $docling->connectionCheckTarget('https://other.example')['fingerprint']);
		$this->assertNotSame($current['fingerprint'], $docling->connectionCheckTarget(null, null, null, 'bearer')['fingerprint']);
		$none = $docling->connectionCheckTarget(null, 'ignored-key', null, 'none');
		$this->assertSame($none, $docling->connectionCheckTarget(null, null, null, 'none'), 'No-auth tests do not depend on a key');
		$this->assertStringNotContainsString('saved-key', json_encode([$current, $none]));
	}

	protected function assertPostConditions(): void {
		// The client catches transport exceptions, so violations are collected instead of asserted inline.
		$this->assertSame([], $this->httpViolations);
		foreach ($this->httpScripts as $script) {
			$this->assertSame([], array_filter($script), 'Every scripted answer must be requested');
		}
	}

	/**
	 * @param array<string,list<IResponse|\Throwable>> $script "METHOD url" => answers in request order
	 * @param array<int,array{0:string,1:string,2:array<string,string>}> $calls
	 */
	private function scriptedHttp(array $script, array &$calls): IClient {
		$this->httpScripts[] = &$script;
		$client = $this->createMock(IClient::class);
		$answer = function (string $method, string $url, array $options) use (&$script, &$calls): IResponse {
			$calls[] = [$method, $url, $options['headers'] ?? []];
			$key = $method . ' ' . $url;
			if (($options['allow_redirects'] ?? true) !== false || ($options['http_errors'] ?? true) !== false
				|| ($options['timeout'] ?? PHP_INT_MAX) > ($method === 'GET' ? 10 : 60)) {
				$this->httpViolations[] = 'Unbounded or redirecting request: ' . $key;
			}
			if (($script[$key] ?? []) === []) {
				$this->httpViolations[] = 'Unexpected request: ' . $key;
				throw new \RuntimeException('Unexpected request');
			}
			$next = array_shift($script[$key]);
			if ($next instanceof \Throwable) {
				throw $next;
			}
			return $next;
		};
		$client->method('get')->willReturnCallback(static fn (string $url, array $options = []): IResponse => $answer('GET', $url, $options));
		$client->method('post')->willReturnCallback(static fn (string $url, array $options = []): IResponse => $answer('POST', $url, $options));
		return $client;
	}

	/**
	 * Saved settings without an endpoint: a dedicated key (if any) and a main API key.
	 */
	private function detectionClient(IClient $client, ?string $dedicatedKey = null, ?string $savedEndpoint = null): DoclingClient {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getDoclingConfig')->willReturnCallback(static function (?string $profile = null, ?string $auth = null) use ($dedicatedKey, $savedEndpoint): array {
			$profile ??= 'legacy';
			$auth ??= 'bearer';
			return [
				'docling_enabled' => false, 'docling_api_endpoint' => $savedEndpoint,
				'docling_api_profile' => $profile, 'docling_auth_mode' => $auth,
				'api_key' => $auth === 'none' ? '' : ($dedicatedKey ?? ($profile === 'legacy' && $auth === 'bearer' ? 'main-key' : null)),
			];
		});
		$settings->method('getApiKey')->willReturn('main-key');
		return $this->createDoclingClient($client, settings: $settings);
	}

	/**
	 * @param array<string,mixed> $expected
	 * @param array<string,mixed> $result
	 */
	private function assertDetection(array $expected, array $result): void {
		$this->assertIsInt($result['duration_ms']);
		$this->assertGreaterThanOrEqual(0, $result['duration_ms']);
		unset($result['duration_ms']);
		ksort($expected);
		ksort($result);
		$this->assertSame($expected, $result);
	}

	/**
	 * @param list<string> $routes
	 * @return array<string,mixed>
	 */
	private function openApi(array $routes, string $title = 'Docling Serve', string $version = '1.36.0'): array {
		return ['openapi' => '3.1.0', 'info' => ['title' => $title, 'version' => $version],
			'paths' => array_fill_keys($routes, ['post' => ['summary' => 'private-body']])];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function serveSuccess(): array {
		return ['status' => 'success', 'document' => ['md_content' => '# Test'], 'errors' => []];
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
