<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Final-answer check for catalogue course links.
 *
 * Reference markers ({{course:ID}}) are expanded only from records the
 * catalogue tools returned in this run. Course links whose ID is known but
 * whose URL differs from the canonical link, and course-route links that
 * cannot be verified, replace the answer with a deterministic list built from
 * verified data. Unrelated links in the answer are never rewritten.
 *
 * @psalm-import-type CatalogueReference from CatalogueReferenceLedger
 */
class CatalogueLinkGuard {
	public const MAX_FALLBACK_ITEMS = 10;
	public const MAX_INDEX_LOOKUPS = 20;

	public const OUTCOME_UNCHANGED = 'unchanged';
	public const OUTCOME_EXPANDED = 'expanded';
	public const OUTCOME_REPLACED = 'replaced';

	private const MARKER_PATTERN = '/\{\{\s*course\s*:\s*([^\s{}]{1,128})\s*\}\}/i';
	private const URL_PATTERN = '~(?<![A-Za-z0-9:/])(?:https?:)?//[^\s<>"\'`()\[\]{}|\\\\^]+~i';

	/** @var array<string,CatalogueReference|null> */
	private array $indexCache = [];
	private int $indexLookups = 0;

	public function __construct(
		private CatalogueReferenceLedger $ledger,
		private CatalogueLinkService $links,
		private CatalogueEmbeddingService $catalogue,
		private LoggerInterface $logger,
	) {
	}

	public function beginRun(): void {
		$this->ledger->begin();
		$this->indexCache = [];
		$this->indexLookups = 0;
	}

	public function endRun(): void {
		$this->ledger->end();
	}

	/**
	 * @return array{text:string,outcome:string,reasons:list<string>,referenced_ids:list<string>}
	 */
	public function enforce(string $answer, IL10N $l): array {
		$catalogueUsed = $this->ledger->wasUsed();
		$hasMarker = preg_match(self::MARKER_PATTERN, $answer) === 1;
		$hasUrl = preg_match(self::URL_PATTERN, $answer) === 1;
		if ((!$catalogueUsed && !$this->catalogue->isEnabled()) || (!$hasMarker && !$hasUrl)) {
			return $this->result($answer, self::OUTCOME_UNCHANGED, [], []);
		}

		$known = $this->ledger->records();
		$resolver = $this->links->resolver();
		$conflicts = [];
		$referenced = [];

		$text = preg_replace_callback(self::MARKER_PATTERN, function (array $match) use ($known, $l, &$conflicts, &$referenced): string {
			$id = CanonicalLinkResolver::normalizeId($match[1]);
			if ($id === null || !isset($known[$id])) {
				$conflicts[] = 'unverified_marker';
				return $match[0];
			}
			$referenced[$id] = true;
			return $this->renderInline($known[$id], $l);
		}, $answer) ?? $answer;

		preg_match_all(self::URL_PATTERN, $text, $matches);
		$urls = array_values(array_unique(array_map(
			static fn (string $url): string => rtrim($url, '.,;:!?*_~'),
			$matches[0],
		)));
		$derivedRoutes = $this->derivedRoutes($known);
		foreach ($urls as $url) {
			$reason = $this->classifyUrl($url, $known, $resolver, $derivedRoutes, $referenced);
			if ($reason !== null) {
				$conflicts[] = $reason;
			}
		}

		$referencedIds = array_map('strval', array_keys($referenced));
		if ($conflicts === []) {
			return $this->result($text, $text === $answer ? self::OUTCOME_UNCHANGED : self::OUTCOME_EXPANDED, [], $referencedIds);
		}

		$reasons = array_values(array_unique($conflicts));
		$this->logger->info('Catalogue answer replaced by verified course list', [
			'reasons' => $reasons,
			'referenced' => count($referencedIds),
			'verified' => count($known),
		]);
		return $this->result($this->renderFallback($referencedIds, $known, $l), self::OUTCOME_REPLACED, $reasons, $referencedIds);
	}

	/**
	 * @param array<string,CatalogueReference> $known
	 * @param list<string> $derivedRoutes
	 * @param array<string,bool> $referenced
	 */
	private function classifyUrl(string $url, array &$known, CanonicalLinkResolver $resolver, array $derivedRoutes, array &$referenced): ?string {
		$canonical = CanonicalLinkResolver::canonicalize($url);
		// Candidate identification is deliberately broader than trusted URL
		// validation; unsafe userinfo or a missing scheme must not hide a course.
		$parts = parse_url($url);
		$origin = is_array($parts) && isset($parts['host'])
			? CanonicalLinkResolver::originOf(($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''))
			: null;
		if ($origin === null) {
			return null;
		}
		foreach ($known as $id => $reference) {
			if ($canonical !== null && $reference['canonical_url'] === $canonical) {
				$referenced[(string)$id] = true;
				return null;
			}
			if ($canonical !== null && $reference['application_url'] === $canonical) {
				return null;
			}
		}

		$routeId = $resolver->matchCourseRoute($url) ?? $this->matchDerivedRoute($url, $derivedRoutes);
		if ($routeId !== null) {
			$reference = $known[$routeId] ?? $this->lookupIndexed($routeId);
			if ($reference !== null) {
				$known[$routeId] ??= $reference;
				$referenced[$routeId] = true;
				return $canonical !== null && $reference['canonical_url'] === $canonical ? null : 'link_mismatch';
			}
			return $this->isCatalogueHost($origin, $resolver, $known) ? 'unverified_link' : null;
		}

		$tail = parse_url($url, PHP_URL_PATH);
		foreach ($known as $id => $reference) {
			if ($reference['canonical_url'] !== null
				&& parse_url($reference['canonical_url'], PHP_URL_PATH) === $tail
				&& CanonicalLinkResolver::originOf($reference['canonical_url']) !== $origin) {
				$referenced[(string)$id] = true;
				return 'link_host_mismatch';
			}
		}
		return null;
	}

	/**
	 * Course routes visible in verified links whose last path segment is the ID.
	 *
	 * @param array<string,CatalogueReference> $known
	 * @return list<string>
	 */
	private function derivedRoutes(array $known): array {
		$routes = [];
		foreach ($known as $id => $reference) {
			$tail = $reference['canonical_url'] !== null ? parse_url($reference['canonical_url'], PHP_URL_PATH) : null;
			$segment = '/' . rawurlencode((string)$id);
			if (!is_string($tail) || !str_ends_with($tail, $segment)) {
				continue;
			}
			$routes['~^' . preg_quote(substr($tail, 0, -strlen($segment)), '~') . '/([^/?#&]+)$~D'] = true;
		}
		return array_keys($routes);
	}

	/** @param list<string> $routes */
	private function matchDerivedRoute(string $url, array $routes): ?string {
		$tail = parse_url($url, PHP_URL_PATH);
		if (!is_string($tail)) {
			return null;
		}
		foreach ($routes as $route) {
			if (preg_match($route, $tail, $matches)) {
				return CanonicalLinkResolver::normalizeId(rawurldecode($matches[1]));
			}
		}
		return null;
	}

	/**
	 * Accepted origins and hosts sharing their registrable-looking suffix
	 * (e.g. a test subdomain of the catalogue).
	 *
	 * @param array<string,CatalogueReference> $known
	 */
	private function isCatalogueHost(string $origin, CanonicalLinkResolver $resolver, array $known): bool {
		$origins = $resolver->acceptedOrigins();
		foreach ($known as $reference) {
			if ($reference['canonical_url'] !== null) {
				$origins[] = (string)CanonicalLinkResolver::originOf($reference['canonical_url']);
			}
		}
		$host = self::hostSuffix($origin);
		foreach (array_unique($origins) as $accepted) {
			if ($accepted === $origin || ($host !== null && self::hostSuffix($accepted) === $host)) {
				return true;
			}
		}
		return false;
	}

	private static function hostSuffix(string $origin): ?string {
		$host = (string)parse_url($origin, PHP_URL_HOST);
		if ($host === '' || str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return null;
		}
		$labels = explode('.', $host);
		return count($labels) >= 2 ? implode('.', array_slice($labels, -2)) : null;
	}

	/** @return CatalogueReference|null */
	private function lookupIndexed(string $id): ?array {
		if (array_key_exists($id, $this->indexCache)) {
			return $this->indexCache[$id];
		}
		if ($this->indexLookups >= self::MAX_INDEX_LOOKUPS || !ctype_digit($id) || strlen($id) > 18 || !$this->catalogue->isEnabled()) {
			return null;
		}
		$this->indexLookups++;
		try {
			$course = $this->catalogue->getCourseFromEmbeddings((int)$id, false)
				?? $this->catalogue->getCourseFromEmbeddings((int)$id, true);
		} catch (\Throwable $e) {
			$this->logger->warning('Catalogue link verification lookup failed', ['exception' => $e]);
			$course = null;
		}
		$reference = is_array($course) ? self::referenceFromRecord($course, $this->links->resolve($course)) : null;
		return $this->indexCache[$id] = ($reference !== null && $reference['id'] === $id ? $reference : null);
	}

	/**
	 * @param array<string,mixed> $record
	 * @param array{id:?string,canonical_url:?string,application_url:?string} $resolved
	 * @return CatalogueReference|null
	 */
	public static function referenceFromRecord(array $record, array $resolved): ?array {
		if ($resolved['id'] === null) {
			return null;
		}
		$title = is_string($record['title'] ?? null) && trim($record['title']) !== '' ? $record['title'] : 'Untitled';
		return [
			'id' => $resolved['id'],
			'title' => $title,
			'canonical_url' => $resolved['canonical_url'],
			'application_url' => $resolved['application_url'],
			'is_past' => (bool)($record['is_past'] ?? false),
		];
	}

	/** @param CatalogueReference $reference */
	private function renderInline(array $reference, IL10N $l): string {
		$title = self::escapeMarkdown($reference['title']);
		if ($reference['canonical_url'] === null) {
			return $title . ' (ID: ' . self::escapeMarkdown($reference['id']) . ', ' . $l->t('Course link not available') . ')';
		}
		return '[' . $title . '](' . $reference['canonical_url'] . ')';
	}

	/**
	 * @param list<string> $referencedIds
	 * @param array<string,CatalogueReference> $known
	 */
	private function renderFallback(array $referencedIds, array $known, IL10N $l): string {
		$ids = [];
		foreach ($referencedIds as $id) {
			if (isset($known[$id])) {
				$ids[] = $id;
			}
		}
		if ($ids === []) {
			$ids = array_map('strval', array_keys($known));
		}
		if ($ids === []) {
			return $l->t('Some course links in this answer could not be verified, and no verified catalogue entry is available. Please search the course catalogue again.');
		}

		$lines = [$l->t('Some course links in this answer could not be verified. These are the verified catalogue entries:'), ''];
		foreach (array_slice($ids, 0, self::MAX_FALLBACK_ITEMS) as $id) {
			$reference = $known[$id];
			$heading = '- **' . self::escapeMarkdown($reference['title']) . '** (ID: ' . self::escapeMarkdown($id) . ')';
			if ($reference['is_past']) {
				$heading .= ' – ' . $l->t('past offering');
			}
			$lines[] = $heading;
			$lines[] = '  ' . $l->t('Course page') . ': ' . ($reference['canonical_url'] ?? $l->t('Course link not available'));
			if ($reference['application_url'] !== null) {
				$lines[] = '  ' . $l->t('Application link (not the course page)') . ': ' . $reference['application_url'];
			}
		}
		$hidden = count($ids) - self::MAX_FALLBACK_ITEMS;
		if ($hidden > 0) {
			$lines[] = '';
			$lines[] = $l->n('%n more verified entry is not shown.', '%n more verified entries are not shown.', $hidden);
		}
		return implode("\n", $lines);
	}

	public static function escapeMarkdown(string $text): string {
		$text = trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text));
		if (mb_strlen($text, 'UTF-8') > 200) {
			$text = rtrim(mb_substr($text, 0, 199, 'UTF-8')) . '…';
		}
		return (string)preg_replace('/([\\\\`*_\[\]<>|~])/', '\\\\$1', $text);
	}

	/**
	 * @param list<string> $reasons
	 * @param list<string> $referencedIds
	 * @return array{text:string,outcome:string,reasons:list<string>,referenced_ids:list<string>}
	 */
	private function result(string $text, string $outcome, array $reasons, array $referencedIds): array {
		return ['text' => $text, 'outcome' => $outcome, 'reasons' => $reasons, 'referenced_ids' => $referencedIds];
	}
}
