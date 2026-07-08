<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

/**
 * Produces deterministic recovery text when the model cannot synthesize tool results.
 */
class ToolResultFallbackService {
	/**
	 * @param array<int,array<string,mixed>> $trace
	 */
	public function generateFromTrace(array $trace): string {
		if (count($trace) === 0) {
			return 'I was unable to find the requested information. Please try rephrasing your question.';
		}

		$documentSearchResults = [];
		foreach ($trace as $invocation) {
			$tool = $invocation['tool'] ?? '';
			$status = $invocation['status'] ?? 'error';
			$response = $invocation['response'] ?? '';

			if (
				in_array($tool, ['rag_search_documents', 'room_search_documents'], true)
				&& $status === 'ok'
				&& $response !== ''
			) {
				$text = $this->extractReadableToolResponse((string)$response);
				if ($text !== null) {
					$documentSearchResults[] = $text;
				}
			}
		}

		if (count($documentSearchResults) > 0) {
			$output = "Based on the documents found:\n\n";
			$firstResult = $documentSearchResults[0];
			$firstResult = preg_replace('/\n{3,}/', "\n\n", $firstResult);

			if (strlen($firstResult) > 3000) {
				$firstResult = substr($firstResult, 0, 3000) . "\n\n... (more results available)";
			}

			$output .= $firstResult;

			return $output;
		}

		return 'I used the available tools but could not generate a complete response. Please try again with a more specific request.';
	}

	private function extractReadableToolResponse(string $response): ?string {
		$trimmed = trim($response);
		if ($trimmed === '') {
			return null;
		}

		$decoded = $this->extractJsonFromContent($trimmed);
		if ($decoded !== null) {
			$text = $this->extractTextFromToolPayload($decoded);
			if ($text !== null && trim($text) !== '') {
				return trim($text);
			}
		}

		if (preg_match_all('/"text"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $trimmed, $matches)) {
			$parts = [];
			foreach ($matches[1] as $encodedText) {
				$decodedText = json_decode('"' . $encodedText . '"');
				if (is_string($decodedText) && trim($decodedText) !== '') {
					$parts[] = trim($decodedText);
				}
			}

			if (count($parts) > 0) {
				return implode("\n\n", $parts);
			}
		}

		if (str_contains($trimmed, 'Found') || str_contains($trimmed, 'Source')) {
			return $trimmed;
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function extractTextFromToolPayload(array $payload): ?string {
		$parts = [];

		if (isset($payload['content']) && is_array($payload['content'])) {
			foreach ($payload['content'] as $item) {
				if (is_array($item) && ($item['type'] ?? null) === 'text' && isset($item['text']) && is_string($item['text'])) {
					$text = trim($item['text']);
					if ($text !== '') {
						$parts[] = $text;
					}
				} elseif (is_string($item)) {
					$text = trim($item);
					if ($text !== '') {
						$parts[] = $text;
					}
				}
			}
		}

		if (count($parts) > 0) {
			return implode("\n\n", $parts);
		}

		if (isset($payload['text']) && is_string($payload['text']) && trim($payload['text']) !== '') {
			return trim($payload['text']);
		}

		return null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function extractJsonFromContent(string $content): ?array {
		$content = trim($content);
		$direct = $this->tryParseJson($content);
		if ($direct !== null) {
			return $direct;
		}

		if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
			$jsonStr = trim($matches[1]);
			$decoded = json_decode($jsonStr, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		if (preg_match('/(\[\s*\{[\s\S]*\}\s*\]|\{[\s\S]*\})/', $content, $matches)) {
			$jsonStr = trim($matches[1]);
			$decoded = json_decode($jsonStr, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function tryParseJson(string $content): ?array {
		$content = trim($content);
		$firstChar = $content[0] ?? '';
		if ($firstChar !== '{' && $firstChar !== '[') {
			return null;
		}
		$decoded = json_decode($content, true);
		return is_array($decoded) ? $decoded : null;
	}
}
