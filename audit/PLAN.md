# KnowledgeSpark — Link Matching Audit Plan

Audits the suggestion pipeline end to end: query construction → Qdrant retrieval → pool aggregation → exclusions → AI ranking → presentation.

Built for a fast, small-context model (GLM 5.3 Flash) to execute **one task at a time, in strict order**.

---

## Execution rules for the executor model

1. Do tasks in numbered order. Never skip ahead. One task = one work session.
2. Before each task, read ONLY the files listed in that task's **READ** section, plus the previous task's output file. Do not re-read the whole repo.
3. After each task, write the **WRITE** file, then stop. The next task starts fresh from that file.
4. Never edit plugin files. Create new files only under `audit/`.
5. If blocked, write what you tried in the task's output file under `## BLOCKED`, and stop to ask the user.
6. Live runs (Tasks 09–10) call your configured Qdrant Cloud and possibly the AI provider. Run each ONCE. Keep to the 10-post eval set to respect free-tier quotas.
7. Use `php -l <file>` after writing any PHP file, and re-run `php tests/smoke.php` at the end of any task that touched a harness file — it must stay green.
8. If a task says "verdict", use exactly: `OK` (no issue), `RISK` (evidence of a problem), `UNKNOWN` (needs run data).

**Handoff template** — for each task, give the model this prompt:
> Execute Task NN from audit/PLAN.md. Read the task, read its READ files, do the steps, write the WRITE file, stop.

---

## Task dependency map

| Phase | Tasks | Needs |
|---|---|---|
| 0. Orientation | 00, 01 | nothing |
| 1. Static code audit | 02–07 | 01 (pipeline map) |
| 2. Ground truth + harnesses | 08–10 | 01, user input on 08 |
| 3. Measured audit | 11–14 | 09, 10 outputs |
| 4. Synthesis | 15 | all |

---

## Task 00 — Environment check

**GOAL:** Confirm tooling and create the audit workspace.
**READ:** none.
**DO:**
1. Run `php -v` and `node -v`.
2. Create folders: `audit/findings/`, `audit/runs/`, `audit/eval/`.
3. Confirm WordPress is present: find `wp-load.php` by walking up parent directories from the plugin folder; also read the WP version from that site root's `wp-includes/version.php` (`$wp_version`).
**WRITE:** `audit/findings/00-environment.md` — PHP version, Node version, WP version, absolute path of the site root, whether `wp-load.php` was found.
**DONE WHEN:** the file exists and states all five facts.

## Task 01 — Pipeline map (as-is)

**GOAL:** Produce the authoritative description of how suggestions are built today. Every later task depends on this file.
**READ:** `includes/class-knowledgespark.php` (whole file, focus on `api_suggest`, `rank`, `profile`, `public_post`, `qdrant`), `assets/admin.js` (functions `currentPost`, `request`, `renderSuggestions`).
**DO:**
1. List every pipeline stage in order with its function name.
2. Extract ALL numeric constants into one table, e.g.: main query = title(200)+text(600); heading queries = title(160)+heading(400), max 5 headings; search limit 30/query; pool cut 20; excerpt 3500 chars; suggestion count default 5, max 10; min draft text 40 chars; draft size cap 250,000 bytes; AI article clip 40,000 chars; reason clip 700; anchor max 200 chars; quote clip 1000; AI temperature 0.2.
3. Note exactly which data the AI sees (article plain text, candidate titles + leading excerpts) and which it never sees (publish dates, categories/tags, full target text).
**WRITE:** `audit/findings/01-pipeline-map.md`.
**DONE WHEN:** the file contains the ordered stage list, the constants table, and the AI-visible/invisible data lists.

## Task 02 — Query construction audit

**GOAL:** Judge whether the text sent to Qdrant is a good representation of the article.
**READ:** `audit/findings/01-pipeline-map.md`, the query-building lines of `api_suggest` in `includes/class-knowledgespark.php`.
**DO:** Give a verdict + one-sentence justification for each:
1. Main query uses title + first 600 chars — is the opening reliably representative (intros vs. fluff/cookie banners/TOCs)?
2. Heading queries are `title(160) + heading(400)` — are short headings plus the SAME title near-duplicates of each other and of the main query?
3. Dedup is `array_unique` (exact string match only) — how often would near-identical queries survive?
4. No categories/tags are used in any query.
5. No reader-intent signal is used anywhere.
**WRITE:** `audit/findings/02-query-construction.md` — one line per item: verdict, justification, and for each `RISK` a note "test in Task 16".
**DONE WHEN:** all five verdicts recorded.

## Task 03 — Retrieval call audit

**GOAL:** Verify the Qdrant search call is correct and compare against official guidance.
**READ:** `audit/findings/01-pipeline-map.md`, `qdrant()` and the batch-search lines of `api_suggest`.
**DO:**
1. Confirm the batch endpoint body is `{"searches":[{"query":{"text":...,"model":...},"limit":30,"with_payload":false}, ...]}` and that parsing expects `result` → array → `points`. Flag any mismatch.
2. Load the qdrant-advisor skill (or fetch https://skills.qdrant.tech/qdrant-search-quality/search-strategies/SKILL.md) and note what Qdrant recommends about: candidate pool depth before reranking, redundant results (MMR/diversity), and hybrid/keyword signals — then mark which of those this pipeline uses or omits.
3. Check consistency: search uses Cloud Inference `text` queries; indexed points embed the compact `profile()` text — confirm both use the same configured model.
4. Note that `api_suggest` does not call `ensure()` — state what error the editor sees if the collection is missing.
**WRITE:** `audit/findings/03-retrieval.md`.
**DONE WHEN:** steps 1–4 each have a verdict or factual answer.

## Task 04 — Pool aggregation audit

**GOAL:** Judge the max-score-merge and top-20 cut.
**READ:** `audit/findings/01-pipeline-map.md`, the pool-building lines of `api_suggest`.
**DO:**
1. Explain in 3 sentences how scores merge across queries (max per post) and how the sort + slice(0,20) works.
2. Verdicts: (a) can one lucky heading query dominate the pool? (b) is there any diversity control (MMR) — per Qdrant guidance is it needed here? (c) is cutting at 20 before AI safe given AI only sees these 20?
3. Record which of these should be measured in Task 11/14.
**WRITE:** `audit/findings/04-pool.md`.
**DONE WHEN:** both verdicts + measurement list present.

## Task 05 — Exclusion audit

**GOAL:** Verify excluded/allowed targets and compile edge cases to test later.
**READ:** `audit/findings/01-pipeline-map.md`, `public_post()`, the `$existing` link-detection lines of `api_suggest`, `sync()`/`remove()` for staleness behavior.
**DO:**
1. Confirm what is excluded: self post; posts already linked in the draft; non-public targets (status ≠ publish, password, not publicly viewable) — and WHERE each check happens (query time vs. `get_post()` per candidate).
2. Explain the staleness story: if a post is unpublished after indexing, is it still suggested? (check the per-candidate `get_post` revalidation).
3. List URL forms the href→`url_to_postid` detection may miss, for testing in Task 13: trailing slash, `http://` vs `https://`, `www.` vs bare domain, query strings (`?p=123`), site-relative (`/post-slug/`), protocol-relative (`//`), uppercase, HTML-encoded.
4. Note performance: up to 6 queries × 30 candidates of `get_post()` calls per suggestion request.
**WRITE:** `audit/findings/05-exclusions.md`.
**DONE WHEN:** exclusion inventory, staleness answer, and the Task-13 URL variant list are present.

## Task 06 — AI ranking audit

**GOAL:** Audit the AI stage: prompt, validation, fallback.
**READ:** `audit/findings/01-pipeline-map.md`, the whole `rank()` method.
**DO:**
1. Reproduce the effective contract: what JSON the AI must return, and every validation rule applied to it (ID must be in pool; dedup; anchor must be substring of draft text; quote must be substring; quote must contain anchor; else anchor/quote blanked). Confirm each rule in the code and cite the line.
2. Verdicts: (a) does the AI have enough context to judge "reader usefulness" (it sees 3,500-char excerpts and no dates/categories)? (b) is the "do not force the count" instruction preserved end-to-end? (c) is the fallback message honest when AI fails? (d) does failure of one field (anchor) discard useful info (reason) — is that right?
3. List prompt-injection defenses present (untrusted-data framing, JSON-only output, ID whitelist) and any gaps.
**WRITE:** `audit/findings/06-ai-ranking.md`.
**DONE WHEN:** rule list has code citations and all four verdicts given.

## Task 07 — Presentation audit

**GOAL:** Verify the editor-facing UI matches the data honestly.
**READ:** `assets/admin.js` (`renderSuggestions`, `currentPost`), `assets/admin.css`, the metabox `box()` output in `includes/class-knowledgespark.php`.
**DO:**
1. Confirm: safe DOM rendering (no `innerHTML` with returned data), manual-placement wording, Copy URL with clipboard fallback.
2. Verdicts: (a) when `anchor` is empty the UI shows "Choose relevant text in your draft." — could an editor mistake this for a suggestion? (b) is the semantic-fallback message ("Reader usefulness has not been assessed by AI") shown in the right cases? (c) does the metabox (now `normal`/`high` placement) render correctly per `box_order()`?
3. Note anything the editor cannot see that would help review (e.g., publish date, category, score).
**WRITE:** `audit/findings/07-presentation.md`.
**DONE WHEN:** confirmation + three verdicts recorded.

## Task 08 — Evaluation set (needs the user)

**GOAL:** Build a small ground-truth set to measure against.
**READ:** `audit/findings/01-pipeline-map.md`.
**DO:**
1. Write `audit/eval/list-posts.php` that boots WordPress and prints ID, title, type, status, date, word count for 30 recent published posts (template below).
2. Run it, then pick **10 posts** spanning: long vs short, tutorial vs comparison vs opinion, old vs new.
3. For each, decide the ideal internal links (up to 5) a good editor would add, based on the post's title/excerpt; also name up to 2 "must NOT suggest" posts.
4. **Ask the user to confirm or correct the ideal links** before proceeding — this is the only task requiring user input.
**WRITE:** `audit/eval/eval-set.json` as `[{ "post_id": N, "why": "...", "ideal": [{"post_id": M, "reason": "..."}], "must_not": [{"post_id": K, "reason": "..."}] }]` plus `audit/findings/08-eval-set.md` summarizing coverage.
**DONE WHEN:** eval-set.json validates as JSON, has 10 entries, and the user has confirmed it.

WordPress boot template (walk up until `wp-load.php` is found):
```php
<?php
$d = __DIR__;
while ($d !== '/' && !file_exists($d . '/wp-load.php')) { $d = dirname($d); }
require $d . '/wp-load.php';
foreach (get_posts(['numberposts' => 30, 'post_type' => 'post', 'post_status' => 'publish']) as $p) {
    printf("%d | %s | %s | %d words\n", $p->ID, $p->post_title, $p->post_date, str_word_count(wp_strip_all_tags($p->post_content)));
}
```

## Task 09 — Live-run harness

**GOAL:** Capture what the plugin actually suggests, per eval post.
**READ:** `audit/findings/01-pipeline-map.md`, `audit/eval/eval-set.json`, `api_suggest` signature in `includes/class-knowledgespark.php`.
**DO:**
1. Write `audit/run-suggest.php` (template below): boots WP, calls `KnowledgeSpark::api_suggest()` directly for each eval post with its SAVED content (no AI key in settings? then output will be semantic-only — record which mode ran by checking the returned `message`), saves each response to `audit/runs/post-<id>.json`.
2. Run it once. If any response is a `WP_Error`, save `{"error": message}` instead and continue.
3. `php -l` the harness, then `php tests/smoke.php` (must pass — the harness does not touch plugin code).
**WRITE:** the run files + `audit/findings/09-runs-summary.md` (mode per run, suggestion counts, any errors).
**DONE WHEN:** 10 `post-<id>.json` files exist and the summary lists their outcome.

```php
<?php
$d = __DIR__;
while ($d !== '/' && !file_exists($d . '/wp-load.php')) { $d = dirname($d); }
require $d . '/wp-load.php';
$set = json_decode(file_get_contents(__DIR__ . '/eval/eval-set.json'), true);
foreach ($set as $entry) {
    $req = new WP_REST_Request('POST');
    $req->set_param('post_id', $entry['post_id']);
    $req->set_param('count', 5);
    $out = KnowledgeSpark::api_suggest($req);
    file_put_contents(__DIR__ . '/runs/post-' . $entry['post_id'] . '.json',
        wp_json_encode(is_wp_error($out) ? ['error' => $out->get_error_message()] : $out, JSON_PRETTY_PRINT));
    echo $entry['post_id'] . " done\n";
}
```
Note: if AI is configured this calls your AI provider once per eval post — 10 calls total.

## Task 10 — Retrieval probe harness

**GOAL:** See the pre-AI stage: exactly which candidates Qdrant returns.
**READ:** `audit/findings/01-pipeline-map.md`, `audit/eval/eval-set.json`, the query-construction lines of `api_suggest`.
**DO:**
1. Write `audit/probe-retrieval.php`: boots WP, gets the post, replicates the query construction EXACTLY as mapped in Task 01 (same clips/limits), reads Qdrant settings via `KnowledgeSpark::settings()`, and calls the batch endpoint directly with limit 30 per query (use `wp_safe_remote_request` with the `api-key` header, like `qdrant()` does).
2. Save per post: each query string, and all returned point IDs + scores, to `audit/runs/probe-<id>.json`.
3. Run once for all 10 eval posts.
**WRITE:** the probe files + `audit/findings/10-probe-summary.md` (queries per post, unique candidate count, whether any ideal links appear in top-30).
**DONE WHEN:** 10 probe files exist, each with ≥1 query and a points array (or a documented error).

## Task 11 — Stage metrics

**GOAL:** Quantify where ideal links are lost.
**READ:** `audit/eval/eval-set.json`, all `audit/runs/probe-*.json`, all `audit/runs/post-*.json`.
**DO:** Build one table, one row per eval post:
- `retrieved@30`: how many of its ideal links appear in ANY probe query's top-30;
- `in_pool@20`: how many ideal links survive a simulated max-score merge + top-20 cut (recompute the merge from probe data exactly as Task 01 describes);
- `final_hit@5`: how many ideal links are in the live suggestions (Task 09);
- `must_not_leaked`: how many must-not posts were suggested.
Compute totals row. State per-stage loss: ideal links lost at retrieval, lost at pool cut, lost at ranking.
**WRITE:** `audit/findings/11-stage-metrics.md` with the table and a 5-line interpretation.
**DONE WHEN:** the table is complete for all 10 posts with totals.

## Task 12 — Failure classification

**GOAL:** Name the mechanism's failure mode for every missed ideal link.
**READ:** `audit/findings/11-stage-metrics.md`, `audit/eval/eval-set.json`, the run files for posts with misses.
**DO:** For each missed ideal link, assign exactly ONE primary class:
- `NOT_INDEXED` — target absent from all probe results and not in the collection;
- `RETRIEVAL_MISS` — indexed but outside every top-30;
- `POOL_CUT` — retrieved but outside the simulated top-20;
- `AI_REJECT` — inside pool but absent from final AI suggestions;
- `EXCLUDED` — draft already links it, or excluded by a status rule;
- `FALLBACK_MODE` — AI not configured, semantic top-5 simply outranked it.
Give a one-line evidence note per miss.
**WRITE:** `audit/findings/12-failure-classes.md` — class counts + one line per miss.
**DONE WHEN:** every missed ideal link from Task 11 has a class and evidence.

## Task 13 — Exclusion edge tests

**GOAL:** Empirically test the link-detection gaps listed in Task 05.
**READ:** `audit/findings/05-exclusions.md`, the `$existing` detection lines of `api_suggest`.
**DO:**
1. Write `audit/test-exclusions.php` in the style of `tests/smoke.php` (mock `url_to_postid`, no network): feed a draft containing links in each variant form from Task 05 (trailing slash, http, www, `?p=123`, site-relative, protocol-relative, encoded) and record which the code detects.
2. Run it. Do not modify the plugin.
**WRITE:** `audit/findings/13-exclusion-edge-results.md` — variant → detected yes/no table + consequence (duplicate suggestion risk).
**DONE WHEN:** every variant from the Task 05 list has a tested result.

## Task 14 — Redundancy and reason quality

**GOAL:** Judge suggestion-set diversity and explanation usefulness.
**READ:** all `audit/runs/post-*.json`, `audit/findings/01-pipeline-map.md`.
**DO:**
1. Redundancy: for each run, eyeball/compare the 5 titles (and excerpts where available) — mark a set `REDUNDANT` if ≥3 suggestions look like near-variants of the same topic; note how often that happens across 10 runs.
2. Anchors: count suggestions with a non-empty `anchor`/`quote` vs blanked — blank rate per run.
3. Reasons: extract all `reason` strings; mark each `SPECIFIC` (names a reader need/section) or `GENERIC` (could apply to any post); give the split.
**WRITE:** `audit/findings/14-quality-review.md`.
**DONE WHEN:** redundancy rate, anchor blank rate, and specific/generic split are all stated.

## Task 15 — Final report (synthesis)

**GOAL:** Turn all findings into one ranked, evidence-backed report. No code changes.
**READ:** every file in `audit/findings/` (00–14).
**DO:**
1. Executive summary: does the mechanism work? Answer with the Task 11 numbers.
2. Ranked issue list — for each: title, evidence (finding file + run data), impact (High/Med/Low), fix direction (one paragraph, no code), effort (S/M/L). Include at minimum the verdicts marked `RISK` in Tasks 02–07 and everything measured in Tasks 11–14.
3. "What we still don't know" section with concrete next measurements.
**WRITE:** `audit/REPORT.md`.
**DONE WHEN:** report has summary, ≥5 ranked issues with evidence, and an unknowns section; then tell the user it is ready.

## Task 16 — (Optional) Query-variant experiment

**GOAL:** Test whether better queries would have recovered retrieval misses.
**READ:** `audit/findings/12-failure-classes.md`, `audit/findings/10-probe-summary.md`.
**DO:** Only if Task 12 shows ≥3 `RETRIEVAL_MISS` cases: for those posts, extend `audit/probe-retrieval.php` with two alternative query builds — (a) first 1500 chars instead of 600, (b) heading + its next paragraph (parse from saved content) — rerun JUST those posts, save as `audit/runs/probe-alt-<id>.json`, and report whether ideal links move into top-30.
**WRITE:** `audit/findings/16-query-variants.md`.
**DONE WHEN:** the affected posts have before/after recall numbers, or the file states the experiment was skipped because the trigger condition was not met.
