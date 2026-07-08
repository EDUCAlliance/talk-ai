# Tool-Provider Extension Point

Other Nextcloud apps can contribute additional agent tools to Talk AI bots
without any changes to this app. Contributed tools behave exactly like the
core built-in tools: they share the name-based per-bot loadout storage, the
per-bot enable/disable UI, execution policies and the agent-loop dispatch.

This is the mechanism used to keep deployment-specific tools out of the
generic core: a companion app can ship its own tools (e.g. a course-catalogue
search) that plug into every bot without forking this app.

## How it works

1. The core app exposes `OCA\EducAI\ToolProvider\ToolProviderRegistry`, which
   all consumers (agent executor, tools API, bot service) use to list and
   execute name-based tools.
2. On first use per request, the registry dispatches
   `OCA\EducAI\ToolProvider\CollectToolProvidersEvent`.
3. Listeners register implementations of
   `OCA\EducAI\ToolProvider\IToolProvider` on the event.

## Registering a provider from another app

In your app's `Application::register()`:

```php
$context->registerEventListener(
    \OCA\EducAI\ToolProvider\CollectToolProvidersEvent::class,
    \OCA\MyApp\Listener\MyToolProviderListener::class
);
```

The listener:

```php
class MyToolProviderListener implements IEventListener {
    public function __construct(private MyToolProvider $provider) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof CollectToolProvidersEvent) {
            return;
        }
        $event->registerProvider($this->provider);
    }
}
```

## Implementing `IToolProvider`

| Method | Purpose |
|---|---|
| `getTools()` | Currently available tool definitions (may be `[]` when unconfigured). Called when assembling a bot's loadout and for the tools UI. |
| `providesTool($name)` | Whether this provider executes the given tool name (include legacy aliases). |
| `executeTool($name, $arguments, $config)` | Run the tool; return `{content: [{type, text}], isError}` (MCP-style result). |
| `getToolMetadata()` | Static UI labels/summaries per tool name — must work even when the provider is disabled, so assigned tools still render properly in the UI. |
| `setInvocationContext($context)` | Receives bot id / room token / attachments before execution; no-op for stateless providers. |

Tool definition shape (same as core built-ins):

```php
[
    'name'        => 'my_tool',                 // unique, snake_case
    'description' => 'LLM-facing description',
    'schema'      => [ /* JSON schema */ ],
    'policy'      => $policyService->searchToolPolicy(), // or readToolPolicy()
    'label'       => 'My Tool',                 // optional UI label
    'summary'     => 'One-line UI summary',     // optional UI description
]
```

Execution policies drive loop budgets and the forced-search heuristics; use
`ToolExecutionPolicyService::searchToolPolicy()` / `readToolPolicy()` for
read-only tools. Mutating provider tools should not be marked read-only —
construct a stricter policy accordingly.

## Guarantees & caveats

- **Name collisions:** core tools can never be shadowed. A provider tool whose
  name collides with an existing tool is skipped with a warning in the log.
- **Failure isolation:** a provider that throws in `getTools()` or
  `setInvocationContext()` is logged and skipped; it cannot break the loadout.
- **Storage:** assignments are stored by tool *name* in the existing
  `bot_tools` table (`is_built_in` path) — no schema changes needed for
  provider tools, and removing a provider app leaves stale assignments
  inert (they simply stop matching any definition).
