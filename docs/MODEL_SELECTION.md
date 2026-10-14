# Model discovery and selection

Talk AI 2.43.0 keeps model discovery, administrator approval and inference testing separate.

## Administrator workflow

1. Save the primary and optional secondary provider settings in **Model Endpoints**. An endpoint is either the provider's base URL ending in `/v1` (for example `https://llm-proxy.educ-alliance.org/v1`) or the full `…/chat/completions` address. Chat, image understanding, the model list, embeddings and speech derive their paths from a `/v1` base URL; any other address is used as entered.
2. Review each provider's catalogue status and last successful update. **Refresh model lists** explicitly retries discovery; ordinary refreshes use a five-minute cache and a one-minute retry cooldown after failure.
3. Select the **Default Model** for bots that inherit the administrator default. A global default is required. Models not listed by a provider can be entered manually with an explicit provider and model ID.
4. If **Allow Multiple Models** is enabled, explicitly select the models users may choose. New discoveries never expand this list. Turning it off preserves existing bot selections; new bots inherit the default.
5. Optionally configure a provider-bound fallback under **Fallback & Timeouts**, and exact model output limits under **Response Limits**.
6. Use **Test a model response** when you need to verify real model access. It makes one small, potentially billable request to the selected saved provider. It does not use the fallback or change any settings.

Unsaved provider settings disable explicit catalogue refresh and the inference test, so a success cannot be mistaken for a test of an unsaved endpoint or credential. Manual model selection, refresh and test controls use the same provider-bound identifiers throughout the UI.

## Automatic refresh and failure handling

- Nextcloud's background jobs refresh the catalogues; normal model-list requests also refresh expired entries. The open admin and bot forms poll at the advertised interval and stop polling when destroyed. Keep Nextcloud cron configured for unattended refreshes.
- Each provider has its own cache, timestamp and failure state. One failed provider does not discard healthy models from another. Last successful models remain visible with a stale marker.
- Endpoint or credential changes invalidate the affected provider's cached discovery and capacity data. They do not rewrite saved provider-bound selections.
- A model removed from a catalogue remains selected, with a warning. It may still be callable. Talk AI does not choose a replacement silently.
- A successful model-list request is not proof that inference works, the selected model is accessible, or the account has credit.
- The explicit inference test caps output at 32 tokens and requires visible response text. A reasoning model may produce no visible text within that cap; a failed test is not proof that the model can never answer. Normal chat limits remain separate.

## Bot behaviour and authorization

**Use administrator default** stores no per-bot override. It continues to follow later administrator default changes. Selecting a specific model stores its provider as well as its model name, including after editing, restarting or discovery failures.

The server enforces the saved per-bot allowlist, not just the browser. A model's presence in discovery does not grant users permission to select it. Unrelated edits to an existing bot retain its saved selection, even if the model is temporarily missing or has since been removed from the allowlist. Clearing a bot's explicit model restores actual inheritance.

## Existing installations

The upgrade widens chat model-reference columns to 512 characters. API references are limited to 512 bytes and cannot contain control characters. Existing current and pending bot selections, default, fallback and allowlist entries keep working as before: an unqualified name goes to the primary provider, unless only the secondary provider lists it.

The background job and an explicit **Refresh model lists** pin such names to `primary:` or `secondary:` when that is proven. With only a primary provider every name is pinned to it. With two providers a name is pinned only if exactly one of them lists it and both have a current list that names their models; a placeholder list such as LiteLLM's `all-team-models`, an outdated list or an unreadable key is not proof. A provider without a key cannot contradict. Pinning never changes which provider a name used before, and a proven association is retained across later catalogue failures and changes.

Names that cannot be pinned stay unchanged and keep the previous routing. The overview lists them; selecting the model again with its provider pins it, also when per-bot selection is off. Qualified references route directly without requiring successful discovery. An unavailable provider does not cause a qualified model to be silently sent to the other provider; the existing explicitly configured fallback policy still applies to eligible inference failures.

The migration resolves names first and then writes to freshly read rows, so settings or bot edits saved meanwhile are kept. Reading the model list never writes settings or bots.

Migration preserves bot approval state, pending content, inheritance, timestamps and output-limit maps. Existing exact output-limit references retain their behaviour. It does not activate malformed legacy output-limit entries or automatically grant newly discovered models.

If embeddings inherit the chat default model name, the local `primary:`/`secondary:` routing prefix is stripped before the embedding request. Explicit embedding model IDs and embedding endpoint selection remain unchanged; merely qualifying the same chat default does not require reindexing.

## API and regression coverage

- `GET /api/v1/models`: model options and independent provider statuses; cache-aware and available to authenticated users.
- `POST /api/v1/models/refresh`: administrator-only explicit discovery refresh.
- `POST /api/v1/models/test` with `{"model":"secondary:model-id"}`: administrator-only, one-request inference probe of the saved selected provider.

The existing `models` and `model_options` response fields remain available. Catalogue responses additionally include `providers` and `refresh_interval_seconds`; each option indicates whether it is current or stale. Provider failures return safe descriptions, not credentials or raw upstream error bodies.

Each option also has `first_seen_at`: the Unix time a model first appeared in a successful list for the same provider endpoint and credential, or `null`. The first successful list after an upgrade, a new provider or a credential change is a baseline in which every model is `null`, so only later additions are marked as new. A model keeps its time across outages and restarts.

- `GET /api/v1/admin/status`: administrator-only. Returns the last explicit `model`, `docling`, `vision`, `speech` and `catalogue` checks (`success`, `checked_at`, `duration_ms`, `summary`, `error`, `current`, or `null` if never tested), `unqualified_models` (saved model names without a provider) and the Talk `webhook_url` and `secret_configured`. A check is `current` while the saved settings still resolve to the endpoint, options and key it used; a test of unsaved values becomes current once exactly those values are saved. Only safe fields and an HMAC fingerprint keyed with the instance secret are stored, never keys or provider responses.
- `PUT /api/v1/settings` accepts `clearCredentials`, a list of optional keys to remove: `secondaryApiKey`, `embeddingApiKey`, `visionApiKey`, `speechApiKey`, `doclingApiKey`. A removed key behaves like one that was never set, and a new value in the same request wins. Removing the secondary key counts as a provider change; removing the embedding key flags a reindex. Other names return HTTP 400.

Unit coverage includes independent outages, recovery, cache invalidation, credential-read failures, stable routing, legacy migration, current/pending bot models, allowlist enforcement, inheritance reset, inference attempt limits, existing output budgets, embedding compatibility and schema upgrades. Frontend regressions cover preserving dirty selections, request races, manual IDs, missing/stale values, save-first guards and server-confirmed migration. Browser validation uses actual Nextcloud/Talk screens with controlled providers for outages, plus separate real OpenRouter inference tests.
