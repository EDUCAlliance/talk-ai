<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\SettingsMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Pins old, unqualified selections without changing their meaning.
 * Separate from SettingsService to avoid a SettingsService/LLMClient DI cycle.
 */
class ModelReferenceMigrationService {
	public function __construct(
		private SettingsMapper $settingsMapper,
		private BotMapper $botMapper,
		private LLMClient $llmClient,
	) {
	}

	/**
	 * Resolving may wait for discovery, so the mapping is applied to rows read
	 * afterwards. Edits saved in the meantime are kept.
	 *
	 * @return array{settings:int,bots:int,unresolved:int}
	 */
	public function migrate(): array {
		$found = $this->findUnqualifiedReferences();
		$mapping = [];
		foreach (array_keys($found['references']) as $reference) {
			try {
				$mapping[$reference] = $this->llmClient->canonicalizeModelReference($reference);
			} catch (\InvalidArgumentException $e) {
			}
		}
		$unresolved = count($found['references']) - count($mapping);
		if ($mapping === []) {
			return ['settings' => 0, 'bots' => 0, 'unresolved' => $unresolved];
		}
		$pin = static fn (mixed $reference): mixed => is_string($reference) && isset($mapping[$reference]) ? $mapping[$reference] : $reference;

		$settingsChanged = 0;
		if ($found['settings']) {
			$settings = $this->settingsMapper->getSettings();
			foreach (['DefaultModel', 'FallbackModel'] as $field) {
				$previous = $settings->{'get' . $field}();
				if ($pin($previous) !== $previous) {
					$settings->{'set' . $field}($pin($previous));
					$settingsChanged = 1;
				}
			}
			$allowed = json_decode((string)$settings->getAllowedModels(), true);
			if (is_array($allowed) && array_map($pin, $allowed) !== $allowed) {
				$settings->setAllowedModels(json_encode(array_map($pin, $allowed), JSON_THROW_ON_ERROR));
				$settingsChanged = 1;
			}
			// Output-limit overrides already require exact provider-qualified keys.
			// Rewriting an old raw key would newly activate a previously ignored cap.
			if ($settingsChanged === 1) {
				$this->settingsMapper->update($settings);
			}
		}

		$botsChanged = 0;
		foreach ($found['bots'] as $botId) {
			try {
				$bot = $this->botMapper->findById($botId);
			} catch (DoesNotExistException $e) {
				continue;
			}
			$changed = false;
			if ($pin($bot->getModel()) !== $bot->getModel()) {
				$bot->setModel($pin($bot->getModel()));
				$changed = true;
			}
			$pending = $bot->getPendingChangesArray();
			if (is_array($pending) && $pin($pending['model'] ?? null) !== ($pending['model'] ?? null)) {
				$pending['model'] = $pin($pending['model']);
				$bot->setPendingChangesArray($pending);
				$changed = true;
			}
			if ($changed) {
				$this->botMapper->update($bot);
				$botsChanged++;
			}
		}
		return ['settings' => $settingsChanged, 'bots' => $botsChanged, 'unresolved' => $unresolved];
	}

	/** @return list<string> Saved model names without a provider, sorted */
	public function unqualifiedReferences(): array {
		$references = array_keys($this->findUnqualifiedReferences()['references']);
		sort($references);
		return $references;
	}

	/** @return array{references:array<string,true>,settings:bool,bots:list<int>} */
	private function findUnqualifiedReferences(): array {
		$references = [];
		$settings = $this->settingsMapper->getSettings();
		$allowed = json_decode((string)$settings->getAllowedModels(), true);
		$inSettings = false;
		foreach ([$settings->getDefaultModel(), $settings->getFallbackModel(), ...(is_array($allowed) ? $allowed : [])] as $reference) {
			if (self::isUnqualified($reference)) {
				$references[$reference] = true;
				$inSettings = true;
			}
		}
		$bots = [];
		foreach ($this->botMapper->findAll() as $bot) {
			$pending = $bot->getPendingChangesArray();
			foreach ([$bot->getModel(), is_array($pending) ? ($pending['model'] ?? null) : null] as $reference) {
				if (self::isUnqualified($reference)) {
					$references[$reference] = true;
					$bots[$bot->getId()] = true;
				}
			}
		}
		return ['references' => $references, 'settings' => $inSettings, 'bots' => array_keys($bots)];
	}

	private static function isUnqualified(mixed $reference): bool {
		return is_string($reference) && trim($reference) !== '' && preg_match('/^(primary|secondary):\S/', $reference) !== 1;
	}
}
