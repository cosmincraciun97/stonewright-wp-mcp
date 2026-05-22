# Stonewright Novamira-style Admin — Design Spec

**Date:** 2026-05-21
**Author:** Stonewright maintainers
**Status:** Approved for implementation

## Goal

Bring Stonewright admin surface to parity with Novamira's UX (Configuration, AI Abilities, Sandbox, Memory & Instructions, Connect Your AI Client) while honoring Stonewright's hard security rules (NO arbitrary PHP code execution, backup before write, confirmation tokens in production-safe mode, clean-room).

## Non-Goals

- Do not implement Novamira's "Execute PHP" tool. Hard rule violation.
- Do not auto-load sandbox files as PHP. Sandbox stores drafts; activation requires explicit admin click + confirmation token, then file is copied into `mu-plugins/` after static analysis.
- Do not copy Novamira code, prompts, IDs, schemas, or text.

## Admin Menu Structure

Top-level **Stonewright** menu (icon: `dashicons-hammer`, position 76). Sub-pages:

| Slug | Title | Purpose |
|---|---|---|
| `stonewright` | Configuration | Master enable, mode, app password, connect AI client |
| `stonewright-abilities` | AI Abilities | List/filter/toggle registered abilities |
| `stonewright-sandbox` | Sandbox | File manager for `wp-content/stonewright-sandbox/` |
| `stonewright-memory` | Memory & Instructions | Custom instructions + categorized memory CRUD |
| `stonewright-audit` | Audit Log | Existing audit log viewer (move under menu) |
| `stonewright-license` | License | Placeholder, links to upgrade flow |

## Page 1: Configuration (3 numbered cards)

**Card 1 — Enable AI Abilities**
- Master toggle: `stonewright_enabled` (default false). When off, MCP server rejects all tool calls except `ping`.
- Mode selector: development / staging / production-safe (existing).
- Production warning banner: shown when `wp_get_environment_type() === 'production'` AND `stonewright_enabled === true`.
- Existing fields (figma token, companion URL, companion token, V4 atomic) stay below.

**Card 2 — Application Password**
- Inline button: "Generate application password" — calls `WP_Application_Passwords::create_new_application_password` with name "Stonewright" (or user-supplied).
- Display generated password ONCE.
- Manage existing list (revoke).

**Card 3 — Connect Your AI Client**
- Server URL display: `{site_url}/wp-json/mcp/stonewright`
- Tab strip with 15 clients: Claude Code, Claude Desktop, Codex, Cursor, VS Code, GitHub Copilot, Windsurf, Cline, Gemini CLI, Roo Code, Amazon Q, Zed, Kilo Code, OpenCode, Antigravity
- Each tab shows the JSON snippet (or CLI command) the user copies into that client's config file.
- All snippets use the universal `mcpServers` block with `npx -y @automattic/mcp-wordpress-remote@latest` as transport.
- Per-client config file path shown above each snippet (e.g., `~/.config/Claude/claude_desktop_config.json` for Claude Desktop).
- Copy buttons for each snippet.
- "Paste-to-agent" prompt block: natural language instruction the user pastes into a chat to have the agent self-configure.

## Page 2: AI Abilities

- Table: Name | Category | Description | Enabled
- Per-row enable/disable toggle (writes `stonewright_disabled_abilities` option as array of ability names).
- Per-category bulk enable/disable.
- Search filter.
- "Master toggle is OFF" banner when global toggle disabled.
- Read-only when user lacks `manage_options`.

Disabled abilities returned by `AbilityRegistry::list` get filtered before `wp_register_ability` at boot.

## Page 3: Sandbox

**File manager UI:**
- Table: Filename | Status (Active/Disabled/Draft) | Last Modified | Actions
- Actions: Edit | Disable | Enable | Activate (requires token in production-safe) | Deactivate | Delete
- Inline editor (textarea or CodeMirror-style) for draft files.
- New file button.

**Backend storage:**
- Draft files live in `wp-content/stonewright-sandbox/`. NEVER auto-loaded.
- "Active" = file copied as `wp-content/mu-plugins/stonewright-sandbox-{slug}.php` after passing static analysis.
- Static analysis blocks the following dangerous tokens (as identifiers or function calls):
  - PHP `eval` keyword
  - `assert` with string argument
  - `create_function`
  - short-echo opening tag
  - backtick execution operator
  - `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `pcntl_exec`
  - `include`/`require` of remote URLs (any `http://` or `https://` argument)
  - `file_put_contents` to arbitrary paths outside the plugin scope
- Activation = `Activate` button → confirmation modal → if production-safe, requires confirmation token from `stonewright/security-issue-confirmation-token`.
- All actions audit-logged.

**Crash recovery:**
- `register_shutdown_function` checks for fatal in last load; if fatal trace came from `mu-plugins/stonewright-sandbox-*.php`, file gets renamed to `.crashed`, error logged, admin notice displayed.

## Page 4: Memory & Instructions

**Custom Instructions section:**
- Textarea (max 4000 chars).
- On/Off toggle (`stonewright_custom_instructions_enabled`).
- Save button.
- Content prepended to MCP server description and to `stonewright/abilities-list` ability output.

**Memory section:**
- Tabs: All | User | Feedback | Project | Reference
- Table: Name | Type | Created | Updated | Actions (Edit, Delete)
- Add new button → modal with Name, Type select, Content textarea.
- On/Off toggle for memory MCP abilities (`stonewright_memory_enabled`).

## Data Model Changes

### `Memory` table — add `type` column

```sql
ALTER TABLE {prefix}stonewright_memory
  ADD COLUMN type VARCHAR(32) NOT NULL DEFAULT 'generic' AFTER scope,
  ADD COLUMN name VARCHAR(190) NOT NULL DEFAULT '' AFTER type,
  ADD INDEX type_idx (type);
```

`type` enum: `user | feedback | project | reference | generic`

### New options

```
stonewright_enabled                       bool   false
stonewright_disabled_abilities            array  []
stonewright_custom_instructions           string ""
stonewright_custom_instructions_enabled   bool   true
stonewright_memory_enabled                bool   true
```

## New MCP Abilities

| Name | Category | Description |
|---|---|---|
| `stonewright/memory-list` | memory | List memory entries optionally filtered by type |
| `stonewright/memory-read` | memory | Read one memory entry by id or scope+key |
| `stonewright/memory-write` | memory | Create or update a memory entry with type |
| `stonewright/memory-delete` | memory | Delete a memory entry |
| `stonewright/instructions-get` | system | Returns the custom instructions block |
| `stonewright/abilities-list` | system | Lists all registered abilities visible to caller |
| `stonewright/sandbox-list-files` | sandbox | List files in `stonewright-sandbox/` |
| `stonewright/sandbox-read-file` | sandbox | Read a sandbox draft file |
| `stonewright/sandbox-write-file` | sandbox | Create/overwrite a draft file (no auto-load) |
| `stonewright/sandbox-edit-file` | sandbox | Targeted string replacement on a draft |
| `stonewright/sandbox-delete-file` | sandbox | Remove a draft file |
| `stonewright/sandbox-disable-file` | sandbox | Mark an active file disabled (move out of mu-plugins) |
| `stonewright/sandbox-enable-file` | sandbox | Re-activate a disabled file |
| `stonewright/sandbox-activate-file` | sandbox | Move draft to mu-plugins after static analysis. Destructive — token required in production-safe |

New category: `memory`, `sandbox`, `system`

## New REST Routes

```
GET  /stonewright/v1/abilities                  list registered abilities
POST /stonewright/v1/abilities/toggle           enable/disable an ability
GET  /stonewright/v1/memory                     list, ?type=...
POST /stonewright/v1/memory                     create/update
DELETE /stonewright/v1/memory/{id}              delete
GET  /stonewright/v1/instructions               read custom instructions
POST /stonewright/v1/instructions               write custom instructions
GET  /stonewright/v1/sandbox/files              list
GET  /stonewright/v1/sandbox/files/{name}       read
POST /stonewright/v1/sandbox/files              write
PUT  /stonewright/v1/sandbox/files/{name}       edit
DELETE /stonewright/v1/sandbox/files/{name}     delete
POST /stonewright/v1/sandbox/files/{name}/activate   activate (token required)
POST /stonewright/v1/sandbox/files/{name}/disable
POST /stonewright/v1/app-password               generate
GET  /stonewright/v1/connect-config             returns per-client JSON snippets
```

All routes: `permission_callback => Permissions::manage_options`. Sandbox routes additionally check the master toggle.

## Admin Bar Indicator

When `stonewright_enabled === true`:
- Add admin bar node: red badge "Stonewright ON" linking to Configuration page.

## Files to Create

```
plugin/includes/Admin/ConfigurationPage.php
plugin/includes/Admin/AbilitiesPage.php
plugin/includes/Admin/SandboxPage.php
plugin/includes/Admin/MemoryInstructionsPage.php
plugin/includes/Admin/AuditLogPage.php
plugin/includes/Admin/AdminBarIndicator.php
plugin/includes/Admin/ConnectClientConfig.php
plugin/includes/Sandbox/SandboxFiles.php
plugin/includes/Sandbox/StaticGuard.php
plugin/includes/Sandbox/CrashRecovery.php
plugin/includes/Abilities/Memory/{List,Read,Write,Delete}.php
plugin/includes/Abilities/System/{InstructionsGet,AbilitiesList}.php
plugin/includes/Abilities/Sandbox/{ListFiles,ReadFile,WriteFile,EditFile,DeleteFile,DisableFile,EnableFile,ActivateFile}.php
docs/admin/configuration.md
docs/admin/abilities.md
docs/admin/sandbox.md
docs/admin/memory.md
docs/admin/connect-clients.md
```

## Files to Modify

```
plugin/includes/Admin/SettingsPage.php          → become ConfigurationPage delegator OR rename
plugin/includes/Core/PluginRegistration.php     → register new admin pages + admin bar
plugin/includes/Core/AbilityRegistry.php        → filter disabled, add new abilities
plugin/includes/Core/RestRoutes.php             → add new routes
plugin/includes/Memory/Memory.php               → schema migration + type support
plugin/includes/Core/ServerRegistration.php     → inject custom instructions into server description
plugin/stonewright.php                          → register Sandbox/* autoload
plugin/README.md                                → update screenshots/feature list
```

## Tests

```
plugin/tests/Unit/MemoryTest.php
plugin/tests/Unit/SandboxStaticGuardTest.php
plugin/tests/Unit/ConnectClientConfigTest.php
plugin/tests/Unit/AbilityRegistryFilterTest.php
```

## Implementation Waves

**Wave 1 (foundation, sequential):**
1. Memory schema + type column + helper methods
2. Sandbox subsystem (SandboxFiles + StaticGuard + CrashRecovery classes)

**Wave 2 (UI pages, parallel — disjoint files):**
3a. ConfigurationPage redesign + AdminBar
3b. AbilitiesPage + REST + registry filter
3c. SandboxPage + REST
3d. MemoryInstructionsPage + REST

**Wave 3 (MCP abilities, parallel — disjoint files):**
4a. Memory abilities (4 classes)
4b. System abilities (Instructions, AbilitiesList)
4c. Sandbox abilities (8 classes)

**Wave 4 (integration):**
5. ServerRegistration instructions injection + ConnectClientConfig generator (15 clients)

**Wave 5 (polish):**
6. Tests (4 new test files) + Docs (5 admin guides)

**Wave 6:** Final review subagent.

## Risk + Open Questions

- Sandbox activation as `mu-plugins/*.php` IS PHP execution by definition once activated. Mitigation: static analysis gate + confirmation token + audit log + crash auto-disable. This is the maximum safety we can give while honoring the user's "100% functional sandbox" ask. If user prefers stricter posture, sandbox stays drafts-only (no activation path).
- Application Password generation requires the current logged-in user. Cannot generate for other users without their cooperation. Documented.
- Multi-client JSON snippets need testing against real MCP clients in a future QA cycle.

## Acceptance Criteria

- [ ] All 5 admin pages render with correct WP nonces + cap checks.
- [ ] Memory type column migrated; existing rows backfilled to `generic`.
- [ ] 14 new MCP abilities register and pass `composer test`.
- [ ] AbilityRegistry filter respects `stonewright_disabled_abilities`.
- [ ] Static guard blocks the documented bad patterns in `StaticGuardTest`.
- [ ] Custom instructions appear in MCP server description on `tools/list` response.
- [ ] Connect snippet generator emits valid JSON for all 15 clients.
- [ ] Admin bar badge appears only when enabled === true.
- [ ] `composer phpcs` + `composer phpstan` + `composer test` all pass.
