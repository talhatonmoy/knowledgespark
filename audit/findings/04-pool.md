# Task 04 — Pool Aggregation Audit

Inputs: `01-pipeline-map.md`, `api_suggest()` L173–181.

## 1. How merging works (3 sentences)

Each of the up-to-6 queries returns its own top-30, and the loop walks every point of every result; the first sighting of a post creates its pool entry and later sightings only raise its score via `max()`, so a post's final score is its single best rank position across all queries. The pool is then sorted by that max score descending and sliced to the top 20, which is what the AI (or the semantic fallback) ever sees. Consequence: a post that ranks #2 in five different queries loses to a post that ranks #1 in just one query, and per-query rank depth below #1 is otherwise ignored.

## 2. Verdicts

**(a) Can one lucky heading query dominate the pool? — RISK.**
Yes, structurally. A short, generic heading query (e.g. "Setup | <title>") can return 30 near-identical broad matches, and every one of them enters the pool; if the main query's neighborhood overlaps heavily, the merged top-20 fills with that one neighborhood. There is no per-query quota, no rank-position weighting (e.g. reciprocal-rank fusion), and no requirement that a candidate appear in multiple queries. Magnitude depends on real data → measured in Task 11.

**(b) Any diversity control / is MMR needed? — RISK (pending Task 14 confirmation).**
None present: no MMR, no category spread, no dedup of near-identical targets. Qdrant guidance says use MMR only when redundancy is an actual problem — so the correct sequence is: measure first (Task 14 redundancy check across 10 runs), then adopt MMR (`diversity≈0.5`) only if ≥ a third of suggestion sets are marked REDUNDANT. Alternatively, "distinct reader needs" is already in the AI prompt, so AI mode may self-diversify while fallback mode cannot — worth checking per-mode in Task 14.

**(c) Is cutting to 20 before AI safe? — OK with caveat.**
For the AI stage it is coherent by construction: the AI only ever sees these 20, so no candidate the AI "could have picked" is hidden — the cut *is* the AI's universe. The caveat: the cut itself is unvalidated. If >5 of the ideal links (Task 08 ground truth) fall at pool-rank 21–30, the ceiling — not the AI — is the bottleneck; Qdrant guidance explicitly recommends checking the perfectly-ordered-pool ceiling before blaming ranking. Task 11 computes exactly this (`in_pool@20` vs `retrieved@30`).

## 3. Measurements assigned

- Task 11: `retrieved@30` vs `in_pool@20` gap (validates/invalidates 2c caveat); query-neighborhood overlap (tests 2a: share of pool entries contributed by only one query).
- Task 14: redundancy rate per suggestion set (decides MMR adoption per 2b); per-mode split (AI vs fallback).

**DONE WHEN:** met — 3-sentence explanation, all three verdicts, measurement list present.
