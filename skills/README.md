# Stonewright Skill Pack

Skills for the Stonewright WordPress MCP plugin. Each skill guides a specific
workflow against the `stonewright/*` ability surface.

## Skills

| Skill | Description |
|---|---|
| `design-to-wordpress` | Design reference, image, brief, or manual spec to WordPress page |
| `elementor-v3-builder` | Elementor V3 container/widget tree, kit colors/typography, templates |
| `elementor-v4-atomic` | Experimental V4 atomic renderer, gated behind feature flag |
| `gutenberg-fse-builder` | Block editor, FSE templates, theme.json, synced patterns |
| `wp-plugin-dev` | Plugin scaffold, ability authoring, security patterns, test workflow |
| `stonewright-review` | Review generated page structure against Design Spec and site state |

## Install for Claude Code

Copy or reference only the skills you need:

```bash
cp -r skills/design-to-wordpress ~/.claude/plugins/
cp -r skills/elementor-v3-builder ~/.claude/plugins/
cp -r skills/gutenberg-fse-builder ~/.claude/plugins/
cp -r skills/wp-plugin-dev ~/.claude/plugins/
```

## Requirements

- Stonewright WordPress MCP plugin installed and active on the target site.
- MCP client configured to reach the plugin.
- For Elementor skills: Elementor >= 3.x active.
- For V4 atomic skill: Elementor >= 4.0.0 and `stonewright_elementor_v4_atomic`
  enabled.
- For FSE skills: a block theme active.
- For WP-CLI acceleration: companion running with `wp` available.

## Companion layer

The `companion/` directory provides WP-CLI, health checks, and the optional MCP
proxy.

```bash
cd companion
npm install
npm run build
npm start
```
