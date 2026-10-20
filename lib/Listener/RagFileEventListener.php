<?php

declare(strict_types=1);

namespace OCA\EducAI\Listener;

use OCA\EducAI\Service\RagFileEventSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Throwable;

/** @template-implements IEventListener<Event> */
class RagFileEventListener implements IEventListener {
	/** @var array<string,list<int>> */
	private array $before = [];
	private bool $handling = false;

	public function __construct(private RagFileEventSyncService $sync) {
	}

	public function handle(Event $event): void {
		if ($this->handling) {
			return;
		}
		$this->handling = true;
		try {
			if ($event instanceof BeforeNodeDeletedEvent) {
				$this->before['delete:' . $event->getNode()->getPath()] = $this->sync->affectedSourceIds([$event->getNode()], true);
			} elseif ($event instanceof BeforeNodeRenamedEvent) {
				$this->before['rename:' . $event->getSource()->getPath()] = $this->sync->affectedSourceIds([$event->getSource(), $event->getTarget()], true);
			} elseif ($event instanceof NodeDeletedEvent || $event instanceof NodeRenamedEvent) {
				$nodes = $event instanceof NodeDeletedEvent ? [$event->getNode()] : [$event->getSource(), $event->getTarget()];
				$key = ($event instanceof NodeDeletedEvent ? 'delete:' : 'rename:') . $nodes[0]->getPath();
				$ids = $this->before[$key] ?? [];
				unset($this->before[$key]);
				$this->sync->schedule(array_merge($ids, $this->sync->affectedSourceIds($nodes)));
			} elseif ($event instanceof NodeCopiedEvent) {
				// Copying does not modify the original source.
				$this->sync->schedule($this->sync->affectedSourceIds([$event->getTarget()]));
			} elseif ($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent) {
				$this->sync->schedule($this->sync->affectedSourceIds([$event->getNode()]));
			}
		} catch (Throwable) {
			// Do not turn unavailable event metadata into a failed file operation.
		} finally {
			$this->handling = false;
		}
	}
}
