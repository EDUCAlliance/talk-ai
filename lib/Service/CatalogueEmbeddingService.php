<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\CatalogueEmbedding;
use OCA\EducAI\Db\CatalogueEmbeddingMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for semantic search of EDUC catalogue courses using embeddings
 */
class CatalogueEmbeddingService {
	private const STATUS_KEY = 'catalogue_reindex_status';

	private CatalogueClient $catalogueClient;
	private CatalogueEmbeddingMapper $mapper;
	private EmbeddingClient $embeddingClient;
	private SettingsService $settingsService;
	private LoggerInterface $logger;
	private IConfig $config;

	public function __construct(
		CatalogueClient $catalogueClient,
		CatalogueEmbeddingMapper $mapper,
		EmbeddingClient $embeddingClient,
		SettingsService $settingsService,
		LoggerInterface $logger,
		IConfig $config,
	) {
		$this->catalogueClient = $catalogueClient;
		$this->mapper = $mapper;
		$this->embeddingClient = $embeddingClient;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
		$this->config = $config;
	}

	/**
	 * Check if catalogue is enabled and properly configured
	 */
	public function isEnabled(): bool {
		return $this->catalogueClient->isEnabled();
	}

	/**
	 * Reindex all courses from the catalogue (both current and past)
	 *
	 * Fetch a complete snapshot before updating. Failed fetches and incomplete
	 * embedding batches must never turn an existing index into an empty one.
	 *
	 * @return array{success: bool, count: int, count_current: int, count_past: int, error: ?string}
	 */
	public function reindex(): array {
		if (!$this->isEnabled()) {
			return $this->reindexResult(0, 0, 'Catalogue integration is not enabled');
		}

		$startedAt = time();
		$this->saveStatus('running', $startedAt);
		$processedCurrent = 0;
		$processedPast = 0;

		try {
			$currentCourses = $this->fetchAllCourses(false);
			$pastCourses = $this->fetchAllCourses(true);
			$model = $this->embeddingClient->getActiveModel();
			$failed = 0;

			foreach ([false, true] as $isPast) {
				foreach (array_chunk($isPast ? $pastCourses : $currentCourses, 10) as $batch) {
					$this->requireEnabled();
					try {
						$this->processBatch($batch, $isPast, $model);
						if ($isPast) {
							$processedPast += count($batch);
						} else {
							$processedCurrent += count($batch);
						}
					} catch (Throwable $e) {
						$failed += count($batch);
						$this->logger->warning('Catalogue embedding batch failed', ['exception' => $e, 'is_past' => $isPast]);
					}
				}
			}

			$this->requireEnabled();
			if ($failed > 0) {
				$error = "$failed catalogue courses could not be indexed. Previous records were retained; retry to complete.";
				$this->saveStatus('failed', $startedAt, $error);
				return $this->reindexResult($processedCurrent, $processedPast, $error);
			}

			$this->mapper->deleteStale(array_column($currentCourses, 'id'), array_column($pastCourses, 'id'));
			$this->settingsService->updateCatalogueIndexStats($this->mapper->count());
			$this->saveStatus('completed', $startedAt);
			return $this->reindexResult($processedCurrent, $processedPast);
		} catch (Throwable $e) {
			$this->logger->error('Catalogue reindex failed', ['exception' => $e]);
			$error = 'Catalogue reindex failed. Check the server logs and retry.';
			$this->saveStatus('failed', $startedAt, $error);
			return $this->reindexResult($processedCurrent, $processedPast, $error);
		}
	}

	/** @return array{success:bool,count:int,count_current:int,count_past:int,error:?string} */
	private function reindexResult(int $current, int $past, ?string $error = null): array {
		return [
			'success' => $error === null,
			'count' => $current + $past,
			'count_current' => $current,
			'count_past' => $past,
			'error' => $error,
		];
	}

	private function saveStatus(string $state, int $startedAt, ?string $error = null): void {
		$this->config->setAppValue('educai', self::STATUS_KEY, json_encode([
			'state' => $state,
			'last_error' => $error,
			'started_at' => $startedAt,
			'finished_at' => $state === 'running' ? null : time(),
		], JSON_THROW_ON_ERROR));
	}

	private function requireEnabled(): void {
		if (!$this->isEnabled()) {
			throw new Exception('Catalogue integration was disabled');
		}
	}

	/**
	 * Semantic search for current (non-past) courses
	 *
	 * @param string $query Natural language query
	 * @param int $limit Maximum results
	 * @param float $minScore Minimum similarity score (0-1)
	 * @return array{courses: array, total: int}
	 */
	public function search(string $query, int $limit = 5, float $minScore = 0.3): array {
		return $this->doSearch($query, $limit, $minScore, false);
	}

	/**
	 * Semantic search for past courses
	 *
	 * @param string $query Natural language query
	 * @param int $limit Maximum results
	 * @param float $minScore Minimum similarity score (0-1)
	 * @return array{courses: array, total: int}
	 */
	public function searchPast(string $query, int $limit = 5, float $minScore = 0.3): array {
		return $this->doSearch($query, $limit, $minScore, true);
	}

	/**
	 * Get a course by ID from the local embeddings database
	 *
	 * This is more reliable than the external Catalogue API because the API's
	 * id parameter is broken and doesn't actually filter by course ID.
	 *
	 * @param int $courseId The course ID to look up
	 * @param bool $isPast Whether to look in past (true) or current (false) courses
	 * @return array<string,mixed>|null Course data or null if not found
	 */
	public function getCourseFromEmbeddings(int $courseId, bool $isPast): ?array {
		if (!$this->isEnabled()) {
			return null;
		}

		try {
			$embedding = $this->mapper->findByCourseIdAndPast($courseId, $isPast);
			$courseData = $embedding->getCourseDataArray();
			// Add is_past flag to the returned data
			$courseData['is_past'] = $isPast;
			return $courseData;
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Internal semantic search implementation
	 *
	 * @param string $query Natural language query
	 * @param int $limit Maximum results
	 * @param float $minScore Minimum similarity score (0-1)
	 * @param bool $isPast Whether to search past or current courses
	 * @return array{courses: array, total: int}
	 */
	private function doSearch(string $query, int $limit, float $minScore, bool $isPast): array {
		if (!$this->isEnabled()) {
			return ['courses' => [], 'total' => 0];
		}

		$query = trim($query);
		if ($query === '') {
			return ['courses' => [], 'total' => 0];
		}

		try {
			$embeddingModel = $this->embeddingClient->getActiveModel();

			// Generate embedding for query
			$queryVectors = $this->embeddingClient->embedTexts([$query], $embeddingModel);
			if (count($queryVectors) === 0) {
				throw new Exception('Failed to generate query embedding');
			}
			$queryVector = $queryVectors[0];

			// Compare vectors only inside the same embedding model space.
			$embeddings = $isPast
				? $this->mapper->findAllPastByModel($embeddingModel)
				: $this->mapper->findAllCurrentByModel($embeddingModel);
			$scored = [];

			foreach ($embeddings as $embedding) {
				$vector = $embedding->getEmbeddingVector();
				if ($vector === null) {
					continue;
				}

				$score = $this->cosineSimilarity($queryVector, $vector);
				if ($score >= $minScore) {
					$scored[] = [
						'embedding' => $embedding,
						'score' => $score,
					];
				}
			}

			// Sort by score descending
			usort($scored, static function (array $a, array $b): int {
				return $b['score'] <=> $a['score'];
			});

			// Get top results
			$total = count($scored);
			$scored = array_slice($scored, 0, $limit);

			// Build course results
			$courses = [];
			foreach ($scored as $item) {
				$courseData = $item['embedding']->getCourseDataArray();
				$courseData['_score'] = round($item['score'], 3);
				$courses[] = $courseData;
			}

			return [
				'courses' => $courses,
				'total' => $total,
			];
		} catch (Exception $e) {
			$this->logger->error('Catalogue semantic search failed', [
				'query' => $query,
				'is_past' => $isPast,
				'error' => $e->getMessage(),
			]);
			return ['courses' => [], 'total' => 0];
		}
	}

	/**
	 * Get index statistics
	 *
	 * This reads local state only, including when the integration is disabled.
	 *
	 * @return array{count:int,last_indexed:?int,needs_reindex:bool,state:string,last_error:?string,started_at:?int,finished_at:?int}
	 */
	public function getStats(): array {
		$count = $this->mapper->count();
		$settings = $this->settingsService->getSettings();

		$lastIndexed = $settings->getCatalogueLastIndexed();
		$reindexHours = $settings->getCatalogueReindexHours() ?? 24;

		$status = json_decode($this->config->getAppValue('educai', self::STATUS_KEY, '{}'), true);
		$status = is_array($status) ? $status : [];
		$state = $status['state'] ?? ($lastIndexed !== null ? 'completed' : 'idle');
		$state = in_array($state, ['idle', 'running', 'completed', 'failed'], true) ? $state : 'idle';
		$needsReindex = $lastIndexed === null || $state === 'failed'
			|| (time() - $lastIndexed) > (max(1, $reindexHours) * 3600);
		if ($count > 0) {
			$model = $this->embeddingClient->getActiveModel();
			$forModel = $this->mapper->countCurrentByModel($model) + $this->mapper->countPastByModel($model);
			$needsReindex = $needsReindex || $forModel < $count;
		}

		return [
			'count' => $count,
			'last_indexed' => $lastIndexed,
			'needs_reindex' => $needsReindex,
			'state' => $state,
			'last_error' => is_string($status['last_error'] ?? null) ? $status['last_error'] : null,
			'started_at' => is_int($status['started_at'] ?? null) ? $status['started_at'] : null,
			'finished_at' => is_int($status['finished_at'] ?? null) ? $status['finished_at'] : null,
		];
	}

	/**
	 * Fetch the full current or archived snapshot. Incomplete pagination must not
	 * be mistaken for courses that have been removed from the catalogue.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchAllCourses(bool $isPast): array {
		$allCourses = [];
		$offset = 0;
		$limit = 500;
		$maxIterations = 10;

		for ($i = 0; $i < $maxIterations; $i++) {
			$this->requireEnabled();
			$result = $isPast
				? $this->catalogueClient->getPastCourses([], $limit, $offset)
				: $this->catalogueClient->searchCourses(null, [], $limit, $offset);
			$courses = $result['courses'] ?? [];
			$more = $result[$isPast ? 'morePast' : 'more'] ?? 0;
			if ($courses === [] && $more > 0) {
				throw new Exception('Catalogue pagination did not return the remaining courses');
			}

			foreach ($courses as $course) {
				if (!is_array($course) || !isset($course['id']) || !is_numeric($course['id']) || (int)$course['id'] <= 0) {
					throw new Exception('Catalogue returned an invalid course ID');
				}
				$course['id'] = (int)$course['id'];
				$allCourses[$course['id']] = $course;
			}
			$offset += count($courses);

			if ($more <= 0) {
				return array_values($allCourses);
			}
		}

		throw new Exception('Catalogue snapshot exceeds the pagination limit; previous records were retained');
	}

	/**
	 * Process a batch of courses - generate embeddings and store
	 *
	 * @param array<int,array<string,mixed>> $courses
	 * @param bool $isPast Whether these are past courses
	 */
	private function processBatch(array $courses, bool $isPast, string $embeddingModel): void {
		// Build searchable texts
		$texts = [];
		foreach ($courses as $course) {
			$texts[] = $this->buildSearchableText($course);
		}

		// Generate embeddings
		$this->requireEnabled();
		$embeddings = $this->embeddingClient->embedTexts($texts, $embeddingModel);
		if (count($embeddings) !== count($courses)) {
			throw new Exception('Catalogue embedding response was incomplete');
		}
		foreach ($embeddings as $vector) {
			if (!is_array($vector) || $vector === []) {
				throw new Exception('Catalogue embedding vector was empty');
			}
		}

		// Store in database
		$this->requireEnabled();
		$now = time();
		foreach ($courses as $index => $course) {
			$courseId = (int)($course['id'] ?? 0);
			if ($courseId <= 0) {
				continue;
			}

			$entity = new CatalogueEmbedding();
			$entity->setCourseId($courseId);
			$entity->setSearchableText($texts[$index]);
			$entity->setEmbedding(json_encode($embeddings[$index]) ?: '[]');
			$entity->setEmbeddingModel($embeddingModel);
			$entity->setCourseData(json_encode($course) ?: '{}');
			$entity->setIsPast($isPast ? 1 : 0);
			$entity->setCreatedAt($now);
			$entity->setUpdatedAt($now);

			$this->mapper->upsert($entity);
		}
	}

	/**
	 * Build searchable text from course data
	 *
	 * Combines multiple fields for comprehensive semantic search
	 */
	private function buildSearchableText(array $course): string {
		$parts = [];

		// Title is most important
		if (!empty($course['title'])) {
			$parts[] = 'Title: ' . $course['title'];
		}

		// Course code
		if (!empty($course['code'])) {
			$parts[] = 'Code: ' . $course['code'];
		}

		// Summary
		if (!empty($course['summary'])) {
			$parts[] = 'Summary: ' . $this->truncate($course['summary'], 500);
		}

		// Description (truncated)
		if (!empty($course['description'])) {
			$parts[] = 'Description: ' . $this->truncate($course['description'], 1000);
		}

		// Discipline
		if (!empty($course['discipline']['name'])) {
			$parts[] = 'Discipline: ' . $course['discipline']['name'];
		}

		// Learning type (Course, Internship, Summer School, etc.)
		if (!empty($course['learningType']['name'])) {
			$parts[] = 'Type: ' . $course['learningType']['name'];
		}

		// Mobility format (Virtual, Physical, Blended)
		if (!empty($course['mobilityFormat']['name'])) {
			$parts[] = 'Format: ' . $course['mobilityFormat']['name'];
		}

		// University
		if (!empty($course['leadUniversity']['name'])) {
			$parts[] = 'University: ' . $course['leadUniversity']['name'];
		}

		// Language
		if (!empty($course['language']['name'])) {
			$parts[] = 'Language: ' . $course['language']['name'];
		}

		// Target levels (Bachelor, Master, PhD, etc.)
		if (!empty($course['levels']) && is_array($course['levels'])) {
			$levelNames = [];
			foreach ($course['levels'] as $level) {
				if (!empty($level['name'])) {
					$levelNames[] = $level['name'];
				}
			}
			if (count($levelNames) > 0) {
				$parts[] = 'Target audience: ' . implode(', ', $levelNames);
			}
		}

		// ECTS
		if (!empty($course['ects'])) {
			$parts[] = 'ECTS: ' . $course['ects'];
		}

		// Semester
		if (!empty($course['semester']['name'])) {
			$parts[] = 'Semester: ' . $course['semester']['name'];
		}

		// Keywords/tags if present
		if (!empty($course['keywords'])) {
			$parts[] = 'Keywords: ' . $course['keywords'];
		}

		return implode("\n", $parts);
	}

	/**
	 * Get coverage of embeddings for the currently active model.
	 *
	 * @return array{model:string,total:int,for_model:int}
	 */
	public function getModelCoverage(bool $isPast): array {
		$model = $this->embeddingClient->getActiveModel();
		if ($isPast) {
			return [
				'model' => $model,
				'total' => $this->mapper->countPast(),
				'for_model' => $this->mapper->countPastByModel($model),
			];
		}

		return [
			'model' => $model,
			'total' => $this->mapper->countCurrent(),
			'for_model' => $this->mapper->countCurrentByModel($model),
		];
	}

	/**
	 * Calculate cosine similarity between two vectors
	 *
	 * @param array<int,float> $a
	 * @param array<int,float> $b
	 */
	private function cosineSimilarity(array $a, array $b): float {
		$length = count($a);
		if ($length === 0 || $length !== count($b)) {
			return 0.0;
		}

		$dot = 0.0;
		$magA = 0.0;
		$magB = 0.0;

		for ($i = 0; $i < $length; $i++) {
			$dot += $a[$i] * $b[$i];
			$magA += $a[$i] * $a[$i];
			$magB += $b[$i] * $b[$i];
		}

		if ($magA <= 0.0 || $magB <= 0.0) {
			return 0.0;
		}

		return $dot / (sqrt($magA) * sqrt($magB));
	}

	/**
	 * Truncate text to maximum length
	 */
	private function truncate(string $text, int $maxLength): string {
		$text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
		if (mb_strlen($text, 'UTF-8') <= $maxLength) {
			return $text;
		}
		return mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...';
	}

}
