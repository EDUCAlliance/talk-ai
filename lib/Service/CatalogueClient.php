<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
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

	public function __construct(
		IClientService $clientService,
		SettingsService $settingsService,
		LoggerInterface $logger,
	) {
		$this->clientService = $clientService;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
	}

	/**
	 * Check if the catalogue integration is enabled and configured
	 */
	public function isEnabled(): bool {
		$settings = $this->settingsService->getSettings();
		return (bool)$settings->getCatalogueEnabled() && trim($settings->getCatalogueApiEndpoint() ?? '') !== '';
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

		$endpoint = $this->getEndpoint();
		$url = $endpoint . '/course/list';

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
			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'query' => $params,
				'timeout' => 30,
				'headers' => [
					'Accept' => 'application/json',
				],
			]);

			$body = (string)$response->getBody();
			$data = json_decode($body, true);

			if (!is_array($data)) {
				throw new Exception('Invalid response from Catalogue API');
			}

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
				'url' => $url,
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

		$endpoint = $this->getEndpoint();
		$url = $endpoint . '/course/list';
		$state = $isPast ? 'CLOSED' : 'OPEN';

		try {
			$client = $this->clientService->newClient();
			$offset = 0;
			$limit = 200;
			$maxIterations = 10;

			for ($i = 0; $i < $maxIterations; $i++) {
				if (!$this->isEnabled()) {
					throw new Exception('Course Catalogue integration is not enabled');
				}
				$response = $client->get($url, [
					'query' => [
						'state' => $state,
						// Keep id for forward compatibility if API starts filtering by id.
						'id' => $courseId,
						'limit' => $limit,
						'offset' => $offset,
					],
					'timeout' => 30,
					'headers' => [
						'Accept' => 'application/json',
					],
				]);

				$body = (string)$response->getBody();
				$data = json_decode($body, true);

				if (!is_array($data)) {
					throw new Exception('Invalid response from Catalogue API');
				}

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
				'url' => $url,
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

		$endpoint = $this->getEndpoint();
		$url = $endpoint . '/filters';

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'timeout' => 30,
				'headers' => [
					'Accept' => 'application/json',
				],
			]);

			$body = (string)$response->getBody();
			$data = json_decode($body, true);

			if (!is_array($data)) {
				throw new Exception('Invalid response from Catalogue API');
			}

			return $data;
		} catch (Exception $e) {
			$this->logger->error('Catalogue API filters failed', [
				'url' => $url,
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

		$endpoint = $this->getEndpoint();
		$url = $endpoint . '/course/list';

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
			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'query' => $params,
				'timeout' => 30,
				'headers' => [
					'Accept' => 'application/json',
				],
			]);

			$body = (string)$response->getBody();
			$data = json_decode($body, true);

			if (!is_array($data)) {
				throw new Exception('Invalid response from Catalogue API');
			}

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
				'url' => $url,
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

		$endpoint = rtrim(trim($endpoint ?? $this->getEndpoint()), '/');
		if ($endpoint === '') {
			return null;
		}
		$url = $endpoint . '/version';

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'timeout' => 10,
				'headers' => [
					'Accept' => 'application/json',
				],
			]);

			$body = (string)$response->getBody();
			$data = json_decode($body, true);

			return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : null;
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
}
