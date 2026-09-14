<?php

declare(strict_types=1);

namespace OCA\EducAI\Command;

use OCA\EducAI\Service\BrandingService;
use OCA\EducAI\Service\TalkBotRegistrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BrandingCommand extends Command {
	public function __construct(
		protected BrandingService $brandingService,
		private TalkBotRegistrationService $talkBotRegistrationService,
	) {
		parent::__construct();
	}

	protected function reportChange(OutputInterface $output): int {
		$output->writeln('Display name: ' . OutputFormatter::escape($this->brandingService->getDisplayName()));
		$output->writeln('Wiki root unchanged: ' . OutputFormatter::escape($this->brandingService->getWikiRootFolder()));
		$result = $this->talkBotRegistrationService->updateManagedDisplayName();
		$output->writeln(OutputFormatter::escape($result['message']));
		return $result['status'] === 'error' ? 1 : 0;
	}
}
