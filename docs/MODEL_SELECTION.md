# Model discovery and selection

Talk AI 2.43.0 keeps model discovery, administrator approval and inference testing separate.

## Administrator workflow

1. Save the primary and optional secondary provider settings in **Model Endpoints**.
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

The upgrade widens chat model-reference columns to 512 characters. API references are limited to 512 bytes and cannot contain control characters. Existing current and pending bot selections, default, fallback and allowlist entries are migrated from unqualified names only when a provider can be determined confidently. A proven provider association is retained across later catalogue failures and changes.

Ambiguous or unresolved legacy names remain unchanged for an administrator to qualify; requests do not guess a provider. Qualified references route directly without requiring successful discovery. An unavailable provider does not cause a model to be silently sent to the other provider; the existing explicitly configured fallback policy still applies to eligible inference failures.

Migration preserves bot approval state, pending content, inheritance, timestamps and output-limit maps. Existing exact output-limit references retain their behaviour. It does not activate malformed legacy output-limit entries or automatically grant newly discovered models.

If embeddings inherit the chat default model name, the local `primary:`/`secondary:` routing prefix is stripped before the embedding request. Explicit embedding model IDs and embedding endpoint selection remain unchanged; merely qualifying the same chat default does not require reindexing.

## API and regression coverage

- `GET /api/v1/models`: model options and independent provider statuses; cache-aware and available to authenticated users.
- `POST /api/v1/models/refresh`: administrator-only explicit discovery refresh.
- `POST /api/v1/models/test` with `{"model":"secondary:model-id"}`: administrator-only, one-request inference probe of the saved selected provider.

The existing `models` and `model_options` response fields remain available. Catalogue responses additionally include `providers` and `refresh_interval_seconds`; each option indicates whether it is current or stale. Provider failures return safe descriptions, not credentials or raw upstream error bodies.

Unit coverage includes independent outages, recovery, cache invalidation, credential-read failures, stable routing, legacy migration, current/pending bot models, allowlist enforcement, inheritance reset, inference attempt limits, existing output budgets, embedding compatibility and schema upgrades. Frontend regressions cover preserving dirty selections, request races, manual IDs, missing/stale values, save-first guards and server-confirmed migration. Browser validation uses actual Nextcloud/Talk screens with controlled providers for outages, plus separate real OpenRouter inference tests.
