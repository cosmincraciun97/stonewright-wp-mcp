# Skill Packs

Stonewright ships a set of skill packs for Claude Code in the `skills/` directory. Each skill pack is a domain-specific prompt bundle that teaches Claude Code how to use Stonewright abilities effectively for a particular workflow.

## Available skills

| Skill | Directory | Description |
|---|---|---|
| `design-to-wordpress` | `skills/design-to-wordpress/` | Import a Figma node or image, produce a Design Spec, choose a renderer, and build the page. |
| `elementor-v3-builder` | `skills/elementor-v3-builder/` | Build and edit Elementor V3 pages using the structural abilities and spec renderer. |
| `elementor-v4-atomic` | `skills/elementor-v4-atomic/` | Experimental Elementor V4 atomic workflow. Use only on sites running Elementor V4. |
| `gutenberg-fse-builder` | `skills/gutenberg-fse-builder/` | Build Gutenberg block pages and FSE templates from a Design Spec. |
| `pixel-perfect-qa` | `skills/pixel-perfect-qa/` | Screenshot a page, diff it against a reference, iterate until the match score is acceptable. |
| `wp-plugin-dev` | `skills/wp-plugin-dev/` | Scaffold WordPress plugins, custom blocks, and widgets. |
| `stonewright-review` | `skills/stonewright-review/` | Run the full QA suite (accessibility, Lighthouse, responsive, pixel diff) before marking a build done. |

## Using a skill with Claude Code

Reference the skill file in your Claude Code settings or in a project-level `CLAUDE.md`:

```markdown
Use the skills in /path/to/stonewright/skills/ for WordPress work.
When building a page from Figma, follow skills/design-to-wordpress/.
```

Or invoke a specific skill directly in a prompt:

```
/stonewright-review
```

## Writing a custom skill

A skill is a Markdown file with:

1. A short description of what it does.
2. The sequence of Stonewright ability calls to make, with example arguments.
3. Decision logic (how to choose between Gutenberg and Elementor, when to call `validate-spec`, when to run QA).
4. Success and failure criteria.

Place custom skills in `skills/` or in your project's `.claude/skills/` directory.

## Skill pack conventions

- Skills call `stonewright/design/validate-spec` before any renderer ability.
- Skills call `stonewright/site/ping` at the start to verify the MCP connection.
- QA skills end with at least one screenshot diff and an accessibility check.
- Skills do not call abilities that require `manage_options` unless the workflow explicitly needs them.
