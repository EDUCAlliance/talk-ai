<?php

declare(strict_types=1);

namespace OCA\EducAI\Reference;

use OCA\EducAI\Service\AppIconService;
use OCA\EducAI\Service\BotService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Smart Picker reference provider for EducAI bots.
 * 
 * This provider enables users to invoke bots via the Smart Picker (triggered by typing)
 * in Nextcloud Talk. The actual picker component is registered via JavaScript
 * and handles the bot selection UI.
 * 
 * The custom picker component is registered in JavaScript via registerCustomPickerElement()
 * with the same ID as this provider's getId() method returns.
 */
class BotReferenceProvider extends ADiscoverableReferenceProvider {

	public function __construct(
		private BotService $botService,
		private AppIconService $appIconService,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ?string $userId
	) {
		$this->logger->debug('[EducAI] BotReferenceProvider constructed', [
			'userId' => $this->userId,
		]);
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'educai-bots';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME;
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		// Show after common providers like files (0), but before less common ones
		return 15;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconUrl(): string {
		return $this->appIconService->getReferenceIconUrl();
	}

	/**
	 * @inheritDoc
	 * 
	 * Match references that look like bot invocations.
	 * We match @BotMentionName patterns to trigger bot interactions.
	 */
	public function matchReference(string $referenceText): bool {
		// Match patterns like @mybotname or @my-bot_name
		// This is a lightweight check - actual bot validation happens in resolveReference
		$matches = preg_match('/^@[a-z0-9_-]+$/i', trim($referenceText)) === 1;
		$this->logger->debug('[EducAI] BotReferenceProvider::matchReference', [
			'referenceText' => $referenceText,
			'matches' => $matches,
		]);
		return $matches;
	}

	/**
	 * @inheritDoc
	 * 
	 * Resolve the reference to provide link preview data.
	 * For bot references, we return minimal info since this is primarily
	 * for inserting text, not creating link previews.
	 */
	public function resolveReference(string $referenceText): ?Reference {
		$this->logger->debug('[EducAI] BotReferenceProvider::resolveReference', [
			'referenceText' => $referenceText,
		]);

		if (!$this->matchReference($referenceText)) {
			return null;
		}

		// Extract the mention name from @botname format
		$mentionName = substr(trim($referenceText), 1); // Remove leading @
		
		// Try to find the bot
		try {
			$bot = $this->botService->findByMentionName('@' . $mentionName);
			
			// Check if user can access this bot
			if ($this->userId !== null && !$this->botService->userCanAccessBot($bot, $this->userId)) {
				$this->logger->debug('[EducAI] User cannot access bot', [
					'userId' => $this->userId,
					'botId' => $bot->getId(),
				]);
				return null;
			}

			$reference = new Reference($referenceText);
			$reference->setTitle($bot->getBotName());
			$reference->setDescription($bot->getDescription() ?? $this->l10n->t('AI Bot'));
			$reference->setImageUrl($this->getIconUrl());
			$reference->setRichObject(
				'educai-bot',
				[
					'id' => $bot->getId(),
					'name' => $bot->getBotName(),
					'mention_name' => $bot->getMentionName(),
					'description' => $bot->getDescription(),
				]
			);

			$this->logger->debug('[EducAI] Resolved bot reference', [
				'botId' => $bot->getId(),
				'botName' => $bot->getBotName(),
			]);

			return $reference;
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$this->logger->debug('[EducAI] Bot not found for mention name', [
				'mentionName' => $mentionName,
			]);
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCachePrefix(string $referenceId): string {
		return $this->userId ?? '';
	}

	/**
	 * @inheritDoc
	 */
	public function getCacheKey(string $referenceId): ?string {
		return $referenceId;
	}
}
