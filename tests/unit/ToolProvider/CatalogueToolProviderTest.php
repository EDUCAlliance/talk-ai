<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\ToolProvider;

use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\ToolExecutionPolicyService;
use OCA\EducAI\ToolProvider\CatalogueToolProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatalogueToolProviderTest extends TestCase {
	public function testDisabledProviderListsNothingAndRejectsExistingLoadoutNames(): void {
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(false);
		$embeddings->expects($this->never())->method('search');
		$embeddings->expects($this->never())->method('searchPast');
		$embeddings->expects($this->never())->method('getCourseFromEmbeddings');
		$provider = new CatalogueToolProvider($embeddings, new ToolExecutionPolicyService(), $this->createMock(LoggerInterface::class));

		$this->assertSame([], $provider->getTools());
		foreach (['catalogue_search', 'catalogue_past_search', 'catalogue_get_opportunity', 'catalogue_search_courses'] as $name) {
			// Recognizing an existing assignment does not make it available or usable.
			$this->assertTrue($provider->providesTool($name));
			$this->assertTrue($provider->executeTool($name, ['query' => 'physics', 'opportunity_id' => 42])['isError']);
		}
	}

	public function testEnabledProviderRetainsCanonicalToolIdsAndLegacySearchAlias(): void {
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->expects($this->exactly(2))->method('search')->with('physics', 5)
			->willReturn(['courses' => [['id' => 42, 'title' => 'Physics']], 'total' => 1]);
		$provider = new CatalogueToolProvider($embeddings, new ToolExecutionPolicyService(), $this->createMock(LoggerInterface::class));

		$this->assertSame(['catalogue_search', 'catalogue_past_search', 'catalogue_get_opportunity'], array_column($provider->getTools(), 'name'));
		$this->assertSame(['catalogue_search_courses'], $provider->getTools()[0]['aliases']);
		$canonical = $provider->executeTool('catalogue_search', ['query' => 'physics']);
		$this->assertFalse($canonical['isError']);
		$this->assertStringContainsString('Physics', $canonical['content'][0]['text']);
		$this->assertSame($canonical, $provider->executeTool('catalogue_search_courses', ['query' => 'physics']));
	}
}
