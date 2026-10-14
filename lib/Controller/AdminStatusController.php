<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use OCA\EducAI\Service\CatalogueClient;
use OCA\EducAI\Service\ConnectionCheckStore;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ModelReferenceMigrationService;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\TalkBotRegistrationService;
use OCA\EducAI\Service\VisionClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AdminStatusController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ConnectionCheckStore $connectionChecks,
		private LLMClient $llmClient,
		private DoclingClient $doclingClient,
		private VisionClient $visionClient,
		private SpeechToTextClient $speechToTextClient,
		private CatalogueClient $catalogueClient,
		private TalkBotRegistrationService $talkBotRegistration,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private ?ModelReferenceMigrationService $modelReferences = null,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @AdminRequired
	 */
	public function status(): DataResponse {
		try {
			$checks = [];
			foreach (ConnectionCheckStore::SERVICES as $service) {
				$record = $this->connectionChecks->read($service);
				$checks[$service] = $record === null
					? null
					: ConnectionCheckStore::present($record, $this->currentFingerprint($service, $record));
			}

			return new DataResponse([
				'checks' => $checks,
				'unqualified_models' => $this->modelReferences?->unqualifiedReferences() ?? [],
				'talk' => [
					'webhook_url' => $this->talkBotRegistration->getWebhookUrl(),
					'secret_configured' => $this->talkBotRegistration->hasWebhookSecret(),
				],
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to load the administrator status', ['exception' => $e]);
			return new DataResponse([
				'error' => $this->l10n->t('Failed to load the status'),
				'errorCode' => 'admin_status_failed',
			], 500);
		}
	}

	/** @param array{summary: ?string} $record */
	private function currentFingerprint(string $service, array $record): ?string {
		try {
			$target = match ($service) {
				'model' => $record['summary'] !== null ? $this->llmClient->connectionCheckTarget($record['summary']) : [],
				'docling' => $this->doclingClient->connectionCheckTarget(),
				'vision' => $this->visionClient->connectionCheckTarget(),
				'speech' => $this->speechToTextClient->connectionCheckTarget(),
				'catalogue' => $this->catalogueClient->connectionCheckTarget(),
				default => [],
			};
		} catch (\Throwable $e) {
			return null;
		}

		return is_string($target['fingerprint'] ?? null) ? $target['fingerprint'] : null;
	}
}
