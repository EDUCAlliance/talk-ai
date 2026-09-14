<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use InvalidArgumentException;
use OCP\IConfig;

/** Installation branding is configuration, never a separate application identity. */
class BrandingService {
	public const DEFAULT_DISPLAY_NAME = 'Talk AI';
	public const DEFAULT_WIKI_ROOT = 'Talk AI';
	public const LEGACY_EDUC_NAME = 'EDUC AI';
	public const DISPLAY_NAME_KEY = 'display_name';
	public const WIKI_ROOT_KEY = 'wiki_root_folder';
	private const APP_ID = 'educai';

	public function __construct(private ?IConfig $config = null) {}

	public function getDisplayName(): string {
		$name = $this->config?->getAppValue(self::APP_ID, self::DISPLAY_NAME_KEY, '') ?? '';
		return $name !== '' ? $name : $this->legacyDefault();
	}

	/** This storage setting is intentionally independent from the display name. */
	public function getWikiRootFolder(): string {
		$root = $this->config?->getAppValue(self::APP_ID, self::WIKI_ROOT_KEY, '') ?? '';
		return $root !== '' ? $root : $this->legacyDefault();
	}

	/** Pin defaults before an upgrade replaces the old installed_version value. */
	public function initializeDefaults(): void {
		if ($this->config === null) {
			return;
		}
		foreach ([self::DISPLAY_NAME_KEY, self::WIKI_ROOT_KEY] as $key) {
			if ($this->config->getAppValue(self::APP_ID, $key, '') === '') {
				$this->config->setAppValue(self::APP_ID, $key, $this->legacyDefault());
			}
		}
	}

	public function setDisplayName(string $name): void {
		if ($this->config === null) {
			throw new \LogicException('Changing branding requires the Nextcloud app configuration service.');
		}
		$name = trim($name);
		// Talk's managed bot name has a 64-byte limit. Keep the UI and Talk in sync.
		if ($name === '' || strlen($name) > 64 || preg_match('//u', $name) !== 1
			|| preg_match('/[\x00-\x1F\x7F<>]/u', $name) === 1) {
			throw new InvalidArgumentException('The display name must be plain text between 1 and 64 bytes without control characters.');
		}
		$this->initializeDefaults();
		$this->config->setAppValue(self::APP_ID, self::DISPLAY_NAME_KEY, $name);
	}

	public function resetDisplayName(): void {
		$this->setDisplayName(self::DEFAULT_DISPLAY_NAME);
	}

	/** @return array{displayName:string,wikiRootFolder:string} */
	public function getPublicState(): array {
		return [
			'displayName' => $this->getDisplayName(),
			'wikiRootFolder' => $this->getWikiRootFolder(),
		];
	}

	/** Stored Talk identity survives a display-name change. */
	public function getManagedTalkBotId(): ?int {
		$id = $this->config?->getAppValue(self::APP_ID, 'talk_bot_id', '') ?? '';
		return ctype_digit($id) && (int)$id > 0 ? (int)$id : null;
	}

	private function legacyDefault(): string {
		return $this->config?->getAppValue(self::APP_ID, 'installed_version', '') === '2.40.0.1'
			? self::LEGACY_EDUC_NAME : self::DEFAULT_DISPLAY_NAME;
	}
}
