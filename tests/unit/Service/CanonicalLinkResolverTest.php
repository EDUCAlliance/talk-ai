<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Exception\CatalogueLinkConfigurationException;
use OCA\EducAI\Service\CanonicalLinkResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalLinkResolverTest extends TestCase {
	private const TEMPLATE = 'https://catalogue.example.org/Opportunity/{id}';

	public function testValidPermalinkOnAcceptedOriginWins(): void {
		$resolver = CanonicalLinkResolver::fromConfig('/permalink', self::TEMPLATE, ['https://courses.example.org']);

		$resolved = $resolver->resolve(['id' => 7, 'permalink' => 'https://Courses.Example.org:443/c/7-intro']);

		$this->assertSame('https://courses.example.org/c/7-intro', $resolved['canonical_url']);
		$this->assertSame(CanonicalLinkResolver::SOURCE_RECORD, $resolved['source']);
		$this->assertSame([], $resolved['issues']);
	}

	public function testTemplateIsUsedWhenNoPermalinkExists(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, []);

		$resolved = $resolver->resolve(['id' => 777, 'title' => 'AI']);

		$this->assertSame('https://catalogue.example.org/Opportunity/777', $resolved['canonical_url']);
		$this->assertSame(CanonicalLinkResolver::SOURCE_TEMPLATE, $resolved['source']);
		$this->assertSame('777', $resolved['id']);
	}

	public function testRelativePermalinkIsRejectedVisiblyAndTemplateApplies(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, []);

		$resolved = $resolver->resolve(['id' => 5, 'permalink' => '/Opportunity/5']);

		$this->assertSame('https://catalogue.example.org/Opportunity/5', $resolved['canonical_url']);
		$this->assertContains(CanonicalLinkResolver::ISSUE_RELATIVE_LINK, $resolved['issues']);
	}

	public function testProtocolRelativePermalinkCountsAsRelative(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, null, ['https://catalogue.example.org']);

		$resolved = $resolver->resolve(['id' => 5, 'permalink' => '//catalogue.example.org/Opportunity/5']);

		$this->assertNull($resolved['canonical_url']);
		$this->assertContains(CanonicalLinkResolver::ISSUE_RELATIVE_LINK, $resolved['issues']);
	}

	public function testPermalinkOnForeignOriginIsNotTrusted(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, []);

		$resolved = $resolver->resolve(['id' => 9, 'permalink' => 'https://catalogue.test.example.org/Opportunity/9']);

		$this->assertSame('https://catalogue.example.org/Opportunity/9', $resolved['canonical_url']);
		$this->assertContains(CanonicalLinkResolver::ISSUE_ORIGIN_NOT_ACCEPTED, $resolved['issues']);
	}

	public function testApiHostIsNeverDerivedWithoutConfiguration(): void {
		$resolver = CanonicalLinkResolver::unconfigured();

		$resolved = $resolver->resolve(['id' => 3, 'permalink' => 'https://catalogue.example.org/api/Opportunity/3']);

		$this->assertNull($resolved['canonical_url']);
		$this->assertSame(CanonicalLinkResolver::SOURCE_NONE, $resolved['source']);
		$this->assertContains(CanonicalLinkResolver::ISSUE_NO_TEMPLATE, $resolved['issues']);
		$this->assertFalse($resolver->isConfigured());
	}

	public function testApplicationLinkStaysSeparateFromCourseLink(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, []);

		$resolved = $resolver->resolve(['id' => 4, 'application_link' => 'https://apply.partner.example/form?x=1']);

		$this->assertSame('https://catalogue.example.org/Opportunity/4', $resolved['canonical_url']);
		$this->assertSame('https://apply.partner.example/form?x=1', $resolved['application_url']);
	}

	public function testInvalidApplicationLinkIsDropped(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, []);

		$resolved = $resolver->resolve(['id' => 4, 'application_link' => 'javascript:alert(1)']);

		$this->assertNull($resolved['application_url']);
		$this->assertContains(CanonicalLinkResolver::ISSUE_INVALID_APPLICATION_LINK, $resolved['issues']);
	}

	public function testStringIdsAreEncodedAndDotSegmentsRejected(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, []);

		$this->assertSame('https://catalogue.example.org/Opportunity/a%2Fb%3Fc', $resolver->resolve(['id' => 'a/b?c'])['canonical_url']);
		$this->assertNull($resolver->resolve(['id' => '..'])['canonical_url']);
		$this->assertNull($resolver->resolve(['id' => "7\n"])['canonical_url']);
		$this->assertNull($resolver->resolve(['id' => -1])['canonical_url']);
		$this->assertNull($resolver->resolve(['id' => 1.5])['canonical_url']);
	}

	public function testQueryValuePlaceholderIsSupported(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, 'https://catalogue.example.org/view?lang=en&id={id}', []);

		$this->assertSame('https://catalogue.example.org/view?lang=en&id=12', $resolver->resolve(['id' => 12])['canonical_url']);
		$this->assertSame('12', $resolver->matchCourseRoute('https://other.example/view?lang=en&id=12'));
	}

	public function testMatchCourseRouteIsHostAgnostic(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, []);

		$this->assertSame('777', $resolver->matchCourseRoute('https://catalogue.test.example.org/Opportunity/777'));
		$this->assertSame('777', $resolver->matchCourseRoute('http://catalogue.example.org/Opportunity/777'));
		$this->assertNull($resolver->matchCourseRoute('https://catalogue.example.org/Opportunity/777/extra'));
		$this->assertNull($resolver->matchCourseRoute('https://catalogue.example.org/Other/777'));
	}

	public function testTemplateOriginIsAcceptedVisibly(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, self::TEMPLATE, ['https://courses.example.org/']);

		$this->assertSame(['https://courses.example.org'], $resolver->configuredOrigins());
		$this->assertSame(['https://courses.example.org', 'https://catalogue.example.org'], $resolver->acceptedOrigins());
		$this->assertSame('https://catalogue.example.org', $resolver->templateOrigin());
	}

	public function testMarkdownBreakingCharactersAreEncoded(): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, null, ['https://catalogue.example.org']);

		$resolved = $resolver->resolve(['id' => 1, 'permalink' => 'https://catalogue.example.org/c/(1)']);

		$this->assertSame('https://catalogue.example.org/c/%281%29', $resolved['canonical_url']);
	}

	/** @return array<string,array{0:string}> */
	public static function unsafeLinks(): array {
		return [
			'userinfo' => ['https://user:pw@catalogue.example.org/c/1'],
			'http' => ['http://catalogue.example.org/c/1'],
			'control character' => ["https://catalogue.example.org/c/1\t2"],
			'encoded control character' => ['https://catalogue.example.org/c/%0d%0aSet-Cookie'],
			'malformed escape' => ['https://catalogue.example.org/c/%zz'],
			'dot segment' => ['https://catalogue.example.org/c/../admin'],
			'encoded dot segment' => ['https://catalogue.example.org/c/%2e%2e/admin'],
			'backslash' => ['https://catalogue.example.org\\@evil.example/c/1'],
			'non-ascii host' => ['https://catalоgue.example.org/c/1'],
			'quote' => ['https://catalogue.example.org/c/1"onclick'],
			'javascript' => ['javascript://catalogue.example.org/%0aalert(1)'],
		];
	}

	#[DataProvider('unsafeLinks')]
	public function testUnsafePermalinksAreRejected(string $link): void {
		$resolver = CanonicalLinkResolver::fromConfig(null, null, ['https://catalogue.example.org']);

		$resolved = $resolver->resolve(['id' => 1, 'permalink' => $link]);

		$this->assertNull($resolved['canonical_url']);
		$this->assertNotSame([], $resolved['issues']);
	}

	/** @return array<string,array{0:?string,1:?string,2:array<mixed>,3:string,4:string}> */
	public static function invalidConfigurations(): array {
		return [
			'pointer' => ['permalink', null, [], 'link_field', 'invalid_pointer'],
			'http template' => [null, 'http://catalogue.example.org/{id}', [], 'link_template', 'template_scheme'],
			'missing placeholder' => [null, 'https://catalogue.example.org/Opportunity', [], 'link_template', 'template_placeholder'],
			'two placeholders' => [null, 'https://catalogue.example.org/{id}/{id}', [], 'link_template', 'template_placeholder'],
			'other placeholder' => [null, 'https://catalogue.example.org/{lang}/{id}', [], 'link_template', 'template_placeholder'],
			'host placeholder' => [null, 'https://{id}.example.org/', [], 'link_template', 'template_authority'],
			'query key placeholder' => [null, 'https://catalogue.example.org/view?{id}=1', [], 'link_template', 'template_placeholder'],
			'fragment placeholder' => [null, 'https://catalogue.example.org/view#{id}', [], 'link_template', 'template_placeholder'],
			'userinfo template' => [null, 'https://a@catalogue.example.org/{id}', [], 'link_template', 'template_invalid'],
			'origin with path' => [null, null, ['https://catalogue.example.org/api'], 'link_origins', 'invalid_origin'],
			'http origin' => [null, null, ['http://catalogue.example.org'], 'link_origins', 'invalid_origin'],
			'non-string origin' => [null, null, [42], 'link_origins', 'invalid_origin'],
		];
	}

	/** @param array<mixed> $origins */
	#[DataProvider('invalidConfigurations')]
	public function testInvalidConfigurationIsRejected(?string $field, ?string $template, array $origins, string $expectedField, string $expectedReason): void {
		try {
			CanonicalLinkResolver::fromConfig($field, $template, $origins);
			$this->fail('Configuration was accepted');
		} catch (CatalogueLinkConfigurationException $e) {
			$this->assertSame($expectedField, $e->getField());
			$this->assertSame($expectedReason, $e->getReason());
		}
	}

	public function testCustomLinkFieldPointer(): void {
		$resolver = CanonicalLinkResolver::fromConfig('/links/web', null, ['https://catalogue.example.org']);

		$resolved = $resolver->resolve(['id' => 1, 'links' => ['web' => 'https://catalogue.example.org/c/1']]);

		$this->assertSame('https://catalogue.example.org/c/1', $resolved['canonical_url']);
	}
}
