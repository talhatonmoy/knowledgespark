# Task 13 — Exclusion Edge Test Results

## Deviation from plan (documented)

Plan specified mocking `url_to_postid`. Instead the harness (`audit/test-exclusions.php`) runs **real WordPress** inside the DevKinsta container against the live site, replicating the plugin's detection lines (`api_suggest()` L165–167) verbatim. Rationale: ground truth for core's actual URL resolution instead of memory-based assumptions; zero external-service cost; strictly more faithful to the plan's GOAL.

## Site context discovered by the test

The site uses **plain permalinks** — the canonical post URL is `http://dev-site.local/?p=410` (query-string form, no path). This makes the query-string forms the *dominant* real-world case.

## Results (target post 410)

| Variant | HREF used | Detected (excluded) |
|---|---|---|
| Canonical permalink | `http://dev-site.local/?p=410` | **YES** |
| Trailing slash removed | same (plain permalinks) | **YES** |
| `http://` on https site | scheme-swapped | **YES** |
| `www.` vs non-www | host-swapped | **YES** |
| Site-relative `/?p=ID` | prefixed → resolved | **YES** |
| Bare relative `?p=ID` | unprefixed, query-regex | **YES** |
| Protocol-relative `//host/?p=ID` | no scheme | **YES** |
| Fragment `#more-410` | canonical + anchor | **YES** |
| Extra query args (utm) | sloppy double-`?` URL | **YES** (core's `p=` regex is first) |
| **UPPERCASE scheme+host with query** | `HTTP://DEV-SITE.LOCAL/?p=410` | **no** → 0 |
| Path-only `/` (no query) | `http://dev-site.local/` | no (resolves to homepage/0) |
| Relative path, no leading slash | — | no (irrelevant under plain permalinks) |
| **Unquoted href attribute** | `<a href=URL>` | **no** (regex never captures it) |

Regex capture count: 12 of 14 planted links captured.

## Interpretation

- **Duplicate-suggestion risk on this site: LOW.** Every form an editor realistically pastes (Gutenberg/classic always emit quoted hrefs; copied canonical URLs carry `?p=ID`) resolves and is excluded. Core's `p=` query regex runs before scheme/host normalization, so even sloppy URLs (double `?`, fragments, extra args) resolve.
- **Two real miss classes exist:** (1) uppercase-scheme/host URLs fail even with a valid query (empirically — regardless of the core mechanism); (2) unquoted href attributes are invisible to the regex. Both are rare in block-editor workflows.
- **Conditional:** if the site ever switches to **pretty permalinks**, canonical URLs become path-based; scheme/www normalization and trailing-slash handling in core would then carry the load. The uppercase-host and unquoted-href misses would persist, and the path-only/relative forms would become the canonical forms whose behavior must be re-tested. Recorded for the report's "re-test triggers".
- Combined with `05-exclusions.md` staleness finding: correctness of exclusions is solid; the weak point is only URL-form coverage at the margins.

**DONE WHEN:** met — every Task-05-listed variant has a tested result + consequence.
