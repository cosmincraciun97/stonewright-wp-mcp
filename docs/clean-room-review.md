# Clean-Room Review Notes

This is a product-level review only. Do not copy code, prompts, schemas,
identifiers, docs, changelog text, UI copy, or branded workflow structure from
other WordPress automation or MCP projects.

Raw competitive notes, source names, screenshots, and private research belong
only in ignored local research files. Public Stonewright documentation should
describe original product decisions, not inspiration sources.

## Product Decisions

- Keep Stonewright safe by default: no arbitrary PHP execution, no shell
  strings, no file-system free-for-all, and no REST write calls from the
  companion.
- Keep WordPress work structured: use native abilities for posts, media,
  Gutenberg, FSE, Elementor, skills, memory, menus, and Theme Builder.
- Keep WP-CLI powerful but guarded: tokenized argv only, deny arbitrary PHP and
  interactive shell entry points, expose discovery before execution.
- Keep browser work separate: use external browser tooling for screenshots and
  visual inspection. Do not add browser or screenshot tools to Stonewright.
- Make smaller models harder to derail: context bootstrap is mandatory, tool
  names are explicit, skills and memory are returned up front, Elementor widget
  planning is required, and design-derived backgrounds require an asset plan.

## Improvements Added

- `stonewright-system-abilities-list` returns both WordPress ability names and
  MCP tool names.
- The generated ability matrix includes an `MCP Tool` column for every command.
- The companion stdio server can proxy the WordPress MCP endpoint so
  Stonewright tools appear in clients that use local stdio MCP.
- Install docs include Windows and macOS paths, correct endpoint, correct
  package invocation, and separate browser-tooling configuration.
- Context bootstrap advertises external MCP recommendations and repeats the
  first-call discipline on every task.
- Strict `low-tools` startup keeps long-running WP-CLI background jobs visible
  while staying under the small-client tool budget.
