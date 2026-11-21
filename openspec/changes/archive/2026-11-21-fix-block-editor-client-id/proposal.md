# Proposal

## Why

On 2026-11-21, after `fix-apply-all-links-on-wp71` shipped, Apply All Links on post 409 reported *"Inserted 5 suggested links and saved the post"* — but the database shows the saved content stayed **byte-identical to the pre-run content** (md5 `4abb4eff…`, 23818 B, zero new links, zero inserted `title` attributes). The same false success recurred on post 405 (saved content identical to revision 560). Read-only forensics against the shipped bundles and the live DB pinpoint the cause:

- The editor apply path addresses blocks by `block.blockId` — **a property that does not exist in the real block-editor store**. The real identifier is `clientId` (the store's own tree code uses `block.clientId`, and `getBlock` is keyed by it).
- `updateBlock`/`replaceInnerBlocks`/`replaceBlock` therefore dispatch actions for an `undefined` id. The `UPDATE_BLOCK` reducer guards with `if (!state.has(action.clientId)) return state;` — a **silent no-op**. Nothing throws, `placed++` runs anyway, `savePost()` persists the *unchanged* store, and the UI reports a success that never happened.
- The stubbed test harness invented `blockId` in the earlier wp7 change and every fixture since reused the self-consistent fiction — 56/56 checks green while real-editor writes were dead. A self-consistent fake proves nothing.
- KNOWN-ISSUES entry #7 currently records a "live confirmation" that was actually a false positive and must be corrected — the project's long-term memory currently guards a wrong fact.

## What Changes

- **Client-identifier addressing**: all four write dispatches in `applyViaBlockEditor` use the block's store-assigned `clientId`; a located block with no resolvable client id is never written to and is reported with the refused cause.
- **Verify-after-write**: a suggestion counts as placed only after re-reading the block's content confirms the inserted link is present. Any silently-ignored write becomes a visible refused skip, so the success message can never report links that are not reflected in the editor store.
- **Faithful test harness**: fake blocks/stores in `audit/js-span-test.js` switch to `clientId` and mirror the real reducer semantics (writes addressed to unknown ids are silent no-ops, exactly like `UPDATE_BLOCK`); new fixtures cover the id-less block and the ignored write.
- **Memory correction**: KNOWN-ISSUES entry #7's false live confirmation is corrected honestly; new entry #8 records this failure class and its invariants — address blocks only by `clientId`; verify the write by re-reading before counting a placement; validate stub-store contracts against the shipped `wp-includes/js/dist` bundle; live confirmations require saved-DB-content proof, not UI messages.
- **Cache bust**: enqueue version strings 0.0.1.4 → 0.0.1.5.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `link-placement`:
  - "Block content resolution for editor apply" — the write-back must address the block by its editor-assigned client identifier (new scenario).
  - "Honest per-cause skip reporting" — the fixed cause set gains "the editor refused the change" (new scenario).
  - New requirement "Placements are only reported when actually applied" — verify-after-write; a write not reflected on re-read is refused and never counts toward the inserted total.

## Impact

- `assets/admin.js` — write addressing + post-write verification in `applyViaBlockEditor` only; the matcher, reader channel, entity tokens, and skip taxonomy are untouched.
- `audit/js-span-test.js` — fake store contract (clientId + real no-op semantics) and new refusal fixtures; no other audit artifacts touched.
- `includes/class-knowledgespark.php` — version strings only.
- `KNOWN-ISSUES.md` — entry #7 correction, entry #8, run-guards header.
- No PHP behavior change, no REST contract change, no suggest-time change, no database migration (the failed runs saved identical content — there is nothing to undo).

## Out of Scope

- No matcher, reader-channel, or entity-handling changes — the wp71 fixes are correct and stay.
- No suggest-side anchor improvements (the KNOWN-ISSUES #6 open follow-up: cross-domain mirror awareness and the placeability pre-check — separate future change).
- No REST/server apply changes — `api_apply` is unaffected and already persists correctly.
- No automatic re-application of the links missed on posts 409/405 — after this change ships, Apply All Links is re-run manually, and verification then proves persistence in the database.
