# Task 02 — Query Construction Audit

Inputs: `01-pipeline-map.md`, `api_suggest()` L162–167. Verdicts: OK / RISK / UNKNOWN.

| # | Item | Verdict | Justification |
|---|---|---|---|
| 1 | Main query = title(200) + first 600 plain chars | **RISK** | The opening usually carries the topic, but 600 chars is a tiny slice of a full article; TOCs-at-top, long preambles, or intro fluff shift the query away from the body. Everything past 600 chars + 5 headings is invisible to retrieval. → test in Task 16 (1500-char variant) |
| 2 | Heading queries = title(160) + heading(400) | **RISK** | All heading queries share the identical title prefix, so they are correlated with the main query and with each other; short/low-information headings ("Introduction", "Conclusion") retrieve the same broad neighborhood. `array_slice($heads,0,5)` takes the first five **in document order**, not the five most informative. → test in Task 16 |
| 3 | Dedup = `array_unique` exact-match only | **RISK** | The first heading's words normally appear *inside* the first 600 plain chars (headings are part of the text flow), so main query and heading-query-1 are near-duplicates that survive dedup as distinct strings — wasting one of up to 6 search slots and double-weighting the same neighborhood. Frequency measured in Task 11. |
| 4 | No categories/tags in queries | **UNKNOWN** | Not necessarily wrong: categories belong in payload filters more than query text. But the index payload has no category/tag either, so no filter path exists today. Whether this causes misses needs Task 11/12 data. |
| 5 | No reader-intent signal anywhere | **RISK** (design gap vs product goal) | Queries are purely topical surface text; they cannot express "what the reader needs next / prerequisite / decision". This was the plugin's stated differentiator (see brainstorm + AI system prompt), so retrieval and AI judgment may diverge from the editorial goal. Not covered by Task 16 variants; assess impact via Task 12 failure classes and Task 14 reason quality; fix direction goes in REPORT. |

## Additional observation found during audit (cross-cutting)

**Query/index format asymmetry — RISK.** Indexed text (`profile()`) is `"Title: X\nHeadings: Y\nExcerpt: Z"` where `\n` is a **literal backslash-n** (single-quoted PHP) and each section carries a prefix label. Queries are bare concatenations (`title + " " + text600`) with no prefixes. Query and document representations therefore differ in both structure and literal tokens, which can measurably depress cosine similarity. Fix direction: consistent formatting both sides + real newlines. Note for Task 03/16; not directly testable in Task 16 as scoped (probe uses current query format).

## RISK items to carry forward

- Items 1, 2, 3, asymmetry → Task 16 (query variants) where scoped; items 3, 4, 5 also informed by Task 11 (stage metrics), Task 12 (failure classes), Task 14 (reason quality).

**DONE WHEN:** met — all five verdicts + 1 extra observation recorded.
