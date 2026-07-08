<?php

declare(strict_types=1);

namespace OCA\EducAI\Listener;

use OCA\EducAI\AppInfo\Application;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Event listener that loads the bot picker JavaScript when the Smart Picker is rendered.
 * 
 * The JavaScript will conditionally register the custom picker component only in Talk,
 * ensuring the bot provider doesn't appear in other apps like Text, Mail, or Deck.
 * 
 * @template-implements IEventListener<RenderReferenceEvent>
 */
class BotPickerListener implements IEventListener {

	/**
	 * @inheritDoc
	 */
	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}

		// Load the bot picker script which will:
		// 1. Check if we're in Talk context
		// 2. Only register the custom picker component if we are
		Util::addScript(Application::APP_ID, 'educai-bot-picker');
		// Note: Vite outputs as {appId}-{entryName}.mjs, so 'bot-picker' entry becomes 'educai-bot-picker.mjs'
	}
}

