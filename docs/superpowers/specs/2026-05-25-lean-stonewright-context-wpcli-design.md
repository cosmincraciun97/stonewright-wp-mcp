# Lean Stonewright Context and WP-CLI Design

## Goal

Stonewright should stop owning Figma ingestion and automated QA, while becoming stricter about loading site guidance before work and faster at WordPress implementation/debugging through a full WP-CLI integration.

## Scope

This change removes Figma and QA functionality completely from Stonewright:

- No Figma import, Figma-to-spec, Figma bridge, or Figma companion tools.
- No screenshot, pixel diff, accessibility, layout diff, Lighthouse, QA report, or QA fix-plan abilities.
- No Stonewright skill pack that asks the agent to run Figma or QA loops.
- No docs or generated ability matrix rows that advertise those removed abilities.

This change keeps the core builder surfaces:

- Elementor V3 and V4 abilities.
- Gutenberg and FSE abilities.
- DesignSpec validation and renderers for manually supplied specs.
- Widget intent, Elementor knowledge, memory, skills, sandbox, content, media, theme builder, menu, and system abilities.

## User Intent

The user will handle Figma with a separate MCP and will give human visual feedback instead of asking Stonewright to run automated QA. Stonewright must focus on safe WordPress building primitives, native Elementor/Gutenberg output, persistent site knowledge, and fast local diagnostics.

The user also wants Stonewright skills and memory to persist between sessions and be treated as mandatory context for every Stonewright task. When the user corrects the agent, or when the agent detects that it made a repeatable mistake, Stonewright should let the agent save that learning so the next session does not repeat the same error.

## Architecture

### 1. Complete Figma and QA Removal

Remove PHP abilities, tests, docs, contracts, companion endpoints, companion MCP tools, skill packs, and package dependencies whose only purpose is Figma ingestion or automated QA.

The companion package remains, but its purpose changes:

- MCP/HTTP proxying where still needed.
- WP-CLI execution.
- Future local WordPress helper services.

The companion no longer depends on Playwright, pixel diff, axe-core, Lighthouse, or a Figma token.

### 2. Mandatory Context Bootstrap

Add a new system ability:

```text
stonewright/context-bootstrap
```

Input:

```json
{
  "task": "User-facing task text",
  "surface": "elementor|gutenberg|fse|content|media|wp-cli|system|unknown",
  "intent": "read|write|delete|debug|plan|unknown"
}
```

Output:

```json
{
  "ok": true,
  "context_token": "swctx_...",
  "expires_at": "2026-05-25T12:00:00Z",
  "instructions": "...",
  "matched_skills": [
    {
      "slug": "stonewright-elementor-v3-builder",
      "title": "Elementor V3 Builder",
      "description": "..."
    }
  ],
  "matched_skill_playbooks": [
    {
      "slug": "stonewright-elementor-v3-builder",
      "content": "..."
    }
  ],
  "memory_entries": [
    {
      "id": 9,
      "type": "feedback",
      "scope": "elementor",
      "memory_key": "no-html-widgets",
      "name": "No Elementor HTML widgets by default",
      "value": "Use native Elementor widgets first."
    }
  ],
  "required_followups": [
    "Before Elementor widget selection, call stonewright/widget-intent-resolve.",
    "Do not use Elementor HTML widgets unless explicitly requested."
  ]
}
```

The bootstrap returns the full playbook content for matched enabled skills, not only the compact index. This addresses the failure mode where an agent sees a skill index but never loads the actual skill.

### 3. Context Token Gate

Write and destructive abilities must reject calls without a fresh `stonewright_context_token`.

The enforcement belongs in the central ability registration wrapper so every existing ability is covered without hand-editing each class. The wrapper should:

- Allow read-only abilities without a token.
- Require a token for write/delete/destructive abilities.
- Verify the token belongs to the current user, task hash, and ability name or ability category.
- Return a structured `WP_Error` explaining that the agent must call `stonewright/context-bootstrap` first.

The token must be short-lived and stored in a transient. The token is not a security substitute for WordPress capabilities, backup, or confirmation tokens; it is a workflow gate that forces the agent to load context.

### 4. Persistent Skills and Memory

Skills already live in `wp_stonewright_skills`; memory already lives in `wp_stonewright_memory`. This design keeps both database-backed and session-independent.

Persistence requirements:

- Built-in skills are seeded idempotently and can be refreshed on activation or version changes.
- User-created skills and memory entries are never overwritten by built-in seeding.
- Enabled skills and recent relevant memory are included in `context-bootstrap`.
- The existing knowledge bundle import/export continues to include instructions, memory, and skills.

### 5. Learning From Corrections

Add:

```text
stonewright/learning-record
```

Input:

```json
{
  "task": "What the agent was trying to do",
  "correction": "What the user corrected",
  "topic": "elementor-html-widget-policy",
  "applies_to": ["elementor", "design-spec"],
  "memory_type": "feedback",
  "skill_slug": "stonewright-elementor-v3-builder",
  "proposed_skill_patch": "Markdown section to append or replace"
}
```

Behavior:

- Saves a typed memory entry under a stable scope/key.
- Optionally updates a user skill or creates a companion user override skill.
- Does not silently edit built-in skill content in place unless the code explicitly supports safe built-in reseeding.
- Audits the learning event.

`AgentInstructions::default()` should instruct agents to call `stonewright/learning-record` after user corrections or after detecting that an existing memory/skill was violated.

### 6. Full WP-CLI Integration

Add WP-CLI support through the companion and PHP abilities.

Companion module:

```text
companion/src/wp-cli-runner.ts
```

Responsibilities:

- Locate `wp` from `STONEWRIGHT_WP_CLI_BIN` or PATH.
- Run commands with `execFile`, never through a shell string.
- Use configured WordPress root from `STONEWRIGHT_WP_PATH`.
- Return stdout, stderr, exit code, duration, parsed JSON when requested.
- Redact secrets in logs and audit payloads.
- Enforce timeout and max output size.

PHP abilities:

```text
stonewright/wp-cli-status
stonewright/wp-cli-run
stonewright/wp-cli-discover
```

`wp-cli-status` verifies binary/path availability and WordPress bootstrap.

`wp-cli-discover` returns available WP-CLI commands and plugin surfaces so agents can see whether Elementor, ACF, CPT UI, WooCommerce, and other plugin commands exist.

`wp-cli-run` accepts structured argv, not a raw shell command:

```json
{
  "argv": ["post", "list", "--post_type=page", "--format=json"],
  "operation": "read|write|destructive",
  "reason": "Debug page state before Elementor write",
  "expect_json": true,
  "affected_posts": [123],
  "confirmation_token": "swc_..."
}
```

Write support is intentionally full for useful WordPress work, including core, plugin, theme, option, post, meta, term, user, rewrite, cache, cron, menu, media, Elementor-related post/meta work, ACF post/meta/local JSON workflows, and CPT UI option workflows.

The only hard command denials are:

- `wp eval`
- `wp eval-file`
- `wp shell`

These violate the project rule against arbitrary PHP execution. Any command that shells out through PHP, runs arbitrary PHP source, or opens an interactive shell must stay blocked.

Safety envelope:

- Read commands require `Permissions::read()` or stronger depending on target.
- Write commands require `Permissions::manage_options()` or target-specific edit permissions.
- Destructive commands require production-safe confirmation token validation.
- Post/meta writes require `affected_posts`; snapshot each listed post before the command.
- If the command changes global site state, audit the exact argv and require an explicit `reason`.
- In `production-safe` mode, destructive and global writes require confirmation tokens.

### 7. Plugin-Specific WP-CLI Helpers

Add higher-level abilities after the raw runner exists:

```text
stonewright/wp-cli-plugin-surface
stonewright/acf-inspect
stonewright/acf-write-field-group
stonewright/cptui-inspect
stonewright/cptui-write-post-type
stonewright/cptui-write-taxonomy
stonewright/elementor-cli-inspect
stonewright/gutenberg-cli-inspect
```

These helpers use WP-CLI under the hood but present safer schemas for common WordPress builder work.

ACF support:

- Detect ACF or ACF Pro activation.
- Inspect field groups from `acf-field-group` and fields from `acf-field` posts.
- Support local JSON paths when configured.
- Write field groups through structured payloads, not arbitrary PHP.

CPT UI support:

- Detect Custom Post Type UI activation.
- Read and validate `cptui_post_types` and `cptui_taxonomies` options.
- Write post type and taxonomy definitions through structured schemas.

Elementor support:

- Inspect Elementor plugin state, post meta, templates, kits, and experiments through WP-CLI where helpful.
- Do not bypass existing Elementor abilities for normal page building.
- Use WP-CLI mainly for diagnostics, bulk reads, cache flushes, repair workflows, and controlled post/meta operations.

Gutenberg support:

- Inspect block content, registered post types, templates, options, and theme state through WP-CLI.
- Keep block rendering and mutation in the existing PHP abilities unless WP-CLI is the safer/faster path for a specific task.

## Security

This design changes the old companion rule. The new rule is:

The companion may run WP-CLI for local WordPress automation, including write commands, but it must never execute arbitrary shell strings, arbitrary PHP source, or interactive shells. All WP-CLI calls must pass through structured argv validation, WordPress permissions, audit logging, context-token gating for writes, confirmation tokens for destructive production-safe operations, and mandatory backups for declared affected posts.

This preserves:

- No arbitrary PHP execution.
- No `__return_true` permission callbacks.
- Backup before Elementor/theme-backed post writes.
- Confirmation tokens for destructive operations in production-safe mode.
- Auditability for every write.

## Documentation Updates

Update:

- `AGENTS.md`
- `README.md`
- `docs/architecture.md`
- `docs/companion.md`
- `docs/companion-contract.md`
- `docs/abilities.md`
- `docs/ability-truth-matrix.md`
- `docs/skills.md`
- `skills/README.md`

Remove:

- Figma references from Stonewright docs.
- QA references from Stonewright docs.
- Instructions telling agents to run screenshot/diff/QA before completion.

Add:

- Mandatory context bootstrap workflow.
- Persistent memory/skills expectations.
- Learning-record workflow.
- WP-CLI configuration and security model.

## Testing

PHP tests:

- `AgentInstructionsTest` no longer expects Figma or QA text.
- `AbilityRegistry` tests assert removed abilities are absent.
- `ContextBootstrapTest` verifies matched skills, memory, and token output.
- `ContextGateTest` verifies write abilities reject missing/expired context tokens.
- `LearningRecordTest` verifies memory and optional skill updates.
- `WpCliAbilitiesTest` verifies permission, token, denial, backup, and companion request payloads.
- Documentation truth-matrix tests are updated to the new ability set.

Node tests:

- Companion route/tool tests verify Figma/QA routes are gone.
- `wp-cli-runner.test.ts` verifies argv execution, no shell, timeout, JSON parsing, denylist, and redaction.
- HTTP/MCP tests verify WP-CLI endpoints/tools.

Verification commands:

```bash
cd plugin
composer test
composer phpstan
composer phpcs

cd ../companion
npm test
npm run build
```

## Rollout

1. Remove Figma and QA surfaces.
2. Add context bootstrap and write-token gate.
3. Add learning-record persistence.
4. Add companion WP-CLI runner and PHP bridge abilities.
5. Add plugin-specific helpers for Elementor, Gutenberg, ACF, and CPT UI.
6. Update docs, skills, and generated matrices.
7. Run PHP and Node verification.

## Decisions

The raw `stonewright/wp-cli-run` ability should be available only to administrators. Lower-privilege targeted helper abilities are out of scope for this change; broad WP-CLI write access belongs behind `manage_options`.

The first implementation should ship the central runner, plugin surface discovery, Elementor inspection, Gutenberg inspection, ACF inspect/write, and CPT UI inspect/write helpers. Additional plugin helpers can be added in separate focused changes while the raw runner remains available for expert admin workflows.
