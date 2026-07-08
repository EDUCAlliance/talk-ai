<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use OCA\EducAI\Service\TalkBotRegistrationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RegisterTalkBotRepairStep implements IRepairStep {
	private TalkBotRegistrationService $talkBotRegistrationService;

	public function __construct(TalkBotRegistrationService $talkBotRegistrationService) {
		$this->talkBotRegistrationService = $talkBotRegistrationService;
	}

	public function getName(): string {
		return 'Register ' . \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME . ' bot with Nextcloud Talk';
	}

	public function run(IOutput $output): void {
		$result = $this->talkBotRegistrationService->syncRegistration(null, false, true);
		$status = $result['status'] ?? 'unknown';
		$message = $result['message'] ?? 'Talk bot registration finished without details.';

		if ($status === 'error') {
			$output->warning($message);
			return;
		}

		$output->info($message);
	}
}
