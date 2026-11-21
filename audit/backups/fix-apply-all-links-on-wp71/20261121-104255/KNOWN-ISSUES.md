# KnowledgeSpark — Known Issues, Fixes, and Invariants

This file is the project's long-term memory for production issues that were solved.
**Read it before changing `includes/class-knowledgespark.php` or `assets/admin.js`.**
Each entry lists the user-visible symptom, root cause, the fix, the invariants future development
must respect, and the automated checks that will fail if a fix regresses.

Run the guards:
```
php -l includes/class-knowledgespark.php
php tests/smoke.php          # 49 checks
node --check assets/admin.js
node audit/js-span-test.js   # 35 checks (verifies the shipped admin.js code)
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
  - **Protected markup is opaque** (added by #6): `<script>`, `<style>`, and HTML-comment ranges — including an unclosed tag stretched to end of content — are computed with the identical regex in both engines (`protected_ranges()` / `PROTECTED_RE` + `protectedRanges()`), and no span may start, end, include, or bridge across such a range. Extend both engines' ranges together; a one-sided change silently desyncs what each engine refuses.
  - Never "force" an insertion when guards reject it; the skip list in the response/UI is the contract. Span validity rests on both engines agreeing — one-sided changes silently desync the editor-side and server-side insertion.
- **Guards:** the matcher, apply, and per-cause skip vectors in `tests/smoke.php` and their mirror in `audit/js-span-test.js` (including the WP 7.1.5 protected-markup vectors and the stubbed block-editor harness — see #6), plus `audit/e2e-apply.php` (live, on a throwaway draft post).

## 5. Development notes for this plugin

- **Environment (DevKinsta):** host PHP 8.4 cannot reach the DB — run all WordPress/DB/Qdrant work with `docker exec devkinsta_fpm php …`; container PHP is **7.4** (no arrow functions / str_contains / PHP 8 syntax in shipped code). Site: `/www/kinsta/public/dev-site` (volume-mounted, host edits are live in the container).
- **Code style of `class-knowledgespark.php`:** dense — **1-space indentation per level, no blank lines**. The `read`/GUI display adds one column; byte-check with `od -c` or a PHP `substr` dump before exact-match edits (exact-match edits have failed repeatedly against display indentation).
- **Whole-file rewrite hazard:** `file()` keeps trailing `\n`; combining it with `implode("\n", …)` doubles every newline (blank line inserted between every line). If a script rewrites the class file, read with `FILE_IGNORE_NEW_LINES`.
- **Testing conventions (tests/smoke.php):** standalone, no WP boot — mocks live at the top (`WP_Error`, `wp_safe_remote_request`, `$GLOBALS` hooks: `index_test`, `flaky`, `ks_format_fail`, `ks_backoff_scale`, `fail_write`). Everything beyond the mocks must keep working; the suite is the regression gate.
- **Live checks (do for provider-facing changes):** repeated runs are required — both failure modes above are *intermittent*. A single successful live run does not prove a fix; a single failure does not disprove current code. Compare `usage.reasoning_tokens` / `finish_reason` when diagnosing AI issues (`audit/ai-format-probe.php`, `audit/ai-repeat-probe.php` replicate the production payload and dump raw provider responses).
- **Provider quirk (validated 2026-11-21):** river.ai GLM-5.3-Flash accepts but **ignores** `thinking: {type: "disabled"}` and `response_format: json_object` works. Do not build logic on the assumption that thinking can be turned off.

## 6. Apply All Links — every anchor "was not found in the current draft" on html-block posts (WP 7.1.5)

- **Symptom (user sees):** in the Gutenberg editor, clicking Apply All Links on a post whose article is a single `core/html` block (this site's standard content shape) reports `No links could be inserted at their suggested anchors.` and every suggestion says `its anchor was not found in the current draft` — even when the anchor text is verbatim in the draft. Reproduced 2026-11-21 on post 410: 5/5 anchors failed.
- **Root cause (three verified defects, found in that order):**
  1. **Dead block-content accessor.** WordPress 7.1.5 registers the text attribute as `content` for `core/html`, `core/paragraph`, `core/list-item`, `core/freeform` (verified in-container via `WP_Block_Type_Registry` — no `innerHTML` key exists). `applyViaBlockEditor()` read only `block.attributes.innerHTML` → the search string was always `''` → no anchor could ever be located. Older block schemas used `innerHTML`; some intermediate ones use `value`.
  2. **Protected-markup susceptibility (latent, activated by fixing 1).** The span matcher matched **inside** `<script type="application/ld+json">` schema markup: headline/FAQ text is a verbatim copy of body text and sits at the top of the html block, so it wins document order. A span fully inside a `<script>` body passed every pre-fix guard — and would have injected `<a>` into the JSON-LD on the first successful apply, silently breaking structured data. Proven 2026-11-21 by running the shipped matcher against post 410's actual structure.
  3. **Misleading skip message.** Anchors whose only occurrence sits inside an existing link were correctly rejected by the anti-nested-link guard (the draft links those phrases to the production mirror `jarhabitats.com`, which `url_to_postid()`'s exclusion map cannot see) but were reported as "not found".
- **Fix (change `fix-apply-all-links-on-wp7`, verified 2026-11-21):**
  - `applyViaBlockEditor()` resolves the text attribute from the chain `innerHTML` → `value` → `content` (first string-valued key) and writes the result back under **the same key it read**.
  - Both matchers pre-scan protected ranges — `<script …>…</script>`, `<style …>…</style>` (stretched to end-of-content when the tag is unclosed), and `<!-- … -->` — with an identical regex, and reject any candidate span that overlaps one. Each rejection carries its reason (`in_link` / `in_protected` / `untidy`; PHP out-param, JS `hint` field), so the engine that dies knows why.
  - Skip sentences use one fixed taxonomy in both engines: not in the current draft / already inside a link / only inside script, style, or comment markup / crosses formatting that cannot be wrapped safely. "Not in the current draft" is reserved for genuinely absent text.
- **Invariants (do not regress):**
  - The ordered attribute chain plus same-key write-back is the cross-version contract. **Never reintroduce a single hard-coded attribute name.** Diagnostic when apply-wide failures recur: diff `wp-includes/blocks/*/block.json` attribute names in the container first.
  - The protected-range regex and the overlap rule must stay identical between `protected_ranges()` (PHP) and `PROTECTED_RE` / `protectedRanges()` (JS) — change both together.
  - Both engines report the same sentence per cause; found-but-unplaceable text must never render as "not in the current draft".
  - The inserted `<a>` keeps its `title` attribute (tests assert the exact saved markup) — do not drop it.
- **Guards (offline: `tests/smoke.php` 49 checks, `audit/js-span-test.js` 35 checks):** matcher vectors for inside-script (JSON-LD shape), inside-style, inside-comment, bridged-across-script, unclosed-script over-mask (2), body-preferred positive with script byte-identical after insertion, per-cause `api_apply` sentences, and the stubbed block-editor harness (attribute chain: `content`-only / both-keys-prefers-`innerHTML` / no-key-skips; mixed and all-fail skip causes; JSON-LD left byte-identical). Live check (DevKinsta, throwaway draft post, deleted after): plain anchor wrapped and saved, JSON-LD byte-identical before/after, already-linked and JSON-only anchors skipped with their honest sentences.
- **Open follow-up (separate change, deliberately not fixed here):** cross-domain mirror awareness at suggest time (the AI still picks anchors inside production links because the exclusion map cannot see `jarhabitats.com` URLs), and a placeability pre-check in `rank()` so unplaceable anchors never reach the UI.
