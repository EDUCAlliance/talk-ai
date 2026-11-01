<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\OpenApi\ApiExecutionException;
use OCA\EducAI\OpenApi\CatalogueApiSource;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Client for the EDUC Alliance Course Catalogue API
 *
 * This service provides methods to search courses, get course details,
 * retrieve filter options, and fetch announcements from the catalogue.
 */
class CatalogueClient {
	private IClientService $clientService;
	private SettingsService $settingsService;
	private LoggerInterface $logger;
	private ?CatalogueApiSource $apiSource;

	public function __construct(
		IClientService $clientService,
		SettingsService $settingsService,
		LoggerInterface $logger,
		?CatalogueApiSource $apiSource = null,
	) {
		$this->clientService = $clientService;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
		$this->apiSource = $apiSource;
	}

	/**
	 * Check if the catalogue integration is enabled and configured
	 */
	public function isEnabled(): bool {
		$settings = $this->settingsService->getSettings();
		return (bool)$settings->getCatalogueEnabled()
			&& ($this->usesApiConnection() || trim($settings->getCatalogueApiEndpoint() ?? '') !== '');
	}

	/** Whether catalogue reads go through an approved API connection instead of the endpoint. */
	public function usesApiConnection(): bool {
		return $this->apiSource?->isSelected() === true;
	}

	/**
	 * Get the configured API endpoint
	 */
	public function getEndpoint(): string {
		$settings = $this->settingsService->getSettings();
		$endpoint = $settings->getCatalogueApiEndpoint() ?? '';
		return rtrim(trim($endpoint), '/');
	}

	/**
	 * Search for courses with optional filters
	 *
	 * @param string|null $query Search query text
	 * @param array $filters Optional filters (learning_type_id, discipline_id, target_group, etc.)
	 * @param int $limit Number of courses to return
	 * @param int $offset Pagination offset
	 * @return array{courses: array, more: int}
	 * @throws Exception
	 */
	public function searchCourses(
		?string $query = null,
		array $filters = [],
		int $limit = 20,
		int $offset = 0,
	): array {
		if (!$this->isEnabled()) {
			throw new Exception('Course Catalogue integration is not enabled');
		}

		$params = [
			'state' => 'OPEN',
			'limit' => $limit,
			'offset' => $offset,
		];

		// Add search query if provided
		if ($query !== null && trim($query) !== '') {
			$trimmedQuery = trim($query);
			// Check if query is a numeric ID (with optional @ prefix)
			if (preg_match('/^@?(\d+)$/', $trimmedQuery, $matches)) {
				// For ID lookups, use the id parameter
				$params['id'] = (int)$matches[1];
			} else {
				// For text search, use the search parameter
				$params['search'] = $trimmedQuery;
			}
		}

		// Add filter parameters
		$filterMapping = [
			'learning_type_id' => 'learning_type_id',
			'discipline_id' => 'discipline_id',
			'target_group' => 'target_group',
			'mobility_format_id' => 'mobility_format_id',
			'semester_id' => 'semester_id',
			'language_id' => 'language_id',
			'lead_university_id' => 'lead_university_id',
		];

		foreach ($filterMapping as $key => $param) {
			if (isset($filters[$key])) {
				$value = $filters[$key];
				if (is_array($value)) {
					$params[$param] = implode(',', array_map('intval', $value));
				} else {
					$params[$param] = (string)$value;
				}
			}
		}

		try {
			$data = $this->fetch('/course/list', $params, 30);

			if (!isset($data['courses']) || !is_array($data['courses'])) {
				throw new Exception('Catalogue API response is missing its courses list');
			}
			$courses = $data['courses'];
			$more = $this->extractRemainingCount($data, $courses, $offset, 'more');

			return [
				'courses' => $courses,
				'more' => $more,
			];
		} catch (Exception $e) {
			$this->logger->error('Catalogue API search failed', [
				'url' => $this->target('/course/list'),
				'error' => $e->getMessage(),
			]);
			throw new Exception('Failed to search courses: ' . $e->getMessage());
		}
	}

	/**
	 * Get a specific course by ID
	 *
	 * @param int $courseId
	 * @param bool $isPast Whether to search in past opportunities
	 * @return array|null Course data or null if not found
	 * @throws Exception
	 */
	public function getCourseById(int $courseId, bool $isPast = false): ?array {
		if (!$this->isEnabled()) {
			throw new Exception('Course Catalogue integration is not enabled');
		}

		$state = $isPast ? 'CLOSED' : 'OPEN';

		try {
			$offset = 0;
			$limit = 200;
			$maxIterations = 10;

			for ($i = 0; $i < $maxIterations; $i++) {
				if (!$this->isEnabled()) {
					throw new Exception('Course Catalogue integration is not enabled');
				}
				$data = $this->fetch('/course/list', [
					'state' => $state,
					// Keep id for forward compatibility if API starts filtering by id.
					'id' => $courseId,
					'limit' => $limit,
					'offset' => $offset,
				], 30);

				$courses = is_array($data['courses'] ?? null) ? $data['courses'] : [];

				// Find the course with matching ID
				foreach ($courses as $course) {
					if (isset($course['id']) && (int)$course['id'] === $courseId) {
						// Add is_past flag to the returned data
						$course['is_past'] = $isPast;
						return $course;
					}
				}

				if (count($courses) < $limit) {
					break;
				}

				$remaining = $this->extractRemainingCount($data, $courses, $offset, $isPast ? 'morePast' : 'more');
				if ($remaining <= 0) {
					break;
				}

				$offset += count($courses);
			}

			return null;
		} catch (Exception $e) {
			$this->logger->error('Catalogue API get course failed', [
				'url' => $this->target('/course/list'),
				'course_id' => $courseId,
				'is_past' => $isPast,
				'error' => $e->getMessage(),
			]);
			throw new Exception('Failed to get course: ' . $e->getMessage());
		}
	}

	/**
	 * Get available filter options
	 *
	 * @return array Filter values including learning types, disciplines, etc.
	 * @throws Exception
	 */
	public function getFilterOptions(): array {
		if (!$this->isEnabled()) {
			throw new Exception('Course Catalogue integration is not enabled');
		}

		try {
			return $this->fetch('/filters', [], 30);
		} catch (Exception $e) {
			$this->logger->error('Catalogue API filters failed', [
				'url' => $this->target('/filters'),
				'error' => $e->getMessage(),
			]);
			throw new Exception('Failed to get filter options: ' . $e->getMessage());
		}
	}

	/**
	 * Get current announcements
	 *
	 * @return array List of announcements
	 * @throws Exception
	 */
	/**
	 * Get past courses
	 *
	 * @param array $filters Optional filters
	 * @param int $limit Number of courses to return
	 * @param int $offset Pagination offset
	 * @return array{courses: array, morePast: int}
	 * @throws Exception
	 */
	public function getPastCourses(array $filters = [], int $limit = 20, int $offset = 0): array {
		if (!$this->isEnabled()) {
			throw new Exception('Course Catalogue integration is not enabled');
		}

		$params = [
			'state' => 'CLOSED',
			'limit' => $limit,
			'offset' => $offset,
		];

		// Add filter parameters
		foreach (['learning_type_id', 'discipline_id', 'target_group', 'mobility_format_id', 'semester_id'] as $key) {
			if (isset($filters[$key])) {
				$value = $filters[$key];
				if (is_array($value)) {
					$params[$key] = implode(',', array_map('intval', $value));
				} else {
					$params[$key] = (string)$value;
				}
			}
		}

		try {
			$data = $this->fetch('/course/list', $params, 30);

			if (!isset($data['courses']) || !is_array($data['courses'])) {
				throw new Exception('Catalogue API response is missing its courses list');
			}
			$courses = $data['courses'];
			$morePast = $this->extractRemainingCount($data, $courses, $offset, 'morePast');

			return [
				'courses' => $courses,
				'morePast' => $morePast,
			];
		} catch (Exception $e) {
			$this->logger->error('Catalogue API past courses failed', [
				'url' => $this->target('/course/list'),
				'error' => $e->getMessage(),
			]);
			throw new Exception('Failed to get past courses: ' . $e->getMessage());
		}
	}

	/**
	 * Support both legacy ("more"/"morePast") and new ("count") pagination responses.
	 *
	 * @param array<string,mixed> $data
	 * @param array<int,mixed> $courses
	 */
	private function extractRemainingCount(array $data, array $courses, int $offset, string $legacyKey): int {
		if (isset($data[$legacyKey]) && is_numeric($data[$legacyKey])) {
			return max(0, (int)$data[$legacyKey]);
		}

		if (isset($data['count']) && is_numeric($data['count'])) {
			return max(0, (int)$data['count'] - ($offset + count($courses)));
		}

		return 0;
	}

	/**
	 * Get API version
	 *
	 * @return string|null API version string
	 */
	public function getVersion(?string $endpoint = null): ?string {
		if ($endpoint === null && !$this->isEnabled()) {
			return null;
		}

		if ($endpoint !== null || !$this->usesApiConnection()) {
			$endpoint = rtrim(trim($endpoint ?? $this->getEndpoint()), '/');
			if ($endpoint === '') {
				return null;
			}
		}

		try {
			$data = $this->fetch('/version', [], 10, $endpoint);

			return is_string($data['version'] ?? null) ? $data['version'] : null;
		} catch (Exception $e) {
			$this->logger->debug('Catalogue API version check failed', [
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Test the connection to the catalogue API
	 *
	 * @return array{success: bool, version: ?string, error: ?string}
	 */
	public function testConnection(?string $endpoint = null): array {
		if ($endpoint === null && !$this->isEnabled()) {
			return [
				'success' => false,
				'version' => null,
				'error' => 'Catalogue integration is not enabled',
			];
		}

		$version = $this->getVersion($endpoint);
		if ($version === null || trim($version) === '') {
			return [
				'success' => false,
				'version' => null,
				'error' => 'Catalogue API version endpoint is unavailable or returned no version',
			];
		}

		return [
			'success' => true,
			'version' => $version,
			'error' => null,
		];
	}

	/** @return array{fingerprint: ?string, summary: ?string} */
	public function connectionCheckTarget(?string $endpoint = null): array {
		try {
			$resolved = $endpoint === null && $this->usesApiConnection()
				? 'api-connection:' . (int)$this->apiSource?->selectedConnectionId()
				: rtrim(trim($endpoint ?? $this->getEndpoint()), '/');
		} catch (\Throwable $e) {
			return ['fingerprint' => null, 'summary' => null];
		}

		return [
			'fingerprint' => ConnectionCheckStore::fingerprint('catalogue', [$resolved], null),
			'summary' => null,
		];
	}

	/**
	 * One catalogue read through the selected source. An explicit endpoint
	 * (an unsaved form value) is always read directly.
	 *
	 * @param array<string,scalar> $query
	 * @return array<mixed>
	 * @throws Exception
	 */
	private function fetch(string $path, array $query, int $timeout, ?string $endpoint = null): array {
		if ($endpoint === null && $this->apiSource !== null && $this->apiSource->isSelected()) {
			try {
				return $this->apiSource->get($path, $query, (float)$timeout);
			} catch (ApiExecutionException $e) {
				$reason = $e->getDetails()['reason'] ?? null;
				throw new Exception($e->getMessage() . ' [' . (is_string($reason) ? $reason : $e->getErrorCode()) . ']', 0, $e);
			}
		}

		$options = [
			'timeout' => $timeout,
			'headers' => [
				'Accept' => 'application/json',
			],
		];
		if ($query !== []) {
			$options['query'] = $query;
		}
		$response = IdentityHttpClient::fromService($this->clientService)->get(($endpoint ?? $this->getEndpoint()) . $path, $options);
		$data = json_decode((string)$response->getBody(), true);
		if (!is_array($data)) {
			throw new Exception('Invalid response from Catalogue API');
		}
		return $data;
	}

	private function target(string $path): string {
		return $this->usesApiConnection()
			? 'api-connection:' . (int)$this->apiSource?->selectedConnectionId() . $path
			: $this->getEndpoint() . $path;
	}
}
