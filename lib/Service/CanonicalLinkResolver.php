<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Exception\CatalogueLinkConfigurationException;

/**
 * Derives the one web link a catalogue record may be presented with.
 *
 * Order: a valid absolute HTTPS link field on an explicitly accepted origin,
 * otherwise the configured template with the encoded record ID, otherwise no
 * link. The API host is never used to guess a web host. The application link
 * is validated independently and must never stand in for the course page.
 */
final class CanonicalLinkResolver {
	public const DEFAULT_LINK_FIELD = '/permalink';
	public const ID_FIELD = '/id';
	public const APPLICATION_FIELD = '/application_link';
	public const PLACEHOLDER = '{id}';
	public const MAX_URL_LENGTH = 2048;
	public const MAX_ORIGINS = 20;
	public const MAX_ID_LENGTH = 128;

	public const SOURCE_RECORD = 'record';
	public const SOURCE_TEMPLATE = 'template';
	public const SOURCE_NONE = 'none';

	public const ISSUE_RELATIVE_LINK = 'record_link_relative';
	public const ISSUE_INVALID_LINK = 'record_link_invalid';
	public const ISSUE_ORIGIN_NOT_ACCEPTED = 'record_link_origin_not_accepted';
	public const ISSUE_INVALID_ID = 'record_id_invalid';
	public const ISSUE_NO_TEMPLATE = 'link_template_missing';
	public const ISSUE_INVALID_APPLICATION_LINK = 'application_link_invalid';

	public const EDUC_PRESET_TEMPLATE = 'https://catalogue.educalliance.eu/Opportunity/{id}';

	private const SENTINEL = 'ZZEDUCAIIDPLACEHOLDERZZ';
	private const HOST_LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

	/** @param list<string> $origins */
	private function __construct(
		private string $linkField,
		private ?string $template,
		private ?string $templateOrigin,
		private array $origins,
	) {
	}

	/**
	 * @param array<mixed> $origins
	 * @throws CatalogueLinkConfigurationException
	 */
	public static function fromConfig(?string $linkField, ?string $template, array $origins): self {
		$field = trim((string)$linkField);
		if ($field === '') {
			$field = self::DEFAULT_LINK_FIELD;
		}
		if (!JsonPointer::isValid($field)) {
			throw new CatalogueLinkConfigurationException('invalid_pointer', 'link_field');
		}

		if (count($origins) > self::MAX_ORIGINS) {
			throw new CatalogueLinkConfigurationException('too_many_origins', 'link_origins');
		}
		$normalizedOrigins = [];
		foreach ($origins as $origin) {
			if (!is_string($origin)) {
				throw new CatalogueLinkConfigurationException('invalid_origin', 'link_origins');
			}
			if (trim($origin) === '') {
				continue;
			}
			$normalized = self::normalizeOrigin(trim($origin));
			if ($normalized === null) {
				throw new CatalogueLinkConfigurationException('invalid_origin', 'link_origins');
			}
			$normalizedOrigins[$normalized] = true;
		}

		$template = trim((string)$template);
		if ($template === '') {
			return new self($field, null, null, array_keys($normalizedOrigins));
		}
		[$normalizedTemplate, $templateOrigin] = self::compileTemplate($template);
		return new self($field, $normalizedTemplate, $templateOrigin, array_keys($normalizedOrigins));
	}

	public static function unconfigured(): self {
		return new self(self::DEFAULT_LINK_FIELD, null, null, []);
	}

	public function linkField(): string {
		return $this->linkField;
	}

	public function template(): ?string {
		return $this->template;
	}

	public function templateOrigin(): ?string {
		return $this->templateOrigin;
	}

	/** @return list<string> Explicit origins */
	public function configuredOrigins(): array {
		return $this->origins;
	}

	/** @return list<string> Explicit origins plus the visible template origin */
	public function acceptedOrigins(): array {
		$origins = $this->origins;
		if ($this->templateOrigin !== null && !in_array($this->templateOrigin, $origins, true)) {
			$origins[] = $this->templateOrigin;
		}
		return $origins;
	}

	/** Without template and accepted origins no record can get a verified link. */
	public function isConfigured(): bool {
		return $this->template !== null || $this->origins !== [];
	}

	/**
	 * @param array<string,mixed> $record Decoded catalogue record
	 * @return array{id:?string,canonical_url:?string,source:string,application_url:?string,issues:list<string>}
	 */
	public function resolve(array $record): array {
		$issues = [];
		$id = self::normalizeId(JsonPointer::get($record, self::ID_FIELD));
		$application = $this->resolveApplicationLink($record, $issues);

		$value = JsonPointer::get($record, $this->linkField, $found);
		if ($found && is_string($value) && trim($value) !== '') {
			$candidate = trim($value);
			if (!preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $candidate)) {
				$issues[] = self::ISSUE_RELATIVE_LINK;
			} else {
				$parsed = self::parse($candidate, ['https']);
				if ($parsed === null) {
					$issues[] = self::ISSUE_INVALID_LINK;
				} elseif (!in_array($parsed['origin'], $this->acceptedOrigins(), true)) {
					$issues[] = self::ISSUE_ORIGIN_NOT_ACCEPTED;
				} else {
					return $this->result($id, self::build($parsed), self::SOURCE_RECORD, $application, $issues);
				}
			}
		}

		if ($this->template === null) {
			$issues[] = self::ISSUE_NO_TEMPLATE;
		} elseif ($id === null) {
			$issues[] = self::ISSUE_INVALID_ID;
		} else {
			return $this->result($id, str_replace(self::PLACEHOLDER, rawurlencode($id), $this->template), self::SOURCE_TEMPLATE, $application, $issues);
		}
		return $this->result($id, null, self::SOURCE_NONE, $application, $issues);
	}

	/**
	 * Identify a course route independently from whether its URL is trusted.
	 * Userinfo and network-path URLs may identify a conflicting link; they are
	 * still rejected by canonicalize(). Tracking queries do not hide path IDs.
	 */
	public function matchCourseRoute(string $url): ?string {
		if ($this->template === null) {
			return null;
		}
		$template = parse_url(str_replace(self::PLACEHOLDER, self::SENTINEL, $this->template));
		$candidate = parse_url($url);
		if ($template === false || $candidate === false || !isset($candidate['host'])) {
			return null;
		}
		$templatePath = $template['path'] ?? '/';
		$candidatePath = $candidate['path'] ?? '/';
		if (str_contains($templatePath, self::SENTINEL)) {
			return self::matchRouteValue($templatePath, $candidatePath);
		}
		if ($templatePath !== $candidatePath) {
			return null;
		}
		// Match the identity-bearing query component, not the entire query
		// string: added/reordered tracking parameters must not bypass checking.
		foreach (explode('&', $template['query'] ?? '') as $pair) {
			[$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
			if (!str_contains($value, self::SENTINEL)) {
				continue;
			}
			foreach (explode('&', $candidate['query'] ?? '') as $candidatePair) {
				[$candidateKey, $candidateValue] = array_pad(explode('=', $candidatePair, 2), 2, '');
				if (rawurldecode($key) === rawurldecode($candidateKey)) {
					$id = self::matchRouteValue($value, $candidateValue);
					if ($id !== null) {
						return $id;
					}
				}
			}
		}
		return null;
	}

	private static function matchRouteValue(string $template, string $value): ?string {
		$pattern = '~^' . str_replace(preg_quote(self::SENTINEL, '~'), '([^/?#&]+)', preg_quote($template, '~')) . '$~D';
		return preg_match($pattern, $value, $matches) === 1 ? self::normalizeId(rawurldecode($matches[1])) : null;
	}

	/** Normalized absolute HTTP(S) URL, or null when it is unsafe or not absolute. */
	public static function canonicalize(string $url): ?string {
		$parsed = self::parse($url, ['https', 'http']);
		return $parsed === null ? null : self::build($parsed);
	}

	public static function originOf(string $url): ?string {
		return self::parse($url, ['https', 'http'])['origin'] ?? null;
	}

	public static function pathAndQuery(string $url): ?string {
		$parsed = self::parse($url, ['https', 'http']);
		if ($parsed === null) {
			return null;
		}
		return self::markdownSafe(($parsed['path'] === '' ? '/' : $parsed['path'])
			. ($parsed['query'] !== null ? '?' . $parsed['query'] : ''));
	}

	public static function normalizeOrigin(string $origin): ?string {
		$parsed = self::parse($origin, ['https']);
		if ($parsed === null || !in_array($parsed['path'], ['', '/'], true) || $parsed['query'] !== null || $parsed['fragment'] !== null) {
			return null;
		}
		return $parsed['origin'];
	}

	/** Stable, printable record IDs only; dot segments would escape the template path. */
	public static function normalizeId(mixed $value): ?string {
		if (is_int($value)) {
			return $value >= 0 ? (string)$value : null;
		}
		if (!is_string($value) || $value === '.' || $value === '..'
			|| !preg_match('/^[\x21-\x7e]{1,' . self::MAX_ID_LENGTH . '}$/D', $value)) {
			return null;
		}
		return $value;
	}

	/**
	 * @param list<string> $issues
	 * @return array{id:?string,canonical_url:?string,source:string,application_url:?string,issues:list<string>}
	 */
	private function result(?string $id, ?string $url, string $source, ?string $application, array $issues): array {
		return [
			'id' => $id,
			'canonical_url' => $url,
			'source' => $source,
			'application_url' => $application,
			'issues' => $issues,
		];
	}

	/**
	 * @param array<string,mixed> $record
	 * @param list<string> $issues
	 */
	private function resolveApplicationLink(array $record, array &$issues): ?string {
		$value = JsonPointer::get($record, self::APPLICATION_FIELD);
		if (!is_string($value) || trim($value) === '') {
			return null;
		}
		$parsed = self::parse(trim($value), ['https', 'http']);
		if ($parsed === null) {
			$issues[] = self::ISSUE_INVALID_APPLICATION_LINK;
			return null;
		}
		return self::build($parsed);
	}

	/**
	 * @return array{0:string,1:string} Normalized template and its origin
	 * @throws CatalogueLinkConfigurationException
	 */
	private static function compileTemplate(string $template): array {
		if (!preg_match('/^https:\/\//i', $template)) {
			throw new CatalogueLinkConfigurationException('template_scheme', 'link_template');
		}
		if (substr_count($template, self::PLACEHOLDER) !== 1) {
			throw new CatalogueLinkConfigurationException('template_placeholder', 'link_template');
		}
		$probe = str_replace(self::PLACEHOLDER, self::SENTINEL, $template);
		if (str_contains($probe, '{') || str_contains($probe, '}')) {
			throw new CatalogueLinkConfigurationException('template_placeholder', 'link_template');
		}
		$parsed = self::parse($probe, ['https']);
		if ($parsed === null) {
			throw new CatalogueLinkConfigurationException('template_invalid', 'link_template');
		}
		if (stripos($parsed['origin'], self::SENTINEL) !== false) {
			throw new CatalogueLinkConfigurationException('template_authority', 'link_template');
		}
		if ($parsed['fragment'] !== null && str_contains($parsed['fragment'], self::SENTINEL)) {
			throw new CatalogueLinkConfigurationException('template_placeholder', 'link_template');
		}
		if ($parsed['query'] !== null && str_contains($parsed['query'], self::SENTINEL)) {
			foreach (explode('&', $parsed['query']) as $pair) {
				$separator = strpos($pair, '=');
				$position = strpos($pair, self::SENTINEL);
				if ($position !== false && ($separator === false || $position < $separator)) {
					throw new CatalogueLinkConfigurationException('template_placeholder', 'link_template');
				}
			}
		}

		$normalized = self::build($parsed);
		return [str_replace(self::SENTINEL, self::PLACEHOLDER, $normalized), $parsed['origin']];
	}

	/**
	 * Strict absolute-URL parser: no userinfo, control characters, non-ASCII,
	 * malformed or control percent-escapes, or dot segments.
	 *
	 * @param list<string> $schemes
	 * @return array{scheme:string,host:string,port:?int,origin:string,path:string,query:?string,fragment:?string}|null
	 */
	private static function parse(string $url, array $schemes): ?array {
		if ($url === '' || strlen($url) > self::MAX_URL_LENGTH
			|| preg_match('/[\x00-\x20\x7f-\xff\\\\<>"`{}|^]/', $url)) {
			return null;
		}
		if (!preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~D', $url, $m, PREG_UNMATCHED_AS_NULL)) {
			return null;
		}
		$scheme = strtolower((string)$m[1]);
		if (!in_array($scheme, $schemes, true)) {
			return null;
		}
		$authority = (string)$m[2];
		if (!preg_match('~^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$~D', $authority, $a, PREG_UNMATCHED_AS_NULL)) {
			return null;
		}
		$host = strtolower((string)$a[1]);
		if ($host[0] !== '[' && !preg_match('/^' . self::HOST_LABEL . '(?:\.' . self::HOST_LABEL . ')*$/D', $host)) {
			return null;
		}
		$port = $a[2] !== null ? (int)$a[2] : null;
		if ($port !== null && ($port < 1 || $port > 65535)) {
			return null;
		}
		if ($port === ($scheme === 'https' ? 443 : 80)) {
			$port = null;
		}

		$path = (string)$m[3];
		$query = $m[4];
		$fragment = $m[5];
		$rest = $path . ($query ?? '') . ($fragment ?? '');
		if (preg_match('/%(?![0-9A-Fa-f]{2})/', $rest) || preg_match('/%(?:[01][0-9A-Fa-f]|7[Ff]|5[Cc])/', $rest)) {
			return null;
		}
		foreach (explode('/', $path) as $segment) {
			$decoded = rawurldecode($segment);
			if ($decoded === '.' || $decoded === '..') {
				return null;
			}
		}

		$origin = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
		return [
			'scheme' => $scheme,
			'host' => $host,
			'port' => $port,
			'origin' => $origin,
			'path' => $path,
			'query' => $query,
			'fragment' => $fragment,
		];
	}

	/** @param array{origin:string,path:string,query:?string,fragment:?string} $parsed */
	private static function build(array $parsed): string {
		return $parsed['origin'] . self::markdownSafe(($parsed['path'] === '' ? '/' : $parsed['path'])
			. ($parsed['query'] !== null ? '?' . $parsed['query'] : '')
			. ($parsed['fragment'] !== null ? '#' . $parsed['fragment'] : ''));
	}

	/** Brackets, parentheses and quotes would end a Markdown link target early. */
	private static function markdownSafe(string $url): string {
		return strtr($url, ['(' => '%28', ')' => '%29', '[' => '%5B', ']' => '%5D', "'" => '%27']);
	}
}
