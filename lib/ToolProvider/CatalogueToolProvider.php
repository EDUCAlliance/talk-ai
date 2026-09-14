<?php

declare(strict_types=1);

namespace OCA\EducAI\ToolProvider;

use Exception;
use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\ToolExecutionPolicyService;
use Psr\Log\LoggerInterface;

/**
 * Optional EDUC course-catalogue tools, contributed via the tool-provider
 * extension point ({@see IToolProvider} / {@see CollectToolProvidersEvent}).
 *
 * The generic tool-provider interface keeps catalogue availability independent
 * of the app's generic knowledge tools and administration.
 */
class CatalogueToolProvider implements IToolProvider {
	public const TOOL_CATALOGUE_SEARCH = 'catalogue_search';
	public const TOOL_CATALOGUE_PAST_SEARCH = 'catalogue_past_search';
	public const TOOL_CATALOGUE_DETAILS = 'catalogue_get_opportunity';

	/** Legacy alias kept for old bot loadouts created before the tool rename. */
	public const LEGACY_TOOL_CATALOGUE_SEARCH = 'catalogue_search_courses';

	private CatalogueEmbeddingService $catalogueEmbeddingService;
	private ToolExecutionPolicyService $toolExecutionPolicyService;
	private LoggerInterface $logger;

	public function __construct(
		CatalogueEmbeddingService $catalogueEmbeddingService,
		ToolExecutionPolicyService $toolExecutionPolicyService,
		LoggerInterface $logger,
	) {
		$this->catalogueEmbeddingService = $catalogueEmbeddingService;
		$this->toolExecutionPolicyService = $toolExecutionPolicyService;
		$this->logger = $logger;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getTools(): array {
		$tools = [];

		if (!$this->catalogueEmbeddingService->isEnabled()) {
			return $tools;
		}

		$tools[] = $this->withPolicy([
			'name' => self::TOOL_CATALOGUE_SEARCH,
			'aliases' => [self::LEGACY_TOOL_CATALOGUE_SEARCH],
			'description' => 'Search the EDUC Alliance Course Catalogue for CURRENT learning opportunities using natural language. Finds courses, internships, summer schools, and other learning opportunities that are currently available or upcoming. Uses semantic search to understand meaning, not just keywords.',
			'schema' => [
				'type' => 'object',
				'properties' => [
					'query' => [
						'type' => 'string',
						'description' => 'Natural language search query. Examples: "computer science internships in Germany", "AI courses for PhD students", "virtual summer schools about sustainability", "language courses in French".',
					],
					'limit' => [
						'type' => 'integer',
						'description' => 'Maximum number of results to return (default: 5, max: 20)',
						'default' => 5,
					],
				],
				'required' => ['query'],
			],
		], ToolExecutionPolicyService::KIND_SEARCH);

		$tools[] = $this->withPolicy([
			'name' => self::TOOL_CATALOGUE_PAST_SEARCH,
			'description' => 'Search the EDUC Alliance Course Catalogue for PAST/ARCHIVED learning opportunities using natural language. Use this to find courses, internships, summer schools that have already ended. Useful for finding historical offerings that may be repeated, or for research purposes.',
			'schema' => [
				'type' => 'object',
				'properties' => [
					'query' => [
						'type' => 'string',
						'description' => 'Natural language search query. Examples: "past AI summer schools", "previous chemistry internships", "archived language courses".',
					],
					'limit' => [
						'type' => 'integer',
						'description' => 'Maximum number of results to return (default: 5, max: 20)',
						'default' => 5,
					],
				],
				'required' => ['query'],
			],
		], ToolExecutionPolicyService::KIND_SEARCH);

		$tools[] = $this->withPolicy([
			'name' => self::TOOL_CATALOGUE_DETAILS,
			'description' => 'Get detailed information about a specific learning opportunity from the EDUC Alliance Catalogue by its exact opportunity_id from catalogue search results. Do not infer IDs from years, course numbers, or free text. Works for both current and past opportunities.',
			'schema' => [
				'type' => 'object',
				'properties' => [
					'opportunity_id' => [
						'type' => 'integer',
						'description' => 'The exact unique numeric ID of the learning opportunity from catalogue_search or catalogue_past_search results (e.g., 292, 150, 42).',
					],
					'is_past' => [
						'type' => 'boolean',
						'description' => 'Set to true to fetch a past/archived opportunity (from catalogue_past_search results). Defaults to false for current opportunities.',
						'default' => false,
					],
				],
				'required' => ['opportunity_id'],
			],
		], ToolExecutionPolicyService::KIND_READ);

		return $tools;
	}

	public function providesTool(string $toolName): bool {
		return in_array($toolName, [
			self::TOOL_CATALOGUE_SEARCH,
			self::TOOL_CATALOGUE_PAST_SEARCH,
			self::TOOL_CATALOGUE_DETAILS,
			self::LEGACY_TOOL_CATALOGUE_SEARCH,
		], true);
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @param array<string,mixed> $config
	 * @return array{content:array<int,array{type:string,text:string}>,isError:bool}
	 */
	public function executeTool(string $toolName, array $arguments, array $config = []): array {
		// A saved bot assignment must not bypass a subsequently disabled integration.
		if (!$this->catalogueEmbeddingService->isEnabled()) {
			return [
				'content' => [['type' => 'text', 'text' => 'Catalogue integration is not enabled.']],
				'isError' => true,
			];
		}

		$this->logger->info('Executing catalogue tool', [
			'tool' => $toolName,
			'argument_keys' => array_keys($arguments),
		]);

		switch ($toolName) {
			case self::TOOL_CATALOGUE_SEARCH:
			case self::LEGACY_TOOL_CATALOGUE_SEARCH:
				return $this->executeCatalogueSearch($arguments);

			case self::TOOL_CATALOGUE_PAST_SEARCH:
				return $this->executeCataloguePastSearch($arguments);

			case self::TOOL_CATALOGUE_DETAILS:
				return $this->executeGetCourse($arguments);

			default:
				throw new Exception("Unknown catalogue tool: $toolName");
		}
	}

	/**
	 * @return array<string,array{label?:string,summary?:string}>
	 */
	public function getToolMetadata(): array {
		return [
			self::TOOL_CATALOGUE_SEARCH => [
				'label' => 'Course Catalogue Search',
				'summary' => 'Search the course catalogue for current learning opportunities.',
			],
			self::TOOL_CATALOGUE_PAST_SEARCH => [
				'label' => 'Course Catalogue Archive Search',
				'summary' => 'Search archived catalogue opportunities.',
			],
			self::TOOL_CATALOGUE_DETAILS => [
				'label' => 'Course Catalogue Details',
				'summary' => 'Get detailed catalogue opportunity information.',
			],
			self::LEGACY_TOOL_CATALOGUE_SEARCH => [
				'label' => 'Course Catalogue Search',
				'summary' => 'Search the course catalogue for training programs and learning content.',
			],
		];
	}

	public function setInvocationContext(?array $context): void {
		// Catalogue tools are stateless - no invocation context needed.
	}

	/**
	 * @param array<string,mixed> $tool
	 * @return array<string,mixed>
	 */
	private function withPolicy(array $tool, string $kind): array {
		$tool['policy'] = $kind === ToolExecutionPolicyService::KIND_SEARCH
			? $this->toolExecutionPolicyService->searchToolPolicy('provider')
			: $this->toolExecutionPolicyService->readToolPolicy('provider');
		$metadata = $this->getToolMetadata()[$tool['name']] ?? [];
		return array_merge($tool, $metadata);
	}


	/**
	 * Execute semantic catalogue search
	 */
	private function executeCatalogueSearch(array $arguments): array {
		$query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';

		if ($query === '') {
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => 'Error: query parameter is required for catalogue search. Please provide a natural language query describing what you\'re looking for.',
					],
				],
				'isError' => true,
			];
		}

		$limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
			? min(20, max(1, (int)$arguments['limit']))
			: 5;

		try {
			$result = $this->catalogueEmbeddingService->search($query, $limit);
			$courses = $result['courses'];
			$total = $result['total'];

			if (count($courses) === 0) {
				// Check if index is empty
				$stats = $this->catalogueEmbeddingService->getStats();
				if ($stats['count'] === 0) {
					return [
						'content' => [
							[
								'type' => 'text',
								'text' => 'The catalogue index is empty. Please wait for the next scheduled reindex or ask an administrator to trigger a manual reindex.',
							],
						],
						'isError' => false,
					];
				}

				$coverage = $this->catalogueEmbeddingService->getModelCoverage(false);
				if ($coverage['total'] > 0 && $coverage['for_model'] === 0) {
					return [
						'content' => [
							[
								'type' => 'text',
								'text' => "Catalogue index exists, but there are no vectors for the active embedding model \"{$coverage['model']}\". Please run a full catalogue reindex after changing embedding settings.",
							],
						],
						'isError' => false,
					];
				}

				return [
					'content' => [
						[
							'type' => 'text',
							'text' => "No learning opportunities found matching: \"$query\"\n\nTry different search terms like:\n- More specific: \"web development course\", \"AI internship\", \"data science\"\n- More general: \"computer science\", \"summer school\", \"virtual course\"",
						],
					],
					'isError' => false,
				];
			}

			$text = $this->buildCatalogueSearchResultText($courses, $query, $total - count($courses));

			$this->logger->info('Catalogue semantic search completed', [
				'query' => $query,
				'results' => count($courses),
				'total' => $total,
			]);

			return [
				'content' => [
					[
						'type' => 'text',
						'text' => $text,
					],
				],
				'isError' => false,
			];
		} catch (Exception $e) {
			$this->logger->error('Catalogue search failed', ['error' => $e->getMessage()]);
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => 'Error searching catalogue: ' . $e->getMessage(),
					],
				],
				'isError' => true,
			];
		}
	}

	/**
	 * Execute semantic catalogue search for PAST opportunities
	 */
	private function executeCataloguePastSearch(array $arguments): array {
		$query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';

		if ($query === '') {
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => 'Error: query parameter is required for past catalogue search. Please provide a natural language query describing what past opportunities you\'re looking for.',
					],
				],
				'isError' => true,
			];
		}

		$limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
			? min(20, max(1, (int)$arguments['limit']))
			: 5;

		try {
			$result = $this->catalogueEmbeddingService->searchPast($query, $limit);
			$courses = $result['courses'];
			$total = $result['total'];

			if (count($courses) === 0) {
				// Check if index is empty
				$stats = $this->catalogueEmbeddingService->getStats();
				if ($stats['count'] === 0) {
					return [
						'content' => [
							[
								'type' => 'text',
								'text' => 'The catalogue index is empty. Please wait for the next scheduled reindex or ask an administrator to trigger a manual reindex.',
							],
						],
						'isError' => false,
					];
				}

				$coverage = $this->catalogueEmbeddingService->getModelCoverage(true);
				if ($coverage['total'] > 0 && $coverage['for_model'] === 0) {
					return [
						'content' => [
							[
								'type' => 'text',
								'text' => "Past catalogue index exists, but there are no vectors for the active embedding model \"{$coverage['model']}\". Please run a full catalogue reindex after changing embedding settings.",
							],
						],
						'isError' => false,
					];
				}

				return [
					'content' => [
						[
							'type' => 'text',
							'text' => "No past learning opportunities found matching: \"$query\"\n\nTry different search terms or check current opportunities with the catalogue_search tool.",
						],
					],
					'isError' => false,
				];
			}

			$text = $this->buildCataloguePastSearchResultText($courses, $query, $total - count($courses));

			$this->logger->info('Catalogue past semantic search completed', [
				'query' => $query,
				'results' => count($courses),
				'total' => $total,
			]);

			return [
				'content' => [
					[
						'type' => 'text',
						'text' => $text,
					],
				],
				'isError' => false,
			];
		} catch (Exception $e) {
			$this->logger->error('Catalogue past search failed', ['error' => $e->getMessage()]);
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => 'Error searching past catalogue: ' . $e->getMessage(),
					],
				],
				'isError' => true,
			];
		}
	}

	/**
	 * Build human-readable catalogue search results
	 *
	 * @param array<int,array<string,mixed>> $courses
	 */
	private function buildCatalogueSearchResultText(array $courses, string $query, int $moreAvailable): string {
		$text = 'Found ' . count($courses) . " learning opportunity(ies) for: \"$query\"";
		if ($moreAvailable > 0) {
			$text .= " ($moreAvailable more available - increase limit to see more)";
		}
		$text .= "\n\n";

		foreach ($courses as $index => $course) {
			$score = $course['_score'] ?? null;
			$title = $course['title'] ?? 'Untitled';
			$id = $course['id'] ?? null;

			$text .= ($index + 1) . '. **' . $title . '**';
			if ($id) {
				$text .= ' (ID: ' . $id . ')';
			}
			if ($score !== null) {
				$text .= sprintf(' [relevance: %.2f]', $score);
			}
			$text .= "\n";

			// Summary
			if (!empty($course['summary'])) {
				$text .= '   ' . $this->truncateText($course['summary'], 200) . "\n";
			}

			// Type and format info
			$typeInfo = [];
			if (!empty($course['learningType']['name'])) {
				$typeInfo[] = $course['learningType']['name'];
			}
			if (!empty($course['mobilityFormat']['name'])) {
				$typeInfo[] = $course['mobilityFormat']['name'];
			}
			if (!empty($course['mode']['name'])) {
				$typeInfo[] = $course['mode']['name'];
			}
			if (!empty($typeInfo)) {
				$text .= '   📋 ' . implode(' • ', $typeInfo) . "\n";
			}

			// Academic details
			$details = [];
			if (!empty($course['discipline']['name'])) {
				$details[] = $course['discipline']['name'];
			}
			if (!empty($course['leadUniversity']['name'])) {
				$details[] = $course['leadUniversity']['name'];
			}
			if (!empty($course['language']['name'])) {
				$langInfo = $course['language']['name'];
				if (!empty($course['languageLevel']['name'])) {
					$langInfo .= " ({$course['languageLevel']['name']})";
				}
				$details[] = $langInfo;
			}
			if (!empty($course['ects'])) {
				$details[] = $course['ects'] . ' ECTS';
			}
			if (!empty($details)) {
				$text .= '   🎓 ' . implode(' | ', $details) . "\n";
			}

			// Target audience and availability
			$availability = [];
			if (!empty($course['levels']) && is_array($course['levels'])) {
				$levelNames = [];
				foreach ($course['levels'] as $level) {
					if (!empty($level['short_name'])) {
						$levelNames[] = $level['short_name'];
					} elseif (!empty($level['name'])) {
						$levelNames[] = $level['name'];
					}
				}
				if (!empty($levelNames)) {
					$availability[] = 'For: ' . implode(', ', $levelNames);
				}
			}
			if (!empty($course['application_deadline'])) {
				$availability[] = 'Deadline: ' . $course['application_deadline'];
			} elseif (!empty($course['permanent']) && $course['permanent']) {
				$availability[] = 'Always open';
			}
			if (!empty($availability)) {
				$text .= '   📅 ' . implode(' | ', $availability) . "\n";
			}

			$text .= "\n";
		}

		$text .= '💡 Use catalogue_get_opportunity with the opportunity_id to get full details including description, schedule, prerequisites, and application links.';

		return $text;
	}

	/**
	 * Build human-readable catalogue PAST search results
	 *
	 * @param array<int,array<string,mixed>> $courses
	 */
	private function buildCataloguePastSearchResultText(array $courses, string $query, int $moreAvailable): string {
		$text = 'Found ' . count($courses) . " PAST learning opportunity(ies) for: \"$query\"";
		if ($moreAvailable > 0) {
			$text .= " ($moreAvailable more available - increase limit to see more)";
		}
		$text .= "\n\n";
		$text .= "⚠️ Note: These opportunities have ended. They may be offered again in the future.\n\n";

		foreach ($courses as $index => $course) {
			$score = $course['_score'] ?? null;
			$title = $course['title'] ?? 'Untitled';
			$id = $course['id'] ?? null;

			$text .= ($index + 1) . '. **' . $title . '** (PAST)';
			if ($id) {
				$text .= ' (ID: ' . $id . ')';
			}
			if ($score !== null) {
				$text .= sprintf(' [relevance: %.2f]', $score);
			}
			$text .= "\n";

			// Summary
			if (!empty($course['summary'])) {
				$text .= '   ' . $this->truncateText($course['summary'], 200) . "\n";
			}

			// Type and format info
			$typeInfo = [];
			if (!empty($course['learningType']['name'])) {
				$typeInfo[] = $course['learningType']['name'];
			}
			if (!empty($course['mobilityFormat']['name'])) {
				$typeInfo[] = $course['mobilityFormat']['name'];
			}
			if (!empty($course['mode']['name'])) {
				$typeInfo[] = $course['mode']['name'];
			}
			if (!empty($typeInfo)) {
				$text .= '   📋 ' . implode(' • ', $typeInfo) . "\n";
			}

			// Academic details
			$details = [];
			if (!empty($course['discipline']['name'])) {
				$details[] = $course['discipline']['name'];
			}
			if (!empty($course['leadUniversity']['name'])) {
				$details[] = $course['leadUniversity']['name'];
			}
			if (!empty($course['language']['name'])) {
				$langInfo = $course['language']['name'];
				if (!empty($course['languageLevel']['name'])) {
					$langInfo .= " ({$course['languageLevel']['name']})";
				}
				$details[] = $langInfo;
			}
			if (!empty($course['ects'])) {
				$details[] = $course['ects'] . ' ECTS';
			}
			if (!empty($details)) {
				$text .= '   🎓 ' . implode(' | ', $details) . "\n";
			}

			// Target audience
			if (!empty($course['levels']) && is_array($course['levels'])) {
				$levelNames = [];
				foreach ($course['levels'] as $level) {
					if (!empty($level['short_name'])) {
						$levelNames[] = $level['short_name'];
					} elseif (!empty($level['name'])) {
						$levelNames[] = $level['name'];
					}
				}
				if (!empty($levelNames)) {
					$text .= '   👥 For: ' . implode(', ', $levelNames) . "\n";
				}
			}

			$text .= "\n";
		}

		$text .= '💡 Use catalogue_get_opportunity with the opportunity_id to get full details. Use catalogue_search to find current opportunities.';

		return $text;
	}

	/**
	 * Get details for a specific learning opportunity
	 */
	private function executeGetCourse(array $arguments): array {
		// Support both opportunity_id (preferred) and course_id (legacy) for compatibility
		$idValue = $arguments['opportunity_id'] ?? $arguments['course_id'] ?? null;

		if ($idValue === null || !is_numeric($idValue)) {
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => 'Error: opportunity_id is required and must be a number (e.g., 292)',
					],
				],
				'isError' => true,
			];
		}

		$courseId = (int)$idValue;
		// Handle is_past properly - LLMs often send strings like "false" which (bool)"false" = true!
		$isPastArg = $arguments['is_past'] ?? false;
		$isPast = filter_var($isPastArg, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

		$this->logger->info('Catalogue get opportunity called', [
			'argument_keys' => array_keys($arguments),
			'parsed_id' => $courseId,
			'is_past' => $isPast,
		]);

		try {
			// First try to get from local embeddings database (faster and more reliable)
			// The external Catalogue API's id parameter is broken and doesn't filter correctly
			$course = $this->catalogueEmbeddingService->getCourseFromEmbeddings($courseId, $isPast);

			if ($course === null && !isset($arguments['is_past'])) {
				// If not found and is_past wasn't specified, try the other type
				$this->logger->info('Opportunity not found in embeddings, trying opposite is_past', [
					'id' => $courseId,
					'tried_past' => $isPast,
				]);
				$course = $this->catalogueEmbeddingService->getCourseFromEmbeddings($courseId, !$isPast);
			}

			if ($course === null) {
				return [
					'content' => [
						[
							'type' => 'text',
							'text' => "No opportunity found with ID: $courseId (searched both current and past in local index). The catalogue may need to be reindexed.",
						],
					],
					'isError' => false,
				];
			}

			return [
				'content' => [
					[
						'type' => 'text',
						'text' => $this->buildCourseDetailText($course),
					],
				],
				'isError' => false,
			];
		} catch (Exception $e) {
			$this->logger->error('Catalogue get course failed', ['error' => $e->getMessage()]);
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => 'Error retrieving opportunity: ' . $e->getMessage(),
					],
				],
				'isError' => true,
			];
		}
	}

	/**
	 * Get announcements
	 */
	/**
	 * Build detailed course information text
	 */
	private function buildCourseDetailText(array $course): string {
		$title = $course['title'] ?? 'Untitled Course';
		$isPast = $course['is_past'] ?? false;
		$statusBadge = $isPast ? '⏳ PAST/ARCHIVED' : '✅ CURRENT';

		$text = '# ' . $title . "\n\n";
		$text .= '**Status:** ' . $statusBadge . "\n";

		if (!empty($course['code'])) {
			$text .= '**Code:** ' . $course['code'] . "\n";
		}
		if (!empty($course['id'])) {
			$text .= '**ID:** ' . $course['id'] . "\n";
		}
		$text .= "\n";

		if (!empty($course['summary'])) {
			$text .= "## Summary\n" . $course['summary'] . "\n\n";
		}

		if (!empty($course['description'])) {
			$text .= "## Description\n" . $course['description'] . "\n\n";
		}

		if (!empty($course['details'])) {
			$text .= "## Schedule & Details\n" . $course['details'] . "\n\n";
		}

		$text .= "## Course Information\n";

		$infoItems = [
			'Discipline' => $course['discipline']['name'] ?? null,
			'Learning Type' => $course['learningType']['name'] ?? null,
			'Mobility Format' => $course['mobilityFormat']['name'] ?? null,
			'Mode' => $course['mode']['name'] ?? null,
			'Language' => $course['language']['name'] ?? null,
			'Language Level' => $course['languageLevel']['name'] ?? null,
			'Lead University' => $course['leadUniversity']['name'] ?? null,
			'Lead Teacher' => $course['leadTeacher']['name'] ?? null,
			'ECTS Credits' => $course['ects'] ?? null,
			'Duration' => $course['duration']['name'] ?? null,
			'Semester' => $course['semester']['name'] ?? null,
			'Platform' => $course['platform'] ?? null,
			'Always Available' => isset($course['permanent']) ? ($course['permanent'] ? 'Yes' : 'No') : null,
			'Application Deadline' => $course['application_deadline'] ?? null,
			'Capacity' => $course['capacity'] ?? null,
		];

		// Target levels
		if (!empty($course['levels']) && is_array($course['levels'])) {
			$levelNames = [];
			foreach ($course['levels'] as $level) {
				$levelNames[] = $level['name'] ?? $level['short_name'] ?? '';
			}
			$infoItems['Target Levels'] = implode(', ', array_filter($levelNames));
		}

		// Targeted universities
		if (!empty($course['targetedUniversities']) && is_array($course['targetedUniversities'])) {
			$uniNames = [];
			foreach ($course['targetedUniversities'] as $uni) {
				$uniNames[] = $uni['name'] ?? $uni['short_name'] ?? '';
			}
			$infoItems['Targeted Universities'] = implode(', ', array_filter($uniNames));
		}

		foreach ($infoItems as $label => $value) {
			if ($value !== null && $value !== '') {
				$text .= "- **$label:** $value\n";
			}
		}

		// Application link
		if (!empty($course['application_link'])) {
			$text .= "\n## Application\n";
			$text .= '**Application Link:** ' . $course['application_link'] . "\n";
		}

		// Additional fields
		if (!empty($course['prerequisites'])) {
			$text .= "\n## Prerequisites\n" . $course['prerequisites'] . "\n";
		}
		if (!empty($course['further_information'])) {
			$text .= "\n## Further Information\n" . $course['further_information'] . "\n";
		}
		if (!empty($course['application_contact'])) {
			$text .= "\n## Application Contact\n" . $course['application_contact'] . "\n";
		}
		if (!empty($course['info_contact'])) {
			$text .= "\n## Information Contact\n" . $course['info_contact'] . "\n";
		}

		return $text;
	}

	/**
	 * Truncate text to a maximum length
	 */
	private function truncateText(string $text, int $maxLength): string {
		$text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
		if (mb_strlen($text, 'UTF-8') <= $maxLength) {
			return $text;
		}
		return mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...';
	}
}
