# KnowledgeSpark

Reader-focused **internal link suggestions** for WordPress, powered by Qdrant Cloud vector
search and optional AI ranking. Works two ways:

- **In the post editor** — a KnowledgeSpark button proposes internal links with a reason and a
  verified anchor phrase; Apply All Links inserts the placeable ones, saves, and reloads.
- **From AI agents** — the same actions are registered as WordPress abilities, so tools like
  OpenCode or DeepSeek Harness can interlink posts end-to-end over MCP without opening the editor.

---

## Requirements

| Requirement | Notes |
|---|---|
| WordPress 6.4+ | The editor flow works from 6.4; **the agent abilities need 6.9+** (core Abilities API) |
| PHP 7.4+ | |
| Qdrant Cloud account | Free tier works; **Cloud Inference must be enabled** on the cluster |
| Optional: OpenAI-compatible API | For AI-reviewed suggestions. Without it, semantic matches still work |
| For agent use: an MCP plugin | e.g. **MCP Adapter**, plus a WordPress **Application Password** |

## Installation

1. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
2. Choose `knowledgespark-0.0.2.zip`, click **Install Now**, then **Activate**.
3. Open **Settings → KnowledgeSpark**.

## Setup — connect Qdrant Cloud

1. Create a free cluster at [qdrant.tech](https://cloud.qdrant.tech) and copy its **HTTPS URL**
   and **API key**.
2. In the Qdrant console, enable **Cloud Inference** and select the default model
   `sentence-transformers/all-MiniLM-L6-v2` (384 dimensions — the plugin expects it).
3. Paste the URL and API key into Settings → KnowledgeSpark. Use a **dedicated collection**
   for this site; do not point the plugin at an unrelated existing collection.
4. Click **Save**, then **Test Connection** (it validates cluster and collection access).
5. Click **Index posts** and keep the page open until it finishes. Progress shows indexed /
   skipped / failed counts; failed batches are listed and can be retried by running again.

Indexing is incremental — unchanged posts are skipped on rerun, normal saves keep the saved
post in sync, and a scheduled task updates the index via WP-Cron.

## Setup — optional AI review (recommended)

Enter an **OpenAI-compatible** base URL, API key, and a model that supports chat completions
with JSON responses. The AI reviews candidate links for reader usefulness and writes the
reason and anchor text; without it you get semantic matches with a boilerplate reason.

Costs from your AI provider are separate from Qdrant's free tier. With AI review enabled,
each suggestion call is slower — see **Timeouts** below.

## Use in the editor

1. Edit a published (or draft) post.
2. Set the maximum suggestion count (default 5, max 10) and click **KnowledgeSpark**.
3. Review each suggestion: target post, URL, reason, and the exact anchor phrase.
4. Click **Apply All Links** to wrap each verified anchor in its link — the post is saved and
   the editor reloads. Or ignore the suggestions and place links manually.

Anchors are never forced: any anchor that would overlap an existing link, a block boundary,
or partial formatting is **skipped with a stated cause**, both in the editor and in agent
responses.

## Use from AI agents (MCP)

KnowledgeSpark registers four abilities on the WordPress **Abilities API** (core since 6.9):

| Ability | Input | Returns |
|---|---|---|
| `knowledgespark/find-suggestions` | `post_id` (required), `count` (1–10, default 5) | suggestions with target title, URL, reason, anchor |
| `knowledgespark/apply-all-links` | `post_id` (required), `links[]` each `{anchor, url, title}` | applied count, skipped list with causes |
| `knowledgespark/interlink` | `post_id` (required), `count` (1–10, default 5) | find + apply in one call; applied/skipped report |
| `knowledgespark/list-posts` | `cursor` (default 0), `limit` (1–100, default 50) | published posts with ID, title, URL, modified; cursor paging |

### Connecting an agent

1. Install a WordPress MCP plugin (e.g. **MCP Adapter** from the plugin directory).
2. Create an **Application Password** under **Users → Profile** for a dedicated user with the
   minimum role needed to edit posts. (On plain-HTTP dev sites, first enable
   **Settings → KnowledgeSpark → "Allow Application Password access"** — dev-only, credentials
   travel unencrypted on HTTP.)
3. Point any Streamable-HTTP MCP client at:

   ```
   https://your-site.com/wp-json/mcp/mcp-adapter-default-server
   ```

   Authenticate with Basic auth (`username:application-password`).

4. Agents discover abilities through the adapter's meta-tools
   (`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`,
   `mcp-adapter-execute-ability`) and run them by name, e.g.:

   ```bash
   curl -s -X POST "https://example.com/wp-json/mcp/mcp-adapter-default-server" \
     --user "user:xxxx xxxx xxxx xxxx xxxx xxxx" \
     -H "Content-Type: application/json" \
     -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"knowledgespark/interlink","parameters":{"post_id":123}}}}'
   ```

### Timeouts — read this before reporting a failure

With AI review enabled, a `find-suggestions` or `interlink` call legitimately takes
**30–90 seconds**. The server-side AI budget is 120 s. Set your MCP client's **per-tool
timeout to 180 seconds** (e.g. `toolCallTimeoutMs: 180000`), or the call will be aborted
client-side and look like a failure. A typical interlink workflow: `list-posts` → pick a
post → `interlink` → read the applied/skipped report.

## Troubleshooting

| Symptom | Meaning / fix |
|---|---|
| `AI ranking unavailable; showing semantic matches` | The AI call failed or timed out; you still got semantic results. Server budget is 120 s; a truncated AI answer is retried once automatically, and the message carries `finish_reason`/token diagnostics. |
| Qdrant `cURL error 60` (TLS mismatch) | Transient Qdrant/CloudFront edge issue. The plugin retries 3× with backoff; if it persists a few seconds, simply retry. |
| Anchors "skipped" in the report | Working as designed — placement guards refused an unsafe insertion (overlap with a link, tag boundary, or formatting). The cause is stated per link. |
| A post not suggested as a target | Targets must be published; deleted/unpublished/password-protected targets are filtered even if the cloud index is stale. |
| Scheduled sync failures | Stored per-post in `_ks_sync_error` meta; WP-Cron depends on site traffic — use a real cron runner for reliable sync. |
| Agents get 401 over plain HTTP | Enable the "Allow Application Password access" dev setting, or serve the site over HTTPS. |

## What data is sent where

- **Qdrant Cloud**: published post title, headings, excerpt text, post ID, URL, modified date
  during indexing; draft title, opening text, and up to 5 headings per query.
- **Optional AI API**: current article text (up to 8,000 chars), title, and up to 12 candidate
  titles/excerpts (up to 1,500 chars each) — only when suggestions are requested.
- API credentials are stored in the WordPress options database and never sent to the browser.
  Protect database backups accordingly.

## Uninstall

Deactivate and delete the plugin from the Plugins screen. This does **not** delete your Qdrant
collection or the stored settings — use **Reset collection** on the settings page (confirmable)
if you want the cloud data gone.

---

**License:** GPL-2.0-or-later · **Version:** 0.0.2
# knowledgespark
