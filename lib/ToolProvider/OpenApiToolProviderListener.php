<?php

declare(strict_types=1);

namespace OCA\EducAI\ToolProvider;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers tools of approved OpenAPI connections.
 *
 * @template-implements IEventListener<CollectToolProvidersEvent>
 */
class OpenApiToolProviderListener implements IEventListener {
	public function __construct(
		private OpenApiToolProvider $provider,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof CollectToolProvidersEvent) {
			$event->registerProvider($this->provider);
		}
	}
}
