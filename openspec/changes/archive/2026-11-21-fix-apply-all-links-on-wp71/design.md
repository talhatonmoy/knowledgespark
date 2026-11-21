# Design

## Context

See proposal.md for the motivation. The facts that shape this design, all verified in this build (WordPress 7.1.5, DevKinsta container + shipped `wp-includes/js/dist` bundles):

- `core/html` declares `attributes.content` with **no `source`** field; this build's spec parser (`packages/blocks` `getBlockAttribute`) resolves a source-less attribute **only from the block comment's attributes** — and a saved `<!-- wp:html -->` block comment carries none. So parsed blocks have no string attributes at all for the article.
- `wp-includes/js/dist/block-library.js` (this build) deprecates "The content attribute on the Custom HTML block" **since 7.1** ("alternative: inner content") and the block's own editor component strips a legacy `content` attribute into `innerContent` on load. `save()` returns `null`.
- The canonical read API `wp.blocks.getBlockContent(block)` (exported; implementation `getBlockInnerHTML`) has an explicit `core/html` branch: it returns `serializeInnerContent(block.innerContent, block.innerBlocks)` — i.e. **inner content is the article**.
- Serialization of a saved post goes through the same function (`serializeBlock` → `getBlockInnerHTML`), so writing inner content round-trips into the saved post.
- The core block's own modal updates the block by: re-parse `<!-- wp:html -->${next}<!-- /wp:html -->`, then `updateBlock(clientId, { innerContent: parsed.innerContent ?? [next] })` and, when the re-parsed inner block tree differs, `replaceInnerBlocks(clientId, parsed.innerBlocks, false)`. Both dispatch actions exist on the `core/block-editor` store.
- KNOWN-ISSUES.md (long-term memory, read 2026-11-21) invariant #6: "The ordered attribute chain plus same-key write-back is the cross-version contract. Never reintroduce a single hard-coded attribute name." Invariant #4: the PHP/JS matcher mirror — every guard change goes into **both** files and both suites; the join pattern `(?:\s|<[^>]+>)*` stays. This design extends, not replaces, both invariants (see Decisions D1 and D3).
- Code constraints: the class file is PHP 7.4, 1-space indent, no blank lines, byte-verified edits; `admin.js` is plain vanilla JS, 4-space; no build step, no dependencies; audit/ is protected — only `audit/js-span-test.js` may be extended.

## Goals / Non-Goals

**Goals:**
- Apply All Links works on WP 7.1+ raw-block posts (the site's standard content shape) through the frontend JS path, writing back through the same channel core itself uses, and still saves via the existing `savePost()` flow.
- Anchors containing `&` (or any standard entity) resolve in both engines against entity-encoded drafts, with byte-preserved raw slices.
- Zero regression on classic text blocks, legacy WP attribute layouts, the protected-markup guards, the skip-sentence taxonomy, and the exact-markup `title` attribute.

**Non-Goals:**
- No suggest/rank pipeline change, no REST contract change, no content migration of stored posts.
- No WP version gating (`is_wp_version` branches) — compatibility comes from guarded fallbacks.
- No redesign of the apply/save UX (it already runs in frontend JS and calls `savePost()`; the user's directional preference is already the behavior).
- The KNOWN-ISSUES #6 open follow-ups (cross-domain mirror awareness, placeability pre-check) remain separate.

## Decisions

**D1 — Raw-block read channel: attribute first, then canonical inner content.**
For `core/html` and `core/freeform` only, resolve the searchable string as: (1) the existing ordered attribute chain `innerHTML` → `value` → `content` when it yields a non-empty string (legacy/older WP, and pre-7.1 saved blocks opened in 7.1 before the core effect strips them); (2) otherwise `wp.blocks.getBlockContent(block)` when available and non-empty (the 7.1+ canonical article); (3) otherwise `block.innerHTML` if it is a non-empty string; (4) otherwise `block.innerContent` joined, if it is an array of strings. Classic text blocks keep today's attribute-chain-only behavior unchanged.
- *Why attribute-first on legacy:* a pre-7.1 block's text lives in the attribute; the 7.1 core effect only copies it (never rewrites), so both channels agree when both exist. In 7.1+ the chain yields nothing and the inner content wins. No hard-coded single name is ever the sole source — the invariant holds.
- *Alternatives:* read `innerHTML` field first (rejected: on older WP the field and the attribute can differ in whitespace/serialization, and the attribute is the schema-registered source there); always `getBlockContent` (rejected: may not exist on older WP, and for legacy blocks it recomputes save output).

**D2 — Raw-block write: mirror the core modal exactly, with a replaceBlock fallback.**
Let `c0` be the string read in D1 and `c1` the spliced result (existing `<a href="…" title="…">…</a>` wrap). If `window.wp.blocks.parse`, and the dispatchers `updateBlock` + `replaceInnerBlocks` are all available: re-parse `<!-- wp:{b.name} -->\n{c1}\n<!-- /wp:{b.name} -->`; take `parsed.innerContent ?? [c1]` and `parsed.innerBlocks ?? []`; dispatch `updateBlock(blockId, { innerContent })` and `replaceInnerBlocks(blockId, innerBlocks, false)` — the same shape the core Custom HTML modal uses. Otherwise fall back to `replaceBlock(blockId, {…block, innerContent: [c1]})` (attributes object copied untouched); on very old stores lacking `innerContent` support, keep today's same-attribute write-back. Then `savePost()` as today.
- *Why re-parse instead of direct innerContent splicing:* `getBlockContent` output can differ from a plain `innerContent.join('')` (joining/inner-block interpolation), so splice offsets must not be mapped between the two; re-parsing the full string is core's own proven flow and sidesteps the mapping entirely.
- *Alternatives:* `replaceBlock` with mutated `attributes.content` (rejected: 7.1 strips the attribute on load and serializes inner content — the write would churn through comment→strip→move round trips or, on a store that keeps it, disagree with the editor preview); raw `getEditedPostContent`/`editPost` (rejected: heavier, bypasses block semantics, not what core does).

**D3 — Entity forms as per-character alternatives; entity-extended joiner; guards unchanged.**
The per-anchor-character pattern token becomes `(?:<entity forms>|<literal>)` for the five escapable characters (`&`, `<`, `>`, `"`, `'`): named forms (`&amp;`, `&lt;`, `&gt;`, `&quot;`, `&apos;`), decimal numeric with leading zeros (`&#0*38;` etc.), and lowercase hex numeric (`&#x0*26;` etc.). The joiner becomes `(?:\s|&nbsp;|&#0*160;|&#x0*a0;|<[^>]+>)*` (nbsp joins like whitespace). Entity forms are listed before the literal (literal `&` is a prefix of `&amp;`; backtracking would make it work either way, ordered preference is cleaner). The resolved `span` is still the raw `m[0]` slice, so document entities are preserved untouched. All existing guards (in-tag at either end, block-close in slice, `<a>` overlap and open-count, nesting balance, protected ranges) run on the raw slice exactly as today — entity matching can only make previously-unmatchable text match; it cannot bypass a guard.
- *Why per-character alternatives (vs. decoding the document):* decoding the draft would change offsets, break the raw-slice write-back, risk rewriting stored entities, and desync the two engines' string handling; per-character alternatives keep one raw string in both engines and the insert is a pure slice splice.
- *Mirror:* the identical token string and joiner are built in PHP (`find_anchor_span`, PHP 7.4-safe string building, 1-space indent, byte-verified splice) and JS (`findAnchorSpan`), per invariant #4. Documented constraint: hex entities are matched in lowercase only (WP output is decimal; uppercase hex is a known-unsupported corner).

**D4 — Version strings 0.0.1.3 → 0.0.1.4** on both the script and style `wp_enqueue_*` calls (class file line 74–75) so browsers pick up the changed JS.

**D5 — Guard suite extension (never replacement).**
`audit/js-span-test.js`: add (a) a 7.1-style raw-block fixture — `core/html` with empty attributes and the article in `innerContent`/`innerHTML` — asserting locate + wrap + write-back through the inner-content channel (and that no `content` attribute is created); (b) a legacy-attribute fixture preserving today's same-key write-back; (c) entity vectors for the shipped matcher (`Water & Plants` over `Water &amp; Plants`, nbsp join, entity-in-attribute stays in_tag); (d) keep every existing vector green. `tests/smoke.php`: add the PHP mirror vectors (entity ampersand/nbsp, in-attribute entity, byte-preservation assertions) and keep all 49 existing checks. KNOWN-ISSUES.md gains entry #7 (WP 7.1 raw-block data layout + entity gap, with invariants and the run-guards update); the run-guards header counts are updated to the new totals.

## Risks / Trade-offs

- [Fallback `replaceBlock` path leaves stale `innerBlocks` on ancient stores] → the modal-mirror path is preferred whenever its three pieces exist; apply always reloads the editor after save, which re-parses the saved content.
- [`getBlockContent` absent or returning empty on an intermediate WP] → guarded `typeof` checks and the D1 chain steps 1/3/4 cover it; the no-readable-content case degrades to today's honest skip, never an error.
- [Entity tokens could mask a guard] → tokens exist only inside the per-character alternatives and the joiner; every guard still runs on the raw slice. Rejected cases stay rejected (covered by mirror vectors). An entity-encoded `&lt;/p&gt;` text inside a candidate region can trip the block-close guard — a false-reject, which errs safe (skip, never corrupt).
- [Re-parse in D2 could normalize whitespace outside the span] → the re-parsed block's inner content is the exact string we feed (raw blocks are opaque to the parser); the js-span harness asserts the final inner-content bytes equal the spliced string.
- [7.1 core effect strips `content` while we write inner content] → no conflict: we never touch `attributes.content` on raw blocks, so the effect stays idle.
- [One-sided mirror change desyncs engines (invariant #4)] → D3 mandates both files + both suites in the same change; tasks sequence enforces the double green before the version bump.

## Migration Plan

1. Pre-edit backups of `assets/admin.js`, `includes/class-knowledgespark.php`, `tests/smoke.php`, `audit/js-span-test.js`, `KNOWN-ISSUES.md` into `audit/backups/fix-apply-all-links-on-wp71/<timestamp>/` with checksums (audit dir is otherwise untouched).
2. Apply code changes per tasks; run the guard gate (`php -l`, `node --check`, both suites) to green.
3. Live verification in the DevKinsta container on a **throwaway draft post** shaped like post 409 (single `core/html` block, JSON-LD scripts, `&amp;` headings): apply via the real block-editor path, assert insertion, entity bytes preserved, JSON-LD byte-identical, save persisted; delete the throwaway post after.
4. User re-runs Apply All Links on post 409 (the original failing case); confirm 5/5 or honest per-cause skips.
5. Rollback: restore the five backed-up files (they are the plugin's rollback; the plugin dir is not a git repo). No database or content migration exists to roll back.

## Open Questions

(none — the remaining known gaps are tracked as the KNOWN-ISSUES #6 open follow-up and are out of scope here.)
