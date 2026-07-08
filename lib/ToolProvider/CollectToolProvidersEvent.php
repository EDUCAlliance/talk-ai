<?php

declare(strict_types=1);

namespace OCA\EducAI\ToolProvider;

use OCP\EventDispatcher\Event;

/**
 * Dispatched (lazily, once per request) when the agent tool loadout is
 * assembled. Listeners register {@see IToolProvider} instances to contribute
 * additional tools.
 *
 * Companion apps register a listener in their Application bootstrap:
 *
 *   $context->registerEventListener(CollectToolProvidersEvent::class, MyToolProviderListener::class);
 *
 * and call {@see CollectToolProvidersEvent::registerProvider()} in the listener.
 */
class CollectToolProvidersEvent extends Event {
	/** @var array<int,IToolProvider> */
	private array $providers = [];

	public function registerProvider(IToolProvider $provider): void {
		$this->providers[] = $provider;
	}

	/**
	 * @return array<int,IToolProvider>
	 */
	public function getProviders(): array {
		return $this->providers;
	}
}
