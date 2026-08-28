<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\RagController;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
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

	private function createController(
		BotService $botService,
		?BotSourceMapper $botSourceMapper = null,
	): RagController {
		$l10nBuilder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$l10nBuilder->addMethods(['t']);
		}
		$l10n = $l10nBuilder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

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
