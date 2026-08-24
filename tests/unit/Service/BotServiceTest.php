<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use Exception;
use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\BotToolMapper;
use OCA\EducAI\Db\ChatRoomMapper;
use OCA\EducAI\Db\Conversation;
use OCA\EducAI\Db\ConversationMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\TraceService;
use OCA\EducAI\Service\WikiLocationService;
use OCA\EducAI\Service\WikiRootRegistryService;
use OCA\EducAI\Service\WikiService;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BotServiceTest extends TestCase {
	public function testPersonalBotUpgradeToSharedScopeBecomesDraftWithoutPublishRights(): void {
		$bot = $this->createPersonalBot();
		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);

		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);
		$botMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Bot $updated): Bot => $updated);

		$permissionService->expects($this->once())
			->method('canEditBot')
			->with('owner', $bot)
			->willReturn(true);
		$permissionService->expects($this->once())
			->method('canPublishBotToScope')
			->with('owner', 'groups', ['group-a'], [])
			->willReturn(false);

		$service = $this->createBotService($botMapper, $permissionService);
		$updated = $service->updateBot(
			id: 7,
			userId: 'owner',
			botName: 'Draft candidate',
			systemPrompt: 'Prompt',
			visibility: 'groups',
			allowedGroups: ['group-a'],
			description: 'Updated description'
		);

		$this->assertSame('draft', $updated->getApprovalStatus());
		$this->assertSame('groups', $updated->getVisibility());
		$this->assertNull($updated->getApprovedBy());
		$this->assertNull($updated->getApprovedAt());
	}

	public function testPersonalBotUpgradeToSharedScopePublishesDirectlyWhenAllowed(): void {
		$bot = $this->createPersonalBot();
		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);

		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);
		$botMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Bot $updated): Bot => $updated);

		$permissionService->expects($this->once())
			->method('canEditBot')
			->with('owner', $bot)
			->willReturn(true);
		$permissionService->expects($this->once())
			->method('canPublishBotToScope')
			->with('owner', 'groups', ['group-a'], [])
			->willReturn(true);

		$service = $this->createBotService($botMapper, $permissionService);
		$updated = $service->updateBot(
			id: 7,
			userId: 'owner',
			botName: 'Published candidate',
			systemPrompt: 'Prompt',
			visibility: 'groups',
			allowedGroups: ['group-a'],
			description: 'Updated description'
		);

		$this->assertSame('approved', $updated->getApprovalStatus());
		$this->assertSame('groups', $updated->getVisibility());
		$this->assertSame('owner', $updated->getApprovedBy());
		$this->assertNotNull($updated->getApprovedAt());
	}

	public function testOwnerEditOfApprovedSharedBotCreatesScopedPendingChange(): void {
		$bot = $this->createApprovedBot();
		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);

		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);
		$botMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Bot $updated): Bot => $updated);

		$permissionService->expects($this->once())
			->method('canEditBot')
			->with('owner', $bot)
			->willReturn(true);
		$permissionService->expects($this->once())
			->method('canPublishBotToScope')
			->with('owner', 'groups', ['group-a'], [])
			->willReturn(true);

		$service = $this->createBotService($botMapper, $permissionService);
		$updated = $service->updateBot(
			id: 7,
			userId: 'owner',
			botName: 'Changed bot',
			systemPrompt: 'Changed prompt',
			visibility: 'groups',
			allowedGroups: ['group-a'],
			description: 'Updated description'
		);

		$this->assertSame('pending', $updated->getApprovalStatus());
		$this->assertSame('Live bot', $updated->getBotName());
		$this->assertSame('reviewer', $updated->getApprovedBy());
		$this->assertSame(100, $updated->getApprovedAt());
		$this->assertSame('Changed bot', $updated->getPendingChangesArray()['bot_name'] ?? null);
	}

	public function testUpdatingVersionedPendingBotKeepsLiveVersionUntouched(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');
		$bot->setPendingChangesArray([
			'bot_name' => 'Pending v1',
			'system_prompt' => 'Pending prompt v1',
			'description' => 'Pending description v1',
			'model' => null,
			'visibility' => 'groups',
			'is_public' => false,
			'allowed_groups' => json_encode(['group-a']),
			'allowed_teams' => json_encode([]),
			'rag_enabled' => false,
			'tools' => null,
			'onboarding_questions' => null,
		]);

		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);

		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);
		$botMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Bot $updated): Bot => $updated);

		$permissionService->expects($this->once())
			->method('canEditBot')
			->with('owner', $bot)
			->willReturn(true);
		$permissionService->expects($this->once())
			->method('canPublishBotToScope')
			->with('owner', 'groups', ['group-a'], [])
			->willReturn(true);

		$service = $this->createBotService($botMapper, $permissionService);
		$updated = $service->updateBot(
			id: 7,
			userId: 'owner',
			botName: 'Pending v2',
			systemPrompt: 'Pending prompt v2',
			visibility: 'groups',
			allowedGroups: ['group-a'],
			description: 'Pending description v2'
		);

		$this->assertSame('pending', $updated->getApprovalStatus());
		$this->assertSame('Live bot', $updated->getBotName());
		$this->assertSame('Pending v2', $updated->getPendingChangesArray()['bot_name'] ?? null);
		$this->assertSame('reviewer', $updated->getApprovedBy());
		$this->assertSame(100, $updated->getApprovedAt());
	}

	public function testApproveBotRejectsOwnerWithoutApprovalRights(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');

		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);

		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);
		$permissionService->expects($this->once())
			->method('canApproveBot');
		$permissionService->method('canApproveBot')
			->with('owner', $bot)
			->willReturn(false);

		$service = $this->createBotService($botMapper, $permissionService);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('You do not have permission to approve this bot');

		$service->approveBot(7, 'owner');
	}

	public function testApproveBotAllowsOwnerWithApprovalRights(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');

		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);

		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);
		$botMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Bot $updated): Bot => $updated);
		$permissionService->expects($this->once())
			->method('canApproveBot')
			->with('owner', $bot)
			->willReturn(true);

		$service = $this->createBotService($botMapper, $permissionService);
		$approved = $service->approveBot(7, 'owner');

		$this->assertSame('approved', $approved->getApprovalStatus());
		$this->assertSame('owner', $approved->getApprovedBy());
		$this->assertNotNull($approved->getApprovedAt());
	}

	public function testInitialPendingBotRequiresEnabledReviewerForAccess(): void {
		$bot = new Bot();
		$bot->setId(9);
		$bot->setUserId('owner');
		$bot->setVisibility('global');
		$bot->setIsPublic(true);
		$bot->setApprovalStatus('pending');

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);

		$this->assertTrue($service->userCanAccessBot($bot, 'owner'));
		$this->assertFalse($service->userCanAccessBot($bot, 'reviewer'));

		$bot->setTestingEnabledBy('reviewer');
		$this->assertTrue($service->userCanAccessBot($bot, 'reviewer'));
		$this->assertFalse($service->userCanAccessBot($bot, 'audience'));
	}

	public function testInitialPendingBotIsHiddenFromPublicListingForRegularAudience(): void {
		$bot = new Bot();
		$bot->setId(9);
		$bot->setUserId('owner');
		$bot->setBotName('Pending global');
		$bot->setMentionName('@pending-global');
		$bot->setVisibility('global');
		$bot->setIsPublic(true);
		$bot->setIsActive(true);
		$bot->setApprovalStatus('pending');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())
			->method('findAllActive')
			->willReturn([$bot]);

		$userManager = $this->createUserManagerMock();
		$userManager->expects($this->once())
			->method('get')
			->with('audience')
			->willReturn(null);

		$service = $this->createBotService(
			$botMapper,
			$this->createMock(PermissionService::class),
			null,
			$userManager
		);

		$this->assertSame([], $service->getAvailableBotsForUserEnriched('audience'));
	}

	public function testOwnPersonalBotAppearsInAvailableBotListing(): void {
		$bot = $this->createPersonalBot();
		$bot->setDescription('Private helper');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())
			->method('findAllActive')
			->willReturn([$bot]);

		$ownerUser = $this->createUserMock();
		$ownerUser->method('getDisplayName')->willReturn('Owner User');

		$userManager = $this->createUserManagerMock();
		$userManager->method('get')
			->willReturnCallback(static fn (string $uid) => $uid === 'owner' ? $ownerUser : null);

		$service = $this->createBotService(
			$botMapper,
			$this->createMock(PermissionService::class),
			null,
			$userManager
		);

		$result = $service->getAvailableBotsForUserEnriched('owner');

		$this->assertCount(1, $result);
		$this->assertSame(7, $result[0]['id']);
		$this->assertSame('personal', $result[0]['visibility']);
		$this->assertSame('personal', $result[0]['approval_status']);
		$this->assertSame('Owner User', $result[0]['owner_display_name']);
		$this->assertSame('owner', $result[0]['access_reason']['type']);
	}

	public function testOtherUsersPersonalBotIsHiddenFromAvailableBotListing(): void {
		$bot = $this->createPersonalBot();

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())
			->method('findAllActive')
			->willReturn([$bot]);

		$userManager = $this->createUserManagerMock();
		$userManager->method('get')->willReturn(null);

		$service = $this->createBotService(
			$botMapper,
			$this->createMock(PermissionService::class),
			null,
			$userManager
		);

		$this->assertSame([], $service->getAvailableBotsForUserEnriched('audience'));
	}

	public function testPendingUpdateKeepsApprovedLiveBotAccessible(): void {
		$bot = $this->createApprovedBot();
		$bot->setVisibility('global');
		$bot->setIsPublic(true);
		$bot->setApprovalStatus('pending');
		$bot->setPendingChangesArray([
			'bot_name' => 'Pending v2',
		]);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);

		$this->assertTrue($service->userCanAccessBot($bot, 'audience'));
	}

	public function testPendingUpdateAllowsEnabledReviewerOutsideLiveScope(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');
		$bot->setTestingEnabledBy('reviewer');
		$bot->setPendingChangesArray([
			'bot_name' => 'Pending v2',
		]);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);

		$this->assertTrue($service->userCanAccessBot($bot, 'reviewer'));
		$this->assertFalse($service->userCanAccessBot($bot, 'audience'));
	}

	public function testGetPendingApprovalsIncludesReviewTargetFromPendingChanges(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');
		$bot->setPendingChangesArray([
			'bot_name' => 'Pending shared bot',
			'system_prompt' => 'Pending prompt',
			'description' => 'Pending description',
			'visibility' => 'global',
			'is_public' => true,
			'allowed_groups' => json_encode([]),
			'allowed_teams' => json_encode([]),
			'rag_enabled' => false,
			'onboarding_questions' => [
				'start' => 'q1',
				'questions' => [
					[
						'id' => 'q1',
						'text' => 'Question?',
						'answers' => [
							['id' => 'a', 'text' => 'Answer', 'next' => null],
						],
					],
				],
			],
			'tools' => [
				['is_builtin' => true, 'builtin_name' => 'catalogue_search_courses'],
			],
		]);

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())
			->method('findByApprovalStatus')
			->with('pending')
			->willReturn([$bot]);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->expects($this->once())
			->method('hasApprovalRights')
			->with('reviewer')
			->willReturn(true);
		$permissionService->expects($this->once())
			->method('canApproveBot')
			->with('reviewer', $bot)
			->willReturn(true);

		$ownerUser = $this->createUserMock();
		$ownerUser->method('getDisplayName')->willReturn('Owner User');
		$userManager = $this->createUserManagerMock();
		$userManager->expects($this->once())
			->method('get')
			->with('owner')
			->willReturn($ownerUser);

		$service = $this->createBotService($botMapper, $permissionService, null, $userManager);
		$result = $service->getPendingApprovals('reviewer');

		$this->assertCount(1, $result);
		$this->assertSame('Pending shared bot', $result[0]['review_target']['bot_name']);
		$this->assertSame('Pending prompt', $result[0]['review_target']['system_prompt']);
		$this->assertSame('global', $result[0]['review_target']['visibility']);
		$this->assertTrue($result[0]['review_target']['is_update']);
		$this->assertSame('Course Catalogue Search', $result[0]['review_target']['tools'][0]['name'] ?? null);
	}

	public function testGetPendingApprovalsIncludesOwnPendingBotWhenOwnerCanReview(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())
			->method('findByApprovalStatus')
			->with('pending')
			->willReturn([$bot]);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->expects($this->once())
			->method('hasApprovalRights')
			->with('owner')
			->willReturn(true);
		$permissionService->expects($this->once())
			->method('canApproveBot')
			->with('owner', $bot)
			->willReturn(true);

		$ownerUser = $this->createUserMock();
		$ownerUser->method('getDisplayName')->willReturn('Owner User');
		$userManager = $this->createUserManagerMock();
		$userManager->expects($this->once())
			->method('get')
			->with('owner')
			->willReturn($ownerUser);

		$service = $this->createBotService($botMapper, $permissionService, null, $userManager);
		$result = $service->getPendingApprovals('owner');

		$this->assertCount(1, $result);
		$this->assertSame(7, $result[0]['id']);
		$this->assertSame('Owner User', $result[0]['owner_name']);
	}

	public function testResolveEffectiveToolLoadoutUsesPendingBuiltInAssignmentsForTestingReviewer(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');
		$bot->setTestingEnabledBy('reviewer');
		$bot->setPendingChangesArray([
			'tools' => [
				['is_builtin' => true, 'builtin_name' => 'catalogue_search_courses'],
			],
		]);

		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolRegistry->expects($this->once())
			->method('getToolsForBot')
			->with(7)
			->willReturn([]);
		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(7)
			->willReturn([
				['name' => 'rag_search_documents', 'config' => []],
			]);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class),
			null,
			null,
			null,
			$toolRegistry
		);

		$method = new \ReflectionMethod(BotService::class, 'resolveEffectiveToolLoadout');
		$method->setAccessible(true);

		$reviewerLoadout = $method->invoke($service, $bot, 'reviewer');
		$audienceLoadout = $method->invoke($service, $bot, 'audience');

		$this->assertSame('catalogue_search_courses', $reviewerLoadout['built_in'][0]['name'] ?? null);
		$this->assertSame('rag_search_documents', $audienceLoadout['built_in'][0]['name'] ?? null);
	}

	public function testResolveEffectiveToolLoadoutStripsWikiToolsForNonPersonalPendingBot(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');
		$bot->setTestingEnabledBy('reviewer');
		$bot->setPendingChangesArray([
			'visibility' => 'groups',
			'tools' => [
				[
					'is_builtin' => true,
					'builtin_name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
					'config' => ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study'],
				],
				['is_builtin' => true, 'builtin_name' => 'catalogue_search_courses'],
			],
		]);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);

		$method = new \ReflectionMethod(BotService::class, 'resolveEffectiveToolLoadout');
		$method->setAccessible(true);

		$reviewerLoadout = $method->invoke($service, $bot, 'reviewer');

		$this->assertSame([[
			'name' => 'catalogue_search_courses',
			'config' => [],
		]], $reviewerLoadout['built_in']);
	}

	public function testResolveEffectiveToolLoadoutStripsStoredWikiToolsForNonPersonalBot(): void {
		$bot = $this->createApprovedBot();
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolRegistry->expects($this->once())
			->method('getToolsForBot')
			->with(7)
			->willReturn([]);
		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(7)
			->willReturn([
				[
					'name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
					'config' => ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study'],
				],
				['name' => 'catalogue_search_courses', 'config' => []],
			]);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class),
			null,
			null,
			null,
			$toolRegistry
		);

		$method = new \ReflectionMethod(BotService::class, 'resolveEffectiveToolLoadout');
		$method->setAccessible(true);

		$loadout = $method->invoke($service, $bot, 'audience');

		$this->assertSame([[
			'name' => 'catalogue_search_courses',
			'config' => [],
		]], $loadout['built_in']);
	}

	public function testResolveEffectiveToolLoadoutExpandsPartialWikiToolsForPersonalBot(): void {
		$bot = $this->createPersonalBot();
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolRegistry->expects($this->once())
			->method('getToolsForBot')
			->with(7)
			->willReturn([]);
		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(7)
			->willReturn([
				[
					'name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
					'config' => ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study'],
				],
				['name' => 'catalogue_search_courses', 'config' => []],
			]);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class),
			null,
			null,
			null,
			$toolRegistry
		);

		$method = new \ReflectionMethod(BotService::class, 'resolveEffectiveToolLoadout');
		$method->setAccessible(true);

		$loadout = $method->invoke($service, $bot, 'owner');
		$builtInNames = array_map(static fn (array $entry): string => $entry['name'], $loadout['built_in']);

		$this->assertSame([
			'catalogue_search_courses',
			BuiltInToolProvider::TOOL_WIKI_SEARCH,
			BuiltInToolProvider::TOOL_WIKI_READ_PAGE,
			BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
			BuiltInToolProvider::TOOL_WIKI_LOG_EVENT,
		], $builtInNames);
		$this->assertSame(
			['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study'],
			$loadout['built_in'][1]['config']
		);
	}

	public function testUpdatePersonalBotRejectsInvalidWikiRootPath(): void {
		$bot = $this->createPersonalBot();
		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);

		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);
		$botMapper->expects($this->never())
			->method('update');

		$permissionService->expects($this->once())
			->method('canEditBot')
			->with('owner', $bot)
			->willReturn(true);

		$service = $this->createBotService($botMapper, $permissionService);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Wiki root path must start with ' . Application::WIKI_ROOT_FOLDER . '/.');

		$service->updateBot(
			id: 7,
			userId: 'owner',
			botName: 'Personal bot',
			systemPrompt: 'Personal prompt',
			visibility: 'personal',
			tools: [[
				'is_builtin' => true,
				'builtin_name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
				'config' => ['wiki_root_path' => 'Private Wikis/custom-study'],
			]]
		);
	}

	public function testPersonalWikiAssignmentNormalizesCollectiveConfig(): void {
		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);
		$method = new \ReflectionMethod(BotService::class, 'stripWikiToolsForVisibility');
		$method->setAccessible(true);

		$result = $method->invoke($service, [[
			'is_builtin' => true,
			'builtin_name' => BuiltInToolProvider::TOOL_WIKI_SEARCH,
			'config' => [
				'wiki_location' => 'collective',
				'wiki_collective_id' => '99',
			],
		]], 'personal');

		$this->assertCount(4, $result);
		$this->assertSame(BuiltInToolProvider::TOOL_WIKI_SEARCH, $result[0]['builtin_name']);
		$this->assertSame([
			'wiki_location' => 'collective',
			'wiki_collective_id' => 99,
		], $result[0]['config']);
	}

	public function testPersonalWikiAssignmentRejectsMissingCollective(): void {
		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);
		$method = new \ReflectionMethod(BotService::class, 'stripWikiToolsForVisibility');
		$method->setAccessible(true);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('A valid collective must be selected');

		$method->invoke($service, [[
			'is_builtin' => true,
			'builtin_name' => BuiltInToolProvider::TOOL_WIKI_SEARCH,
			'config' => [
				'wiki_location' => 'collective',
				'wiki_collective_id' => '',
			],
		]], 'personal');
	}

	public function testTeamWikiAssignmentAllowsMatchingCollectiveConfig(): void {
		$wikiLocationService = $this->createMock(WikiLocationService::class);
		$wikiLocationService->expects($this->once())
			->method('collectiveMatchesAnyTeam')
			->with(99, 'owner', ['team-a'])
			->willReturn(true);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class),
			wikiLocationService: $wikiLocationService
		);
		$method = new \ReflectionMethod(BotService::class, 'stripWikiToolsForVisibility');
		$method->setAccessible(true);

		$result = $method->invoke($service, [[
			'is_builtin' => true,
			'builtin_name' => BuiltInToolProvider::TOOL_WIKI_SEARCH,
			'config' => [
				'wiki_location' => 'collective',
				'wiki_collective_id' => '99',
			],
		]], 'teams', ['team-a'], 'owner');

		$this->assertCount(4, $result);
		$this->assertSame([
			'wiki_location' => 'collective',
			'wiki_collective_id' => 99,
		], $result[0]['config']);
	}

	public function testTeamWikiAssignmentRejectsCollectiveOutsideSelectedTeams(): void {
		$wikiLocationService = $this->createMock(WikiLocationService::class);
		$wikiLocationService->expects($this->once())
			->method('collectiveMatchesAnyTeam')
			->with(99, 'owner', ['team-a'])
			->willReturn(false);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class),
			wikiLocationService: $wikiLocationService
		);
		$method = new \ReflectionMethod(BotService::class, 'stripWikiToolsForVisibility');
		$method->setAccessible(true);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Team bots can use LLM Wiki only with an admin-owned collective');

		$method->invoke($service, [[
			'is_builtin' => true,
			'builtin_name' => BuiltInToolProvider::TOOL_WIKI_SEARCH,
			'config' => [
				'wiki_location' => 'collective',
				'wiki_collective_id' => 99,
			],
		]], 'teams', ['team-a'], 'owner');
	}

	public function testCreatePersonalBotInitializesWikiWhenWikiToolsAreAssigned(): void {
		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);
		$wikiService = $this->createMock(WikiService::class);
		$wikiRootRegistryService = $this->createMock(WikiRootRegistryService::class);

		$botMapper->expects($this->once())
			->method('findByMentionName')
			->with('@personal-bot')
			->willThrowException(new DoesNotExistException('not found'));
		$botMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (Bot $bot): Bot {
				$bot->setId(7);
				return $bot;
			});

		$wikiService->expects($this->once())
			->method('initializeWiki')
			->with(7, [
				'wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study',
				'wiki_location' => 'personal_files',
			])
			->willReturn([
				'success' => true,
				'action' => 'initialized',
				'wiki_root' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study',
				'indexed_files' => 0,
				'index_path' => 'index.md',
			]);
		$wikiRootRegistryService->expects($this->once())
			->method('refreshBot')
			->with($this->isInstanceOf(Bot::class), [
				'wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study',
				'wiki_location' => 'personal_files',
			]);

		$service = $this->createBotService(
			$botMapper,
			$permissionService,
			wikiService: $wikiService,
			wikiRootRegistryService: $wikiRootRegistryService
		);

		$created = $service->createBot(
			userId: 'owner',
			botName: 'Personal bot',
			mentionName: 'personal-bot',
			systemPrompt: 'Personal prompt',
			visibility: 'personal',
			tools: [[
				'is_builtin' => true,
				'builtin_name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
				'config' => ['wiki_root_path' => Application::WIKI_ROOT_FOLDER . '/Personal Wikis/custom-study'],
			]]
		);

		$this->assertSame(7, $created->getId());
		$this->assertSame('personal', $created->getApprovalStatus());
	}

	public function testCreateTeamBotInitializesWikiForMatchingCollective(): void {
		$botMapper = $this->createMock(BotMapper::class);
		$permissionService = $this->createMock(PermissionService::class);
		$wikiService = $this->createMock(WikiService::class);
		$wikiRootRegistryService = $this->createMock(WikiRootRegistryService::class);
		$wikiLocationService = $this->createMock(WikiLocationService::class);

		$botMapper->expects($this->once())
			->method('findByMentionName')
			->with('@team-bot')
			->willThrowException(new DoesNotExistException('not found'));
		$botMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (Bot $bot): Bot {
				$bot->setId(8);
				return $bot;
			});
		$permissionService->expects($this->once())
			->method('canPublishBotToScope')
			->with('owner', 'teams', null, ['team-a'])
			->willReturn(true);
		$wikiLocationService->expects($this->once())
			->method('collectiveMatchesAnyTeam')
			->with(99, 'owner', ['team-a'])
			->willReturn(true);
		$wikiService->expects($this->once())
			->method('initializeWiki')
			->with(8, [
				'wiki_location' => 'collective',
				'wiki_collective_id' => 99,
			]);
		$wikiRootRegistryService->expects($this->once())
			->method('refreshBot')
			->with($this->isInstanceOf(Bot::class), [
				'wiki_location' => 'collective',
				'wiki_collective_id' => 99,
			]);

		$service = $this->createBotService(
			$botMapper,
			$permissionService,
			wikiService: $wikiService,
			wikiRootRegistryService: $wikiRootRegistryService,
			wikiLocationService: $wikiLocationService
		);

		$created = $service->createBot(
			userId: 'owner',
			botName: 'Team bot',
			mentionName: 'team-bot',
			systemPrompt: 'Team prompt',
			visibility: 'teams',
			allowedTeams: ['team-a'],
			tools: [[
				'is_builtin' => true,
				'builtin_name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE,
				'config' => [
					'wiki_location' => 'collective',
					'wiki_collective_id' => '99',
				],
			]]
		);

		$this->assertSame(8, $created->getId());
		$this->assertSame('approved', $created->getApprovalStatus());
	}

	public function testProcessMessageIncludesWikiWriteInstructionWhenWikiToolsAreEnabled(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$builtInToolProvider = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);

		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->with(7, 'room-token', null, 50)
			->willReturn([$this->createConversation('user', 'Please write this into the wiki.')]);

		$toolRegistry->expects($this->once())
			->method('getToolsForBot')
			->with(7)
			->willReturn([]);
		$toolRegistry->expects($this->once())
			->method('getBuiltInToolsForBot')
			->with(7)
			->willReturn([['name' => BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE, 'config' => []]]);

		$builtInToolProvider->expects($this->exactly(2))
			->method('setInvocationContext');

		$agentExecutor->expects($this->once())
			->method('run')
			->with(
				$this->callback(function (string $systemPrompt): bool {
					$this->assertStringContainsString('## Wiki Instructions', $systemPrompt);
					$this->assertStringContainsString('When `wiki_read_page` returns `has_more=true`', $systemPrompt);
					$this->assertStringContainsString('offset=next_offset', $systemPrompt);
					$this->assertStringContainsString('explicitly mention the page path and `next_offset`', $systemPrompt);
					$this->assertStringContainsString('call `wiki_write_page` before claiming that it was written or saved', $systemPrompt);
					$this->assertStringContainsString('check whether `index.md` needs a curated overview update', $systemPrompt);
					$this->assertStringContainsString('Leave the `Existing Files` section to ' . Application::APP_DISPLAY_NAME . ' automation', $systemPrompt);

					return true;
				}),
				$this->anything(),
				[],
				$this->callback(function (array $options): bool {
					$builtInTools = $options['built_in_tools'] ?? [];
					$builtInNames = array_map(static fn (array $entry): string => $entry['name'], $builtInTools);

					$this->assertContains(BuiltInToolProvider::TOOL_WIKI_SEARCH, $builtInNames);
					$this->assertContains(BuiltInToolProvider::TOOL_WIKI_READ_PAGE, $builtInNames);
					$this->assertContains(BuiltInToolProvider::TOOL_WIKI_WRITE_PAGE, $builtInNames);
					$this->assertContains(BuiltInToolProvider::TOOL_WIKI_LOG_EVENT, $builtInNames);

					return true;
				})
			)
			->willReturn([
				'status' => 'completed',
				'terminalReason' => 'final_response',
				'content' => 'Saved.',
				'messages' => [],
				'toolInvocations' => [],
				'rateLimitHeaders' => [],
			]);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class),
			null,
			null,
			null,
			$toolRegistry,
			$conversationMapper,
			$agentExecutor,
			$builtInToolProvider,
			$rateLimitService,
			$settingsService
		);

		$response = $service->processMessage(
			$bot,
			'Please write this into the wiki.',
			'room-token',
			'owner',
			'@personal-bot Please write this into the wiki.'
		);

		$this->assertSame('Saved.', $response);
	}

	public function testProcessMessageRoutesNoToolRequestThroughAgentExecutorAndPreservesContent(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();
		$contexts = [];

		$rateLimitService->method('isEnabled')->willReturn(false);
		$rateLimitService->expects($this->once())
			->method('updateFromHeaders')
			->with(['x-ratelimit-remaining-requests' => '17']);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);

		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->with(7, 'room-token', null, 50)
			->willReturn([$this->createConversation('user', 'What is the weather today?')]);

		$toolRegistry->expects($this->once())->method('getToolsForBot')->with(7)->willReturn([]);
		$toolRegistry->expects($this->once())->method('getBuiltInToolsForBot')->with(7)->willReturn([]);
		$toolProviderRegistry->expects($this->exactly(2))
			->method('setInvocationContext')
			->willReturnCallback(static function (?array $context) use (&$contexts): void {
				$contexts[] = $context;
			});

		$agentExecutor->expects($this->once())
			->method('run')
			->with(
				$this->anything(),
				$this->anything(),
				[],
				$this->callback(function (array $options): bool {
					$this->assertSame([], $options['built_in_tools'] ?? null);
					$this->assertArrayNotHasKey('force_tool_call', $options);
					$this->assertArrayNotHasKey('user_query', $options);
					$this->assertArrayNotHasKey('rag_enabled', $options);

					return true;
				})
			)
			->willReturn([
				'status' => 'completed',
				'terminalReason' => 'final_response',
				'content' => 'Ordinary <tool_call>XML prose</tool_call> remains.',
				'messages' => [],
				'toolInvocations' => [],
				'rateLimitHeaders' => ['x-ratelimit-remaining-requests' => '17'],
			]);

		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService
		);

		$response = $service->processMessage(
			$bot,
			'What is the weather today?',
			'room-token',
			'owner',
			'@personal-bot What is the weather today?'
		);

		$this->assertSame('Ordinary <tool_call>XML prose</tool_call> remains.', $response);
		$this->assertCount(2, $contexts);
		$this->assertIsArray($contexts[0]);
		$this->assertSame(7, $contexts[0]['bot_id'] ?? null);
		$this->assertNull($contexts[1]);
	}

	public function testProcessMessageSanitizesReasoningBeforeProgressTracePersistenceAndFutureHistory(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$traceService = $this->createMock(TraceService::class);
		$settings = new Settings();
		$inserted = [];
		$traceEvents = [];
		$visiblePartials = [];
		$streamMismatchCount = 0;

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);
		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static function (Conversation $conversation) use (&$inserted): Conversation {
				$inserted[] = $conversation;
				return $conversation;
			});
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->with(7, 'room-token', null, 50)
			->willReturn([
				$this->createConversation('assistant', '<think>old private reasoning</think> Earlier answer.'),
				$this->createConversation('user', 'Current question.'),
			]);
		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([]);
		$toolProviderRegistry->expects($this->exactly(2))->method('setInvocationContext');
		$agentExecutor->expects($this->once())
			->method('run')
			->willReturnCallback(function (string $systemPrompt, array $messages, array $toolLoadout, array $options): array {
				$this->assertSame([
					['role' => 'assistant', 'content' => 'Earlier answer.'],
					['role' => 'user', 'content' => 'Current question.'],
				], $messages);
				$emit = $options['on_partial_result'] ?? null;
				$this->assertIsCallable($emit);
				$emit('<think>new private reasoning</think>');
				$emit("\nVisible answer.");

				return [
					'status' => 'completed',
					'terminalReason' => 'final_response',
					'content' => "<think>new private reasoning</think>\nVisible answer.",
					'messages' => [],
					'toolInvocations' => [],
					'rateLimitHeaders' => [],
				];
			});
		$traceService->method('recordEvent')
			->willReturnCallback(static function (?int $runId, string $eventType, array $event = []) use (&$traceEvents): void {
				$traceEvents[] = [
					'run_id' => $runId,
					'event_type' => $eventType,
					'event' => $event,
				];
			});

		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService,
			traceService: $traceService
		);

		$response = $service->processMessage(
			bot: $bot,
			message: 'Current question.',
			roomToken: 'room-token',
			userId: 'owner',
			onProgress: static function (string $partial) use (&$visiblePartials): void {
				$visiblePartials[] = $partial;
			},
			traceRunId: 63,
			onStreamMismatch: static function () use (&$streamMismatchCount): void {
				$streamMismatchCount++;
			},
		);

		$this->assertSame('Visible answer.', $response);
		$this->assertSame(['Visible answer.'], $visiblePartials);
		$this->assertSame(0, $streamMismatchCount);
		$this->assertCount(2, $inserted);
		$this->assertSame('assistant', $inserted[1]->getRole());
		$this->assertSame('Visible answer.', $inserted[1]->getContent());
		$assistantResponses = array_values(array_filter(
			$traceEvents,
			static fn (array $event): bool => $event['event_type'] === 'assistant_response'
		));
		$this->assertCount(1, $assistantResponses);
		$this->assertSame('Visible answer.', $assistantResponses[0]['event']['result']['content'] ?? null);
		$serializedOutput = json_encode([$visiblePartials, $inserted[1]->getContent(), $traceEvents], JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString('<think>', $serializedOutput);
		$this->assertStringNotContainsString('new private reasoning', $serializedOutput);
	}

	public function testProcessMessageRejectsReasoningOnlyCompletionWithoutAssistantPersistence(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$traceService = $this->createMock(TraceService::class);
		$settings = new Settings();
		$traceEvents = [];
		$visiblePartials = [];

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);
		$conversationMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->willReturn([$this->createConversation('user', 'Current question.')]);
		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([]);
		$toolProviderRegistry->expects($this->exactly(2))->method('setInvocationContext');
		$agentExecutor->expects($this->once())
			->method('run')
			->willReturnCallback(function (string $systemPrompt, array $messages, array $toolLoadout, array $options): array {
				$emit = $options['on_partial_result'] ?? null;
				$this->assertIsCallable($emit);
				$emit('<think>private reasoning only</think>');

				return [
					'status' => 'completed',
					'terminalReason' => 'final_response',
					'content' => '<think>private reasoning only</think>',
					'messages' => [],
					'toolInvocations' => [],
					'rateLimitHeaders' => [],
				];
			});
		$traceService->method('recordEvent')
			->willReturnCallback(static function (?int $runId, string $eventType, array $event = []) use (&$traceEvents): void {
				$traceEvents[] = [
					'event_type' => $eventType,
					'event' => $event,
				];
			});

		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService,
			traceService: $traceService
		);

		$response = $service->processMessage(
			bot: $bot,
			message: 'Current question.',
			roomToken: 'room-token',
			userId: 'owner',
			onProgress: static function (string $partial) use (&$visiblePartials): void {
				$visiblePartials[] = $partial;
			},
			traceRunId: 64,
		);

		$this->assertSame("Sorry, I'm having trouble connecting to the AI service right now. Please try again later.", $response);
		$this->assertSame([], $visiblePartials);
		$this->assertSame([], array_values(array_filter(
			$traceEvents,
			static fn (array $event): bool => $event['event_type'] === 'assistant_response'
		)));
		$this->assertStringNotContainsString('private reasoning only', json_encode($traceEvents, JSON_THROW_ON_ERROR));
	}

	public function testProcessMessageQuarantinesAssistantDeltasAndKeepsToolProgressInOrder(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();
		$visibleAssistantPartials = [];
		$visibleToolProgress = [];
		$streamMismatchCount = 0;

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);
		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->willReturn([$this->createConversation('user', 'Stream the answer.')]);
		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([]);
		$toolProviderRegistry->expects($this->exactly(2))->method('setInvocationContext');

		$agentExecutor->expects($this->once())
			->method('run')
			->willReturnCallback(function (string $systemPrompt, array $messages, array $toolLoadout, array $options): array {
				$emit = $options['on_partial_result'] ?? null;
				$emitToolProgress = $options['on_tool_progress'] ?? null;
				$this->assertIsCallable($emit);
				$this->assertIsCallable($emitToolProgress);
				foreach (['First', ' paragraph.', "\n", "\n", 'Before', ' tool.'] as $delta) {
					$emit($delta);
				}
				$emitToolProgress('🔧 _Using tool: search_test..._');
				foreach (['Final', ' answer', '.'] as $delta) {
					$emit($delta);
				}

				return [
					'status' => 'completed',
					'terminalReason' => 'final_response',
					'content' => 'Final answer.',
					'messages' => [],
					'toolInvocations' => [],
					'rateLimitHeaders' => [],
				];
			});

		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService
		);

		$response = $service->processMessage(
			$bot,
			'Stream the answer.',
			'room-token',
			'owner',
			onProgress: static function (string $partial) use (&$visibleAssistantPartials): void {
				$visibleAssistantPartials[] = $partial;
			},
			onStreamMismatch: static function () use (&$streamMismatchCount): void {
				$streamMismatchCount++;
			},
			onToolProgress: static function (string $progress) use (&$visibleToolProgress): void {
				$visibleToolProgress[] = $progress;
			},
		);

		$this->assertSame('Final answer.', $response);
		$this->assertSame(['Final answer.'], $visibleAssistantPartials);
		$this->assertSame(['🔧 _Using tool: search_test..._'], $visibleToolProgress);
		$this->assertSame(1, count(array_filter(
			$visibleAssistantPartials,
			static fn (string $partial): bool => $partial === 'Final answer.'
		)));
		$this->assertSame(0, $streamMismatchCount);
	}

	#[DataProvider('streamMismatchCases')]
	public function testProcessMessageEmitsCanonicalTerminalContentAfterStreamMismatch(string $draftDelta, array $expectedPartials): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();
		$visibleAssistantPartials = [];
		$visibleToolProgress = [];
		$streamMismatchCount = 0;

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);
		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->willReturn([$this->createConversation('user', 'Give me the canonical answer.')]);
		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([]);
		$toolProviderRegistry->expects($this->exactly(2))->method('setInvocationContext');

		$agentExecutor->expects($this->once())
			->method('run')
			->willReturnCallback(function (string $systemPrompt, array $messages, array $toolLoadout, array $options) use ($draftDelta): array {
				$emit = $options['on_partial_result'] ?? null;
				$this->assertIsCallable($emit);
				$emit($draftDelta);

				return [
					'status' => 'completed',
					'terminalReason' => 'final_response',
					'content' => 'Canonical',
					'messages' => [],
					'toolInvocations' => [],
					'rateLimitHeaders' => [],
				];
			});

		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService
		);

		$response = $service->processMessage(
			$bot,
			'Give me the canonical answer.',
			'room-token',
			'owner',
			onProgress: static function (string $partial) use (&$visiblePartials): void {
				$visiblePartials[] = $partial;
			},
			onStreamMismatch: static function () use (&$streamMismatchCount): void {
				$streamMismatchCount++;
			}
		);

		$this->assertSame('Canonical', $response);
		$this->assertSame($expectedPartials, $visiblePartials);
		$this->assertSame(1, $streamMismatchCount);
	}

	/**
	 * @return array<string,array{string,array<int,string>}>
	 */
	public static function streamMismatchCases(): array {
		return [
			'unsent draft buffer' => ['Draft', ['Canonical']],
			'paragraph-shaped draft buffer' => ["Draft paragraph.\n\n", ['Canonical']],
		];
	}

	public function testProcessMessageAddsAvailableRagToolToExplicitBuiltInLoadout(): void {
		$bot = $this->createPersonalBot();
		$bot->setRagEnabled(true);
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$embeddingMapper = $this->createMock(EmbeddingMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);
		$embeddingMapper->expects($this->once())->method('findByBot')->with(7)->willReturn([new \stdClass()]);
		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->willReturn([$this->createConversation('user', 'Search the knowledge base.')]);
		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([]);
		$toolProviderRegistry->expects($this->exactly(2))->method('setInvocationContext');

		$agentExecutor->expects($this->once())
			->method('run')
			->with(
				$this->callback(function (string $systemPrompt): bool {
					$this->assertStringContainsString('`rag_search_documents`', $systemPrompt);
					$this->assertStringContainsString('Respect explicit scope', $systemPrompt);
					$this->assertStringContainsString('different source or tool', $systemPrompt);
					$this->assertStringNotContainsString('ALWAYS search first', $systemPrompt);
					$this->assertStringNotContainsString('You MUST follow these rules', $systemPrompt);
					return true;
				}),
				$this->anything(),
				[],
				$this->callback(function (array $options): bool {
					$this->assertSame([
						['name' => BuiltInToolProvider::TOOL_RAG_SEARCH, 'config' => []],
					], $options['built_in_tools'] ?? null);
					$this->assertArrayNotHasKey('rag_enabled', $options);
					return true;
				})
			)
			->willReturn([
				'status' => 'completed',
				'terminalReason' => 'final_response',
				'content' => 'Knowledge-base answer.',
				'messages' => [],
				'toolInvocations' => [],
				'rateLimitHeaders' => [],
			]);
		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService,
			embeddingMapper: $embeddingMapper
		);

		$this->assertSame('Knowledge-base answer.', $service->processMessage(
			$bot,
			'Search the knowledge base.',
			'room-token',
			'owner'
		));
	}

	public function testProcessMessageForwardsAttachmentInitialToolChoiceOnlyAsRequestPreference(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);
		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->willReturn([$this->createConversation('user', '')]);
		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([
			['name' => BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO, 'config' => []],
		]);
		$toolProviderRegistry->expects($this->exactly(2))->method('setInvocationContext');

		$agentExecutor->expects($this->once())
			->method('run')
			->with(
				$this->anything(),
				$this->anything(),
				[],
				$this->callback(function (array $options): bool {
					$this->assertSame([
						'type' => 'function',
						'function' => ['name' => BuiltInToolProvider::TOOL_ATTACHMENT_AUDIO],
					], $options['initial_tool_choice'] ?? null);
					$this->assertArrayNotHasKey('force_tool_call', $options);
					return true;
				})
			)
			->willReturn([
				'status' => 'completed',
				'terminalReason' => 'final_response',
				'content' => 'Attachment response.',
				'messages' => [],
				'toolInvocations' => [],
				'rateLimitHeaders' => [],
			]);

		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService
		);

		$this->assertSame('Attachment response.', $service->processMessage(
			$bot,
			'',
			'room-token',
			'owner',
			null,
			null,
			false,
			null,
			[
				'attachments' => [new IncomingTalkAttachment(
					IncomingTalkAttachment::KIND_AUDIO,
					'file',
					'audio/wav',
					'voice.wav',
					'file'
				)],
				'document_source_ids' => [],
				'image_source_ids' => [],
				'attachment_only' => true,
			]
		));
	}

	public function testProcessMessageScopesConversationHistoryToTalkThread(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$builtInToolProvider = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);

		$inserted = [];
		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static function (Conversation $conversation) use (&$inserted): Conversation {
				$inserted[] = $conversation;
				return $conversation;
			});
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->with(7, 'room-token', 42, 50)
			->willReturn([$this->createConversation('user', 'Earlier thread message.', 42)]);

		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')
			->willReturn([['name' => BuiltInToolProvider::TOOL_WIKI_SEARCH, 'config' => []]]);
		$builtInToolProvider->expects($this->exactly(2))
			->method('setInvocationContext');
		$agentExecutor->expects($this->once())
			->method('run')
			->willReturn([
				'status' => 'completed',
				'terminalReason' => 'final_response',
				'content' => 'Thread answer.',
				'messages' => [],
				'toolInvocations' => [],
				'rateLimitHeaders' => [],
			]);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class),
			null,
			null,
			null,
			$toolRegistry,
			$conversationMapper,
			$agentExecutor,
			$builtInToolProvider,
			$rateLimitService,
			$settingsService
		);

		$response = $service->processMessage(
			$bot,
			'Please continue the thread.',
			'room-token',
			'owner',
			'@personal-bot Please continue the thread.',
			null,
			false,
			null,
			null,
			42,
			42
		);

		$this->assertSame('Thread answer.', $response);
		$this->assertCount(2, $inserted);
		$this->assertSame(42, $inserted[0]->getThreadRootMessageId());
		$this->assertSame(42, $inserted[1]->getThreadRootMessageId());
	}

	public function testProcessMessageKeepsSameSecondHistoryChronologicalWithoutMergingCurrentPrompt(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();
		$sameSecond = 1_700_000_000;
		$stored = [
			$this->createConversation('user', 'First question.'),
			$this->createConversation('assistant', 'First answer.'),
			$this->createConversation('user', 'Second question.'),
			$this->createConversation('assistant', 'Second answer.'),
		];
		foreach ($stored as $index => $conversation) {
			$conversation->setId($index + 1);
			$conversation->setCreatedAt($sameSecond);
		}

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);
		$conversationMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(static function (Conversation $conversation) use (&$stored, $sameSecond): Conversation {
				$conversation->setId(count($stored) + 1);
				$conversation->setCreatedAt($sameSecond);
				$stored[] = $conversation;
				return $conversation;
			});
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->with(7, 'room-token', null, 50)
			->willReturnCallback(static function () use (&$stored): array {
				$history = $stored;
				usort($history, static fn (Conversation $left, Conversation $right): int => $left->getId() <=> $right->getId());
				return $history;
			});

		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([]);
		$toolProviderRegistry->expects($this->exactly(2))->method('setInvocationContext');
		$agentExecutor->expects($this->once())
			->method('run')
			->with(
				$this->anything(),
				$this->callback(function (array $messages): bool {
					$this->assertSame([
						['role' => 'user', 'content' => 'First question.'],
						['role' => 'assistant', 'content' => 'First answer.'],
						['role' => 'user', 'content' => 'Second question.'],
						['role' => 'assistant', 'content' => 'Second answer.'],
						['role' => 'user', 'content' => 'Current prompt.'],
					], $messages);
					return true;
				}),
				[],
				$this->anything()
			)
			->willReturn([
				'status' => 'completed',
				'terminalReason' => 'final_response',
				'content' => 'Current answer.',
				'messages' => [],
				'toolInvocations' => [],
				'rateLimitHeaders' => [],
			]);

		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService
		);

		$this->assertSame('Current answer.', $service->processMessage(
			$bot,
			'Current prompt.',
			'room-token',
			'owner',
			'@personal-bot Current prompt.'
		));
		$this->assertSame(['user', 'assistant'], array_map(
			static fn (Conversation $conversation): string => $conversation->getRole(),
			array_slice($stored, -2)
		));
	}

	public function testProcessMessageDoesNotExposeProviderEndpointInUserFacingError(): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$settings = new Settings();

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);

		$conversationMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->with(7, 'room-token', null, 50)
			->willReturn([]);

		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([]);

		$toolProviderRegistry->expects($this->exactly(2))
			->method('setInvocationContext');
		$agentExecutor->expects($this->once())
			->method('run')
			->willThrowException(new Exception('Failed to get response from AI: cURL error 7: Failed to connect to https://secret.example.invalid/v1/chat/completions'));

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class),
			null,
			null,
			null,
			$toolRegistry,
			$conversationMapper,
			$agentExecutor,
			$toolProviderRegistry,
			$rateLimitService,
			$settingsService
		);

		$response = $service->processMessage(
			$bot,
			'Hello',
			'room-token',
			'owner',
			'@personal-bot Hello'
		);

		$this->assertSame("Sorry, I'm having trouble connecting to the AI service right now. Please try again later.", $response);
		$this->assertStringNotContainsString('https://secret.example.invalid', $response);
		$this->assertStringNotContainsString('Error:', $response);
	}

	#[DataProvider('nonCompletedAgentResults')]
	public function testProcessMessageMapsNonCompletedAgentResultToSafeGenericResponse(
		string $status,
		string $terminalReason,
	): void {
		$bot = $this->createPersonalBot();
		$conversationMapper = $this->createMock(ConversationMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$agentExecutor = $this->createMock(AgentExecutor::class);
		$toolProviderRegistry = $this->createToolProviderRegistryMock();
		$rateLimitService = $this->createMock(RateLimitService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$traceService = $this->createMock(TraceService::class);
		$settings = new Settings();
		$executionErrors = [];
		$visibleAssistantPartials = [];
		$visibleToolProgress = [];

		$rateLimitService->method('isEnabled')->willReturn(false);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getDefaultTemperature')->willReturn(0.2);
		$conversationMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (Conversation $conversation): Conversation => $conversation);
		$conversationMapper->expects($this->once())
			->method('findByBotRoomAndThread')
			->willReturn([]);
		$toolRegistry->method('getToolsForBot')->willReturn([]);
		$toolRegistry->method('getBuiltInToolsForBot')->willReturn([]);
		$toolProviderRegistry->expects($this->exactly(2))->method('setInvocationContext');
		$agentExecutor->expects($this->once())
			->method('run')
			->willReturnCallback(function (string $systemPrompt, array $messages, array $toolLoadout, array $options) use ($status, $terminalReason): array {
				$emit = $options['on_partial_result'] ?? null;
				$emitToolProgress = $options['on_tool_progress'] ?? null;
				$this->assertIsCallable($emit);
				$this->assertIsCallable($emitToolProgress);
				$emit("Discarded paragraph.\n\n");
				$emit('🔧 _Using tool: spoofed_provider_text..._');
				$emitToolProgress('🔧 _Using tool: search_test..._');
				$emit(str_repeat('Discarded sentence. ', 8));

				return [
					'status' => $status,
					'terminalReason' => $terminalReason,
					'content' => 'Unsafe terminal content',
					'messages' => [],
					'toolInvocations' => [],
					'rateLimitHeaders' => [],
				];
			});
		$traceService->expects($this->never())->method('finishRun');

		$service = $this->createBotService(
			botMapper: $this->createMock(BotMapper::class),
			permissionService: $this->createMock(PermissionService::class),
			toolRegistry: $toolRegistry,
			conversationMapper: $conversationMapper,
			agentExecutor: $agentExecutor,
			toolProviderRegistry: $toolProviderRegistry,
			rateLimitService: $rateLimitService,
			settingsService: $settingsService,
			traceService: $traceService
		);

		$this->assertSame(
			"Sorry, I'm having trouble connecting to the AI service right now. Please try again later.",
			$service->processMessage(
				bot: $bot,
				message: 'Hello',
				roomToken: 'room-token',
				userId: 'owner',
				onProgress: static function (string $partial) use (&$visibleAssistantPartials): void {
					$visibleAssistantPartials[] = $partial;
				},
				traceRunId: 71,
				onExecutionError: static function (?string $errorSummary = null) use (&$executionErrors): void {
					$executionErrors[] = $errorSummary;
				},
				onToolProgress: static function (string $progress) use (&$visibleToolProgress): void {
					$visibleToolProgress[] = $progress;
				},
			)
		);
		$this->assertSame([
			'Agent execution terminated: ' . $terminalReason,
		], $executionErrors);
		$this->assertSame([], $visibleAssistantPartials);
		$this->assertSame(['🔧 _Using tool: search_test..._'], $visibleToolProgress);
	}

	/** @return array<string,array{string,string}> */
	public static function nonCompletedAgentResults(): array {
		return [
			'length' => ['budget_exhausted', 'length'],
			'content filter' => ['error', 'content_filter'],
			'turn budget' => ['budget_exhausted', 'max_turns'],
		];
	}

	public function testEnableTestingOverwritesPreviousReviewerSlot(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->exactly(2))
			->method('findById')
			->with(7)
			->willReturn($bot);
		$botMapper->expects($this->exactly(2))
			->method('update')
			->willReturnCallback(static fn (Bot $updated): Bot => $updated);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->expects($this->exactly(2))
			->method('canApproveBot')
			->willReturn(true);

		$service = $this->createBotService($botMapper, $permissionService);

		$service->enableTesting(7, 'reviewer-a');
		$this->assertSame('reviewer-a', $bot->getTestingEnabledBy());

		$service->enableTesting(7, 'reviewer-b');
		$this->assertSame('reviewer-b', $bot->getTestingEnabledBy());
	}

	public function testPendingReviewContextCanBeInspectedByApproverOrEnabledTester(): void {
		$bot = $this->createApprovedBot();
		$bot->setApprovalStatus('pending');
		$bot->setTestingEnabledBy('tester');

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->expects($this->exactly(2))
			->method('canApproveBot')
			->willReturnCallback(static fn (string $userId, Bot $candidate): bool => $userId === 'approver' && $candidate === $bot);

		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$permissionService
		);

		$this->assertTrue($service->canInspectPendingReviewContext($bot, 'tester'));
		$this->assertTrue($service->canInspectPendingReviewContext($bot, 'approver'));
		$this->assertFalse($service->canInspectPendingReviewContext($bot, 'audience'));
	}

	public function testShouldForceInitialAudioTranscriptionForSingleAudioOnlyAttachment(): void {
		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);
		$method = new \ReflectionMethod(BotService::class, 'shouldForceInitialAudioTranscription');
		$method->setAccessible(true);

		$result = $method->invoke($service, [
			'has_images' => false,
			'has_audio' => true,
			'has_room_documents' => false,
			'image_names' => [],
			'audio_names' => ['voice.wav'],
			'document_names' => [],
		], true);

		$this->assertTrue($result);
	}

	public function testShouldNotForceInitialAudioTranscriptionForMixedAttachments(): void {
		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);
		$method = new \ReflectionMethod(BotService::class, 'shouldForceInitialAudioTranscription');
		$method->setAccessible(true);

		$result = $method->invoke($service, [
			'has_images' => true,
			'has_audio' => true,
			'has_room_documents' => false,
			'image_names' => ['screenshot.png'],
			'audio_names' => ['voice.wav'],
			'document_names' => [],
		], true);

		$this->assertFalse($result);
	}

	public function testShouldForceInitialImageAnalysisForSingleImageOnlyAttachment(): void {
		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);
		$method = new \ReflectionMethod(BotService::class, 'shouldForceInitialImageAnalysis');
		$method->setAccessible(true);

		$result = $method->invoke($service, [
			'has_images' => true,
			'has_audio' => false,
			'has_room_documents' => false,
			'has_room_images' => true,
			'image_names' => ['screenshot.png'],
			'audio_names' => [],
			'document_names' => [],
		], true, true);

		$this->assertTrue($result);
	}

	public function testShouldNotForceInitialImageAnalysisForMixedAttachments(): void {
		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);
		$method = new \ReflectionMethod(BotService::class, 'shouldForceInitialImageAnalysis');
		$method->setAccessible(true);

		$result = $method->invoke($service, [
			'has_images' => true,
			'has_audio' => true,
			'has_room_documents' => false,
			'has_room_images' => true,
			'image_names' => ['screenshot.png'],
			'audio_names' => ['voice.wav'],
			'document_names' => [],
		], true, true);

		$this->assertFalse($result);
	}

	public function testAttachmentHistoryContextPersistsAttachmentNames(): void {
		$service = $this->createBotService(
			$this->createMock(BotMapper::class),
			$this->createMock(PermissionService::class)
		);
		$method = new \ReflectionMethod(BotService::class, 'appendAttachmentHistoryContext');
		$method->setAccessible(true);

		$result = $method->invoke($service, 'Please inspect this', [
			'attachments' => [
				new IncomingTalkAttachment(
					IncomingTalkAttachment::KIND_IMAGE,
					'file',
					'image/png',
					'screenshot.png',
					'file'
				),
			],
			'document_source_ids' => [],
			'image_source_ids' => [21],
			'attachment_only' => false,
		]);

		$this->assertStringContainsString('[Attachments: image: screenshot.png]', $result);
	}

	private function createApprovedBot(): Bot {
		$bot = new Bot();
		$bot->setId(7);
		$bot->setUserId('owner');
		$bot->setBotName('Live bot');
		$bot->setMentionName('@live-bot');
		$bot->setSystemPrompt('Live prompt');
		$bot->setVisibility('groups');
		$bot->setIsPublic(false);
		$bot->setAllowedGroups(json_encode(['group-a']) ?: '[]');
		$bot->setAllowedTeams(json_encode([]) ?: '[]');
		$bot->setApprovalStatus('approved');
		$bot->setApprovedBy('reviewer');
		$bot->setApprovedAt(100);
		$bot->setCreatedAt(10);
		$bot->setUpdatedAt(10);

		return $bot;
	}

	private function createPersonalBot(): Bot {
		$bot = new Bot();
		$bot->setId(7);
		$bot->setUserId('owner');
		$bot->setBotName('Personal bot');
		$bot->setMentionName('@personal-bot');
		$bot->setSystemPrompt('Personal prompt');
		$bot->setVisibility('personal');
		$bot->setIsPublic(false);
		$bot->setApprovalStatus('personal');
		$bot->setCreatedAt(10);
		$bot->setUpdatedAt(10);

		return $bot;
	}

	private function createConversation(string $role, string $content, ?int $threadRootMessageId = null): Conversation {
		$conversation = new Conversation();
		$conversation->setBotId(7);
		$conversation->setRoomToken('room-token');
		$conversation->setThreadRootMessageId($threadRootMessageId);
		$conversation->setUserId($role === 'assistant' ? '@personal-bot' : 'owner');
		$conversation->setRole($role);
		$conversation->setContent($content);
		$conversation->setCreatedAt(10);

		return $conversation;
	}

	private function createBotService(
		BotMapper $botMapper,
		PermissionService $permissionService,
		?IGroupManager $groupManager = null,
		?IUserManager $userManager = null,
		?IAppManager $appManager = null,
		?ToolRegistry $toolRegistry = null,
		?ConversationMapper $conversationMapper = null,
		?AgentExecutor $agentExecutor = null,
		?ToolProviderRegistry $toolProviderRegistry = null,
		?RateLimitService $rateLimitService = null,
		?SettingsService $settingsService = null,
		?WikiService $wikiService = null,
		?RoomImageIngestionService $roomImageIngestionService = null,
		?WikiRootRegistryService $wikiRootRegistryService = null,
		?WikiLocationService $wikiLocationService = null,
		?EmbeddingMapper $embeddingMapper = null,
		?TraceService $traceService = null,
	): BotService {
		return new BotService(
			$botMapper,
			$conversationMapper ?? $this->createMock(ConversationMapper::class),
			$this->createMock(ChatRoomMapper::class),
			$this->createMock(LoggerInterface::class),
			$groupManager ?? $this->createMock(IGroupManager::class),
			$userManager ?? $this->createUserManagerMock(),
			$appManager ?? $this->createMock(IAppManager::class),
			$this->createMock(BotSourceMapper::class),
			$embeddingMapper ?? $this->createMock(EmbeddingMapper::class),
			$this->createMock(BotToolMapper::class),
			$this->createMock(ToolMapper::class),
			$toolRegistry ?? $this->createMock(ToolRegistry::class),
			$agentExecutor ?? $this->createMock(AgentExecutor::class),
			$toolProviderRegistry ?? $this->createToolProviderRegistryMock(),
			$wikiService ?? $this->createMock(WikiService::class),
			$rateLimitService ?? $this->createMock(RateLimitService::class),
			$permissionService,
			$settingsService ?? $this->createMock(SettingsService::class),
			$this->createMock(RoomDocumentIngestionService::class),
			$roomImageIngestionService ?? $this->createMock(RoomImageIngestionService::class),
			$wikiRootRegistryService ?? $this->createMock(WikiRootRegistryService::class),
			$wikiLocationService ?? $this->createMock(WikiLocationService::class),
			$traceService
		);
	}

	private function createToolProviderRegistryMock(): ToolProviderRegistry {
		$registry = $this->createMock(ToolProviderRegistry::class);
		// Fixture emulating an external tool provider's metadata (kept
		// independent of any concrete provider so the test also works in
		// builds that ship without the catalogue provider).
		$metadata = [
			'catalogue_search' => [
				'label' => 'Course Catalogue Search',
				'summary' => 'Search the course catalogue for current learning opportunities.',
			],
			'catalogue_search_courses' => [
				'label' => 'Course Catalogue Search',
				'summary' => 'Search the course catalogue for training programs and learning content.',
			],
		];
		$registry->method('getToolMetadata')->willReturnCallback(
			static fn (string $name): ?array => $metadata[$name] ?? null
		);
		return $registry;
	}

	private function createUserManagerMock(): IUserManager {
		return $this->createMock(IUserManager::class);
	}

	private function createUserMock(): \OCP\IUser {
		return $this->createMock(\OCP\IUser::class);
	}
}
