/**
 * Project-gated MCP registration.
 *
 * DSH's MCP manager governs the global plane: a configured server is available
 * in every session of every profile. This plugin instead contributes the server
 * through `ctx.mcpManager.register()` — a registration lives exactly as long as
 * the registering effect — and makes that effect conditional on the calling
 * Agent's working directory. A session whose directory is (or is inside) the
 * configured project gets the server; every other session does not.
 *
 * This is a Host-only bundle with no dependencies, so it deliberately does not
 * import a schema library: it declares no `Config` export and validates the
 * row's configuration itself.
 */

/**
 * Required services. `mcpManager` comes from `@deepseek-ai/dsh-mcp-manager`,
 * `workingDirectory` from `@deepseek-ai/dsh-working-directory`. Both are
 * declared so this plugin stays inactive in a composition that lacks them
 * instead of throwing during activation.
 */
export const inject = ['mcpManager', 'workingDirectory']

/**
 * Whether `cwd` is `projectDir` itself or a directory inside it.
 *
 * Compared on normalized absolute paths with a trailing separator, so a sibling
 * such as `/srv/site-other` never matches the project `/srv/site`.
 * @param {string} cwd - the session's effective absolute directory.
 * @param {string} projectDir - the configured project directory.
 * @returns {boolean} true when the session belongs to the project.
 */
function isInside(cwd, projectDir) {
  if (cwd === '' || projectDir === '') return false
  const strip = value => value.replace(/[/\\]+$/, '')
  const base = strip(projectDir)
  const target = strip(cwd)
  if (target === base) return true
  return target.startsWith(base + '/') || target.startsWith(base + '\\')
}

/**
 * Read one configuration field, rejecting a wrong type instead of coercing it.
 * @param {Record<string, unknown>} config - the row's configuration.
 * @param {string} key - field name.
 * @param {string} fallback - value used when the field is absent.
 * @returns {string} the resolved string value.
 */
function stringField(config, key, fallback) {
  const value = config[key]
  if (value === undefined) return fallback
  if (typeof value !== 'string') throw new TypeError(`mcp-wordpress-gate: ${key} must be a string`)
  return value
}

/**
 * @param {import('@deepseek-ai/cordis').Context} ctx - the plugin's context.
 * @param {Record<string, unknown>} config - the row's configuration.
 */
export function apply(ctx, config = {}) {
  const serverName = stringField(config, 'serverName', 'my-wordpress-site')
  const projectDir = stringField(config, 'projectDir', '')
  const url = stringField(config, 'url', '')
  const authHeader = stringField(config, 'authHeader', '')
  const toolCallTimeoutMs = config.toolCallTimeoutMs ?? 60_000

  if (url === '' || projectDir === '') {
    ctx.logger.warn(
      'mcp-wordpress-gate: "url" and "projectDir" are both required; registering nothing',
    )
    return
  }

  ctx.on('agent/created', ({ agent }) => {
    const cwd = ctx.workingDirectory.get(agent.session)
    if (!isInside(cwd, projectDir)) return

    const entry = {
      transport: 'streamable-http',
      url,
      toolCallTimeoutMs,
      ...authHeader === '' ? {} : { headers: { Authorization: authHeader } },
    }

    // Registered on `agent.ctx` so disposal follows the Agent. The disposer is
    // returned from an effect on that same context, so either the Agent being
    // disposed or this plugin unloading withdraws the server and its tools.
    agent.ctx.effect(() => {
      const dispose = ctx.mcpManager.register(serverName, entry)
      ctx.logger.info(`mcp-wordpress-gate: registered "${serverName}" for session in ${cwd}`)
      return () => {
        dispose()
        ctx.logger.info(`mcp-wordpress-gate: withdrew "${serverName}" for ${cwd}`)
      }
    }, 'mcp-wordpress-gate.registration')
  })
}
