# Design

## Context

Two apply paths exist today (see `proposal.md` for motivation):

- **Block-editor path** — `assets/admin.js`: `applyViaBlockEditor()` walks `wp.data select('core/block-editor').getBlocks()`, tests each block against `TEXT_BLOCK_TYPES`, reads `block.attributes.innerHTML` as the searchable text, runs `findAnchorSpan(html, anchor)`, and rewrites the block via `replaceBlock`.
- **REST path** — `includes/class-knowledgespark.php`: `api_apply()` receives the editor's current content as a raw string and runs PHP `find_anchor_span($content, $anchor)` over the whole document.

Constraints shaping the approach:

- **KNOWN-ISSUES.md is the project's long-term memory** (user standing instruction). Invariant #4 already pins the apply guards: the PHP and JS matchers are mirrors, the join pattern `(?:\s|<[^>]+>)*` must stay in both, and unplaceable anchors are skipped and reported, never forced. This change *extends* that invariant; it is not a rewrite of the matcher core.
- PHP 7.4 only (no arrow functions, no `str_contains`), dense style, no empty lines in the class file (KNOWN-ISSUES §5). Plain JS in `admin.js` with no dependencies; no build step; no bundler.
- Verified facts: this install is WordPress **7.1.5**; its block registry registers `content` (not `innerHTML`) as the text attribute for `core/html`, `core/paragraph`, `core/list-item`, `core/freeform` (checked via `WP_Block_Type_Registry` in the container). Shipping `findAnchorSpan` matches **inside** `<script type="application/ld+json">` bodies today — proven by running the shipped code against the real post-410 structure (a span fully inside a script passes every current guard).
- The plugin directory is **not a git repository** — there is no `git checkout` to fall back on.

## Goals / Non-Goals

**Goals:**

- Apply All Links works for html-block (single-block-article) posts on WP 7 without version sniffing, and keeps working on older WP schemas.
- No apply run can ever modify the body of a `<script>`, `<style>`, or HTML comment in either path.
- Every skip message names its actual cause; "not found" is retired for found-but-unplaceable anchors.
- The KNOWN-ISSUES.md memory is extended (entry #6) so the next agent sees the full invariant set.

**Non-Goals:**

- Making the AI stop choosing already-linked production-mirror phrases (follow-up change; the guard already protects the document).
- Placeability pre-check at suggest time (follow-up change).
- Any change to suggestion retrieval, ranking, indexing, sync, or the settings screen.
- Restoring or reading blocks via WP internal `wp.blocks` APIs at runtime (we stay on `getBlocks()`).

## Decisions

### D1 — Block content: fixed ordered attribute chain, write back to the read key

`applyViaBlockEditor()` resolves a block's text attribute by the first of `innerHTML` → `value` → `content` whose value is a string (empty string counts as present: the block simply has no searchable text). The key that supplied the text is remembered per block, and the `replaceBlock()` call writes the updated HTML to **that same key** (spreading existing attributes and setting one).

- **Why:** the ordered chain is the cheapest cross-version contract — a block in any WP version exposes exactly one of these names as its text store (verified for this install; the older names covered by the chain are the documented historical ones). No version sniffing, no `blkType.attributes` lookup.
- **Write-back to the read key:** writing a second key (e.g., also setting `innerHTML` on a 7.x block) would introduce an unregistered attribute; the safest contract is "what the block gave us, we return under the same name."
- **Alternatives considered:** (a) look up the registered attribute via `wp.blocks.getBlockType(block.name)` and pick by `source: 'raw'/'html'` — more principled but binds us to an unregistered internal API and adds failure surfaces for little gain on text blocks; (b) serialize-and-reparse each block through `serialize`/`parse` — heavyweight, and block-store round-trips can normalize content unexpectedly.

### D2 — Protected regions: pre-scan, reject on overlap (do not strip)

Both engines add one identical pre-scan per match call:

```
/(<script\b[^>]*>[\s\S]*?<\/script>)|(<style\b[^>]*>[\s\S]*?<\/style>)|(<!--[\s\S]*?-->)/gi
```

collecting the character ranges of the three protected element classes. The existing candidate-walk (regex over the raw text, offset-captured) is unchanged; each candidate span is rejected when its `[start, end)` **overlaps** any protected range — which covers: span entirely inside a protected body, span starting or ending inside one, and the bridge case where the join pattern crosses a protected boundary (`…</script><p>…`), because such a slice physically crosses the recorded range.

- **Why reject instead of strip:** stripping protected bodies with placeholder padding would shift every offset and complicate the PHP `PREG_OFFSET_CAPTURE` / JS `exec` flow; overlap-rejection keeps the mirror diff small and reviewable, and the spec (protected-markup requirement) is exactly what this check enforces.
- **Why trio (script/style/comment):** the demonstrated corruption is JSON-LD scripts, but `<style>` bodies and comments share the identical textual hazard (and comments also exist in this content format as `<!-- wp:html -->` delimiters around the block).
- **Alternatives considered:** (a) special-case only `<script>` — leaves the same class of bug for styles/comments; (b) a full HTML tokenizer — overkill for the guard, and it would diverge from the JS engine's regex-based style (KNOWN-ISSUES §4 calls out the shared regex dialect).

### D3 — Reasons travel with the matcher, messages are a fixed taxonomy

`findAnchorSpan` (JS) and `find_anchor_span` (PHP) gain a rejection *reason* when they return no span: the existing tidy-loop already rejects candidates for specific checks, so we classify per candidate: `in-existing-link` (rejected by the `<a>` guards) or `in-protected-markup` (new D2 check); a loop that found a raw-text occurrence but only unplaceable ones returns the corresponding reason; no raw-text occurrence at all → reason absent. PHP carries this via an optional pass-by-reference out-param (PHP 7.4-compatible, existing call sites with no argument keep working); JS returns an object with an optional `hint` field (existing callers — the block-apply flow and `audit/js-span-test.js` — tolerate it).

Each path then maps reasons to its sentence strings from the shared fixed taxonomy (not-in-draft / in-existing-link / in-protected-markup / invalid-url / not-a-post / self-link / editor-refused). The REST response shape stays an array of human-readable strings (only the existing JS consumes it); the block-editor path builds the same sentences.

- **Why:** requirement "Honest per-cause skip reporting" cannot be met by endpoint heuristics — the moment a candidate dies is the only moment its cause is known for sure.
- **Alternative considered:** re-check the document at reporting time (a separate "why" scan) — duplicate work, extra divergence surface between engines, and the normalization must be mirrored anyway.

### D4 — KNOWN-ISSUES.md entry #6 (and an addendum to entry #4)

Per the standing instruction that KNOWN-ISSUES.md is the project's long-term memory: a new entry documents the user-visible symptom (all anchors "not found" on html-block posts), the three verified root causes (dead `innerHTML` accessor on WP 7.1.5; protected-markup susceptibility; misleading message for already-linked anchors), the fix, the invariants (ordered attribute chain + same-key write-back; identical protected-range regex + overlap rule in both engines; shared reason taxonomy; guard suite stays the gate), and the guard commands. Entry #4's invariants list is extended with the protected-region clause so the mirror rule now names it.

## Risks / Trade-offs

- [WP renames the text attribute again] → the chain has no "next" name, so a future schema would read no attribute and every suggestion would skip honestly (no corruption); mitigation is the KNOWN-ISSUES entry #6 diagnostic note: when apply-wide failures recur, first diff `wp-includes/blocks/*/block.json` attribute names in the container (the exact check that caught this).
- [Malformed content with an unclosed `<script>`] → the range regex greedily pairs to the end of the block, over-masking legitimate text; outcome is honest skips, never insertion into broken markup. Acceptable trade; covered by a test vector.
- [Message-text changes break existing test expectations] → `tests/smoke.php` and any e2e harness string assertions are updated in the same change (tasks cover it); the guard suite remains the gate.
- [Per-suggestion per-block re-scan cost on large html blocks] → one extra non-greedy regex pass per candidate over ≤250 KB; negligible next to the existing matcher walk.
- [Parity of block coverage is out of contract scope] → editor path still only searches `TEXT_BLOCK_TYPES` blocks; the spec contracts guard decisions, not coverage (deliberate, keeps the change small).
- [No git history in the plugin dir] → tasks include an explicit file backup step before editing (see Migration Plan).

## Migration Plan

1. Back up `includes/class-knowledgespark.php`, `assets/admin.js`, `tests/smoke.php`, `audit/js-span-test.js`, `KNOWN-ISSUES.md` (timestamped copies in `audit/backups/<change-name>/`) — no git repo exists to fall back on.
2. Land the guard/matcher changes (D2, D3) in both engines with new test vectors; run `php -l`, `node --check`, `php tests/smoke.php`, `node audit/js-span-test.js` — all green before the accessor change.
3. Land the accessor change (D1) in `admin.js`; re-run the JS suite (`audit/js-span-test.js` re-extracts the shipped `admin.js`, so it exercises the new code path via its existing harness vectors plus the new attribute-chain vector).
4. Live verification in the DevKinsta container on a **throwaway draft post** (html block, JSON-LD script, one already-linked phrase, one plain phrase): apply → plain phrase linked, JSON-LD bytes diff-clean, already-linked phrase skipped with the "already inside a link" sentence.
5. Update KNOWN-ISSUES.md entry #6 with the live-verification outcome.

**Rollback:** restore the five backed-up files; no data, cache, or schema migration is involved, so rollback is instant and total.

## Open Questions

- Exact final wording of the skip sentences (order: not-in-draft / in-existing-link / in-protected-markup is fixed by the spec; phrasing can be tuned while implementing without touching the spec).
- Whether the follow-up change (cross-domain mirror awareness at suggest time) also moves the exclusion map out of `url_to_postid()` — deferred to that change's own design.
