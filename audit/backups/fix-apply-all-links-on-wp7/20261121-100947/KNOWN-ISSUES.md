# KnowledgeSpark — Known Issues, Fixes, and Invariants

This file is the project's long-term memory for production issues that were solved.
**Read it before changing `includes/class-knowledgespark.php` or `assets/admin.js`.**
Each entry lists the user-visible symptom, root cause, the fix, the invariants future development
must respect, and the automated checks that will fail if a fix regresses.

Run the guards:
```
php -l includes/class-knowledgespark.php
php tests/smoke.php          # 32 checks
node --check assets/admin.js
node audit/js-span-test.js   # 10 checks (verifies the shipped admin.js code)
```

---

## 1. AI call timed out at 45 s (`cURL error 28`, "AI ranking unavailable")

- **Symptom (user sees):** red/orange fallback: `AI ranking unavailable; showing semantic matches. cURL error 28: Operation timed out after 45006 milliseconds…` with boilerplate reasons and no anchors.
- **Root cause:** the provider (GLM-5.3-Flash via api.river.ai) needs ~30–90 s for the production prompt; the original generic 45 s HTTP timeout was too small.
- **Fix:** `rank()` passes a **120 s timeout** to the AI call only (Qdrant stays at 45 s), and `assets/admin.js` allows **180 s** for the `suggest` route (`var timeout = route === 'suggest' ? 180000 : 100000;`). The prompt was also slimmed to keep generation fast: article text 8,000 chars, 12 candidates × 1,500-char excerpts.
- **Invariants (do not regress):**
  - AI call timeout ≥ 120 s (`rank()`, the `...,120)` on the `request()` calls).
  - Browser suggest timeout ≥ server AI timeout + headroom (180 s in admin.js).
  - Prompt budget stays slim (8k / 12×1500) — the original 40k / 20×3500 payload pushed total time past any safe timeout.
- **Guards:** live-only (no mock); verify any AI-path change with a real run:
  `docker exec devkinsta_fpm php -r '<require wp-load, call KnowledgeSpark::api_suggest for post 410, print elapsed + message>'` — expect "AI-reviewed suggestions" in ~30–90 s, not the fallback.

## 2. "AI returned an invalid recommendation format" (thinking-model token truncation)

- **Symptom (user sees):** `AI ranking unavailable; showing semantic matches. AI returned an invalid recommendation format.` — even though the AI call *succeeded* (HTTP 200 in ~30–50 s). Intermittent.
- **Root cause:** GLM-5.3-Flash is a **thinking model**. It emits hidden chain-of-thought (`reasoning_content`; `usage.completion_tokens_details.reasoning_tokens`) that consumes output tokens. Observed reasoning lengths per identical call: **1,888 / 2,703 / 6,008 / 2,988 tokens**. With `max_tokens: 4000`, whenever reasoning exceeded the headroom the JSON answer was cut off mid-string (`finish_reason: length`) and `json_decode` failed. The provider **ignores** `thinking: {type: "disabled"}` (accepted, HTTP 200, reasoning still generated — probed 2026-11-21).
- **Fix (in `rank()` + `parse_rank_response()`):**
  - `max_tokens` raised **4,000 → 16,000** (the model emits the same reasoning either way; the cap only prevented truncation).
  - **One automatic retry** when the parse fails and the first attempt finished in < 90 s (truncation fails fast; reasoning length varies, so a retry usually gets a shorter one).
  - `parse_rank_response()` validates `choices[0].message.content` (string → strip optional ``` fence → `json_decode` → `suggestions` array).
  - Failure message is now self-diagnosing: `…(finish: length, completion tokens: 4000). The answer was cut off at the token limit. One automatic retry was attempted…`.
- **Invariants (do not regress):**
  - `max_tokens` ≥ ~8,000 (worse: never below what the longest observed reasoning + ~1,500 answer tokens needs).
  - Keep the retry-once + 90 s gate, and the diagnostics fields (`finish_reason`, `completion_tokens`) — they are the only way to see this class of failure from the UI.
  - Keep `parse_rank_response()` as the single parse/validation point for AI output.
- **Guards (tests/smoke.php):** `Retry the AI call once when it returns a truncated answer` and `Surface AI format failure with finish/token diagnostics` (mock hook `$GLOBALS['ks_format_fail']`).

## 3. Qdrant `cURL error 60: SSL: no alternative certificate subject name matches…`

- **Symptom (user sees):** suggestions died at the query step: `cURL error 60: SSL: no alternative certificate subject name matches target host name '…aws.cloud.qdrant.io'` (reproduced 2026-11-21 at 7 s; the same class of error caused 27 transient failures during the original 541-post indexing).
- **Root cause:** Qdrant Cloud sits behind AWS CloudFront; an edge node occasionally serves a TLS certificate whose SANs don't match the requested host. Purely transient — retrying against the same host succeeds (different edge).
- **Fix (in `qdrant()`):** all Qdrant calls **retry up to 3×** on transient failures — cURL codes **28, 35, 52, 53, 56, 60** or remote HTTP **502/503/504** — with backoff `usleep((1+attempt) * 1 s)` (scaleable via `$GLOBALS['ks_backoff_scale']` for tests). All Qdrant operations used by the plugin are idempotent (reads, upsert-by-id, delete), so retrying is safe. If TLS errors persist through all attempts, the error is rewritten to a clear **`ks_qdrant_tls`** message (HTTP 503): "intermittent … clears on its own — try again in a few seconds."
- **Invariants (do not regress):**
  - The retry loop + transient code list in `qdrant()` must stay; removing it returns users to raw cURL 60 failures on every edge glitch.
  - Keep idempotency-safe assumptions: if you add a **non-idempotent** Qdrant call, it must bypass the retry or be made idempotent.
  - Keep the `ks_qdrant_tls` rewrite so users get guidance instead of a raw cURL message.
- **Guards (tests/smoke.php):** `Retry transient Qdrant TLS error and recover` and `Report a persistent TLS mismatch with guidance` (mock hook `$GLOBALS['flaky']`).

## 4. Apply All Links — HTML anchor insertion safety

- **Risk (if regressed):** wrapping anchor text in `<a>` can produce broken markup (nested/unclosed tags, links inside links, block-level tags inside anchors) when the anchor span starts or ends inside an inline tag or crosses a `<p>` boundary — e.g. anchor `hard all day` over `<strong>hard</strong> all day`, or `spot y` over `spot</em> y`.
- **Fix:** the span matcher (`find_anchor_span()` → `is_tidy_span()` → `nesting_ok()`/`tag_stack()`) only returns a span that is (a) not inside a tag at either end (last-`<`/first-`>` checks), (b) contains no block-closing tag, (c) contains and overlaps no `<a>`, (d) nesting-consistent: the seeded tag stack opened **before** the span must balance identically **after** the span (a tag opened before the span closing inside it, or opened inside it and left unclosed, both break the markup and are rejected). Unplaceable anchors are **skipped and reported, never forced**.
- **Invariants (do not regress):**
  - **The PHP and JS implementations are mirrors.** `admin.js` has `findAnchorSpan` / `spanNesting` / `tagStackScope` implementing the same guards. Any change to the guards **must be made in both files** and verified with both suites: `php tests/smoke.php` (span checks) and `node audit/js-span-test.js` (runs the shipped admin.js code against the same vectors).
  - The join pattern `(?:\s|<[^>]+>)*` between anchor characters is what lets the anchor match across inline tags; keep it in both.
  - Never "force" an insertion when guards reject it; the skip list in the response/UI is the contract. Span validity rests on both engines agreeing — one-sided changes silently desync the editor-side and server-side insertion.
- **Guards:** 10 span checks in `tests/smoke.php`, 10 vectors in `audit/js-span-test.js`, 5 `api_apply` e2e checks in `tests/smoke.php`, plus `audit/e2e-apply.php` (live, on a throwaway draft post).

## 5. Development notes for this plugin

- **Environment (DevKinsta):** host PHP 8.4 cannot reach the DB — run all WordPress/DB/Qdrant work with `docker exec devkinsta_fpm php …`; container PHP is **7.4** (no arrow functions / str_contains / PHP 8 syntax in shipped code). Site: `/www/kinsta/public/dev-site` (volume-mounted, host edits are live in the container).
- **Code style of `class-knowledgespark.php`:** dense — **1-space indentation per level, no blank lines**. The `read`/GUI display adds one column; byte-check with `od -c` or a PHP `substr` dump before exact-match edits (exact-match edits have failed repeatedly against display indentation).
- **Whole-file rewrite hazard:** `file()` keeps trailing `\n`; combining it with `implode("\n", …)` doubles every newline (blank line inserted between every line). If a script rewrites the class file, read with `FILE_IGNORE_NEW_LINES`.
- **Testing conventions (tests/smoke.php):** standalone, no WP boot — mocks live at the top (`WP_Error`, `wp_safe_remote_request`, `$GLOBALS` hooks: `index_test`, `flaky`, `ks_format_fail`, `ks_backoff_scale`, `fail_write`). Everything beyond the mocks must keep working; the suite is the regression gate.
- **Live checks (do for provider-facing changes):** repeated runs are required — both failure modes above are *intermittent*. A single successful live run does not prove a fix; a single failure does not disprove current code. Compare `usage.reasoning_tokens` / `finish_reason` when diagnosing AI issues (`audit/ai-format-probe.php`, `audit/ai-repeat-probe.php` replicate the production payload and dump raw provider responses).
- **Provider quirk (validated 2026-11-21):** river.ai GLM-5.3-Flash accepts but **ignores** `thinking: {type: "disabled"}` and `response_format: json_object` works. Do not build logic on the assumption that thinking can be turned off.
