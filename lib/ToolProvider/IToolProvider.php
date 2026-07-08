<?php

declare(strict_types=1);

namespace OCA\EducAI\ToolProvider;

/**
 * Extension point for agent tools.
 *
 * Implementations contribute additional name-based ("built-in style") tools to
 * the agent loop without the core app knowing about them. Providers are
 * collected via {@see CollectToolProvidersEvent}, so other Nextcloud apps can
 * register their own tools by listening to that event.
 *
 * Tool definitions returned by {@see IToolProvider::getTools()} use the same
 * shape as the core built-in tools:
 *
 *   [
 *     'name'        => 'my_tool',              // unique tool name (snake_case)
 *     'description' => '...',                  // LLM-facing description
 *     'schema'      => [...],                  // JSON schema for the arguments
 *     'policy'      => [...],                  // execution policy, see ToolExecutionPolicyService
 *     'label'       => 'My Tool',              // optional human-readable UI label
 *     'summary'     => 'One-line UI summary',  // optional UI description
 *   ]
 */
interface IToolProvider {
	/**
	 * Return the tool definitions this provider currently offers.
	 *
	 * May return an empty array when the provider is not configured/enabled.
	 * This is called when assembling the tool loadout for a bot and when
	 * listing available tools in the UI.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getTools(): array;

	/**
	 * Whether this provider can execute the given tool name.
	 *
	 * Should also return true for legacy alias names the provider still
	 * accepts, even if they are no longer advertised via getTools().
	 */
	public function providesTool(string $toolName): bool;

	/**
	 * Execute one of this provider's tools.
	 *
	 * @param array<string,mixed> $arguments Arguments passed by the model
	 * @param array<string,mixed> $config Per-bot tool configuration
	 * @return array{content:array<int,array{type:string,text:string}>,isError:bool}
	 * @throws \Exception If the tool is unknown or execution fails
	 */
	public function executeTool(string $toolName, array $arguments, array $config = []): array;

	/**
	 * Static UI metadata for this provider's tools, keyed by tool name.
	 *
	 * Unlike getTools() this must not depend on runtime configuration, so the
	 * UI can still render labels for tools that are assigned to a bot while
	 * the provider is (temporarily) disabled. May include legacy alias names.
	 *
	 * @return array<string,array{label?:string,summary?:string}>
	 */
	public function getToolMetadata(): array;

	/**
	 * Receive the per-invocation context (bot id, room token, attachments, ...)
	 * before tools are executed. Stateless providers can ignore this.
	 *
	 * @param array<string,mixed>|null $context Null resets the context.
	 */
	public function setInvocationContext(?array $context): void;
}
