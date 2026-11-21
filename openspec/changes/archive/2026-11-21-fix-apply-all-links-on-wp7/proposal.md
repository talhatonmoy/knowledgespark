# Proposal

## Why

On the live install (WordPress 7.1.5 in DevKinsta), **Apply All Links fails 100% for the site's standard content shape** — posts whose article body is a single `core/html` block: every suggestion is reported as "its anchor was not found in the current draft" even though the anchor text is verbatim in the draft. Root cause verified against the site's own block registry: WP 7.x block schemas store text in `attributes.content`, while `assets/admin.js` only reads `block.attributes.innerHTML` — dead for `core/html`, `core/paragraph`, `core/list-item`, and `core/freeform` in this install — so the client-side search always runs against an empty string.

Behind that dead accessor sit two latent defects, both proven against the real post 410 draft:

- The mirrored span matcher matches **inside** `<script type="application/ld+json">` schema markup (headline/FAQ text is a verbatim copy of body text and sits at the top of the block, so it wins document-order). Once the accessor bug is fixed, a "successful" apply would inject `<a>` into the JSON-LD and silently break structured data.
- Anchors whose only occurrences are inside **existing links** (the draft links those exact phrases to the production mirror domain `jarhabitats.com`, which `url_to_postid()` cannot see) are correctly rejected by the anti-nested-link guard but misreported as "not found".

This change restores Apply All Links on WP 7 html-block posts, hardens the matcher against schema-markup corruption, and makes skip reporting tell the truth.

## What Changes

- **Version-resilient block content resolution** (client apply path): a block's searchable text is read from the first string-valued content attribute among `innerHTML` → `value` → `content` (the documented historical names; `content` is what this install's 7.1.5 schema registers, verified in-container). Insertion is written back to the **same attribute key that was read**.
- **Protected markdown regions in the span matcher**: both engines (PHP `find_anchor_span()`, JS `findAnchorSpan()` — mirror must stay exact per KNOWN-ISSUES.md invariant #4) treat `<script>`, `<style>`, and HTML comment ranges as **opaque**: a span may never start, end, include, or bridge across such a range, and may never anchor on text inside a tag's attribute values.
- **Honest skip reporting**: every skipped suggestion names its cause from a fixed taxonomy (not present in the current draft / anchor already inside a link / anchor only in protected markup / invalid URL / target is not a post on this site / self-link). "Not found" is never used when the text was found but unplaceable. Both the PHP REST messages and the JS block-editor messages use the same taxonomy.
- **Regression vectors** added to both suites: inside-`<script>` span, span bridging a `<script>`, occurrence only in an `href` value, already-in-link vs not-present message distinction, and the attribute fallback chain (existing 10 vectors in each suite stay green).
- **KNOWN-ISSUES.md entry #6** documents the symptom, root causes, fix, invariants, and guards (this file is the project's long-term memory for production issues; the fix is verified against it before and after).

**Out of scope** (captured as a follow-up change after this one lands): cross-domain mirror awareness so the AI stops choosing already-linked production phrases as anchors (`url_to_postid()` limitation against `jarhabitats.com`); placeability pre-check at suggest time (clearing anchors that have no placeable span before they reach the UI); any change to the classic-editor REST path beyond the shared skip taxonomy; and all of suggestion retrieval, AI prompts/timeouts, indexing, and sync.

## Capabilities

### New Capabilities

- `link-placement`: Safe automatic anchor placement for Apply All Links — version-resilient block-content resolution, protected-region-aware anchor span resolution in the mirrored PHP/JS matchers, and honest per-cause skip reporting.

### Modified Capabilities

(none — this project currently has no specs; `openspec list --specs` is empty)

## Impact

- `assets/admin.js` — `applyViaBlockEditor()` attribute resolution; `findAnchorSpan()` protected-region masking; skip message strings. No bundler, no dependencies (plain-JS constraint).
- `includes/class-knowledgespark.php` — `find_anchor_span()` protected-region masking; `api_apply()` skip taxonomy. PHP 7.4-only syntax, dense style, no empty lines (KNOWN-ISSUES.md §5).
- `tests/smoke.php`, `audit/js-span-test.js` — new vectors; the suites remain the regression gate and must run fully offline with mocked hooks.
- `KNOWN-ISSUES.md` — new entry #6.
- No REST contract change (same request shape; `skipped[]` strings get richer), no new external calls, no database or schema changes, no site configuration changes.
- Verification: offline suites (`php -l`, `node --check`, `php tests/smoke.php`, `node audit/js-span-test.js`) plus one live check on a throwaway draft post with html-block content via the container (DevKinsta convention).
