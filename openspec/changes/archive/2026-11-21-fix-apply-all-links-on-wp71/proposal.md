# Proposal

## Why

On the live site (WordPress 7.1.5), "Apply All Links" still fails for html-block posts. On post 409 (body = one `core/html` block) all five suggestions were skipped with the generic sentence "its anchor is not in the current draft", even though every anchor is visibly present in the article. Live exploration proved the cause:

1. **WP 7.1 changed raw-block data layout.** `core/html`'s `content` attribute is deprecated since 7.1: this build's parser only reads source-less attributes from the block comment (empty for `<!-- wp:html -->`), and the block's own editor component strips any legacy `content` attribute into `innerContent` on load. The article lives only in the block's inner content (`innerContent`/`innerHTML`), not in any attribute. The shipped block-editor apply path reads only `block.attributes` (chain `innerHTML`/`value`/`content`), so the matcher never sees the article and every suggestion falls to the generic skip sentence. No write occurs — the failure is honest but misdirecting, and the post is untouched.
2. **Latent entity gap.** Suggest-time validation checks anchors against entity-decoded text, but both span matchers compare character-for-character against raw HTML, where `&` is stored as `&amp;`. Post 409's draft carries `&amp;` in four headings ("Balance Light, Water &amp; Plants"), so any AI anchor containing `&` would keep failing the same sentence even after fix 1.

## What Changes

- **Editor apply, read side**: for raw blocks (`core/html`, `core/freeform`) resolve the searchable content from the block's inner content (canonical `wp.blocks.getBlockContent` when available, else `innerHTML`, else `innerContent` join); keep the existing attribute chain as the compatibility path for classic text blocks and older WordPress.
- **Editor apply, write side**: for raw blocks write the result back through the same inner-content flow the core Custom HTML block uses in its own editor (update the block's `innerContent` and resync its inner blocks), falling back to a full-block `replaceBlock` when modern dispatchers are absent. Classic text blocks keep today's same-attribute write-back.
- **Entity-aware anchor matching, both engines**: the JS span matcher and the PHP `find_anchor_span` treat the standard HTML entity forms (`amp`, `lt`, `gt`, `quot`, apostrophe; decimal and hex numeric forms) of a character as that character, and no-break-space entities join like plain whitespace. A resolved span is always the raw slice from the draft, so entities in the saved document are preserved, never rewritten. The PHP/JS mirror invariant stays intact.
- **Admin script version string** bumped for cache busting (0.0.1.3 → 0.0.1.4).
- **Guard suite extended** (not replaced): the JS span test gains WP 7.1-style raw-block fixtures and entity vectors; the PHP smoke suite gains matching vectors. KNOWN-ISSUES.md gains entry #7 and its run-guards header is updated.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `link-placement`: the "Block content resolution for editor apply" requirement changes (raw blocks must be read from and written back to inner content, with the attribute chain kept only as compatibility fallback), and a new "Entity-aware anchor matching" requirement is added (entity forms match literal characters in both engines, raw slices are preserved).

## Impact

- `assets/admin.js`: raw-block read/write logic in the apply path, entity tokens in the span matcher, version string.
- `includes/class-knowledgespark.php`: entity tokens in `find_anchor_span` (1-space indent, PHP 7.4 only).
- `tests/smoke.php`: new PHP matcher vectors (count grows, existing assertions preserved).
- `audit/js-span-test.js`: new fixtures/vectors (the only audit file this change may touch; all other audit artifacts stay untouched).
- `KNOWN-ISSUES.md`: entry #7 plus run-guards header update.
- No build step, no new dependencies, no REST contract change, no database change, no changed suggest/index behavior.

## Out of Scope

- No change to the suggest/rank pipeline (Qdrant queries, AI prompts; suggest-time validation continues to use decoded plain text).
- No Gutenberg architecture migration (no raw-editor content editing, no store rewrites beyond the minimal `updateBlock`/`replaceInnerBlocks` dispatchers), and no "invoke the save button" redesign — the block path already/frontend-saves via `savePost`, and this change keeps that.
- No content migration or rewriting of saved articles (existing `&amp;` entities in stored HTML stay untouched).
- No WordPress version gating: compatibility comes from the fallback chain, not `is_wp_version` branches (6.4+ must keep working).
- The remaining KNOWN-ISSUES #6 follow-ups (cross-domain mirror awareness, placeability pre-check) stay out of scope for a separate future change.
