<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Exception\AssistantExistsException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

/**
 * The personal assistant of a user: one personal bot that the app page pins in first place.
 *
 * The assistant is an ordinary personal bot. Which bot it is, is remembered per user; the bot
 * itself carries no mark. A remembered bot only counts while it still belongs to the user and
 * is personal.
 */
class AssistantService {
	private const APP_ID = 'educai';
	private const CONFIG_KEY = 'assistant_bot_id';
	private const MENTION_PREFIX = 'assistant';
	// The mention column holds 100 characters including the leading @.
	private const MENTION_MAX_LENGTH = 99;

	public function __construct(
		private BotService $botService,
		private IConfig $config,
	) {
	}

	/**
	 * The assistant of this user, if there is one.
	 */
	public function findAssistant(string $userId): ?Bot {
		$id = (int)$this->config->getUserValue($userId, self::APP_ID, self::CONFIG_KEY, '0');
		if ($id <= 0) {
			return null;
		}
		try {
			$bot = $this->botService->getBot($id);
		} catch (DoesNotExistException $e) {
			return null;
		}

		return $bot->getUserId() === $userId && $bot->getVisibility() === 'personal' ? $bot : null;
	}

	public function getAssistantId(string $userId): ?int {
		return $this->findAssistant($userId)?->getId();
	}

	/**
	 * Create the assistant of this user as a personal bot. A user has at most one.
	 *
	 * Mention names are unique across all bots, so the assistant gets a mention of its own
	 * that is derived from the user id.
	 *
	 * @param array<int,mixed>|null $tools
	 * @param array<string,mixed>|null $onboardingQuestions
	 * @throws AssistantExistsException
	 * @throws Exception
	 */
	public function createAssistant(
		string $userId,
		string $botName,
		string $systemPrompt,
		?string $model = null,
		mixed $temperature = null,
		mixed $ragEnabled = null,
		?array $tools = null,
		?string $description = null,
		?array $onboardingQuestions = null,
		mixed $allowGuests = null,
	): Bot {
		if ($this->findAssistant($userId) !== null) {
			throw new AssistantExistsException();
		}

		$bot = $this->botService->createBot(
			$userId,
			$botName,
			$this->freeMentionName($userId),
			$systemPrompt,
			false,
			$model,
			$temperature,
			'personal',
			null,
			null,
			$ragEnabled,
			$tools,
			$description,
			$onboardingQuestions,
			$allowGuests
		);
		$this->config->setUserValue($userId, self::APP_ID, self::CONFIG_KEY, (string)$bot->getId());

		return $bot;
	}

	/**
	 * Forget the assistant when its bot is deleted, so that a later bot is never taken for it.
	 */
	public function forget(string $userId, int $botId): void {
		$id = (int)$this->config->getUserValue($userId, self::APP_ID, self::CONFIG_KEY, '0');
		if ($id === $botId) {
			$this->config->deleteUserValue($userId, self::APP_ID, self::CONFIG_KEY);
		}
	}

	/**
	 * Mark the assistant in a list of bot payloads.
	 *
	 * @param array<int,array<string,mixed>> $bots
	 * @return array<int,array<string,mixed>>
	 */
	public function markAssistant(string $userId, array $bots): array {
		$assistantId = $this->getAssistantId($userId);
		foreach ($bots as &$bot) {
			$bot['is_assistant'] = $assistantId !== null && (int)($bot['id'] ?? 0) === $assistantId;
		}
		unset($bot);

		return $bots;
	}

	/**
	 * "@assistant-<user>", with a counter when another bot already uses that name.
	 */
	private function freeMentionName(string $userId): string {
		$base = self::MENTION_PREFIX . '-' . $this->slug($userId);
		for ($attempt = 1; $attempt <= 50; $attempt++) {
			$suffix = $attempt === 1 ? '' : '-' . $attempt;
			$candidate = substr($base, 0, self::MENTION_MAX_LENGTH - strlen($suffix)) . $suffix;
			try {
				$this->botService->findByMentionName('@' . $candidate);
			} catch (DoesNotExistException $e) {
				return $candidate;
			}
		}

		throw new Exception('No free mention name for the personal assistant');
	}

	/**
	 * A user id in the characters a mention name allows. Ids that leave nothing usable get a hash.
	 */
	private function slug(string $userId): string {
		$slug = trim((string)preg_replace('/[^a-z0-9_]+/', '-', strtolower($userId)), '-');
		if ($slug === '') {
			$slug = substr(sha1($userId), 0, 12);
		}

		return substr($slug, 0, 60);
	}
}
