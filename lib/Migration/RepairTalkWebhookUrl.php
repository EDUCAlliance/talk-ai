<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use OCA\EducAI\Service\TalkBotRegistrationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RepairTalkWebhookUrl implements IRepairStep {
	public function __construct(
		private TalkBotRegistrationService $talkBotRegistrationService,
	) {
	}

	public function getName(): string {
		return 'Repair managed Talk bot webhook URL';
	}

	public function run(IOutput $output): void {
		// Repair only the known invalid URL; do not re-register or rotate credentials.
		$result = $this->talkBotRegistrationService->repairInvalidWebhookUrl();
		$message = $result['message'] ?? 'Talk bot webhook URL repair finished without details.';
		if (($result['status'] ?? 'unknown') === 'error') {
			$output->warning($message);
			return;
		}

		$output->info($message);
	}
}
