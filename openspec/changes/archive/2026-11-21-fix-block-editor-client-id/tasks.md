# Tasks

## 1. Backups

- [x] 1.1 Back up `assets/admin.js`, `audit/js-span-test.js`, `includes/class-knowledgespark.php`, and `KNOWN-ISSUES.md` to `audit/backups/fix-block-editor-client-id/<timestamp>/` (flat directory), record checksums of the originals, and verify the copies by comparison before touching code (no other audit artifacts touched)

## 2. Editor write addressing + verified placement (assets/admin.js)

- [x] 2.1 In `applyViaBlockEditor()` only: extract the raw/classic content-channel resolution into a shared helper used both to search blocks and to re-read a block after a write (no behavior change by itself; matcher, reader channel, entity tokens, and skip taxonomy untouched)
- [x] 2.2 Resolve the target block's store identifier as `block.clientId` (the real block-editor store contract) and replace `block.blockId` at all four dispatch sites; if a located block exposes no client id, push the refused skip sentence (`the editor refused this change (block identity missing)`), dispatch nothing, and do not count the link as placed
- [x] 2.3 After each dispatched write (raw and classic paths), re-read the block through the shared helper and require the inserted wrap prefix — `<a href="<url>" title="<title>">` — to be present in the block's content; if absent, push the refused skip sentence (`the editor refused this change (the editor store did not accept the change)`), do not count the link as placed, and continue with the next suggestion

## 3. Cache busting

- [x] 3.1 Bump both `wp_enqueue_script`/`wp_enqueue_style` version strings `0.0.1.4` → `0.0.1.5` in `includes/class-knowledgespark.php` (1-space indent, byte-verified)

## 4. Guard suites

- [x] 4.1 In `audit/js-span-test.js` only: switch every fake block and fake store from `blockId` to `clientId`, and make the fake store's `updateBlock`/`replaceInnerBlocks`/`replaceBlock` mirror the real reducer semantics — writes addressed to unknown client ids are silent no-ops; update the existing harness assertions to the new identifier and keep all existing scenarios passing
- [x] 4.2 Add fixtures: (a) a located block with no client id — apply reports the refused cause, dispatches nothing, placed stays 0, no save; (b) a store that silently drops the write — apply reports the refused cause, the completion message never claims an insertion, placed stays 0, no save; (c) the accepted-write path still counts and saves (existing placements updated to assert the verified count)
- [x] 4.3 Run `php tests/smoke.php` to confirm the PHP suite stays green at 62 (this change makes no PHP behavior change)

## 5. Long-term memory

- [x] 5.1 Correct `KNOWN-ISSUES.md` entry #7's live-confirmation bullet: record that the 10:51 "solved" run was a false positive (saved content byte-identical, zero links persisted) while the wp71 reader and entity fixes remain correct and necessary
- [x] 5.2 Add `KNOWN-ISSUES.md` entry #8 — "Apply All Links — false success: 'Inserted N links' with zero links persisted (`blockId` vs `clientId`)" — with symptom, root cause (four dispatch sites used a non-existent `blockId` property; the `UPDATE_BLOCK` reducer no-ops on unknown ids; the stubbed harness invented the property and stayed self-consistent), the fix, and invariants: address blocks only by the store's `clientId`; verify every write by re-reading before counting a placement — never trust a dispatch; stub-store contracts must be validated against the shipped `wp-includes/js/dist` bundle; live confirmations require saved-DB-content proof, not UI messages
- [x] 5.3 Update the run-guards header counts to the new suite totals

## 6. Live verification (trust nothing but the database)

- [x] 6.1 After implementation, run Apply All Links on post 409 in the real editor on a freshly loaded page (0.0.1.5); then a read-only container probe verifies post 409's saved `post_content` md5 changed and contains the inserted `<a href>` wraps with their title attributes; repeat once on post 405

## 7. Project checks

- [x] 7.1 `php -l includes/class-knowledgespark.php`
- [x] 7.2 `node --check assets/admin.js`
- [x] 7.3 `php tests/smoke.php` — all checks green
- [x] 7.4 `node audit/js-span-test.js` — all checks green, including the new refusal fixtures
