<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\RagController;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\KnowledgeSourcePresenter;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
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

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function editors(): array {
		return [
			'owner' => ['owner'],
			'admin of the bot group' => ['bob'],
			'Nextcloud administrator' => ['admin'],
		];
	}

	#[DataProvider('editors')]
	public function testWhoeverMayEditTheBotListsAndChangesItsSources(string $userId): void {
		$botSourceMapper = $this->sourceMapperWithStoredSource();
		$botSourceMapper->expects($this->once())->method('deleteById')->with(8);
		$embeddingMapper = $this->createMock(EmbeddingMapper::class);
		$embeddingMapper->expects($this->once())->method('deleteBySource')->with(8);
		$queued = [];
		$ragIngestionService = $this->createMock(RagIngestionService::class);
		$ragIngestionService->method('enqueueSource')->willReturnCallback(static function (int $sourceId, bool $force = false) use (&$queued): void {
			$queued[] = [$sourceId, $force];
		});
		$controller = $this->createController($this->botServiceFor($this->pendingGroupBot()), $botSourceMapper, $userId, $ragIngestionService, $embeddingMapper);

		$listed = $controller->index(42);
		$added = $controller->store(42, 55, 'folder');
		$reindexed = $controller->reindex(42, 8);
		$removed = $controller->destroy(42, 8);

		$this->assertSame(200, $listed->getStatus());
		$this->assertSame([8], array_column($listed->getData()['sources'], 'id'));
		$this->assertSame(201, $added->getStatus());
		$this->assertSame(9, $added->getData()['id']);
		// The files of a source are read as the user who attached it
		$this->assertSame($userId, $added->getData()['owner_uid']);
		$this->assertSame(200, $reindexed->getStatus());
		$this->assertSame(200, $removed->getStatus());
		$this->assertSame([[9, false], [8, true]], $queued);
	}

	public function testReviewerOfAPendingBotListsItsSourcesButCannotChangeThem(): void {
		$botSourceMapper = $this->sourceMapperWithStoredSource();
		$botSourceMapper->expects($this->never())->method('insert');
		$botSourceMapper->expects($this->never())->method('deleteById');
		$embeddingMapper = $this->createMock(EmbeddingMapper::class);
		$embeddingMapper->expects($this->never())->method('deleteBySource');
		$ragIngestionService = $this->createMock(RagIngestionService::class);
		$ragIngestionService->expects($this->never())->method('enqueueSource');
		$controller = $this->createController($this->botServiceFor($this->pendingGroupBot()), $botSourceMapper, 'reviewer', $ragIngestionService, $embeddingMapper);

		$listed = $controller->index(42);

		$this->assertSame(200, $listed->getStatus());
		$this->assertSame([8], array_column($listed->getData()['sources'], 'id'));
		foreach ([$controller->store(42, 55, 'folder'), $controller->reindex(42, 8), $controller->destroy(42, 8)] as $response) {
			$this->assertSame(403, $response->getStatus());
			$this->assertSame(['error' => 'Unauthorized', 'errorCode' => 'forbidden'], $response->getData());
		}
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function outsiders(): array {
		return [
			'plain member of the bot group' => ['alice', 'groups'],
			'admin of another group' => ['carol', 'groups'],
			// Nobody but the owner may save a personal bot, so no group admin touches its sources
			'group admin and a foreign personal bot' => ['bob', 'personal'],
		];
	}

	public function testNextcloudAdministratorListsTheSourcesOfAForeignPersonalBotButCannotChangeThem(): void {
		$bot = $this->pendingGroupBot();
		$bot->setVisibility('personal');
		$bot->setApprovalStatus('personal');
		$botSourceMapper = $this->sourceMapperWithStoredSource();
		$botSourceMapper->expects($this->never())->method('insert');
		$botSourceMapper->expects($this->never())->method('deleteById');
		$embeddingMapper = $this->createMock(EmbeddingMapper::class);
		$embeddingMapper->expects($this->never())->method('deleteBySource');
		$ragIngestionService = $this->createMock(RagIngestionService::class);
		$ragIngestionService->expects($this->never())->method('enqueueSource');
		$controller = $this->createController($this->botServiceFor($bot), $botSourceMapper, 'admin', $ragIngestionService, $embeddingMapper);

		// The admin settings list every bot with its sources; saving such a bot is refused, and so is changing its sources
		$listed = $controller->index(42);
		$this->assertSame(200, $listed->getStatus());
		$this->assertSame([8], array_column($listed->getData()['sources'], 'id'));
		foreach ([$controller->store(42, 55, 'folder'), $controller->reindex(42, 8), $controller->destroy(42, 8)] as $response) {
			$this->assertSame(403, $response->getStatus());
			$this->assertSame(['error' => 'Unauthorized', 'errorCode' => 'forbidden'], $response->getData());
		}
	}

	#[DataProvider('outsiders')]
	public function testUsersWhoMayNotEditTheBotAreRefusedItsSources(string $userId, string $visibility): void {
		$bot = $this->pendingGroupBot();
		$bot->setVisibility($visibility);
		$bot->setApprovalStatus($visibility === 'personal' ? 'personal' : 'approved');
		$botSourceMapper = $this->sourceMapperWithStoredSource();
		$botSourceMapper->expects($this->never())->method('findByBot');
		$botSourceMapper->expects($this->never())->method('insert');
		$botSourceMapper->expects($this->never())->method('deleteById');
		$embeddingMapper = $this->createMock(EmbeddingMapper::class);
		$embeddingMapper->expects($this->never())->method('deleteBySource');
		$ragIngestionService = $this->createMock(RagIngestionService::class);
		$ragIngestionService->expects($this->never())->method('enqueueSource');
		$controller = $this->createController($this->botServiceFor($bot), $botSourceMapper, $userId, $ragIngestionService, $embeddingMapper);

		foreach ([$controller->index(42), $controller->store(42, 55, 'folder'), $controller->reindex(42, 8), $controller->destroy(42, 8)] as $response) {
			$this->assertSame(403, $response->getStatus());
			$this->assertSame(['error' => 'Unauthorized', 'errorCode' => 'forbidden'], $response->getData());
		}
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
		?string $userId = 'alice',
		?RagIngestionService $ragIngestionService = null,
		?EmbeddingMapper $embeddingMapper = null,
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
			$embeddingMapper ?? $this->createMock(EmbeddingMapper::class),
			$ragIngestionService ?? $this->createMock(RagIngestionService::class),
			$this->createMock(UrlContentFetcher::class),
			$this->createScopedPermissionService($l10n),
			new KnowledgeSourcePresenter($this->createMock(IRootFolder::class), $l10n, $this->createMock(LoggerInterface::class)),
			$userId,
			$this->createMock(LoggerInterface::class),
			$l10n,
		);
	}

	/**
	 * The real permission rules: "admin" is a Nextcloud administrator, bob administers
	 * group-a, carol group-b, everyone else nothing.
	 */
	private function createScopedPermissionService(IL10N $l10n): PermissionService {
		$service = $this->getMockBuilder(PermissionService::class)
			->setConstructorArgs([
				$this->createMock(IGroupManager::class),
				$this->createMock(IUserManager::class),
				$this->createMock(IAppManager::class),
				$this->createMock(LoggerInterface::class),
				$l10n,
			])
			->onlyMethods(['isAdmin', 'getAdminGroups', 'getAdminTeams'])
			->getMock();
		$service->method('isAdmin')->willReturnCallback(static fn (string $userId): bool => $userId === 'admin');
		$service->method('getAdminGroups')->willReturnCallback(
			static fn (string $userId): array => ['bob' => ['group-a'], 'carol' => ['group-b']][$userId] ?? []
		);
		$service->method('getAdminTeams')->willReturn([]);

		return $service;
	}

	/**
	 * A bot of "owner" for group-a that waits for review.
	 */
	private function pendingGroupBot(): Bot {
		$bot = new Bot();
		$bot->setId(42);
		$bot->setUserId('owner');
		$bot->setVisibility('groups');
		$bot->setAllowedGroups(json_encode(['group-a']) ?: '[]');
		$bot->setApprovalStatus('pending');

		return $bot;
	}

	/**
	 * A bot service that knows one bot; "reviewer" may inspect it while it is pending.
	 */
	private function botServiceFor(Bot $bot): BotService {
		$botService = $this->createMock(BotService::class);
		$botService->method('getBot')->with(42)->willReturn($bot);
		$botService->method('canInspectPendingReviewContext')->willReturnCallback(
			static fn (Bot $candidate, string $userId): bool => $candidate === $bot && $userId === 'reviewer'
		);

		return $botService;
	}

	/**
	 * A source mapper holding source 8 of bot 42; inserted sources get ID 9.
	 */
	private function sourceMapperWithStoredSource(): BotSourceMapper {
		$botSourceMapper = $this->createMock(BotSourceMapper::class);
		$botSourceMapper->method('findByBot')->with(42)->willReturn([$this->folderSource('ready', null)]);
		$botSourceMapper->method('findById')->with(8)->willReturn($this->folderSource('ready', null));
		$botSourceMapper->method('insert')->willReturnCallback(static function (BotSource $source): BotSource {
			$source->setId(9);
			return $source;
		});

		return $botSourceMapper;
	}
}
