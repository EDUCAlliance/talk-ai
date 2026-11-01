# OpenAPI connections: operator and compatibility guide

Talk AI (`educai`) can expose individually reviewed, read-only HTTP operations as bot tools. This is a bounded OpenAPI 3.0 JSON
subset, **not arbitrary OpenAPI support**, a general URL fetcher, or an API-to-RAG crawler. MCP and the existing built-in tools
remain separate. No write tools, personal OAuth sessions, file exports or generated/executable API code are provided.

## Supported subset

| Area | Supported behavior and deliberate exclusions |
| --- | --- |
| Documents | UTF-8 JSON objects declaring `3.0.x`; Swagger 2.0, OpenAPI 3.1 and YAML are rejected. A leading UTF-8 BOM is accepted; original bytes, including the BOM, and their SHA-256 are retained. |
| References | Local `#/...` JSON pointers only. External/file references, missing targets and cycles are diagnosed; documents are never fetched through a reference. |
| Operations | Only `GET`, with a separate administrator confirmation that it changes nothing. Other methods and any GET `requestBody` block the operation. |
| Parameters | Primitive path parameters using `simple`; primitive query parameters and arrays of primitives using `form`. Path parameters must be required and match the template. Operation-level parameters replace path-level parameters with the same name and location. |
| Serialization | Path `explode` defaults to `false` (either boolean is equivalent for primitives); query `explode` defaults to `true`. Arrays become repeated query keys when true, comma-separated encoded items when false. Components are percent-encoded separately. |
| Excluded inputs | Header/cookie parameters, including optional ones; path arrays, objects, nested arrays, `deepObject`, `spaceDelimited`, `pipeDelimited`, parameter `content`, `allowReserved: true` and `allowEmptyValue: true`. Authentication headers are configured separately. |
| Schema constraints | `string`, `integer`, `number`, `boolean`, query arrays; `required`, `nullable`, primitive `enum`, numeric bounds with OpenAPI 3.0 boolean exclusivity, `multipleOf`, string lengths, array lengths and `uniqueItems`. Unknown constraints block approval rather than being dropped. |
| Formats | `int32`, `date`, `date-time` and `uuid` are checked. `int64`, `float`, `double` and `password` are accepted annotations, not additional format checks; runtime numeric/type limits still apply. Other formats, including binary/byte, block input schemas. |
| Excluded schemas | Input `allOf`, `anyOf`, `oneOf`, `not`, discriminator, object keywords and `pattern`. Documentation keywords and `x-` extensions do not add runtime constraints; schema `default` is not an automatically sent value. |
| Responses | Documented successful JSON responses (`application/json` or `application/*+json`); nested data is permitted within transport limits. No binary/download/multipart response support. Response schemas guide mapping, not full response-schema validation. |
| Advisories | Response composition/discriminator produces `response_schema_complex`, not an input blocker. Unreadable response documentation can remain reviewable; a real call must still pass JSON and projection validation. Callbacks are ignored; deprecation is advisory. |

### Authentication: choose exactly one alternative

Connection modes are **No authentication**, **Bearer token**, and **API key in a header**. API keys in query/cookie, HTTP Basic,
OAuth, OpenID Connect, mutual TLS and multi-scheme AND requirements are not supported. Reserved protocol/proxy/cookie headers cannot
be selected as API-key headers.

An operation's `security` replaces root security; otherwise root security is inherited. No effective declaration or `security: []`
requires the explicit `none` choice. An empty requirement `{}` inside a security list is the separate `anonymous` alternative. A
list is OR: select a supported `scheme:<name>` or the anonymous alternative explicitly. The selected scheme must match the
connection mode and API-key header. No fallback occurs after a missing credential or an upstream authentication rejection; anonymous
calls carry no connection credential.

### Servers, base address and identity

Root, path and operation server declarations are displayed. Server variables use string defaults; relative servers can be resolved
against the imported document URL, not against a locally uploaded file. Missing variable defaults are reported. **Importing never
selects a server automatically.** Set the actual connection base explicitly; requests concatenate that base and the operation's
exact path.

Root servers are suggestions: a manually chosen different base produces a UI warning but can be approved. A path/operation override
must resolve exactly to the connection's chosen base or that operation cannot be approved. The model cannot choose another server,
origin, method or base path. Base addresses exclude user information, queries, fragments, dot segments and unsafe characters;
normalization lowercases scheme/host and removes default ports and trailing slashes.

Operation identity is the upper-case method plus **exact** path template, not `operationId`. Duplicate operation IDs produce
conflict warnings on the affected operations but do not block otherwise supported operations. Labels, descriptions and operation-ID
hints do not rename stored `api_<connection>_<operation>` tools; renaming a path or a template variable creates a different
identity. No manual identity remap is available. Mutation-like path/name words produce the advisory `possibly_mutating`; absence of
that hint is not proof that GET is read-only.

## Administrator workflow

1. Open **Administration settings → Talk AI → Capabilities → API connections**. Choose **Add API connection**, enter a recognizable
   name and save. A new connection is disabled; assignment defaults to administrators only, allowed bot visibilities to
   personal/groups/teams/global, authentication to none and data sharing to unconfirmed. Creating it assigns nothing to bots.
2. Open the connection and **Upload JSON document**, or enter a public HTTPS document address (no query/fragment) and select
   **Import**. URL import sends no connection credentials; obtain private authenticated specifications separately and upload the
   file. Import creates a draft only; no operation is tested or released.
3. Inspect **Servers in the document** and **Address actually used**. Use the appropriate server suggestion or **Edit connection
   settings** to set the real base and authentication. Enter only the token/key, not a bearer prefix. Stored credentials are
   represented by a stored indicator, never read back. Replacement and removal take effect on Save; Cancel leaves the stored value
   unchanged.
4. For each intended operation, inspect blockers and notes, enable **Offer to bots**, and confirm **I checked that this operation
   only reads data and changes nothing**. GET and a spec's `readOnly` annotation are not that confirmation. Review the tool label
   and model description; both start from untrusted spec text and become the reviewed model-facing description. Select
   authentication.
5. Expand **Parameters and results**. Check the records pointer, per-record ID, title, extra fields, source link and paging. Set
   non-secret **Fixed query values** if needed (`name = value`, one per line). These validated values are hidden from the model's
   argument schema and cannot be supplied/overridden by the model. Per-bot configuration overrides are ignored for API tools.
6. **Save review**, enter test values and select **Run test call**. This sends one real request using the saved review; it can test
   a draft before the connection is enabled. The read confirmation, base, auth, credential and mapping must be settled first.
   Inspect **Show what the model would receive** and last-check status. Tests are manual and do not approve the operation; approval
   does not require a recorded successful test.
7. In connection settings, confirm **Who sees the data**. The declaration `shared_for_bot_audience` covers everyone who can read an
   answer: members, guests, federated participants and later history readers. The app requires this declaration, not evidence of
   upstream per-user authorization. Do not use this feature for data whose access depends on the asking user's identity.
8. Choose who may assign the tools: administrators, selected Nextcloud groups, or all users. Administrators can always assign.
   Separately choose permitted bot visibilities. Assignment permission does not make already published data confidential; runtime
   checks the bot's visibility, not the reader's API rights.
9. Review the diff and **Approve revision** (or **Save and approve**). At least one operation must be offered and all offered
   operations must pass review. Approval activates the revision transactionally; it does **not** enable a disabled connection.
   Select **Enable connection** separately.
10. In the bot editor's tool selection, choose the reviewed capability, displayed as **connection name: tool label**, then
    save/submit through the normal bot approval workflow. Only available tools assignable by that editor are offered. Server checks
    also apply on save, submission and publication. An explicitly empty tool selection stays empty; enabling a connection adds
    nothing to bots.
11. Use the bot in a Talk room where Talk AI is active. Tool metadata carries the reviewed label; the model receives only the
    effective assigned definitions and projected data. Source links come from the reviewed mapping when valid, not from the API base
    address. The normal Talk tool display uses labels, not raw OpenAPI descriptions. See [BOT_SETUP_GUIDE.md](BOT_SETUP_GUIDE.md).

### Mapping the response

| Setting | Interpretation |
| --- | --- |
| Records at | JSON pointer from response root, such as `/items`; empty means root. A record object or a list of record objects is supported, not a primitive result. |
| ID / title | Optional pointers relative to each record. Values must be scalar when present; IDs are preserved, not clipped or normalized. |
| Extra fields | Selected scalar values or lists of scalars; nested objects/lists are not passed through. Missing/null fields are omitted. Keys are unique, lowercase-leading, and cannot be `id`, `title` or `url`. |
| Source link | A per-record field on accepted HTTPS origins, otherwise a fixed HTTPS template containing `{id}` in its path or query value. No expressions or host placeholders. The template origin is implicitly accepted. Invalid links are omitted, not guessed. |
| Paging | Response-root pointers for boolean `has_more`, string/integer `next_cursor`, non-negative integer `next_offset` and `total`. No next URL is followed automatically. |

Results contain `notice`, `source`, `records`, `page.returned`, `truncated`, `completeness` and `fetched_at`, with `omitted_records`
when necessary. URLs appear as `url`. The projector clips descriptive text, then omits whole records to fit; IDs, URLs and paging
are not cut. Oversized identity/link/metadata can instead fail. Completeness is `single`, `partial`, `complete` or `unknown`, never
a promise that semantic search is an export. For lists, `has_more`, a cursor, total and omissions inform completeness; `next_offset`
alone does not establish completeness.

## Updating, withdrawing and re-releasing

- Import the replacement document into the same connection. Earlier drafts are discarded; the approved revision stays in use until
  deliberate approval of the new draft. Identical bytes are rejected as `document_unchanged` while base/auth approval is current.
  There is no background spec refresh during chat.
- Unchanged, non-revoked operation definitions inherit their reviewed settings. New/changed definitions start with no read
  confirmation and are not offered. Review additions, removals and changes before approving.
- **Changes compared to the approved revision** shows approved/draft values, not only names: parameters/schema, security, servers,
  response, blockers, descriptive changes and reviewed mapping, auth, fixed values, offered/read-confirmed policy, label and
  description. Method/path changes appear as removal plus addition. Connection settings show the actual base/auth separately; the
  diff is not a secret viewer.
- After activation, removed identities become tombstones. Old bot assignments stay recognizable but unavailable. Reintroducing the
  same method/path later gets a new tool ID and needs explicit assignment; old grants cannot revive. A draft referring to an
  identity removed meanwhile fails `revision_outdated`.
- **Disable** stops subsequent calls for the connection. **Revoke** on an approved operation clears its read confirmation
  immediately, including copies in already imported drafts. Subsequent attempts fail before HTTP, even from saved assignments. This
  does not cancel an HTTP request already in flight or erase answers already published in Talk.
- To re-release a revoked operation, use an existing draft or import a new revision, explicitly confirm read semantics afresh,
  review/offer it and approve. If the same document would be unchanged, edit/import an appropriately revised document. Enabling
  alone does not undo revocation. Disabling alone does not erase approval: re-enabling can resume still-approved operations.
- Changing base URL or auth mode/header pauses approved tools. Use **Review the approved document again**, or import for the new
  settings, then approve. Token replacement/removal does not change the approval snapshot; missing/unreadable credentials still
  block calls. Choosing connection auth `none` clears its key.
- Newly added API tools in a published bot's pending loadout wait for bot approval, **also for owner/tester Talk conversations**.
  Existing approved API tools may remain usable; do not treat the owner/tester path as an isolated API preview. Pending removals can
  reduce that owner's/tester's effective selection.
- Superseded revisions remain for audit. Deleting a connection removes its revisions/identities; switch catalogue source first if it
  is selected there.

## Network and data boundary

Production targets require public HTTPS. The app rejects private/reserved IP literals, local hostnames and numeric host shorthands;
Nextcloud's HTTP client provides connection-time DNS/local-address protection, proxy handling and TLS validation. Keep Nextcloud's
system `allow_local_remote_servers` at its default `false`: setting it to `true` weakens platform local-address protection,
including for public names resolving to local addresses. The app's literal/name checks are not a replacement for that platform
protection.

The app option `openapi_allow_insecure_test_targets` defaults to `no`. Only `yes` permits HTTP/local targets and requests Nextcloud
`allow_local_address`. It is for disposable test instances, not a production intranet enablement policy. For a deliberately isolated
test instance, `occ config:app:set educai openapi_allow_insecure_test_targets --value=yes` enables it; restore `--value=no` after
testing. This does not turn off TLS certificate verification for HTTPS.

Requests use `IdentityHttpClient` through Nextcloud, refuse redirects and compressed responses, and stay below the approved base.
Oversized advertised bodies are refused at headers; a cURL progress callback also aborts oversized transfers without a trustworthy
Content-Length, with a bounded read as a final check. No implicit retry or full pagination occurs for a direct API tool call. Call
and deadline limits below are additional to the agent's overall limits.

Credentials are encrypted at rest and only placed in the selected upstream auth header. Stored values are never returned to the
browser or included in model tool definitions/results by credential handling; the browser only sends a replacement value when the
admin saves it. Logs carry identifiers, status, duration and byte counts, not auth headers or raw error bodies. Projected upstream
content is not a secret-redaction filter: review which upstream fields may be shared. Results are explicitly marked **data, never
instructions**; they cannot change server grants, choose credentials or introduce model-controlled HTTP targets.

## Limits and defaults: where this is defined

These are implementation bounds, not promises of arbitrary OpenAPI support. Class names below link to their source; constants are
named explicitly. Where code uses a literal guard/local default instead, its exact definition is named. Character limits count
characters unless marked bytes; narrower spec constraints still apply. Changing a Nextcloud/PHP upload limit does not raise these
bounds.

| Boundary | Current value | Definition |
| --- | --- | --- |
| Import bytes / parser depth / nodes | 2,097,152 bytes (2 MiB) / 64 / 200,000 | [OpenApiDocumentLoader](../lib/OpenApi/OpenApiDocumentLoader.php): `MAX_BYTES`, `MAX_DEPTH`, `MAX_NODES` |
| Reference resolutions / operations | 20,000 / 500 | [RefResolver](../lib/OpenApi/RefResolver.php): `MAX_RESOLUTIONS`; [OpenApiCompiler](../lib/OpenApi/OpenApiCompiler.php): `MAX_OPERATIONS` |
| Path length / parameters | 1,024 bytes / 64 merged parameters | `OpenApiCompiler::MAX_PATH_LENGTH`, `MAX_PARAMETERS` |
| Parameter name / auth header name | 64 / 128 ASCII characters | `OpenApiCompiler::PARAMETER_NAME`, `HEADER_NAME` patterns (plus forbidden-header list) |
| Enum values / argument string / array | 200 / 2,048 characters / 100 items | [ParameterSchemaCompiler](../lib/OpenApi/ParameterSchemaCompiler.php): `MAX_ENUM_VALUES`; [ArgumentValidator](../lib/OpenApi/ArgumentValidator.php): `MAX_STRING_LENGTH`, `MAX_ARRAY_ITEMS` |
| Fixed query values / full request URL | 16 / 8,192 bytes | [ApiOperationReview](../lib/OpenApi/ApiOperationReview.php): `normalizeFixed()` guard; [ApiRequestBuilder](../lib/OpenApi/ApiRequestBuilder.php): `build()` length guard (no named constants) |
| Connection name / description / credential | 128 / 1,000 characters / 4,096 bytes | [ApiConnectionService](../lib/OpenApi/ApiConnectionService.php): `MAX_NAME`, `MAX_DESCRIPTION`, `MAX_CREDENTIAL` |
| Assignment groups / operation label / model description | 50 groups / 80 / 600 characters | `ApiConnectionService::MAX_ASSIGN_GROUPS`; `ApiOperationReview::MAX_LABEL`, `MAX_DESCRIPTION` (server limits) |
| Base URL / JSON pointer | 2,048 / 512 bytes | [ApiUrl](../lib/OpenApi/ApiUrl.php): `MAX_LENGTH`; [JsonPointer](../lib/Service/JsonPointer.php): `MAX_LENGTH` |
| Direct response bytes / JSON depth | 1,048,576 bytes (1 MiB) / 128 | [ApiHttpTransport](../lib/OpenApi/ApiHttpTransport.php): `MAX_RESPONSE_BYTES`, `MAX_RESPONSE_DEPTH` |
| Default call / transport maximum / connect / document timeout | 15 / 30 / 5 / 20 seconds | [OpenApiExecutionService](../lib/OpenApi/OpenApiExecutionService.php): `DEFAULT_TIMEOUT_SECONDS`; `ApiHttpTransport::MAX_TIMEOUT_SECONDS`, `CONNECT_TIMEOUT_SECONDS`, `DOCUMENT_TIMEOUT_SECONDS` |
| Direct-tool API calls per run / deadline reserve / minimum remaining call time | 8 / 5 / 1 seconds (latter two) | [ApiInvocation](../lib/OpenApi/ApiInvocation.php): `MAX_CALLS_PER_RUN`, `DEADLINE_RESERVE_SECONDS`, `MIN_CALL_SECONDS` |
| Overall run wall clock | Default 300 seconds, hard maximum 900 | [AgentExecutor](../lib/Service/AgentExecutor.php): `DEFAULT_MAX_WALL_CLOCK_SECONDS`, `HARD_MAX_WALL_CLOCK_SECONDS`; API calls use the actual run deadline |
| Projected output / records / extra fields / list items | 4,000 characters / 50 / 12 / 20 | [ApiResultProjector](../lib/OpenApi/ApiResultProjector.php): `OUTPUT_LIMIT` = `AgentExecutor::TOOL_OUTPUT_LIMIT`, `MAX_RECORDS`, `MAX_FIELDS`, `MAX_LIST_ITEMS` |
| Descriptive text fitting / cursor | 500 → 240 → 100 → 40 characters / 1,024 bytes | `ApiResultProjector::TEXT_LIMITS`, `MAX_CURSOR_LENGTH` |
| Suggested mapping depth / fields | 8 / 8 (suggestions only) | [ResponseMappingSuggester](../lib/OpenApi/ResponseMappingSuggester.php): `MAX_DEPTH`, `MAX_FIELDS` |
| Canonical URL / accepted origins / template ID | 2,048 bytes / 20 origins / 128 ASCII characters | [CanonicalLinkResolver](../lib/Service/CanonicalLinkResolver.php): `MAX_URL_LENGTH`, `MAX_ORIGINS`, `MAX_ID_LENGTH` |
| Catalogue adapter response bytes | 8,388,608 bytes (8 MiB), not the direct-tool cap | [CatalogueApiSource](../lib/OpenApi/CatalogueApiSource.php): `MAX_RESPONSE_BYTES` |
| Catalogue snapshot page / iterations | 500 records / 10 pages per current or archived snapshot | [CatalogueEmbeddingService](../lib/Service/CatalogueEmbeddingService.php): `fetchAllCourses()` local defaults `$limit`, `$maxIterations` |
| Initial connection/review | Disabled, auth `none`, assignment `admins`, all `BOT_VISIBILITIES`; sharing unconfirmed; generic operations disabled/unconfirmed | [ApiConnection](../lib/Db/ApiConnection.php) property defaults / `BOT_VISIBILITIES`; `ApiOperationReview::defaults()` |
| Network test option / platform local access | `no` / `false` | [ApiNetworkPolicy](../lib/OpenApi/ApiNetworkPolicy.php): `CONFIG_ALLOW_TEST_TARGETS`, `allowsTestTargets()`, `localRemoteServersAllowed()` defaults |

The additive [OpenAPI migration](../lib/Migration/Version024500Date20261030010000.php) creates separate connection/revision/identity
tables. Installing the feature does not create approved connections, assign tools or rebuild the catalogue index.

## EDUC course catalogue preset and rollback

**Add from preset → EDUC course catalogue → Create connection** creates a disabled connection and draft without HTTP. The shipped
preset contains the normalized `GET /course/list`, `GET /course/view` and `GET /version`, public/no-auth defaults, review labels,
mappings and course-link template. Suggested operations are preselected, but read confirmation, data visibility, approval and
enabling are still manual. Source files, the unmodified upstream snapshot and documented corrections are in [the preset
directory](../lib/OpenApi/Presets/educ-catalogue/DEVIATIONS.md). It is curated data, not an automatically refreshed copy of the
upstream spec.

The actual API base is `https://catalogue.educalliance.eu/api`; the preset fixes the upstream doubled API path and request
definitions. Course-list `state` is required (`OPEN` or `CLOSED`); filters use comma-separated ID strings. Filter semantics are not
guaranteed by the importer; see the preset's deviations.

To retain existing semantic search, enable the catalogue integration and its embedding setup, then open **Knowledge → Course
catalogue → Record source**. Choose the reviewed API connection and **Use this source**. Its course-list operation must be approved
and callable, and the connection must allow **every** bot visibility: personal, groups, teams and global. Assignment policy is not
an index access partition. The version operation is optional. The existing client uses the list for detail lookup too; the preset's
view operation is a separate optional direct tool, not required to select the catalogue source.

The adapter changes the transport/record source behind existing catalogue tools; it does not assign the new `api_…` tools.
`catalogue_search`, `catalogue_past_search`, `catalogue_get_opportunity` and alias `catalogue_search_courses` retain their names and
existing index/ranking path. Direct preset tools instead make bounded live API calls and are assigned normally. Their review
mappings do not configure how the existing catalogue index is built.

If the selected list operation is disabled, revoked, absent, restricted to fewer bot audiences, or its base/auth approval no longer
matches, catalogue searches stop and refresh jobs skip/stop. Admin status becomes `source_unavailable` with
`source_unavailable_reason`; saved catalogue tools return an unavailable error, not successful empty matches. The previous index
remains stored but is not used to bypass a withdrawn source. There is no automatic fallback to the old endpoint. An HTTP failure
during refresh also fails the refresh rather than supplying an empty snapshot; it does not by itself revoke an otherwise available
connection.

Refresh fetches complete current **and** archived snapshots before updating. Missing paging counts, changing totals, overlapping
IDs, empty pages with more remaining, final count mismatches and exhausted pagination all fail before stale record deletion. Failed
embedding batches/provider pauses also retain previous records; retries reuse vectors when model and searchable text are unchanged.
Metadata can refresh without unnecessary embeddings. Successful complete refreshes may remove genuinely stale records; failure
preservation is not a deletion ban.

**Rollback:** choose **Catalogue API endpoint** and **Use this source**. Switching is immediate and separate from the surrounding
settings save. It neither reindexes nor deletes settings, bot loadouts, jobs, records or vectors; the next refresh uses the selected
source. Keep the legacy endpoint configured for this rollback.

Canonical course pages are separate from API/application addresses. Direct preset mappings use
`https://catalogue.educalliance.eu/Opportunity/{id}`. Existing catalogue tools use **Course links** settings; creating/selecting the
API preset does not silently set those. Explicitly choose the EDUC link preset there if appropriate. Record links on approved
origins take precedence, then the configured template; otherwise the course link is unavailable. `application_link` is only an
external application form. The catalogue answer guard checks course references before Talk publication; generic OpenAPI links do not
receive that catalogue-specific guard. See [CATALOGUE_LINKS.md](CATALOGUE_LINKS.md) for preview and resolution details.

## Troubleshooting codes

Admin failures expose `errorCode` and optional `details`; test responses use `success`, `output`, `error`. Tool failures use
`isError`, `error.code` and `error.details`, including `reason` and `request_sent`. Compiler diagnostics have `code`, `level` and
`where`; operations have `blockers`, advisory `notes`, and review `problems`. UI text localizes known codes; preserve the code when
reporting an issue. A false `request_sent` is not proof that a generic transport failure never reached the upstream;
availability/argument/budget rejections happen locally.

### Import and compatibility

| Code | Meaning / action |
| --- | --- |
| `document_missing` | Upload a file or enter the document address. |
| `document_fetch_failed` | Inspect `details.reason` for the bounded URL-download failure. |
| `document_unchanged` | Active bytes/settings are unchanged; no replacement draft was created. |
| `document_not_utf8` | Convert the document to UTF-8. |
| `document_not_json` | Supply a JSON object, not another root value. |
| `document_invalid_json` | Fix JSON syntax. |
| `yaml_not_supported` | Convert YAML to JSON. |
| `unsupported_version` | Use the supported OpenAPI version family. |
| `document_invalid` | Fix required `info.title`, `info.version` or `paths`. |
| `document_too_large` | Reduce import bytes. |
| `document_too_deep` | Reduce JSON nesting. |
| `document_too_complex` | Reduce nodes/reference expansion. |
| `document_too_many_operations` | Import a smaller API subset. |
| `source_invalid` | Use a supported file, URL or preset source. |
| `ref_external` | Bundle referenced definitions into the document. |
| `ref_not_found` | Fix a missing local reference target. |
| `ref_invalid` | Use a valid local JSON pointer. |
| `ref_cycle` | Remove a circular reference chain. |
| `operation_invalid` | Fix the operation object. |
| `operation_id_duplicate` | Review the conflict; method/path identity remains distinct. |
| `method_not_supported` | Only reviewed read-only GET can be offered. |
| `possibly_mutating` | Verify side effects; this is an advisory, not automatic release. |
| `request_body_unsupported` | Remove/exclude the operation requiring a body. |
| `callbacks_ignored` | No callback execution is provided. |
| `operation_deprecated` | Review the upstream deprecation advisory. |
| `path_item_invalid` | Fix the path item object. |
| `path_invalid` | Fix path syntax/length or unsafe segments. |
| `path_template_invalid` | Fix malformed/duplicate template placeholders. |
| `path_parameter_missing` | Define each template variable. |
| `path_parameter_not_required` | Mark path parameters required. |
| `path_parameter_unused` | Remove or correct an unused path parameter. |
| `parameters_invalid` | Supply valid parameter lists. |
| `parameters_too_many` | Reduce merged parameters. |
| `parameter_invalid` | Fix name/location/definition or boolean explode. |
| `parameter_duplicate` | Remove duplicate name/location within the same level. |
| `parameter_deprecated` | Review the parameter deprecation advisory. |
| `parameter_location_unsupported` | Header and cookie parameters block approval, even optional ones. |
| `parameter_name_unsupported` | Use supported ASCII parameter names. |
| `parameter_style_unsupported` | Use path simple or query form. |
| `parameter_content_unsupported` | Parameter content schemas are not supported. |
| `parameter_schema_missing` | Supply the parameter schema. |
| `parameter_allow_empty_unsupported` | Exclude allowEmptyValue behavior. |
| `parameter_allow_reserved_unsupported` | Exclude allowReserved behavior. |
| `parameter_array_in_path_unsupported` | Path values must be primitive. |
| `schema_invalid` | Fix keyword types, enum or incompatible constraints. |
| `schema_cycle` | Remove a recursive parameter schema. |
| `schema_type_missing` | Declare the parameter type. |
| `schema_type_unsupported` | Use a supported primitive/query-array type. |
| `schema_object_unsupported` | Object inputs are not supported. |
| `schema_nested_array_unsupported` | Use arrays of primitives, not arrays of arrays. |
| `schema_items_missing` | Declare array items. |
| `schema_composition_unsupported` | Input composition/discriminator cannot be approved. |
| `schema_keyword_unsupported` | Exclude the unsupported input constraint. |
| `schema_format_unsupported` | Use only implemented formats. |
| `schema_pattern_unsupported` | Pattern validation is not implemented. |
| `no_success_response` | Document a successful response. |
| `response_not_json` | Document/return a supported JSON media type. |
| `response_schema_complex` | Advisory: manually test projection; no full schema validation. |
| `response_unreadable` | Advisory: inspect response references and test projection. |
| `response_schema_unreadable` | Advisory: inspect the schema and test projection. |
| `servers_invalid` | Fix the server list. |
| `server_invalid` | Fix a server entry. |
| `server_variable_missing` | Provide a string default for a server variable. |
| `server_override` | Review the path/operation-specific server. |
| `security_invalid` | Fix the security requirement structure. |
| `security_schemes_invalid` | Fix the security schemes container. |
| `security_unsupported` | No supported security alternative remains. |
| `security_and_unsupported` | Combining schemes in one requirement is unsupported. |
| `security_scheme_unknown` | Define the referenced security scheme. |
| `security_scheme_invalid` | Fix the scheme definition/reference. |
| `security_scheme_unsupported` | The scheme type is unsupported. |
| `security_http_scheme_unsupported` | HTTP schemes other than bearer, including Basic, are unsupported. |
| `security_api_key_location_unsupported` | Move the key to a supported header scheme. |
| `security_header_name_unsupported` | Choose a sendable non-protocol header. |
| `security_oauth2_unsupported` | Personal OAuth flows are unsupported. |
| `security_openidconnect_unsupported` | OpenID Connect is unsupported. |
| `security_mutualtls_unsupported` | Mutual TLS security schemes are unsupported. |

### Connection, review and availability

`tool_not_available` wraps a reason below; resolve the reason before reassigning. Disable/revoke errors require no outgoing HTTP.
Assignment-specific codes also apply to bot save/submit/publish, not just the picker.

| Code / reason | Meaning / action |
| --- | --- |
| `tool_not_available` | Tool access/review failed; inspect its reason below. |
| `name_missing` | Enter a connection name. |
| `base_url_invalid` | Set a valid absolute base with no query/fragment. |
| `base_url_https_required` | Use HTTPS outside isolated tests. |
| `base_url_private_host` | The app blocks this private/local target. |
| `base_url_missing` | Set the actual base address. |
| `base_url_changed` | Approve a revision against the changed base. |
| `auth_changed` | Approve a revision against the changed auth mode/header. |
| `auth_mode_invalid` | Choose a supported connection mode. |
| `auth_header_invalid` | Configure a sendable API-key header. |
| `auth_choice_invalid` | Supply a valid operation auth choice. |
| `auth_not_chosen` | Explicitly select the operation's auth alternative. |
| `auth_mode_mismatch` | Match chosen scheme and connection mode. |
| `auth_header_mismatch` | Match the scheme's API-key header. |
| `credential_invalid` | Replace empty/oversized/control-containing credentials. |
| `credential_missing` | Store the required token/key. |
| `credential_unreadable` | Replace the credential whose decryption failed. |
| `data_visibility_missing` | Confirm sharing with all possible answer readers. |
| `data_visibility_invalid` | Use the supported declaration or clear it. |
| `assign_policy_invalid` | Choose administrators, groups or all users. |
| `assign_groups_missing` | Select a group for group-based assignment. |
| `assign_groups_invalid` | Fix the group list. |
| `bot_visibilities_invalid` | Select supported bot visibilities. |
| `assign_not_permitted` | The editor may not assign this connection's tools. |
| `bot_visibility_not_allowed` | The bot audience is outside the connection's release. |
| `connection_not_found` | Reload; the connection was removed. |
| `connection_disabled` | Enable the still-approved connection deliberately. |
| `no_approved_revision` | Approve a document revision. |
| `revision_not_found` | Reload; the revision no longer exists. |
| `revision_not_draft` | Only drafts may be approved/discarded. |
| `revision_not_editable` | Only drafts may be edited. |
| `revision_not_approved` | Revoke only from the approved revision. |
| `revision_not_testable` | Test only draft/approved revisions. |
| `revision_outdated` | Re-import after referenced identities were removed. |
| `no_operation_enabled` | Offer an operation before approval. |
| `operations_not_approvable` | Resolve the returned per-operation problems. |
| `operation_unknown` | The submitted operation is not in this revision/release. |
| `operation_not_found` | The stored operation identity no longer exists. |
| `operation_removed` | Replace/remove the tombstoned bot assignment. |
| `operation_not_supported` | Resolve the compiler blockers. |
| `operation_not_enabled` | Offer/review the operation in a draft. |
| `operation_not_testable` | Settle the returned review problems first. |
| `operation_revoked` | Re-release only with a fresh read confirmation and approval. |
| `read_not_confirmed` | Confirm actual read-only semantics. |
| `label_missing` | Enter a nonempty reviewed tool label. |
| `label_invalid` | Supply a string label. |
| `description_invalid` | Supply a string model description. |
| `server_override_not_approved` | Match the effective override to the approved base. |
| `fixed_invalid` | Supply only the bounded query fixed-values object. |
| `fixed_parameter_unknown` | Use a declared query parameter. |
| `fixed_value_invalid` | Match fixed values to the parameter schema. |
| `mapping_invalid` | Supply a valid mapping object. |
| `mapping_records_invalid` | Fix the records pointer. |
| `mapping_id_invalid` | Fix the per-record ID pointer. |
| `mapping_title_invalid` | Fix the per-record title pointer. |
| `mapping_fields_invalid` | Fix the bounded extra-field list. |
| `mapping_field_key_invalid` | Use unique, nonreserved supported field keys. |
| `mapping_field_pointer_invalid` | Give each field a valid pointer. |
| `mapping_link_invalid` | Fix the HTTPS link field/template/origins. |
| `mapping_link_origin_missing` | Accept an origin before using record links. |
| `mapping_paging_invalid` | Fix response-root paging pointers. |
| `tool_name_invalid` | Use the persisted generated tool identity. |
| `invocation_context_missing` | Run inside a server-verified bot conversation. |
| `api_connection_failed` | Unexpected admin failure; inspect the sanitized server log. |

### Calls and catalogue source

| Code / reason / state | Meaning / action |
| --- | --- |
| `invalid_arguments` | Fix required/unknown/fixed parameters, types, formats or constraints. |
| `invalid_fixed_values` | Internal fixed-value validation failed. |
| `connection_base_invalid` | Repair the connection base. |
| `request_build_failed` | Request construction failed locally. |
| `request_too_large` | Reduce encoded path/query length. |
| `api_call_budget_exhausted` | Answer with existing results; no further API call this run. |
| `deadline_exceeded` | Insufficient run time remains for another call. |
| `target_not_allowed` | App or Nextcloud network policy denied the target. |
| `target_outside_base` | The constructed target would leave the approved base. |
| `redirect_blocked` | Configure the final endpoint; redirects are not followed. |
| `auth_rejected` | Upstream rejected authentication; no alternative is attempted. |
| `not_found` | Upstream found no resource. |
| `rate_limited` | Respect upstream/provider pause; direct tools do not retry automatically. |
| `upstream_error` | Upstream server failure, not an empty successful result. |
| `request_rejected` | Upstream rejected the request. |
| `timeout` | The request exceeded its time budget. |
| `transport_error` | Check connectivity/TLS/proxy; raw upstream errors are not echoed. |
| `response_compressed` | Upstream must honor identity content encoding. |
| `response_too_large` | Reduce response/page size within the relevant byte cap. |
| `response_too_deep` | Reduce response JSON nesting. |
| `response_invalid_json` | Upstream did not return valid JSON. |
| `response_unexpected` | Catalogue adapter requires a JSON object. |
| `result_path_not_found` | Fix the records pointer or changed upstream shape. |
| `result_type_invalid` | Records must be an object or list of objects. |
| `result_field_type` | Fix scalar/list/paging field types or mapping. |
| `result_record_too_large` | Reduce projection; identity/link/metadata cannot fit intact. |
| `preset_not_found` | Select a shipped preset. |
| `preset_invalid` | Shipped preset data is missing/damaged. |
| `catalogue_source_not_selected` | Internal adapter call has no selected connection. |
| `catalogue_source_unavailable` | Selection failed; inspect its reason. |
| `catalogue_source_in_use` | Switch the record source before deleting this connection. |
| `audience_not_instance_wide` | Release the connection for every bot visibility. |
| `operation_missing` | Release the required catalogue operation in the active document. |
| `source_unavailable` | Admin state: source withdrawn; searches/indexing pause, stored data remains. |
| `source_unavailable_reason` | Admin status field identifying the underlying source availability reason. |
| `search_failed` | Catalogue search failed/was unavailable, not a successful no-match. |
