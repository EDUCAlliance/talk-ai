<?php

declare(strict_types=1);

namespace OCA\EducAI\Command;

use OCA\EducAI\Service\BrandingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BrandingShow extends Command {
	public function __construct(private BrandingService $brandingService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('educai:branding:show')->setDescription('Show the current display name and persistent wiki root');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('Display name: ' . OutputFormatter::escape($this->brandingService->getDisplayName()));
		$output->writeln('Wiki root: ' . OutputFormatter::escape($this->brandingService->getWikiRootFolder()));
		$output->writeln('App ID: educai; package name: Talk AI');
		return 0;
	}
}
