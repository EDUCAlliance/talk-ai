<?php

declare(strict_types=1);

namespace OCA\EducAI\Listener;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Jobs\RebuildWikiRootRegistryJob;
use OCP\App\Events\AppEnableEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<Event>
 */
class WikiRegistryRebuildListener implements IEventListener {
	private IJobList $jobList;

	public function __construct(IJobList $jobList) {
		$this->jobList = $jobList;
	}

	public function handle(Event $event): void {
		if (!$event instanceof AppEnableEvent || $event->getAppId() !== Application::APP_ID) {
			return;
		}

		$this->enqueue('app_enable');
	}

	public function enqueue(string $reason): void {
		$arguments = ['reason' => $reason];
		if (!$this->jobList->has(RebuildWikiRootRegistryJob::class, $arguments)) {
			$this->jobList->scheduleAfter(RebuildWikiRootRegistryJob::class, time() + 5, $arguments);
		}
	}
}
