<?php

declare(strict_types=1);

namespace OCA\EducAI\Settings;

use OCA\EducAI\Service\AppIconService;
use OCP\IL10N;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
	public function __construct(
		private IL10N $l10n,
		private AppIconService $appIconService,
	) {}

	public function getID(): string { return 'educai'; }
	public function getName(): string { return \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME; }
	public function getPriority(): int { return 50; }
	public function getIcon(): string {
		return $this->appIconService->getSettingsSectionIcon();
	}
}

