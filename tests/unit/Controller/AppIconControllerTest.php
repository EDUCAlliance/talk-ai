<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\AppIconController;
use OCA\EducAI\Service\AppIconService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AppIconControllerTest extends TestCase {
	public function testUploadWithoutFileReturnsLocalizedStableError(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('icon')->willReturn(null);
		$l10n = $this->createL10n();
		$controller = new AppIconController(
			'educai',
			$request,
			$this->createMock(AppIconService::class),
			$this->createMock(IURLGenerator::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->upload('black');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('icon_upload_missing', $response->getData()['errorCode']);
		$this->assertSame('translated:No SVG file was uploaded.', $response->getData()['error']);
	}

	public function testUploadStorageFailureDoesNotExposeRawError(): void {
		$tmpPath = sys_get_temp_dir() . '/educai-app-icon-' . bin2hex(random_bytes(8)) . '.svg';
		file_put_contents($tmpPath, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('icon')->willReturn([
			'error' => UPLOAD_ERR_OK,
			'name' => 'icon.svg',
			'tmp_name' => $tmpPath,
		]);
		$appIconService = $this->createMock(AppIconService::class);
		$appIconService->method('storeUploadedIcon')->willThrowException(new \RuntimeException('storage secret'));
		$controller = new AppIconController(
			'educai',
			$request,
			$appIconService,
			$this->createMock(IURLGenerator::class),
			$this->createL10n(),
			$this->createMock(LoggerInterface::class),
		);

		try {
			$response = $controller->upload('black');
		} finally {
			if (is_file($tmpPath)) {
				unlink($tmpPath);
			}
		}

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('icon_upload_failed', $response->getData()['errorCode']);
		$this->assertSame('translated:Failed to upload the app icon', $response->getData()['error']);
		$this->assertStringNotContainsString('secret', $response->getData()['error']);
	}

	private function createL10n(): IL10N {
		$l10nBuilder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$l10nBuilder->addMethods(['t']);
		}
		$l10n = $l10nBuilder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'translated:' . $text);

		return $l10n;
	}
}
