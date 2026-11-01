<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

/** Lossless decimal boundaries between JSON numbers, PHP values and URLs. */
final class DecimalNumber {
	public static function format(int|float $value): string {
		if (is_int($value)) {
			return (string)$value;
		}
		if (!is_finite($value)) {
			throw new ApiExecutionException('invalid_arguments', 'A number must be finite.');
		}
		[$sign, $digits, $power] = self::parts(self::shortest($value));
		if ($digits === '0') {
			return '0';
		}
		if ($power >= 0) {
			return $sign . $digits . str_repeat('0', $power);
		}
		$point = strlen($digits) + $power;
		return $point <= 0
			? $sign . '0.' . str_repeat('0', -$point) . $digits
			: $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
	}

	/** Accept only strings whose decimal value survives numeric coercion. */
	public static function fromString(string $value): int|float|null {
		if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value) !== 1) {
			return null;
		}
		$number = (float)$value;
		if (!is_finite($number)) {
			return null;
		}
		// Reject underflow before parsing a potentially enormous exponent. An
		// all-zero significand, unlike a tiny nonzero number, is exactly zero.
		$significand = preg_split('/[eE]/', $value, 2)[0];
		if ($number === 0.0) {
			return preg_match('/[1-9]/', $significand) === 1 ? null : 0;
		}
		[$sign, $digits, $power] = $parts = self::parts($value);
		if ($power >= 0) {
			$limit = $sign === '-' ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
			$length = strlen($digits) + $power;
			if ($length <= strlen($limit)) {
				$integer = $digits . str_repeat('0', $power);
				if ($length < strlen($limit) || strcmp($integer, $limit) <= 0) {
					return (int)($sign . $integer);
				}
			}
		}
		return $parts === self::parts(self::shortest($number)) ? $number : null;
	}

	/** Compare without rounding a native integer to a float at mixed boundaries. */
	public static function compare(int|float $left, int|float $right): int {
		[$leftSign, $leftDigits, $leftPower] = self::parts(self::format($left));
		[$rightSign, $rightDigits, $rightPower] = self::parts(self::format($right));
		if ($leftSign !== $rightSign) {
			return $leftSign === '-' ? -1 : 1;
		}
		$direction = $leftSign === '-' ? -1 : 1;
		if ($leftDigits === '0' || $rightDigits === '0') {
			return $direction * (($leftDigits !== '0' ? 1 : 0) <=> ($rightDigits !== '0' ? 1 : 0));
		}
		$order = (strlen($leftDigits) + $leftPower) <=> (strlen($rightDigits) + $rightPower);
		if ($order === 0) {
			$width = max(strlen($leftDigits), strlen($rightDigits));
			$order = strcmp(str_pad($leftDigits, $width, '0'), str_pad($rightDigits, $width, '0')) <=> 0;
		}
		return $direction * $order;
	}

	private static function shortest(float $value): string {
		$precision = ini_get('serialize_precision');
		try {
			if ($precision !== '-1') {
				ini_set('serialize_precision', '-1');
			}
			return json_encode($value, JSON_THROW_ON_ERROR);
		} finally {
			if ($precision !== false && $precision !== '-1') {
				ini_set('serialize_precision', $precision);
			}
		}
	}

	/** @return array{string,string,int} Sign, significant digits, decimal power. */
	private static function parts(string $value): array {
		preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $value, $match);
		$fraction = $match[3] ?? '';
		$digits = ltrim($match[2] . $fraction, '0');
		if ($digits === '') {
			return ['', '0', 0];
		}
		$significant = rtrim($digits, '0');
		return [$match[1], $significant, (int)($match[4] ?? 0) - strlen($fraction) + strlen($digits) - strlen($significant)];
	}
}
