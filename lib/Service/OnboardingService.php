<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\ChatRoom;
use OCA\EducAI\Db\ChatRoomMapper;
use OCA\EducAI\Db\ConversationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Service for managing chat room onboarding flow.
 * Handles room state, response mode selection, and custom question trees.
 *
 * @psalm-import-type OnboardingQuestion from \OCA\EducAI\TypeDefinitions
 */
class OnboardingService {
	private ChatRoomMapper $chatRoomMapper;
	private BotMapper $botMapper;
	private ConversationMapper $conversationMapper;
	private LoggerInterface $logger;

	public function __construct(
		ChatRoomMapper $chatRoomMapper,
		BotMapper $botMapper,
		ConversationMapper $conversationMapper,
		LoggerInterface $logger
	) {
		$this->chatRoomMapper = $chatRoomMapper;
		$this->botMapper = $botMapper;
		$this->conversationMapper = $conversationMapper;
		$this->logger = $logger;
	}

	/**
	 * Get the current room state for a bot in a room.
	 *
	 * @param int $botId
	 * @param string $roomToken
	 * @return ChatRoom|null
	 */
	public function getRoomState(int $botId, string $roomToken): ?ChatRoom {
		return $this->chatRoomMapper->findByBotAndRoom($botId, $roomToken);
	}

	/**
	 * Initialize a new room with onboarding state.
	 *
	 * @param int $botId
	 * @param string $roomToken
	 * @param string $userId
	 * @return ChatRoom
	 */
	public function initializeRoom(int $botId, string $roomToken, string $userId): ChatRoom {
		$now = time();

		$this->deactivateOtherAlwaysModeBots($roomToken, $botId);

		$room = new ChatRoom();
		$room->setBotId($botId);
		$room->setRoomToken($roomToken);
		$room->setActivatedBy($userId);
		$room->setOnboardingStatus('mode_selection');
		$room->setCreatedAt($now);
		$room->setUpdatedAt($now);

		return $this->chatRoomMapper->insert($room);
	}

	/**
	 * Get the welcome message for starting onboarding.
	 *
	 * @param Bot $bot
	 * @return string
	 */
	public function getWelcomeMessage(Bot $bot): string {
		$mentionName = $bot->getMentionName();

		return "You have activated the bot **{$mentionName}**.\n\n" .
			"Would you like me to respond to:\n" .
			"- **A**: Only when you @mention me\n" .
			"- **B**: Every message in this chat\n\n" .
			"Reply with **A** or **B**.\n\n" .
			"_(You can reset this chat anytime with `((RESET))`)_";
	}

	/**
	 * Handle a user's response during onboarding.
	 * Returns the next message to send, or null if onboarding is complete.
	 *
	 * @param ChatRoom $room
	 * @param Bot $bot
	 * @param string $message
	 * @return array{message:?string,completed:bool}
	 */
	public function handleOnboardingResponse(ChatRoom $room, Bot $bot, string $message): array {
		$status = $room->getOnboardingStatus();
		$message = trim($message);
		$upperMessage = strtoupper($message);

		$this->logger->debug('Handling onboarding response', [
			'status' => $status,
			'message' => $message,
			'bot_id' => $bot->getId(),
		]);

		if ($status === 'mode_selection') {
			return $this->handleModeSelection($room, $bot, $upperMessage);
		}

		if ($status === 'questions') {
			// For question flow we may need the raw user input (free-text answers),
			// so we pass the trimmed message (not uppercased).
			return $this->handleQuestionResponse($room, $bot, $message);
		}

		// Already completed or unknown status
		return ['message' => null, 'completed' => true];
	}

	/**
	 * Handle the mode selection phase (A = mention, B = always).
	 */
	private function handleModeSelection(ChatRoom $room, Bot $bot, string $answer): array {
		$validAnswers = ['A', 'B'];

		if (!in_array($answer, $validAnswers, true)) {
			return [
				'message' => "Please reply with **A** (only when @mentioned) or **B** (every message).",
				'completed' => false,
			];
		}

		$mode = $answer === 'A' ? 'mention' : 'always';
		$modeDescription = $answer === 'A'
			? 'only when you @mention me'
			: 'every message in this chat';

		$room->setResponseMode($mode);
		$room->setUpdatedAt(time());

		if ($mode === 'always') {
			$this->deactivateOtherAlwaysModeBots($room->getRoomToken(), $room->getBotId());
		}

		// Check if bot has custom onboarding questions
		if ($bot->hasOnboardingQuestions()) {
			$startQuestion = $bot->getStartingOnboardingQuestion();
			if ($startQuestion !== null) {
				$room->setOnboardingStatus('questions');
				$room->setCurrentQuestionId($startQuestion['id']);
				$this->chatRoomMapper->update($room);

				$questionMessage = $this->formatQuestion($startQuestion);

				return [
					'message' => "Got it! I'll respond to {$modeDescription}.\n\n" .
						"Before we start, I have a few questions to better assist you:\n\n" .
						$questionMessage,
					'completed' => false,
				];
			}
		}

		// No custom questions - complete onboarding
		$room->setOnboardingStatus('completed');
		$this->chatRoomMapper->update($room);

		return [
			'message' => "Got it! I'll respond to {$modeDescription}.\n\n" .
				"I'm ready to help! You can reset this conversation anytime with `((RESET))`.",
			'completed' => true,
		];
	}

	/**
	 * Handle a response to a custom onboarding question.
	 */
	private function handleQuestionResponse(ChatRoom $room, Bot $bot, string $message): array {
		$rawMessage = trim($message);
		$upperMessage = strtoupper($rawMessage);

		$currentQuestionId = $room->getCurrentQuestionId();
		if ($currentQuestionId === null) {
			// No current question - complete onboarding
			$room->setOnboardingStatus('completed');
			$room->setCurrentQuestionId(null);
			$this->chatRoomMapper->update($room);

			return [
				'message' => $this->getOnboardingCompleteMessage(),
				'completed' => true,
			];
		}

		$question = $bot->getOnboardingQuestion($currentQuestionId);
		if ($question === null) {
			// Question not found - complete onboarding
			$this->logger->warning('Onboarding question not found', [
				'question_id' => $currentQuestionId,
				'bot_id' => $bot->getId(),
			]);
			$room->setOnboardingStatus('completed');
			$room->setCurrentQuestionId(null);
			$this->chatRoomMapper->update($room);

			return [
				'message' => $this->getOnboardingCompleteMessage(),
				'completed' => true,
			];
		}

		$answers = is_array($question['answers'] ?? null) ? $question['answers'] : [];

		// Split answers into fixed-choice and free-text
		$choiceAnswers = [];
		$freeTextAnswer = null;
		foreach ($answers as $answerOption) {
			$type = strtolower((string)($answerOption['type'] ?? 'choice'));
			if ($type === 'free_text') {
				$freeTextAnswer = $answerOption;
				continue;
			}
			$choiceAnswers[] = $answerOption;
		}

		// If this question expects free-text only, accept any non-empty input
		$selectedAnswer = null;
		$recordedAnswerText = null;

		if (count($choiceAnswers) === 0 && $freeTextAnswer !== null) {
			if ($rawMessage === '') {
				return [
					'message' => 'Please reply with your answer in free text.',
					'completed' => false,
				];
			}
			$selectedAnswer = $freeTextAnswer;
			$recordedAnswerText = $rawMessage;
		} else {
			// Try to match a fixed-choice answer (A/B/C/…)
			foreach ($choiceAnswers as $answerOption) {
				if (strtoupper((string)$answerOption['id']) === $upperMessage) {
					$selectedAnswer = $answerOption;
					$recordedAnswerText = (string)($answerOption['text'] ?? '');
					break;
				}
			}

			// If not matched and free-text is allowed, treat the user's message as the answer
			if ($selectedAnswer === null && $freeTextAnswer !== null) {
				if ($rawMessage === '') {
					$options = array_map(fn($a) => strtoupper((string)$a['id']), $choiceAnswers);
					$optionsStr = implode(' or ', array_map(fn($o) => "**{$o}**", $options));
					$hint = $optionsStr !== '' ? "Please reply with {$optionsStr} or write your answer in free text." : 'Please reply with your answer in free text.';
					return [
						'message' => $hint,
						'completed' => false,
					];
				}
				$selectedAnswer = $freeTextAnswer;
				$recordedAnswerText = $rawMessage;
			}

			if ($selectedAnswer === null) {
				// Invalid answer - show options again
				$options = array_map(fn($a) => strtoupper((string)$a['id']), $choiceAnswers);
				$optionsStr = implode(' or ', array_map(fn($o) => "**{$o}**", $options));

				return [
					'message' => $optionsStr !== '' ? "Please reply with {$optionsStr}." : 'Please reply with a valid answer.',
					'completed' => false,
				];
			}
		}

		// Record the answer (for free-text questions, store the user's input)
		$room->addOnboardingAnswer(
			(string)$question['id'],
			(string)$question['text'],
			(string)($selectedAnswer['id'] ?? 'free_text'),
			(string)($recordedAnswerText ?? '')
		);

		// Check for next question
		$nextQuestionId = $selectedAnswer['next'] ?? null;
		if ($nextQuestionId === null) {
			// End of this path - complete onboarding
			$room->setOnboardingStatus('completed');
			$room->setCurrentQuestionId(null);
			$this->chatRoomMapper->update($room);

			return [
				'message' => $this->getOnboardingCompleteMessage(),
				'completed' => true,
			];
		}

		// Move to next question
		$nextQuestion = $bot->getOnboardingQuestion($nextQuestionId);
		if ($nextQuestion === null) {
			// Next question not found - complete onboarding
			$this->logger->warning('Next onboarding question not found', [
				'next_question_id' => $nextQuestionId,
				'bot_id' => $bot->getId(),
			]);
			$room->setOnboardingStatus('completed');
			$room->setCurrentQuestionId(null);
			$this->chatRoomMapper->update($room);

			return [
				'message' => $this->getOnboardingCompleteMessage(),
				'completed' => true,
			];
		}

		$room->setCurrentQuestionId($nextQuestionId);
		$this->chatRoomMapper->update($room);

		return [
			'message' => $this->formatQuestion($nextQuestion),
			'completed' => false,
		];
	}

	/**
	 * Format a question for display.
	 *
	 * @param OnboardingQuestion $question
	 * @return string
	 */
	private function formatQuestion(array $question): string {
		$text = (string)$question['text'] . "\n";
		$answers = is_array($question['answers'] ?? null) ? $question['answers'] : [];

		$choiceAnswers = [];
		$hasFreeText = false;
		foreach ($answers as $answer) {
			$type = strtolower((string)($answer['type'] ?? 'choice'));
			if ($type === 'free_text') {
				$hasFreeText = true;
				continue;
			}
			$choiceAnswers[] = $answer;
		}

		// Free-text only question
		if ($hasFreeText && count($choiceAnswers) === 0) {
			$text .= "\nReply with your answer in free text.";
			return $text;
		}

		// Render fixed-choice answers
		foreach ($choiceAnswers as $answer) {
			$id = strtoupper((string)$answer['id']);
			$answerText = (string)($answer['text'] ?? '');
			$text .= "- **{$id}**: {$answerText}\n";
		}

		$options = array_map(fn($a) => strtoupper((string)$a['id']), $choiceAnswers);
		$optionsStr = implode(' or ', $options);
		if ($optionsStr !== '' && $hasFreeText) {
			$text .= "\nReply with {$optionsStr} or write your answer in free text.";
		} elseif ($optionsStr !== '') {
			$text .= "\nReply with {$optionsStr}.";
		} elseif ($hasFreeText) {
			$text .= "\nReply with your answer in free text.";
		} else {
			$text .= "\nReply with your answer.";
		}

		return $text;
	}

	/**
	 * Get the completion message.
	 */
	private function getOnboardingCompleteMessage(): string {
		return "Thanks! I've noted your preferences. I'm ready to help!\n\n" .
			"_(Reminder: You can reset this chat with `((RESET))`)_";
	}

	/**
	 * Build system prompt context from onboarding answers.
	 *
	 * @param ChatRoom $room
	 * @return string
	 */
	public function buildOnboardingContext(ChatRoom $room): string {
		$answers = $room->getOnboardingAnswersArray();
		if (count($answers) === 0) {
			return '';
		}

		$context = "\n\n## User Onboarding Context\n";
		$context .= "The user has provided the following information during onboarding:\n";

		foreach ($answers as $answer) {
			$context .= "- **{$answer['question_text']}** → {$answer['answer_text']}\n";
		}

		$context .= "\nUse this context to personalize your responses.\n";

		return $context;
	}

	/**
	 * Reset a room's onboarding state and conversation history.
	 *
	 * @param int $botId
	 * @param string $roomToken
	 * @return bool True if reset was successful
	 */
	public function resetRoom(int $botId, string $roomToken): bool {
		try {
			// Delete room state
			$this->chatRoomMapper->deleteByBotAndRoom($botId, $roomToken);

			// Delete conversation history for this bot in this room
			$this->conversationMapper->deleteByBotAndRoom($botId, $roomToken);

			$this->logger->info('Room state reset', [
				'bot_id' => $botId,
				'room_token' => $roomToken,
			]);

			return true;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to reset room state', [
				'bot_id' => $botId,
				'room_token' => $roomToken,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Get all rooms in "always" mode for a given room token.
	 * Used to find bots that should respond to any message.
	 *
	 * @param string $roomToken
	 * @return array<int,array{room:ChatRoom,bot:Bot}>
	 */
	public function getAlwaysModeBotsForRoom(string $roomToken): array {
		$rooms = $this->chatRoomMapper->findAlwaysModeByRoom($roomToken);
		return $this->resolveActiveBotsForRooms($rooms);
	}

	/**
	 * Ensure only one bot remains in always mode for a given room.
	 */
	private function deactivateOtherAlwaysModeBots(string $roomToken, int $activeBotId): void {
		$alwaysModeRooms = $this->chatRoomMapper->findAlwaysModeByRoom($roomToken);
		$deactivatedBotIds = [];

		foreach ($alwaysModeRooms as $room) {
			if ($room->getBotId() === $activeBotId) {
				continue;
			}

			$room->setResponseMode('mention');
			$room->setUpdatedAt(time());
			$this->chatRoomMapper->update($room);
			$deactivatedBotIds[] = $room->getBotId();
		}

		if ($deactivatedBotIds !== []) {
			$this->logger->info('Deactivated existing always-mode bots in room', [
				'room_token' => $roomToken,
				'active_bot_id' => $activeBotId,
				'deactivated_bot_ids' => $deactivatedBotIds,
			]);
		}
	}

	/**
	 * Get all bots with onboarding in progress for a given room token.
	 * Used to detect onboarding responses without mentions.
	 *
	 * @param string $roomToken
	 * @return array<int,array{room:ChatRoom,bot:Bot}>
	 */
	public function getOnboardingInProgressBotsForRoom(string $roomToken): array {
		$rooms = $this->chatRoomMapper->findOnboardingInProgressByRoom($roomToken);
		return $this->resolveActiveBotsForRooms($rooms);
	}

	/**
	 * Get all bots with ANY state (any onboarding status) for a given room token.
	 * Used for reset command to find all bots to reset.
	 *
	 * @param string $roomToken
	 * @return array<int,array{room:ChatRoom,bot:Bot}>
	 */
	public function getAllBotsWithStateForRoom(string $roomToken): array {
		$rooms = $this->chatRoomMapper->findAllByRoom($roomToken);
		return $this->resolveActiveBotsForRooms($rooms);
	}

	/**
	 * @param ChatRoom[] $rooms
	 * @return array<int,array{room:ChatRoom,bot:Bot}>
	 */
	private function resolveActiveBotsForRooms(array $rooms): array {
		$result = [];

		foreach ($rooms as $room) {
			try {
				$bot = $this->botMapper->findById($room->getBotId());
				if ($bot->getIsActive()) {
					$result[] = [
						'room' => $room,
						'bot' => $bot,
					];
				}
			} catch (DoesNotExistException $e) {
				// Bot was deleted - clean up orphaned room state
				$this->chatRoomMapper->delete($room);
			}
		}

		return $result;
	}

	/**
	 * Check if onboarding is in progress for a bot in a room.
	 *
	 * @param int $botId
	 * @param string $roomToken
	 * @return bool
	 */
	public function isOnboardingInProgress(int $botId, string $roomToken): bool {
		$room = $this->getRoomState($botId, $roomToken);
		if ($room === null) {
			return false;
		}
		return !$room->isOnboardingComplete();
	}
}
