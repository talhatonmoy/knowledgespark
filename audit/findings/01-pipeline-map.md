# Task 01 — Pipeline Map (as-is)

Authoritative description of the suggestion pipeline as built today. All later tasks reference this file.
Source: `includes/class-knowledgespark.php` (v0.0.1.1) + `assets/admin.js`.

## 1. Pipeline stages, in order (function names)

| # | Stage | Where |
|---|---|---|
| 1 | Permission + post validation (must have `edit_post` cap; post must exist, `post_type === 'post'`) | `routes()` perm callback, `api_suggest()` L156–157 |
| 2 | Draft input: JS reads **unsaved** editor content (`currentPost()` → Gutenberg `getEditedPostContent()` / `getEditedPostAttribute('title')`, falls back to Classic `#title`/`#content`/TinyMCE) and POSTs `{post_id, title, content, count}`; PHP falls back to saved post fields if params absent | `admin.js currentPost()`, `api_suggest()` L158 |
| 3 | Size guards: raw content ≤ 250,000 bytes; plain text ≥ 40 chars, else WP_Error | L159–160 |
| 4 | Count clamp: default 5, min 1, max 10 | L161 |
| 5 | Query construction: 1 main query + up to 5 heading queries | L162–164 |
| 6 | Existing-link detection: regex over raw content hrefs → `url_to_postid()` → `$existing` map | L165–167 |
| 7 | Batch retrieval: `/points/query/batch`, one search per unique query, `limit: 30`, `with_payload: false`, Cloud Inference `text` query with configured model | L168–172, `qdrant()` |
| 8 | Pool aggregation: skip self + already-linked; revalidate each candidate against WP (`public_post()`); entry = {post_id, title, url, excerpt(plain content ≤3500), score}; score = **max** across queries | L173–180 |
| 9 | Pool cut: sort by score desc, keep top **20** | L181 |
| 10 | Empty pool → return `{suggestions: [], message}` | L182 |
| 11 | AI ranking (only if `ai_key` && `ai_model` set): `rank()`; success → return AI suggestions; failure → fall through with message "AI ranking unavailable; showing semantic matches. <error>" | L185–189 |
| 12 | Semantic fallback: top `count` of pool, generic reason, empty anchor/quote | L190 |
| 13 | `rank()` internals: candidates → one chat-completions call → JSON validation → ID whitelist → anchor/quote substring verification → clip/stop at count | `rank()` L193–215 |

Indexing side (what the collection contains — context for retrieval audits): `sync()` embeds `profile()` text = `Title: <plain title>` (clip 220) + `'\nHeadings: <headings joined ' | '>'` (clip 400) + `'\nExcerpt: <plain excerpt or content>'` (clip 500) via Cloud Inference on `PUT /points?wait=true`; payload = {post_id, title, url, modified, fingerprint}. Skip logic: sha256 fingerprint over [profile text, model, dimensions, permalink, modified_gmt]; legacy points (pre-fingerprint 0.0.1) skipped when modified+url match.

**Noted fact (flag for later tasks):** `profile()` uses single-quoted `'\n'` in PHP → the embedded text contains **literal backslash-n sequences**, not newlines (L118). Also `profile()` clips the *whole* `'Title: …'` string to 220 (including the prefix).

## 2. Constants table

| Constant | Value | Location |
|---|---|---|
| Draft content cap (raw, bytes) | 250,000 | L159 |
| Min plain draft text | 40 chars | L160 |
| Suggestion count | default 5, min 1, max 10 | L161, metabox input |
| Main query | title clip **200** + plain-text clip **600** | L162 |
| Heading queries | max **5** headings; title clip **160** + heading clip **400** | L163–164 |
| Query dedup | `array_unique` (exact string) | L170 |
| Search limit per query | 30 | L170 |
| Pool cut | top 20 | L181 |
| Candidate excerpt clip | 3,500 chars (plain `post_content`) | L177 |
| AI article text clip | 40,000 chars (plain draft text) | L198 |
| Anchor max length | 200 chars | L210 |
| Reason clip | 700 | L211 |
| Quote clip | 1,000 | L211 |
| AI temperature / response_format | 0.2 / `json_object` | L196 |
| HTTP timeout (PHP) | 45 s, no redirects | L87 |
| JS fetch timeout | suggest 120 s; test/index/reset 100 s | `admin.js request()` |
| Index batch size | 1 post per REST call (`LIMIT 1`) | L150 |
| `profile()` clips | title 220 (incl prefix), headings 400, excerpt 500 | L118 |
| Dimensions default / cap | 384 / 4096 | L18, L37 |

## 3. What the AI sees vs. never sees

**AI sees (one user message, JSON-encoded):**
- `maximum` (requested count)
- `article.title` — plain title (no clip beyond plain())
- `article.text` — plain draft text, first 40,000 chars
- per candidate: `post_id`, `title`, `excerpt` — leading 3,500 plain chars of `post_content`

**AI never sees:**
- Publish/modified dates, freshness of any kind
- Categories, tags, author
- Full target article text (only first 3,500 chars)
- Qdrant similarity scores
- Candidate permalink/URL
- Site structure, nav, existing-link context (only that linked posts were removed upstream)
- Candidate headings as structured data (only if they happen to appear inside the first 3,500 plain chars)
- Whether a candidate is a tutorial vs comparison etc. (must be inferred from excerpt)

## 4. Other structural facts later tasks need

- `api_suggest()` does **not** call `ensure()` — a missing/mismatched collection surfaces as a generic `ks_remote` HTTP-404-mapped error to the editor (relevant Task 03).
- Anchor/quote validation runs against `$text` = plain draft text of the **whole** draft (not the 40k AI clip); quotes are verified to contain the anchor.
- Non-string `anchor`/`quote`/`reason` (when set) causes the **whole candidate to be skipped** (L208), not just the field blanked.
- Existing-link regex only matches quoted href attributes (`"`, `'`); requires the regex `[\"\x27]([^\"\x27]+)[\"\x27]` — unquoted attributes and edge URL forms are candidates for Task 13.
- JS never uses `innerHTML` with returned data; links restricted to http/https via `safeUrl()`.

**DONE WHEN:** met — stage list, constants table, and AI-visible/invisible lists present.
