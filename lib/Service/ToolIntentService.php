<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\Bot;

/**
 * Keeps user-message intent heuristics out of the message orchestration code.
 *
 * @psalm-import-type McpToolLoadoutEntry from \OCA\EducAI\TypeDefinitions
 */
class ToolIntentService {
	private const FORCE_TOOL_KEYWORDS = [
		'tool call',
		'toolcall',
		'nutze das tool',
		'mach ein tool',
		'mach einen tool',
		'websuche',
		'suche im web',
		'suche im internet',
		'such im internet',
		'recherchiere',
		'search the web',
		'search online',
		'use the web',
		'googele',
		'im internet',
		'im web',
		'internet recherchieren',
	];

	/**
	 * @param array<int,McpToolLoadoutEntry> $toolLoadout
	 */
	public function shouldForceToolCall(Bot $bot, string $cleanMessage, ?string $originalMessage, array $toolLoadout): bool {
		$combinedText = strtolower(trim($originalMessage ?? '')) . ' ' . strtolower(trim($cleanMessage));
		$combinedText = trim($combinedText);

		foreach ($toolLoadout as $entry) {
			$config = $entry['config'] ?? [];
			if (($config['force'] ?? false) === true) {
				return true;
			}
		}

		$keywords = self::FORCE_TOOL_KEYWORDS;
		if ($this->mentionIndicatesSearch(strtolower($bot->getMentionName())) || $this->toolListIndicatesSearch($toolLoadout)) {
			$keywords[] = 'was ist';
			$keywords[] = 'aktuelles';
			$keywords[] = 'heute';
		}

		foreach ($keywords as $needle) {
			if ($needle !== '' && $combinedText !== '' && str_contains($combinedText, $needle)) {
				return true;
			}
		}

		return false;
	}

	public function mentionIndicatesSearch(string $mention): bool {
		if ($mention === '') {
			return false;
		}
		return str_contains($mention, 'web')
			|| str_contains($mention, 'search')
			|| str_contains($mention, 'browser');
	}

	/**
	 * @param array<int,McpToolLoadoutEntry> $toolLoadout
	 */
	public function toolListIndicatesSearch(array $toolLoadout): bool {
		foreach ($toolLoadout as $entry) {
			$tool = $entry['tool'];
			$name = strtolower($tool->getName());
			$description = strtolower($tool->getDescription() ?? '');
			if (
				str_contains($name, 'web')
				|| str_contains($name, 'search')
				|| str_contains($name, 'browser')
				|| str_contains($description, 'websuche')
				|| str_contains($description, 'search')
				|| str_contains($description, 'internet')
			) {
				return true;
			}
		}
		return false;
	}
}
