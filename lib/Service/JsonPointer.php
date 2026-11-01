<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

/**
 * RFC 6901 JSON Pointer evaluation over decoded JSON arrays.
 *
 * Only the absolute form ("" or "/a/b") is accepted; URI fragment pointers are
 * deliberately not, so configured values have exactly one spelling.
 */
final class JsonPointer {
	public const MAX_LENGTH = 512;

	public static function isValid(string $pointer): bool {
		if ($pointer === '') {
			return true;
		}
		if (strlen($pointer) > self::MAX_LENGTH || $pointer[0] !== '/' || preg_match('/[\x00-\x1f\x7f]/', $pointer)) {
			return false;
		}
		// "~" must be followed by 0 or 1.
		return preg_match('/~(?![01])/', $pointer) !== 1;
	}

	/** @return list<string> */
	public static function tokens(string $pointer): array {
		if (!self::isValid($pointer)) {
			throw new \InvalidArgumentException('Invalid JSON pointer');
		}
		if ($pointer === '') {
			return [];
		}
		return array_map(
			static fn (string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
			explode('/', substr($pointer, 1)),
		);
	}

	/**
	 * @param mixed $document Decoded JSON (associative arrays)
	 * @param-out bool $found
	 */
	public static function get(mixed $document, string $pointer, ?bool &$found = null): mixed {
		$found = false;
		$current = $document;
		foreach (self::tokens($pointer) as $token) {
			if (!is_array($current)) {
				return null;
			}
			if (array_is_list($current) && $current !== []) {
				if (!preg_match('/^(0|[1-9][0-9]{0,8})$/D', $token) || !array_key_exists((int)$token, $current)) {
					return null;
				}
				$current = $current[(int)$token];
				continue;
			}
			if (!array_key_exists($token, $current)) {
				return null;
			}
			$current = $current[$token];
		}
		$found = true;
		return $current;
	}

	public static function escapeToken(string $token): string {
		return str_replace(['~', '/'], ['~0', '~1'], $token);
	}
}
