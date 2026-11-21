# Tasks

## 1. Backups

- [x] 1.1 Back up `assets/admin.js`, `includes/class-knowledgespark.php`, `tests/smoke.php`, `audit/js-span-test.js`, and `KNOWN-ISSUES.md` to `audit/backups/fix-apply-all-links-on-wp71/<timestamp>/` (flat directory), record checksums for each original and verify the copies by comparison before touching code

## 2. Entity-aware anchor matching (both engines)

- [x] 2.1 In `assets/admin.js` `findAnchorSpan` only: add per-character entity-form alternatives for `&`, `<`, `>`, `"`, and `'` (named `amp`/`lt`/`gt`/`quot`/`apos`, decimal numeric with leading zeros, lowercase hex) listed before the literal, and extend the joiner to `(?:\s|&nbsp;|&#0*160;|&#x0*a0;|<[^>]+>)*`; the returned span stays the raw match slice and no guard logic changes
- [x] 2.2 In `includes/class-knowledgespark.php` `find_anchor_span` only (pattern build at the `implode('(?:\s|<[^>]+>)*', …)` line): add the identical token strings and joiner, PHP 7.4 syntax, 1-space indent, no blank lines; byte-verify the edited region with a `python3` repr dump before saving
- [x] 2.3 Confirm the two engines tokenize character-by-identical aligned descriptions (same forms, same order) — the PHP/JS mirror invariant (KNOWN-ISSUES #4) must hold after this task

## 3. Raw-block read/write in the editor apply path

- [x] 3.1 In `assets/admin.js` `blockEditorApi()`: additionally expose `updateBlock` and `replaceInnerBlocks` from the `core/block-editor` dispatch, each guarded by a `typeof === 'function'` check (null when absent)
- [x] 3.2 In `applyViaBlockEditor()`: for `core/html` and `core/freeform` blocks only, resolve the searchable string by the D1 chain — existing attribute chain `innerHTML`/`value`/`content` first, then `window.wp.blocks.getBlockContent(block)` when available and non-empty, then `block.innerHTML`, then `block.innerContent` joined; classic text blocks keep today's attribute-chain behavior unchanged
- [x] 3.3 In `applyViaBlockEditor()`: when the raw-block channel was used, write the splice back through the D2 mirror — re-parse `<!-- wp:{b.name} -->\n{c1}\n<!-- /wp:{b.name} -->` via `window.wp.blocks.parse`, then `updateBlock(blockId, { innerContent: parsed.innerContent ?? [c1] })` plus `replaceInnerBlocks(blockId, parsed.innerBlocks ?? [], false)` when both dispatchers exist; otherwise `replaceBlock(blockId, {…block, innerContent: [c1]})` with the attributes object copied untouched; on stores without `innerContent` support keep today's same-attribute write-back; the `<a>` wrap markup (href + title) is unchanged

## 4. Cache busting

- [x] 4.1 Bump both `wp_enqueue_script`/`wp_enqueue_style` version strings `0.0.1.3` → `0.0.1.4` in `includes/class-knowledgespark.php` (the two enqueue lines), 1-space indent, byte-verified

## 5. Guard suites (extend, never replace)

- [x] 5.1 Extend `audit/js-span-test.js` only (no other audit artifacts touched): add a WP 7.1-style `core/html` fixture with empty attributes and the article in inner content (assert locate, exact wrapped markup with `title`, write-back via the inner-content channel, no `content` attribute created, JSON-LD script region byte-identical), a legacy attribute fixture preserving same-key write-back, and entity vectors (`Water & Plants` over `Water &amp; Plants`, nbsp join, entity-only-in-attribute stays skipped); keep all existing checks passing
- [x] 5.2 Extend `tests/smoke.php`: add the PHP mirror vectors — ampersand anchor over `&amp;` raw text, nbsp join, entity occurrence only inside an attribute value is skipped, and after insertion the entity bytes at the matched position are byte-preserved; keep all existing checks passing

## 6. Live verification (DevKinsta container)

- [x] 6.1 On a throwaway **draft** post shaped like post 409 (single `core/html` block: JSON-LD scripts first, `&amp;` headings, body phrases matching sibling posts), run the real block-editor apply path in-container and a REST apply over the same document; assert links inserted, `&amp;` bytes unchanged, JSON-LD region byte-identical, save persisted; delete the throwaway post afterwards and leave no probe artifacts in `audit/`

## 7. Long-term memory upkeep

- [x] 7.1 Update `KNOWN-ISSUES.md`: add entry #7 "Apply All Links — WP 7.1 raw-block (core/html) data layout + entity gap" with symptom, root cause (source-less `content` attribute is comment-only; deprecated since 7.1; article lives in inner content; matcher was attribute-only; entity gap latent), the fix, invariants (attribute chain stays the legacy fallback — never a single hard-coded source; inner-content write mirrors the core block's own update flow; entity token sets stay identical between engines; hex matches lowercase only as a documented corner), and the new guard counts; update the run-guards header (49/35 → new totals); mark in entry #6 that the raw-layout and entity classes are owned by #7 while keeping #6's cross-domain follow-up open

## 8. Project checks

- [x] 8.1 `php -l includes/class-knowledgespark.php`
- [x] 8.2 `node --check assets/admin.js`
- [x] 8.3 `php tests/smoke.php` — all checks green, including the new vectors
- [x] 8.4 `node audit/js-span-test.js` — all checks green, including the new fixtures and vectors
