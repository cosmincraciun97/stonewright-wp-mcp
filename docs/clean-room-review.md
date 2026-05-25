# Clean-Room Review Notes

This is a product-level review only. Do not copy code, prompts, schemas,
identifiers, docs, or text from other WordPress MCP projects.

Sources reviewed:

- Novamira docs and changelog: https://novamira.ai/docs/ and https://novamira.ai/changelog/
- Novamira repository overview: https://github.com/use-novamira/novamira
- Claudeus WordPress MCP repository overview: https://github.com/deus-h/claudeus-wp-mcp
- Elementor MCP repository overview: https://github.com/msrbuilds/elementor-mcp
- Playwright MCP: https://github.com/microsoft/playwright-mcp

## Product Decisions

- Keep Stonewright safe by default: no arbitrary PHP execution, no shell strings,
  no file-system free-for-all, and no REST write calls from the companion.
- Keep WordPress work structured: use native abilities for posts, media,
  Gutenberg, FSE, Elementor, skills, memory, menus, and Theme Builder.
- Keep WP-CLI powerful but guarded: tokenized argv only, deny arbitrary PHP and
  interactive shell entry points, expose discovery before execution.
- Keep browser work separate: use external Playwright MCP for browser testing,
  screenshots, and visual inspection. Do not add browser or screenshot tools to
  Stonewright.
- Make weaker models harder to derail: context bootstrap is mandatory, tool
  names are explicit, skills/memory are returned up front, Elementor widget
  planning is required, and design-derived backgrounds require an asset plan.

## Improvements Added

- `stonewright-system-abilities-list` returns both WordPress ability names and
  MCP tool names.
- The generated ability matrix includes a `MCP Tool` column for every command.
- The companion stdio server can proxy the WordPress MCP endpoint so tools like
  `stonewright-context-bootstrap` appear in clients that use local stdio MCP.
- Install docs include Windows/macOS paths, correct endpoint, correct package
  invocation, and the separate Playwright MCP config.
- Context bootstrap advertises external MCP recommendations and repeats the
  first-call discipline on every task.
