# Task 06 — AI Ranking Audit

Inputs: `01-pipeline-map.md`, `rank()` L193–215, `api_suggest()` L185–191.

## 1. Effective contract (what the AI must return, and every validation rule)

**Required JSON:** `{"suggestions":[{"post_id":123,"reason":"…","anchor":"…","quote":"…"}]}` — enforced via `response_format: json_object` (L196) + decode check (L202).

| Rule | Line | Effect |
|---|---|---|
| Decode must produce object with array `suggestions` | L202 | Else WP_Error → semantic fallback with error text |
| `post_id` must exist in the pool (`$lookup`) | L206 | Whitelist; invented IDs dropped |
| Duplicate IDs dropped (`$seen`) | L206 | One suggestion per target |
| `anchor`/`quote`/`reason`, when present, must be strings | L208 | **Whole candidate skipped** if a present field is a non-string |
| Anchor non-empty, ≤200 chars, must be substring of full plain draft text | L210 | Else anchor+quote blanked (suggestion kept) |
| Quote non-empty, substring of plain draft text, and must contain anchor | L210 | Else both blanked |
| Reason clipped to 700; quote to 1000; sanitized (`sanitize_text_field`) | L211 | Output hygiene |
| Upper bound only: `break` at `count` | L212–213 | Never pads; stops early |

## 2. Verdicts

**(a) Does the AI have enough context to judge "reader usefulness"? — RISK (known, bounded).**
It sees the article (40k chars) plus 20 candidates reduced to titles + first 3,500 plain chars each. It cannot see full targets, dates, categories, or structure (Task 01 §3). Judgments are therefore excerpt-based plausibility, not verified usefulness — acceptable for an MVP reviewer that requires editor confirmation, but the UI's "AI-reviewed" phrasing (L187) should not imply deeper verification than occurred. Fix direction: surface excerpt-basis in the UI wording; later add dates/categories to candidate payloads.

**(b) Is "do not force the requested count" preserved end-to-end? — OK.**
Instruction present in system prompt (L197); PHP enforces only an upper bound and never pads (L212–213); an empty AI list returns an empty suggestions array rather than falling back to semantic filler. End-to-end preserved.

**(c) Is the fallback message honest when AI fails? — OK, with one honesty gap.**
On AI error: `"AI ranking unavailable; showing semantic matches. <error>"` (L188) — honest and actionable. **Gap found:** when the AI *successfully returns an empty list*, the response is `{suggestions: [], message: "AI-reviewed suggestions. Verify the target and placement before linking."}` — the UI renders an empty state whose message reads as if suggestions exist. Minor misleading empty-state (UI-facing; noted for Task 07/15).

**(d) Does failure of one field discard useful info? — OK.**
No: failed anchor/quote verification blanks only those two fields and **keeps** the suggestion + its reason (L210–211). Only type-invalid fields (non-string) discard the whole item (L208) — a defensible strictness, though skipping for a non-string `reason` while the ID/title are fine is slightly aggressive. Severity: negligible.

## 3. Prompt-injection defenses present, and gaps

**Present:** untrusted-data framing in system prompt (L197); JSON-only output mode; ID whitelist against the pool; anchor/quote must be verbatim substrings of the draft (fabricated placement text cannot survive); `sanitize_text_field` on all AI strings; length clips (700/1000/200).

**Gaps (all minor for an editor-only tool):**
1. **No `max_tokens` cap on the AI call** (L196 payload) — response length bounded only by provider defaults; a runaway response is truncated by the provider or times out at the 45 s PHP timeout → semantic fallback (safe outcome, but silent).
2. **Input size is user-model-dependent:** ~40k article + 20×3.5k excerpts ≈ 110k chars (~27k tokens) is fine for gpt-4o-mini-class models but may silently truncate on smaller configured models; excerpt quality degrades without error.
3. **45 s PHP timeout shared by Qdrant and AI calls** — heavy AI ranking can exceed it; failure mode is graceful (fallback), but a longer timeout for `rank()` specifically would reduce spurious fallbacks.

**DONE WHEN:** met — contract with citations, all four verdicts, injection defense list present.
