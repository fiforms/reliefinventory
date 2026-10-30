# REPORT_ENGINE.md

Pre-plan for a customizable report engine: report definitions, layouts and dashboards that a warehouse admin edits in the web UI
instead of waiting for a software release. Nothing here is built. Concept from Daniel and Mark; the architecture review and open
questions below are from a read of the current code. Roadmap entry: [ROADMAP.md](ROADMAP.md) section 5. How reports work today: [notes/REPORTS.md](notes/REPORTS.md).

## Why

Today each report is a controller (`index` / `pdf` / `csv` / `buildReport()`), a Blade PDF view, a `DocumentRegistry` entry and a Vue page.
A new report, or a branding change, means a code change, a release, and an instance admin updating. This engine moves *presentation
and aggregation* into data a warehouse admin can edit, so:

- the shipped reports become standard definitions that can be customized and saved per warehouse;
- warehouses can add their own reports, situation reports and dashboards without a release;
- branding (logo, address, contact) is defined once per warehouse and reused by every report, not per report (Mark).

New data (a new field, a new source) still needs a software release. The engine only decouples what is done *with* data we already expose.

## The pieces

| Piece | Role | Runs |
|---|---|---|
| **Data sources** | Documented, permissioned JSON endpoints, from the local database or federated peers | server |
| **Report definition** | JSON document: which sources, params, a jq filter to reshape/aggregate, which template, which output | stored per warehouse |
| **jq** | Transform/aggregate/calculate over the source JSON | server (PDF/CSV), browser (dashboards) |
| **Template** | Turns transformed JSON into a document | server |
| **Dashboard widget** | Declarative JSON (type, interval, source, jq filter) rendered by Chart.js | browser |

Template engine: the original idea was Carbone (.docx templates); a later revision proposed Mustache + HTML → WeasyPrint. See "Template engine" below.
Recommendation: HTML + Mustache first.

## What the current architecture gives us

- **WeasyPrint is already in the Docker image** and every PDF uses it (`->driver('weasyprint')`, `Services/Printing/DocumentRegistry.php`). The image is
  deliberately trimmed (`Dockerfile`, `docker/trim-image.sh`), so every added runtime has a cost.
- **Chart.js is already wired** (`Components/ChartCanvas.vue`: bar/line only, numbers always in a table beside it, no dual axis). Dashboard widgets
  should reuse it and keep those rules.
- **Reverb** (realtime) exists ([notes/REVERB.md](notes/REVERB.md)), so live dashboards can push instead of poll.
- **Per-warehouse storage and schema** ([notes/MULTI_WAREHOUSE_NOTES.md](notes/MULTI_WAREHOUSE_NOTES.md)): definitions and templates belong to one warehouse.
  Never assume `storage_path()` is shared; go through the warehouse context.
- **Permissions** are `permission:<key>` middleware; a page and its `/json` endpoints share a key ([notes/ROUTES.md](notes/ROUTES.md)).
- **Federation** exists for signed peer requests ([notes/FEDERATION.md](notes/FEDERATION.md)), the basis for federated data sources.
- Existing aggregation lives in controllers and `Services/WarehouseMetrics.php`. `FILTER_RULES` per report already describe parameters.

## Data sources (the API)

The pasted idea says "controllers provide a documented JSON REST API". Design points:

- **Don't point definitions at the existing page `/json/*` endpoints.** They are shaped for specific Vue pages, session/CSRF-authenticated, and free to change
  with the UI. A report definition that depends on them would break on any refactor. Create a **separate, versioned report-data layer** with its own
  contract (name, params, output schema, required permission) and treat changing it as a breaking change.
- **Explicit registry** (from the guardrails): a data source exists only if registered. Each entry declares: id, parameters (typed, validated), output
  schema, required permission key, and whether it is local or federated. Definitions reference sources by id, never by URL.
- **Who runs the query.** On the server, resolve a source in-process (call the service, not HTTP back to ourselves), as the *requesting user*, checking
  the source's permission. A definition never grants access; a user without the source's permission gets a denied source, not partial data. Scheduled
  or emailed reports need an explicit, stored run-as identity: decide this deliberately.
- **Same sources in the browser** for dashboards, through one `/json/report-data/{source}` style endpoint backed by the same registry, not one route per source.
- **Size/row limits** on every source (the Flow report already refuses above `MAX_BUCKETS`; do the same).
- **Federated sources** go through the existing signed peer requests and appear in the registry as remote sources with their own permission mapping.
  Keep these out of phase 1.
- Document the registry (generated from the registry itself, like `SCHEMA.md`) so report authors have a reference.

## Report definitions

Stored as JSON, validated against a schema on save. Roughly:

```
{ "id", "title", "version", "permission",
  "params":   [ typed inputs shown in the UI ],
  "sources":  { "stock": { "source": "inventory.by_item", "args": {...} } },
  "transform": "<jq program over { stock, ... }>",
  "outputs":  [ { "format": "pdf|csv|screen", "template": "..." } ] }
```

- **Where they live:** a database table in the warehouse schema (not files), so they are covered by the warehouse backup and restore with no extra
  paths, versioned (keep prior versions to allow rollback), with created/edited-by recorded. *Open:* confirm how backup treats uploaded files if
  templates need assets such as logos.
- **Shipped defaults:** standard reports ship in the repo as definition files. A warehouse customizes by copying to a local override; a release updates the default,
  the override is kept, and the UI shows "customized" with a "reset to standard". Need a rule for when a new release changes the data-source contract under an override.
- **Export/import** a definition (and its template) as a single bundle, so a good report built at one warehouse can be shared with another. Imports are untrusted
  input ([notes/UNTRUSTED_CONTENT.md](notes/UNTRUSTED_CONTENT.md)): validate, never trust, show a diff before enabling.
- **Branding** is not part of a report: a per-warehouse branding record (logo, name, address, contact, footer) exposed to every template as shared
  data and shared partials (header, footer, address block). This delivers "branding across reports, not per report".

## jq

- **Server:** the `jq` binary is small and in Debian, easy to add to the image. Run it as a subprocess with a hard timeout (5 s per the guardrail), a memory/CPU ulimit,
  no filesystem or env access (`jq -n`, no `--rawfile`/`input_filename`, no `$ENV`/`env` leakage: restrict via the sandbox, since jq exposes `env`), and an output size cap.
  Input goes over stdin; the program is passed as a file/arg, never interpolated into a shell string.
- **Browser:** jq-wasm in a Web Worker with a kill-on-timeout (terminate the worker) so a runaway filter can't freeze the UI.
- **Parity risk.** The same filter must give the same result in jq (C) and jq-wasm, which may be a different version. Pin and test both against one shared fixture set.
  If this proves flaky, run dashboard filters server-side too and give up the client-side compute (cheaper to secure, costs one request per refresh).
- jq is a real language. It can't run code or touch the network, but it can burn CPU/memory (`recurse`, `range`, `limit`-less loops) and read env. Treat it as hostile input:
  limits above, not trust.
- **Authoring experience is the hard part.** jq is unfamiliar to most admins. The editor needs: a live preview against real (or sample) data, readable errors, and starter filters.
  A visual builder (pick source, group by, sum) that *generates* jq would reach many more users and could come after the raw editor. Consider whether the
  audience is "any admin" or "the one person per org who likes this"; that changes how much UI is worth building.

## Template engine

| | HTML + Mustache → WeasyPrint | Carbone (.docx) |
|---|---|---|
| New to our stack | Mustache library only (WeasyPrint is already there) | Node service **plus LibreOffice** for PDF output (hundreds of MB; fights the trimmed image) |
| Authoring | HTML/CSS editor in the web UI | Word, which admins already know |
| Output | PDF, HTML, and CSV easily | .docx and PDF |
| Fidelity with today's PDFs | Same engine as every PDF now | Different renderer, different look |
| Security | Logic-less, auto-escaping; WeasyPrint needs the fetcher lock below | Template is a binary zip: harder to validate and preview |

**Recommendation: HTML + Mustache + WeasyPrint for v1**, because it adds no big runtime and matches the existing PDF pipeline. Revisit Carbone only if admins
insist on Word authoring; if so, run it as an optional sidecar container, not in the main image.

Security notes for the HTML path:
- Mustache `{{ }}` escapes HTML, which is *not* context-aware: a value in an `href`, `src`, or inline `style` is still dangerous. Disallow raw `{{{ }}}`,
  and sanitize/allowlist URL and CSS contexts. (The claim in the original notes that this "completely eliminates XSS" is too strong; escaping handles text nodes only.)
- Data inside reports (donor, partner, item names; free text) is **untrusted** ([notes/UNTRUSTED_CONTENT.md](notes/UNTRUSTED_CONTENT.md)). Templates are written by admins, but the
  *data* is not, so the escaping matters even in a fully trusted-admin model.
- **WeasyPrint hardening:** supply a `url_fetcher` that permits only whitelisted resources (the warehouse's own uploaded logo/assets, bundled fonts) and rejects `file:`,
  `http(s):` and everything else. Today's Blade PDFs are our own code; custom templates are not, so this is new ground. Run WeasyPrint with a time limit.
- Templates are previewed in the editor as an iframe with `sandbox` and a strict CSP, never injected into the app's DOM.

## Dashboards (live situation view)

- A **widget** is JSON only: `{ type: bar|line|table|stat, source, args, jq, refresh }`, never code. A **dashboard** is an ordered list of widgets.
- The browser fetches through the registry endpoint, filters in the jq worker, and hands `{ labels, datasets }` to `ChartCanvas`. Keep the existing chart rules
  (bar/line, a table with the same numbers, no dual axis). Add `stat` and `table` widgets since they need no canvas.
- Poll first; use Reverb pushes ("source X changed") later to trigger refetch rather than streaming data.
- Any warehouse can show one on the kiosk / R Station view ([SUB_PROJECTS](notes/SUB_PROJECTS.md)); *open:* is that in scope?

## Permissions

New keys, added to `PermissionsSeeder`:
- **run reports** (existing `view-reports`, plus per-source permissions at fetch time);
- **manage report definitions / templates / branding** (administrator-level): the ability to write a definition is the ability to choose what data gets shown and to whom, so keep it narrow.
- Each definition declares its own permission to run; the editor can't grant more than the author's own source access (an editor may not save a definition referencing a source they can't read).

## Migration of existing reports

Incremental, one report per step; the old controller keeps working until its definition reproduces it with matching totals.
Reports with heavy logic (Flow bucketing, donor standing suggestions, `Sitrep`) may stay as registered data sources with the *layout* moved into templates,
rather than trying to rewrite their aggregation in jq. Not everything needs to be jq: **sources do heavy lifting, jq reshapes.** The existing tests that
check Flow against Inventory totals give a ready regression bar.

## Suggested phases

1. **Spike (a week or so):** server jq runner with limits + Mustache + WeasyPrint with the locked fetcher, rendering one real report (Outstanding Orders) from a hand-written definition, compare against the current PDF. Settles jq-in-image, fetcher lockdown and fidelity before any UI.
2. **Registry + branding:** data-source registry with permission checks and generated docs; branding record and shared partials.
3. **Definitions + editor:** storage with versions, shipped defaults + per-warehouse override, editor with live preview, PDF/CSV output. Convert the standard reports.
4. **Dashboards:** widget schema, jq worker, ChartCanvas reuse.
5. **Later:** visual builder, definition export/import, federated sources, scheduled/emailed reports, optional Carbone sidecar.

## Open questions

- Audience: do admins write jq, or do we need the visual builder at v1? (Drives most of the UI cost.)
- Run-as identity for scheduled/emailed reports.
- Backup coverage for uploaded assets (logo) and whether a restore into a different instance remaps references.
- Release compatibility: what happens to a customized definition when a data source changes shape?
- Training warehouse: seeded with the standard definitions, or reset with them?
- Localization ([HELP_AND_LOCALIZATION_PLAN.md](HELP_AND_LOCALIZATION_PLAN.md)): report strings in templates; per-language template variants or message keys?
- Support burden: a broken customized report is the warehouse's, but "reset to standard" must always work.
- jq-wasm vs. server-only for dashboards (see parity risk).
