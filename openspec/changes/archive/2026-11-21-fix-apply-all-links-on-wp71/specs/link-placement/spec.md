# Spec Delta

## MODIFIED Requirements

### Requirement: Block content resolution for editor apply
Each text block's searchable content SHALL come from the channel that stores its text: raw HTML blocks (`core/html`, `core/freeform`) from the block's inner content (canonical content API when available, else `innerHTML`, else `innerContent` joined); other text blocks from their registered text attribute (`innerHTML`, `value`, or `content`). An applied insertion SHALL be written back through the same channel that supplied the text.

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

## ADDED Requirements

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
