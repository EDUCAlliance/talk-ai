<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\IDBConnection;

final class RoomImageTables {
	public const SOURCES = 'educai_room_img_src';
	public const LEGACY_SOURCES = 'educai_room_image_sources';

	public const EMBEDDINGS = 'educai_room_img_emb';
	public const LEGACY_EMBEDDINGS = 'educai_room_image_embeddings';

	private function __construct() {
	}

	public static function resolveSourcesTable(IDBConnection $db): string {
		return self::resolve($db, self::SOURCES, self::LEGACY_SOURCES);
	}

	public static function resolveEmbeddingsTable(IDBConnection $db): string {
		return self::resolve($db, self::EMBEDDINGS, self::LEGACY_EMBEDDINGS);
	}

	private static function resolve(IDBConnection $db, string $preferred, string $legacy): string {
		if ($db->tableExists($preferred)) {
			return $preferred;
		}

		if ($db->tableExists($legacy)) {
			return $legacy;
		}

		return $preferred;
	}
}
