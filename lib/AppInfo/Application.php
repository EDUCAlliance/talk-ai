<?php

declare(strict_types=1);

namespace OCA\EducAI\AppInfo;

use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\BotToolMapper;
use OCA\EducAI\Db\ChatRoomMapper;
use OCA\EducAI\Db\ConversationMapper;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Db\QueuedRequestMapper;
use OCA\EducAI\Db\RateLimitStateMapper;
use OCA\EducAI\Db\RoomDocumentEmbeddingMapper;
use OCA\EducAI\Db\RoomDocumentSourceMapper;
use OCA\EducAI\Db\RoomImageEmbeddingMapper;
use OCA\EducAI\Db\RoomImageSourceMapper;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Db\TraceEventMapper;
use OCA\EducAI\Db\TraceRunMapper;
use OCA\EducAI\Db\WikiRootBotMapper;
use OCA\EducAI\Db\WikiRootMapper;
use OCA\EducAI\Jobs\CleanupConversationsJob;
use OCA\EducAI\Jobs\CleanupOrphanedSourcesJob;
use OCA\EducAI\Jobs\CleanupRoomDocumentsJob;
use OCA\EducAI\Jobs\ProcessQueuedRequestsJob;
use OCA\EducAI\Jobs\RebuildWikiRootRegistryJob;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCA\EducAI\Jobs\SyncWikiRootIndexJob;
use OCA\EducAI\Listener\BotPickerListener;
use OCA\EducAI\Listener\TalkEnabledListener;
use OCA\EducAI\Listener\WikiFileEventListener;
use OCA\EducAI\Listener\WikiRegistryRebuildListener;
use OCA\EducAI\Migration\RegisterTalkBotRepairStep;
use OCA\EducAI\Reference\BotReferenceProvider;
use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\AppIconService;
use OCA\EducAI\Service\AttachmentResolver;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\McpClient;
use OCA\EducAI\Service\OnboardingService;
use OCA\EducAI\Service\PermissionService;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\RagRetriever;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\TalkBotRegistrationService;
use OCA\EducAI\Service\TextSessionResetService;
use OCA\EducAI\Service\ToolArgumentNormalizer;
use OCA\EducAI\Service\ToolExecutionPolicyService;
use OCA\EducAI\Service\ToolIntentService;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\ToolResultFallbackService;
use OCA\EducAI\Service\TraceService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCA\EducAI\Service\VisionClient;
use OCA\EducAI\Service\WikiLocationService;
use OCA\EducAI\Service\WikiFileEventSyncService;
use OCA\EducAI\Service\WikiRootRegistryService;
use OCA\EducAI\Service\WikiService;
use OCA\EducAI\Webhook\TalkAttachmentNormalizer;
use OCA\EducAI\Webhook\TalkHandler;
use OCA\EducAI\Webhook\TalkMessageParser;
use OCP\App\Events\AppEnableEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IConfig;
use OCP\IContainer;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;

class Application extends App implements IBootstrap {

	public const APP_ID = 'educai';
	/** User-facing product name (UI, Talk bot name). The private EDUC build overrides this to 'EDUC AI'. */
	public const APP_DISPLAY_NAME = 'Talk AI';
	/** Root folder for bot wikis in user storage. MUST NOT change on existing installs (paths are stored in the DB). */
	public const WIKI_ROOT_FOLDER = 'Talk AI';
	private const WIKI_REGISTRY_BACKFILL_VERSION = '20260504';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Register services
		$context->registerService(BotMapper::class, function (IContainer $c) {
			return new BotMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(ConversationMapper::class, function (IContainer $c) {
			return new ConversationMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(TraceRunMapper::class, function (IContainer $c) {
			return new TraceRunMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(TraceEventMapper::class, function (IContainer $c) {
			return new TraceEventMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(BotSourceMapper::class, function (IContainer $c) {
			return new BotSourceMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(EmbeddingMapper::class, function (IContainer $c) {
			return new EmbeddingMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(RoomDocumentSourceMapper::class, function (IContainer $c) {
			return new RoomDocumentSourceMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(RoomDocumentEmbeddingMapper::class, function (IContainer $c) {
			return new RoomDocumentEmbeddingMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(RoomImageSourceMapper::class, function (IContainer $c) {
			return new RoomImageSourceMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(RoomImageEmbeddingMapper::class, function (IContainer $c) {
			return new RoomImageEmbeddingMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(ToolMapper::class, function (IContainer $c) {
			return new ToolMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(BotToolMapper::class, function (IContainer $c) {
			return new BotToolMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(WikiRootMapper::class, function (IContainer $c) {
			return new WikiRootMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(WikiRootBotMapper::class, function (IContainer $c) {
			return new WikiRootBotMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(ChatRoomMapper::class, function (IContainer $c) {
			return new ChatRoomMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(RateLimitStateMapper::class, function (IContainer $c) {
			return new RateLimitStateMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(QueuedRequestMapper::class, function (IContainer $c) {
			return new QueuedRequestMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(SettingsMapper::class, function (IContainer $c) {
			return new SettingsMapper($c->get(\OCP\IDBConnection::class));
		});

		$context->registerService(CredentialService::class, function (IContainer $c) {
			return new CredentialService(
				$c->get(\OCP\Security\ICrypto::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(AppIconService::class, function (IContainer $c) {
			return new AppIconService(
				$c->get(SettingsMapper::class),
				$c->get(\OCP\Files\AppData\IAppDataFactory::class)->get(self::APP_ID),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(TalkBotRegistrationService::class, function (IContainer $c) {
			return new TalkBotRegistrationService(
				$c->get(SettingsMapper::class),
				$c->get(CredentialService::class),
				$c->get(\OCP\App\IAppManager::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\IRequest::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(SettingsService::class, function (IContainer $c) {
			return new SettingsService(
				$c->get(SettingsMapper::class),
				$c->get(CredentialService::class),
				$c->get(TalkBotRegistrationService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(LLMClient::class, function (IContainer $c) {
			return new LLMClient(
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(SettingsService::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCP\IConfig::class)
			);
		});

		$context->registerService(EmbeddingClient::class, function (IContainer $c) {
			return new EmbeddingClient(
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(SettingsService::class),
				$c->get(RateLimitService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(DoclingClient::class, function (IContainer $c) {
			return new DoclingClient(
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(SettingsService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(VisionClient::class, function (IContainer $c) {
			return new VisionClient(
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(SettingsService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(SpeechToTextClient::class, function (IContainer $c) {
			return new SpeechToTextClient(
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(SettingsService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(UrlContentFetcher::class, function (IContainer $c) {
			return new UrlContentFetcher(
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(DoclingClient::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(AttachmentResolver::class, function (IContainer $c) {
			return new AttachmentResolver(
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(RagIngestionService::class, function (IContainer $c) {
			return new RagIngestionService(
				$c->get(BotSourceMapper::class),
				$c->get(EmbeddingMapper::class),
				$c->get(EmbeddingClient::class),
				$c->get(DoclingClient::class),
				$c->get(UrlContentFetcher::class),
				$c->get(SettingsService::class),
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(RagRetriever::class, function (IContainer $c) {
			return new RagRetriever(
				$c->get(EmbeddingMapper::class),
				$c->get(EmbeddingClient::class),
				$c->get(SettingsService::class)
			);
		});

		$context->registerService(RoomDocumentIngestionService::class, function (IContainer $c) {
			return new RoomDocumentIngestionService(
				$c->get(RoomDocumentSourceMapper::class),
				$c->get(RoomDocumentEmbeddingMapper::class),
				$c->get(AttachmentResolver::class),
				$c->get(EmbeddingClient::class),
				$c->get(DoclingClient::class),
				$c->get(SettingsService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(RoomImageIngestionService::class, function (IContainer $c) {
			return new RoomImageIngestionService(
				$c->get(RoomImageSourceMapper::class),
				$c->get(RoomImageEmbeddingMapper::class),
				$c->get(AttachmentResolver::class),
				$c->get(VisionClient::class),
				$c->get(EmbeddingClient::class),
				$c->get(SettingsService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(ToolRegistry::class, function (IContainer $c) {
			return new ToolRegistry(
				$c->get(ToolMapper::class),
				$c->get(BotToolMapper::class)
			);
		});

		$context->registerService(McpClient::class, function (IContainer $c) {
			return new McpClient(
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(CredentialService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(WikiService::class, function (IContainer $c) {
			return new WikiService(
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(BotMapper::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(WikiLocationService::class),
				$c->get(TextSessionResetService::class)
			);
		});

		$context->registerService(TextSessionResetService::class, function (IContainer $c) {
			return new TextSessionResetService(
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(WikiLocationService::class, function (IContainer $c) {
			return new WikiLocationService(
				$c->get(\OCP\App\IAppManager::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(WikiFileEventSyncService::class, function (IContainer $c) {
			return new WikiFileEventSyncService(
				$c->get(WikiRootRegistryService::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(WikiRootRegistryService::class, function (IContainer $c) {
			return new WikiRootRegistryService(
				$c->get(BotMapper::class),
				$c->get(BotToolMapper::class),
				$c->get(WikiRootMapper::class),
				$c->get(WikiRootBotMapper::class),
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(WikiLocationService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(ToolExecutionPolicyService::class, function () {
			return new ToolExecutionPolicyService();
		});

		$context->registerService(ToolIntentService::class, function () {
			return new ToolIntentService();
		});

		$context->registerService(ToolArgumentNormalizer::class, function () {
			return new ToolArgumentNormalizer();
		});

		$context->registerService(ToolResultFallbackService::class, function () {
			return new ToolResultFallbackService();
		});

		$context->registerService(TraceService::class, function (IContainer $c) {
			return new TraceService(
				$c->get(TraceRunMapper::class),
				$c->get(TraceEventMapper::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(BuiltInToolProvider::class, function (IContainer $c) {
			return new BuiltInToolProvider(
				$c->get(SettingsService::class),
				$c->get(EmbeddingMapper::class),
				$c->get(EmbeddingClient::class),
				$c->get(RoomDocumentEmbeddingMapper::class),
				$c->get(RoomDocumentSourceMapper::class),
				$c->get(RoomImageEmbeddingMapper::class),
				$c->get(RoomImageSourceMapper::class),
				$c->get(AttachmentResolver::class),
				$c->get(VisionClient::class),
				$c->get(SpeechToTextClient::class),
				$c->get(WikiService::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(ToolExecutionPolicyService::class)
			);
		});

		$context->registerService(\OCA\EducAI\ToolProvider\ToolProviderRegistry::class, function (IContainer $c) {
			return new \OCA\EducAI\ToolProvider\ToolProviderRegistry(
				$c->get(BuiltInToolProvider::class),
				$c->get(\OCP\EventDispatcher\IEventDispatcher::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(TalkAttachmentNormalizer::class, function () {
			return new TalkAttachmentNormalizer();
		});

		$context->registerService(TalkMessageParser::class, function (IContainer $c) {
			return new TalkMessageParser(
				$c->get(TalkAttachmentNormalizer::class)
			);
		});

		$context->registerService(AgentExecutor::class, function (IContainer $c) {
			return new AgentExecutor(
				$c->get(LLMClient::class),
				$c->get(McpClient::class),
				$c->get(ToolRegistry::class),
				$c->get(\OCA\EducAI\ToolProvider\ToolProviderRegistry::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(ToolExecutionPolicyService::class),
				$c->get(ToolArgumentNormalizer::class),
				$c->get(ToolResultFallbackService::class),
				$c->get(TraceService::class)
			);
		});

		$context->registerService(RateLimitService::class, function (IContainer $c) {
			return new RateLimitService(
				$c->get(RateLimitStateMapper::class),
				$c->get(QueuedRequestMapper::class),
				$c->get(SettingsService::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(PermissionService::class, function (IContainer $c) {
			return new PermissionService(
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\App\IAppManager::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(BotService::class, function (IContainer $c) {
			return new BotService(
				$c->get(BotMapper::class),
				$c->get(ConversationMapper::class),
				$c->get(ChatRoomMapper::class),
				$c->get(LLMClient::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\App\IAppManager::class),
				$c->get(BotSourceMapper::class),
				$c->get(EmbeddingMapper::class),
				$c->get(BotToolMapper::class),
				$c->get(ToolMapper::class),
				$c->get(ToolRegistry::class),
				$c->get(AgentExecutor::class),
				$c->get(\OCA\EducAI\ToolProvider\ToolProviderRegistry::class),
				$c->get(WikiService::class),
				$c->get(RateLimitService::class),
				$c->get(PermissionService::class),
				$c->get(SettingsService::class),
				$c->get(RoomDocumentIngestionService::class),
				$c->get(RoomImageIngestionService::class),
				$c->get(WikiRootRegistryService::class),
				$c->get(WikiLocationService::class),
				$c->get(ToolIntentService::class),
				$c->get(TraceService::class)
			);
		});

		$context->registerService(ReindexBotSourceJob::class, function (IContainer $c) {
			return new ReindexBotSourceJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(RagIngestionService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(CleanupConversationsJob::class, function (IContainer $c) {
			return new CleanupConversationsJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(ConversationMapper::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(CleanupOrphanedSourcesJob::class, function (IContainer $c) {
			return new CleanupOrphanedSourcesJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(BotSourceMapper::class),
				$c->get(EmbeddingMapper::class),
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(CleanupRoomDocumentsJob::class, function (IContainer $c) {
			return new CleanupRoomDocumentsJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(RoomDocumentIngestionService::class),
				$c->get(RoomImageIngestionService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(ProcessQueuedRequestsJob::class, function (IContainer $c) {
			return new ProcessQueuedRequestsJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(RateLimitService::class),
				$c->get(BotService::class),
				$c->get(BotMapper::class),
				$c->get(TalkHandler::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(SyncWikiRootIndexJob::class, function (IContainer $c) {
			return new SyncWikiRootIndexJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(WikiRootMapper::class),
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(WikiService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(RebuildWikiRootRegistryJob::class, function (IContainer $c) {
			return new RebuildWikiRootRegistryJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(WikiRootRegistryService::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(OnboardingService::class, function (IContainer $c) {
			return new OnboardingService(
				$c->get(ChatRoomMapper::class),
				$c->get(BotMapper::class),
				$c->get(ConversationMapper::class),
				$c->get(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(TalkHandler::class, function (IContainer $c) {
			return new TalkHandler(
				$c->get(BotService::class),
				$c->get(SettingsService::class),
				$c->get(OnboardingService::class),
				$c->get(TalkMessageParser::class),
				$c->get(RoomDocumentIngestionService::class),
				$c->get(RoomImageIngestionService::class),
				$c->get(\OCP\Http\Client\IClientService::class),
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(TraceService::class)
			);
		});

		$context->registerService(RegisterTalkBotRepairStep::class, function (IContainer $c) {
			return new RegisterTalkBotRepairStep(
				$c->get(TalkBotRegistrationService::class)
			);
		});

		$context->registerService(TalkEnabledListener::class, function (IContainer $c) {
			return new TalkEnabledListener(
				$c->get(TalkBotRegistrationService::class)
			);
		});

		$context->registerService(WikiFileEventListener::class, function (IContainer $c) {
			return new WikiFileEventListener(
				$c->get(WikiFileEventSyncService::class)
			);
		});

		$context->registerService(WikiRegistryRebuildListener::class, function (IContainer $c) {
			return new WikiRegistryRebuildListener(
				$c->get(\OCP\BackgroundJob\IJobList::class)
			);
		});

		$context->registerService(BotReferenceProvider::class, function (IContainer $c) {
			return new BotReferenceProvider(
				$c->get(BotService::class),
				$c->get(AppIconService::class),
				$c->get(\OCP\IL10N::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCP\IUserSession::class)->getUser()?->getUID()
			);
		});

		$context->registerReferenceProvider(BotReferenceProvider::class);

		$context->registerEventListener(
			RenderReferenceEvent::class,
			BotPickerListener::class
		);

		$context->registerEventListener(
			AppEnableEvent::class,
			TalkEnabledListener::class
		);
		$context->registerEventListener(
			AppEnableEvent::class,
			WikiRegistryRebuildListener::class
		);

		$context->registerEventListener(
			NodeCreatedEvent::class,
			WikiFileEventListener::class
		);
		$context->registerEventListener(
			NodeWrittenEvent::class,
			WikiFileEventListener::class
		);
		$context->registerEventListener(
			NodeDeletedEvent::class,
			WikiFileEventListener::class
		);
		$context->registerEventListener(
			NodeRenamedEvent::class,
			WikiFileEventListener::class
		);
		$context->registerEventListener(
			NodeCopiedEvent::class,
			WikiFileEventListener::class
		);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (INavigationManager $navigationManager, IURLGenerator $urlGenerator, IL10N $l10n, AppIconService $appIconService): void {
			$navigationManager->add(static function () use ($urlGenerator, $l10n, $appIconService): array {
				return [
					'id' => self::APP_ID,
					'order' => 100,
					'href' => $urlGenerator->linkToRoute('educai.page.index'),
					'name' => self::APP_DISPLAY_NAME,
					'icon' => $appIconService->getAppNavigationIcon(),
					'type' => INavigationManager::TYPE_APPS,
					'app' => self::APP_ID,
				];
			});
		});

		$context->injectFn(function (IConfig $config, IJobList $jobList): void {
			$key = 'wiki_root_registry_backfill_version';
			if ($config->getAppValue(self::APP_ID, $key, '') === self::WIKI_REGISTRY_BACKFILL_VERSION) {
				return;
			}

			$arguments = ['reason' => 'app_upgrade'];
			if (!$jobList->has(RebuildWikiRootRegistryJob::class, $arguments)) {
				$jobList->scheduleAfter(RebuildWikiRootRegistryJob::class, time() + 5, $arguments);
			}
			$config->setAppValue(self::APP_ID, $key, self::WIKI_REGISTRY_BACKFILL_VERSION);
		});
	}
}
