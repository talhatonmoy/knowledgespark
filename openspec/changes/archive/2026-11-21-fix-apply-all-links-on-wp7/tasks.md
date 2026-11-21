# Tasks

Reference: spec `link-placement` (what), design D1–D4 (how). Guard suite = `php -l includes/class-knowledgespark.php && node --check assets/admin.js && php tests/smoke.php && node audit/js-span-test.js`.

## 1. Baseline and backup

- [x] 1.1 Create timestamped backups of `includes/class-knowledgespark.php`, `assets/admin.js`, `tests/smoke.php`, `audit/js-span-test.js`, `KNOWN-ISSUES.md` under `audit/backups/fix-apply-all-links-on-wp7/` (no git repo exists; verification: checksum each backup against the source file) of `includes/class-knowledgespark.php`, `assets/admin.js`, `tests/smoke.php`, `audit/js-span-test.js`, `KNOWN-ISSUES.md` under `audit/backups/fix-apply-all-links-on-wp7/` (no git repo exists; verification: checksum each backup against the source file)
- [x] 1.2 Record a green baseline of the full guard suite before any edit (verification: all four commands exit 0; note the passing check counts)

## 2. Protected-region guard, both engines

- [x] 2.1 PHP `includes/class-knowledgespark.php`: add the protected-range pre-scan (single regex for `<script>`/`<style>`/`<!-- -->` ranges, per design D2) to `find_anchor_span()` and reject any candidate whose span overlaps a protected range; add the PHP 7.4-safe optional pass-by-reference rejection-reason out-param (`in_existing_link` / `in_protected_markup`) from the existing tidy-loop; keep dense style, no blank lines, PHP 7.4 syntax only (verification: `php -l` clean; new vectors from 2.3 pass)
- [x] 2.2 JS `assets/admin.js`: mirror the identical range regex and overlap rejection into `findAnchorSpan()` and return the rejection reason as an optional `hint` field, ES5, no dependencies (verification: `node --check` clean; new vectors from 2.4 pass)
- [x] 2.3 `tests/smoke.php`: add span vectors — anchor only inside a JSON-LD-shaped `<script>`; anchor bridgeable only across `</script><p>`; anchor only inside a `<style>`; anchor only inside an HTML comment; unclosed `<script>` over-masks (honest skip, no insertion); positive vector: anchor in both script and body resolves to the body span and the script text is byte-identical after insertion (verification: `php tests/smoke.php` green including 6 new vectors)
- [x] 2.4 `audit/js-span-test.js`: add the same vector class against the shipped `admin.js` (verification: `node audit/js-span-test.js` green with the parallel new vectors)

## 3. Honest skip reasons, both engines

- [x] 3.1 PHP `api_apply()`: drive the skip sentences from the matcher reason + the existing plain-text presence check so the taxonomy (not in the current draft / already inside a link / only in protected markup / invalid URL / not a post on this site / self-link / no anchor) is used; keep the response shape (array of strings) (verification: `php tests/smoke.php` asserts the new sentences for each cause)
- [x] 3.2 JS block-editor apply: build skip messages from the same taxonomy (replace the generic "not found in the current draft" sentence); keep the "No links could be inserted…" summary composed from per-cause lines (verification: `audit/js-span-test.js` gains a stubbed fake block-editor harness — fake `getBlocks`/`replaceBlock`/`savePost`, captured status node — asserting one sentence per cause)
- [x] 3.3 Update any existing test expectations that assert the old message wording (verification: full `php tests/smoke.php` + `node audit/js-span-test.js` green)

## 4. WP 7 block-content attribute resolution

- [x] 4.1 JS `applyViaBlockEditor()`: resolve each text block's content from the chain `innerHTML` → `value` → `content` (first string-valued attribute) and write the updated HTML back to the same key that was read in the `replaceBlock` payload (verification: js-span-test harness: a fake block exposing only `content` has its anchor found and is replaced via `content`; a fake block exposing only `innerHTML` still works)
- [x] 4.2 js-span-test vectors for attribute choice: block with both `innerHTML` and `content` (chain prefers `innerHTML`); block with no string attribute among the three (skipped as not in the current draft, no error) (verification: `node audit/js-span-test.js` green)

## 5. Live verification and long-term memory

- [x] 5.1 Live end-to-end on a throwaway draft post in DevKinsta (Gutenberg, single html block containing a JSON-LD `<script>`, one plain-text anchor, one anchor already inside an existing link, one anchor present only in the JSON-LD): Apply All Links → plain anchor inserted and the post saves, JSON-LD byte-identical before/after (diff via `docker exec devkinsta_fpm php -r` get_post read — reads only), already-linked anchor skipped with the "already inside a link" sentence, JSON-only anchor skipped with the protected-markup sentence; then delete the throwaway post (verification: observed UI sentences + content diff)
- [x] 5.2 Re-run the full guard suite and record the results (verification: all four commands exit 0 with check counts noted)
- [x] 5.3 Add KNOWN-ISSUES.md entry #6 (symptom: all anchors "not found" on html-block posts; root causes: dead `innerHTML` accessor on WP 7.1.5 — schema registers `content` — protected-markup matcher susceptibility; misleading not-found message for already-linked anchors; fix summary; invariants per design D1/D2/D3; guard commands) and extend entry #4's invariants with the protected-region clause (verification: entry present, every listed guard command runs green as documented)

## Workflow follow-up

- Archive the change (`openspec archive`) after the project's review is satisfied.
- Optionally plan a follow-up change: cross-domain mirror awareness at suggest time (exclusion map cannot see `jarhabitats.com` links via `url_to_postid`) and placeability pre-check in `rank()` so already-unplaceable anchors never reach the UI.
