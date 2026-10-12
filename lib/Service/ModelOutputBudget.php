<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

/** Optional provider capacities; never guess them from a model's name. */
final class ModelOutputBudget {
	private const CONTEXT_RESERVE = 1024;

	/** ponytail: rough text estimate shared with history; use a provider tokenizer if exact counts are needed. */
	public static function estimateTokens(string $text): int {
		return (int)ceil(mb_strlen($text, 'UTF-8') / 4);
	}

	/** @return array{output_tokens?:int,context_tokens?:int} */
	public static function fromModel(array $model): array {
		$provider = is_array($model['top_provider'] ?? null) ? $model['top_provider'] : [];
		return self::normalize([
			'output_tokens' => self::smallestPositive([
				$model['max_output_tokens'] ?? null,
				$model['max_completion_tokens'] ?? null,
				$provider['max_completion_tokens'] ?? null,
			]),
			'context_tokens' => self::smallestPositive([
				$model['context_length'] ?? null,
				$model['max_model_len'] ?? null,
				$provider['context_length'] ?? null,
			]),
		]);
	}

	/** @return array{output_tokens?:int,context_tokens?:int} */
	public static function normalize(array $limits): array {
		$result = [];
		foreach (['output_tokens', 'context_tokens'] as $key) {
			$value = self::smallestPositive([$limits[$key] ?? null]);
			if ($value !== null) {
				$result[$key] = $value;
			}
		}
		return $result;
	}

	public static function constrain(int $requested, array $messages, array $tools, array $limits): int {
		$limits = self::normalize($limits);
		$budget = min($requested, $limits['output_tokens'] ?? $requested);
		if (isset($limits['context_tokens'])) {
			$input = json_encode(['messages' => $messages, 'tools' => $tools],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
			$available = $limits['context_tokens'] - self::estimateTokens($input) - self::CONTEXT_RESERVE;
			// A heuristic can size output, but only the provider can confirm an overflow.
			$budget = min($budget, max(1, $available));
		}
		return $budget;
	}

	private static function smallestPositive(array $values): ?int {
		$valid = [];
		foreach ($values as $value) {
			if ((is_int($value) || (is_string($value) && preg_match('/^[0-9]{1,10}$/D', $value) === 1))
				&& $value > 0 && $value <= 2147483647) {
				$valid[] = (int)$value;
			}
		}
		return $valid === [] ? null : min($valid);
	}
}
