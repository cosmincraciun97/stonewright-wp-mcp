# Verified client versions

Manual smoke evidence for Stonewright MCP clients. CI validates **parser/schema**
fixtures for generated configs and deterministic OAuth matrix unit tests;
proprietary GUI clients cannot run full GUI certification in CI.

## Support tiers

| Tier | Meaning |
|---|---|
| **tier-1** | Primary certification target: Codex, Claude Code/Desktop, Cursor, VS Code / GitHub Copilot family. |
| **tier-2** | Supported with caveats; smoke on demand. |
| **compatible** | Config and discovery expected to work; not fully certified. |
| **experimental** | Best-effort only. |

**Certified** = completed [client-acceptance-template.md](releases/client-acceptance-template.md)
for a named version. **Compatible** = loads documented MCP config and can call
`stonewright-task-start` without a full matrix pass.

Catalog fields `support_tier` and `evidence` live in
`plugin/data/clients/*.json` (loaded by `ClientCatalog`).

| Client | Catalog slug | Tier | Config kind | Official CLI (if any) | Docs verified on | Manual smoke | Notes |
|---|---|---|---|---|---|---|---|
| Claude Code | `claude-code` | tier-1 | CLI | `claude mcp add` | 2026-07-16 | pending | Prefer CLI add; restart Claude Code after config change |
| Claude Desktop | `claude-desktop` | tier-1 | JSON | — | 2026-07-16 | pending | Restart Desktop after config change |
| Codex | `codex` | tier-1 | TOML | `codex mcp add` | 2026-07-16 | pending | Use `/mcp` after reload |
| Cursor | `cursor` | tier-1 | JSON | — | 2026-07-16 | pending | Prefer user-level `~/.cursor/mcp.json` for secrets |
| VS Code (Copilot) | `vscode-copilot` | tier-1 | JSON | — | 2026-07-16 | pending | Prefer user MCP settings for secrets |
| GitHub Copilot | `github-copilot` | tier-1 | JSON | — | 2026-07-16 | pending | Same family as VS Code MCP settings |
| Gemini CLI | `gemini-cli` | compatible | JSON | `gemini mcp add` | 2026-07-16 | pending | |
| Windsurf | `windsurf` | compatible | JSON | — | 2026-07-16 | pending | |
| Zed | `zed` | compatible | JSON (`context_servers`) | — | 2026-07-16 | pending | |
| OpenCode | `opencode` | compatible | JSON | — | 2026-07-16 | pending | |
| Generic MCP | `generic-mcp` | compatible | JSON HTTP/stdio | — | 2026-07-16 | pending | Streamable HTTP or companion stdio |

## Secret storage policy

- Default: **user-level / private** client config.
- Never recommend committing Application Passwords into project-tracked files.
- Catalog field `secret_storage: user-level` is enforced in `plugin/data/clients/*.json`.
- Paste-to-agent prompts must stay credential-free (placeholders only).

## How to re-verify

1. Use [client-acceptance-template.md](releases/client-acceptance-template.md).
2. Prefer OAuth for remote HTTP clients when the plugin is installed; otherwise
   Application Password + companion stdio.
3. Apply the client snippet (CLI add preferred when available). Keep secrets in
   private user-level config.
4. Perform the **client-specific restart / MCP session reload**.
5. Confirm `stonewright-task-start` appears in the tool list. Call it first.
6. Run in-admin **Verify connection** (loopback) and/or
   `npx @stonewright/companion doctor`.
7. Default startup profile is `essential-static` unless you intentionally set
   `bootstrap`, `low-tools`, or lock the env profile.
8. Update the `Manual smoke` column, catalog `evidence.*`, and
   `verified_against_docs_on` in the client JSON.
