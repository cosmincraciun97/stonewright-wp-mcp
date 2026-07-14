# Stonewright Onboarding

This guide is for site owners, maintainers, and agents using Stonewright to
edit WordPress sites through MCP.

## First Run

1. Install and activate the WordPress plugin.
2. Start the companion when you need WP-CLI-assisted work.
3. Create a WordPress Application Password for the MCP client user.
4. Add the Stonewright MCP server to your AI client.
5. In wp-admin, open **Stonewright > Configuration**, enable Stonewright, and
   choose the operating mode.
6. Reload or restart the AI client and confirm the tool list includes
   `stonewright-context-bootstrap`.
7. Smoke test the connection with `stonewright-ping`, then call
   `stonewright-task-start`.

Every real task should start with `stonewright-task-start`. The response
contains active instructions, relevant skills, persistent memory, workflow
followups, and the short-lived token needed by write abilities.

If `stonewright-context-bootstrap` is missing, the MCP server is not loaded yet.
Stop and fix the MCP config or reload the client before WordPress work. Local
agent skills, prompt snippets, repository files, private client config files,
scratch scripts such as `query-mcp.js` or `run-ability.js`, hand-rolled
JSON-RPC, helper JSON argument files such as `bootstrap-args.json`,
`cli_command.json`, or `get_structure.json`, direct companion shell launch
scripts such as `query-local-stonewright.js`, action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, plugin/companion
source-code spelunking to reverse-engineer tool schemas, and
`/wp-json/stonewright/v1/abilities/run` shell calls do not replace live
Stonewright MCP tools.
Do not recover by running `wp ...` in a normal shell or by switching to another
PHP adapter; use `stonewright-php-execute` and Stonewright's tokenized WP-CLI
MCP tools.

## Prompt Template

Use this shape when asking an AI client to work with Stonewright:

```text
Use Stonewright for this WordPress task.

Task:
- What should be created, edited, or audited.

Target:
- Site URL, post/page/template name or ID, and whether Gutenberg, Elementor,
  Full Site Editing, menus, media, or WP-CLI are allowed.

Design or content reference:
- Link to design, screenshot, brief, copy, assets, and any exact spacing,
  color, typography, or responsive requirements.

Workflow:
- Start with stonewright-task-start.
- If stonewright-context-bootstrap is not visible in the MCP tool list, stop and
  ask me to reload or fix the Stonewright MCP config.
- Do not inspect private client config files, create scratch helper scripts,
  create helper JSON argument files, launch the companion through ad hoc shell
  scripts, create action scripts, inspect plugin/companion source to
  reverse-engineer tool schemas, hand-roll JSON-RPC, call the REST runner from
  shell, or run shell `wp ...` commands as a Stonewright MCP workaround.
- Use native WordPress or Elementor abilities first.
- Use stonewright-php-execute for short full WordPress runtime snippets when a
  direct plugin API call is faster than many typed calls.
- Validate design specs before rendering.
- Snapshot before Elementor, template, global style, or theme-backed writes.
- Use production-safe confirmation tokens for destructive work.

Acceptance checks:
- Run desktop, tablet, and mobile checks.
- Verify there is no horizontal overflow.
- Verify no Elementor HTML widgets were used unless explicitly requested.
- Report changed abilities, tests, screenshots, and remaining risk.
```

## Visual Work

For visual builds, treat screenshots as part of the workflow:

- Measure the reference before writing: canvas size, section bounds, max
  widths, colors, type scale, spacing, and asset crop bounds.
- For pixel-matching work, treat `visual_build_gate` as blocking. Before the
  first write, prepare a token table, existing media audit, and section plan.
- Treat reference screenshots as the layout source of truth. Use design-tool
  structure for tokens, text, styles, assets, and hints, but do not copy broken
  grouping into WordPress when the visible design needs a cleaner native
  structure.
- Create a global-style plan before the first Elementor write. Decide which
  colors and type styles belong in the Elementor kit and which should stay
  local to the page.
- Audit existing WordPress media before uploading assets. Reuse matching
  filename, alt text, dimensions, and crop when the asset is already present.
- Use full-width outer sections, centered max-width inner containers, native
  rows/columns, and native widgets.
- Before full-page screenshots, scroll through the page or otherwise preload
  lazy-loaded media so missing assets are not mistaken for layout failures.
- Check every active Elementor or WordPress breakpoint used by the site on the
  logged-out public page. Admin bars and editor chrome do not count as
  responsive proof.
- Before signoff, list screenshot deltas for desktop, tablet, and mobile. Each
  delta must be fixed, accepted as a limitation, or blocked by missing approval.
- For long designs, capture multiple section reference screenshots and compare
  section-by-section before final full-page review.
- Use a separate browser MCP for screenshots and visual inspection. Stonewright
  does not include browser or Figma tools.

## Gutenberg And Block Themes

For Gutenberg-only or block-theme work, keep the client editing experience
native:

- Read the active theme, registered blocks, `theme.json`, templates, template
  parts, and patterns before writing.
- Plan reusable color, typography, spacing, layout, and block style tokens in
  `theme.json` before editing templates.
- Prefer core blocks, block supports, synced/unsynced patterns, and template
  parts before custom blocks or custom CSS.
- Use Stonewright Gutenberg/FSE abilities for writes and verify both the editor
  structure and the front end at desktop, tablet, and mobile sizes.
- Treat AI block/theme generators as prototype inspiration only; production
  output still needs valid block markup, readable theme files, and browser
  verification.

## Skills And Memory

Stonewright has two persistent teaching surfaces:

- **Skills** are reusable playbooks. Enable **Auto-match** for skills that
  should be selected from task descriptions. Enable **Prompt/command** for
  skills that should be exposed as explicit user entries.
- **Memory** stores site facts, project rules, and corrections. Use it for
  repeatable constraints such as "do not use HTML widgets" or "newsletter forms
  must use the native Elementor form widget."

If an agent makes a repeatable mistake or the owner corrects a workflow, record
that lesson with `stonewright-learning-record` so future tasks become faster
and more precise.

## Useful Ability Flow

For most tasks:

1. `stonewright-task-start`
2. Discovery abilities such as `stonewright-system-abilities-list`,
   `stonewright-site-info`, `stonewright-elementor-v3-status`, or
   `stonewright-blocks-list-registered`
3. Read the current target, such as page structure, blocks, menus, media, or
   theme settings
4. Plan the edit and choose native abilities
5. Write with the context token
6. Verify through readback, tests, browser screenshots, and audit logs

When using REST directly instead of a full MCP client, authenticated admins can
call `POST /wp-json/stonewright/v1/abilities/run` with:

```json
{
  "name": "stonewright/ping",
  "input": {}
}
```

Write abilities still require the `stonewright_context_token` returned by
`stonewright/task-start` (or a compatibility bootstrap). This runner is for deliberate REST clients and
tests, not a workaround when an MCP client failed to load the Stonewright tool
list.
