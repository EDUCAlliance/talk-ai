<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Db\ApiSpecRevision;

/**
 * Curated API connections shipped with the app. A preset is a normalized
 * OpenAPI document plus suggested review values; the unmodified upstream
 * document lies next to it for comparison. Creating one never enables,
 * approves or confirms anything and never contacts the API.
 */
class ApiPresetService {
	public const IDS = ['educ-catalogue'];
	private const DIRECTORY = __DIR__ . '/Presets';

	public function __construct(
		private ApiConnectionService $connectionService,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function listPresets(): array {
		return array_map(fn (string $id): array => $this->present($this->manifest($id)), self::IDS);
	}

	/**
	 * Creates a disabled connection with a draft from the preset document.
	 * Suggested operations are preselected but still need the read
	 * confirmation, the data visibility decision and the approval.
	 *
	 * @return array{connection:ApiConnection,revision:ApiSpecRevision}
	 * @throws ApiConnectionException
	 */
	public function create(string $presetId, string $userId): array {
		$manifest = $this->manifest($presetId);
		$connection = $this->connectionService->createConnection([
			'name' => $manifest['name'],
			'description' => $manifest['description'],
			'base_url' => $manifest['connection']['base_url'],
			'auth_mode' => $manifest['connection']['auth_mode'],
		], $userId);
		try {
			$revision = $this->connectionService->importDocument($connection->getId(), $this->read($presetId, $manifest['document']), ApiSpecRevision::SOURCE_PRESET, null, $userId);
			$edits = [];
			foreach (ApiConnectionService::operationsById($revision) as $id => $operation) {
				$suggestion = $manifest['operations'][$operation['match_key']] ?? null;
				if (is_array($suggestion) && $operation['supported'] === true) {
					$edits[$id] = ['enabled' => true] + array_intersect_key($suggestion, array_flip(['label', 'description', 'auth', 'mapping']));
				}
			}
			$revision = $this->connectionService->updateDraftOperations($revision->getId(), $edits, $userId);
		} catch (\Throwable $e) {
			$this->connectionService->deleteConnection($connection->getId());
			if ($e instanceof ApiConnectionException) {
				throw $e;
			}
			throw new ApiConnectionException('preset_invalid', 'The preset could not be imported.', 500, ['preset' => $presetId]);
		}
		return ['connection' => $connection, 'revision' => $revision];
	}

	/**
	 * @return array<string,mixed>
	 * @throws ApiConnectionException
	 */
	public function manifest(string $presetId): array {
		if (!in_array($presetId, self::IDS, true)) {
			throw new ApiConnectionException('preset_not_found', 'Unknown preset.', 404);
		}
		try {
			$manifest = json_decode($this->read($presetId, 'preset.json'), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$manifest = null;
		}
		if (!is_array($manifest) || ($manifest['id'] ?? null) !== $presetId || !is_array($manifest['connection'] ?? null)
			|| !is_array($manifest['operations'] ?? null) || !is_array($manifest['upstream'] ?? null)) {
			throw new ApiConnectionException('preset_invalid', 'The preset is damaged.', 500, ['preset' => $presetId]);
		}
		return $manifest;
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @return array<string,mixed>
	 */
	private function present(array $manifest): array {
		return [
			'id' => $manifest['id'],
			'revision' => (int)($manifest['revision'] ?? 1),
			'name' => $manifest['name'],
			'description' => $manifest['description'],
			'base_url' => $manifest['connection']['base_url'],
			'auth_mode' => $manifest['connection']['auth_mode'],
			'operations' => array_keys($manifest['operations']),
			'upstream' => [
				'url' => $manifest['upstream']['url'],
				'version' => $manifest['upstream']['version'],
				'sha256' => $manifest['upstream']['sha256'],
				'retrieved_at' => $manifest['upstream']['retrieved_at'],
			],
		];
	}

	/** @throws ApiConnectionException */
	private function read(string $presetId, string $file): string {
		if (preg_match('/^[a-z0-9][a-z0-9.-]{0,63}\.json$/D', $file) !== 1) {
			throw new ApiConnectionException('preset_invalid', 'The preset is damaged.', 500, ['preset' => $presetId]);
		}
		$bytes = @file_get_contents(self::DIRECTORY . '/' . $presetId . '/' . $file);
		if ($bytes === false) {
			throw new ApiConnectionException('preset_invalid', 'The preset is damaged.', 500, ['preset' => $presetId]);
		}
		return $bytes;
	}
}
