# Stonewright Onboarding

This guide is for site owners, maintainers, and agents using Stonewright to
edit WordPress sites through MCP.

## First Run

1. Install and activate the WordPress plugin.
2. Start the companion when you need WP-CLI-assisted work.
3. Create a WordPress Application Password for the MCP client user.
4. Add the Stonewright MCP server to your AI client.
5. In wp-admin, open **Stonewright > Configuration**, enable Stonewright, and
   choose the safety mode.
6. Smoke test the connection with `stonewright-ping`, then call
   `stonewright-context-bootstrap`.

Every real task should start with `stonewright-context-bootstrap`. The response
contains active instructions, relevant skills, persistent memory, safety
followups, and the short-lived token needed by write abilities.

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

Safety:
- Start with stonewright-context-bootstrap.
- Use native WordPress or Elementor abilities first.
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
- Create a global-style plan before the first Elementor write. Decide which
  colors and type styles belong in the Elementor kit and which should stay
  local to the page.
- Use full-width outer sections, centered max-width inner containers, native
  rows/columns, and native widgets.
- Before full-page screenshots, scroll through the page or otherwise preload
  lazy-loaded media so missing assets are not mistaken for layout failures.
- Check every active Elementor or WordPress breakpoint used by the site.
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

1. `stonewright-context-bootstrap`
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
`stonewright/context-bootstrap`.
