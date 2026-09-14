<?php

declare(strict_types=1);

namespace OCA\EducAI\Migration;

use Closure;
use OCA\EducAI\Service\BrandingService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Pin storage roots before the old installed_version is replaced by 2.41.0. */
class Version024100Date20260914000000 extends SimpleMigrationStep {
	public function __construct(private BrandingService $brandingService) {}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->brandingService->initializeDefaults();
	}
}
