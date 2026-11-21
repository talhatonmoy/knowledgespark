# Task 07 — Presentation Audit

Inputs: `admin.js` (`request`, `currentPost`, `renderSuggestions`), `admin.css`, `box()`/`box_order()` in `class-knowledgespark.php` (L56–70).

## 1. Confirmation of safety and workflow properties

- **Safe DOM rendering:** confirmed — `renderSuggestions()` builds nodes with `createElement`/`textContent` only; returned strings never reach `innerHTML`. Links pass `safeUrl()` which allows only `http:`/`https:`; `target=_blank` + `rel=noopener noreferrer`. **OK**
- **Manual-placement wording:** confirmed — intro copy ("Review and place links manually"), per-run banner ("Review each suggestion, copy its URL, then add the link yourself… Your content has not been changed"), and no auto-insertion code path exists. **OK**
- **Copy URL:** confirmed — clipboard API with try/catch; on failure the readonly field is focused/selected with a Ctrl/Cmd+C message. **OK**
- **Unsaved draft usage:** `currentPost()` prefers Gutenberg `getEditedPostContent()`/`getEditedPostAttribute('title')`, falls back to Classic fields/TinyMCE — matches the documented behavior. **OK**

## 2. Verdicts

**(a) Empty-anchor placeholder confusion — OK (minor note).**
When `anchor` is empty the UI shows "Choose relevant text in your draft." under the label "Suggested existing anchor", and "No contextual quote provided." under the quote label. Both are clearly instructional rather than fabricated data, so an editor is unlikely to mistake them for a real suggestion. Cosmetic improvement possible (dim styling for placeholder rows).

**(b) Mode/fallback messaging — OK for mode labels; RISK-minor on empty state.**
- AI mode success → "AI-reviewed suggestions. Verify the target and placement before linking." ✅
- AI not configured → "Semantic matches; review each target… Configure AI…" ✅
- AI failed → "AI ranking unavailable; showing semantic matches. <error>" ✅
- **Gap (carried from Task 06):** AI returns an *empty* list → same "AI-reviewed suggestions…" message renders in the empty state, implying suggestions exist when none do. Should say "AI reviewed and found no useful links." Cheap fix, goes to report.

**(c) Normal-column metabox rendering — OK.**
`metabox()` registers `normal`/`high`; `box_order()` migrates legacy user options that still have `knowledgespark` in the `side` column into `normal` (prepended) without disturbing other boxes (covered by regression test "Move saved sidebar placement below editor…"). Gutenberg renders normal-column metaboxes below the editor; CSS `max-width: none` on `#knowledgespark-editor` gives the panel full column width; grid suggestion cards and mobile styles are column-agnostic. No render path issues found.

## 3. Information the editor cannot see that would help review

| Missing datum | Why it would help |
|---|---|
| Target publish/modified date | Staleness judgment at review time |
| Target category/tags | Section-fit judgment |
| Target length/depth | Avoid suggesting thin stubs or 10k-word essays |
| Similarity score (even coarse Low/Med/High) | Triage order within the list |
| Whether target links back / already linked elsewhere in site | Link-graph awareness |
| Target's headings | Judge section-level fit without opening the post |

None of these are integrity problems — they are review-efficiency gaps. Feeds into REPORT improvement list.

**DONE WHEN:** met — confirmations + three verdicts + missing-info list present.
