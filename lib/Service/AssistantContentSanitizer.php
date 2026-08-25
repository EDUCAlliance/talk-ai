<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

final class AssistantContentSanitizer {
	public static function sanitize(string $content): string {
		$sanitized = preg_replace('/<think(?:\s[^>]*)?>.*?<\/think\s*>/is', '', $content);
		if ($sanitized === null) {
			return $content;
		}

		$sanitized = preg_replace('/<think(?:\s[^>]*)?>.*$/is', '', $sanitized) ?? $sanitized;
		$sanitized = preg_replace('/<\/think\s*>/i', '', $sanitized) ?? $sanitized;

		return $sanitized === $content ? $content : trim($sanitized);
	}
}
