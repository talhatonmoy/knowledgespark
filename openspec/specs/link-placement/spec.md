# link-placement Specification

## Purpose

Defines how Apply All Links places suggested anchor links safely — which block content it searches, where an anchor span may never be placed (script, style, comments, attribute values, existing links), and how skipped suggestions are reported to the editor.

## Requirements

### Requirement: Block content resolution for editor apply
Each text block's searchable content SHALL come from the channel that stores its text: raw HTML blocks (`core/html`, `core/freeform`) from the block's inner content (canonical content API when available, else `innerHTML`, else `innerContent` joined); other text blocks from their registered text attribute (`innerHTML`, `value`, or `content`). An applied insertion SHALL be written back through the same channel that supplied the text, addressed to the block by its editor-assigned client identifier.

#### Scenario: html-block article on WordPress 7
- **WHEN** the post body is one `core/html` block whose article text is stored in the block's inner content — as in current WordPress, where that block's text attribute is empty — and a suggestion's anchor is plain text in that article
- **THEN** the anchor is located in that block, the link is inserted there, and the post saves

#### Scenario: block exposing content only as innerHTML
- **WHEN** a text block registers its article text under `innerHTML` (older block schema) and no inner-content channel is available
- **THEN** the anchor is still located in that block and the write-back uses that same attribute (compatibility is preserved both ways)

#### Scenario: block exposes no readable content attribute
- **WHEN** no block in the post exposes readable searchable content through any channel
- **THEN** every suggestion is skipped as not present in the current draft, nothing is inserted, and no error is raised

#### Scenario: write-back uses the read attribute
- **WHEN** an insertion is applied to a block whose content was read from its `content` attribute, or — for a raw HTML block — from its inner content
- **THEN** the replaced block carries the updated text under that same channel (the `content` attribute, or the block's inner content with its inner blocks resynchronized), and no other content channel is created or clobbered

#### Scenario: raw-block write-back round-trips through serialization
- **WHEN** an insertion is applied to a raw HTML block whose article was read from its inner content, and the post is saved
- **THEN** the saved post's serialization still contains the full original raw article plus the inserted link, byte-preserved outside the inserted `<a>` wrap

#### Scenario: write-back addresses the block by its store client identifier
- **WHEN** a placement is dispatched to the block-editor store for a located block
- **THEN** the write is addressed to that block's client identifier as exposed on the store's block objects, and a block exposing no client identifier is never written to

### Requirement: Protected regions are never matched into
Anchor span resolution SHALL treat the full ranges of `<script>` elements, `<style>` elements, and HTML comments as opaque: no resolved span SHALL start, end, or overlap inside such a range, and at most one span position per anchor SHALL be returned only from literal character data outside tags. Matching across markup SHALL NOT bridge a protected element's body.

#### Scenario: anchor duplicated in JSON-LD schema markup
- **WHEN** an anchor phrase appears verbatim first inside a `<script type="application/ld+json">` element and also later in visible body text
- **THEN** the resolved span (if any) is the body occurrence, and the script element's content is byte-identical after the apply completes

#### Scenario: anchor exists only inside protected markup
- **WHEN** an anchor's only occurrences are inside `<script>`, `<style>`, or an HTML comment
- **THEN** no span is resolved for it and it is skipped without modifying any protected element

#### Scenario: anchor bridgeable only across protected markup
- **WHEN** an anchor's characters occur consecutively only by spanning across a `<script>…</script>` or comment range
- **THEN** no span is resolved (the bridge is not allowed through protected bodies)

#### Scenario: anchor occurs only in an attribute value
- **WHEN** an anchor's only textual occurrences are inside tag attribute values (for example an `href` slug)
- **THEN** no span is resolved for it

### Requirement: Guard parity between editor and server paths
The in-editor path and the server-side REST path SHALL apply the same placement guards — protected-region handling, existing-link and nesting-tidiness checks, and anchor text normalization — so that for the same anchor and candidate region, both paths reach the same guard decision: the span is accepted, or the same skip cause applies.

#### Scenario: same candidate region, both paths agree
- **WHEN** an anchor's candidate text region is searched by both paths (the editor's text-block coverage and the server's whole-document coverage both include it, for example one html block holding the whole article as in this site's content format)
- **THEN** both paths either resolve the identical span or skip the suggestion for the same cause

### Requirement: Honest per-cause skip reporting
A skipped suggestion SHALL be reported with its actual cause from a fixed set: the anchor is not present in the current draft; already inside a link; only inside protected markup; the target URL is invalid; the target is not a post on this site; the target is the post itself; or the editor refused the change. The "not present" wording SHALL NOT be used when the anchor was found but unplaceable, and unplaceable suggestions SHALL never be forced into the document.

#### Scenario: anchor already inside a link
- **WHEN** an anchor's every occurrence is inside an existing `<a>` element
- **THEN** the skip message states the anchor is already inside a link, and the document is unchanged

#### Scenario: anchor genuinely absent
- **WHEN** an anchor's text occurs nowhere in the current draft
- **THEN** the skip message states the anchor is not in the current draft

#### Scenario: anchor only in protected markup
- **WHEN** an anchor's every occurrence is inside script, style, or comment markup
- **THEN** the skip message states the anchor sits in protected markup, and the protected content is unchanged

#### Scenario: mixed placement outcome
- **WHEN** one suggestion is placeable and another is already inside a link
- **THEN** the placeable suggestion is inserted and saved, and the other is listed with its specific cause in the same result

#### Scenario: editor refuses the write
- **WHEN** a block cannot be identified in the editor store, or the store does not accept a dispatched write
- **THEN** the suggestion is skipped with the editor-refused cause and is not counted as inserted

### Requirement: Entity-aware anchor matching
Anchor span resolution SHALL treat the standard HTML entity forms of a character (named `amp`, `lt`, `gt`, `quot`, apostrophe, and decimal or hex numeric forms) as that character, and no-break-space entities as joinable whitespace. A resolved span SHALL be the literal slice of the draft's original text, so entities in the stored document are preserved and never rewritten. This matching SHALL be identical in the editor path and the server path.

#### Scenario: ampersand anchor matches entity-encoded draft
- **WHEN** an anchor contains a literal `&` (for example "Light, Water & Plants") and the current draft contains the same phrase encoded as `&amp;`
- **THEN** the span is resolved in the raw document, the inserted link wraps the raw slice, and the stored document retains its original entity bytes at that position with no other change

#### Scenario: both engines agree on entity forms
- **WHEN** the same anchor and the same raw document are resolved by the editor path and by the server path
- **THEN** both paths resolve the identical raw span (or the identical skip cause under the placement guards), keeping the PHP/JS matcher mirror intact

#### Scenario: entity forms inside attribute values do not match
- **WHEN** an anchor's only entity occurrences sit inside tag attribute values (for example `title="a&amp;b"`)
- **THEN** no span is resolved (the in-tag guard outranks entity matching)

#### Scenario: plain (non-entity) anchors are unchanged
- **WHEN** an anchor's text occurs in the draft without any entity encoding
- **THEN** the span resolves exactly as it did before this change, with no regression to prior matching behavior

### Requirement: Placements are only reported when actually applied
The editor apply flow SHALL treat a suggestion as placed only after re-reading the affected block's content confirms the inserted link is present. A write not reflected in the block's content on re-read SHALL be reported as refused and SHALL NOT count toward the inserted total, so the completion message SHALL never report links that are not reflected in the editor store.

#### Scenario: store silently ignores the write
- **WHEN** a write is dispatched and the block's content is unchanged when re-read
- **THEN** the suggestion is reported with the refused cause, the inserted count stays unchanged, and no success message claims it

#### Scenario: accepted write counts truthfully
- **WHEN** a dispatched write is reflected in the block's content on re-read
- **THEN** the suggestion counts toward the inserted total and the save proceeds

#### Scenario: completion message matches the store
- **WHEN** the apply completes with zero links verified in the editor store
- **THEN** the flow reports the refused causes instead of a successful insertion count and does not save
