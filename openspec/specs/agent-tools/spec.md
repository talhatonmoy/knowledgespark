# agent-tools Specification

## Purpose

Exposes KnowledgeSpark's suggest ("KnowledgeSpark" button) and Apply All Links actions as WordPress abilities on the core Abilities API, so AI harnesses (OpenCode, DeepSeek, any MCP client) can discover posts, find suggestions, and insert internal links end-to-end through WordPress MCP plugins with the same guards as the editor flow.

## Requirements

### Requirement: Interlink actions register as WordPress abilities
The plugin SHALL register exactly four abilities on the WordPress Abilities API — find suggestions, apply all links, interlink, and list posts — under its own ability category, each with a label, description, JSON-Schema input description, an execute callback, and a permission callback, marked public so WordPress MCP plugins expose them. On a WordPress without the Abilities API the plugin SHALL register nothing and every other feature SHALL keep working unchanged.

#### Scenario: registration with schemas and opt-in
- **WHEN** the WordPress Abilities API initializes on a site running KnowledgeSpark
- **THEN** the four abilities are registered under the KnowledgeSpark category with input schemas describing their parameters, and each is marked public so MCP consumers can expose it

#### Scenario: WordPress without the Abilities API
- **WHEN** KnowledgeSpark runs on a WordPress version that has no Abilities API
- **THEN** no registration is attempted, no error is raised, and the editor buttons and REST routes behave exactly as before

### Requirement: Ability calls behave like the editor buttons
Find-suggestions and apply-all-links SHALL produce the same suggestion fields, inserted counts, and per-cause skip sentences the editor buttons produce, operating on the post's saved content, by reusing the same engine internals the buttons' REST routes use. Their permission callbacks SHALL enforce the same per-post capability the routes enforce, and a call whose actor lacks that capability SHALL be refused before execution.

#### Scenario: agent finds suggestions for a saved post
- **WHEN** the find-suggestions ability is executed with a post ID and a maximum count for a saved post, by a user who can edit that post
- **THEN** it returns the suggestions the editor button would show — target title, URL, reason, and verified anchor/quote when available — limited to the requested count

#### Scenario: agent applies suggestions to a saved post
- **WHEN** the apply-all-links ability is executed with a post ID and the links from a prior find-suggestions call, by a user who can edit that post
- **THEN** the post's saved content is updated with the placeable links, the result reports how many were inserted and every skipped link with its actual cause, and the placement guards decide each placement

#### Scenario: ability execution refuses non-editors
- **WHEN** an authenticated user lacks permission to edit the target post
- **THEN** the find-suggestions, apply-all-links, and interlink abilities refuse the operation, and the listing ability refuses a user who cannot edit posts, without exposing result data

### Requirement: One-shot interlink mirrors the button pair
The interlink ability SHALL, in one execution, find suggestions for the post and then apply the applicable ones — keeping exactly the suggestions that carry a non-empty anchor and a valid target URL, the same filter the Apply All Links button applies before it asks the editor for confirmation. It SHALL report inserted links and every skipped suggestion with its cause, and SHALL change nothing when no suggestion is applicable.

#### Scenario: mixed applicable and unplaceable suggestions
- **WHEN** some found suggestions have anchors that place cleanly and others are already inside links or absent from the saved draft
- **THEN** the placeable ones are inserted and saved, and the others are reported with their specific skip causes in the same result

#### Scenario: no applicable suggestions
- **WHEN** the found suggestions all lack an anchor phrase or valid target URL
- **THEN** nothing is applied or saved and the result states that no suggestions were applicable, without fabricating placements

#### Scenario: post edited while interlink runs
- **WHEN** the post's saved content changes between suggestion and placement within an interlink execution
- **THEN** the placement path re-locates anchors in the current saved content and any stale anchor degrades to its honest skip cause, never a forced insertion

### Requirement: Agent target listing
The list-posts ability SHALL return the site's published posts — post ID, title, permalink, and last modified time — in ascending ID order with cursor paging, so an agent can choose interlinking targets. It SHALL NOT include drafts, pages, private, or password-protected posts, and SHALL require the same permission as editing posts.

#### Scenario: list published posts
- **WHEN** the list-posts ability is executed by an authenticated user who can edit posts
- **THEN** published posts are returned with ID, title, URL, and modified time, with cursor paging, and non-post or non-public entries are absent

#### Scenario: paging advances without gaps or repeats
- **WHEN** the listing is executed with the cursor returned by a previous page
- **THEN** the next page starts at the following post ID with no repeated entries

### Requirement: MCP exposure is opt-in through WordPress MCP plugins
Abilities SHALL be exposed to MCP clients only through WordPress MCP plugins consuming the Abilities API (such as MCP Adapter), authenticated as WordPress users via Application Passwords; the plugin itself SHALL ship no MCP transport. With no WordPress MCP plugin installed, the abilities SHALL remain registered and callable through the core abilities REST surface, and nothing else SHALL change.

#### Scenario: MCP client executes through the adapter
- **WHEN** an MCP client authenticates through the site's WordPress MCP plugin and invokes the KnowledgeSpark find-suggestions tool for a post the user can edit
- **THEN** the ability executes under that user's permissions and returns its result through MCP, with the plugin adding no protocol logic of its own

#### Scenario: no WordPress MCP plugin installed
- **WHEN** the site has no WordPress MCP plugin active
- **THEN** the four abilities stay registered and executable through the core abilities REST route, no MCP tools appear, and the editor and plugin behavior are otherwise unchanged

### Requirement: Application Password availability on non-HTTPS sites is explicit opt-in
The plugin SHALL provide a setting, off by default, that makes WordPress Application Passwords available when the site is served over plain HTTP (such as a local development site); on HTTPS sites Application Password availability SHALL remain exactly what WordPress core provides.

#### Scenario: opt-in disabled on an HTTP site
- **WHEN** the site is served over plain HTTP and the agent-access setting is off
- **THEN** Application Passwords remain unavailable as WordPress core provides, and agent authentication against the site fails closed

#### Scenario: opt-in enabled on a local HTTP site
- **WHEN** an administrator enables the agent-access setting on a plain-HTTP site and creates an Application Password
- **THEN** MCP clients authenticating with those credentials can reach the abilities through the site's WordPress MCP plugin

### Requirement: Agent applies preserve the placement guards
Every apply performed through the abilities SHALL pass through the same placement guards and permission checks as the in-editor Apply All Links flow: no ability path SHALL insert a link the server route would skip, and no suggestion SHALL ever be forced into the document.

#### Scenario: anchor already inside a link
- **WHEN** a suggestion's anchor occurs only inside an existing link in the saved post
- **THEN** the ability reports that anchor as already inside a link and the post is otherwise unchanged

#### Scenario: anchor only inside protected markup
- **WHEN** a suggestion's anchor occurs only inside script, style, or comment markup in the saved post
- **THEN** the ability skips it with the protected-markup cause and the protected content stays byte-identical

#### Scenario: engine failures never silently succeed
- **WHEN** an ability execution fails inside the engine (for example the post cannot be saved)
- **THEN** the failure surfaces as the ability's error result with the engine's message, never as a report of inserted links
