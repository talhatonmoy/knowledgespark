# Proposal

## Why

Indexing is the slowest and most fragile part of KnowledgeSpark. The live site has 541 published posts (verified via the site MCP), and today indexing is browser-driven and strictly serial: the admin page loops over one-post REST calls, and each post costs two Qdrant HTTP requests (a fingerprint retrieve plus a single-point inference upsert). A full run is roughly 541 REST iterations and 1,000+ Qdrant calls, and the settings page must stay open the whole time — it warns "Keep this page open while indexing" and closing the tab stops the run. KNOWN-ISSUES #3 records 27 transient TLS failures during the original 541-post indexing, so long runs multiply the exposure. Qdrant Cloud Inference already supports what the per-post loop never uses: a single upsert request accepts a `points` array of Document Inference Objects (server-side embedding, official guidance 64–256 points per batch), and the payload retrieve accepts an `ids` array — so 100 posts can be fingerprint-checked and embedded in about two requests instead of ~200.

## What Changes

- **Batch-of-100 embedding**: the indexer processes published posts in batches of 100. Per batch it issues one `POST /points` retrieve for up to 100 stored payloads (fingerprint skip check) and one `PUT /points?wait=true` upsert carrying only the stale points, each with its `{text, model}` inference object, so Qdrant Cloud embeds all of them in a single request. The existing fingerprint/legacy skip logic, and the Qdrant transient-failure retry wrapper, are reused unchanged.
- **Background indexing job**: clicking "Index published posts" starts a server-side job and returns immediately. Batches run as chained WP-Cron events (each run processes one batch and schedules the next, with a loopback kick so the chain continues without needing browser traffic), so the user can close the screen. Job state (cursor, counts, recent errors, done/running/stopped) is persisted in an option; a failed batch records its error, is skipped over, and can be retried by running indexing again — the existing resume-by-fingerprint safety is preserved.
- **Progress and control endpoints**: two new REST routes — `status` (job state for polling) and `stop` (server-side stop honored before the next batch) — both `manage_options`. The `index` route's semantics change from "process one post per call" to "start (or restart) the background job".
- **Admin UI without the keep-open constraint**: `assets/admin.js` replaces the browser batch loop with status polling; the stop button stops the server-side job instead of the local loop; the beforeunload guard and "Keep this page open" warning are removed; reopening the settings page shows the running (or last) job's live progress. Settings-page copy is updated accordingly.
- **Unchanged single-post sync**: post-save synchronization (`knowledgespark_sync`) keeps its per-post WP-Cron path; only the bulk indexing pipeline changes.
- **Offline smoke tests and docs**: mocked vectors for the batch pipeline (retrieve count, upsert contents, skip, failure, done detection) and the job state machine (start, run, reschedule, stop, restart); `readme.txt` changelog and usage docs; a new KNOWN-ISSUES entry recording the batching and job invariants.

## Capabilities

### New Capabilities

- `indexing-pipeline`: How published posts get embedded into the site's dedicated Qdrant collection — batch-of-100 embedding with server-side inference, the fingerprint skip check at batch granularity, the resumable background indexing job and its lifecycle (start, run, stop, restart, completion), progress reporting to the admin UI, and retry behavior on Qdrant transient failures. This capability was previously unspecified: indexing behavior lived only in the implementation.

### Modified Capabilities

(none — `link-placement` and `agent-tools` are untouched: suggestions, apply guards, abilities, and the single-post save-sync path keep their current requirements and invariants.)

## Impact

- **Code**: `includes/class-knowledgespark.php` (batch runner, job-state option, cron action registration, `status`/`stop` REST routes, `index` route re-semantics, settings-page copy), `assets/admin.js` (polling UI replaces the browser batch loop, stop button, page-load resumption), `tests/smoke.php` (WP-Cron stubs plus batch and job-state vectors), minor `assets/admin.css` if the progress display needs it.
- **APIs**: `knowledgespark/v1` gains `status` and `stop`; `index` becomes a start-job call. No REST route is removed.
- **Systems**: WP-Cron gains one self-rescheduling event type (`knowledgespark_index_batch`); Qdrant Cloud sees two HTTP requests per 100 posts instead of ~200, both through the existing retry wrapper.
- **Dependencies**: none. Plain PHP/JS, no libraries; the batch size is a class constant (100, inside Qdrant's recommended 64–256 range), not a setting.

## Out of Scope

- No agent/MCP abilities for indexing — admin operations (index, reset, test, settings) stay human-only, per the `agent-tools` scoping; agents keep the existing four abilities.
- No parallel upload streams, shard, or collection-topology changes — Qdrant's parallel-bulk guidance targets datasets far larger than this site's; the single-threaded cron chain is sufficient at 541 posts.
- No configurable batch size, embedding-model changes, client-side embedding, or new collection settings.
- No changes to suggestion, ranking, apply, or span-matcher behavior and their KNOWN-ISSUES invariants.
- No notifications beyond the settings page (no emails, webhooks, or editor-page progress).
- No real-cron/systemd requirement: the chain survives on loopback kicks plus ordinary site traffic, with system-cron fallback documented only.
