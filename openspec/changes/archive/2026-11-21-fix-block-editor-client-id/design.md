# Design

## Context

See proposal.md for the failure story. The design rests on facts verified read-only on 2026-11-21 against the shipped WP 7.1.5 bundles and the live database:

- The real block-editor store addresses every block by `clientId`: its own tree-update code reads `block.clientId` (block-editor.js L8048–8059), and `getBlock(state, clientId)` is keyed by it (L14237).
- `updateBlock(clientId, updates)` is a plain action-object factory (L16316–16322): dispatching it with an `undefined` id cannot throw.
- The `UPDATE_BLOCK` reducer guards unknown ids — `if (!state.has(action.clientId)) return state;` (L8458–8461) — a silent no-op. `REPLACE_INNER_BLOCKS` resolves the root's order for the given id the same way (L9924+).
- The shipped apply path calls `block.blockId` at four sites (admin.js L316, L317, L321, L334) — `undefined` in every real editor session.
- The stubbed harness invented `blockId` (js-span-test.js L103, L158–160, L175, L192) and stayed self-consistent across two changes — all checks green while real-editor writes were dead.
- DB forensics: post 409 saved 2026-11-21 04:51:40 UTC byte-identical to its pre-run content (md5 `4abb4eff…`); post 405 saved 05:02:45 UTC identical to revision 560 — both "successful" runs persisted zero links.

**KNOWN-ISSUES invariant preservation** (memory read before this design): #4 (PHP/JS matcher mirror) — untouched, no matcher change in this change; #6 (attribute-chain contract, skip taxonomy) — the reader channel and the four-cause skip sentences stay exactly as shipped; #7 (raw-block layout, entity tokens) — the wp71 read/write flow is kept, only the write addressing and placement verification are added on top; entry #7's false live-confirmation bullet is corrected and the new class moves to entry #8. Code constraints unchanged: class file PHP 7.4, 1-space indent; admin.js vanilla JS, no build step; audit/ protected except `js-span-test.js`.

## Goals / Non-Goals

**Goals:**
- Links actually persist when Apply All Links reports success; a silent no-op becomes a loud, honest refusal.
- The test harness mirrors the real store contract so this failure class cannot pass tests again.
- The long-term memory records the true story (false positive corrected, new invariants added).

**Non-Goals:**
- No suggest/rank/REST changes; no matcher or reader changes; no UI redesign beyond the refused sentence family.
- No automatic re-application of the links missed on posts 409/405 — the user re-runs Apply All Links after this ships, and verification proves persistence in the DB.

## Decisions

**D1 — `clientId` only, no `blockId` fallback.** `blockId` never existed in any real store version; a compatibility fallback would re-entrench the fiction the harness built. Blocks from the real store always carry `clientId` (evidence in Context).

**D2 — Verify-after-write, not trust-after-dispatch.** Redux dispatches are fire-and-forget action objects; the only honest acceptance signal is the store's own state. After dispatching a write, re-read the block through the same channel helper used for the search and require the inserted wrap prefix — `<a href="<url>" title="<title>">` — to be present; otherwise report the refused cause and do not count the placement. This catches the entire silent-no-op class (wrong identifier, reducer guards, future store changes), not just the known instance. Cost: one extra read per placement — negligible.

**D3 — One refused-cause sentence family, two precise parentheticals**: `the editor refused this change (block identity missing)` for unidentifiable blocks, and `the editor refused this change (the editor store did not accept the change)` for writes not reflected on re-read. No new taxonomy tier; the sentence family already exists in the dispatch-catch path.

**D4 — Faithful fakes.** The harness switches to `clientId` and mirrors the real reducer semantics inside the fake store: writes addressed to unknown client ids are silent no-ops, exactly like `UPDATE_BLOCK`. New fixtures: (a) an id-less located block → refused, nothing dispatched, placed 0, no save; (b) a store that drops the write → refused, placed 0, message never claims an insertion. Recorded invariant: stub-store contracts must be validated against the shipped `wp-includes/js/dist` bundle — a self-consistent fake proves nothing.

**D5 — Shared channel helper.** The raw/classic content resolution moves into one helper used by both the search pass and the post-write re-read — a single source of truth for channel resolution, minimal refactor inside `applyViaBlockEditor`'s scope.

**D6 — Version 0.0.1.5** on both enqueue calls (cache bust).

**D7 — KNOWN-ISSUES**: correct entry #7's live-confirmation bullet honestly (the 10:51 run was a false positive; the reader/entity fixes remain correct and necessary), add entry #8 owning this class with the invariants: client-id addressing; verify-after-write before counting a placement; faithful fakes validated against the dist bundle; live confirmations require saved-DB-content proof, not UI messages.

## Risks / Trade-offs

- [Re-read is a heuristic acceptance check] → it verifies presence of the wrap prefix in the re-read content; a pre-existing identical link (same URL and title) could satisfy it without our write having taken effect — accepted residual risk (URL+title combos are unique per suggestion set), documented here.
- [Store selectors could be memoized or async] → WP data dispatch is synchronous; the re-read happens after the dispatch returns; the harness fakes mirror this.
- [Users who previously saw "Inserted 5" may now see refusals when something is off] → that honesty is the point: the message now matches the store.
- [Classic-path `replaceBlock` with a copied node] → covered by the same verify-after-write: if a replace does not take effect, the suggestion is refused loudly. The raw path — this site's actual content shape — keeps the core-modal-mirrored `updateBlock` + `replaceInnerBlocks` flow from wp71.

## Migration Plan

1. Back up `assets/admin.js`, `audit/js-span-test.js`, `includes/class-knowledgespark.php`, `KNOWN-ISSUES.md` into `audit/backups/fix-block-editor-client-id/<timestamp>/` with checksums (audit/ otherwise untouched).
2. Implement per tasks; guard suites green (`php -l`, `node --check`, `tests/smoke.php`, `audit/js-span-test.js`).
3. Live verification, trust-nothing edition: the user runs Apply All Links on post 409 on a freshly loaded editor page (0.0.1.5); a read-only container probe then verifies the saved `post_content` md5 **changed** and contains the inserted `<a href>` wraps with their title attributes; repeat once on post 405.
4. Rollback: restore the four backed-up files. No DB migration — the failed runs saved identical content, so there is nothing to undo.

## Open Questions

(none)
