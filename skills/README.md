# Stonewright Skill Pack

Seven Claude Code skills for the Stonewright WordPress MCP plugin. Each skill
drives a specific workflow against the `stonewright/*` ability surface.

## License

The skill prose is original work bundled with the Stonewright plugin under
GPL-2.0-or-later. The documentation text is additionally available under
CC BY 4.0.

## Skills

| Skill | Description |
|---|---|
| `design-to-wordpress` | Full Figma/image -> WordPress page pipeline with renderer selection |
| `elementor-v3-builder` | Elementor V3 container/widget tree, kit colors/typography, templates |
| `elementor-v4-atomic` | Experimental V4 atomic renderer, gated behind feature flag |
| `gutenberg-fse-builder` | Block editor, FSE templates, theme.json, synced patterns |
| `pixel-perfect-qa` | Screenshot-diff-fix loop with accessibility and Lighthouse checks |
| `wp-plugin-dev` | Plugin scaffold, ability authoring, security patterns, test workflow |
| `stonewright-review` | Validate generated page against Design Spec, surface drift |

## Install for Claude Code

### Option A: drop into user plugins directory

```bash
cp -r skills/design-to-wordpress ~/.claude/plugins/
cp -r skills/elementor-v3-builder ~/.claude/plugins/
cp -r skills/elementor-v4-atomic ~/.claude/plugins/
cp -r skills/gutenberg-fse-builder ~/.claude/plugins/
cp -r skills/pixel-perfect-qa ~/.claude/plugins/
cp -r skills/wp-plugin-dev ~/.claude/plugins/
cp -r skills/stonewright-review ~/.claude/plugins/
```

### Option B: reference from plugin-config.json

Add entries to `~/.claude/plugin-config.json`:

```json
{
  "plugins": [
    { "path": "/path/to/stonewright-wp-mcp/skills/design-to-wordpress" },
    { "path": "/path/to/stonewright-wp-mcp/skills/elementor-v3-builder" },
    { "path": "/path/to/stonewright-wp-mcp/skills/elementor-v4-atomic" },
    { "path": "/path/to/stonewright-wp-mcp/skills/gutenberg-fse-builder" },
    { "path": "/path/to/stonewright-wp-mcp/skills/pixel-perfect-qa" },
    { "path": "/path/to/stonewright-wp-mcp/skills/wp-plugin-dev" },
    { "path": "/path/to/stonewright-wp-mcp/skills/stonewright-review" }
  ]
}
```

Replace `/path/to/stonewright-wp-mcp` with the absolute path to this repo.

## Requirements

- Stonewright WordPress MCP plugin installed and active on the target site.
- Claude Code with MCP server configured to point at the plugin's REST endpoint.
- For Elementor skills: Elementor >= 3.x active.
- For V4 atomic skill: Elementor >= 4.0.0 and `stonewright_elementor_v4_atomic`
  option enabled in wp-options.
- For FSE skills: a block theme active (`wp_is_block_theme()` returns true).
- For pixel-perfect-qa: companion layer running with Playwright available.

## Companion layer

The `companion/` directory contains the Node.js companion that provides
Playwright screenshots and pixel-diff. Start it before running
`pixel-perfect-qa`:

```bash
cd companion
npm install
npm run build
npm start
```
