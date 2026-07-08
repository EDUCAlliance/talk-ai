<?php

declare(strict_types=1);

namespace OCA\EducAI\Listener;

use OCA\EducAI\Service\WikiFileEventSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\AbstractNodesEvent;

/**
 * @template-implements IEventListener<Event>
 */
class WikiFileEventListener implements IEventListener {
	private WikiFileEventSyncService $syncService;

	public function __construct(WikiFileEventSyncService $syncService) {
		$this->syncService = $syncService;
	}

	public function handle(Event $event): void {
		$nodes = [];
		if ($event instanceof AbstractNodeEvent) {
			$nodes[] = $event->getNode();
		} elseif ($event instanceof AbstractNodesEvent) {
			$nodes[] = $event->getSource();
			$nodes[] = $event->getTarget();
		}

		$this->syncService->scheduleForChangedNodes($nodes);
	}
}
