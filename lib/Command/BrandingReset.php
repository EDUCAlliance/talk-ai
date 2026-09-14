<?php

declare(strict_types=1);

namespace OCA\EducAI\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BrandingReset extends BrandingCommand {
	protected function configure(): void {
		$this->setName('educai:branding:reset')
			->setDescription('Reset the display name to Talk AI; keep existing wiki folders and Catalogue settings');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->brandingService->resetDisplayName();
		return $this->reportChange($output);
	}
}
