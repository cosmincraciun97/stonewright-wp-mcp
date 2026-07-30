# Tool surface recovery runbook

When an AI client cannot see or call Stonewright tools mid-session, walk this table top-down. `stonewright-task-start` is the canonical first call of every task; everything below assumes it ran at least once.

| Symptom | Meaning | Action |
| --- | --- | --- |
| `-32602 Tool <name> disabled` | The companion disabled a proxied handle during a profile refresh. | Call `stonewright-client-surface-check`. If the tool shows in `live_enabled_tool_names`, re-list tools (`tools/list`) because the client cache is stale. If it does not, call `stonewright-tool-profile` with `action=activate` and the profile you need, then re-list. Gateway tools (`task-start`, `tool-profile`, `php-execute`, `wordpress-mcp-status`) are never disabled; if one of those errors, restart the MCP client. |
| `session_profile_applied: false` with reason `surface_full_already_exposes_all_tools` | Not an error. The site surface is `full`; every tool is already visible server-side. | Continue. If the client still misses tools, it is probably the client cap; see “Capped clients” below. |
| `session_profile_applied: false` with reason `missing_or_invalid_mcp_session_id_header` | The transport did not send `Mcp-Session-Id`, so per-session expansion cannot work. | Use plugin-proxy mode through the companion, which forwards the header, or ask the user to set the site surface to `full` for the task. |
| `degraded: true` with `truncated_tools` in a tool-profile response | The profile has more tools than `max_tools`; the named tail was omitted from the returned list. | Re-run `stonewright-tool-profile` with `action=resolve` and a higher `max_tools`, or raise the companion/client cap. Do not assume an unlisted tool is callable by a strict client. |
| Tools listed but stale (client shows the cold-start set) | The client ignored `notifications/tools/list_changed`, or an operator changed the site surface mid-session. | Trigger a manual re-list in the client. The `surface_revision` field changes when the surface changes; if it is newer but the list is unchanged, re-list or restart. Follow any `re_list_instruction`. |
| Elementor write blocked: `architecture-ambiguous` | Elementor 4 runtime is active and the plugin could not detect the target document architecture. | Re-run `stonewright-task-start` with `post_id` set to the post you will edit. Only pass `target_architecture=v3` explicitly when creating a new V3 document. |
| `stonewright_php_parse_error` from php-execute | The snippet broke in transport, commonly through shell heredoc quoting. | Resend the code as a plain multi-line JSON string. No heredoc markers and no base64. |

## Capped clients

- Set `STONEWRIGHT_MCP_MAX_TOOLS=50` in the companion environment so the companion and client agree on the cap and the plugin’s write-critical-first ordering decides what survives.
- Keep exactly one Stonewright server entry in client configuration. Parallel plugin-proxy and Direct entries double-register overlapping tools and consume the cap.
- `STONEWRIGHT_MCP_TOOL_PROFILE` is only the startup profile. Mid-session expansion happens through `stonewright-task-start` and `stonewright-tool-profile`; set `STONEWRIGHT_MCP_TOOL_PROFILE_LOCK=1` only when you explicitly want to freeze the environment-selected startup surface.

## Verify the live surface

1. Call `stonewright-wordpress-mcp-status`. Read `surface_revision`, `live_tool_profile`, `live_enabled_tool_count`, and `last_refresh_at`; `startup_*` fields are the cold-start snapshot.
2. Call `stonewright-client-surface-check` to compare the expected tool with live registration and get a concrete diagnosis.
3. Call `stonewright-tool-profile` with `action=resolve` and the target profile to read the plugin’s authoritative ordered list, including `truncated_tools`.

Plugin-direct clients cannot receive server-push from the vendored adapter. See [MCP adapter list-changed limit](../knowledge/notes/mcp-adapter-listchanged-limit.md).
