<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Exception\CatalogueLinkConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * Settings-backed course-link resolution shared by all catalogue tools, the
 * answer guard and the admin preview.
 */
class CatalogueLinkService {
	private ?CanonicalLinkResolver $resolver = null;
	private bool $storedConfigurationValid = true;

	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}

	public function resolver(): CanonicalLinkResolver {
		if ($this->resolver !== null) {
			return $this->resolver;
		}
		$config = $this->settingsService->getCatalogueLinkConfig();
		try {
			$this->resolver = CanonicalLinkResolver::fromConfig($config['link_field'], $config['link_template'], $config['link_origins']);
			$this->storedConfigurationValid = true;
		} catch (CatalogueLinkConfigurationException $e) {
			// Values are validated on save; this only guards rows edited outside the app.
			$this->logger->warning('Stored catalogue link configuration is invalid; course links are disabled', [
				'reason' => $e->getReason(),
				'field' => $e->getField(),
			]);
			$this->resolver = CanonicalLinkResolver::unconfigured();
			$this->storedConfigurationValid = false;
		}
		return $this->resolver;
	}

	public function reset(): void {
		$this->resolver = null;
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array{id:?string,canonical_url:?string,source:string,application_url:?string,issues:list<string>}
	 */
	public function resolve(array $record): array {
		return $this->resolver()->resolve($record);
	}

	/** @return array{configured:bool,valid:bool,accepted_origins:list<string>,template_origin:?string} */
	public function status(): array {
		$resolver = $this->resolver();
		return [
			'configured' => $resolver->isConfigured(),
			'valid' => $this->storedConfigurationValid,
			'accepted_origins' => $resolver->acceptedOrigins(),
			'template_origin' => $resolver->templateOrigin(),
		];
	}
}
