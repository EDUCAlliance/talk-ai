<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Db\ApiSpecRevision;
use OCA\EducAI\OpenApi\ApiConnectionException;
use OCA\EducAI\OpenApi\ApiConnectionService;
use OCA\EducAI\OpenApi\ApiOperationTester;
use OCA\EducAI\OpenApi\OpenApiImportException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Administration of API connections. Every route is admin-only (no
 * NoAdminRequired). Errors carry a stable errorCode; the UI localizes it.
 */
class ApiConnectionController extends Controller {
	private const CONNECTION_FIELDS = [
		'name', 'description', 'base_url', 'auth_mode', 'auth_header', 'credential',
		'data_visibility', 'assign_policy', 'assign_groups', 'bot_visibilities',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private ApiConnectionService $service,
		private ApiOperationTester $tester,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** @AdminRequired */
	public function index(): DataResponse {
		return $this->guard(function (): DataResponse {
			$connections = [];
			foreach ($this->service->listConnections() as $connection) {
				$draft = $this->service->draftRevision($connection);
				$connections[] = $this->service->presentConnection($connection) + [
					'draft_revision_id' => $draft?->getId(),
					'approved_operations' => $this->approvedOperationCount($this->service->activeRevision($connection)),
				];
			}
			return new DataResponse(['connections' => $connections]);
		});
	}

	/** @AdminRequired */
	public function show(int $id): DataResponse {
		return $this->guard(fn (): DataResponse => new DataResponse($this->detail($this->service->getConnection($id))));
	}

	/** @AdminRequired */
	public function create(): DataResponse {
		return $this->guard(function (): DataResponse {
			$connection = $this->service->createConnection($this->connectionInput(), (string)$this->userId);
			return new DataResponse($this->detail($connection), 201);
		});
	}

	/** @AdminRequired */
	public function update(int $id): DataResponse {
		return $this->guard(function () use ($id): DataResponse {
			return new DataResponse($this->detail($this->service->updateConnection($id, $this->connectionInput(), (string)$this->userId)));
		});
	}

	/** @AdminRequired */
	public function setEnabled(int $id, bool $enabled = false): DataResponse {
		return $this->guard(function () use ($id, $enabled): DataResponse {
			return new DataResponse($this->detail($this->service->setEnabled($id, $enabled, (string)$this->userId)));
		});
	}

	/** @AdminRequired */
	public function destroy(int $id): DataResponse {
		return $this->guard(function () use ($id): DataResponse {
			$this->service->deleteConnection($id);
			return new DataResponse(['deleted' => true]);
		});
	}

	/**
	 * Imports either the content of an uploaded document or a public HTTPS
	 * document address; the result is a draft and nothing is activated.
	 *
	 * @AdminRequired
	 */
	public function import(int $id, ?string $document = null, ?string $url = null): DataResponse {
		return $this->guard(function () use ($id, $document, $url): DataResponse {
			$userId = (string)$this->userId;
			if (is_string($url) && trim($url) !== '') {
				$this->service->importFromUrl($id, trim($url), $userId);
			} elseif (is_string($document) && $document !== '') {
				$this->service->importDocument($id, $document, ApiSpecRevision::SOURCE_FILE, null, $userId);
			} else {
				throw new ApiConnectionException('document_missing', 'Choose a document file or enter its address.');
			}
			return new DataResponse($this->detail($this->service->getConnection($id)), 201);
		});
	}

	/** @AdminRequired */
	public function reopen(int $id): DataResponse {
		return $this->guard(function () use ($id): DataResponse {
			$this->service->reopenActive($id, (string)$this->userId);
			return new DataResponse($this->detail($this->service->getConnection($id)), 201);
		});
	}

	/**
	 * @AdminRequired
	 * @param array<string,mixed>|null $operations Edits keyed by operation ID
	 */
	public function updateOperations(int $revisionId, ?array $operations = null): DataResponse {
		return $this->guard(function () use ($revisionId, $operations): DataResponse {
			if (!is_array($operations)) {
				throw new ApiConnectionException('operation_unknown', 'No operation settings were sent.');
			}
			$revision = $this->service->updateDraftOperations($revisionId, $operations, (string)$this->userId);
			return new DataResponse($this->detail($this->service->getConnection($revision->getConnectionId())));
		});
	}

	/** @AdminRequired */
	public function approve(int $revisionId): DataResponse {
		return $this->guard(function () use ($revisionId): DataResponse {
			$revision = $this->service->approve($revisionId, (string)$this->userId);
			return new DataResponse($this->detail($this->service->getConnection($revision->getConnectionId())));
		});
	}

	/** @AdminRequired */
	public function discard(int $revisionId): DataResponse {
		return $this->guard(function () use ($revisionId): DataResponse {
			$connectionId = $this->service->getRevision($revisionId)->getConnectionId();
			$this->service->discardDraft($revisionId, (string)$this->userId);
			return new DataResponse($this->detail($this->service->getConnection($connectionId)));
		});
	}

	/** @AdminRequired */
	public function revoke(int $revisionId, int $operationId): DataResponse {
		return $this->guard(function () use ($revisionId, $operationId): DataResponse {
			$revision = $this->service->revokeOperation($revisionId, $operationId, (string)$this->userId);
			return new DataResponse($this->detail($this->service->getConnection($revision->getConnectionId())));
		});
	}

	/**
	 * @AdminRequired
	 * @param array<string,mixed>|null $arguments {"path": {...}, "query": {...}}
	 */
	public function test(int $revisionId, int $operationId, ?array $arguments = null): DataResponse {
		return $this->guard(function () use ($revisionId, $operationId, $arguments): DataResponse {
			$result = $this->tester->test($revisionId, $operationId, $arguments ?? []);
			return new DataResponse([
				'success' => !$result['isError'],
				'output' => $result['content'][0]['text'] ?? '',
				'error' => $result['error'] ?? null,
			]);
		});
	}

	/** @return array<string,mixed> */
	private function connectionInput(): array {
		$params = $this->request->getParams();
		return array_intersect_key($params, array_flip(self::CONNECTION_FIELDS));
	}

	/** @return array<string,mixed> */
	private function detail(ApiConnection $connection): array {
		$active = $this->service->activeRevision($connection);
		$draft = $this->service->draftRevision($connection);
		return [
			'connection' => $this->service->presentConnection($connection) + [
				'draft_revision_id' => $draft?->getId(),
				'approved_operations' => $this->approvedOperationCount($active),
			],
			'active' => $active === null ? null : $this->service->presentRevision($active, $connection),
			'draft' => $draft === null ? null : $this->service->presentRevision($draft, $connection) + [
				'diff' => $this->service->diff($draft, $connection),
				'approval_problems' => (object)$this->service->approvalProblems($draft, $connection),
			],
		];
	}

	private function approvedOperationCount(?ApiSpecRevision $revision): int {
		$count = 0;
		foreach ($revision?->getOpConfigArray() ?? [] as $entry) {
			if (($entry['enabled'] ?? false) === true && !isset($entry['revoked_at'])) {
				$count++;
			}
		}
		return $count;
	}

	/** @param callable():DataResponse $action */
	private function guard(callable $action): DataResponse {
		try {
			return $action();
		} catch (ApiConnectionException $e) {
			return new DataResponse(['error' => $e->getMessage(), 'errorCode' => $e->getErrorCode(), 'details' => $e->getDetails()], $e->getStatus());
		} catch (OpenApiImportException $e) {
			return new DataResponse(['error' => $e->getMessage(), 'errorCode' => $e->getErrorCode(), 'details' => $e->getDetails()], 422);
		} catch (\Throwable $e) {
			// Trace arguments may hold the submitted credential.
			$this->logger->error('EducAI: API connection request failed', [
				'exception_class' => get_class($e),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			]);
			return new DataResponse([
				'error' => $this->l10n->t('The API connection could not be processed. Check the server log for details.'),
				'errorCode' => 'api_connection_failed',
			], 500);
		}
	}
}
