<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Service\AttachmentResolver;
use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttachmentResolverTest extends TestCase {
	public function testResolveSourceFileFallsBackToOwnerTalkFolderPath(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$file = $this->createMock(File::class);

		$rootFolder->expects($this->once())
			->method('getById')
			->with(1512)
			->willReturn([]);
		$rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('admin')
			->willReturn($userFolder);

		$userFolder->expects($this->once())
			->method('get')
			->with('Talk/2025_03_03_EDUC_THINK_LAB_ThomasRoese_TR96206.jpg')
			->willReturn($file);

		$resolver = new AttachmentResolver(
			$rootFolder,
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class)
		);

		$attachment = new IncomingTalkAttachment(
			IncomingTalkAttachment::KIND_IMAGE,
			'file',
			'image/jpeg',
			'2025_03_03_EDUC_THINK_LAB_ThomasRoese_TR96206.jpg',
			'file',
			[
				'id' => '1512',
				'path' => '2025_03_03_EDUC_THINK_LAB_ThomasRoese_TR96206.jpg',
				'_educai_owner_uid' => 'admin',
			]
		);

		$this->assertSame($file, $resolver->resolveSourceFile($attachment));
	}
}
