# Task 09 — Live Runs Summary

Harness: `audit/run-suggest.php`, executed via `docker exec devkinsta_fpm php …` (see `00-environment.md` / `08-eval-set.md` adaptation notes). Saved-content mode (no unsaved content param), count 5. Ran once, 2026-11-20.

## Outcome per eval post

| Post | Mode | # Suggestions | Suggested IDs (in order) |
|---|---|---|---|
| 410 | ai | 5 | 32, 21, 31, 17, 16 |
| 406 | ai-failed-fallback | 5 | 21, 17, 30, 16, 10 |
| 402 | ai-failed-fallback | 5 | 21, 13, 39, 8, 10 |
| 399 | ai-failed-fallback | 5 | 21, 39, 19, 10, 30 |
| 396 | ai-failed-fallback | 5 | 30, 16, 8, 18, 33 |
| 392 | ai-failed-fallback | 5 | 16, 33, 30, 40, 10 |
| 394 | ai | 5 | 17, 22, 16, 19, 39 |
| 393 | ai | 5 | 32, 19, 16, 18, 22 |
| 383 | ai | 5 | 17, 27, 32, 19, 16 |
| 381 | ai-failed-fallback | 5 | 23, 39, 38, 44, 10 |

## Key observations

1. **No hard failures.** Zero WP_Error results; Qdrant retrieval succeeded on all 10 runs (all returned 5 suggestions).
2. **AI timeouts on 4/10 runs (40%).** All four fallbacks share the same cause: `cURL error 28: Operation timed out after …` — the 45 s PHP HTTP timeout expires before the AI finishes ranking 20 candidates (3,500-char excerpts each). This empirically confirms the gap predicted in `06-ai-ranking.md` §3. The fallback is graceful (5 semantic suggestions returned), but AI value is inconsistent run-to-run — the same click can be AI-reviewed or not.
3. **Suggestions skew to old posts.** Most suggested IDs (8, 10, 13, 16–23, 27, 30–33, 38–44) are **outside** the recent 381–410 window. For 383 (moss revival), all 5 suggestions are old posts — none of the recent moss-cluster posts (384–387) appear, though 383's ideal links are all in that cluster. Whether this is retrieval miss, pool cut, or legitimate older-but-relevant posts is resolved by Task 10/11 probe data.
4. Run files at `audit/runs/post-<id>.json` include full suggestion payloads (reason/anchor/quote per suggestion) for Task 14.

**DONE WHEN:** met — 10 run files exist; summary lists mode, counts, errors.
