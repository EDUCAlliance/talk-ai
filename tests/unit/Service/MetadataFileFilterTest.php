<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Service\MetadataFileFilter;
use OCA\EducAI\Webhook\TalkAttachmentNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MetadataFileFilterTest extends TestCase {
	#[DataProvider('paths')]
	public function testFiltersMetadataButKeepsDocuments(string $path, bool $metadata): void {
		$this->assertSame($metadata, MetadataFileFilter::isMetadataPath($path));
		$attachments = (new TalkAttachmentNormalizer())->normalize([
			'metadata' => ['type' => 'file', 'mimetype' => 'application/pdf', 'name' => $path],
			'document' => ['type' => 'file', 'mimetype' => 'application/pdf', 'name' => 'useful.pdf'],
		]);
		$this->assertCount($metadata ? 1 : 2, $attachments);
		$this->assertSame('useful.pdf', $attachments[count($attachments) - 1]->getDisplayName());
	}

	public static function paths(): array {
		return [
			['._document.pdf', true], ['nested/._image.png', true], ['.DS_Store', true],
			['Thumbs.db', true], ['eHThumbs.DB', true], ['desktop.ini', true],
			['.localized', true], ["icon\r", true], ['__MACOSX/document.pdf', true],
			['folder/__macosx/nested/document.pdf', true], ['folder\\__MACOSX\\document.pdf', true],
			['.Trashes/document.pdf', true], ['.Spotlight-V100/document.pdf', true],
			['.fseventsd/document.pdf', true], ['.TemporaryItems/document.pdf', true], ['.apdisk/document.pdf', true],
			['document.pdf', false], ['__MACOSX-notes.pdf', false], ['notes/.DS_Store.pdf', false],
		];
	}
}
