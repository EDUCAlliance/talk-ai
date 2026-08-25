<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

final class ToolCallIdGenerator {
	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	private const ID_LENGTH = 9;

	public function generate(): string {
		$id = '';
		$lastIndex = strlen(self::ALPHABET) - 1;
		for ($i = 0; $i < self::ID_LENGTH; $i++) {
			$id .= self::ALPHABET[random_int(0, $lastIndex)];
		}

		return $id;
	}
}
