<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\SettingsMapper;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

class AppIconService {
	private const VARIANT_BLACK = 'black';
	private const VARIANT_WHITE = 'white';
	private const MAX_ICON_FILE_SIZE_BYTES = 1048576;

	public function __construct(
		private SettingsMapper $settingsMapper,
		private IAppData $appData,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {}

	public function getSettingsSectionIcon(): string {
		return $this->resolveIconUrl('black', 'app-dark.svg', false);
	}

	public function getAppNavigationIcon(): string {
		return $this->resolveIconUrl('white', 'app.svg', false);
	}

	public function getReferenceIconUrl(): string {
		return $this->resolveIconUrl('black', 'app-dark.svg', true);
	}

	/**
	 * @return array{black: string, white: string}
	 */
	public function getRuntimeIconUrls(): array {
		return [
			'black' => $this->getSettingsSectionIcon(),
			'white' => $this->getAppNavigationIcon(),
		];
	}

	private function resolveIconUrl(string $variant, string $fallbackIcon, bool $absolute): string {
		$configuredUrl = $this->getConfiguredIconUrl($variant);
		$url = $configuredUrl ?? $this->urlGenerator->imagePath(Application::APP_ID, $fallbackIcon);

		if ($absolute && !preg_match('/^https?:\/\//i', $url)) {
			return $this->urlGenerator->getAbsoluteURL($url);
		}

		return $url;
	}

	private function getConfiguredIconUrl(string $variant): ?string {
		try {
			$settings = $this->settingsMapper->getSettings();
			$mode = $settings->getAppIconMode() ?: SettingsService::APP_ICON_MODE_DEFAULT;
			if ($mode === SettingsService::APP_ICON_MODE_CUSTOM) {
				return $this->getConfiguredVariantUrl($variant, $settings->getAppIconBlackUrl(), $settings->getAppIconWhiteUrl())
					?? $this->getLegacyIconUrl($settings->getAppIconUrl(), $variant);
			}

			return $this->getLegacyIconUrl($settings->getAppIconUrl(), $variant);
		} catch (Throwable $e) {
			$this->logger->debug('EducAI: Falling back to bundled app icon', [
				'exception' => $e::class,
				'message' => $e->getMessage(),
			]);
			return null;
		}
	}

	private function getConfiguredVariantUrl(string $variant, ?string $blackUrl, ?string $whiteUrl): ?string {
		$url = trim((string)($variant === 'white' ? $whiteUrl : $blackUrl));
		if ($this->isUploadedIconReference($url)) {
			return $this->getIconRouteUrl($variant, $url);
		}

		return $this->normalizeDirectIconUrl($url);
	}

	private function getLegacyIconUrl(?string $legacyIconUrl, string $variant = self::VARIANT_BLACK): ?string {
		$url = trim((string)$legacyIconUrl);
		if ($this->isUploadedIconReference($url)) {
			return $this->getIconRouteUrl($variant, $url);
		}

		return $this->normalizeDirectIconUrl($url);
	}

	public function getConfiguredIconFile(string $variant): ?ISimpleFile {
		$source = $this->getConfiguredIconSource($variant);
		return $this->resolveUploadedIconReference($source);
	}

	public function storeUploadedIcon(string $variant, string $content): ISimpleFile {
		$variant = $this->normalizeVariant($variant);
		if ($variant === null) {
			throw new \InvalidArgumentException('Invalid app icon variant');
		}

		$content = trim($content);
		if ($content === '' || strlen($content) > self::MAX_ICON_FILE_SIZE_BYTES || !$this->looksLikeSvg($content)) {
			throw new \InvalidArgumentException('App icon upload must be an SVG file up to 1 MB');
		}

		$fileName = $variant . '.svg';
		$folder = $this->getUploadedIconFolder();
		if ($folder->fileExists($fileName)) {
			$file = $folder->getFile($fileName);
			$file->putContent($content);
			return $file;
		}

		return $folder->newFile($fileName, $content);
	}

	public function getUploadedIconReference(string $variant): string {
		$variant = $this->normalizeVariant($variant) ?? self::VARIANT_BLACK;
		return SettingsService::APP_ICON_UPLOAD_PREFIX . $variant;
	}

	public function resolveUploadedIconReference(?string $source): ?ISimpleFile {
		$variant = $this->parseUploadedIconReference($source);
		if ($variant === null) {
			return null;
		}

		try {
			$file = $this->getUploadedIconFolder()->getFile($variant . '.svg');
			if (!$this->isSvgIconFile($file)) {
				return null;
			}

			return $file;
		} catch (Throwable $e) {
			$this->logger->debug('EducAI: Failed to resolve uploaded app icon file', [
				'variant' => $variant,
				'exception' => $e::class,
				'message' => $e->getMessage(),
			]);
			return null;
		}
	}

	public function isUploadedIconReference(?string $source): bool {
		return $this->parseUploadedIconReference($source) !== null;
	}

	private function parseUploadedIconReference(?string $source): ?string {
		$value = trim((string)$source);
		if (!str_starts_with($value, SettingsService::APP_ICON_UPLOAD_PREFIX)) {
			return null;
		}

		$parts = parse_url($value);
		if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'educai-upload') {
			return null;
		}

		return $this->normalizeVariant((string)($parts['host'] ?? ''));
	}

	private function normalizeDirectIconUrl(string $url): ?string {
		if ($url === '') {
			return null;
		}

		if (preg_match('/^https?:\/\//i', $url) === 1 || (str_starts_with($url, '/') && !str_starts_with($url, '//'))) {
			return $url;
		}

		return null;
	}

	private function getConfiguredIconSource(string $variant): ?string {
		if (!$this->isValidVariant($variant)) {
			return null;
		}

		try {
			$settings = $this->settingsMapper->getSettings();
			$mode = $settings->getAppIconMode() ?: SettingsService::APP_ICON_MODE_DEFAULT;
			if ($mode === SettingsService::APP_ICON_MODE_CUSTOM) {
				$variantSource = trim((string)($variant === self::VARIANT_WHITE ? $settings->getAppIconWhiteUrl() : $settings->getAppIconBlackUrl()));
				if ($variantSource !== '') {
					return $variantSource;
				}
			}

			$legacySource = trim((string)$settings->getAppIconUrl());
			return $legacySource !== '' ? $legacySource : null;
		} catch (Throwable $e) {
			$this->logger->debug('EducAI: Falling back to bundled app icon source', [
				'exception' => $e::class,
				'message' => $e->getMessage(),
			]);
			return null;
		}
	}

	private function getIconRouteUrl(string $variant, ?string $source = null): string {
		$variant = $this->isValidVariant($variant) ? $variant : self::VARIANT_BLACK;
		$routeArguments = [
			'variant' => $variant,
		];
		$cacheBuster = $this->getUploadedIconCacheBuster($source ?? $this->getUploadedIconReference($variant));
		if ($cacheBuster !== null) {
			$routeArguments['v'] = $cacheBuster;
		}

		return $this->urlGenerator->linkToRoute('educai.app_icon.show', $routeArguments);
	}

	private function getUploadedIconCacheBuster(?string $source): ?string {
		$file = $this->resolveUploadedIconReference($source);
		if ($file === null) {
			return null;
		}

		try {
			return (string)$file->getMTime();
		} catch (Throwable) {
			return null;
		}
	}

	private function normalizeVariant(string $variant): ?string {
		return in_array($variant, [self::VARIANT_BLACK, self::VARIANT_WHITE], true) ? $variant : null;
	}

	private function isValidVariant(string $variant): bool {
		return $this->normalizeVariant($variant) !== null;
	}

	private function isSvgIconFile(ISimpleFile $file): bool {
		if ($file->getSize() > self::MAX_ICON_FILE_SIZE_BYTES) {
			return false;
		}

		$mimeType = strtolower($file->getMimeType());
		$fileName = strtolower($file->getName());
		return $mimeType === 'image/svg+xml' || str_ends_with($fileName, '.svg');
	}

	private function looksLikeSvg(string $content): bool {
		return preg_match('/<svg(?:\s|>)/i', $content) === 1
			&& preg_match('/<\/svg>/i', $content) === 1
			&& preg_match('/<\?(?:php|=)/i', $content) !== 1;
	}

	private function getUploadedIconFolder(): ISimpleFolder {
		try {
			return $this->appData->getFolder('app_icons');
		} catch (Throwable) {
			return $this->appData->newFolder('app_icons');
		}
	}
}
