<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class AttachmentResolver {
	private IRootFolder $rootFolder;
	private IClientService $clientService;
	private LoggerInterface $logger;

	public function __construct(
		IRootFolder $rootFolder,
		IClientService $clientService,
		LoggerInterface $logger
	) {
		$this->rootFolder = $rootFolder;
		$this->clientService = $clientService;
		$this->logger = $logger;
	}

	public function resolveSourceFile(IncomingTalkAttachment $attachment): ?File {
		$fileRef = $attachment->getFileRef();
		foreach ($this->collectCandidateIds($fileRef) as $candidateId) {
			try {
				$nodes = $this->rootFolder->getById($candidateId);
				foreach ($nodes as $node) {
					if ($node instanceof File) {
						return $node;
					}
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Failed to resolve attachment node candidate', [
					'candidate_id' => $candidateId,
					'attachment' => $attachment->getDisplayName(),
					'exception' => $e,
				]);
			}
		}

		$file = $this->resolveFromOwnerFolder($fileRef);
		if ($file instanceof File) {
			return $file;
		}

		return null;
	}

	/**
	 * @throws Exception
	 */
	public function resolveToTempFile(IncomingTalkAttachment $attachment): ResolvedAttachment {
		$file = $this->resolveSourceFile($attachment);
		if ($file instanceof File) {
			$mimeType = $attachment->getMimeType() ?? $file->getMimeType();
			return $this->writeTempFile($attachment->getDisplayName(), (string)$file->getContent(), $mimeType);
		}

		$url = $this->resolveRemoteUrl($attachment->getFileRef());
		if ($url !== null) {
			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'timeout' => 60,
			]);
			$contentType = $attachment->getMimeType();
			try {
				$header = $response->getHeader('Content-Type');
				if (is_string($header) && $header !== '') {
					$contentType = $header;
				} elseif (is_array($header) && isset($header[0]) && is_string($header[0])) {
					$contentType = $header[0];
				}
			} catch (\Throwable $e) {
				// Ignore missing header.
			}

			return $this->writeTempFile($attachment->getDisplayName(), (string)$response->getBody(), $contentType);
		}

		throw new Exception('Unable to resolve attachment content for ' . $attachment->getDisplayName());
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<int,int>
	 */
	private function collectCandidateIds(array $data): array {
		$matches = [];
		$whitelist = ['fileid', 'file_id', 'fileId', 'nodeid', 'node_id', 'nodeId', 'id'];
		$this->collectRecursiveIds($data, $matches, $whitelist);
		return array_values(array_unique($matches));
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,int> $matches
	 * @param array<int,string> $whitelist
	 */
	private function collectRecursiveIds(array $data, array &$matches, array $whitelist): void {
		foreach ($data as $key => $value) {
			$normalizedKey = is_string($key) ? $key : '';
			if (in_array($normalizedKey, $whitelist, true) && is_numeric($value)) {
				$matches[] = (int)$value;
			}

			if (is_array($value)) {
				$this->collectRecursiveIds($value, $matches, $whitelist);
			}
		}
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function resolveRemoteUrl(array $data): ?string {
		$candidates = $this->collectRemoteUrls($data);
		foreach ($candidates as $candidate) {
			if (filter_var($candidate, FILTER_VALIDATE_URL)) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<int,string>
	 */
	private function collectRemoteUrls(array $data): array {
		$matches = [];
		foreach ($data as $key => $value) {
			if (is_string($key) && in_array($key, ['link', 'url', 'downloadUrl', 'download_url', 'previewUrl', 'preview_url'], true) && is_string($value)) {
				$matches[] = $value;
				continue;
			}
			if (is_array($value)) {
				$matches = array_merge($matches, $this->collectRemoteUrls($value));
			}
		}

		return $matches;
	}

	/**
	 * @param array<string,mixed> $fileRef
	 */
	private function resolveFromOwnerFolder(array $fileRef): ?File {
		$ownerUid = $this->resolveOwnerUid($fileRef);
		if ($ownerUid === null) {
			return null;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($ownerUid);
		} catch (\Throwable $e) {
			$this->logger->debug('Failed to resolve user folder for attachment owner', [
				'owner_uid' => $ownerUid,
				'exception' => $e,
			]);
			return null;
		}

		foreach ($this->collectCandidateUserPaths($fileRef) as $candidatePath) {
			try {
				$node = $userFolder->get($candidatePath);
				if ($node instanceof File) {
					return $node;
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Failed to resolve attachment from owner folder path candidate', [
					'owner_uid' => $ownerUid,
					'candidate_path' => $candidatePath,
					'exception' => $e,
				]);
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $fileRef
	 */
	private function resolveOwnerUid(array $fileRef): ?string {
		$candidates = [
			$fileRef['_educai_owner_uid'] ?? null,
			$fileRef['owner_uid'] ?? null,
			$fileRef['ownerUid'] ?? null,
		];

		foreach ($candidates as $candidate) {
			if (!is_string($candidate) || trim($candidate) === '') {
				continue;
			}

			$normalized = trim($candidate);
			if (str_starts_with($normalized, 'users/')) {
				$normalized = substr($normalized, strlen('users/'));
			}

			if ($normalized !== '') {
				return $normalized;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $fileRef
	 * @return array<int,string>
	 */
	private function collectCandidateUserPaths(array $fileRef): array {
		$paths = [];
		$candidates = [
			$fileRef['path'] ?? null,
			$fileRef['name'] ?? null,
			is_array($fileRef['file'] ?? null) ? ($fileRef['file']['path'] ?? null) : null,
			is_array($fileRef['file'] ?? null) ? ($fileRef['file']['name'] ?? null) : null,
		];

		foreach ($candidates as $candidate) {
			if (!is_string($candidate) || trim($candidate) === '') {
				continue;
			}

			$this->appendCandidateUserPath($paths, $candidate);
		}

		return array_values(array_unique($paths));
	}

	/**
	 * @param array<int,string> $paths
	 */
	private function appendCandidateUserPath(array &$paths, string $candidate): void {
		$normalized = str_replace('\\', '/', trim($candidate));
		$normalized = ltrim($normalized, '/');
		if ($normalized === '') {
			return;
		}

		if (preg_match('#^remote\.php/dav/files/[^/]+/(.+)$#', $normalized, $matches) === 1) {
			$normalized = $matches[1];
		}

		if (preg_match('#^files/([^/]+)/(.+)$#', $normalized, $matches) === 1) {
			$normalized = $matches[2];
		}

		if (!str_starts_with($normalized, 'Talk/')) {
			$paths[] = 'Talk/' . $normalized;
		}

		$paths[] = $normalized;
	}

	/**
	 * @throws Exception
	 */
	private function writeTempFile(string $displayName, string $content, ?string $mimeType): ResolvedAttachment {
		$tempPath = tempnam(sys_get_temp_dir(), 'educai_att_');
		if ($tempPath === false) {
			throw new Exception('Unable to allocate temporary file for attachment');
		}

		if (file_put_contents($tempPath, $content) === false) {
			throw new Exception('Unable to write temporary attachment file');
		}

		return new ResolvedAttachment($tempPath, $displayName, $mimeType);
	}
}
