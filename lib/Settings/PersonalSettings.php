<?php

declare(strict_types=1);

namespace OCA\EducAI\Settings;

use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;

class PersonalSettings implements ISettings {
	public function getSection(): string { return 'educai'; }
	public function getPriority(): int { return 10; }
	public function getForm(): TemplateResponse {
		return new TemplateResponse('educai', 'personal', []);
	}
}


