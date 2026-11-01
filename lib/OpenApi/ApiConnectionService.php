<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Db\ApiConnectionMapper;
use OCA\EducAI\Db\ApiOperation;
use OCA\EducAI\Db\ApiOperationMapper;
use OCA\EducAI\Db\ApiSpecRevision;
use OCA\EducAI\Db\ApiSpecRevisionMapper;
use OCA\EducAI\Service\CredentialService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Administration of API connections: import creates drafts only, approval
 * activates exactly one revision per connection in one transaction, and
 * operations that disappear from an approved revision are tombstoned so their
 * tool names and bot assignments can never come back.
 */
class ApiConnectionService {
	public const MAX_NAME = 128;
	public const MAX_DESCRIPTION = 1000;
	public const MAX_CREDENTIAL = 4096;
	public const MAX_ASSIGN_GROUPS = 50;
	private const REVIEW_DIFF_ASPECTS = ['review_mapping' => 'mapping', 'review_auth' => 'auth', 'review_fixed' => 'fixed', 'review_enabled' => 'enabled', 'review_read_confirmed' => 'read_confirmed', 'review_label' => 'label', 'review_description' => 'description'];
	private const DIFF_ASPECTS = ['parameters', 'security', 'servers', 'response', 'blockers', 'summary', 'description', 'deprecated'];

	public function __construct(
		private ApiConnectionMapper $connections,
		private ApiSpecRevisionMapper $revisions,
		private ApiOperationMapper $operations,
		private IDBConnection $db,
		private CredentialService $credentials,
		private ApiNetworkPolicy $networkPolicy,
		private OpenApiDocumentLoader $loader,
		private OpenApiCompiler $compiler,
		private ApiHttpTransport $transport,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<ApiConnection> */
	public function listConnections(): array {
		return $this->connections->findAll();
	}

	/** @throws ApiConnectionException */
	public function getConnection(int $id): ApiConnection {
		try {
			return $this->connections->findById($id);
		} catch (DoesNotExistException) {
			throw new ApiConnectionException('connection_not_found', 'The API connection does not exist.', 404);
		}
	}

	/** @throws ApiConnectionException */
	public function getRevision(int $id): ApiSpecRevision {
		try {
			return $this->revisions->findById($id);
		} catch (DoesNotExistException) {
			throw new ApiConnectionException('revision_not_found', 'The API document revision does not exist.', 404);
		}
	}

	/** @return list<ApiSpecRevision> */
	public function listRevisions(int $connectionId): array {
		return $this->revisions->findByConnection($connectionId);
	}

	/**
	 * @param array<string,mixed> $input
	 * @throws ApiConnectionException
	 */
	public function createConnection(array $input, string $userId): ApiConnection {
		$connection = new ApiConnection();
		$connection->setCreatedBy($userId);
		$connection->setCreatedAt(time());
		$connection->setEnabled(false);
		$this->apply($connection, $input, true);
		$connection->setUpdatedBy($userId);
		$connection->setUpdatedAt(time());
		return $this->connections->insert($connection);
	}

	/**
	 * Changing the base URL or authentication does not carry an approval
	 * over: calls stay blocked until a revision is approved for the new values.
	 *
	 * @param array<string,mixed> $input
	 * @throws ApiConnectionException
	 */
	public function updateConnection(int $id, array $input, string $userId): ApiConnection {
		$connection = $this->getConnection($id);
		$this->apply($connection, $input, false);
		$connection->setUpdatedBy($userId);
		$connection->setUpdatedAt(time());
		return $this->connections->update($connection);
	}

	/** @throws ApiConnectionException */
	public function setEnabled(int $id, bool $enabled, string $userId): ApiConnection {
		$connection = $this->getConnection($id);
		if ($enabled) {
			$active = $this->activeRevision($connection);
			if ($active === null) {
				throw new ApiConnectionException('no_approved_revision', 'Approve an API document before enabling the connection.', 409);
			}
			if ($connection->getDataVisibility() !== ApiConnection::VISIBILITY_SHARED_FOR_BOT_AUDIENCE) {
				throw new ApiConnectionException('data_visibility_missing', 'Confirm who may see the API data before enabling the connection.', 409);
			}
		}
		$connection->setEnabled($enabled);
		$connection->setUpdatedBy($userId);
		$connection->setUpdatedAt(time());
		return $this->connections->update($connection);
	}

	/** @throws ApiConnectionException */
	public function deleteConnection(int $id): void {
		$connection = $this->getConnection($id);
		$this->transactional(function () use ($connection): void {
			$this->revisions->deleteByConnection($connection->getId());
			$this->operations->deleteByConnection($connection->getId());
			$this->connections->delete($connection);
		});
	}

	/**
	 * Compiles a document into a new draft. Earlier drafts are discarded; the
	 * active revision stays in effect until another one is approved.
	 *
	 * @throws ApiConnectionException
	 * @throws OpenApiImportException
	 */
	public function importDocument(int $connectionId, string $bytes, string $sourceKind, ?string $sourceUrl, string $userId): ApiSpecRevision {
		if (!in_array($sourceKind, [ApiSpecRevision::SOURCE_FILE, ApiSpecRevision::SOURCE_URL, ApiSpecRevision::SOURCE_PRESET], true)) {
			throw new ApiConnectionException('source_invalid', 'Unknown document source.');
		}
		$connection = $this->getConnection($connectionId);
		$loaded = $this->loader->load($bytes);
		$active = $this->activeRevision($connection);
		if ($active !== null && hash_equals($active->getSha256(), $loaded['sha256']) && self::approvalCurrent($active, $connection)) {
			throw new ApiConnectionException('document_unchanged', 'This document is identical to the approved revision.', 409);
		}
		return $this->createDraft($connection, $active, $loaded, $sourceKind, $sourceUrl, $userId);
	}

	/**
	 * @param array{document:array<string,mixed>,version:string,sha256:string,bytes:string} $loaded
	 * @throws OpenApiImportException
	 */
	private function createDraft(ApiConnection $connection, ?ApiSpecRevision $active, array $loaded, string $sourceKind, ?string $sourceUrl, string $userId): ApiSpecRevision {
		$manifest = $this->compiler->compile($loaded['document'], $loaded['version'], $sourceUrl);
		$activeOperations = [];
		$activeConfig = $active?->getOpConfigArray() ?? [];
		foreach ($active?->getManifestArray()['operations'] ?? [] as $operation) {
			$activeOperations[(int)$operation['id']] = $operation;
		}

		return $this->transactional(function () use ($connection, $loaded, $manifest, $sourceKind, $sourceUrl, $userId, $activeOperations, $activeConfig): ApiSpecRevision {
			foreach ($this->revisions->findDrafts($connection->getId()) as $draft) {
				$draft->setStatus(ApiSpecRevision::STATUS_DISCARDED);
				$draft->setUpdatedBy($userId);
				$draft->setUpdatedAt(time());
				$this->revisions->update($draft);
			}

			$config = [];
			foreach ($manifest['operations'] as &$operation) {
				$identity = $this->identityFor($connection->getId(), $operation);
				$operation['id'] = $identity->getId();
				$previous = $activeOperations[$identity->getId()] ?? null;
				$previousConfig = $activeConfig[(string)$identity->getId()] ?? null;
				$config[(string)$identity->getId()] = $previous !== null && $previousConfig !== null && !isset($previousConfig['revoked_at'])
					? ($previous['fingerprint'] === $operation['fingerprint'] ? $previousConfig : self::reconfirm($previousConfig))
					: ApiOperationReview::defaults($operation);
				if (isset($previousConfig['revoked_at'])) {
					$config[(string)$identity->getId()]['revoked_at'] = $previousConfig['revoked_at'];
					$config[(string)$identity->getId()]['revoked_by'] = $previousConfig['revoked_by'] ?? null;
				}
			}
			unset($operation);

			$revision = new ApiSpecRevision();
			$revision->setConnectionId($connection->getId());
			$revision->setSha256($loaded['sha256']);
			$revision->setSourceKind($sourceKind);
			$revision->setSourceUrl($sourceUrl);
			$revision->setDocument($loaded['bytes']);
			$revision->setOasVersion($loaded['version']);
			$revision->setManifestArray($manifest);
			$revision->setOpConfigArray($config);
			$revision->setStatus(ApiSpecRevision::STATUS_DRAFT);
			$revision->setCreatedBy($userId);
			$revision->setCreatedAt(time());
			$revision->setUpdatedBy($userId);
			$revision->setUpdatedAt(time());
			return $this->revisions->insert($revision);
		});
	}

	/**
	 * @throws ApiConnectionException
	 * @throws OpenApiImportException
	 */
	public function importFromUrl(int $connectionId, string $url, string $userId): ApiSpecRevision {
		$this->getConnection($connectionId);
		try {
			$bytes = $this->transport->fetchDocument($url);
		} catch (ApiExecutionException $e) {
			throw new ApiConnectionException('document_fetch_failed', 'The document could not be downloaded.', 422, ['reason' => $e->getErrorCode()]);
		}
		return $this->importDocument($connectionId, $bytes, ApiSpecRevision::SOURCE_URL, $url, $userId);
	}

	/**
	 * Opens the approved document as a new draft: to confirm the review again
	 * after the base URL or authentication changed, to offer a revoked or
	 * another operation, or to correct a mapping. Nothing changes for bots
	 * until the draft is approved.
	 *
	 * @throws ApiConnectionException
	 * @throws OpenApiImportException
	 */
	public function reopenActive(int $connectionId, string $userId): ApiSpecRevision {
		$connection = $this->getConnection($connectionId);
		$active = $this->activeRevision($connection);
		if ($active === null) {
			throw new ApiConnectionException('no_approved_revision', 'There is no approved document to review again.', 409);
		}
		$loaded = $this->loader->load($active->getDocument());
		return $this->createDraft($connection, $active, $loaded, $active->getSourceKind(), $active->getSourceUrl(), $userId);
	}

	/**
	 * @param array<string|int,array<string,mixed>> $edits Keyed by operation ID
	 * @throws ApiConnectionException
	 */
	public function updateDraftOperations(int $revisionId, array $edits, string $userId): ApiSpecRevision {
		$revision = $this->getRevision($revisionId);
		if (!$revision->isDraft()) {
			throw new ApiConnectionException('revision_not_editable', 'Only drafts can be edited.', 409);
		}
		$operations = self::operationsById($revision);
		$config = $revision->getOpConfigArray();
		foreach ($edits as $id => $input) {
			$key = (string)$id;
			if (!isset($operations[$key]) || !is_array($input)) {
				throw new ApiConnectionException('operation_unknown', 'The operation is not part of this revision.', 400, ['operation' => $key]);
			}
			if (($input['enabled'] ?? false) === true && $operations[$key]['supported'] !== true) {
				throw new ApiConnectionException('operation_not_supported', 'This operation cannot be offered.', 400, ['operation' => $key]);
			}
			try {
				$config[$key] = ApiOperationReview::normalizeEdit($input, $config[$key] ?? ApiOperationReview::defaults($operations[$key]), $operations[$key], $userId, time());
			} catch (\InvalidArgumentException $e) {
				throw new ApiConnectionException($e->getMessage(), 'The operation settings are invalid.', 400, ['operation' => $key]);
			}
		}
		$revision->setOpConfigArray($config);
		$revision->setUpdatedBy($userId);
		$revision->setUpdatedAt(time());
		return $this->revisions->update($revision);
	}

	/**
	 * Problems that keep each enabled operation from approval.
	 *
	 * @return array<string,list<string>> Keyed by operation ID
	 */
	public function approvalProblems(ApiSpecRevision $revision, ApiConnection $connection): array {
		$problems = [];
		$credentialPresent = $this->hasCredential($connection);
		$config = $revision->getOpConfigArray();
		foreach (self::operationsById($revision) as $id => $operation) {
			$entry = $config[$id] ?? [];
			if (($entry['enabled'] ?? false) !== true) {
				continue;
			}
			$found = ApiOperationReview::problems($operation, $entry, $connection, $credentialPresent);
			if ($found !== []) {
				$problems[$id] = $found;
			}
		}
		return $problems;
	}

	/** @throws ApiConnectionException */
	public function approve(int $revisionId, string $userId): ApiSpecRevision {
		$revision = $this->getRevision($revisionId);
		if (!$revision->isDraft()) {
			throw new ApiConnectionException('revision_not_draft', 'Only drafts can be approved.', 409);
		}
		$connection = $this->getConnection($revision->getConnectionId());
		$base = (string)$connection->getBaseUrl();
		try {
			if ($base === '' || $this->networkPolicy->normalizeTarget($base) !== $base) {
				throw new \InvalidArgumentException('base_url_invalid');
			}
		} catch (\InvalidArgumentException $e) {
			throw new ApiConnectionException($e->getMessage(), 'Set a valid API base address before approving.', 409);
		}
		if ($connection->getDataVisibility() !== ApiConnection::VISIBILITY_SHARED_FOR_BOT_AUDIENCE) {
			throw new ApiConnectionException('data_visibility_missing', 'Confirm who may see the API data before approving.', 409);
		}

		$config = $revision->getOpConfigArray();
		$enabled = array_keys(array_filter($config, static fn (array $entry): bool => ($entry['enabled'] ?? false) === true));
		if ($enabled === []) {
			throw new ApiConnectionException('no_operation_enabled', 'Enable at least one operation before approving.', 409);
		}
		$problems = $this->approvalProblems($revision, $connection);
		if ($problems !== []) {
			$flat = [];
			foreach ($problems as $id => $codes) {
				foreach ($codes as $code) {
					$flat[] = $id . ':' . $code;
				}
			}
			throw new ApiConnectionException('operations_not_approvable', 'Some enabled operations cannot be approved yet.', 409, ['problems' => array_slice($flat, 0, 50)]);
		}

		$manifestOperations = self::operationsById($revision);
		$identities = [];
		foreach ($this->operations->findByIds(array_map('intval', array_keys($manifestOperations))) as $identity) {
			$identities[(string)$identity->getId()] = $identity;
		}
		foreach ($manifestOperations as $id => $operation) {
			$identity = $identities[$id] ?? null;
			if ($identity === null || $identity->isTombstoned() || $identity->getConnectionId() !== $connection->getId()
				|| $identity->getMatchKey() !== $operation['match_key']) {
				throw new ApiConnectionException('revision_outdated', 'This draft refers to operations that were removed meanwhile. Import the document again.', 409);
			}
		}

		return $this->transactional(function () use ($revision, $connection, $userId, $config, $manifestOperations, $identities): ApiSpecRevision {
			$now = time();
			$previous = $this->activeRevision($connection);
			$previousConfig = $previous?->getOpConfigArray() ?? [];
			if ($previous !== null && $previous->getId() !== $revision->getId()) {
				$previous->setStatus(ApiSpecRevision::STATUS_SUPERSEDED);
				$previous->setUpdatedBy($userId);
				$previous->setUpdatedAt($now);
				$this->revisions->update($previous);
			}

			$revision->setStatus(ApiSpecRevision::STATUS_APPROVED);
			$revision->setApprovedBaseUrl($connection->getBaseUrl());
			$revision->setApprovedAuth(json_encode(self::authSnapshot($connection), JSON_THROW_ON_ERROR));
			$revision->setApprovedBy($userId);
			$revision->setApprovedAt($now);
			$revision->setUpdatedBy($userId);
			$revision->setUpdatedAt($now);
			$revision = $this->revisions->update($revision);

			$connection->setActiveRevisionId($revision->getId());
			$connection->setUpdatedBy($userId);
			$connection->setUpdatedAt($now);
			$this->connections->update($connection);

			foreach ($this->operations->findByConnection($connection->getId()) as $identity) {
				$id = (string)$identity->getId();
				if (!$identity->isTombstoned() && !isset($manifestOperations[$id])) {
					$identity->setActiveKey(null);
					$identity->setTombstonedAt($now);
					$this->operations->update($identity);
				} elseif (isset($manifestOperations[$id], $identities[$id])) {
					$label = is_string($config[$id]['label'] ?? null) ? $config[$id]['label'] : null;
					if ($label !== null && $label !== '' && $label !== $identity->getLastLabel()) {
						$identity->setLastLabel($label);
						$this->operations->update($identity);
					}
				}
			}
			foreach ($config as $id => $entry) {
				if (isset($previousConfig[$id]['revoked_at']) && ($entry['enabled'] ?? false) === true && !isset($entry['revoked_at'])) {
					$this->logger->info('API operation re-released', ['app' => 'educai', 'connection_id' => $connection->getId(), 'revision_id' => $revision->getId(), 'operation_id' => (int)$id, 'actor_id' => $userId]);
				}
			}
			return $revision;
		});
	}

	/**
	 * Revocation takes effect for the next call; the revision stays active.
	 *
	 * @throws ApiConnectionException
	 */
	public function revokeOperation(int $revisionId, int $operationId, string $userId): ApiSpecRevision {
		$revision = $this->getRevision($revisionId);
		if (!$revision->isApproved()) {
			throw new ApiConnectionException('revision_not_approved', 'Only operations of the approved revision can be revoked.', 409);
		}
		$config = $revision->getOpConfigArray();
		$key = (string)$operationId;
		if (!isset($config[$key]) || ($config[$key]['enabled'] ?? false) !== true) {
			throw new ApiConnectionException('operation_unknown', 'The operation is not approved in this revision.', 404);
		}
		return $this->transactional(function () use ($revision, $key, $userId): ApiSpecRevision {
			$now = time();
			// Invalidate confirmations in drafts imported before the revocation too.
			foreach ([$revision, ...$this->revisions->findDrafts($revision->getConnectionId())] as $affected) {
				$config = $affected->getOpConfigArray();
				if (!isset($config[$key])) {
					continue;
				}
				$config[$key]['read_confirmed'] = false;
				$config[$key]['revoked_at'] = $now;
				$config[$key]['revoked_by'] = $userId;
				$affected->setOpConfigArray($config);
				$affected->setUpdatedBy($userId);
				$affected->setUpdatedAt($now);
				$this->revisions->update($affected);
			}
			return $revision;
		});
	}

	/** @throws ApiConnectionException */
	public function discardDraft(int $revisionId, string $userId): void {
		$revision = $this->getRevision($revisionId);
		if (!$revision->isDraft()) {
			throw new ApiConnectionException('revision_not_draft', 'Only drafts can be discarded.', 409);
		}
		$revision->setStatus(ApiSpecRevision::STATUS_DISCARDED);
		$revision->setUpdatedBy($userId);
		$revision->setUpdatedAt(time());
		$this->revisions->update($revision);
	}

	/**
	 * Semantic difference between a draft and the approved revision.
	 *
	 * @return array{base_revision_id:?int,added:list<int>,removed:list<array{id:int,match_key:string}>,changed:list<array{id:int,match_key:string,aspects:list<string>,changes:array<string,array{before:mixed,after:mixed}>}>,unchanged:int}
	 */
	public function diff(ApiSpecRevision $revision, ApiConnection $connection): array {
		$active = $this->activeRevision($connection);
		$before = $active !== null && $active->getId() !== $revision->getId() ? self::operationsById($active) : [];
		$after = self::operationsById($revision);
		$beforeConfig = $active?->getOpConfigArray() ?? [];
		$afterConfig = $revision->getOpConfigArray();
		$result = ['base_revision_id' => $active?->getId(), 'added' => [], 'removed' => [], 'changed' => [], 'unchanged' => 0];
		foreach ($after as $id => $operation) {
			$old = $before[$id] ?? null;
			if ($old === null) {
				$result['added'][] = (int)$id;
			} else {
				$changes = [];
				if ($old['fingerprint'] !== $operation['fingerprint']) {
					foreach (self::DIFF_ASPECTS as $aspect) {
						if (($old[$aspect] ?? null) !== ($operation[$aspect] ?? null)) {
							$changes[$aspect] = ['before' => $old[$aspect] ?? null, 'after' => $operation[$aspect] ?? null];
						}
					}
					if ($changes === []) {
						$changes['other'] = ['before' => $old, 'after' => $operation];
					}
				}
				foreach (self::REVIEW_DIFF_ASPECTS as $aspect => $key) {
					$beforeValue = $beforeConfig[$id][$key] ?? null;
					$afterValue = $afterConfig[$id][$key] ?? null;
					if ($beforeValue !== $afterValue) {
						$changes[$aspect] = ['before' => $beforeValue, 'after' => $afterValue];
					}
				}
				if ($changes === []) {
					$result['unchanged']++;
				} else {
					$result['changed'][] = ['id' => (int)$id, 'match_key' => $operation['match_key'], 'aspects' => array_keys($changes), 'changes' => $changes];
				}
			}
		}
		foreach ($before as $id => $operation) {
			if (!isset($after[$id])) {
				$result['removed'][] = ['id' => (int)$id, 'match_key' => $operation['match_key']];
			}
		}
		return $result;
	}

	public function activeRevision(ApiConnection $connection): ?ApiSpecRevision {
		$id = $connection->getActiveRevisionId();
		if ($id === null) {
			return null;
		}
		try {
			$revision = $this->revisions->findById($id);
		} catch (DoesNotExistException) {
			return null;
		}
		return $revision->isApproved() && $revision->getConnectionId() === $connection->getId() ? $revision : null;
	}

	/** The draft under review; importing keeps at most one. */
	public function draftRevision(ApiConnection $connection): ?ApiSpecRevision {
		return $this->revisions->findDrafts($connection->getId())[0] ?? null;
	}

	public function hasCredential(ApiConnection $connection): bool {
		return $connection->getCredential() !== null && $connection->getCredential() !== '';
	}

	/** @return array{mode:string,header:?string} */
	/**
	 * A changed operation keeps its reviewed settings, so an offered tool is not
	 * silently dropped and its mapping is not lost. It cannot be approved again
	 * until an admin confirms the new definition; settings that no longer fit
	 * the operation show up as review problems.
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	private static function reconfirm(array $config): array {
		unset($config['confirmed_by'], $config['confirmed_at']);
		return array_merge($config, ['read_confirmed' => false]);
	}

	public static function authSnapshot(ApiConnection $connection): array {
		return ['mode' => $connection->getAuthMode(), 'header' => $connection->getAuthHeader()];
	}

	public static function approvalCurrent(ApiSpecRevision $revision, ApiConnection $connection): bool {
		return $revision->getApprovedBaseUrl() !== null
			&& $revision->getApprovedBaseUrl() === $connection->getBaseUrl()
			&& $revision->getApprovedAuthArray() === self::authSnapshot($connection);
	}

	/** @return array<string,array<string,mixed>> */
	public static function operationsById(ApiSpecRevision $revision): array {
		$result = [];
		foreach ($revision->getManifestArray()['operations'] ?? [] as $operation) {
			if (is_array($operation) && isset($operation['id'])) {
				$result[(string)$operation['id']] = $operation;
			}
		}
		return $result;
	}

	/**
	 * Admin view; the credential itself is never returned.
	 *
	 * @return array<string,mixed>
	 */
	public function presentConnection(ApiConnection $connection): array {
		$active = $this->activeRevision($connection);
		return [
			'id' => $connection->getId(),
			'name' => $connection->getName(),
			'description' => (string)$connection->getDescription(),
			'enabled' => $connection->isEnabled(),
			'base_url' => $connection->getBaseUrl(),
			'auth_mode' => $connection->getAuthMode(),
			'auth_header' => $connection->getAuthHeader(),
			'credential_set' => $this->hasCredential($connection),
			'data_visibility' => $connection->getDataVisibility(),
			'assign_policy' => $connection->getAssignPolicy(),
			'assign_groups' => $connection->getAssignGroupList(),
			'bot_visibilities' => $connection->getBotVisibilityList(),
			'active_revision_id' => $active?->getId(),
			'approval_current' => $active !== null && self::approvalCurrent($active, $connection),
			'last_check' => $connection->getLastCheckAt() === null ? null : [
				'at' => $connection->getLastCheckAt(),
				'status' => $connection->getLastCheckStatus(),
				'message' => $connection->getLastCheckMessage(),
			],
			'created_by' => $connection->getCreatedBy(),
			'created_at' => $connection->getCreatedAt(),
			'updated_by' => $connection->getUpdatedBy(),
			'updated_at' => $connection->getUpdatedAt(),
		];
	}

	/** @return array<string,mixed> */
	public function presentRevision(ApiSpecRevision $revision, ApiConnection $connection): array {
		$manifest = $revision->getManifestArray();
		$config = $revision->getOpConfigArray();
		$credentialPresent = $this->hasCredential($connection);
		$operations = [];
		foreach ($manifest['operations'] ?? [] as $operation) {
			$id = (string)$operation['id'];
			$entry = $config[$id] ?? ApiOperationReview::defaults($operation);
			$operations[] = [
				'id' => (int)$id,
				'tool_name' => OpenApiToolCatalog::toolName($connection->getId(), (int)$id),
				'match_key' => $operation['match_key'],
				'method' => $operation['method'],
				'path' => $operation['path'],
				'operation_id' => $operation['operation_id'],
				'summary' => $operation['summary'],
				'description' => $operation['description'],
				'deprecated' => $operation['deprecated'],
				'servers' => $operation['servers'],
				'security' => $operation['security'],
				'parameters' => $operation['parameters'],
				'response' => $operation['response'],
				'supported' => $operation['supported'],
				'blockers' => $operation['blockers'],
				'notes' => $operation['notes'],
				'config' => $entry,
				'problems' => ApiOperationReview::problems($operation, ['enabled' => true, 'read_confirmed' => true] + $entry, $connection, $credentialPresent),
			];
		}
		return [
			'id' => $revision->getId(),
			'connection_id' => $revision->getConnectionId(),
			'status' => $revision->getStatus(),
			'sha256' => $revision->getSha256(),
			'source_kind' => $revision->getSourceKind(),
			'source_url' => $revision->getSourceUrl(),
			'oas_version' => $revision->getOasVersion(),
			'info' => $manifest['info'] ?? null,
			'servers' => $manifest['servers'] ?? [],
			'security_schemes' => $manifest['security_schemes'] ?? [],
			'diagnostics' => $manifest['diagnostics'] ?? [],
			'operations' => $operations,
			'approved_base_url' => $revision->getApprovedBaseUrl(),
			'approved_by' => $revision->getApprovedBy(),
			'approved_at' => $revision->getApprovedAt(),
			'created_by' => $revision->getCreatedBy(),
			'created_at' => $revision->getCreatedAt(),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @throws ApiConnectionException
	 */
	private function apply(ApiConnection $connection, array $input, bool $creating): void {
		if ($creating || array_key_exists('name', $input)) {
			$name = is_string($input['name'] ?? null) ? ParameterSchemaCompiler::text($input['name'], self::MAX_NAME) : '';
			if ($name === '') {
				throw new ApiConnectionException('name_missing', 'Enter a name for the connection.');
			}
			$connection->setName($name);
		}
		if (array_key_exists('description', $input)) {
			$description = is_string($input['description']) ? trim($input['description']) : '';
			$connection->setDescription($description === '' ? null : mb_substr($description, 0, self::MAX_DESCRIPTION));
		}
		if (array_key_exists('base_url', $input)) {
			$raw = is_string($input['base_url']) ? trim($input['base_url']) : '';
			if ($raw === '') {
				$connection->setBaseUrl(null);
			} else {
				try {
					$connection->setBaseUrl($this->networkPolicy->normalizeTarget($raw));
				} catch (\InvalidArgumentException $e) {
					throw new ApiConnectionException($e->getMessage(), 'The API base address is not allowed.');
				}
			}
		}
		if (array_key_exists('auth_mode', $input)) {
			$mode = $input['auth_mode'];
			if (!in_array($mode, [ApiConnection::AUTH_NONE, ApiConnection::AUTH_BEARER, ApiConnection::AUTH_API_KEY_HEADER], true)) {
				throw new ApiConnectionException('auth_mode_invalid', 'Unknown authentication mode.');
			}
			$connection->setAuthMode($mode);
		}
		if (array_key_exists('auth_header', $input) || array_key_exists('auth_mode', $input)) {
			$header = is_string($input['auth_header'] ?? null) ? trim($input['auth_header']) : (string)$connection->getAuthHeader();
			if ($connection->getAuthMode() === ApiConnection::AUTH_API_KEY_HEADER) {
				if (!OpenApiCompiler::isSendableHeaderName($header)) {
					throw new ApiConnectionException('auth_header_invalid', 'Enter the header name the API expects for the key.');
				}
				$connection->setAuthHeader($header);
			} else {
				$connection->setAuthHeader(null);
			}
		}
		if (array_key_exists('credential', $input)) {
			$this->applyCredential($connection, $input['credential']);
		}
		if ($connection->getAuthMode() === ApiConnection::AUTH_NONE && $this->hasCredential($connection)) {
			$connection->setCredential(null);
		}
		if (array_key_exists('data_visibility', $input)) {
			$visibility = $input['data_visibility'];
			if ($visibility !== null && $visibility !== ApiConnection::VISIBILITY_SHARED_FOR_BOT_AUDIENCE) {
				throw new ApiConnectionException('data_visibility_invalid', 'Unknown data visibility.');
			}
			$connection->setDataVisibility($visibility);
			if ($visibility === null) {
				$connection->setEnabled(false);
			}
		}
		if (array_key_exists('assign_policy', $input)) {
			$policy = $input['assign_policy'];
			if (!in_array($policy, [ApiConnection::ASSIGN_ADMINS, ApiConnection::ASSIGN_GROUPS, ApiConnection::ASSIGN_ALL_USERS], true)) {
				throw new ApiConnectionException('assign_policy_invalid', 'Unknown assignment policy.');
			}
			$connection->setAssignPolicy($policy);
		}
		if (array_key_exists('assign_groups', $input)) {
			$connection->setAssignGroupList(self::stringList($input['assign_groups'], self::MAX_ASSIGN_GROUPS, 255, 'assign_groups_invalid'));
		}
		if ($connection->getAssignPolicy() === ApiConnection::ASSIGN_GROUPS && $connection->getAssignGroupList() === []) {
			throw new ApiConnectionException('assign_groups_missing', 'Choose at least one group that may assign this API.');
		}
		if ($connection->getAssignPolicy() !== ApiConnection::ASSIGN_GROUPS) {
			$connection->setAssignGroupList([]);
		}
		if (array_key_exists('bot_visibilities', $input)) {
			$visibilities = self::stringList($input['bot_visibilities'], count(ApiConnection::BOT_VISIBILITIES), 16, 'bot_visibilities_invalid');
			if ($visibilities === [] || array_diff($visibilities, ApiConnection::BOT_VISIBILITIES) !== []) {
				throw new ApiConnectionException('bot_visibilities_invalid', 'Choose the bot visibilities this API may be used with.');
			}
			$connection->setBotVisibilityList($visibilities);
		}
	}

	/** @return list<string> */
	private static function stringList(mixed $value, int $maxItems, int $maxLength, string $code): array {
		if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
			throw new ApiConnectionException($code, 'The list is invalid.');
		}
		$items = [];
		foreach ($value as $item) {
			if (!is_string($item) || trim($item) === '' || mb_strlen($item) > $maxLength || preg_match('/[\x00-\x1f\x7f]/', $item) === 1) {
				throw new ApiConnectionException($code, 'The list contains an invalid entry.');
			}
			$items[] = $item;
		}
		return array_values(array_unique($items));
	}

	/**
	 * Credential edits: {"action":"replace","value":"…"} or {"action":"remove"}.
	 * Anything else leaves the stored secret untouched.
	 *
	 * @throws ApiConnectionException
	 */
	private function applyCredential(ApiConnection $connection, mixed $edit): void {
		if (!is_array($edit)) {
			return;
		}
		$action = $edit['action'] ?? 'keep';
		if ($action === 'remove') {
			$connection->setCredential(null);
			return;
		}
		if ($action !== 'replace') {
			return;
		}
		$value = is_string($edit['value'] ?? null) ? trim($edit['value']) : '';
		if ($value === '' || strlen($value) > self::MAX_CREDENTIAL || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
			throw new ApiConnectionException('credential_invalid', 'The credential is empty, too long or contains control characters.');
		}
		$connection->setCredential($this->credentials->encrypt($value));
	}

	/** @param array<string,mixed> $operation */
	private function identityFor(int $connectionId, array $operation): ApiOperation {
		$existing = $this->operations->findActive($connectionId, $operation['match_key']);
		if ($existing !== null) {
			return $existing;
		}
		$identity = new ApiOperation();
		$identity->setConnectionId($connectionId);
		$identity->setMatchKey($operation['match_key']);
		$identity->setMatchHash(ApiOperation::hashMatchKey($operation['match_key']));
		$identity->setActiveKey(ApiOperation::activeKeyFor($connectionId, $operation['match_key']));
		$identity->setOperationIdHint($operation['operation_id']);
		$identity->setCreatedAt(time());
		try {
			return $this->operations->insert($identity);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$concurrent = $this->operations->findActive($connectionId, $operation['match_key']);
			if ($concurrent === null) {
				throw $e;
			}
			return $concurrent;
		}
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	private function transactional(callable $callback): mixed {
		$this->db->beginTransaction();
		try {
			$result = $callback();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			$this->logger->warning('EducAI: API connection change rolled back', ['exception_class' => get_class($e)]);
			throw $e;
		}
	}
}
