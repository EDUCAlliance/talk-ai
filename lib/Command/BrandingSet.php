<?php

declare(strict_types=1);

namespace OCA\EducAI\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BrandingSet extends BrandingCommand {
	protected function configure(): void {
		$this->setName('educai:branding:set')
			->setDescription('Set the installation display name without changing app identity, wiki folders or user bots')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name (1–64 bytes)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->brandingService->setDisplayName((string)$input->getOption('name'));
		} catch (InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 2;
		}
		return $this->reportChange($output);
	}
}
