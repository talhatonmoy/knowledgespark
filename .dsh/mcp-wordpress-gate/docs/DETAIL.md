# Project-scoped WordPress MCP

Registers the WordPress MCP server with DSH's MCP manager only for sessions whose
working directory is inside this plugin's project directory.

## Why this exists

DSH's MCP manager governs the **global plane**: a server in `config.servers` is
available in every session of every project and profile. This bundle gives the
server project scope instead. It contributes the server through
`ctx.mcpManager.register()`, which lasts exactly as long as the registering
effect, and registers that effect per Agent only when the Agent's working
directory is the configured project directory or a directory inside it.

The practical effects:

- Sessions in this project get the `mcp__my-wordpress-site__*` tools.
- Sessions in any other directory do not, and cannot call them.
- Disabling this bundle in **Plugins** withdraws the server everywhere without
  leaving a stale configuration entry behind.

## Configuration

The row in `cordis.patch.yml` carries these fields.

| Field | Default | Meaning |
|---|---|---|
| `serverName` | `my-wordpress-site` | Tool namespace, used as `mcp__<serverName>__<tool>` |
| `projectDir` | — | Gating directory; sessions at or below this path receive the server |
| `url` | — | Streamable HTTP endpoint of the MCP server |
| `authHeader` | `''` | Value of the `Authorization` header; accepts a `${credential:REF}` template |
| `toolCallTimeoutMs` | `60000` | Per-call timeout |

`url` and `projectDir` are required. A row missing either logs a warning and
registers nothing rather than failing activation.

## Transport

The endpoint is reached directly over Streamable HTTP. DSH's MCP client speaks
this transport natively, so no `npx mcp-remote` bridge process is spawned. Use a
stdio entry only for a server that offers nothing but stdio.

## Credentials

`authHeader` accepts the `${credential:REF}` template syntax, which the MCP
manager expands at connect time; the resolved value is redacted from
diagnostics. Store the header value under the referenced name so the secret
stays out of configuration files:

The composed value for WordPress Basic authentication is the literal
`Basic <base64-of-user:application-password>`, for example
`Basic YWRtaW46cGFzc3dvcmQ=`. Store the **whole** value, including the `Basic `
prefix, because the template replaces the entire header value.

## Known limitations

- Gating reads the Agent's working directory once, when the Agent is created. A
  session that changes its working directory mid-conversation keeps whichever
  decision was made at creation.
- The name must be unique across configured and registered servers; registering
  a name that is already configured throws.
- Because the server is registered, **Settings** cannot edit or override its
  entries. Change it through this bundle's `config`.
