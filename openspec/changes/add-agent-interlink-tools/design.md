# Design

## Context

WordPress 7.1.5 on the dev-site ships the core **Abilities API** natively (verified live: `wp_register_ability()`, `wp_register_ability_category()`, the `WP_Ability` class, and REST routes `/wp-json/wp-abilities/v1/abilities` + `/run` all present). The official **MCP Adapter** plugin (`WordPress/mcp-adapter`, part of the AI Building Blocks initiative; PHP 7.4, tested 7.1.5) exposes registered abilities as MCP tools over HTTP (`/wp-json/mcp/mcp-adapter-default-server`) and STDIO, authenticated with Application Passwords, so harnesses like OpenCode and DeepSeek connect through it without any per-plugin MCP work. The user's production sites run the abilities plugin (v1.12.x, the same surface the Novamira CLI consumes), so core-registered abilities also surface through `novamira discover`/`run` there. The suggest/apply engine behind the abilities is unchanged (see proposal.md Why; specs/agent-tools/spec.md for the contract). Constraints: no build step or dependencies, tests fully mocked and offline, KNOWN-ISSUES.md invariants must not regress.

## Goals / Non-Goals

**Goals:**

- Publish the two button actions on the standard WordPress agent surface — the core Abilities API — so any WordPress MCP consumer (MCP Adapter, the Novamira abilities plugin on production, direct abilities REST) can use them.
- Reuse — not re-implement — the existing suggest/apply internals so every placement guard, per-cause skip sentence, retry, and permission check remains in exactly one place.
- Agent authentication with core WordPress Application Passwords, made available on the plain-HTTP dev site by an explicit, off-by-default opt-in.
- Offline, fully mocked tests for registration, callbacks, and the opt-in.

**Non-Goals** (beyond proposal.md's Out of Scope):

- No MCP transport, session, or protocol work of any kind — discovery and transport belong to MCP Adapter and WordPress core.
- No new custom REST routes and no changes to the existing ones.
- No agent identity management (creating users or Application Passwords from the plugin) — the administrator does that in the WordPress admin.

## Decisions

### D1: Register core abilities; ship no MCP server

KnowledgeSpark registers one ability category and four abilities through the core API (`wp_register_ability()` on `wp_abilities_api_init`; category on `wp_abilities_api_categories_init`). `meta.public => true` opts them into client exposure — on WordPress 7.1 that covers both the abilities REST surface and MCP; earlier versions route exposure through MCP Adapter. Registration is guarded by `function_exists()` so older WordPress (the plugin declares 6.4+; the Abilities API arrived later) is a silent no-op.

- Alternatives considered: the earlier draft's standalone stdio MCP server (`mcp/knowledgespark-mcp.php`) — dropped per the user's direction; it duplicated what MCP Adapter already provides and its protocol surface would live outside WordPress's security model. Hosting Streamable HTTP inside the plugin — rejected for the same reason plus plain-HTTP dev complexity. A hard `Requires Plugins: mcp-adapter` header — rejected: the production fleet runs a different abilities plugin (v1.12.x), and a hard dependency would block KnowledgeSpark there; the requirement is documented instead.

### D2: Names, category, and MCP-visible tool names

Category `knowledgespark` (own label/description). Abilities: `knowledgespark/find-suggestions`, `knowledgespark/apply-all-links`, `knowledgespark/interlink`, `knowledgespark/list-posts`. MCP Adapter sanitizes registration names to MCP tool names (`knowledgespark-find-suggestions`, …) and also exposes them through its meta-tools (`mcp-adapter/discover-abilities`, `get-ability-info`, `execute-ability`); on the default server, ability names pass through as-is in that context. Input schemas are JSON-Schema objects: find `{post_id (integer, required), count (integer 1–10, default 5)}`; apply `{post_id (required), links (array of {anchor, url, title}, required)}`; interlink `{post_id (required), count (optional)}`; list `{cursor (integer, default 0), limit (integer, default 50, max 100)}`. Annotations in WordPress format: find/list `readonly: true`, all `destructive: false`, apply/interlink `idempotent: false`.

### D3: Execute callbacks reuse the engine; permission mirrors the routes

Execute callbacks call the existing public statics — `api_suggest` and `api_apply` — by constructing the same request arrays the REST routes receive (they already accept array-style request objects, as `tests/smoke.php` proves), and a small listing query for list-posts modeled on the indexing route's cursor pattern (published, public posts only). No engine code is copied or forked, so guard parity is automatic. Permission callbacks mirror the REST route permissions exactly: `current_user_can('edit_post', post_id)` for find/apply/interlink (with `absint`-normalized post id), `current_user_can('edit_posts')` for listing. The Abilities API validates input against the schema before the permission callback runs, so a call with invalid arguments never reaches a permission decision.

### D4: Interlink composes in one server-side execution

`interlink` calls the suggest internals, filters to applicable suggestions (non-empty anchor + valid `http(s)://` URL — the editor button's applicability filter), then calls the apply internals with `{anchor, url, title}` links, all inside one execution. Unlike the dropped two-HTTP-call design there is no network-level race window; a concurrent human save is handled by the apply path's own anchor re-location, degrading any stale anchor to an honest skip. When nothing is applicable, no apply work happens and the result says so plainly.

### D5: Application-Password availability is an explicit off-by-default setting

MCP Adapter's HTTP transport authenticates WordPress users with Application Passwords; WordPress core disables them on non-HTTPS sites, and `wp_is_application_passwords_available()` returns `false` on the dev-site today (verified live). A boolean setting (`agent_access`, off by default, changeable by `manage_options`) makes the plugin filter `wp_is_application_passwords_available` to `true` when enabled — necessary for DevKinsta, inert on HTTPS sites where core already returns `true`. The settings row carries the warning that the toggle is for local/development sites: enabling it on a public plain-HTTP site transmits credentials unencrypted, and the plugin must say so rather than silently decide.

### D6: Tests stub the Abilities API, not a transport

`tests/smoke.php` gains small stubs for `wp_register_ability()` / `wp_register_ability_category()` (capturing registrations into `$GLOBALS`, the suite's established mock pattern) plus vectors: exactly four abilities registered under the plugin category with the expected names, schemas, and public flags; zero registration when the API functions are absent; execute callbacks produce the same suggestion/skip results as the route handlers (driven with the existing engine mocks); permission callbacks deny non-editors and wrong-post actors; the listing filters drafts/private/password-protected posts and pages without gaps or repeats; interlink's applicability filter, merged report, and nothing-applied path. `php -l` on every changed PHP file closes the loop. No HTTP or protocol mocking exists anymore — there is no client code to mock.

### D7: Preserving the KNOWN-ISSUES.md invariants

The suggestion engine is not modified: `rank()`, `parse_rank_response()`, `find_anchor_span()` and its guard chain, `api_apply`, and `assets/admin.js` are untouched, so the PHP/JS mirror, timeout/token budgets, Qdrant retry loop, and Apply-All-Links guards remain byte-identical. Abilities call the same static methods the REST routes call, keeping parity automatic (specs: "Agent applies preserve the placement guards"). The new surface adds its own invariants, recorded as a new KNOWN-ISSUES entry (#9): abilities reuse engine internals (no forked apply path), exposure is opt-in via `meta.public` only, and the app-password opt-in is inert on HTTPS. No existing entry changes.

## Risks / Trade-offs

- [Sites with no WordPress MCP plugin: agents see no MCP tools] → the abilities stay registered and callable via the core abilities REST route; `readme.txt` documents the MCP Adapter install as the MCP step, and nothing else changes on such sites.
- [WordPress version differences for `meta.public` (REST exposure from 7.1 only; earlier needs the adapter)] → documented; the abilities are registered identically everywhere and the adapter path works across versions.
- [Ability error contract details on 7.1.5] → verify how a `WP_Error` returned from an execute callback renders to MCP clients during implementation; the spec only requires failures surface with messages, never silent success.
- [Agent with an Application Password can edit every post the user can] → this is exactly the editor-button capability granted; docs tell administrators to use a dedicated user/password with the minimum role and revoke it when done.
- [Long AI ranking calls can exceed harness MCP timeouts] → the server AI call stays 120 s (KNOWN-ISSUES #1); documented so users raise their harness tool timeout accordingly.

## Migration Plan

1. Ship the class changes; on any WordPress with the Abilities API the category and four abilities register immediately — no activation flag, no data migration.
2. For MCP on the dev-site: install MCP Adapter, enable **Allow Application Password access**, create an Application Password, then point OpenCode/DSH at the adapter's HTTP endpoint.
3. For HTTPS production: install the WordPress MCP plugin in use (MCP Adapter or the site's existing abilities plugin), create an Application Password, connect — no setting change needed.
4. Rollback: revert the class file; the abilities and the setting disappear with it. The `agent_access` option remaining in the database is inert once the filter is gone.

## Open Questions

- How a `WP_Error` from an ability execute callback is serialized to MCP clients on WordPress 7.1.5 — verify in the container during implementation; spec-neutral (either shape still surfaces the failure with its message).
- Whether the production abilities plugin (v1.12.x, Novamira REST surface v1) surfaces third-party core abilities through `novamira discover` — verify after deploy on a production site; the readme documents it as the expected path, and a negative finding changes only the docs.
