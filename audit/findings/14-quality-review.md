# Task 14 — Redundancy and Reason Quality (complete-index runs)

Method note: the bucket heuristic in `audit/quality.php` over-flagged (10/10 "REDUNDANT") because single-niche titles share keywords ('jar') by definition. Final judgment below is from manual inspection of all 50 suggested titles (`post-*.json`) plus the 10 AI-mode reasons read in full.

## 1. Redundancy (manual, per suggestion set)

| Verdict | Runs | Sets |
|---|---|---|
| **Severely redundant** (≥3 near-variants of the article's own topic) | 402 (177/330/446 = three closed-vs-open competitors), 399 (441/542/449/444 = four longevity-myth variants), 394 (166/540/424 = three gnat-removal variants) | **3/10** |
| Borderline (2–3 topical variants or one cluster-dominated) | 396 (2 removal variants + must-not leak 388), 392 (3 trap-focused) | 2/10 |
| Well-diversified | 410, 406, 393, 383, 381 | 5/10 |

**All 3 severe cases are fallback mode** — max-score top-5 collapses onto one neighborhood when the query's own cluster is dense. The 2 AI-mode sets spread across distinct reader needs. Confirms Qdrant-guidance sequencing: redundancy is now measured as an actual problem (fallback mode), so MMR or a diversity step is justified there.

## 2. Anchor / quote coverage

- Overall: **10/50 (20%)** — 100% in the 2 AI-mode runs (20/20 suggestions), 0% in all 8 fallback runs (by design; fallback returns empty anchor/quote).
- Every AI-proposed anchor/quote survived verification (all were real substrings; none blanked) — the substring contract works when the AI succeeds.
- **Consequence:** with AI timeouts at 80% in the current runs, anchor coverage — the plugin's strongest placement feature — collapses with them. AI reliability is the lever, not the anchor logic.

## 3. Reason specificity (manual grade)

- **AI mode: 10/10 specific.** Reasons name the exact gap in the source article (e.g. "The quarantine step tells readers to place a sticky trap at the jar opening but gives no build or placement details…", "Secondhand and vintage buyers need to know whether a jar with rim chips is safe to reuse…"). Verified by direct read of all 10.
- **Fallback mode: 0/40 specific (by design)** — all 40 carry the identical boilerplate string ("Related to the article or one of its sections. Reader usefulness has not been assessed by AI."). Honest labeling, zero editorial value.

## 4. Carry-forward to report

1. Fallback sets need a redundancy guard (near-variant suppression) and should never look equivalent to AI-reviewed output (severe sets 402/399/394 present three interchangeable links to the article's own topic — actively unhelpful for editors).
2. Anchor/quote quality in AI mode is production-grade; the failure mode is availability (timeouts), not quality.
3. The quality script's keyword-bucket redundancy test is not usable on single-niche sites; manual/semantic judgment (or the AI itself as a diversity judge) is required.

**DONE WHEN:** met — redundancy rate, anchor blank rate, and specificity split stated with method notes.
