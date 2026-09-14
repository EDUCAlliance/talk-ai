<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/** Preserve personal wiki locations across the former public/private builds. */
class WikiPathService {
	private BrandingService $brandingService;

	public function __construct(?BrandingService $brandingService = null) {
		$this->brandingService = $brandingService ?? new BrandingService();
	}

	public function getDefaultPath(Folder $userFolder, string $slug): string {
		$paths = array_map(static fn (string $root): string => $root . '/Personal Wikis/' . $slug, $this->getRoots());
		foreach ($paths as $path) {
			try {
				if ($userFolder->get($path) instanceof Folder) {
					return $path;
				}
			} catch (NotFoundException) {
				// Old private releases did not store a distribution marker. Reuse
				// their existing bot folder, without renaming or moving anything.
			}
		}
		return $paths[0];
	}

	public function normalizeRootPath(string $path): string {
		$path = trim(str_replace('\\', '/', $path));
		if ($path === '') {
			throw new Exception('Wiki root path is required.');
		}
		if (str_starts_with($path, '/')) {
			throw new Exception('Wiki root path must be relative.');
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
			throw new Exception('Wiki root path contains an invalid character.');
		}
		$path = trim($path, '/');
		$validPrefix = false;
		foreach ($this->getRoots() as $root) {
			$validPrefix = $validPrefix || str_starts_with($path, $root . '/');
		}
		if (!$validPrefix) {
			throw new Exception('Wiki root path must start with ' . $this->brandingService->getWikiRootFolder() . '/. Existing Talk AI/ and EDUC AI/ paths are also supported.');
		}
		if (strlen($path) > 512) {
			throw new Exception('Wiki root path is too long.');
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new Exception('Wiki root path must not contain empty, current, or parent segments.');
			}
			if (str_starts_with($segment, '.')) {
				throw new Exception('Wiki root path must not target hidden/internal folders.');
			}
		}
		return $path;
	}

	/** @return list<string> */
	private function getRoots(): array {
		return array_values(array_unique([
			$this->brandingService->getWikiRootFolder(),
			BrandingService::DEFAULT_WIKI_ROOT,
			BrandingService::LEGACY_EDUC_NAME,
		]));
	}
}
