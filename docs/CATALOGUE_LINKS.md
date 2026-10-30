# Course links from the catalogue

Bots link catalogue courses only with a web address that the administrator configured.
They never derive a course page from the catalogue API address, and they never present an
application form as the course page.

## Configuration

**Administration settings → Talk AI → Knowledge → Course catalogue → Course links**

| Setting | Meaning |
| --- | --- |
| Course link template | Absolute `https://` address with exactly one `{id}`, in the path or in a query value. The host is fixed; `{id}` may not appear in the host, in a query key or in the fragment. |
| Link field in the record | [JSON pointer](https://www.rfc-editor.org/rfc/rfc6901) to a web address in each catalogue record. Empty means `/permalink`. |
| Accepted web origins | One `https://host[:port]` per line, without a path (at most 20). Only record links on these origins are used. The template origin is always accepted. |

**Use EDUC Alliance catalogue preset** fills in
`https://catalogue.educalliance.eu/Opportunity/{id}` and its origin as unsaved changes.
It is never applied automatically. Without a template and without accepted origins, the
section shows a warning, and bots say that the course link is not available.

**Preview link** resolves one record ID with the entered (also unsaved) values. It reads
the local catalogue index only and contacts no external service. It shows the course page,
whether it came from the record or from the template, the separate application link and
every ignored value with its reason.

The server validates the configuration on save and stores it normalised (lowercase host,
default port removed). Invalid input is rejected with the affected field; the stored value
stays unchanged. Changes apply to the next answer. They do not reindex the catalogue or
recompute embeddings.

## Resolution order

For every record the same resolver is used by `catalogue_search`, `catalogue_past_search`,
`catalogue_get_opportunity` and the alias `catalogue_search_courses`:

1. The configured link field, when it is a valid absolute HTTPS address on an accepted
   origin.
2. Otherwise the template, with the record ID (`/id`) percent-encoded into `{id}`. IDs are
   non-negative integers or printable ASCII up to 128 characters; `.` and `..` are refused.
3. Otherwise no course page. Tools output “not available (no verified course link)”.

Record links are refused, and reported in the preview, when they are relative, use another
scheme, contain user information, whitespace, control or non-ASCII characters, unsafe or
encoded backslash escapes, dot segments, or are longer than 2048 characters. Links on
origins that are not accepted are ignored. `application_link` passes the same safety checks
as an absolute HTTP(S) address on any host, because application forms are usually hosted
elsewhere. It is only shown as “Application link (external application form, not the
course page)” and never replaces the course page.

## Tool output

Every listed entry carries ID, title and its full course page. Search results add whole
entries only while they fit into the 4000-character tool budget; entries that do not fit
are omitted and counted, so no link is ever cut. Course details put identity and links
first and shorten or omit the remaining sections.

## Answer check

While a bot uses catalogue tools, its answer is held back until the run ends and checked
once before anything is published to Talk:

- `{{course:ID}}` is replaced by `[Title](course page)` only for records returned by a
  catalogue tool in the same run.
- A link to a catalogue course page must equal the canonical link of that record, looked up
  from the run's tool results or the local index.
- A catalogue-looking link to an unknown record, or a known course path on another host,
  counts as unverified.

If any check fails, the whole answer is replaced by a short list of the verified entries
(at most 10, referenced entries first) with their course page or “Course link not
available” and the separate application link. Links outside the catalogue are left
untouched; there is no global link rewriting. The outcome is recorded in the run trace as
`catalogue_link_guard` with outcome, reasons and record IDs.

## Upgrade

Migration `Version024400Date20261030000000` adds three nullable settings columns. Existing
installations keep working and see the missing-configuration warning until a template or
accepted origins are saved. No reindex is needed.
