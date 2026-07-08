<?php

declare(strict_types=1);

namespace OCA\EducAI\Listener;

use OCA\EducAI\Service\TalkBotRegistrationService;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<AppEnableEvent>
 */
class TalkEnabledListener implements IEventListener {
	private TalkBotRegistrationService $talkBotRegistrationService;

	public function __construct(TalkBotRegistrationService $talkBotRegistrationService) {
		$this->talkBotRegistrationService = $talkBotRegistrationService;
	}

	public function handle(Event $event): void {
		if (!$event instanceof AppEnableEvent || $event->getAppId() !== TalkBotRegistrationService::TALK_APP_ID) {
			return;
		}

		$this->talkBotRegistrationService->syncRegistration();
	}
}
