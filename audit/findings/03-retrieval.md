# Task 03 — Retrieval Call Audit

Inputs: `01-pipeline-map.md`, `qdrant()` L95–99, batch-search lines L168–172, `ensure()` L100–109. External guidance fetched fresh: [qdrant-search-strategies SKILL.md](https://skills.qdrant.tech/qdrant-search-quality/search-strategies/SKILL.md).

## 1. Batch call shape and parsing

Code sends `POST /points/query/batch` with body `{"searches": [{"query": {"text": "...", "model": "<embedding>"}, "limit": 30, "with_payload": false}, ...]}` and parses `result` as an array of per-search results, each with `points[]`, reading `point['id']` and `point['score']`.

- **Verdict: OK (shape)** — this matches the documented Cloud-Inference batch query format (query object with `text` + `model` under each search entry, results returned in `result` array order).
- **Parsing hardening note (RISK-minor):** `(int)$point['id']` silently casts; Qdrant point IDs were created as ints (`(int)$id` at sync), so this holds for this plugin's data. If a point id were ever a UUID, `(int)` would yield 0 and the candidate would map to `get_post(0)` → null → dropped. Not triggerable with current indexing; noted for robustness only.

## 2. Comparison against Qdrant's official guidance

| Guidance point | This pipeline | Status |
|---|---|---|
| Candidate pool depth before reranking ("right docs exist but rank low" → retrieve wider, then rerank; score ceiling check) | Retrieves 30/query × up to 6 queries, merges, cuts to 20, AI sees 20 | **Partially aligned** — pool of 20 for AI judgment is reasonable; but the *retrieval→pool* funnel (30 per query) is unmeasured; Task 11 will quantify loss at each cut |
| Redundant results → MMR (`diversity=0.5` start), only when redundancy is an actual problem | No MMR; max-score merge actively *keeps* duplicates that co-occur across queries | **Omitted** — Task 14 will measure whether redundancy is "an actual problem" here |
| Missing keyword matches → hybrid search | Dense-only queries; no sparse/BM25 vector on points | **Omitted** — deferred by design (guidance's What-NOT-to-do: don't add hybrid before verifying pure vector quality — this audit is exactly that verification) |
| Verify retriever/model fit first; exact-search sanity | Not done; retrieval quality assumed | **UNKNOWN** → Tasks 10–11 measure it |
| Evaluate end-to-end when changing ranking (Pipeline Output Quality) | No evaluation harness existed pre-audit | **Being fulfilled by this audit** (Tasks 08–14) |
| Business logic (recency) → score boosting | No recency/freshness signal anywhere | **Omitted** — noted; candidates' `modified` exists in payload but is unused at query time |

## 3. Search/index model consistency

- Index upsert (`sync()` L142): vector `{text: profile(), model: $s['embedding']}`.
- Query (`api_suggest()` L170): `{text: query, model: $s['embedding']}`.
- Both read the same saved setting at call time. **Verdict: OK** — same model on both sides.
- **Asymmetry carried from Task 02:** same model, but document text and query text use different formats (prefixed/`\n`-labeled profile vs. bare concatenation). Model consistency ≠ representation consistency; flagged as RISK in `02-query-construction.md`.

## 4. Missing `ensure()` in `api_suggest()`

`api_test()` and `api_index()` call `ensure()` (creates collection on 404, validates dimensions). `api_suggest()` does **not**. If the collection is missing or was reset:

1. `qdrant('POST','/points/query/batch',…)` → Qdrant returns HTTP 404.
2. `request()` maps any non-2xx to `WP_Error('ks_remote', 'Remote service returned HTTP 404. Check credentials, model availability, collection settings, and quota.')`.
3. Editor sees exactly that generic message.

**Verdict: RISK (UX)** — the message says "check credentials/quota" when the actual cause is "collection missing; run indexing." Misleading guidance at the exact moment a new user hits it. Fix direction: call `ensure()` first (or map 404 on this route to "run indexing"). Low severity, cheap fix.

**DONE WHEN:** met — all four steps answered.
