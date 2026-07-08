<?php

declare(strict_types=1);

namespace OCA\EducAI\Webhook;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\ChatRoom;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\OnboardingService;
use OCA\EducAI\Service\RoomDocumentIngestionService;
use OCA\EducAI\Service\RoomImageIngestionService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\TraceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type MessageContext from \OCA\EducAI\TypeDefinitions
 */
class TalkHandler {
	private const IGNORED_TALK_SYSTEM_EVENT_NAMES = [
		'thread_created',
	];

	private BotService $botService;
	private SettingsService $settingsService;
	private OnboardingService $onboardingService;
	private TalkMessageParser $talkMessageParser;
	private RoomDocumentIngestionService $roomDocumentIngestionService;
	private RoomImageIngestionService $roomImageIngestionService;
	private IClientService $clientService;
	private IDBConnection $db;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;
	private ?TraceService $traceService;

	public function __construct(
		BotService $botService,
		SettingsService $settingsService,
		OnboardingService $onboardingService,
		TalkMessageParser $talkMessageParser,
		RoomDocumentIngestionService $roomDocumentIngestionService,
		RoomImageIngestionService $roomImageIngestionService,
		IClientService $clientService,
		IDBConnection $db,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger,
		?TraceService $traceService = null,
	) {
		$this->botService = $botService;
		$this->settingsService = $settingsService;
		$this->onboardingService = $onboardingService;
		$this->talkMessageParser = $talkMessageParser;
		$this->roomDocumentIngestionService = $roomDocumentIngestionService;
		$this->roomImageIngestionService = $roomImageIngestionService;
		$this->clientService = $clientService;
		$this->db = $db;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->traceService = $traceService;
	}

	/**
	 * Handle incoming webhook from Nextcloud Talk
	 *
	 * @param array $request Contains 'body', 'signature', 'random'
	 * @return void
	 * @throws Exception
	 */
	public function handleIncoming(array $request): void {
		$body = $request['body'] ?? '';
		$signature = $request['signature'] ?? '';
		$random = $request['random'] ?? '';

		// Verify signature
		if (!$this->verifySignature($signature, $random, $body)) {
			$this->logger->warning('Invalid webhook signature');
			throw new Exception('Invalid signature');
		}

		// Parse payload
		$payload = json_decode($body, true);
		if (!$payload) {
			$this->logger->error('Invalid JSON payload', ['body_length' => strlen($body)]);
			throw new Exception('Invalid JSON payload');
		}

		$this->logger->info('Received Talk webhook', [
			'payload_keys' => array_keys($payload),
			'object_keys' => array_keys($payload['object'] ?? []),
			'actor' => $payload['actor']['id'] ?? 'unknown',
		]);

		if ($this->isIgnoredTalkSystemEvent($payload)) {
			$this->logger->info('Ignoring Talk system event', [
				'object_name' => $payload['object']['name'] ?? '',
				'object_id' => $payload['object']['id'] ?? '',
			]);
			return;
		}

		$incomingMessage = $this->talkMessageParser->parse($payload);
		$message = $incomingMessage->getText();
		$roomToken = $incomingMessage->getRoomToken();
		$userId = $incomingMessage->getActorId();
		$messageId = $incomingMessage->getMessageId();
		$threadContext = $this->resolveTalkThreadContext($incomingMessage);
		$replyTargetId = $threadContext['reply_target_id'];
		$threadRootMessageId = $threadContext['thread_root_message_id'];

		$this->logger->info('Extracted webhook data', [
			'message_length' => strlen($message),
			'room_token' => $roomToken,
			'user_id' => $userId,
			'message_id' => $messageId,
			'reply_target_id' => $replyTargetId,
			'thread_root_message_id' => $threadRootMessageId,
			'attachment_count' => count($incomingMessage->getAttachments()),
		]);

		if (empty($roomToken) || (empty($message) && !$incomingMessage->hasAttachments())) {
			$this->logger->warning('Missing required fields in webhook payload', [
				'has_message' => !empty($message),
				'has_attachments' => $incomingMessage->hasAttachments(),
				'has_room_token' => !empty($roomToken),
			]);
			return;
		}

		// Check for ((RESET)) command first
		if ($this->isResetCommand($message)) {
			$this->handleResetCommand($roomToken, $userId, $replyTargetId, $message);
			return;
		}

		// Try to detect a mentioned bot
		$mentionedBot = $this->detectBot($message);
		
		// If no mention, check for "always" mode bots in this room
		if ($mentionedBot === null) {
			$this->handleAlwaysModeMessage($roomToken, $userId, $message, $messageId, $replyTargetId, $threadRootMessageId, $incomingMessage);
			return;
		}

		// We have a mentioned bot - process it
		$this->handleMentionedBot($mentionedBot, $roomToken, $userId, $message, $messageId, $replyTargetId, $threadRootMessageId, $incomingMessage);
	}

	private function resolveReplyTargetId(IncomingTalkMessage $incomingMessage): int {
		return $incomingMessage->getInReplyTo() ?? $incomingMessage->getMessageId();
	}

	private function resolveThreadRootMessageId(IncomingTalkMessage $incomingMessage): ?int {
		return $incomingMessage->getThreadRootMessageId();
	}

	/**
	 * @return array{reply_target_id:int,thread_root_message_id:?int}
	 */
	private function resolveTalkThreadContext(IncomingTalkMessage $incomingMessage): array {
		$threadRootMessageId = $this->resolveThreadRootMessageId($incomingMessage)
			?? $this->findThreadRootMessageIdForComment($incomingMessage->getRoomToken(), $incomingMessage->getMessageId());

		if ($threadRootMessageId !== null) {
			return [
				'reply_target_id' => $incomingMessage->getMessageId(),
				'thread_root_message_id' => $threadRootMessageId,
			];
		}

		return [
			'reply_target_id' => $this->resolveReplyTargetId($incomingMessage),
			'thread_root_message_id' => null,
		];
	}

	private function resolveStreamingReplyTarget(bool $isFirstMessage, int $replyTargetId, ?int $threadRootMessageId): int {
		if ($threadRootMessageId !== null) {
			return $replyTargetId;
		}

		return $isFirstMessage ? $replyTargetId : 0;
	}

	private function findThreadRootMessageIdForComment(string $roomToken, int $messageId): ?int {
		if ($roomToken === '' || $messageId <= 0) {
			return null;
		}

		try {
			$roomId = $this->findTalkRoomId($roomToken);
			if ($roomId === null) {
				return null;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->select('parent_id', 'topmost_parent_id', 'meta_data')
				->from('comments')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)));

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			if (!is_array($row)) {
				return null;
			}

			$metaThreadId = $this->extractThreadIdFromMetaData($row['meta_data'] ?? null);
			if ($metaThreadId !== null) {
				return $metaThreadId;
			}

			$topmostParentId = (int)($row['topmost_parent_id'] ?? 0);
			if ($topmostParentId > 0 && $this->talkThreadExists($roomId, $topmostParentId)) {
				return $topmostParentId;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->debug('Could not resolve Talk thread context from comments table', [
				'room_token' => $roomToken,
				'message_id' => $messageId,
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}

	private function findTalkRoomId(string $roomToken): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('talk_rooms')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($roomToken)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!is_array($row) || !is_numeric($row['id'] ?? null)) {
			return null;
		}

		return (int)$row['id'];
	}

	private function talkThreadExists(int $roomId, int $threadId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('talk_threads')
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($threadId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return is_array($row) && (int)($row['count'] ?? 0) > 0;
	}

	private function extractThreadIdFromMetaData(mixed $metaData): ?int {
		if (is_string($metaData)) {
			$decoded = json_decode($metaData, true);
			$metaData = is_array($decoded) ? $decoded : [];
		}

		if (!is_array($metaData)) {
			return null;
		}

		foreach ([$metaData['thread_id'] ?? null, $metaData['threadId'] ?? null] as $candidate) {
			if (is_numeric($candidate)) {
				$value = (int)$candidate;
				return $value > 0 ? $value : null;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function isIgnoredTalkSystemEvent(array $payload): bool {
		$objectName = $payload['object']['name'] ?? '';
		if (!is_string($objectName)) {
			return false;
		}

		return in_array($objectName, self::IGNORED_TALK_SYSTEM_EVENT_NAMES, true);
	}

	/**
	 * Check if message is a reset command
	 */
	private function isResetCommand(string $message): bool {
		$trimmed = trim($message);
		// Check for ((RESET)) - case insensitive
		return preg_match('/^\(\(RESET\)\)/i', $trimmed) === 1;
	}

	/**
	 * Handle the ((RESET)) command
	 */
	private function handleResetCommand(string $roomToken, string $userId, int $replyTargetId, string $message): void {
		$this->logger->info('Reset command received', [
			'room_token' => $roomToken,
			'user_id' => $userId,
		]);

		// Check if there's a bot mention after ((RESET)) (e.g., "((RESET)) @mybot")
		$resetBot = $this->detectBot($message);
		
		if ($resetBot !== null) {
			// Reset specific bot
			if (!$this->botService->userCanAccessBot($resetBot, $userId)) {
				$this->logger->info('User cannot access bot for reset', [
					'user_id' => $userId,
					'bot_id' => $resetBot->getId(),
				]);
				return;
			}

			$success = $this->onboardingService->resetRoom($resetBot->getId(), $roomToken);
			if ($success) {
				$this->roomDocumentIngestionService->deleteRoomDocuments($resetBot->getId(), $roomToken);
				$this->roomImageIngestionService->deleteRoomImages($resetBot->getId(), $roomToken);
			}
			if ($success) {
				$this->sendReplyToTalk(
					$roomToken,
					"Chat with **{$resetBot->getMentionName()}** has been reset. " .
					"Mention the bot again to start fresh.",
					$replyTargetId
				);
			} else {
				$this->sendReplyToTalk(
					$roomToken,
					"Failed to reset chat. Please try again.",
					$replyTargetId
				);
			}
			return;
		}

		// No specific bot - reset all bots in this room that the user can access
		// Use getAllBotsWithStateForRoom to include bots in onboarding (not just completed always-mode)
		$allBotsInRoom = $this->onboardingService->getAllBotsWithStateForRoom($roomToken);
		$resetCount = 0;

		foreach ($allBotsInRoom as $entry) {
			$bot = $entry['bot'];
			if ($this->botService->userCanAccessBot($bot, $userId)) {
				$this->onboardingService->resetRoom($bot->getId(), $roomToken);
				$this->roomDocumentIngestionService->deleteRoomDocuments($bot->getId(), $roomToken);
				$this->roomImageIngestionService->deleteRoomImages($bot->getId(), $roomToken);
				$resetCount++;
			}
		}

		if ($resetCount > 0) {
			$this->sendReplyToTalk(
				$roomToken,
				"Chat has been reset. Mention a bot to start fresh.",
				$replyTargetId
			);
		} else {
			$this->sendReplyToTalk(
				$roomToken,
				"No active bots found in this chat. Mention a bot to activate it.",
				$replyTargetId
			);
		}
	}

	/**
	 * Handle a message without a bot mention (for "always" mode bots or onboarding responses)
	 */
	private function handleAlwaysModeMessage(
		string $roomToken,
		string $userId,
		string $message,
		int $messageId,
		int $replyTargetId,
		?int $threadRootMessageId,
		IncomingTalkMessage $incomingMessage
	): void {
		// First, check if there are any bots in onboarding mode - they should receive answers without mentions
		$onboardingBots = $this->onboardingService->getOnboardingInProgressBotsForRoom($roomToken);
		
		foreach ($onboardingBots as $entry) {
			$bot = $entry['bot'];
			$effectiveBot = $this->botService->getEffectiveBotForUser($bot, $userId);
			$room = $entry['room'];

			if (!$this->botService->userCanAccessBot($bot, $userId)) {
				continue;
			}

			$this->logger->info('Processing onboarding response without mention', [
				'bot_id' => $effectiveBot->getId(),
				'bot_name' => $effectiveBot->getBotName(),
				'onboarding_status' => $room->getOnboardingStatus(),
			]);

			// Handle the onboarding response
			$this->handleOnboardingMessage($effectiveBot, $room, $message, $roomToken, $replyTargetId);
			return;
		}

		// Then check for "always" mode bots with completed onboarding
		$alwaysModeBots = $this->onboardingService->getAlwaysModeBotsForRoom($roomToken);

		if (count($alwaysModeBots) === 0) {
			$this->logger->debug('No always-mode or onboarding bots in room', [
				'room_token' => $roomToken,
			]);
			return;
		}

		// Process with the first "always" mode bot the user can access
		foreach ($alwaysModeBots as $entry) {
			$bot = $entry['bot'];
			$effectiveBot = $this->botService->getEffectiveBotForUser($bot, $userId);
			$room = $entry['room'];

			if (!$this->botService->userCanAccessBot($bot, $userId)) {
				continue;
			}

			$this->logger->info('Processing message with always-mode bot', [
				'bot_id' => $effectiveBot->getId(),
				'bot_name' => $effectiveBot->getBotName(),
			]);

			$messageContext = $this->buildMessageContext($effectiveBot, $roomToken, $userId, $messageId, $incomingMessage);
			if ($messageContext['capability_error'] !== null) {
				$this->sendReplyToTalk($roomToken, $messageContext['capability_error'], $replyTargetId);
				return;
			}

			$isAttachmentOnly = trim($message) === '';
			$messageContext['context']['attachment_only'] = $isAttachmentOnly;
			$effectiveMessage = !$isAttachmentOnly ? $message : $this->buildAttachmentOnlyPrompt($incomingMessage);
			$this->processNormalMessage($effectiveBot, $room, $roomToken, $userId, $effectiveMessage, $replyTargetId, null, $incomingMessage, $messageContext['context'], $threadRootMessageId);
			return;
		}

		$this->logger->debug('No accessible always-mode bots for user', [
			'room_token' => $roomToken,
			'user_id' => $userId,
		]);
	}

	/**
	 * Handle a message with a bot mention
	 */
	private function handleMentionedBot(
		Bot $bot,
		string $roomToken,
		string $userId,
		string $message,
		int $messageId,
		int $replyTargetId,
		?int $threadRootMessageId,
		IncomingTalkMessage $incomingMessage
	): void {
		$this->logger->info('Bot detected', [
			'bot_id' => $bot->getId(),
			'bot_name' => $bot->getBotName(),
			'mention_name' => $bot->getMentionName(),
		]);

		// Enforce access control
		if (!$this->botService->userCanAccessBot($bot, $userId)) {
			$this->logger->info('User not allowed to access bot; ignoring mention', [
				'user_id' => $userId,
				'bot_id' => $bot->getId(),
				'mention_name' => $bot->getMentionName(),
			]);
			return;
		}

		$effectiveBot = $this->botService->getEffectiveBotForUser($bot, $userId);

		// Get or create room state
		$room = $this->onboardingService->getRoomState($effectiveBot->getId(), $roomToken);
		
		if ($room === null) {
			// First time this bot is used in this room - start onboarding
			$this->handleFirstActivation($effectiveBot, $roomToken, $userId, $replyTargetId);
			return;
		}

		// Remove mention from message
		$cleanMessage = $this->removeBotMention($message, $effectiveBot->getMentionName());
		$messageContext = $this->buildMessageContext($effectiveBot, $roomToken, $userId, $messageId, $incomingMessage);

		if ($messageContext['capability_error'] !== null) {
			$this->sendReplyToTalk($roomToken, $messageContext['capability_error'], $replyTargetId);
			return;
		}

		if (!$room->isOnboardingComplete()) {
			// Still in onboarding
			$this->handleOnboardingMessage($effectiveBot, $room, $cleanMessage, $roomToken, $replyTargetId);
			return;
		}

		// Normal message processing
		$isAttachmentOnly = trim($cleanMessage) === '';
		$messageContext['context']['attachment_only'] = $isAttachmentOnly;
		if ($isAttachmentOnly) {
			$cleanMessage = $this->buildAttachmentOnlyPrompt($incomingMessage);
		}

		$this->processNormalMessage($effectiveBot, $room, $roomToken, $userId, $cleanMessage, $replyTargetId, $message, $incomingMessage, $messageContext['context'], $threadRootMessageId);
	}

	/**
	 * Handle first activation of a bot in a room
	 */
	private function handleFirstActivation(Bot $bot, string $roomToken, string $userId, int $replyTargetId): void {
		$this->logger->info('First bot activation in room', [
			'bot_id' => $bot->getId(),
			'room_token' => $roomToken,
			'user_id' => $userId,
		]);

		// Initialize room state
		$this->onboardingService->initializeRoom($bot->getId(), $roomToken, $userId);

		// Send welcome message
		$welcomeMessage = $this->onboardingService->getWelcomeMessage($bot);
		$this->sendReplyToTalk($roomToken, $welcomeMessage, $replyTargetId);
	}

	/**
	 * Handle a message during onboarding
	 */
	private function handleOnboardingMessage(Bot $bot, ChatRoom $room, string $message, string $roomToken, int $replyTargetId): void {
		$this->logger->info('Processing onboarding message', [
			'bot_id' => $bot->getId(),
			'status' => $room->getOnboardingStatus(),
			'message' => $message,
		]);

		$result = $this->onboardingService->handleOnboardingResponse($room, $bot, $message);

		if ($result['message'] !== null) {
			$this->sendReplyToTalk($roomToken, $result['message'], $replyTargetId);
		}

		if ($result['completed']) {
			$this->logger->info('Onboarding completed', [
				'bot_id' => $bot->getId(),
				'room_token' => $roomToken,
			]);
		}
	}

	/**
	 * Process a normal message (after onboarding is complete)
	 */
	private function processNormalMessage(
		Bot $bot,
		ChatRoom $room,
		string $roomToken,
		string $userId,
		string $cleanMessage,
		int $replyTargetId,
		?string $originalMessage = null,
		?IncomingTalkMessage $incomingMessage = null,
		?array $messageContext = null,
		?int $threadRootMessageId = null
	): void {
		$attachmentCount = $incomingMessage instanceof IncomingTalkMessage ? count($incomingMessage->getAttachments()) : 0;
		$this->logger->info('Processing message for bot', [
			'bot_id' => $bot->getId(),
			'message_length' => strlen($originalMessage ?? $cleanMessage),
			'clean_message_length' => strlen($cleanMessage),
			'attachment_count' => $attachmentCount,
		]);

		$traceRunId = $this->traceService?->startRun([
			'user_id' => $userId,
			'bot_id' => $bot->getId(),
			'bot_mention_name' => $bot->getMentionName(),
			'room_token' => $roomToken,
			'talk_message_id' => $incomingMessage instanceof IncomingTalkMessage ? $incomingMessage->getMessageId() : null,
			'reply_target_message_id' => $replyTargetId,
			'thread_root_message_id' => $threadRootMessageId,
			'source' => 'talk',
			'user_message' => $originalMessage ?? $cleanMessage,
		]);
		$this->traceService?->recordEvent($traceRunId, 'user_message', [
			'status' => 'ok',
			'payload' => [
				'original_message' => $originalMessage ?? $cleanMessage,
				'clean_message' => $cleanMessage,
				'message_length' => strlen($originalMessage ?? $cleanMessage),
				'clean_message_length' => strlen($cleanMessage),
				'attachment_count' => $attachmentCount,
				'attachments' => $this->summarizeTraceAttachments($incomingMessage),
			],
		]);

		// Build onboarding context for system prompt
		$onboardingContext = $this->onboardingService->buildOnboardingContext($room);

		$alreadySent = false;
		$isFirstMessage = true;
		$thinkingMessageSent = false;
		$thinkingBuffer = '';
		$isInThinkingMode = false;
		
		$traceStatus = 'success';

		try {
			// Process message and get response
			$response = $this->botService->processMessage(
				$bot,
				$cleanMessage,
				$roomToken,
				$userId,
				$originalMessage ?? $cleanMessage,
				function (string $partial) use ($roomToken, $replyTargetId, $threadRootMessageId, &$alreadySent, &$isFirstMessage, &$thinkingMessageSent, &$thinkingBuffer, &$isInThinkingMode) {
					$replyTo = $this->resolveStreamingReplyTarget($isFirstMessage, $replyTargetId, $threadRootMessageId);
					if ($this->isProgressOnlyPartial($partial)) {
						$isInThinkingMode = false;
						$thinkingBuffer = '';
						$this->sendReplyToTalk($roomToken, $partial, $replyTo);
						$isFirstMessage = false;
						return;
					}

					// Handle thinking tokens - filter them out and show placeholder
					$filteredPartial = $this->filterThinkingTokens(
						$partial,
						$isInThinkingMode,
						$thinkingBuffer,
						$thinkingMessageSent,
						$roomToken,
						$replyTo,
						$isFirstMessage
					);

					// Only send if there's actual content after filtering thinking tokens
					if ($filteredPartial !== null && trim($filteredPartial) !== '') {
						$replyTo = $this->resolveStreamingReplyTarget($isFirstMessage, $replyTargetId, $threadRootMessageId);
						$this->sendReplyToTalk($roomToken, $filteredPartial, $replyTo);
						if (!$this->isProgressOnlyPartial($filteredPartial)) {
							$alreadySent = true;
						}
						$isFirstMessage = false;
					}
				},
				false,
				$onboardingContext,
				$messageContext,
				$threadRootMessageId,
				$replyTargetId,
				$traceRunId
			);

			$this->logger->info('Got bot response', [
				'response_length' => strlen($response),
				'already_streamed' => $alreadySent,
				'thinking_message_sent' => $thinkingMessageSent,
				'trace_run_id' => $traceRunId,
			]);

				if (!$alreadySent) {
					// Filter thinking tokens from final response as well
					$filteredResponse = $this->stripThinkingTokensFromFinal($response);

					// Check if we have actual content to send
				if (trim($filteredResponse) === '') {
					// The response was only thinking tokens
					if ($thinkingMessageSent) {
						// We sent a thinking placeholder, now send a fallback message
						$fallbackSent = $this->sendReplyToTalk(
							$roomToken,
							'_(The AI finished thinking but produced no response. Please try again.)_',
							$this->resolveStreamingReplyTarget(false, $replyTargetId, $threadRootMessageId)
						);
						if (!$fallbackSent) {
							$traceStatus = 'partial';
						}
						$this->logger->warning('LLM response contained only thinking tokens with no actual output');
					} else {
						// Empty response with no thinking - something went wrong
						if (!$this->sendReplyToTalk($roomToken, '_(No response received from AI. Please try again.)_', $replyTargetId)) {
							$traceStatus = 'partial';
						}
						$this->logger->warning('LLM returned empty response');
					}
				} else {
					// Normal case - send the filtered response
					$result = $this->sendReplyToTalk($roomToken, $filteredResponse, $replyTargetId);

					if ($result) {
						$this->logger->info('Bot response sent successfully to Talk');
					} else {
						$traceStatus = 'partial';
						$this->logger->error('Failed to send bot response to Talk - check Talk API logs');
					}
				}
			} else {
				$this->logger->info('Bot response was streamed to Talk, skipping final reply');
			}

			$this->traceService?->finishRun($traceRunId, $traceStatus);
		} catch (\Throwable $e) {
			$this->traceService?->recordEvent($traceRunId, 'error', [
				'status' => 'error',
				'payload' => [
					'stage' => 'talk_handler',
					'bot_id' => $bot->getId(),
				],
				'error_message' => $e->getMessage(),
			]);
			$this->traceService?->finishRun($traceRunId, 'error', $e->getMessage());
			throw $e;
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function summarizeTraceAttachments(?IncomingTalkMessage $incomingMessage): array {
		if (!$incomingMessage instanceof IncomingTalkMessage) {
			return [];
		}

		return array_map(function (IncomingTalkAttachment $attachment): array {
			$fileRef = $attachment->getFileRef();
			return [
				'filename' => $attachment->getDisplayName(),
				'kind' => $attachment->getKind(),
				'shared_item_type' => $attachment->getSharedItemType(),
				'mime_type' => $attachment->getMimeType(),
				'parameter_key' => $attachment->getParameterKey(),
				'file_id' => $this->firstScalar($fileRef, ['fileId', 'file_id', 'id']),
				'owner_uid' => $this->firstScalar($fileRef, ['ownerUid', 'owner_uid', '_educai_owner_uid']),
				'size' => $this->firstScalar($fileRef, ['size', 'fileSize', 'file_size']),
				'unsupported_reason' => $attachment->getUnsupportedReason(),
			];
		}, $incomingMessage->getAttachments());
	}

	/**
	 * @param array<string,mixed> $values
	 * @param array<int,string> $keys
	 */
	private function firstScalar(array $values, array $keys): ?string {
		foreach ($keys as $key) {
			$value = $values[$key] ?? null;
			if (is_scalar($value) && (string)$value !== '') {
				return (string)$value;
			}
		}

		return null;
	}

	/**
	 * Verify HMAC signature
	 *
	 * @param string $signature
	 * @param string $random
	 * @param string $body
	 * @return bool
	 */
	private function verifySignature(string $signature, string $random, string $body): bool {
		$secret = $this->settingsService->getWebhookSecret();
		
		if (empty($secret)) {
			// Fail closed: without a configured secret the HMAC cannot be verified,
			// so we cannot trust the (public, unauthenticated) webhook payload.
			// The route stays inert until bot registration stores a secret.
			$this->logger->warning('Webhook secret not configured - rejecting webhook request');
			return false;
		}

		if (empty($signature) || empty($random)) {
			$this->logger->warning('Missing signature or random in webhook request');
			return false;
		}

		$expectedSignature = hash_hmac('sha256', $random . $body, $secret);
		
		$isValid = hash_equals($expectedSignature, $signature);
		
		if (!$isValid) {
			$this->logger->warning('Signature mismatch', [
				'expected' => substr($expectedSignature, 0, 20) . '...',
				'received' => substr($signature, 0, 20) . '...',
			]);
		}
		
		return $isValid;
	}

	/**
	 * Detect which bot was mentioned in the message
	 * 
	 * Supports two mention formats:
	 * - @mention_name (traditional Talk mention format)
	 * - /mention_name (Smart Picker format for bot invocation)
	 *
	 * @param string $message
	 * @return Bot|null
	 */
	private function detectBot(string $message): ?Bot {
		// Extract all @mentions and /mentions from the message
		// @mentions: traditional format
		// /mentions: Smart Picker format (used when user selects bot from picker)
		preg_match_all('/[@\/]([a-z0-9_-]+)/i', $message, $matches);
		
		if (empty($matches[0])) {
			return null;
		}

		// Try to find a bot with one of the mentioned names
		foreach ($matches[0] as $mention) {
			// Normalize /mention to @mention for database lookup
			$normalizedMention = $mention;
			if (str_starts_with($mention, '/')) {
				$normalizedMention = '@' . substr($mention, 1);
			}
			
			try {
				$bot = $this->botService->findByMentionName($normalizedMention);
				if ($bot->getIsActive()) {
					return $bot;
				}
			} catch (DoesNotExistException $e) {
				// Continue to next mention
			}
		}

		return null;
	}

	/**
	 * Remove bot mention from message
	 * 
	 * Handles both @mention and /mention formats.
	 *
	 * @param string $message
	 * @param string $mentionName The bot's mention name (stored as @name in DB)
	 * @return string
	 */
	private function removeBotMention(string $message, string $mentionName): string {
		// Remove the @mention format
		$cleaned = str_replace($mentionName, '', $message);
		
		// Also remove /mention format (Smart Picker format)
		// Convert @name to /name for matching
		if (str_starts_with($mentionName, '@')) {
			$slashMention = '/' . substr($mentionName, 1);
			$cleaned = str_replace($slashMention, '', $cleaned);
		}
		
		return trim($cleaned);
	}

	/**
	 * @return array{
	 *   context: MessageContext,
	 *   capability_error:?string
	 * }
	 */
	private function buildMessageContext(
		Bot $bot,
		string $roomToken,
		string $userId,
		int $messageId,
		IncomingTalkMessage $incomingMessage
	): array {
		$attachments = $incomingMessage->getAttachments();
		if ($attachments === []) {
			return [
				'context' => [
					'attachments' => [],
					'document_source_ids' => [],
					'image_source_ids' => [],
					'attachment_only' => false,
				],
				'capability_error' => null,
			];
		}

		$enabledBuiltInTools = $this->botService->getEffectiveBuiltInToolNames($bot, $userId);
		$hasImageTool = in_array('attachment_analyze_image', $enabledBuiltInTools, true);
		$hasRoomImageTool = in_array('room_search_images', $enabledBuiltInTools, true);
		$hasAudioTool = in_array('attachment_transcribe_audio', $enabledBuiltInTools, true);
		$hasRoomDocumentTool = in_array('room_search_documents', $enabledBuiltInTools, true);

		$documentSourceIds = [];
		$imageSourceIds = [];

		foreach ($attachments as $attachment) {
			if (!$attachment->isSupported()) {
				return [
					'context' => [
						'attachments' => $attachments,
						'document_source_ids' => [],
						'image_source_ids' => [],
						'attachment_only' => false,
					],
					'capability_error' => $attachment->getUnsupportedReason() ?? 'This attachment type is not supported yet.',
				];
			}

			if ($attachment->isImage() && !$hasImageTool && !$hasRoomImageTool) {
				return [
					'context' => [
						'attachments' => $attachments,
						'document_source_ids' => [],
						'image_source_ids' => [],
						'attachment_only' => false,
					],
					'capability_error' => 'This bot received an image attachment, but image understanding or room image memory is not enabled for it.',
				];
			}

			if ($attachment->isAudio() && !$hasAudioTool) {
				return [
					'context' => [
						'attachments' => $attachments,
						'document_source_ids' => [],
						'image_source_ids' => [],
						'attachment_only' => false,
					],
					'capability_error' => 'This bot received an audio or voice attachment, but speech transcription is not enabled for it.',
				];
			}

			if ($attachment->isDocument()) {
				if (!$hasRoomDocumentTool) {
					return [
						'context' => [
							'attachments' => $attachments,
							'document_source_ids' => [],
							'image_source_ids' => [],
							'attachment_only' => false,
						],
						'capability_error' => 'This bot received a document attachment, but room document understanding is not enabled for it.',
					];
				}

				try {
					$source = $this->roomDocumentIngestionService->ingestAttachment(
						$bot->getId(),
						$roomToken,
						$userId,
						$messageId,
						$attachment
					);
					$documentSourceIds[] = $source->getId();
				} catch (Exception $e) {
					$this->logger->error('Failed to ingest room document attachment', [
						'bot_id' => $bot->getId(),
						'room_token' => $roomToken,
						'attachment' => $attachment->getDisplayName(),
						'exception' => $e,
					]);

					return [
						'context' => [
							'attachments' => $attachments,
							'document_source_ids' => [],
							'image_source_ids' => [],
							'attachment_only' => false,
						],
						'capability_error' => 'The uploaded document could not be indexed for this bot: ' . $e->getMessage(),
					];
				}
			}

			if ($attachment->isImage() && $hasRoomImageTool) {
				try {
					$source = $this->roomImageIngestionService->ingestAttachment(
						$bot->getId(),
						$roomToken,
						$userId,
						$messageId,
						$attachment
					);
					$imageSourceIds[] = $source->getId();
				} catch (Exception $e) {
					$this->logger->error('Failed to ingest room image attachment', [
						'bot_id' => $bot->getId(),
						'room_token' => $roomToken,
						'attachment' => $attachment->getDisplayName(),
						'exception' => $e,
					]);

					return [
						'context' => [
							'attachments' => $attachments,
							'document_source_ids' => [],
							'image_source_ids' => [],
							'attachment_only' => false,
						],
						'capability_error' => 'The uploaded image could not be indexed for this bot: ' . $e->getMessage(),
					];
				}
			}
		}

		return [
			'context' => [
				'attachments' => $attachments,
				'document_source_ids' => $documentSourceIds,
				'image_source_ids' => $imageSourceIds,
				'attachment_only' => false,
			],
			'capability_error' => null,
		];
	}

	private function buildAttachmentOnlyPrompt(IncomingTalkMessage $incomingMessage): string {
		$hasImage = false;
		$hasAudio = false;
		$hasDocument = false;

		foreach ($incomingMessage->getAttachments() as $attachment) {
			if ($attachment->isImage()) {
				$hasImage = true;
			}
			if ($attachment->isAudio()) {
				$hasAudio = true;
			}
			if ($attachment->isDocument()) {
				$hasDocument = true;
			}
		}

		if ($hasImage && !$hasAudio && !$hasDocument) {
			return 'Please analyze the uploaded image attachment and respond based on what it contains.';
		}
		if ($hasAudio && !$hasImage && !$hasDocument) {
			return 'Please transcribe the uploaded audio attachment and respond based on its spoken content.';
		}
		if ($hasDocument && !$hasImage && !$hasAudio) {
			return 'Please inspect the uploaded document and answer based on its contents.';
		}
		if ($incomingMessage->hasAttachments()) {
			return 'Please analyze the uploaded attachment(s) and answer based on their contents.';
		}

		return 'Hi';
	}

	/**
	 * Send a reply message to Nextcloud Talk
	 *
	 * @param string $roomToken
	 * @param string $message
	 * @param int $replyToId
	 * @return bool
	 */
	public function sendReplyToTalk(string $roomToken, string $message, int $replyToId = 0): bool {
		try {
			// Never send empty messages - Talk API will reject them with 400
			if (trim($message) === '') {
				$this->logger->debug('Skipping empty message - not sending to Talk');
				return true; // Return true to not trigger error handling
			}
			
			$secret = $this->settingsService->getWebhookSecret();
			
			if (empty($secret)) {
				$this->logger->error('Cannot send reply: webhook secret not configured');
				return false;
			}

			// Get Nextcloud base URL
			$baseUrl = $this->urlGenerator->getAbsoluteURL('');
			$endpoint = $baseUrl . 'ocs/v2.php/apps/spreed/api/v1/bot/' . $roomToken . '/message';

			// Prepare request body
			$requestBody = [
				'message' => $message,
			];

			if ($replyToId > 0) {
				$requestBody['replyTo'] = $replyToId;
			}

			// Add unique reference ID
			$random = bin2hex(random_bytes(32));
			$requestBody['referenceId'] = sha1($random);

			// Convert to JSON
			$jsonBody = json_encode($requestBody);

			// Create signature (HMAC of random + message)
			$hash = hash_hmac('sha256', $random . $message, $secret);

			$this->logger->debug('Sending reply to Talk', [
				'endpoint' => $endpoint,
				'room_token' => $roomToken,
				'message_length' => strlen($message),
				'reply_to' => $replyToId,
			]);

			$client = $this->clientService->newClient();
			
			$response = $client->post($endpoint, [
				'headers' => [
					'Content-Type' => 'application/json',
					'OCS-APIRequest' => 'true',
					'X-Nextcloud-Talk-Bot-Random' => $random,
					'X-Nextcloud-Talk-Bot-Signature' => $hash,
				],
				'body' => $jsonBody,
				'timeout' => 10,
			]);

			$statusCode = $response->getStatusCode();
			if ($statusCode >= 200 && $statusCode < 300) {
				$this->logger->info('Successfully sent reply to Talk', [
					'room_token' => $roomToken,
				]);
				return true;
			}

			$this->logger->warning('Unexpected status code from Talk API', [
				'status_code' => $statusCode,
				'response_body_length' => strlen((string)$response->getBody()),
			]);
			return false;

		} catch (Exception $e) {
			$this->logger->error('Failed to send reply to Talk: ' . $e->getMessage(), [
				'exception' => $e,
				'room_token' => $roomToken,
			]);
			return false;
		}
	}

	/**
	 * Filter thinking tokens from streaming partial content
	 * 
	 * Handles <think>...</think> blocks by:
	 * 1. Sending a placeholder message once when thinking starts
	 * 2. Buffering all thinking content (not sending it)
	 * 3. Returning actual content after thinking ends
	 *
	 * @param string $partial The partial content from the stream
	 * @param bool &$isInThinkingMode Whether we are currently inside a <think> block
	 * @param string &$thinkingBuffer Buffer for incomplete think tags
	 * @param bool &$thinkingMessageSent Whether we already sent the "thinking" placeholder
	 * @param string $roomToken The room to send placeholder to
	 * @param int $replyToId Message ID to reply to (0 for no reply)
	 * @param bool &$isFirstMessage Whether this is the first message (for replyTo logic)
	 * @return string|null Filtered content or null if nothing to send
	 */
	private function filterThinkingTokens(
		string $partial,
		bool &$isInThinkingMode,
		string &$thinkingBuffer,
		bool &$thinkingMessageSent,
		string $roomToken,
		int $replyToId,
		bool &$isFirstMessage
	): ?string {
		// Append to buffer for proper tag detection
		$thinkingBuffer .= $partial;
		
		$result = '';
		$pos = 0;
		$len = strlen($thinkingBuffer);
		
		while ($pos < $len) {
			if ($isInThinkingMode) {
				// Look for </think> closing tag
				$closePos = stripos($thinkingBuffer, '</think>', $pos);
				if ($closePos !== false) {
					// Found closing tag - exit thinking mode
					$isInThinkingMode = false;
					$pos = $closePos + 8; // Skip past </think>
				} else {
					// Still in thinking mode, check if we might have partial closing tag
					$remaining = substr($thinkingBuffer, $pos);
					if ($this->mightBePartialTag($remaining, '</think>')) {
						// Keep the remainder in buffer for next iteration
						$thinkingBuffer = $remaining;
						break;
					}
					// Discard thinking content
					$pos = $len;
				}
			} else {
				// Look for <think> opening tag
				$openPos = stripos($thinkingBuffer, '<think>', $pos);
				if ($openPos !== false) {
					// Capture content before <think>
					$beforeThink = substr($thinkingBuffer, $pos, $openPos - $pos);
					$result .= $beforeThink;
					
					// Enter thinking mode
					$isInThinkingMode = true;
					
					// Send thinking placeholder if not already sent
					if (!$thinkingMessageSent) {
						$this->sendReplyToTalk(
							$roomToken,
							'🤔 _(The AI is thinking... this may take a moment)_',
							$replyToId
						);
						$thinkingMessageSent = true;
						$isFirstMessage = false;
					}
					
					$pos = $openPos + 7; // Skip past <think>
				} else {
					// Check for partial opening tag at end
					$remaining = substr($thinkingBuffer, $pos);
					if ($this->mightBePartialTag($remaining, '<think>')) {
						// Keep the remainder in buffer
						$thinkingBuffer = $remaining;
						break;
					}
					// No think tag, use all remaining content
					$result .= substr($thinkingBuffer, $pos);
					$pos = $len;
				}
			}
		}
		
		// Clear buffer if we processed everything
		if ($pos >= $len) {
			$thinkingBuffer = '';
		}
		
		return $result !== '' ? $result : null;
	}

	/**
	 * Check if a string might be a partial tag (incomplete tag at end of buffer)
	 */
	private function mightBePartialTag(string $text, string $fullTag): bool {
		$text = strtolower($text);
		$fullTag = strtolower($fullTag);
		
		// Check if text ends with any prefix of the full tag
		for ($i = 1; $i < strlen($fullTag); $i++) {
			$prefix = substr($fullTag, 0, $i);
			if (str_ends_with($text, $prefix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Strip all thinking tokens from a final response (non-streaming fallback)
	 */
	private function stripThinkingTokensFromFinal(string $content): string {
		// Remove <think>...</think> blocks including content
		$result = preg_replace('/<think>.*?<\/think>/is', '', $content);
		
		// Also handle unclosed <think> tags (thinking was interrupted)
		$result = preg_replace('/<think>.*$/is', '', $result ?? $content);
		
		// Clean up any leftover </think> tags
		$result = str_ireplace('</think>', '', $result ?? $content);
		
		// Trim whitespace
		return trim($result ?? $content);
	}

	private function isProgressOnlyPartial(string $partial): bool {
		$trimmed = trim($partial);

		return str_starts_with($trimmed, '🔧 _Using tool: ')
			|| str_starts_with($trimmed, '🔧 _Using tools: ');
	}
}
