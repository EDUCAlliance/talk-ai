<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/**
 * Strict handling of API base addresses: absolute, no user info, query,
 * fragment, dot segments, whitespace or control characters. Normalized to
 * lowercase scheme/host, no default port and no trailing slash.
 */
final class ApiUrl {
	public const MAX_LENGTH = 2048;

	/**
	 * @return array{scheme:string,host:string,port:?int,path:string,origin:string,base:string}|null
	 */
	public static function parseBase(string $url, bool $allowHttp = false): ?array {
		$url = trim($url);
		if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f-\xff\\\\<>"{}|^`]/', $url) === 1) {
			return null;
		}
		if (preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)([^?#]*)$~D', $url, $m) !== 1) {
			return null;
		}
		$scheme = strtolower($m[1]);
		if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
			return null;
		}
		$authority = $m[2];
		if ($authority === '' || str_contains($authority, '@')) {
			return null;
		}
		if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$/D', $authority, $a) !== 1) {
			return null;
		}
		$host = strtolower($a[1]);
		if (!str_starts_with($host, '[') && (str_starts_with($host, '.') || str_ends_with($host, '.') || str_contains($host, '..'))) {
			return null;
		}
		if (str_starts_with($host, '[') && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
			return null;
		}
		$port = isset($a[2]) && $a[2] !== '' ? (int)$a[2] : null;
		if ($port !== null && ($port < 1 || $port > 65535)) {
			return null;
		}
		if ($port === ($scheme === 'https' ? 443 : 80)) {
			$port = null;
		}

		$path = rtrim($m[3], '/');
		if ($path !== '') {
			if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1 || preg_match('/%(2[eE]|2[fF]|5[cC]|0[0-9A-Fa-f]|1[0-9A-Fa-f]|7[fF])/', $path) === 1) {
				return null;
			}
			foreach (explode('/', substr($path, 1)) as $segment) {
				if ($segment === '' || $segment === '.' || $segment === '..') {
					return null;
				}
			}
		}
		$origin = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
		return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => $path, 'origin' => $origin, 'base' => $origin . $path];
	}

	public static function normalizeBase(string $url, bool $allowHttp = false): ?string {
		return self::parseBase($url, $allowHttp)['base'] ?? null;
	}

	/** Bare host without brackets, for address classification. */
	public static function hostForClassification(string $host): string {
		return str_starts_with($host, '[') ? substr($host, 1, -1) : $host;
	}

	/**
	 * Resolve a relative server URL from a specification against the URL the
	 * specification was fetched from (RFC 3986 merge for the common forms).
	 */
	public static function resolveRelative(string $relative, string $sourceUrl): ?string {
		if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $relative) === 1) {
			return $relative;
		}
		if (preg_match('~^([A-Za-z][A-Za-z0-9+.-]*):(//[^/?#]*)([^?#]*)~', $sourceUrl, $m) !== 1) {
			return null;
		}
		if (str_starts_with($relative, '//')) {
			return $m[1] . ':' . $relative;
		}
		if (str_starts_with($relative, '/')) {
			return $m[1] . ':' . $m[2] . $relative;
		}
		$directory = substr($m[3], 0, (int)strrpos($m[3], '/') + 1);
		$segments = [];
		foreach (explode('/', ($directory === '' ? '/' : $directory) . $relative) as $segment) {
			if ($segment === '..') {
				array_pop($segments);
			} elseif ($segment !== '.') {
				$segments[] = $segment;
			}
		}
		$path = implode('/', $segments);
		return $m[1] . ':' . $m[2] . (str_starts_with($path, '/') ? $path : '/' . $path);
	}
}
