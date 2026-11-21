# Task 12 — Failure Classification

Every missed ideal link from Task 11 (36 misses = 47 − 11 final hits), one primary class each. Post-indexing verification confirmed all targets are indexed, so `NOT_INDEXED` is impossible here (baseline's dominant class dropped to zero after full indexing).

Class definitions: `RETRIEVAL_MISS` = indexed but outside every query's top-30. `POOL_CUT` = retrieved, outside simulated top-20 (with merged max-score rank shown). `RANK_DROP` = inside pool (rank shown), absent from final 5 (fallback = score top-5; ai = AI selection).

## Per-miss classification

| Post (mode) | RETRIEVAL_MISS | POOL_CUT | RANK_DROP |
|---|---|---|---|
| 410 (fallback) | 400, 405, 398 | — | 409 (pool #8) |
| 406 (fallback) | 405, 409, 400 | 403 (merged #58) | — |
| 402 (fallback) | 409, 401, 407 | — | 410 (pool #19) |
| 399 (fallback) | 409 | 400 (#24), 410 (#40), 407 (#27) | 403 (pool #12) |
| 396 (fallback) | — | — | 392 (pool #9), 393 (pool #15), 389 (pool #6) |
| 392 (ai) | — | 390 (#39) | 396 (pool #6), **394 (pool #1)**, 393 (pool #14) |
| 394 (fallback) | 398 | 390 (#37) | 393 (pool #12) |
| 393 (fallback) | 398 | 396 (#26) | 389 (pool #16) |
| 383 (fallback) | — | 386 (#45), 387 (#23) | 385 (pool #7) |
| 381 (ai) | 397, 407 | — | 382 (pool #18) |

## Class counts

| Class | Count | Share of 36 misses |
|---|---|---|
| `RETRIEVAL_MISS` | **14** | 39% |
| `POOL_CUT` | **9** | 25% |
| `RANK_DROP` | **13** | 36% |

## Evidence notes (one line per miss-group)

- **RETRIEVAL_MISS (14):** all in the self-sustaining/balance cluster (400, 401, 405, 407, 409, 398, 397) whose titles are question-phrased and synonymous ("How to Make… Self-Sustaining" vs "From High-Maintenance to Self-Sufficient"); title+600-char queries never surface them. Trigger for Task 16 met (≥3).
- **POOL_CUT (9):** merged-rank 23–58 — the top-20 cap discards them even though 5 of 9 sit at merged-rank 23–40, i.e. one deeper pool (30–40) would capture most.
- **RANK_DROP (13):** 9 of 13 are fallback top-5 losses (max-score ordering favors one neighborhood; e.g. 396's pool holds 5/5 ideal yet only 2 reach final). The 4 AI-mode drops include **post 394 at pool rank #1 for post 392** — the highest-scored, ground-truth-correct candidate rejected by the AI (excerpt-only judgment; 392 is a panic-response post and 394 is the systematic fix — a false rejection worth flagging in the report).

**DONE WHEN:** met — every miss classified with evidence; counts total 36.
