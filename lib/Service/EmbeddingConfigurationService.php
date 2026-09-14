<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\Settings;
use OCP\IConfig;

/** Persistent reminders, independent of whether an integration is enabled. */
class EmbeddingConfigurationService {
	public function __construct(private IConfig $config) {
	}

	public function recordChange(Settings $before, Settings $after): void {
		foreach (['rag', 'catalogue'] as $scope) {
			if ($this->fingerprint($before, $scope) !== $this->fingerprint($after, $scope)) {
				$this->config->setAppValue('educai', $scope . '_reindex_required', '1');
			}
		}
	}

	public function isRequired(string $scope): bool {
		return $this->config->getAppValue('educai', $scope . '_reindex_required', '0') === '1';
	}

	/** Queued is not completed; source/job status continues to show the work. */
	public function markQueued(string $scope): void {
		$this->config->setAppValue('educai', $scope . '_reindex_required', '0');
	}

	private function fingerprint(Settings $settings, string $scope): string {
		$endpoint = trim((string)$settings->getEmbeddingApiEndpoint());
		$key = $settings->getEmbeddingApiKey();
		$model = $settings->getEmbeddingModel();
		// Match EmbeddingClient::getActiveModel: a missing/empty embedding model
		// inherits the chat default, so changing that default changes vector space.
		if ($model === null || $model === '') {
			$model = $settings->getDefaultModel();
		}
		$sourceConfiguration = $scope === 'catalogue'
			? [rtrim(trim((string)$settings->getCatalogueApiEndpoint()), '/')]
			: [$settings->getRagChunkSize(), $settings->getRagChunkOverlap()];
		return hash('sha256', json_encode([
			$endpoint !== '' ? $endpoint : trim((string)$settings->getApiEndpoint()),
			$settings->getApiProvider(),
			(string)$model,
			$key !== null && $key !== '' ? $key : $settings->getApiKey(),
			$sourceConfiguration,
		], JSON_THROW_ON_ERROR));
	}
}
