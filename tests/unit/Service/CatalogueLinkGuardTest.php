<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Service\CanonicalLinkResolver;
use OCA\EducAI\Service\CatalogueEmbeddingService;
use OCA\EducAI\Service\CatalogueLinkGuard;
use OCA\EducAI\Service\CatalogueLinkService;
use OCA\EducAI\Service\CatalogueReferenceLedger;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatalogueLinkGuardTest extends TestCase {
	private const LIVE = 'https://catalogue.educ.example';
	private const TEMPLATE = self::LIVE . '/Opportunity/{id}';

	private CatalogueReferenceLedger $ledger;
	/** @var array<int,array<string,mixed>> */
	private array $index = [];
	private CatalogueLinkGuard $guard;
	private IL10N $l10n;

	protected function setUp(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, []);
		$links = $this->createMock(CatalogueLinkService::class);
		$links->method('resolver')->willReturn($resolver);
		$links->method('resolve')->willReturnCallback(static fn (array $record): array => $resolver->resolve($record));

		$catalogue = $this->createMock(CatalogueEmbeddingService::class);
		$catalogue->method('isEnabled')->willReturn(true);
		$catalogue->method('getCourseFromEmbeddings')->willReturnCallback(
			fn (int $id, bool $isPast): ?array => $isPast ? null : ($this->index[$id] ?? null)
		);

		$this->ledger = new CatalogueReferenceLedger();
		$this->guard = new CatalogueLinkGuard($this->ledger, $links, $catalogue, $this->createMock(LoggerInterface::class));
		$l10nBuilder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$l10nBuilder->addMethods(['t', 'n']);
		}
		$this->l10n = $l10nBuilder->getMock();
		$this->l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->l10n->method('n')->willReturnCallback(static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural));
		$this->guard->beginRun();
	}

	private function verify(int $id, string $title, ?string $application = null, ?string $canonical = 'template'): void {
		$this->ledger->record([
			'id' => (string)$id,
			'title' => $title,
			'canonical_url' => $canonical === 'template' ? self::LIVE . '/Opportunity/' . $id : $canonical,
			'application_url' => $application,
			'is_past' => false,
		]);
	}

	public function testAnswerWithoutCatalogueLinksIsUntouched(): void {
		$answer = 'See [the university](https://www.uni.example/study) for details.';

		$result = $this->guard->enforce($answer, $this->l10n);

		$this->assertSame($answer, $result['text']);
		$this->assertSame(CatalogueLinkGuard::OUTCOME_UNCHANGED, $result['outcome']);
	}

	public function testCanonicalAndApplicationLinksPass(): void {
		$this->verify(777, 'AI Basics', 'https://apply.partner.example/777');
		$answer = 'Try [AI Basics](' . self::LIVE . '/Opportunity/777). Apply at https://apply.partner.example/777.';

		$result = $this->guard->enforce($answer, $this->l10n);

		$this->assertSame($answer, $result['text']);
		$this->assertSame(['777'], $result['referenced_ids']);
	}

	public function testMarkersExpandOnlyFromVerifiedResults(): void {
		$this->verify(777, 'AI *Basics*');

		$result = $this->guard->enforce('Recommended: {{course:777}}.', $this->l10n);

		$this->assertSame(CatalogueLinkGuard::OUTCOME_EXPANDED, $result['outcome']);
		$this->assertSame('Recommended: [AI \\*Basics\\*](' . self::LIVE . '/Opportunity/777).', $result['text']);
	}

	public function testMarkerForCourseWithoutLinkSaysSo(): void {
		$this->verify(12, 'Offline course', null, null);

		$result = $this->guard->enforce('{{course:12}}', $this->l10n);

		$this->assertSame('Offline course (ID: 12, Course link not available)', $result['text']);
	}

	public function testUnverifiedMarkerReplacesAnswer(): void {
		$this->verify(777, 'AI Basics');
		$this->index[999] = ['id' => 999, 'title' => 'Not from a tool'];

		$result = $this->guard->enforce('See {{course:999}}', $this->l10n);

		$this->assertSame(CatalogueLinkGuard::OUTCOME_REPLACED, $result['outcome']);
		$this->assertSame(['unverified_marker'], $result['reasons']);
		$this->assertStringContainsString('**AI Basics** (ID: 777)', $result['text']);
		$this->assertStringNotContainsString('{{course', $result['text']);
	}

	public function testWrongHostForVerifiedIdIsReplacedWithCanonicalList(): void {
		$this->verify(777, 'AI Basics', 'https://apply.partner.example/777');

		$result = $this->guard->enforce('Open [AI Basics](https://catalogue.test.educ.example/Opportunity/777)!', $this->l10n);

		$this->assertSame(CatalogueLinkGuard::OUTCOME_REPLACED, $result['outcome']);
		$this->assertSame(['link_mismatch'], $result['reasons']);
		$this->assertStringNotContainsString('test.educ.example', $result['text']);
		$this->assertStringContainsString('Course page: ' . self::LIVE . '/Opportunity/777', $result['text']);
		$this->assertStringContainsString('Application link (not the course page): https://apply.partner.example/777', $result['text']);
	}

	public function testSchemeVariantIsReplacedButUnrelatedPathIsNot(): void {
		$this->verify(777, 'AI Basics');

		$apiPath = $this->guard->enforce('Link: ' . self::LIVE . '/api/Opportunity/777', $this->l10n);
		$httpVariant = $this->guard->enforce('Link: http://catalogue.educ.example/Opportunity/777', $this->l10n);

		$this->assertSame(CatalogueLinkGuard::OUTCOME_UNCHANGED, $apiPath['outcome']);
		$this->assertSame(CatalogueLinkGuard::OUTCOME_REPLACED, $httpVariant['outcome']);
	}

	public function testUnknownIdOnCatalogueHostIsUnverifiable(): void {
		$this->verify(777, 'AI Basics');

		$result = $this->guard->enforce('Also see ' . self::LIVE . '/Opportunity/31337', $this->l10n);

		$this->assertSame(['unverified_link'], $result['reasons']);
		$this->assertStringContainsString('ID: 777', $result['text']);
	}

	public function testUnknownIdOnUnrelatedHostIsLeftAlone(): void {
		$this->verify(777, 'AI Basics');
		$answer = 'Compare https://other-university.example/Opportunity/31337';

		$this->assertSame($answer, $this->guard->enforce($answer, $this->l10n)['text']);
	}

	public function testCorrectLinkForIndexedCourseFromHistoryPasses(): void {
		$this->index[778] = ['id' => 778, 'title' => 'Robotics'];
		$answer = 'Earlier I mentioned ' . self::LIVE . '/Opportunity/778';

		$this->assertSame($answer, $this->guard->enforce($answer, $this->l10n)['text']);
	}

	public function testWrongHostForIndexedCourseWithoutToolCallIsReplaced(): void {
		$this->index[778] = ['id' => 778, 'title' => 'Robotics'];

		$result = $this->guard->enforce('See https://catalogue.test.educ.example/Opportunity/778', $this->l10n);

		$this->assertSame(CatalogueLinkGuard::OUTCOME_REPLACED, $result['outcome']);
		$this->assertStringContainsString('**Robotics** (ID: 778)', $result['text']);
		$this->assertStringContainsString(self::LIVE . '/Opportunity/778', $result['text']);
	}

	public function testPermalinkPathOnOtherHostIsReplaced(): void {
		$this->verify(5, 'Permalink course', null, 'https://courses.educ.example/c/intro-5');

		$result = $this->guard->enforce('See https://courses-test.example/c/intro-5', $this->l10n);

		$this->assertSame(['link_host_mismatch'], $result['reasons']);
	}

	public function testDerivedRouteFromVerifiedPermalinkDetectsWrongId(): void {
		$this->verify(5, 'Permalink course', null, 'https://courses.educ.example/course/5');

		$result = $this->guard->enforce('See https://courses.educ.example/course/6', $this->l10n);

		$this->assertSame(['unverified_link'], $result['reasons']);
	}

	public function testFallbackListIsBoundedAndEscaped(): void {
		for ($id = 1; $id <= 12; $id++) {
			$this->verify($id, $id === 1 ? 'Evil](https://evil.example) [x' : 'Course ' . $id);
		}

		$result = $this->guard->enforce('See {{course:404}}', $this->l10n);

		$this->assertSame(10, substr_count($result['text'], 'Course page: '));
		$this->assertStringContainsString('2 more verified entries are not shown.', $result['text']);
		$this->assertStringContainsString('**Evil\\](https://evil.example) \\[x**', $result['text']);
		$this->assertStringNotContainsString('**Evil](', $result['text']);
	}

	public function testFallbackPrefersReferencedRecords(): void {
		$this->verify(1, 'First');
		$this->verify(2, 'Second');

		$result = $this->guard->enforce('See https://catalogue.test.educ.example/Opportunity/2', $this->l10n);

		$this->assertStringContainsString('**Second**', $result['text']);
		$this->assertStringNotContainsString('**First**', $result['text']);
	}

	public function testNoVerifiedEntriesYieldsHonestMessage(): void {
		$this->ledger->markUsed();

		$result = $this->guard->enforce('See ' . self::LIVE . '/Opportunity/31337', $this->l10n);

		$this->assertSame(CatalogueLinkGuard::OUTCOME_REPLACED, $result['outcome']);
		$this->assertStringContainsString('no verified catalogue entry is available', $result['text']);
	}

	public function testDisabledCatalogueWithoutToolUseSkipsChecks(): void {
		$catalogue = $this->createMock(CatalogueEmbeddingService::class);
		$catalogue->method('isEnabled')->willReturn(false);
		$catalogue->expects($this->never())->method('getCourseFromEmbeddings');
		$guard = new CatalogueLinkGuard(new CatalogueReferenceLedger(), $this->createMock(CatalogueLinkService::class), $catalogue, $this->createMock(LoggerInterface::class));
		$answer = 'See ' . self::LIVE . '/Opportunity/1 and {{course:1}}';

		$this->assertSame($answer, $guard->enforce($answer, $this->l10n)['text']);
	}

	public function testLedgerIgnoresRecordsOutsideARun(): void {
		$this->guard->endRun();
		$this->verify(1, 'Late');
		$this->guard->beginRun();

		$this->assertSame([], $this->ledger->records());
		$this->assertFalse($this->ledger->wasUsed());
	}
}
