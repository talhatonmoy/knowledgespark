# Task 10 — Probe Summary

Harness: `audit/probe-retrieval.php` (exact replication of `api_suggest()` query construction, limit 30/query, `with_payload: false`), run inside the DevKinsta container. Post 396 needed one retry after a transient `cURL error 60` (SSL subject-name mismatch; 9/10 identical calls succeeded — intermittent, not systematic).

## Per-post probe results

| Post | Queries | Unique candidates (of ≤180 slots) | Ideal links in any top-30 |
|---|---|---|---|
| 410 | 6 | 33 | **0 / 5** |
| 406 | 6 | 33 | **0 / 5** |
| 402 | 6 | 30 | **0 / 5** |
| 399 | 6 | 34 | **0 / 5** |
| 396 | 6 | 31 | **0 / 5** |
| 392 | 6 | 31 | **0 / 5** |
| 394 | 6 | 31 | **0 / 5** |
| 393 | 6 | 31 | **0 / 5** |
| 383 | 6 | 30 | **0 / 4** |
| 381 | 6 | 31 | **0 / 3** |

## Finding 1 — Root cause of zero recall: the ideal targets are not in the collection

Collection state at probe time: **37 points, status green**. Site has **541 published posts**. Direct point-ID check: **0 of the 30 recent-window posts (381–410) are indexed**. A scroll of all 37 points shows they are exactly **posts 8–44** (the oldest posts, foundational guides — "What Is a Terrarium?", glossary, history…), all payload `legacy` (pre-fingerprint 0.0.1 indexing).

Conclusion: an early indexing run was interrupted after 37 posts and never completed. **The 0/47 ideal-link recall is fully explained by `NOT_INDEXED`** — the targets do not exist in the search universe. This is *not* a retrieval-quality measurement.

## Finding 2 — Extreme query redundancy (structurally confirmed)

6 queries × 30 limit = 180 slots, yet unique candidates are only **30–34 per post** — the six near-duplicate queries (Task 02 §2–3) return **overlapping neighborhoods at ~85–95% overlap**. The batch call effectively spends 6× the cost for ~1 query's worth of diversity. Confirms Task 02 RISK verdicts 2 and 3 with data.

## Finding 3 — The pipeline behaved correctly within its (tiny) universe

All 10 live runs returned 5 suggestions drawn from the only 37 indexed posts. The suggested IDs in `09-runs-summary.md` all fall in 8–44 — the exact indexed set. Filtering, exclusion, merging, and fallback logic all executed as designed.

## Consequence for Tasks 11–16

- Task 11/12 will classify essentially **all misses as `NOT_INDEXED`**; retrieval/pool/ranking quality over the real corpus stays **UNKNOWN** until indexing covers the site.
- Task 16's trigger (≥3 `RETRIEVAL_MISS`) is not met on this data and should be re-evaluated after a complete index.
- A fair end-to-end measurement requires completing the index (≈504 remaining posts; the 37 legacy points will be skipped by the legacy-match rule at zero inference cost).

**DONE WHEN:** met — 10 probe files (396 after one documented retry), queries + candidate counts + ideal-presence recorded.
