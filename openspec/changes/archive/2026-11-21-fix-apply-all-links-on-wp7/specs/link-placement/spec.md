# Spec Delta

## Purpose

Defines how Apply All Links places suggested anchor links safely — which block content it searches, where an anchor span may never be placed (script, style, comments, attribute values, existing links), and how skipped suggestions are reported to the editor.

## ADDED Requirements

### Requirement: Block content resolution for editor apply
The in-block-editor apply path SHALL resolve each text block's searchable content from the block schema's registered text attribute, supporting the attribute names `innerHTML`, `value`, and `content`, so apply works across WordPress versions. When an insertion is applied, the updated content SHALL be written back to the same attribute that was read. A text block exposing none of these string attributes contributes no searchable content and is treated as containing no anchor.

#### Scenario: html-block article on WordPress 7
- **WHEN** the post body is one `core/html` block whose article text is stored in its `content` attribute and a suggestion's anchor is plain text in that article
- **THEN** the anchor is located in that block and the link is inserted there, and the post saves

#### Scenario: block exposing content only as innerHTML
- **WHEN** a text block registers its article text under `innerHTML` (older block schema)
- **THEN** the anchor is still located in that block (compatibility is preserved both ways)

#### Scenario: block exposes no readable content attribute
- **WHEN** no block in the post exposes `innerHTML`, `value`, or `content` as a string
- **THEN** every suggestion is skipped as not present in the current draft, nothing is inserted, and no error is raised

#### Scenario: write-back uses the read attribute
- **WHEN** an insertion is applied to a block whose content was read from `content`
- **THEN** the replaced block carries the updated text under `content`, and no other content attribute is created or clobbered

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
A skipped suggestion SHALL be reported with its actual cause from a fixed set: the anchor is not present in the current draft; the anchor is present but already inside a link; the anchor is present only inside protected markup; the target URL is invalid; the target is not a post on this site; or the target is the post itself. The report SHALL NOT use the "not present" wording when the anchor text was found but unplaceable, and unplaceable suggestions SHALL never be forced into the document.

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
