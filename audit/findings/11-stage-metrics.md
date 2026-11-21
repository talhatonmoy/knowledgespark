# Task 11 — Stage Metrics (complete index: 541/541 points)

Data: fresh `runs/probe-*.json` + `runs/post-*.json` re-runs after full indexing (baseline 37-point data in earlier run files was overwritten per plan's re-run authorization; baseline numbers preserved in git-less notes below).

| POST | MODE | IDEAL retrieved@30 | in_pool@20 | final_hit@5 | MUSTNOT leaked |
|---|---|---|---|---|---|
| 410 | fallback | 2/5 | 2/5 | 1/5 | **1/2** (403) |
| 406 | fallback | 2/5 | 1/5 | 1/5 | 0/2 |
| 402 | fallback | 2/5 | 2/5 | 1/5 | 0/2 |
| 399 | fallback | 4/5 | 1/5 | 0/5 | 0/2 |
| 396 | fallback | 5/5 | 5/5 | 2/5 | **1/2** (388) |
| 392 | ai | 5/5 | 4/5 | 1/5 | 0/2 |
| 394 | fallback | 4/5 | 3/5 | 2/5 | 0/2 |
| 393 | fallback | 4/5 | 3/5 | 2/5 | 0/2 |
| 383 | fallback | 4/4 | 2/4 | 1/4 | 0/1 |
| 381 | ai | 1/3 | 1/3 | 0/3 | 0/1 |
| **TOTAL** | | **33/47 (70%)** | **24/47 (51%)** | **11/47 (23%)** | **2/18 (11%)** |

Slot efficiency across probes: 568 unique candidates / 1800 slots = **31.6%** (up from 17.5% on the 37-point collection — a full index makes queries diverge more, but ~2/3 of slots are still duplicates).

## Per-stage loss (the funnel)

```
47 ideal links (ground truth)
 ├─ 14 lost at RETRIEVAL (not in any query's top-30)      → 33 remain (70%)
 ├─  9 lost at POOL CUT (retrieved but outside top-20)    → 24 remain (51%)
 └─ 13 lost at RANKING (in pool, absent from final 5)     → 11 remain (23%)
```

## Interpretation (5 lines)

1. **No single stage owns the loss** — retrieval (14), pool cut (9), and ranking (13) each destroy roughly a third to a half of what survives the previous stage. Fixes must be staged, not single-point.
2. **Retrieval misses cluster in the self-sustaining cluster** (posts 400/401/405/407/409 missed repeatedly across 410/406/402/399) — the cluster's titles are question-phrased and near-synonymous, so title-prefix queries don't separate them; Task 16 tests whether longer/paragraph queries fix it.
3. **Pool cut at 20 is measurably too tight** for a 541-post collection: it discards 27% of what retrieval found. The baseline (37-post universe) never exposed this.
4. **Ranking drops are mode-dependent:** in 8 fallback runs, "ranking" is just max-score top-5 of 20 — a 13/24 loss there means score-merge ordering, not judgment. In the 2 AI runs, the AI dropped 4 pool-ranked ideal links — including the **#1-ranked candidate for post 392** (post 394, the step-by-step elimination guide) — evidence that excerpt-only AI judgment can reject exactly-right targets.
5. **Must-not leaks are exactly the flagged near-duplicates** (403 vs 410's opening-question theme; 388 vs 396's ID-post) — 2/18 (11%) leak rate confirms the Task 08 warnings were well-placed and that near-duplicate targets are not systematically suppressed.

Baseline (37-point collection) for contrast: retrieval 0/47, pool 0/47, final 0/47 — every miss was NOT_INDEXED. The mechanism's code path was correct throughout; coverage was the blocker.

**DONE WHEN:** met — table complete for all 10 posts with totals.
