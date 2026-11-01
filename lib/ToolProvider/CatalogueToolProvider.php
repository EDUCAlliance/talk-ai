<?php

declare(strict_types=1);

namespace OCA\EducAI\ToolProvider;

use Exception;
use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\CanonicalLinkResolver;
use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\CatalogueLinkGuard;
use OCA\EducAI\Service\CatalogueLinkService;
use OCA\EducAI\Service\CatalogueReferenceLedger;
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

	private const OUTPUT_LIMIT = AgentExecutor::TOOL_OUTPUT_LIMIT;
	private const SEARCH_SUMMARY_LENGTH = 200;
	private const TITLE_LENGTH = 200;
	private const LINK_INSTRUCTIONS = 'To link a course, copy its "Course page" URL exactly or write {{course:ID}}; never construct, shorten or alter catalogue links, and never present an application link as the course page. If no course page is available, say so.';

	private CatalogueEmbeddingService $catalogueEmbeddingService;
	private ToolExecutionPolicyService $toolExecutionPolicyService;
	private LoggerInterface $logger;
	private ?CatalogueLinkService $linkService;
	private ?CatalogueReferenceLedger $ledger;

	public function __construct(
		CatalogueEmbeddingService $catalogueEmbeddingService,
		ToolExecutionPolicyService $toolExecutionPolicyService,
		LoggerInterface $logger,
		?CatalogueLinkService $linkService = null,
		?CatalogueReferenceLedger $ledger = null,
	) {
		$this->catalogueEmbeddingService = $catalogueEmbeddingService;
		$this->toolExecutionPolicyService = $toolExecutionPolicyService;
		$this->logger = $logger;
		$this->linkService = $linkService;
		$this->ledger = $ledger;
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
			$text = $this->catalogueEmbeddingService->sourceUnavailableReason() === null
				? 'Catalogue integration is not enabled.'
				: 'The course catalogue is unavailable right now because its data source cannot be used. Tell the user that course data cannot be retrieved at the moment.';
			return [
				'content' => [['type' => 'text', 'text' => $text]],
				'isError' => true,
			];
		}

		$this->ledger?->markUsed();
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
			if (isset($result['error'])) {
				return $this->searchFailure($result);
			}
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

			$text = $this->buildSearchResultText($courses, $query, $total - count($courses), false);

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
			if (isset($result['error'])) {
				return $this->searchFailure($result);
			}
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

			$text = $this->buildSearchResultText($courses, $query, $total - count($courses), true);

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
	 * The search did not run, which must not read like "no matching courses".
	 *
	 * @param array{error?:string,retry_after_seconds?:int} $result
	 * @return array<string,mixed>
	 */
	private function searchFailure(array $result): array {
		if (($result['error'] ?? '') === 'rate_limited') {
			$seconds = (int)($result['retry_after_seconds'] ?? 60);
			$code = 'rate_limited';
			$message = 'The course search is temporarily rate limited by the embedding provider. Tell the user to try again in about '
				. max(1, (int)ceil($seconds / 60)) . ' minute(s); do not say that no courses exist.';
			$details = ['retry_after_seconds' => $seconds];
		} else {
			$code = 'search_failed';
			$message = 'The course search could not run because of a technical problem. Tell the user that the catalogue cannot be searched right now; do not say that no courses exist.';
			$details = [];
		}

		return [
			'content' => [['type' => 'text', 'text' => $message]],
			'isError' => true,
			'error' => ['code' => $code, 'message' => $message, 'details' => $details],
		];
	}

	/**
	 * Whole result entries within the tool output budget; entries that do not
	 * fit are counted instead of being cut, so no link is ever truncated.
	 *
	 * @param array<int,array<string,mixed>> $courses
	 */
	private function buildSearchResultText(array $courses, string $query, int $moreAvailable, bool $past): string {
		$footer = $past
			? '💡 Use catalogue_get_opportunity with the opportunity_id and is_past=true to get full details. Use catalogue_search to find current opportunities.'
			: '💡 Use catalogue_get_opportunity with the opportunity_id to get full details including description, schedule and prerequisites.';
		$footer .= "\n" . self::LINK_INSTRUCTIONS;
		$intro = $past ? "⚠️ Note: These opportunities have ended. They may be offered again in the future.\n\n" : '';
		$query = $this->truncateText($query, 200);
		$headerReserve = mb_strlen($this->searchHeader(count($courses), $query, $moreAvailable + count($courses), $past), 'UTF-8');
		$omissionReserve = 200;

		$blocks = '';
		$shown = 0;
		$used = $headerReserve + mb_strlen($intro . $footer, 'UTF-8') + $omissionReserve;
		foreach ($courses as $course) {
			$resolved = $this->resolveLinks($course);
			$block = $this->searchResultBlock($shown + 1, $course, $resolved, $past, false);
			if ($used + mb_strlen($block, 'UTF-8') > self::OUTPUT_LIMIT) {
				$block = $this->searchResultBlock($shown + 1, $course, $resolved, $past, true);
				if ($used + mb_strlen($block, 'UTF-8') > self::OUTPUT_LIMIT) {
					break;
				}
			}
			$blocks .= $block;
			$used += mb_strlen($block, 'UTF-8');
			$shown++;
			$this->recordReference($course, $resolved, $past);
		}

		$omitted = count($courses) - $shown;
		$text = $this->searchHeader($shown, $query, $moreAvailable + $omitted, $past) . $intro . $blocks;
		if ($omitted > 0) {
			$text .= "($omitted further result(s) omitted to stay within the tool output limit; search again with a smaller limit or a more specific query.)\n\n";
		}
		return $text . $footer;
	}

	private function searchHeader(int $shown, string $query, int $moreAvailable, bool $past): string {
		$text = 'Found ' . $shown . ($past ? ' PAST' : '') . " learning opportunity(ies) for: \"$query\"";
		if ($moreAvailable > 0) {
			$text .= " ($moreAvailable more available - increase limit to see more)";
		}
		return $text . "\n\n";
	}

	/**
	 * @param array<string,mixed> $course
	 * @param array{id:?string,canonical_url:?string,application_url:?string} $resolved
	 */
	private function searchResultBlock(int $position, array $course, array $resolved, bool $past, bool $compact): string {
		$title = $this->truncateText($this->stringField($course, 'title') ?? 'Untitled', self::TITLE_LENGTH);
		$text = $position . '. **' . $title . '**' . ($past ? ' (PAST)' : '');
		if ($resolved['id'] !== null) {
			$text .= ' (ID: ' . $resolved['id'] . ')';
		}
		$score = $course['_score'] ?? null;
		if (is_int($score) || is_float($score)) {
			$text .= sprintf(' [relevance: %.2f]', $score);
		}
		$text .= "\n";

		if (!$compact) {
			$summary = $this->stringField($course, 'summary');
			if ($summary !== null) {
				$text .= '   ' . $this->truncateText($summary, self::SEARCH_SUMMARY_LENGTH) . "\n";
			}

			$typeInfo = array_filter([
				$this->nestedName($course, 'learningType'),
				$this->nestedName($course, 'mobilityFormat'),
				$this->nestedName($course, 'mode'),
			]);
			if ($typeInfo !== []) {
				$text .= '   📋 ' . implode(' • ', $typeInfo) . "\n";
			}

			$details = array_filter([
				$this->nestedName($course, 'discipline'),
				$this->nestedName($course, 'leadUniversity'),
			]);
			$language = $this->nestedName($course, 'language');
			if ($language !== null) {
				$level = $this->nestedName($course, 'languageLevel');
				$details[] = $level !== null ? "$language ($level)" : $language;
			}
			if (!empty($course['ects']) && is_scalar($course['ects'])) {
				$details[] = $course['ects'] . ' ECTS';
			}
			if ($details !== []) {
				$text .= '   🎓 ' . implode(' | ', $details) . "\n";
			}

			$availability = [];
			$levels = $this->levelNames($course, true);
			if ($levels !== []) {
				$availability[] = 'For: ' . implode(', ', $levels);
			}
			if (!$past) {
				$deadline = $this->stringField($course, 'application_deadline');
				if ($deadline !== null) {
					$availability[] = 'Deadline: ' . $deadline;
				} elseif (!empty($course['permanent'])) {
					$availability[] = 'Always open';
				}
			}
			if ($availability !== []) {
				$text .= ($past ? '   👥 ' : '   📅 ') . implode(' | ', $availability) . "\n";
			}
		}

		return $text . $this->linkLines($resolved, '   ') . "\n";
	}

	/** @param array{canonical_url:?string,application_url:?string} $resolved */
	private function linkLines(array $resolved, string $indent): string {
		$text = $indent . '🔗 Course page: ' . ($resolved['canonical_url'] ?? 'not available (no verified course link)') . "\n";
		if ($resolved['application_url'] !== null) {
			$text .= $indent . '📝 Application link (external application form, not the course page): ' . $resolved['application_url'] . "\n";
		}
		return $text;
	}

	/**
	 * @param array<string,mixed> $course
	 * @param array{id:?string,canonical_url:?string,application_url:?string} $resolved
	 */
	private function recordReference(array $course, array $resolved, bool $past): void {
		$reference = CatalogueLinkGuard::referenceFromRecord(['is_past' => $past] + $course, $resolved);
		if ($reference !== null) {
			$this->ledger?->record($reference);
		}
	}

	/**
	 * @param array<string,mixed> $course
	 * @return array{id:?string,canonical_url:?string,source:string,application_url:?string,issues:list<string>}
	 */
	private function resolveLinks(array $course): array {
		return $this->linkService?->resolve($course) ?? CanonicalLinkResolver::unconfigured()->resolve($course);
	}

	/** @param array<string,mixed> $data */
	private function stringField(array $data, string $key): ?string {
		$value = $data[$key] ?? null;
		if (is_int($value) || is_float($value)) {
			$value = (string)$value;
		}
		return is_string($value) && trim($value) !== '' ? $value : null;
	}

	/** @param array<string,mixed> $data */
	private function nestedName(array $data, string $key): ?string {
		return is_array($data[$key] ?? null) ? $this->stringField($data[$key], 'name') : null;
	}

	/**
	 * @param array<string,mixed> $course
	 * @return list<string>
	 */
	private function levelNames(array $course, bool $preferShort): array {
		if (empty($course['levels']) || !is_array($course['levels'])) {
			return [];
		}
		$names = [];
		foreach ($course['levels'] as $level) {
			if (!is_array($level)) {
				continue;
			}
			$name = $preferShort
				? ($this->stringField($level, 'short_name') ?? $this->stringField($level, 'name'))
				: ($this->stringField($level, 'name') ?? $this->stringField($level, 'short_name'));
			if ($name !== null) {
				$names[] = $name;
			}
		}
		return $names;
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

			$detail = $this->buildCourseDetailText($course);
			if ($detail === null) {
				$message = 'This opportunity cannot fit into the tool result without shortening its identity or links. No details were returned.';
				return [
					'content' => [['type' => 'text', 'text' => $message]],
					'isError' => true,
					'error' => ['code' => 'result_record_too_large', 'message' => $message, 'details' => []],
				];
			}
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => $detail,
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
	 * Identity and links first; long sections are shortened or omitted so the
	 * result never relies on the agent's blind truncation.
	 *
	 * @param array<string,mixed> $course
	 */
	private function buildCourseDetailText(array $course): ?string {
		$resolved = $this->resolveLinks($course);
		$isPast = (bool)($course['is_past'] ?? false);
		$title = $this->truncateText($this->stringField($course, 'title') ?? 'Untitled Course', self::TITLE_LENGTH);

		$head = '# ' . $title . "\n\n";
		$head .= '**Status:** ' . ($isPast ? '⏳ PAST/ARCHIVED' : '✅ CURRENT') . "\n";
		$code = $this->stringField($course, 'code');
		if ($code !== null) {
			$head .= '**Code:** ' . $this->truncateText($code, 100) . "\n";
		}
		if ($resolved['id'] !== null) {
			$head .= '**ID:** ' . $resolved['id'] . "\n";
		}
		$head .= $this->linkLines($resolved, '');
		$footer = "\n" . self::LINK_INSTRUCTIONS;
		if (mb_strlen($head . $footer, 'UTF-8') > self::OUTPUT_LIMIT) {
			return null;
		}

		$infoItems = [
			'Discipline' => $this->nestedName($course, 'discipline'),
			'Learning Type' => $this->nestedName($course, 'learningType'),
			'Mobility Format' => $this->nestedName($course, 'mobilityFormat'),
			'Mode' => $this->nestedName($course, 'mode'),
			'Language' => $this->nestedName($course, 'language'),
			'Language Level' => $this->nestedName($course, 'languageLevel'),
			'Lead University' => $this->nestedName($course, 'leadUniversity'),
			'Lead Teacher' => $this->nestedName($course, 'leadTeacher'),
			'ECTS Credits' => $this->stringField($course, 'ects'),
			'Duration' => $this->nestedName($course, 'duration'),
			'Semester' => $this->nestedName($course, 'semester'),
			'Platform' => $this->stringField($course, 'platform'),
			'Always Available' => isset($course['permanent']) ? ($course['permanent'] ? 'Yes' : 'No') : null,
			'Application Deadline' => $this->stringField($course, 'application_deadline'),
			'Capacity' => $this->stringField($course, 'capacity'),
			'Target Levels' => implode(', ', $this->levelNames($course, false)),
		];
		$universities = [];
		if (!empty($course['targetedUniversities']) && is_array($course['targetedUniversities'])) {
			foreach ($course['targetedUniversities'] as $university) {
				$name = is_array($university) ? ($this->stringField($university, 'name') ?? $this->stringField($university, 'short_name')) : null;
				if ($name !== null) {
					$universities[] = $name;
				}
			}
		}
		$infoItems['Targeted Universities'] = implode(', ', $universities);
		$info = '';
		foreach ($infoItems as $label => $value) {
			if ($value !== null && $value !== '') {
				$info .= "- **$label:** $value\n";
			}
		}

		$sections = [
			'Summary' => $this->stringField($course, 'summary'),
			'Description' => $this->stringField($course, 'description'),
			'Schedule & Details' => $this->stringField($course, 'details'),
			'Course Information' => $info !== '' ? rtrim($info) : null,
			'Prerequisites' => $this->stringField($course, 'prerequisites'),
			'Further Information' => $this->stringField($course, 'further_information'),
			'Application Contact' => $this->stringField($course, 'application_contact'),
			'Information Contact' => $this->stringField($course, 'info_contact'),
		];

		$omissionReserve = 120;
		$remaining = self::OUTPUT_LIMIT - mb_strlen($head . $footer, 'UTF-8') - $omissionReserve;
		$body = '';
		$omitted = 0;
		foreach ($sections as $heading => $content) {
			if ($content === null) {
				continue;
			}
			$section = "\n## $heading\n" . trim($content) . "\n";
			$length = mb_strlen($section, 'UTF-8');
			if ($length <= $remaining) {
				$body .= $section;
				$remaining -= $length;
				continue;
			}
			$marker = ' … [shortened]';
			$prefix = "\n## $heading\n";
			$room = $remaining - mb_strlen($prefix . $marker . "\n", 'UTF-8');
			if ($room >= 120) {
				$body .= $prefix . rtrim(mb_substr(trim($content), 0, $room, 'UTF-8')) . $marker . "\n";
				$remaining = 0;
				continue;
			}
			$omitted++;
		}
		if ($omitted > 0) {
			$body .= "\n($omitted section(s) omitted to stay within the tool output limit.)\n";
		}

		if (mb_strlen($head . $body . $footer, 'UTF-8') > self::OUTPUT_LIMIT) {
			return null;
		}
		$this->recordReference($course, $resolved, $isPast);
		return $head . $body . $footer;
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
