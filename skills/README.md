# Stonewright Skill Pack

Skills for the Stonewright WordPress MCP plugin. Each skill guides a specific
workflow against the `stonewright/*` ability surface.

## Skills

| Skill | Description |
|---|---|
| `stonewright` | Searchable Stonewright entrypoint for WordPress, Elementor, Gutenberg, WooCommerce, content model, sandbox, memory, and workflow routing |
| `design-to-wordpress` | Design reference, image, brief, or manual spec to WordPress page |
| `content-model-integrations` | ACF, ACPT, Meta Box, ASE, Pods, custom fields, CPTs, taxonomies, option pages |
| `elementor-v3-builder` | Elementor V3 container/widget tree, kit colors/typography, templates |
| `elementor-v4-atomic` | Experimental V4 atomic renderer, gated behind feature flag |
| `gutenberg-fse-builder` | Block editor, FSE templates, theme.json, synced patterns |
| `woocommerce-catalog` | WooCommerce products, variations, SKUs, attributes, terms, shipping classes |
| `wp-plugin-dev` | Plugin scaffold, ability authoring, security patterns, test workflow |
| `stonewright-review` | Review generated page structure against Design Spec and site state |

## Install for Claude Code

Copy or reference only the skills you need:

```bash
cp -r skills/design-to-wordpress ~/.claude/plugins/
cp -r skills/content-model-integrations ~/.claude/plugins/
cp -r skills/elementor-v3-builder ~/.claude/plugins/
cp -r skills/gutenberg-fse-builder ~/.claude/plugins/
cp -r skills/woocommerce-catalog ~/.claude/plugins/
cp -r skills/wp-plugin-dev ~/.claude/plugins/
```

## Install for Codex

Copy the skill folders into the Codex skills directory, then restart Codex so
slash and `$` search can index them:

```powershell
Copy-Item -Recurse -Force .\skills\stonewright "$env:USERPROFILE\.codex\skills\stonewright"
Copy-Item -Recurse -Force .\skills\design-to-wordpress "$env:USERPROFILE\.codex\skills\design-to-wordpress"
Copy-Item -Recurse -Force .\skills\content-model-integrations "$env:USERPROFILE\.codex\skills\content-model-integrations"
Copy-Item -Recurse -Force .\skills\elementor-v3-builder "$env:USERPROFILE\.codex\skills\elementor-v3-builder"
Copy-Item -Recurse -Force .\skills\gutenberg-fse-builder "$env:USERPROFILE\.codex\skills\gutenberg-fse-builder"
Copy-Item -Recurse -Force .\skills\woocommerce-catalog "$env:USERPROFILE\.codex\skills\woocommerce-catalog"
Copy-Item -Recurse -Force .\skills\wp-plugin-dev "$env:USERPROFILE\.codex\skills\wp-plugin-dev"
Copy-Item -Recurse -Force .\skills\stonewright-review "$env:USERPROFILE\.codex\skills\stonewright-review"
```

## Requirements

- Stonewright WordPress MCP plugin installed and active on the target site.
- MCP client configured to reach the plugin.
- For Elementor skills: Elementor >= 3.x active.
- For V4 atomic skill: Elementor >= 4.0.0 and `stonewright_elementor_v4_atomic`
  enabled.
- For FSE skills: a block theme active.
- For content-model skills: the target plugin active and discoverable through
  `stonewright/site-plugins-list`, official REST routes, or WP-CLI command
  discovery.
- For WooCommerce catalog skills: WooCommerce active and official REST v3 or
  `wp wc` commands available for the requested operation.
- For WP-CLI acceleration: companion running with `wp` available on `PATH` or
  LocalWP-style PHP + `wp-cli.phar` discoverable from the WordPress root. If no
  WP-CLI is available, the direct companion tool `stonewright-wp-cli-install`
  can install `wp-cli.phar` into the Stonewright cache.

Skills are routing guidance, not substitutes for live MCP tools. If
`stonewright-context-bootstrap` is missing, restart or fix the MCP client
instead of reading repository files as a replacement. For WP-CLI work, use
`stonewright-wp-cli-status`, `stonewright-wp-cli-discover`,
`stonewright-wp-cli-run`, `stonewright-wp-cli-batch-run`, or
`stonewright-wp-cli-install`; do not recover by running `wp ...` in a normal
shell or by using arbitrary PHP execution from another adapter.

## Companion layer

The `companion/` directory provides WP-CLI, health checks, and the optional MCP
proxy.

```bash
cd companion
npm install
npm run build
npm start
```

The companion exposes both `companion_wp_cli_*` tools and direct
`stonewright-wp-cli-*` aliases. The direct aliases do not require the optional
HTTP bridge on port `8765`.

Leave `PORT` unset for stdio-only clients. It enables the optional HTTP bridge;
stdio MCP remains the primary transport for normal agent sessions.
Use `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` for Antigravity, Gemini API, or
other strict tool-cap clients; it keeps the client-visible startup surface under
30 tools. Use `essential` for normal fast-path sessions and specialist aliases
such as `elementor`, `acf`, `cpt-ui`, `fse`, or `wp cli` when a session needs
one narrow surface.
