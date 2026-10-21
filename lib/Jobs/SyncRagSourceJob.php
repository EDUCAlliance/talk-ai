<?php

declare(strict_types=1);

namespace OCA\EducAI\Jobs;

use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Service\RagIngestionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** File events have their own follow-up job, separate from provider retry chains. */
class SyncRagSourceJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private BotSourceMapper $sources,
		private RagIngestionService $ingestion,
		private IJobList $jobs,
		private ?ILockingProvider $locking = null,
	) {
		parent::__construct($time);
	}

	/** Serialize JobList's non-atomic has/add pair across concurrent file events. */
	public static function schedule(IJobList $jobs, ?ILockingProvider $locking, int $sourceId, int $delay = 5): void {
		$lock = 'educai:rag-sync-queue:' . $sourceId;
		$args = ['sourceId' => $sourceId];
		$acquired = false;
		foreach ([0, 1000, 5000, 10000] as $pause) {
			if ($pause > 0) {
				usleep($pause);
			}
			try {
				$locking?->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
				$acquired = true;
				break;
			} catch (LockedException) {
				// A job may start between two writes. Never assume another
				// scheduler's earlier check also covers this later file change.
			}
		}
		if (!$acquired) {
			// Prefer an extra serialized, checksum-checked pass to a lost write.
			$jobs->scheduleAfter(self::class, time() + $delay, $args);
			return;
		}
		try {
			if (!$jobs->has(self::class, $args)) {
				$jobs->scheduleAfter(self::class, time() + $delay, $args);
			}
		} finally {
			$locking?->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function run($arguments): void {
		$sourceId = $arguments['sourceId'] ?? null;
		if (!is_int($sourceId) || $sourceId <= 0) {
			return;
		}
		try {
			$source = $this->sources->findById($sourceId);
			if ($source->getNodeType() === 'url') {
				return;
			}
			if ($this->ingestion->isBusy($source)) {
				// Keep the change until the active run/retry finishes. Never bypass a
				// provider's Retry-After or reset a running source's progress from a hook.
				self::schedule($this->jobs, $this->locking, $sourceId, 30);
				return;
			}
			$this->ingestion->ingestSourceById($sourceId, false, 0, true);
		} catch (DoesNotExistException) {
			// The user removed the knowledge source before its follow-up ran.
		}
	}
}
