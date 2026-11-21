# Task 16 — Query-Variant Experiment

**Trigger met:** 14 `RETRIEVAL_MISS` ≥ 3 (Task 12). Affected posts: 410, 406, 402, 399, 394, 393, 381.
**Resequencing note:** executed before Tasks 14/15 (plan lists 16 as optional/last) so the final report can cite its results.

## Results (ideal links visible in top-30, per post)

| Post | Ideal | Original | **A: 1500-char main** | **B: heading+paragraph** |
|---|---|---|---|---|
| 410 | 5 | 2 | 2 | 2 |
| 406 | 5 | 2 | 2 | 2 |
| **402** | 5 | 2 | **5 ✅ (+3)** | 2 |
| 399 | 5 | 4 | 4 | 4 |
| 394 | 5 | 4 | 4 | 4 |
| 393 | 5 | 4 | 4 | 4 |
| 381 | 3 | 1 | 1 | 1 |
| **Affected total** | 33 | 19 | **22** | 19 |

Run files: `runs/probe-alt-A-<id>.json`, `runs/probe-alt-B-<id>.json` (14 batch searches, no inference).

## Findings

1. **Variant A (longer main query) fully recovers post 402's three misses** (403, 409, 407 — wait, recovered set = all three of its misses: 409, 401, 407). Its comparison content discusses both closed and open styles deep in the body; the first 600 chars never reach those discussions. Site-wide, A would move retrieval from 33/47 → 36/47 (+6.4pp) at zero extra cost (same query count).
2. **Variant B (heading + next paragraph) changes nothing anywhere** — heading neighborhoods are already saturated by the title prefix; adding the following paragraph doesn't shift the ranking beyond top-30 for the missed targets.
3. **The remaining 11 retrieval misses (posts 410, 406, 399, 394, 393, 381) are cluster-similarity cases**, fixable by neither variant: the missed targets are near-synonymous siblings whose question-phrased titles (e.g. 405 vs 409 vs 407) rank them below 30 for each other's queries. Levers that plausibly help, in Qdrant-guidance order: hybrid/sparse signals for keyword discrimination, section-level indexing (the missed posts' *relevant sections* vs the query), or reranking a deeper pool (deeper retrieval + existing AI stage) rather than better query text.
4. Practical recommendation for the report: adopt Variant A's 1500-char main query (cheap, proven on real data); treat B as rejected; treat deeper-pool + rerank as the next experiment for the 11 residual misses.

**DONE WHEN:** met — affected posts have before/after recall numbers; conclusions stated.
