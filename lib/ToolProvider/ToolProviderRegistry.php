<?php

declare(strict_types=1);

namespace OCA\EducAI\ToolProvider;

use OCA\EducAI\Service\BuiltInToolProvider;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Facade over the core built-in tools and all externally registered
 * {@see IToolProvider} instances.
 *
 * Consumers (AgentExecutor, ToolsController, BotService, ...) talk to this
 * registry instead of BuiltInToolProvider directly, so tools contributed by
 * other apps via {@see CollectToolProvidersEvent} behave exactly like core
 * built-in tools: they share the name-based bot loadout storage, the per-bot
 * enable/disable UI, execution policies and the agent loop dispatch.
 */
class ToolProviderRegistry {
	private BuiltInToolProvider $builtInToolProvider;
	private IEventDispatcher $eventDispatcher;
	private LoggerInterface $logger;

	/** @var array<int,IToolProvider>|null */
	private ?array $providers = null;

	public function __construct(
		BuiltInToolProvider $builtInToolProvider,
		IEventDispatcher $eventDispatcher,
		LoggerInterface $logger,
	) {
		$this->builtInToolProvider = $builtInToolProvider;
		$this->eventDispatcher = $eventDispatcher;
		$this->logger = $logger;
	}

	/**
	 * @return array<int,IToolProvider>
	 */
	private function getProviders(): array {
		if ($this->providers === null) {
			$event = new CollectToolProvidersEvent();
			$this->eventDispatcher->dispatchTyped($event);
			$this->providers = $event->getProviders();
		}
		return $this->providers;
	}

	/**
	 * All currently available tool definitions: core built-ins first, then
	 * provider tools. Provider tools whose name collides with an existing
	 * tool are skipped (with a warning) so core tools can never be shadowed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getAvailableTools(): array {
		$tools = $this->builtInToolProvider->getAvailableTools();
		$seen = [];
		foreach ($tools as $tool) {
			if (isset($tool['name']) && is_string($tool['name'])) {
				$seen[$tool['name']] = true;
			}
		}

		foreach ($this->getProviders() as $provider) {
			try {
				$providerTools = $provider->getTools();
			} catch (\Throwable $e) {
				$this->logger->error('Tool provider failed to list tools', [
					'provider' => get_class($provider),
					'exception' => $e,
				]);
				continue;
			}

			foreach ($providerTools as $tool) {
				$name = $tool['name'] ?? null;
				if (!is_string($name) || $name === '') {
					continue;
				}
				if (isset($seen[$name])) {
					$this->logger->warning('Skipping tool with duplicate name from provider', [
						'tool' => $name,
						'provider' => get_class($provider),
					]);
					continue;
				}
				$seen[$name] = true;
				$tools[] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Whether the given name is handled by the core built-ins or a provider
	 * (i.e. a name-based tool as opposed to an MCP tool).
	 */
	public function isBuiltInTool(string $toolName): bool {
		if ($this->builtInToolProvider->isBuiltInTool($toolName)) {
			return true;
		}
		foreach ($this->getProviders() as $provider) {
			if ($provider->providesTool($toolName)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Execute a name-based tool, routing to the core built-ins or the
	 * responsible provider.
	 *
	 * @param array<string,mixed> $arguments
	 * @param array<string,mixed> $config Per-bot tool configuration
	 * @return array{content:array<int,array{type:string,text:string}>,isError:bool}
	 * @throws \Exception If no provider handles the tool or execution fails
	 */
	public function executeTool(string $toolName, array $arguments, array $config = []): array {
		if ($this->builtInToolProvider->isBuiltInTool($toolName)) {
			return $this->builtInToolProvider->executeTool($toolName, $arguments, $config);
		}

		foreach ($this->getProviders() as $provider) {
			if ($provider->providesTool($toolName)) {
				return $provider->executeTool($toolName, $arguments, $config);
			}
		}

		throw new \Exception("Unknown built-in tool: $toolName");
	}

	/**
	 * UI metadata (label/summary) for a tool name, independent of whether the
	 * tool is currently available. Returns null when no provider knows the name.
	 *
	 * @return array{label?:string,summary?:string}|null
	 */
	public function getToolMetadata(string $toolName): ?array {
		foreach ($this->getProviders() as $provider) {
			$metadata = $provider->getToolMetadata();
			if (isset($metadata[$toolName]) && is_array($metadata[$toolName])) {
				return $metadata[$toolName];
			}
		}
		return null;
	}

	/**
	 * Forward the per-invocation context to the core built-ins and all providers.
	 *
	 * @param array<string,mixed>|null $context Null resets the context.
	 */
	public function setInvocationContext(?array $context): void {
		$this->builtInToolProvider->setInvocationContext($context);
		foreach ($this->getProviders() as $provider) {
			try {
				$provider->setInvocationContext($context);
			} catch (\Throwable $e) {
				$this->logger->error('Tool provider failed to accept invocation context', [
					'provider' => get_class($provider),
					'exception' => $e,
				]);
			}
		}
	}
}
