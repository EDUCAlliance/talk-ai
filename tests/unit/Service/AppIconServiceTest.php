<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Service\AppIconService;
use PHPUnit\Framework\TestCase;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class AppIconServiceTest extends TestCase {
	public function testDefaultIconsResolvePerContext(): void {
		$settings = new Settings();
		$service = $this->createService($settings);

		$this->assertSame('/apps/educai/img/app-dark.svg', $service->getSettingsSectionIcon());
		$this->assertSame('/apps/educai/img/app.svg', $service->getAppNavigationIcon());
		$this->assertSame('https://nextcloud.test/apps/educai/img/app-dark.svg', $service->getReferenceIconUrl());
		$this->assertSame([
			'black' => '/apps/educai/img/app-dark.svg',
			'white' => '/apps/educai/img/app.svg',
		], $service->getRuntimeIconUrls());
	}

	public function testCustomIconsResolvePerContext(): void {
		$settings = new Settings();
		$settings->setAppIconMode('custom');
		$settings->setAppIconBlackUrl('/apps/theming/img/educai-black.svg');
		$settings->setAppIconWhiteUrl('https://example.invalid/educai-white.svg');
		$service = $this->createService($settings);

		$this->assertSame('/apps/theming/img/educai-black.svg', $service->getSettingsSectionIcon());
		$this->assertSame('https://example.invalid/educai-white.svg', $service->getAppNavigationIcon());
		$this->assertSame('https://nextcloud.test/apps/theming/img/educai-black.svg', $service->getReferenceIconUrl());
	}

	public function testLegacyIconUrlStillResolves(): void {
		$settings = new Settings();
		$settings->setAppIconUrl('/apps/theming/img/legacy-educai.svg');
		$service = $this->createService($settings);

		$this->assertSame('/apps/theming/img/legacy-educai.svg', $service->getSettingsSectionIcon());
		$this->assertSame('/apps/theming/img/legacy-educai.svg', $service->getAppNavigationIcon());
	}

	public function testUploadedIconReferenceResolvesThroughAppRoute(): void {
		$settings = new Settings();
		$settings->setAppIconMode('custom');
		$settings->setAppIconBlackUrl('educai-upload://black');
		$settings->setAppIconWhiteUrl('educai-upload://white');
		$service = $this->createService($settings);

		$this->assertSame('/apps/educai/api/v1/app-icon/black', $service->getSettingsSectionIcon());
		$this->assertSame('/apps/educai/api/v1/app-icon/white', $service->getAppNavigationIcon());
		$this->assertSame('https://nextcloud.test/apps/educai/api/v1/app-icon/black', $service->getReferenceIconUrl());
		$this->assertTrue($service->isUploadedIconReference('educai-upload://black'));
		$this->assertSame('educai-upload://white', $service->getUploadedIconReference('white'));
	}

	public function testUploadedIconRoutesIncludeFileMtimeCacheBuster(): void {
		$settings = new Settings();
		$settings->setAppIconMode('custom');
		$settings->setAppIconBlackUrl('educai-upload://black');
		$settings->setAppIconWhiteUrl('educai-upload://white');

		$blackFile = $this->createMock(ISimpleFile::class);
		$blackFile->method('getSize')->willReturn(1024);
		$blackFile->method('getMimeType')->willReturn('image/svg+xml');
		$blackFile->method('getName')->willReturn('black.svg');
		$blackFile->method('getMTime')->willReturn(1719920101);

		$whiteFile = $this->createMock(ISimpleFile::class);
		$whiteFile->method('getSize')->willReturn(1024);
		$whiteFile->method('getMimeType')->willReturn('image/svg+xml');
		$whiteFile->method('getName')->willReturn('white.svg');
		$whiteFile->method('getMTime')->willReturn(1719920102);

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')
			->willReturnMap([
				['black.svg', $blackFile],
				['white.svg', $whiteFile],
			]);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')
			->with('app_icons')
			->willReturn($folder);

		$service = $this->createService($settings, appData: $appData);

		$this->assertSame('/apps/educai/api/v1/app-icon/black?v=1719920101', $service->getSettingsSectionIcon());
		$this->assertSame('/apps/educai/api/v1/app-icon/white?v=1719920102', $service->getAppNavigationIcon());
		$this->assertSame([
			'black' => '/apps/educai/api/v1/app-icon/black?v=1719920101',
			'white' => '/apps/educai/api/v1/app-icon/white?v=1719920102',
		], $service->getRuntimeIconUrls());
	}

	public function testUnsupportedLegacyFileReferenceFallsBackToBundleIcon(): void {
		$settings = new Settings();
		$settings->setAppIconMode('custom');
		$settings->setAppIconBlackUrl('nextcloud-fileid://pkienast/12345');
		$settings->setAppIconWhiteUrl('/apps/theming/img/educai-white.svg');
		$service = $this->createService($settings);

		$this->assertSame('/apps/educai/img/app-dark.svg', $service->getSettingsSectionIcon());
		$this->assertSame('/apps/theming/img/educai-white.svg', $service->getAppNavigationIcon());
	}

	public function testConfiguredUploadedIconReferenceResolvesSvgFile(): void {
		$settings = new Settings();
		$settings->setAppIconMode('custom');
		$settings->setAppIconBlackUrl('educai-upload://black');

		$file = $this->createMock(ISimpleFile::class);
		$file->method('getSize')->willReturn(1024);
		$file->method('getMimeType')->willReturn('image/svg+xml');
		$file->method('getName')->willReturn('black.svg');

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())
			->method('getFile')
			->with('black.svg')
			->willReturn($file);

		$appData = $this->createMock(IAppData::class);
		$appData->expects($this->once())
			->method('getFolder')
			->with('app_icons')
			->willReturn($folder);

		$service = $this->createService($settings, appData: $appData);

		$this->assertSame($file, $service->getConfiguredIconFile('black'));
	}

	public function testStoreUploadedIconCreatesNewSvgFile(): void {
		$settings = new Settings();
		$content = '<svg viewBox="0 0 1 1"></svg>';
		$file = $this->createMock(ISimpleFile::class);

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())
			->method('fileExists')
			->with('black.svg')
			->willReturn(false);
		$folder->expects($this->once())
			->method('newFile')
			->with('black.svg', $content)
			->willReturn($file);

		$appData = $this->createMock(IAppData::class);
		$appData->expects($this->once())
			->method('getFolder')
			->with('app_icons')
			->willReturn($folder);

		$service = $this->createService($settings, appData: $appData);

		$this->assertSame($file, $service->storeUploadedIcon('black', $content));
	}

	public function testStoreUploadedIconOverwritesExistingSvgFile(): void {
		$settings = new Settings();
		$content = '<svg viewBox="0 0 1 1"></svg>';
		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->once())
			->method('putContent')
			->with($content);

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())
			->method('fileExists')
			->with('white.svg')
			->willReturn(true);
		$folder->expects($this->once())
			->method('getFile')
			->with('white.svg')
			->willReturn($file);
		$folder->expects($this->never())
			->method('newFile');

		$appData = $this->createMock(IAppData::class);
		$appData->expects($this->once())
			->method('getFolder')
			->with('app_icons')
			->willReturn($folder);

		$service = $this->createService($settings, appData: $appData);

		$this->assertSame($file, $service->storeUploadedIcon('white', $content));
	}

	public function testStoreUploadedIconRejectsNonSvgContent(): void {
		$service = $this->createService(new Settings());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('App icon upload must be an SVG file up to 1 MB');

		$service->storeUploadedIcon('black', '<html></html>');
	}

	private function createService(Settings $settings, ?IAppData $appData = null): AppIconService {
		$settingsMapper = $this->createMock(SettingsMapper::class);
		$settingsMapper->method('getSettings')->willReturn($settings);

		$appData ??= $this->createMock(IAppData::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
			$urlGenerator->method('linkToRoute')
				->willReturnCallback(static function (string $routeName, array $arguments = []): string {
					if ($routeName === 'educai.app_icon.show') {
						$url = '/apps/educai/api/v1/app-icon/' . ($arguments['variant'] ?? 'black');
						return isset($arguments['v']) ? $url . '?v=' . $arguments['v'] : $url;
					}

					return '/apps/educai/';
				});
		$urlGenerator->method('imagePath')
			->willReturnCallback(static function (string $app, string $file): string {
				return $app === 'core' ? "/core/img/{$file}" : "/apps/{$app}/img/{$file}";
			});
		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $url): string => str_starts_with($url, 'http') ? $url : 'https://nextcloud.test' . $url);

		$logger = $this->createMock(LoggerInterface::class);

		return new AppIconService(
			$settingsMapper,
			$appData,
			$urlGenerator,
			$logger
		);
	}
}
