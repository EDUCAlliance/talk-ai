<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\ToolProvider;

use OCA\EducAI\Service\AgentExecutor;
use OCA\EducAI\Service\CanonicalLinkResolver;
use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\CatalogueLinkService;
use OCA\EducAI\Service\CatalogueReferenceLedger;
use OCA\EducAI\Service\ToolExecutionPolicyService;
use OCA\EducAI\ToolProvider\CatalogueToolProvider;
use PHPUnit\Framework\Attributes\DataProvider;
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

	public function testSearchThatCouldNotRunTellsTheModelInsteadOfReportingNoCourses(): void {
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->method('search')->willReturn(['courses' => [], 'total' => 0, 'error' => 'rate_limited', 'retry_after_seconds' => 150]);
		$embeddings->method('searchPast')->willReturn(['courses' => [], 'total' => 0, 'error' => 'search_failed']);
		$embeddings->expects($this->never())->method('getStats');
		$provider = new CatalogueToolProvider($embeddings, new ToolExecutionPolicyService(), $this->createMock(LoggerInterface::class));

		$current = $provider->executeTool('catalogue_search', ['query' => 'physics']);
		$this->assertTrue($current['isError']);
		$this->assertSame('rate_limited', $current['error']['code']);
		$this->assertSame(['retry_after_seconds' => 150], $current['error']['details']);
		$this->assertStringContainsString('3 minute(s)', $current['error']['message']);

		$past = $provider->executeTool('catalogue_past_search', ['query' => 'physics']);
		$this->assertTrue($past['isError']);
		$this->assertSame('search_failed', $past['error']['code']);
		$this->assertStringNotContainsString('No learning opportunities found', $past['content'][0]['text']);
	}

	/** @return array{0:CatalogueToolProvider,1:CatalogueReferenceLedger} */
	private function linkedProvider(CatalogueEmbeddingService $embeddings, ?string $template = 'https://catalogue.educ.example/Opportunity/{id}'): array {
		$resolver = CanonicalLinkResolver::fromConfig(null, $template, []);
		$links = $this->createMock(CatalogueLinkService::class);
		$links->method('resolve')->willReturnCallback(static fn (array $record): array => $resolver->resolve($record));
		$ledger = new CatalogueReferenceLedger();
		$ledger->begin();
		return [new CatalogueToolProvider($embeddings, new ToolExecutionPolicyService(), $this->createMock(LoggerInterface::class), $links, $ledger), $ledger];
	}

	public function testAllCatalogueToolsShowTheSameCanonicalLinkAndSeparateApplicationLink(): void {
		$record = [
			'id' => 777,
			'title' => 'AI Basics',
			'summary' => 'Intro',
			'permalink' => '/Opportunity/777',
			'application_link' => 'https://apply.partner.example/777',
		];
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->method('search')->willReturn(['courses' => [$record + ['_score' => 0.9]], 'total' => 1]);
		$embeddings->method('searchPast')->willReturn(['courses' => [$record + ['_score' => 0.8]], 'total' => 1]);
		$embeddings->method('getCourseFromEmbeddings')->willReturnCallback(static fn (int $id, bool $isPast): ?array => $id === 777 && !$isPast ? $record + ['is_past' => false] : null);
		[$provider, $ledger] = $this->linkedProvider($embeddings);

		$outputs = [
			$provider->executeTool('catalogue_search', ['query' => 'ai'])['content'][0]['text'],
			$provider->executeTool('catalogue_past_search', ['query' => 'ai'])['content'][0]['text'],
			$provider->executeTool('catalogue_get_opportunity', ['opportunity_id' => 777])['content'][0]['text'],
		];

		foreach ($outputs as $output) {
			$this->assertStringContainsString('ID: 777', str_replace('**ID:** 777', 'ID: 777', $output));
			$this->assertStringContainsString('🔗 Course page: https://catalogue.educ.example/Opportunity/777', $output);
			$this->assertStringContainsString('Application link (external application form, not the course page): https://apply.partner.example/777', $output);
			$this->assertStringContainsString('{{course:ID}}', $output);
			$this->assertStringNotContainsString('](/Opportunity', $output);
			$this->assertLessThanOrEqual(AgentExecutor::TOOL_OUTPUT_LIMIT, mb_strlen($output, 'UTF-8'));
		}
		$this->assertSame(['777'], array_map('strval', array_keys($ledger->records())));
		$this->assertSame('https://catalogue.educ.example/Opportunity/777', $ledger->records()['777']['canonical_url']);
	}

	public function testMissingLinkConfigurationSaysCourseLinkIsUnavailable(): void {
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->method('search')->willReturn(['courses' => [['id' => 5, 'title' => 'X']], 'total' => 1]);
		[$provider] = $this->linkedProvider($embeddings, null);

		$text = $provider->executeTool('catalogue_search', ['query' => 'x'])['content'][0]['text'];

		$this->assertStringContainsString('Course page: not available (no verified course link)', $text);
	}

	/** @return iterable<string,array{array<string,mixed>,?string}> */
	public static function unavailableCourseLinks(): iterable {
		foreach (['absent' => [], 'null' => ['id' => null]] as $case => $identity) {
			yield $case . ' ID with template' => [$identity, 'https://catalogue.educ.example/Opportunity/{id}'];
			yield $case . ' ID without template' => [$identity, null];
		}
		yield 'ID without template' => [['id' => 777], null];
	}

	#[DataProvider('unavailableCourseLinks')]
	public function testMissingIdOrTemplateKeepsTheTitleWithoutInventingACourseLink(array $identity, ?string $template): void {
		$record = $identity + ['title' => 'Preserved course title'];
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->method('search')->willReturn(['courses' => [$record], 'total' => 1]);
		$embeddings->method('searchPast')->willReturn(['courses' => [$record], 'total' => 1]);
		$embeddings->method('getCourseFromEmbeddings')->with(777, false)->willReturn($record);
		[$provider, $ledger] = $this->linkedProvider($embeddings, $template);

		foreach ([
			'catalogue_search' => ['query' => 'preserved'],
			'catalogue_past_search' => ['query' => 'preserved'],
			'catalogue_get_opportunity' => ['opportunity_id' => 777],
		] as $tool => $arguments) {
			$result = $provider->executeTool($tool, $arguments);
			$this->assertFalse($result['isError']);
			$text = $result['content'][0]['text'];
			$this->assertStringContainsString('Preserved course title', $text);
			$this->assertStringContainsString('Course page: not available (no verified course link)', $text);
			$this->assertStringNotContainsString('https://', $text);
			if (!isset($identity['id'])) {
				$this->assertStringNotContainsString('ID:', $text);
				$this->assertStringNotContainsString('**ID:**', $text);
			}
		}
		if (!isset($identity['id'])) {
			$this->assertSame([], $ledger->records(), 'The lookup argument must not become a fabricated record identity');
		}
	}

	public function testSearchKeepsWholeEntriesWithinTheToolBudget(): void {
		$courses = [];
		for ($id = 1; $id <= 20; $id++) {
			$courses[] = [
				'id' => $id,
				'title' => str_repeat('Long title ', 30),
				'summary' => str_repeat('Summary text ', 40),
				'application_link' => 'https://apply.partner.example/' . str_repeat('segment/', 40) . $id,
				'discipline' => ['name' => str_repeat('Discipline ', 10)],
			];
		}
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->method('search')->willReturn(['courses' => $courses, 'total' => 25]);
		[$provider, $ledger] = $this->linkedProvider($embeddings);

		$text = $provider->executeTool('catalogue_search', ['query' => 'long', 'limit' => 20])['content'][0]['text'];

		$this->assertLessThanOrEqual(AgentExecutor::TOOL_OUTPUT_LIMIT, mb_strlen($text, 'UTF-8'));
		$this->assertStringContainsString('omitted to stay within the tool output limit', $text);
		$shown = count($ledger->records());
		$this->assertGreaterThan(0, $shown);
		$this->assertLessThan(20, $shown);
		$this->assertSame($shown, substr_count($text, '🔗 Course page: https://catalogue.educ.example/Opportunity/'));
		$this->assertStringContainsString('Found ' . $shown . ' learning', $text);
		$this->assertStringEndsWith('say so.', $text);
	}

	public function testDetailShortensLongSectionsButKeepsIdentityAndLinks(): void {
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->method('getCourseFromEmbeddings')->willReturn([
			'id' => 9,
			'title' => 'Big course',
			'summary' => str_repeat('S', 1500),
			'description' => str_repeat('D', 5000),
			'prerequisites' => str_repeat('P', 3000),
			'application_link' => 'https://apply.partner.example/9',
			'is_past' => true,
		]);
		[$provider] = $this->linkedProvider($embeddings);

		$text = $provider->executeTool('catalogue_get_opportunity', ['opportunity_id' => 9, 'is_past' => true])['content'][0]['text'];

		$this->assertLessThanOrEqual(AgentExecutor::TOOL_OUTPUT_LIMIT, mb_strlen($text, 'UTF-8'));
		$this->assertStringStartsWith("# Big course\n\n**Status:** ⏳ PAST/ARCHIVED\n**ID:** 9\n🔗 Course page: https://catalogue.educ.example/Opportunity/9\n", $text);
		$this->assertStringContainsString('… [shortened]', $text);
		$this->assertStringContainsString('section(s) omitted', $text);
		$this->assertStringContainsString('{{course:ID}}', $text);
	}
	public function testOversizedIndivisibleDetailIsAnErrorNotAClippedUrlOrShownReference(): void {
		$canonical = 'https://catalogue.educ.example/Opportunity/777?x=';
		$canonical .= str_repeat('a', CanonicalLinkResolver::MAX_URL_LENGTH - strlen($canonical));
		$application = 'https://apply.partner.example/form?x=';
		$application .= str_repeat('b', CanonicalLinkResolver::MAX_URL_LENGTH - strlen($application));
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->method('getCourseFromEmbeddings')->willReturn([
			'id' => 777, 'title' => str_repeat('T', 200), 'code' => str_repeat('C', 100),
			'permalink' => $canonical, 'application_link' => $application,
			'summary' => str_repeat('Description ', 1000),
		]);
		[$provider, $ledger] = $this->linkedProvider($embeddings);

		$result = $provider->executeTool('catalogue_get_opportunity', ['opportunity_id' => 777]);
		$this->assertTrue($result['isError']);
		$this->assertSame('result_record_too_large', $result['error']['code']);
		$this->assertSame([], $ledger->records());
		$text = $result['content'][0]['text'];
		$this->assertLessThanOrEqual(AgentExecutor::TOOL_OUTPUT_LIMIT, mb_strlen($text, 'UTF-8'));
		$executor = (new \ReflectionClass(AgentExecutor::class))->newInstanceWithoutConstructor();
		$this->assertSame($text, (new \ReflectionMethod(AgentExecutor::class, 'sanitizeOutput'))->invoke($executor, $result));
	}

	public function testLongButFittingDetailLinksRemainWholeAfterAgentSanitizer(): void {
		$canonical = 'https://catalogue.educ.example/Opportunity/777?x=' . str_repeat('a', 1400);
		$application = 'https://apply.partner.example/form?x=' . str_repeat('b', 1400);
		$embeddings = $this->createMock(CatalogueEmbeddingService::class);
		$embeddings->method('isEnabled')->willReturn(true);
		$embeddings->method('getCourseFromEmbeddings')->willReturn([
			'id' => 777, 'title' => 'Long links', 'permalink' => $canonical,
			'application_link' => $application, 'description' => str_repeat('Description ', 1000),
		]);
		[$provider, $ledger] = $this->linkedProvider($embeddings);
		$result = $provider->executeTool('catalogue_get_opportunity', ['opportunity_id' => 777]);
		$this->assertFalse($result['isError']);
		$executor = (new \ReflectionClass(AgentExecutor::class))->newInstanceWithoutConstructor();
		$text = (new \ReflectionMethod(AgentExecutor::class, 'sanitizeOutput'))->invoke($executor, $result);
		$this->assertSame($result['content'][0]['text'], $text);
		$this->assertStringContainsString($canonical, $text);
		$this->assertStringContainsString($application, $text);
		$this->assertArrayHasKey('777', $ledger->records());
	}

}
