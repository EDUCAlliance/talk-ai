<?php

declare(strict_types=1);

namespace OCA\EducAI\ToolProvider;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the optional course-catalogue provider. Availability and execution
 * are gated by the saved integration setting; disabling it keeps bot loadouts.
 *
 * @template-implements IEventListener<CollectToolProvidersEvent>
 */
class CatalogueToolProviderListener implements IEventListener {
	private CatalogueToolProvider $catalogueToolProvider;

	public function __construct(CatalogueToolProvider $catalogueToolProvider) {
		$this->catalogueToolProvider = $catalogueToolProvider;
	}

	public function handle(Event $event): void {
		if (!$event instanceof CollectToolProvidersEvent) {
			return;
		}
		$event->registerProvider($this->catalogueToolProvider);
	}
}
