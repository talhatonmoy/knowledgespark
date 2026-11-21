# Task 05 — Exclusion Audit

Inputs: `01-pipeline-map.md`, `public_post()` L114, existing-link detection L165–167, pool loop L175–176, `sync()`/`remove()` L124–145.

## 1. Exclusion inventory (what is excluded, and where)

| Exclusion | Where enforced | Mechanism |
|---|---|---|
| Self post | Pool loop, query time | `$pid===$id` skip (L175) |
| Already linked in draft | Pool loop, query time | `$existing` map built from href regex + `url_to_postid()` (L165–167) |
| Non-`post` post types | Pool loop, per candidate | `public_post()` type check |
| Non-publish status | Pool loop, per candidate | `post_status==='publish'` |
| Password-protected | Pool loop, per candidate | `!$post->post_password` |
| Not publicly viewable (plugin/theme filters) | Pool loop, per candidate | `is_post_publicly_viewable()` |
| Deleted/unpublished **after indexing** (stale points) | Pool loop, per candidate | `get_post($pid)` re-checks live WP state before every suggestion |

Note: none of these are enforced at the Qdrant layer (no payload filters); all are WP-side post-filters. This is correct for safety but means retrieval "wastes" slots on excluded points — counted in Task 11 as invisible loss.

## 2. Staleness story

If a post is unpublished, password-protected, or deleted **after** being indexed, its point stays in the collection until a `save_post`/`before_delete_post` cron sync removes it (and WP-Cron may lag or not fire on low-traffic sites). Suggestions remain safe regardless: the per-candidate `get_post()` + `public_post()` check runs against live WordPress state on **every** suggest call, so stale points are dropped at query time. **Verdict: OK** — correctness does not depend on the index being fresh. Residual gap: the stale point still consumed one of the 30 retrieval slots per query (slot waste, not correctness).

## 3. URL forms the href→`url_to_postid()` detection may miss (test list for Task 13)

Detection path: regex captures quoted href → `html_entity_decode` → if starts with `/` (not `//`) prefix `home_url()` → `url_to_postid()`.

Variants to test:
1. Trailing slash vs canonical permalink (`/post-slug` vs `/post-slug/`)
2. `http://` vs `https://` scheme mismatch
3. `www.` vs bare domain
4. Query-string form: `/?p=123` (should work — canonical mapping) and `?p=123` unprefixed relative (regex captures `?p=123`; not starting with `/`, so passed unresolved → likely fails)
5. Site-relative `/post-slug/` (should work)
6. Protocol-relative `//example.com/post-slug/` (not prefixed, passed raw to `url_to_postid()` — likely fails)
7. Uppercase scheme/host `HTTPS://EXAMPLE.COM/...`
8. HTML-encoded ampersands/quotes (`&amp;` handled by decode; single-vs-double quote mix)
9. Anchor fragments `…/#section` and query args on permalinks (`?utm_source=x`)
10. Unquoted href attribute (`<a href=/post-slug/>`) — regex requires quotes entirely
11. Plain ID anchors / non-post URLs (pages, CPTs) — `url_to_postid` covers pages; CPT behavior noted
12. Feeds/attachment URLs linking a post indirectly

## 4. Performance note

Up to 6 queries × 30 results = up to 180 points, each triggering `get_post()` (cache miss → DB) plus `plain()`/`clip()` on first sighting. With persistent object cache absent (typical DevKinsta single site has one), worst case ~180 `get_post` lookups per suggest request — acceptable for an admin action but worth recording; a single `WP_Query(['post__in'=>…])` priming pass would cut it. **Verdict: OK** (admin-only, low frequency).

**DONE WHEN:** met — inventory with locations, staleness answer, Task-13 variant list, performance note present.
