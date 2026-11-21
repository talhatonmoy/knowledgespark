# Proposal

## Why

KnowledgeSpark's two actions — finding reader-focused suggestions and applying all links — are reachable today only by a human clicking the **KnowledgeSpark** and **Apply All Links** buttons in the post editor. AI harnesses (OpenCode, DeepSeek Harness, any MCP client) connect to WordPress through the standard WordPress MCP surface: the core **Abilities API** plus a WordPress MCP plugin such as the official **MCP Adapter**, which turns registered abilities into MCP tools. KnowledgeSpark publishes nothing there, so agents can do no interlinking work even though the underlying actions (the REST routes behind the buttons, which operate on the saved post) are fully capable. Agent authentication is also blocked on the local HTTP dev site (`wp_is_application_passwords_available()` returns `false` on `http://dev-site.local`).

## What Changes

- **Four WordPress abilities registered on the core Abilities API** (verified live: `wp_register_ability()`, `WP_Ability`, and the `/wp-json/wp-abilities/v1` REST surface all exist on the dev-site's WordPress 7.1.5), under a plugin-owned `knowledgespark` ability category:
  - `knowledgespark/find-suggestions` — the KnowledgeSpark button for a saved post (`post_id`, `count`): suggestions with reasons, anchors, and quotes.
  - `knowledgespark/apply-all-links` — the Apply All Links button for a saved post (`post_id` + `links` from a prior find): the existing server apply path, with all placement guards and per-cause skip reporting.
  - `knowledgespark/interlink` — one-shot composition of both buttons: find suggestions, keep the applicable ones (non-empty anchor, valid URL — the editor button's filter), apply them, report inserted and skipped with their causes.
  - `knowledgespark/list-posts` — published posts (`post_id`, `title`, `url`, `modified`) so an agent can pick interlinking targets.
- **MCP exposure through WordPress MCP plugins**: abilities are marked `meta.public` (opt-in), so the official MCP Adapter exposes them as MCP tools over its HTTP and STDIO transports to harnesses like OpenCode and DeepSeek; on production sites that run the abilities plugin behind the Novamira CLI, the same registrations surface through `novamira discover`/`run`. KnowledgeSpark ships **no MCP protocol server** of its own.
- **Permission parity**: each ability's permission callback enforces the same per-post capability the REST routes enforce (`edit_post` on the target post; `edit_posts` for listing), and execute callbacks reuse the existing suggest/apply internals unchanged.
- **Agent authentication**: MCP clients authenticate as WordPress users via Application Passwords. A new plugin setting (**Allow Application Password access**, default off) makes Application Passwords available on non-HTTPS sites such as DevKinsta's `http://dev-site.local`; HTTPS production sites work with no plugin changes.
- **Offline tests** in the `tests/smoke.php` style: stub the Abilities API functions, assert the registrations (names, category, schemas, public flags, callbacks), and drive the execute and permission callbacks with the existing mocked engine — no external credentials needed.
- **Harness wiring documentation** in `readme.txt`: installing MCP Adapter, creating the Application Password, enabling the opt-in, connecting OpenCode and DSH to the adapter's endpoint, and the Novamira path on production sites.

## Capabilities

### New Capabilities

- `agent-tools`: KnowledgeSpark's suggest and apply-all actions exposed as WordPress abilities to AI agents — the ability set, registration and MCP opt-in, agent authentication, target listing, and guard parity. Owns how external agents interlink posts end-to-end through the standard WordPress MCP surface.

### Modified Capabilities

(none — `link-placement` is untouched: ability execute callbacks reuse `api_suggest`/`api_apply` exactly, so every guard, skip cause, and mirror invariant carries over unchanged.)

## Impact

- **Modified**: `includes/class-knowledgespark.php` (ability category + four registrations with schemas and callbacks; Application-Password availability setting, sanitize, and settings UI row), `tests/smoke.php` (Abilities API stubs + vectors), `readme.txt` (changelog, agent-access docs).
- **Unchanged**: `assets/admin.js`, `assets/admin.css`, the span matcher, the apply guards, all `KNOWN-ISSUES.md` invariants (no PHP/JS mirror change — abilities call the existing server internals), the existing REST routes (no new routes, none removed).
- **Systems**: the core Abilities API gains one category and four abilities; MCP exposure requires a WordPress MCP plugin (MCP Adapter) already installed — KnowledgeSpark declares no hard dependency so sites running other abilities plugins are unaffected. Qdrant and the AI provider are called only by the unchanged suggest engine behind the abilities.
- **Dependencies**: none new. Ability registration requires WordPress with the Abilities API (present on the dev-site's 7.1.5); on older WordPress the plugin registers nothing and every other feature keeps working.

## Out of Scope

- No standalone MCP server shipped in the plugin (`mcp/knowledgespark-mcp.php` from the earlier draft is dropped by this revision) — KnowledgeSpark works through WordPress MCP plugins instead.
- No hard `Requires Plugins: mcp-adapter` header — production sites run a different abilities plugin; the requirement is documented, not enforced.
- No changes to the suggestion engine, span matcher, Apply-All-Links safety guards, or their PHP/JavaScript mirror — `KNOWN-ISSUES.md` invariants stay intact.
- No MCP transport, session, or protocol work inside KnowledgeSpark (discovery and protocol belong to MCP Adapter and core).
- No agent tools for admin operations (index, reset, test connection, settings) — those remain human-only in the admin UI.
- No bulk/site-wide endpoint: an agent interlinks multiple posts by calling `interlink` per post.
- No changes to editor UI behavior; the two buttons work exactly as before.
