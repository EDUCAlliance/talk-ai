<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\BotController;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\BotToolMapper;
use OCA\EducAI\Db\ChatRoomMapper;
use OCA\EducAI\Db\ConversationMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Exception\AuthorizationException;
use OCA\EducAI\Exception\ModelNotReleasedException;
use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\Service\BuiltInToolUiService;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\WikiService;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BotControllerTest extends TestCase {
	public function testBotToolsMapsTypedAuthorizationFailureWithoutExposingRawMessage(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('getBotTools')
			->willThrowException(new AuthorizationException('database details must stay private'));
		$controller = $this->createController($botService);

		$response = $controller->tools(42);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
		$this->assertSame('You do not have permission to view this bot', $response->getData()['error']);
		$this->assertStringNotContainsString('database', $response->getData()['error']);
	}

	public function testUpdateMapsTypedAuthorizationFailureToForbidden(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('updateBot')
			->willThrowException(new AuthorizationException('internal update authorization details'));
		$controller = $this->createController($botService);

		$response = $controller->update(42, 'Test bot', 'Test prompt');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
		$this->assertSame('You do not have permission to update this bot', $response->getData()['error']);
		$this->assertStringNotContainsString('internal', $response->getData()['error']);
	}

	public function testModelSelectionRefusalsExplainTheModelInsteadOfClaimingMissingRights(): void {
		foreach ([
			[false, 'This model has not been released for bots. Choose a released model or the administrator default.'],
			[true, 'Choosing a model per bot is turned off. The bot uses the administrator default model.'],
		] as [$selectionDisabled, $message]) {
			$botService = $this->createMock(BotService::class);
			$botService->method('updateBot')->willThrowException(new ModelNotReleasedException($selectionDisabled));
			$botService->method('createBot')->willThrowException(new ModelNotReleasedException($selectionDisabled));
			$controller = $this->createController($botService);
			foreach ([$controller->update(42, 'Test bot', 'Test prompt', model: 'secondary:x'), $controller->create('Test bot', 'test', 'Test prompt', model: 'secondary:x')] as $response) {
				$this->assertSame(400, $response->getStatus());
				$this->assertSame('model_not_released', $response->getData()['errorCode']);
				$this->assertSame($message, $response->getData()['error']);
			}
		}
	}

	public function testDestroyMapsTypedAuthorizationFailureToForbidden(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('deleteBot')
			->willThrowException(new AuthorizationException('internal delete authorization details'));
		$controller = $this->createController($botService);

		$response = $controller->destroy(42);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
		$this->assertSame('You do not have permission to delete this bot', $response->getData()['error']);
		$this->assertStringNotContainsString('internal', $response->getData()['error']);
	}

	public function testSubmitMapsTypedAuthorizationFailureToForbidden(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('submitForApproval')
			->willThrowException(new AuthorizationException('internal submit authorization details'));
		$controller = $this->createController($botService);

		$response = $controller->submit(42);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
		$this->assertSame('You do not have permission to submit this bot for approval', $response->getData()['error']);
		$this->assertStringNotContainsString('internal', $response->getData()['error']);
	}

	public function testScopeAdminLoadsTheBotAndItsToolsWithoutOwningIt(): void {
		$bot = $this->createGroupBot();
		$permissions = $this->createScopedPermissionService();

		foreach (['owner', 'bob'] as $editor) {
			$controller = $this->createController($this->createBotService($bot, $permissions), $permissions, $editor);

			$shown = $controller->show(7);
			$tools = $controller->tools(7);

			$this->assertSame(200, $shown->getStatus(), $editor);
			$this->assertSame($bot, $shown->getData());
			$this->assertSame(200, $tools->getStatus(), $editor);
			$this->assertSame([BuiltInToolProvider::TOOL_ROOM_SEARCH], array_column($tools->getData()['tools'], 'builtin_name'));
		}
	}

	public function testUsersWhoMayNotEditTheBotAreRefusedTheBotAndItsTools(): void {
		$bot = $this->createGroupBot();
		$permissions = $this->createScopedPermissionService();

		// alice is a plain member, carol administers another group
		foreach (['alice', 'carol'] as $other) {
			$controller = $this->createController($this->createBotService($bot, $permissions), $permissions, $other);

			$shown = $controller->show(7);
			$tools = $controller->tools(7);

			$this->assertSame(403, $shown->getStatus(), $other);
			$this->assertSame(['error' => 'Unauthorized', 'errorCode' => 'forbidden'], $shown->getData());
			$this->assertSame(403, $tools->getStatus(), $other);
			$this->assertSame(
				['error' => 'You do not have permission to view this bot', 'errorCode' => 'forbidden'],
				$tools->getData()
			);
		}
	}

	public function testShowRefusesRequestsWithoutAUser(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('getBot')->willReturn($this->createGroupBot());
		$permissions = $this->createMock(PermissionService::class);
		$permissions->expects($this->never())->method('canEditBot');

		$response = $this->createController($botService, $permissions, null)->show(7);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
	}

	public function testShowStillReportsAMissingBotAsNotFound(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('getBot')->willThrowException(new \RuntimeException('no such row'));

		$response = $this->createController($botService)->show(404);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Bot not found', 'errorCode' => 'bot_not_found'], $response->getData());
	}

	public function testBotListsLocalizeBuiltInToolsAndKeepEveryOtherField(): void {
		$own = ['id' => 7, 'bot_name' => 'Mine', 'tools' => $this->toolEntries(), 'rag_source_count' => 2, 'rag_source_problem_count' => 1, 'can_edit' => true];
		$public = ['id' => 8, 'bot_name' => 'Shared', 'access_reason' => ['type' => 'group', 'names' => ['Group A']], 'tools' => $this->toolEntries(), 'rag_source_count' => 0, 'can_edit' => false];
		$botService = $this->createMock(BotService::class);
		$botService->method('getBotsByUserEnriched')->with('alice')->willReturn([$own]);
		$botService->method('getAvailableBotsForUserEnriched')->with('alice')->willReturn([$public]);
		$botService->method('getPublicBotDetails')->with(8, 'alice')->willReturn($public);
		$controller = $this->createController($botService, builtInToolUiService: new BuiltInToolUiService($this->createL10n('de:')));
		$localized = ['tools' => $this->toolEntries('de:')];

		$this->assertSame([array_replace($own, $localized)], $controller->index()->getData());
		$this->assertSame([array_replace($public, $localized)], $controller->listPublic()->getData());
		$this->assertSame(array_replace($public, $localized), $controller->showPublic(8)->getData());
	}

	public function testPendingApprovalsLocalizeTheReviewedAndTheLiveTools(): void {
		$approval = [
			'id' => 7,
			'bot_name' => 'Live name',
			'review_target' => ['bot_name' => 'Pending name', 'tools' => $this->toolEntries(), 'is_update' => true],
			'live_tools' => array_slice($this->toolEntries(), 1, 1),
			'owner_name' => 'Owner User',
		];
		$botService = $this->createMock(BotService::class);
		$botService->method('getPendingApprovals')->with('alice')->willReturn([$approval, ['id' => 8]]);
		$controller = $this->createController($botService, builtInToolUiService: new BuiltInToolUiService($this->createL10n('de:')));

		$response = $controller->pendingApprovals();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([
			'id' => 7,
			'bot_name' => 'Live name',
			'review_target' => ['bot_name' => 'Pending name', 'tools' => $this->toolEntries('de:'), 'is_update' => true],
			'live_tools' => array_slice($this->toolEntries('de:'), 1, 1),
			'owner_name' => 'Owner User',
		], $response->getData()['bots'][0]);
		$this->assertSame(['id' => 8], $response->getData()['bots'][1]);
	}

	private function createController(
		BotService $botService,
		?PermissionService $permissionService = null,
		?string $userId = 'alice',
		?BuiltInToolUiService $builtInToolUiService = null,
	): BotController {
		return new BotController(
			'educai',
			$this->createMock(IRequest::class),
			$botService,
			$permissionService ?? $this->createMock(PermissionService::class),
			$userId,
			$this->createMock(LoggerInterface::class),
			$this->createL10n(),
			$builtInToolUiService ?? $this->createMock(BuiltInToolUiService::class),
		);
	}

	private function createL10n(string $prefix = ''): IL10N {
		$builder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$builder->addMethods(['t']);
		}
		$l10n = $builder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $prefix . $text);

		return $l10n;
	}

	/**
	 * A group bot of "owner": bob administers its group, carol another group, alice none.
	 */
	private function createGroupBot(): Bot {
		$bot = new Bot();
		$bot->setId(7);
		$bot->setUserId('owner');
		$bot->setBotName('Group bot');
		$bot->setVisibility('groups');
		$bot->setAllowedGroups(json_encode(['group-a']) ?: '[]');

		return $bot;
	}

	/**
	 * The real permission rules with the roles used by createGroupBot().
	 */
	private function createScopedPermissionService(): PermissionService {
		$service = $this->getMockBuilder(PermissionService::class)
			->setConstructorArgs([
				$this->createMock(IGroupManager::class),
				$this->createMock(IUserManager::class),
				$this->createMock(IAppManager::class),
				$this->createMock(LoggerInterface::class),
				$this->createL10n(),
			])
			->onlyMethods(['isAdmin', 'getAdminGroups', 'getAdminTeams'])
			->getMock();
		$service->method('isAdmin')->willReturn(false);
		$service->method('getAdminGroups')->willReturnCallback(
			static fn (string $userId): array => ['bob' => ['group-a'], 'carol' => ['group-b']][$userId] ?? []
		);
		$service->method('getAdminTeams')->willReturn([]);

		return $service;
	}

	/**
	 * The real bot service around one stored bot with a single built-in tool.
	 */
	private function createBotService(Bot $bot, PermissionService $permissionService): BotService {
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findById')->with($bot->getId())->willReturn($bot);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([
			['name' => BuiltInToolProvider::TOOL_ROOM_SEARCH, 'config' => []],
		]);

		return new BotService(
			$botMapper,
			$this->createMock(ConversationMapper::class),
			$this->createMock(ChatRoomMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppManager::class),
			$this->createMock(BotSourceMapper::class),
			$this->createMock(EmbeddingMapper::class),
			$this->createMock(BotToolMapper::class),
			$this->createMock(ToolMapper::class),
			$toolRegistry,
			$this->createMock(AgentExecutor::class),
			$this->createMock(ToolProviderRegistry::class),
			$this->createMock(WikiService::class),
			$this->createMock(RateLimitService::class),
			$permissionService,
			$this->createMock(SettingsService::class),
			$this->createMock(RoomDocumentIngestionService::class),
		);
	}

	/**
	 * Tool entries as the bot service returns them: an MCP tool, a core built-in tool
	 * and a built-in tool of a provider, which has no translation of its own.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function toolEntries(string $prefix = ''): array {
		return [
			['name' => 'Library search', 'description' => null, 'is_builtin' => false],
			[
				'name' => $prefix . 'Room Document Search',
				'description' => $prefix . 'Search documents uploaded in the current Nextcloud Talk room. Can be used together with the bot\'s global document search.',
				'is_builtin' => true,
				'builtin_name' => BuiltInToolProvider::TOOL_ROOM_SEARCH,
			],
			[
				'name' => 'Course Catalogue Search',
				'description' => 'Search the course catalogue for current learning opportunities.',
				'is_builtin' => true,
				'builtin_name' => 'catalogue_search',
			],
		];
	}
}
