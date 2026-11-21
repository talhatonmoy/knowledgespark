=== KnowledgeSpark ===
Contributors: knowledgespark
Tags: internal links, editorial, qdrant, ai
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 0.0.3
License: GPLv2 or later

Reader-focused internal link suggestions in the WordPress post editor. The same suggest and apply-all actions are also registered as WordPress abilities, so AI agents can interlink posts through WordPress MCP plugins such as MCP Adapter.

== Description ==
KnowledgeSpark searches a dedicated Qdrant Cloud collection and optionally uses an OpenAI-compatible chat API to review candidates for reader usefulness. Suggestions include a target, reason, and verified existing anchor/quote when available. Apply All Links (after your confirmation) wraps each suggested anchor phrase in its target link, saves the post, and reloads the editor. Otherwise, place links manually.

Version 0.0.1 supports public published posts as link targets, Gutenberg and the Classic Editor, an adjustable result count (default five, maximum ten), Apply All Links with confirmation, automatic save, and editor reload, connection testing, incremental indexing, and scheduled updates through WordPress cron. Current unsaved draft text is used for suggestions.

== Installation ==
1. Activate KnowledgeSpark in WordPress Plugins.
2. Open Settings > KnowledgeSpark.
3. Enter your Qdrant Cloud HTTPS cluster URL and API key. Use a dedicated collection for this site; do not reuse an unrelated collection.
4. Enable Cloud Inference in the cluster console. Verify the selected model is available and labelled free. The default sentence-transformers/all-MiniLM-L6-v2 model requires 384 dimensions.
5. Optionally configure an OpenAI-compatible API base URL, API key, and model supporting chat completions with JSON object responses. AI costs are separate from Qdrant's free tier. Without AI, semantic matches remain available.
6. Save settings, test the connection, and index published posts. Indexing runs on the server in batches of 100 posts, so you can close the page; reopen it later to watch progress or stop the run. Errors are shown; rerun indexing to retry, since unchanged posts are skipped.
7. Edit a post, set the maximum recommendation count, and click KnowledgeSpark. Review the proposed destination, reason, and placement, then click Apply All Links to insert, save, and reload, or add links yourself with the normal editor link tool.

The server needs outbound HTTPS access to the configured services. API credentials are stored in the WordPress options database and are never sent to the browser. Protect database backups accordingly. Only administrators can configure or index; suggestions require permission to edit the source post.

== Agent tools via WordPress MCP ==

KnowledgeSpark registers four WordPress abilities on the core Abilities API (part of WordPress core since 6.9), so AI agents such as OpenCode or DeepSeek can interlink posts end-to-end without opening the editor:

- find suggestions (`knowledgespark/find-suggestions`) — the KnowledgeSpark button: suggestions for a saved post with target title, URL, reason, and a verified anchor phrase.
- apply all links (`knowledgespark/apply-all-links`) — the Apply All Links button: inserts the given links into a saved post, with every placement guard and per-cause skip report of the editor flow.
- interlink (`knowledgespark/interlink`) — both buttons in one call: find suggestions, keep the applicable ones (non-empty anchor and valid URL, the editor button's filter), apply them, and report inserted and skipped links with their causes.
- list published posts (`knowledgespark/list-posts`) — published posts with ID, title, URL, and modified time, cursor-paged, so an agent can pick targets.

Setup:

1. Install a WordPress MCP plugin on the site. MCP Adapter (the official one, from the plugin directory) exposes the abilities over MCP; point any MCP client that supports the Streamable HTTP transport at `/wp-json/mcp/mcp-adapter-default-server`. With the default server, agents discover abilities through its meta-tools (`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability`) and execute them by name.
2. Authenticate as a WordPress user with permission to edit the target posts, using an Application Password created under Users → Profile. Use a dedicated user with the minimum role, and revoke the password when agent access is no longer needed.
3. On HTTPS sites Application Passwords work out of the box. On plain-HTTP sites (local development such as DevKinsta), enable Settings → KnowledgeSpark → "Allow Application Password access" first; it exists only for local or development sites because credentials travel unencrypted over HTTP.
4. Example call:

curl -s -X POST "https://example.com/wp-json/mcp/mcp-adapter-default-server" --user "user:application-password" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"knowledgespark/interlink","parameters":{"post_id":123}}}}'

OpenCode connects to the same URL as a remote MCP server (type "remote" in its MCP configuration); DeepSeek Harness connects through its MCP connection settings using the HTTP transport and the same Basic-auth credentials. Sites running the abilities plugin behind the Novamira CLI should surface the same abilities through `novamira discover`/`run`; verify on your production site after deploying.

Suggestion calls run the same engine as the editor, including the optional AI review, so allow roughly 30–90 seconds per find-suggestions or interlink call and raise the client's tool timeout accordingly. Every agent apply passes through the same placement guards as the editor: unplaceable anchors are skipped with their cause, never forced.

== External services ==
Qdrant Cloud: sends compact published post title, headings, excerpt text, post ID, URL, and modification date during indexing. Queries contain current draft title, opening text, and up to five headings. Requires an account, cluster, and API key.
Documentation: https://qdrant.tech/documentation/inference/cloud-inference/
Privacy: https://qdrant.tech/legal/privacy-policy/

Optional AI API: sends current article text (up to 8,000 characters), title, and up to 12 candidate titles and leading excerpts (up to 1,500 characters each) when the editor explicitly requests suggestions. The configured provider's pricing, terms, and privacy policy apply. Default endpoint: https://api.openai.com/v1
Default provider terms: https://openai.com/policies/terms-of-use/
Default provider privacy: https://openai.com/policies/privacy-policy/

== Limitations ==
This is an early MVP. Retrieval uses one compact vector per post and heading queries, not full section indexing. Model input windows may truncate the compact representation. AI evaluates supplied excerpts, not every candidate's complete article. Always review the target before linking. No numerical confidence scores, feedback learning, or persistent recommendation cache are included. Apply All Links only wraps the exact verified anchor phrase in the target link; anchors that would overlap a block, a link, or partial formatting are skipped and reported, never forced.

Only the post post-type is supported. Noindex metadata from third-party SEO plugins is not inspected. Existing links are detected via WordPress URL resolution; custom redirect aliases may not resolve. Deleted, unpublished, and password-protected targets are also checked against WordPress at recommendation time, even if the cloud index is stale.

WP-Cron drives the background indexing job and per-post save synchronization. Each indexing run embeds posts in batches of 100 — one payload read plus one Qdrant Cloud Inference upsert per batch, about two requests per 100 posts instead of two per post — and stores its progress (cursor, counts, recent errors) in the database, so closing the settings page never stops it. Reopening the page shows live progress and a Stop control; starting indexing again after completion or a stop re-verifies everything and skips unchanged posts. Batches that keep failing after the automatic retries are recorded with their post-ID range and cause while the run continues; rerun indexing to retry them, and failed posts also store the error under the post's _ks_sync_error metadata. On quiet sites the run can pause between batches until the next request: WordPress triggers its cron from ordinary page requests (including this page's progress polling), and a system cron entry (for example `wget -q -O - https://example.com/wp-cron.php` every minute) keeps long runs moving regardless. Deleting the plugin does not delete your cloud collection or settings. Reset collection stops a running job and deletes the configured collection; it must be confirmed. Qdrant requests retry automatically twice (with backoff) on transient TLS or network errors; AI responses that come back unparseable (typically token truncation from the model's internal reasoning) are retried once, and failures report finish reason and token counts for diagnosis.

Changing embedding models requires a new collection and full indexing, even if dimensions match. Free-tier quotas and free model availability must be verified in Qdrant's console. Test connection validates cluster/collection access; indexing verifies inference access. No live external account was configured during development.

== Development checks ==
Read KNOWN-ISSUES.md before changing the engine — it records the critical invariants (AI timeout and token budget, Qdrant transient-failure retry, the PHP/JavaScript span-matcher mirror, Apply-All-Links safety guards) and the tests that enforce them. Run php tests/smoke.php for standalone mocked regression checks. Run php -l on PHP files and node --check assets/admin.js for syntax validation. Live WordPress editor and external service integration still require credentials and a running site.

== Changelog ==
= 0.0.3 =
* Background batch indexing: posts are embedded into Qdrant in batches of 100 (one payload read plus one server-side inference upsert per batch — about two requests per 100 posts instead of two per post), as a resumable background job driven by WP-Cron. The settings page starts the job and shows live progress; you can close it at any time, stop the run, and rerun to retry failures while unchanged posts are skipped. New status and stop REST routes; a job-state option records the cursor, counts, and recent errors, and a failed batch records its post-ID range while the run continues. Reset now stops a running job before deleting the collection. No change to suggestions, the placement guards, or per-post save synchronization.

= 0.0.2 =
* Agent tools: find suggestions, apply all links, interlink, and list posts are registered as WordPress abilities and exposed to AI agents through WordPress MCP plugins such as MCP Adapter (Application Password authentication; explicit opt-in setting for plain-HTTP development sites). No change to the editor flow, the suggestion engine, or the placement guards.

= 0.0.1 =
Initial MVP: settings, Qdrant Cloud Inference indexing, editorial suggestions, optional AI review, and manual link placement.
