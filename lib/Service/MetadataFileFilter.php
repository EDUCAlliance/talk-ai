<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCP\Files\File;

class MetadataFileFilter {
	private const METADATA_FILE_NAMES = ['.ds_store', 'thumbs.db', 'ehthumbs.db', 'desktop.ini', '.localized', "icon\r"];
	private const METADATA_FOLDER_NAMES = ['__macosx', '.trashes', '.spotlight-v100', '.fseventsd', '.temporaryitems', '.apdisk'];

	public static function isMetadataFile(File $file): bool {
		return self::isMetadataPath($file->getName()) || self::isMetadataPath($file->getPath());
	}

	public static function isMetadataFolderName(string $name): bool {
		return in_array(mb_strtolower($name, 'UTF-8'), self::METADATA_FOLDER_NAMES, true);
	}

	public static function isMetadataPath(string $path): bool {
		$parts = explode('/', str_replace('\\', '/', $path));
		$name = array_pop($parts);
		if (str_starts_with($name, '._') || in_array(mb_strtolower($name, 'UTF-8'), self::METADATA_FILE_NAMES, true)
			|| self::isMetadataFolderName($name)) {
			return true;
		}
		foreach ($parts as $part) {
			if (self::isMetadataFolderName($part)) {
				return true;
			}
		}
		return false;
	}
}
