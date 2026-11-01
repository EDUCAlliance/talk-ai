<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\AppInfo\Application;
use OCP\IConfig;

/**
 * Optional record source for the course catalogue: the catalogue reads go
 * through an approved API connection instead of the legacy endpoint. Every
 * request resolves the operation afresh, so disabling the connection,
 * revoking the operation or changing its base or authentication stops the
 * source immediately. Failures are thrown, never returned as empty data.
 */
class CatalogueApiSource {
	public const CONFIG_KEY = 'catalogue_api_connection_id';
	/** Archived snapshots are read in pages of 500 records of a few KiB each. */
	public const MAX_RESPONSE_BYTES = 8_388_608;
	public const LIST_OPERATION = 'GET /course/list';
	/** @var list<string> */
	public const OPERATIONS = ['GET /course/list', 'GET /version'];

	public function __construct(
		private IConfig $config,
		private ApiConnectionService $connectionService,
		private OpenApiToolCatalog $catalog,
		private OpenApiExecutionService $execution,
	) {
	}

	public function selectedConnectionId(): ?int {
		$value = $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '');
		return preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1 ? (int)$value : null;
	}

	public function isSelected(): bool {
		return $this->selectedConnectionId() !== null;
	}

	/**
	 * Selecting requires an available list operation; switching back to the
	 * legacy endpoint (null) is always possible. Neither triggers a reindex.
	 *
	 * @throws ApiConnectionException
	 */
	public function select(?int $connectionId): void {
		if ($connectionId === null) {
			$this->config->deleteAppValue(Application::APP_ID, self::CONFIG_KEY);
			return;
		}
		$reason = $this->operationStatus($connectionId)[self::LIST_OPERATION];
		if ($reason !== null) {
			throw new ApiConnectionException('catalogue_source_unavailable', 'The connection cannot serve the course list yet.', 409, ['reason' => $reason]);
		}
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_KEY, (string)$connectionId);
	}

	/**
	 * Why each catalogue operation cannot be used right now; null when it can.
	 *
	 * @return array<string,?string> Keyed by match key
	 */
	public function operationStatus(int $connectionId): array {
		$status = [];
		foreach (self::OPERATIONS as $matchKey) {
			try {
				$this->resolve($connectionId, $matchKey);
				$status[$matchKey] = null;
			} catch (ApiExecutionException $e) {
				$details = $e->getDetails();
				$status[$matchKey] = is_string($details['reason'] ?? null) ? $details['reason'] : $e->getErrorCode();
			}
		}
		return $status;
	}

	/**
	 * @param string $path Catalogue path below the base, e.g. "/course/list"
	 * @param array<string,scalar> $query
	 * @return array<string,mixed> Decoded JSON object
	 * @throws ApiExecutionException
	 */
	public function get(string $path, array $query, float $timeout): array {
		$connectionId = $this->selectedConnectionId();
		if ($connectionId === null) {
			throw new ApiExecutionException('tool_not_available', 'No API connection is selected for the course catalogue.', ['reason' => 'catalogue_source_not_selected']);
		}
		$resolved = $this->resolve($connectionId, 'GET ' . $path);
		$data = $this->execution->fetch($resolved, $query === [] ? [] : ['query' => $query], $timeout, [
			'purpose' => 'catalogue',
			'connection_id' => $connectionId,
			'operation_id' => (int)$resolved['operation']['id'],
		], self::MAX_RESPONSE_BYTES);
		if (!is_array($data) || ($data !== [] && array_is_list($data))) {
			throw new ApiExecutionException('response_unexpected', 'The catalogue API did not answer with a JSON object.', [], true);
		}
		return $data;
	}

	/**
	 * @return array{connection:\OCA\EducAI\Db\ApiConnection,revision:\OCA\EducAI\Db\ApiSpecRevision,operation:array<string,mixed>,config:array<string,mixed>}
	 * @throws ApiExecutionException
	 */
	private function resolve(int $connectionId, string $matchKey): array {
		try {
			$connection = $this->connectionService->getConnection($connectionId);
		} catch (ApiConnectionException) {
			throw new ApiExecutionException('tool_not_available', 'The catalogue API connection is not available.', ['reason' => 'connection_not_found']);
		}
		$revision = $this->connectionService->activeRevision($connection);
		foreach ($revision?->getManifestArray()['operations'] ?? [] as $operation) {
			if (($operation['match_key'] ?? null) === $matchKey) {
				return $this->catalog->resolve($connectionId, (int)$operation['id']);
			}
		}
		throw new ApiExecutionException('tool_not_available', 'The catalogue API connection does not offer this operation.', ['reason' => $revision === null ? 'no_approved_revision' : 'operation_missing']);
	}
}
