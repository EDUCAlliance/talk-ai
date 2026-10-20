<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\RagController;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RagControllerTest extends TestCase {
	public function testIndexDoesNotExposeRepositoryFailure(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('getBot')->willThrowException(new \RuntimeException('SQL connection secret'));
		$controller = $this->createController($botService);

		$response = $controller->index(42);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('rag_sources_load_failed', $response->getData()['errorCode']);
		$this->assertSame('Failed to load knowledge sources', $response->getData()['error']);
		$this->assertStringNotContainsString('SQL', $response->getData()['error']);
	}

	public function testIndexReplacesPersistedSourceErrorWithSafeLocalizedPayload(): void {
		$bot = new Bot();
		$bot->setUserId('alice');
		$botService = $this->createMock(BotService::class);
		$botService->method('getBot')->willReturn($bot);

		$source = new BotSource();
		$source->setId(7);
		$source->setBotId(42);
		$source->setOwnerUid('alice');
		$source->setNodeId(0);
		$source->setNodeType('url');
		$source->setSourceUrl('https://example.com/source');
		$source->setStatus('error');
		$source->setErrorMessage('HTTP authorization token leaked');

		$botSourceMapper = $this->createMock(BotSourceMapper::class);
		$botSourceMapper->method('findByBot')->with(42)->willReturn([$source]);
		$controller = $this->createController($botService, $botSourceMapper);

		$response = $controller->index(42);
		$payload = $response->getData()['sources'][0];

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('The knowledge source could not be processed.', $payload['error_message']);
		$this->assertSame('knowledge_source_processing_failed', $payload['source_error_code']);
		$this->assertStringNotContainsString('token', $payload['error_message']);
	}

	public function testIndexClearsSourceErrorFieldsUnlessErrorStatusHasInternalMessage(): void {
		$bot = new Bot();
		$bot->setUserId('alice');
		$botService = $this->createMock(BotService::class);
		$botService->method('getBot')->willReturn($bot);

		$source = new BotSource();
		$source->setBotId(42);
		$source->setOwnerUid('alice');
		$source->setNodeId(0);
		$source->setNodeType('url');
		$source->setSourceUrl('https://example.com/source');
		$source->setStatus('ready');
		$source->setErrorMessage('stale internal failure');

		$sourceWithoutMessage = new BotSource();
		$sourceWithoutMessage->setBotId(42);
		$sourceWithoutMessage->setOwnerUid('alice');
		$sourceWithoutMessage->setNodeId(0);
		$sourceWithoutMessage->setNodeType('url');
		$sourceWithoutMessage->setSourceUrl('https://example.com/other-source');
		$sourceWithoutMessage->setStatus('error');
		$sourceWithoutMessage->setErrorMessage(null);

		$botSourceMapper = $this->createMock(BotSourceMapper::class);
		$botSourceMapper->method('findByBot')->with(42)->willReturn([$source, $sourceWithoutMessage]);
		$controller = $this->createController($botService, $botSourceMapper);

		$response = $controller->index(42);
		$payloads = $response->getData()['sources'];

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($payloads[0]['error_message']);
		$this->assertNull($payloads[0]['source_error_code']);
		$this->assertNull($payloads[1]['error_message']);
		$this->assertNull($payloads[1]['source_error_code']);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>,3:string,4:string}>
	 */
	public static function categorizedFailures(): array {
		return [
			'missing source' => ['unavailable', KnowledgeSourceStatus::SOURCE_MISSING, [], 'source_missing', 'no longer reachable for its owner'],
			'access denied' => ['unavailable', KnowledgeSourceStatus::SOURCE_ACCESS_DENIED, [], 'source_access_denied', 'no longer has access'],
			'metadata only' => ['error', KnowledgeSourceStatus::METADATA_ONLY, ['metadata' => 3], 'metadata_only', 'Only system files were found'],
			'no text' => ['error', KnowledgeSourceStatus::NO_TEXT, ['empty' => 2, 'unsupported' => 1], 'no_text', '2 files without text, 1 not supported'],
			'extraction failed' => ['error', KnowledgeSourceStatus::EXTRACTION_FAILED, ['failed' => 1, 'total' => 4], 'extraction_failed', '1 of 4 files could not be read'],
			'retry scheduled' => ['error', KnowledgeSourceStatus::SOURCE_TEMPORARILY_UNAVAILABLE, ['retry_scheduled' => true], 'source_temporarily_unavailable', 'retried automatically'],
			'embedding rejected' => ['error', KnowledgeSourceStatus::EMBEDDING_FAILED, ['http_status' => 400], 'embedding_failed', 'rejected the request (HTTP 400)'],
			'rate limit exhausted' => ['error', KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED, ['retry_at' => 0], 'embedding_rate_limited', 'kept asking for a pause'],
		];
	}

	/**
	 * @param array<string,mixed> $params
	 */
	#[DataProvider('categorizedFailures')]
	public function testIndexExplainsCategorizedFailuresWithoutTheStoredDetail(string $status, string $code, array $params, string $expectedCode, string $expectedText): void {
		$source = $this->folderSource($status, KnowledgeSourceStatus::encode($code, $params, 'provider said: secret-token-123'));

		$payload = $this->indexPayload($source);

		$this->assertSame($expectedCode, $payload['source_error_code']);
		$this->assertStringContainsString($expectedText, $payload['error_message']);
		$this->assertStringNotContainsString('secret-token-123', json_encode($payload));
		$this->assertNull($payload['notice_message']);
	}

	public function testUnsupportedFileTypesAndDisabledConverterAreNamed(): void {
		$source = $this->folderSource('error', KnowledgeSourceStatus::encode(KnowledgeSourceStatus::NO_SUPPORTED_FILES, [
			'unsupported' => 3,
			'extensions' => ['pages', 'pdf', '<img src=x>'],
			'converter_disabled' => true,
		]));

		$payload = $this->indexPayload($source);

		$this->assertSame('no_supported_files', $payload['source_error_code']);
		$this->assertStringContainsString('Unsupported file types: .pages, .pdf', $payload['error_message']);
		$this->assertStringContainsString('Docling', $payload['error_message']);
		$this->assertStringNotContainsString('<img', $payload['error_message']);
	}

	public function testReadySourceWithSkippedFilesShowsANoticeInsteadOfAnError(): void {
		$source = $this->folderSource('ready', KnowledgeSourceStatus::encode(KnowledgeSourceStatus::SKIPPED_FILES, [
			'indexed' => 4,
			'unsupported' => 1,
			'empty' => 2,
			'extensions' => ['pages'],
			'converter_disabled' => false,
		]));

		$payload = $this->indexPayload($source);

		$this->assertNull($payload['error_message']);
		$this->assertNull($payload['source_error_code']);
		$this->assertSame('Skipped files: 1 not supported, 2 without text. Unsupported file types: .pages', $payload['notice_message']);
	}

	public function testWaitingSourceExplainsThePause(): void {
		$source = $this->folderSource('pending', KnowledgeSourceStatus::encode(KnowledgeSourceStatus::EMBEDDING_RATE_LIMITED, ['retry_at' => 2000000000]));

		$payload = $this->indexPayload($source);

		$this->assertNull($payload['error_message']);
		$this->assertStringContainsString('Indexing continues automatically', $payload['notice_message']);
	}

	public function testUnknownStoredCodeFallsBackToTheGenericMessage(): void {
		$source = $this->folderSource('error', KnowledgeSourceStatus::encode(KnowledgeSourceStatus::INDEXING_FAILED, [], 'SQLSTATE secret'));

		$payload = $this->indexPayload($source);

		$this->assertSame('The knowledge source could not be processed.', $payload['error_message']);
		$this->assertSame('knowledge_source_processing_failed', $payload['source_error_code']);
		$this->assertStringNotContainsString('SQLSTATE', json_encode($payload));
	}

	private function folderSource(string $status, ?string $errorMessage): BotSource {
		$source = new BotSource();
		$source->setId(8);
		$source->setBotId(42);
		$source->setOwnerUid('alice');
		$source->setNodeId(55);
		$source->setNodeType('folder');
		$source->setStatus($status);
		$source->setErrorMessage($errorMessage);
		return $source;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function indexPayload(BotSource $source): array {
		$bot = new Bot();
		$bot->setUserId('alice');
		$botService = $this->createMock(BotService::class);
		$botService->method('getBot')->willReturn($bot);
		$botSourceMapper = $this->createMock(BotSourceMapper::class);
		$botSourceMapper->method('findByBot')->with(42)->willReturn([$source]);

		$response = $this->createController($botService, $botSourceMapper)->index(42);
		$this->assertSame(200, $response->getStatus());

		return $response->getData()['sources'][0];
	}

	private function createController(
		BotService $botService,
		?BotSourceMapper $botSourceMapper = null,
	): RagController {
		$l10nBuilder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$l10nBuilder->addMethods(['t']);
		}
		$l10n = $l10nBuilder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters));

		return new RagController(
			'educai',
			$this->createMock(IRequest::class),
			$botService,
			$botSourceMapper ?? $this->createMock(BotSourceMapper::class),
			$this->createMock(EmbeddingMapper::class),
			$this->createMock(RagIngestionService::class),
			$this->createMock(UrlContentFetcher::class),
			$this->createMock(PermissionService::class),
			$this->createMock(IRootFolder::class),
			'alice',
			$this->createMock(LoggerInterface::class),
			$l10n,
		);
	}
}
