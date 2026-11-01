# EDUC course catalogue preset

| File | Content |
|---|---|
| `upstream-3.0.2.json` | Unmodified document from `https://catalogue.educalliance.eu/api/` (info version 3.0.2), retrieved 2026-10-30, SHA-256 `9b681eecb571aef0138bff2c829188777ce277408317f73471ce8637377f8db3`. Kept for comparison only; never imported. |
| `openapi.json` | Normalized read-only subset (info version `3.0.2-talkai.1`) that Talk AI imports. |
| `preset.json` | Connection defaults and suggested review values (labels, descriptions, mappings). Preset revision 1. |

The preset is not updated from the remote document. A new upstream version means a new
upstream file, a new normalized revision and a new preset revision, reviewed by hand.

## Deviations from the upstream document

Each deviation was checked against public, anonymous reads of the live API on 2026-10-30.

| Upstream | Normalized | Reason |
|---|---|---|
| Server `https://catalogue.educalliance.eu/api/api/` | `https://catalogue.educalliance.eu/api` | The doubled `/api` does not exist; all routes answer below `/api`. |
| `GET /course/list` takes its parameters as a required `application/x-www-form-urlencoded` request body | Query parameters | The API reads the query string. A GET body cannot be sent and blocks the operation. |
| `state` is not declared | Required query parameter, `OPEN` or `CLOSED` | Without it the API answers `500 {"error":"Wrong or missing course state"}`. |
| `search` is an integer | String, at most 200 characters | It is a free-text search. |
| Filters (`learning_type_id`, `discipline_id`, `target_group`, `mobility_format_id`, `semester_id`, `language_id`, `lead_university_id`) are integer arrays inside a `Filter` object | Strings with comma-separated IDs, at most 200 characters | The legacy client sends them this way. Server-side filtering is not verified by a live test. |
| `id` is not declared for the course list | Optional integer | The legacy client sends it for ID lookups. The API currently ignores it; lookups page through the list. |
| `GET /view` with an optional `id` | `GET /course/view` with a required `id` | `/view` does not exist; the detail route is `/course/view` and needs the ID. |
| Response `$ref`s use `app\schemas\NamedValue` | `#/components/schemas/NamedValue` | The upstream references cannot be resolved. |
| `ects` is a nullable string | Nullable number | The API returns numbers. |
| No `security` | `security: []` | All included operations are public reads. |

## Omitted operations

Not part of the preset because they change data, depend on a login, are administrative or
are not needed by Talk AI. Several of them are GET requests that still change state.

- Changes state or session: `/application/apply`, `/application/unapply`, `/favourite/create`,
  `/favourite/delete`, `/login`, `/login/logout`.
- Specific to a logged-in user: `/favourite/list`, `/login/status`, `/applied`, `/application/get-status`.
- Administrative: `/admin`, `/admin/course`.
- `/filters` and `/filters/{filterClass}`: the response groups lists of `{id, name}` objects, which
  the tool projection cannot express, and Talk AI does not need them. Upstream, `/filters/{filterClass}`
  also declares its path parameter as optional.

## Suggested review values

All three operations are preselected with `auth: none` and the course link template
`https://catalogue.educalliance.eu/Opportunity/{id}`. The read confirmation, the data visibility,
the approval and the enabling stay with the administrator.

- **`GET /course/list`** maps `/courses` with `/count` as the total.
- **`GET /course/view`** maps `/course` as a single record.
- **`GET /version`** feeds the optional version check of the catalogue source.
