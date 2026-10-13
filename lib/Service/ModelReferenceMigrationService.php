<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\SettingsMapper;

/**
 * Pin old, unqualified selections without changing their meaning. Unresolved
 * values are deliberately kept for the administrator to select explicitly.
 * Separate from SettingsService to avoid a SettingsService/LLMClient DI cycle.
 */
class ModelReferenceMigrationService {
	public function __construct(
		private SettingsMapper $settingsMapper,
		private BotMapper $botMapper,
		private LLMClient $llmClient,
	) {
	}

	/** @return array{settings:int,bots:int,unresolved:int} */
	public function migrate(): array {
		$resolved = [];
		$unresolved = [];
		$resolve = function (?string $reference) use (&$resolved, &$unresolved): ?string {
			if ($reference === null || trim($reference) === '' || preg_match('/^(primary|secondary):\S/', $reference) === 1) {
				return $reference;
			}
			if (array_key_exists($reference, $resolved)) {
				return $resolved[$reference];
			}
			try {
				$resolved[$reference] = $this->llmClient->canonicalizeModelReference($reference);
			} catch (\InvalidArgumentException $e) {
				$unresolved[$reference] = true;
				$resolved[$reference] = $reference;
			}
			return $resolved[$reference];
		};

		$settings = $this->settingsMapper->getSettings();
		$settingsChanged = false;
		foreach (['DefaultModel', 'FallbackModel'] as $field) {
			$previous = $settings->{'get' . $field}();
			$next = $resolve($previous);
			if ($next !== $previous) {
				$settings->{'set' . $field}($next);
				$settingsChanged = true;
			}
		}
		$allowed = json_decode((string)$settings->getAllowedModels(), true);
		if (is_array($allowed)) {
			$nextAllowed = array_map(static fn ($reference) => is_string($reference) ? $resolve($reference) : $reference, $allowed);
			if ($nextAllowed !== $allowed) {
				$settings->setAllowedModels(json_encode($nextAllowed, JSON_THROW_ON_ERROR));
				$settingsChanged = true;
			}
		}
		// Output-limit overrides already require exact provider-qualified keys.
		// Rewriting an old raw key would newly activate a previously ignored cap;
		// leave that map untouched until an explicit administrator edit.
		if ($settingsChanged) {
			$this->settingsMapper->update($settings);
		}

		$botsChanged = 0;
		foreach ($this->botMapper->findAll() as $bot) {
			$changed = false;
			$previous = $bot->getModel();
			$next = $resolve($previous);
			if ($next !== $previous) {
				$bot->setModel($next);
				$changed = true;
			}
			$pending = $bot->getPendingChangesArray();
			if (is_array($pending) && is_string($pending['model'] ?? null)) {
				$next = $resolve($pending['model']);
				if ($next !== $pending['model']) {
					$pending['model'] = $next;
					$bot->setPendingChangesArray($pending);
					$changed = true;
				}
			}
			if ($changed) {
				// Do not change approval state, user content or timestamps.
				$this->botMapper->update($bot);
				$botsChanged++;
			}
		}
		return ['settings' => $settingsChanged ? 1 : 0, 'bots' => $botsChanged, 'unresolved' => count($unresolved)];
	}
}
