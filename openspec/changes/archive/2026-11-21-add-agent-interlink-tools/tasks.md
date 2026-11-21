# Tasks

## 1. Ability registration

- [x] 1.1 In `includes/class-knowledgespark.php` (dense one-space style, PHP 7.4), hook `wp_abilities_api_categories_init` to register the `knowledgespark` ability category and `wp_abilities_api_init` to register the four abilities — `knowledgespark/find-suggestions`, `knowledgespark/apply-all-links`, `knowledgespark/interlink`, `knowledgespark/list-posts` — each with label, description, JSON-Schema input schema, output schema, execute and permission callbacks, and `meta.public => true` with WordPress-format annotations (design D2). Guard registration with `function_exists` so older WordPress is a silent no-op. Verify with `php -l includes/class-knowledgespark.php`.
- [x] 1.2 Add Abilities API stubs to `tests/smoke.php` (capture `wp_register_ability`/`wp_register_ability_category` into `$GLOBALS`, the suite's mock pattern) and vectors: exactly four abilities registered under the `knowledgespark` category with the expected names, required input properties, and public flag; category registered before abilities; and zero registrations when the API functions are absent. Verify with `php tests/smoke.php`.
- [x] 1.3 Live container probe on the dev-site: the four abilities and their category appear in the core abilities REST discovery (`GET /wp-json/wp-abilities/v1/abilities` and `/wp-abilities/v1/categories`), with public visibility. No credentials required. Verify with a `docker exec devkinsta_fpm php` request (or curl to `http://dev-site.local`).

## 2. Ability behaviors wired to the engine

- [x] 2.1 Implement the `find-suggestions` and `list-posts` execute callbacks: find reuses `api_suggest` semantics on the saved post (`post_id`, `count`, saved content when none supplied); list returns `{post_id, title, url, modified}` for published public posts, ascending-ID cursor paging modeled on the indexing route. Permission callbacks: `edit_post` on the target post / `edit_posts` for listing. Add smoke vectors: suggestion output parity with `api_suggest` (fields, count limit), listing excludes drafts/private/password-protected/posts and pages, cursor paging has no gaps or repeats, permission callbacks refuse non-editors. Verify with `php -l includes/class-knowledgespark.php` and `php tests/smoke.php`.
- [x] 2.2 Implement the `apply-all-links` execute callback reusing `api_apply` on the saved post: `links` in, inserted count and per-cause skip sentences out, post saved on insert. Add smoke vectors: apply parity with `api_apply` (per-cause skips: already-linked, protected-markup, not-in-draft, invalid URL, self-link), saved content updated, engine `WP_Error` surfaces as the ability's failure (never silent success), and permission refusal for actors who cannot edit the post. Verify with `php tests/smoke.php`.
- [x] 2.3 Implement the `interlink` execute callback (design D4): compose suggest → applicability filter (non-empty anchor + valid `http(s)://` URL, the editor button's filter) → apply in one execution, merging the inserted/skipped report; nothing-applicable performs no apply and says so; stale anchors under a concurrent save degrade to honest per-cause skips. Add smoke vectors for all four paths. Verify with `php tests/smoke.php`.

## 3. Agent authentication on non-HTTPS sites

- [x] 3.1 Add the `agent_access` setting (boolean, default off) to `includes/class-knowledgespark.php`: settings defaults, `sanitize()` handling, a settings-page row labeled "Allow Application Password access" with the local-development warning, a static `app_passwords_available($available)` method returning true only when the setting is on, and registration on the `wp_is_application_passwords_available` filter from `boot()`. Verify with `php -l includes/class-knowledgespark.php`.
- [x] 3.2 Add smoke vectors: default-off leaves the core value untouched (`false` in, `false` out), setting-on yields `true`, and the settings page renders the row with its warning. Verify with `php tests/smoke.php`.
- [x] 3.3 Live container probe (KNOWN-ISSUES live-check convention): `wp_is_application_passwords_available()` returns `false` with the setting off and `true` after enabling it (including the per-user variant — design open question). Confirm the toggle's result without creating credentials, then restore the setting to off. Verify with a `docker exec devkinsta_fpm php` probe.

## 4. MCP exposure verification and harness wiring

- [x] 4.1 Live end-to-end probe on the dev-site (DB-proof standard from KNOWN-ISSUES #8): install MCP Adapter on the dev-site, enable the opt-in setting, and with a dedicated Application Password created by the administrator, verify over HTTP against the adapter endpoint — MCP `initialize` handshake, discovery lists the KnowledgeSpark abilities/tools, and executing `find-suggestions` plus `interlink` on a throwaway saved post inserts and saves links (prove `post_content` changed: md5 + links present in the database via container probe). Delete the throwaway post and revoke the Application Password afterward. Verify nothing in the probe output contains the password.
- [x] 4.2 Update `readme.txt`: an "Agent tools via WordPress MCP" section — the four abilities, installing a WordPress MCP plugin (MCP Adapter) for MCP exposure, creating an Application Password, the non-HTTPS opt-in and its warning, connection snippets for OpenCode and DSH pointing at the adapter's endpoint, the Novamira `discover`/`run` path on sites running the abilities plugin (flagged as production verification), and the AI latency note — plus a changelog entry. Verify each documented name, flag, and endpoint against the actual registrations from task 1 and the adapter's real URL, and that HTTPS sites need no setting change.
- [x] 4.3 Add a new entry #9 to `KNOWN-ISSUES.md` recording the agent-surface invariants (abilities reuse engine internals so guard parity is automatic; exposure is opt-in via `meta.public` only; the app-password opt-in is inert on HTTPS; no engine/matcher edits in this change), leaving every existing entry byte-identical. Verify the run-guards header still lists the correct commands and check counts.

## 5. Integration and final checks

- [x] 5.1 Run the project checks and require all green: `php -l` on every changed PHP file (`includes/class-knowledgespark.php`, `tests/smoke.php`), `node --check assets/admin.js` (JS untouched — must still pass unchanged), and `php tests/smoke.php`.

## Workflow follow-up

- Archive the change after review; per the archive guidance, confirm `readme.txt` (changelog, limitations) and `KNOWN-ISSUES.md` reflect the change first.
- After deploy on a production site, verify `novamira discover` lists the KnowledgeSpark abilities (design open question; a negative finding updates the readme only).
