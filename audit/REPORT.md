# KnowledgeSpark Link-Matching Audit — Final Report

Date: 2026-11-20 · Auditor pipeline: `audit/PLAN.md` (Tasks 00–16) · Evidence: `audit/findings/00–16`, `audit/runs/*`, `audit/eval/eval-set.json`

---

## 1. Executive summary

**Does the mechanism work?** The code pipeline works exactly as designed — every stage (query build → Qdrant batch retrieval → pool merge → exclusions → AI ranking → presentation) executed correctly across 20 live suggestion runs and 30+ probe searches, with zero hard failures and all safety contracts holding (invented IDs rejected, anchors verified as real substrings, unsafe URLs blocked, excluded posts never suggested).

**How well does it match?** On the 10-post evaluation set with editor-confirmed ground truth (47 ideal links, 18 must-nots) and a **complete index** (541/541 posts):

| Stage | Ideal links surviving | Rate |
|---|---|---|
| Retrieved (any query's top-30) | 33/47 | 70% |
| Pool (merged max-score top-20) | 24/47 | 51% |
| Final suggestions (top-5) | 11/47 | 23% |

**The single biggest problem found was not matching at all:** the production collection held only **37 of 541 posts** (an interrupted early indexing run). Every "bad suggestion" experience before this audit ran against that 7%-populated universe. Coverage is now fixed (541/541), and the audit's numbers are the first honest measurement of the mechanism.

**The second-biggest problem is AI reliability:** the AI ranking stage timed out at the 45 s HTTP limit on 40% (baseline runs) then 80% (final runs) of requests. AI-reviewed output is dramatically better than fallback output (10/10 specific reasons + verified anchors vs. boilerplate + redundant near-variants) — but it is currently a coin flip whether the editor receives it.

---

## 2. Ranked issues

### Issue 1 — AI ranking timeout makes the best feature intermittent
- **Evidence:** `09-runs-summary.md` (4/10 fallback: cURL 28 at 45 s), re-runs on full index (8/10 fallback); `06-ai-ranking.md` §3 predicted it (45 s shared by Qdrant and AI calls; 20 candidates × 3,500-char excerpts is heavy).
- **Impact:** High. Anchor/quote coverage (20/20 when AI runs), specific reasons, and set diversity all collapse in fallback mode (`14-quality-review.md`).
- **Fix direction:** raise the HTTP timeout for the AI call specifically (it is already bounded by the JS client's 120 s); trim candidate excerpts (3,500 → ~1,200 chars) to cut generation latency; consider sending the top 12–15 candidates instead of 20. Re-run the eval set after the change to verify the fallback rate drops below ~10%.
- **Effort:** S (settings/code) + one re-run of Tasks 09–14.

### Issue 2 — Fallback mode presents redundant, low-value sets as first-class suggestions
- **Evidence:** `14-quality-review.md` — 3/10 sets severely redundant (402 suggested three closed-vs-open competitors; 399 four longevity-myth variants; 394 three gnat-removal variants), all fallback; reasons are boilerplate 40/40; anchors empty 40/40.
- **Impact:** High (when fallback occurs — see Issue 1). Editors get interchangeable links to the article's own topic.
- **Fix direction:** (a) fix Issue 1 first; (b) in fallback mode, add a cheap near-variant guard (title-similarity suppression within the top-N, or Qdrant recommendation-API style dedup) so the top-5 can't be five siblings; (c) visually distinguish fallback output from AI-reviewed output beyond the current one-line message.
- **Effort:** M.

### Issue 3 — Pool cap of 20 discards a quarter of already-retrieved ideal links
- **Evidence:** `11-stage-metrics.md` funnel (33 retrieved → 24 pooled); `12-failure-classes.md` — 9 POOL_CUT misses at merged ranks 23–58 (five of nine at 23–40).
- **Impact:** Medium-High. Free recall left on the table independent of AI quality.
- **Fix direction:** raise the pool to 30–40 and spend the cost where it matters: deeper pool + slightly shorter excerpts to the AI, or apply MMR (Qdrant `diversity≈0.5`, per official guidance now that redundancy is measured) *before* the cut so the pool is both wider and more diverse. Verify with a re-run of the funnel metrics.
- **Effort:** S for the cap; M with MMR.

### Issue 4 — Retrieval misses cluster in near-synonymous title clusters
- **Evidence:** `12-failure-classes.md` — 14/36 misses are RETRIEVAL_MISS, concentrated in the self-sustaining cluster (400/401/405/407/409 missed repeatedly); `16-query-variants.md` — a 1500-char main query fully recovered post 402 (+3, zero cost) but the other 11 misses need different levers.
- **Impact:** Medium-High. These misses bypass everything downstream (never reach AI judgment).
- **Fix direction:** adopt the 1500-char main query immediately (proven, same request count). For the residual 11: hybrid/sparse signals for keyword discrimination, or section-level indexing so the *relevant section* of a sibling post can rank. Both are larger builds — do them only after Issues 1–3 land and metrics re-measured.
- **Effort:** S (1500 chars) → M/L (hybrid or sections).

### Issue 5 — Missing-collection error misleads editors
- **Evidence:** `03-retrieval.md` §4 — `api_suggest()` skips `ensure()`; a 404 renders as "Check credentials, model availability, collection settings, and quota" when the real fix is "run indexing".
- **Impact:** Medium (onboarding trap; this audit's own baseline state would have produced it).
- **Fix direction:** call `ensure()` in `api_suggest()` (cheap) or map 404 on this route to "Collection missing — run indexing in Settings".
- **Effort:** S.

### Issue 6 — Empty AI result renders a misleading empty state
- **Evidence:** `06-ai-ranking.md` §2c, `07-presentation.md` §2b — AI returning `[]` shows "AI-reviewed suggestions…" above an empty list.
- **Impact:** Low. Confusing moment, rare case.
- **Fix direction:** distinct empty-state copy: "AI reviewed the candidates and found no useful links."
- **Effort:** S.

### Issue 7 — Near-duplicate targets not systematically suppressed
- **Evidence:** `11-stage-metrics.md` — 2/18 must-not leaks, exactly the flagged near-duplicates (403, 388); `14-quality-review.md` redundancy findings.
- **Impact:** Low-Medium. Leaked links actively compete with the current article.
- **Fix direction:** payload tags (topic cluster / near-dup group) at indexing time + query-time payload filter; or negative-example steering via Qdrant's recommendation API.
- **Effort:** M.

### Issue 8 — Indexing reliability and throughput for a 541-post site
- **Evidence:** audit harness logs — 27 failed on pass 1, 18 on pass 2 (all transient DNS/TLS errors, all fixed on retry); browser-driven UI indexes 1 post per REST call (~500 sequential calls, ~1 hour, must stay open).
- **Impact:** Medium (operations). Incomplete indexes silently degrade suggestions — this audit's central discovery started here.
- **Fix direction:** batch server-side (10–25 posts per REST call, or a WP-CLI command) with per-post error tolerance and a completion summary; auto-retry transient cURL errors (6/28/60) once before recording failure.
- **Effort:** M.

### Issue 9 — Index/query representation asymmetry (latent quality drag)
- **Evidence:** `01-pipeline-map.md` noted fact + `02-query-construction.md` observation — indexed text contains literal `\n` sequences and `Title:/Headings:/Excerpt:` prefixes; queries are bare concatenations.
- **Impact:** Unknown-Medium (plausibly depresses similarity; not isolated by this audit's experiments).
- **Fix direction:** real newlines + identical structural format on both sides; requires full re-index (fingerprint change makes this automatic and resumable).
- **Effort:** S (code) + one re-index.

### Issue 10 — Marginal URL forms escape existing-link detection
- **Evidence:** `13-exclusion-edge-results.md` — uppercase-scheme/host URLs and unquoted href attributes are not excluded; all realistic editor forms on this site resolve.
- **Impact:** Low.
- **Fix direction:** case-normalize the host before `url_to_postid()`; relax the regex to allow unquoted attributes. Re-test if permalink structure ever changes to pretty permalinks.
- **Effort:** S.

**Verified-good (no action):** exclusion correctness incl. live staleness revalidation (`05`), fingerprint/legacy skip logic (499/499 skips, zero re-embedding cost, `10` §pass logs), injection defenses (`06`), safe DOM rendering and manual-placement promise (`07`), AI contract "do not force the count" end-to-end (`06`).

---

## 3. What we still don't know

1. **Retrieval ceiling** — what would 47/47 recall require? Next measurement: rerun the funnel with pool 40 + the 1500-char query, then inspect the residual misses' actual similarity ranks.
2. **AI timeout vs. excerpt size curve** — at which excerpt length does the 45 s budget stop binding? Next: rerun Tasks 09 with 1,200-char excerpts and count fallbacks.
3. **Cluster generalization** — 10 posts from 5 recent clusters; older clusters (history, glossary, decor…) are unmeasured. Next: a second eval batch drawn from IDs 8–380.
4. **Editor-grounded quality** — this audit's ground truth came from one reviewer's title-level judgment. Accept/reject telemetry (planned Stage 4) is the real metric.
5. **Representation asymmetry effect** (Issue 9) — needs an A/B re-index to quantify; skipped here to avoid double re-indexing.
6. **Post-publication value** — CTR on placed links, the metric that ultimately justifies the plugin, is unmeasurable until links are placed on the live site.

---

## 4. Recommended sequence

1. Ship the S-effort fixes: AI timeout + shorter excerpts (1), 1500-char query (4a), `ensure()` in suggest (5), empty-state copy (6), case-normalized host (10).
2. Re-run Tasks 09–14 once (harnesses are in place; ~15 min) to measure the delta.
3. Then decide between pool-widening/MMR (3), near-dup suppression (2b/7), and hybrid or section indexing (4b) using the fresh funnel numbers.
4. Batch indexing (8) before the next full re-index (which Issue 9 will eventually require anyway).
