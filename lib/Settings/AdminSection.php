<?php

declare(strict_types=1);

namespace OCA\EducAI\Settings;

use OCA\EducAI\Service\AppIconService;
use OCA\EducAI\Service\BrandingService;
use OCP\IL10N;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l10n,
		private AppIconService $appIconService,
		private BrandingService $brandingService,
	) {}

	public function getID(): string { return 'educai'; }
	public function getName(): string { return $this->brandingService->getDisplayName(); }
	public function getPriority(): int { return 50; }
	public function getIcon(): string {
		return $this->appIconService->getSettingsSectionIcon();
	}
}

