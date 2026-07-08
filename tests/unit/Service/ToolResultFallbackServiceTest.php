<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Service\ToolResultFallbackService;
use PHPUnit\Framework\TestCase;

class ToolResultFallbackServiceTest extends TestCase {
	public function testDocumentToolTraceProducesDocumentFallback(): void {
		$service = new ToolResultFallbackService();

		$result = $service->generateFromTrace([
			[
				'tool' => 'rag_search_documents',
				'status' => 'ok',
				'response' => "Found 1 relevant document chunk(s)\n\nSource 1\n\nContent",
			],
		]);

		$this->assertStringStartsWith("Based on the documents found:\n\n", $result);
		$this->assertStringContainsString('Source 1', $result);
	}

	public function testDocumentFallbackUsesFirstResultAndTruncatesAtExistingLimit(): void {
		$service = new ToolResultFallbackService();
		$first = 'Found first result ' . str_repeat('a', 3100);

		$result = $service->generateFromTrace([
			[
				'tool' => 'rag_search_documents',
				'status' => 'ok',
				'response' => $first,
			],
			[
				'tool' => 'room_search_documents',
				'status' => 'ok',
				'response' => 'Found second result',
			],
		]);

		$this->assertStringContainsString('Found first result', $result);
		$this->assertStringNotContainsString('Found second result', $result);
		$this->assertStringEndsWith("... (more results available)", $result);
	}
}
