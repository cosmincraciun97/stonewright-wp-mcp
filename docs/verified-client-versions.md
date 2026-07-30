# Verified client versions

Manual smoke evidence for Stonewright MCP clients. CI validates **parser/schema**
fixtures for generated configs; proprietary GUI clients cannot run in CI.

| Client | Config kind | Official CLI (if any) | Docs verified on | Manual smoke | Notes |
|---|---|---|---|---|---|
| Claude Code | CLI | `claude mcp add` | 2026-07-16 | pending | Prefer CLI add over hand-edited JSON |
| Claude Desktop | JSON | — | 2026-07-16 | pending | Restart Desktop after config change |
| Codex | TOML | `codex mcp add` | 2026-07-16 | pending | Use `/mcp` after reload |
| Cursor | JSON | — | 2026-07-16 | pending | Prefer user-level `~/.cursor/mcp.json` for secrets |
| VS Code (Copilot) | JSON | — | 2026-07-16 | pending | Prefer user MCP settings for secrets |
| Gemini CLI | JSON | `gemini mcp add` | 2026-07-16 | pending | |
| Windsurf | JSON | — | 2026-07-16 | pending | |
| Zed | JSON (`context_servers`) | — | 2026-07-16 | pending | |
| OpenCode | JSON | — | 2026-07-16 | pending | |
| Generic MCP | JSON HTTP/stdio | — | 2026-07-16 | pending | Streamable HTTP or companion stdio |

## Secret storage policy

- Default: **user-level / private** client config.
- Never recommend committing Application Passwords into project-tracked files.
- Catalog field `secret_storage: user-level` is enforced in `plugin/data/clients/*.json`.

## How to re-verify

1. Generate a fresh Application Password in Stonewright Setup.
2. Apply the client snippet (CLI add preferred when available).
3. Restart / reload the MCP client.
4. Confirm `stonewright-task-start` appears in the tool list.
5. Run in-admin **Verify connection** (loopback) and/or `npx @stonewright/companion doctor`.
6. Update the `Manual smoke` column and `verified_against_docs_on` in the client JSON.
