# Design

## Context

See `proposal.md` — Why for motivation. Today `api_index()` processes exactly one post per REST call (`LIMIT 1`), and each post costs two Qdrant HTTP requests inside `sync()` (payload retrieve for the fingerprint check, then a single-point inference upsert); `assets/admin.js` drives that loop from the browser and warns the user to keep the page open. The live site has 541 published posts (verified through the site MCP), and the settings already use a WP-Cron single event (`knowledgespark_sync`) for per-post save synchronization. Development constraints: no dependencies, PHP 7.4 syntax in shipped code, offline mocked tests, and the KNOWN-ISSUES invariants (read before designing, per project rules — preservation addressed in D6).

## Goals / Non-Goals

**Goals:**
- Embed up to 100 posts per Qdrant request pair (one retrieve, one inference upsert), using Qdrant Cloud's batched upsert.
- Run bulk indexing server-side so the browser can close at any point; keep it resumable, stoppable, and observable.
- Preserve every recorded invariant and the existing single-post save-sync path.

**Non-Goals:**
- No new libraries, no new DB tables, no PHP workers/queue daemons, no REST-driven browser loop.
- No parallel upload streams or collection topology changes (Qdrant's parallel-bulk guidance targets far larger datasets).
- No agent/MCP surface changes (indexing stays a human admin operation).

## Decisions

### D1: Batch pipeline — two Qdrant calls per 100-post batch

One batch = up to 100 published, public, unpassworded posts after the job cursor (ascending IDs, same SQL shape as `ability_list_posts` minus one extra row trick — `LIMIT 100`). Per batch:

1. Compute profile text and fingerprint for all batch posts locally (reuse `profile()`; extract the fingerprint + point-building from `sync()` into a shared private helper so the single-post and batch paths cannot drift).
2. One `POST /points` retrieve with the batch's IDs (`with_payload: true`, `with_vector: false`) — the existing skip rules (fingerprint match, legacy 0.0.1 modified+url preservation) applied per post against the 100 returned payloads.
3. One `PUT /points?wait=true` upsert containing only stale points, each `{id, vector: {text, model}, payload}` — Qdrant Cloud embeds all 100 server-side in that single request (Document Inference Objects in a `points` array; Qdrant's bulk guidance recommends 64–256 points per batch, so 100 sits inside the recommended range).

`wait=true` stays: one wait per 100 posts instead of 100, and it keeps per-batch error reporting honest. Batch upserts need a longer HTTP timeout than the default 45 s — thread an optional timeout argument through `qdrant()` → `request()` (batch upsert uses 120 s; everything else unchanged).

Alternative considered: pre-embed via the standalone inference endpoint, then upsert raw vectors — two-phase, strictly more code, no benefit since upsert accepts inference objects directly. Rejected.

### D2: Background execution — chained WP-Cron single events + loopback kick

Why WP-Cron: no dependencies are allowed (rules out Action Scheduler), the plugin already trusts it for per-post sync, and long-lived PHP requests are unreliable on shared hosting. Flow:

- **Start** (`api_index`): `ensure()` (collection validation stays the entry gate) → write fresh job state `{status: running, cursor: 0, …}` → clear + schedule the `knowledgespark_index_batch` single event (clear-then-schedule is deterministic against duplicates in both real WP and test stubs) → `spawn_cron()` (WordPress's own non-blocking loopback to `wp-cron.php`, which works from the REST request since `DOING_CRON` is not defined there) → return the initial state. No batch runs inline, so the click returns promptly (spec: prompt start response).
- **Batch run** (`index_batch`): re-read job state; if status is not `running`, exit (honors stop and finished runs). Schedule the successor event (`time()+60`, aligned with core's cron lock window) **before** working, so a PHP kill mid-run can never strand the chain. Then loop: process D1 batches until a 20 s time budget elapses or the run completes, persisting state after each batch. On completion (`done`) mark `status: done` and clear the scheduled event; otherwise leave the successor scheduled. Verified against the shipped WordPress 7.1.5 source: `spawn_cron()` is a no-op inside wp-cron.php (`DOING_CRON` guard), and core hooks `_wp_cron()` on `shutdown` of every ordinary request — including this plugin's 3 s status polls — so the chain advances without browser involvement; ordinary site traffic and system cron are natural fallbacks if a loopback is blocked.
- **Guard against double-running**: clear-then-schedule guarantees a single pending event, the runner never processes more than one batch at a time, and the status re-check at run start makes a second chain a no-op.
- **Deletion race**: re-check each post is still published/public locally right before adding it to the upsert, so a post deleted after the batch's IDs were fetched is not resurrected.

### D3: Job state — one autoloaded option, honest counters

Option `knowledgespark_index_job`: `{status: running|done|stopped, cursor, indexed, skipped, failed, errors[] (capped at 20), started, updated}`. An option (not a table) keeps this a zero-migration WP-native change; the payload is tiny because error details are capped.

Counter/error semantics: retrieve failure → all posts of the batch count `failed`, one error entry names the batch's ID range and cause; upsert failure → only the stale points count `failed` (already-current ones remain `skipped`); success clears `_ks_sync_error` on the embedded posts and sets it on failed ones, preserving today's per-post meta convention. A failed batch still advances the cursor — one bad batch cannot stall the run — and a fresh start re-verifies everything against the actual collection (the existing resume-by-fingerprint safety).

### D4: REST surface — add `status` and `stop`, re-semantics `index`

Extend the existing route loop to `['test','index','reset','suggest','apply','status','stop']`; both new routes require `manage_options` like their admin siblings. `index` starts (or, if a job is already running, returns its state without restarting — idempotent start). `status` returns the persisted state with no Qdrant calls. `stop` sets `status: stopped` and `wp_clear_scheduled_hook('knowledgespark_index_batch')`. The old one-post-per-call contract of `index` disappears — the only consumer was our own admin.js, replaced atomically in this change; the agent abilities never used indexing routes.

### D5: Admin UI — poll instead of drive

`setupIndex()` changes: the Index button POSTs `/index` once, then polls `/status` every 3 s while the page is open; the Stop button POSTs `/stop` (server-side stop, safe to close the page anyway); on page load one immediate `/status` call resumes the live display if a job is running (reopen mid-run scenario) and shows the last run's summary otherwise. The `beforeunload` guard and the loop's cursor bookkeeping are deleted; the settings-page copy drops "Keep this page open" in favor of background-indexing wording. Asset versions bump so the new JS replaces the cached loop. Polling is display-only: closing the page stops polling but never the job.

### D6: Preserved invariants (KNOWN-ISSUES, read before designing)

- **#3 Qdrant transient retry**: both batch requests go through the existing `qdrant()` retry wrapper — the retrieve is a read, the upsert is idempotent by ID, so both remain retry-safe; the cURL/HTTP transient class list, backoff (with `$GLOBALS['ks_backoff_scale']` test hook), and the `ks_qdrant_tls` rewrite are untouched and now protect 2 calls per 100 posts instead of ~200.
- **#1/#2 AI timeout/token budget**: untouched — the suggest/rank path is not modified.
- **#4/#6/#7/#8 span matcher, apply guards, JS mirror**: untouched; `audit/` stays unmodified.
- **#5 development notes**: shipped code stays PHP 7.4 and the dense one-space style; smoke tests extend the existing `$GLOBALS` mock pattern (new stubs for `wp_schedule_single_event`/`wp_next_scheduled`/`wp_clear_scheduled_hook`/`spawn_cron`); no new dependencies; live verification through the container/Novamira, repeated runs for intermittents.
- A new KNOWN-ISSUES entry records the batching and job-state invariants (batch ≤ 100 as one retrieve + one inference upsert; stop honored at batch boundaries; the cron chain; failure accounting) without touching existing entries.

## Risks / Trade-offs

- [Loopback blocked on some hosts → cron chain pauses] → Job state is persisted, so any later request (or a real system cron entry, documented in the readme) resumes the run; the status display makes a stall visible rather than silent.
- [Batch inference request slower / larger than a single-point one] → 120 s timeout for the batch upsert only; profiles are ~1 KB so 100 points ≈ ~100 KB JSON, well inside Qdrant's recommended batch range; retry wrapper handles transients.
- [PHP killed mid-batch (cron time limit)] → The cursor advances only after a completed batch, and every operation is idempotent — the next cron trigger re-runs the same batch safely.
- [Per-post error granularity becomes per-batch] → Accepted: the error entry names the ID range, `_ks_sync_error` is still set/cleared per post, and a re-run retries exactly the failed posts.
- [Batch races a concurrent single-post sync] → Both are idempotent upserts by ID with fingerprint checks; last writer wins per post and the next fingerprint check self-heals — no corruption possible.

## Migration Plan

Deploy = replace the plugin directory (no DB schema change; the job option is created on first start). An interrupted old-style browser run leaves no persistent state; starting the new job simply re-verifies and skips current points. Rollback = restore the previous directory; a leftover `knowledgespark_index_job` option is inert (nothing reads it in the old version). Reset semantics unchanged.

## Open Questions

- DevKinsta local `spawn_cron()` loopback behavior over `http://dev-site.local` — verify during implementation's live check; if loopback is blocked there, the documented traffic/system-cron fallback covers it (no approach change).
