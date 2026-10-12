# Stonewright Skill Pack

Skills for the Stonewright WordPress MCP plugin. Each skill guides a specific
workflow against the `stonewright/*` ability surface.

## Skills

| Skill | Description |
|---|---|
| `stonewright` | Searchable Stonewright entrypoint for WordPress, Elementor, Gutenberg, WooCommerce, content model, PHP runtime, sandbox, memory, and workflow routing |
| `design-to-wordpress` | Design reference, image, brief, or manual spec to WordPress page |
| `content-model-integrations` | ACF, ACPT, Meta Box, ASE, Pods, custom fields, CPTs, taxonomies, option pages |
| `elementor-v3-builder` | Elementor V3 container/widget tree, kit colors/typography, templates |
| `elementor-v4-atomic` | Experimental V4 atomic renderer, gated behind feature flag |
| `gutenberg-fse-builder` | Block editor, FSE templates, theme.json, synced patterns |
| `woocommerce-catalog` | WooCommerce products, variations, SKUs, attributes, terms, shipping classes |
| `wp-plugin-dev` | Plugin scaffold, ability authoring, security patterns, test workflow |
| `stonewright-review` | Review generated page structure against Design Spec and site state |
| `stonewright-rescue` | Recover from a change that left the site failing: rescue status, rollback plan and run, and re-check |
| `stonewright-section-reuse` | Offer sections the site already has for a new page, copy the picked ones and adapt them in the same batch (Gutenberg, Elementor V3, Elementor V4) |
| `agent-operating-rules` | Permanent operating rules for every agent: native-first styling, separate verification tabs, custom-code grants, write closure, verified learning |
| `acf-build-fields` | ACF field groups, custom fields, repeaters, flexible content, options pages, and ACF values on posts |
| `elementor-site-clone` | Clone or rebuild an Elementor page or section into a new draft with typed tools |
| `forms-inventory` | List contact forms, find their shortcodes or blocks, and embed an existing form (read and embed only) |
| `seo-optimize` | SEO titles, descriptions, canonicals, robots, and focus keywords with Yoast, Rank Math, or All in One SEO |
| `visual-direction` | Brand palette, typography scale, spacing, imagery, and motion: direction capture, reviewed kit sync, and rendered evidence |
| `blocksy-build-page` | Blocksy pages with live block schemas, theme chrome tokens, and the Gutenberg finalizer |
| `generateblocks-build-page` | GenerateBlocks pages with live `generateblocks/*` schemas, and GeneratePress chrome when that theme is active |
| `kadence-build-page` | Kadence Blocks pages with live `kadence/*` schemas, and Kadence Theme chrome when that theme is active |
| `spectra-build-page` | Spectra (`uagb/*`) pages with live schemas and the Gutenberg finalizer |
| `how-to-write-skills` | Write, review, import, or test a site skill: trigger descriptions, body size, version constraints, and the checks a skill must pass |

## Playbooks

Task playbooks in `playbooks/<name>.md` ship with the pack as `playbook-<name>`
and open on request.

| Playbook | Description |
|---|---|
| `ab-copy-variants` | A/B headline and CTA copy variants for a landing section |
| `about-page` | About page with story, team, values, and supporting media |
| `blog-series` | Multi-post blog series with shared structure, categories, and featured images |
| `contact-page` | Contact page with form, map or address block, and response expectations |
| `faq-accordion` | FAQ section with accessible accordion structure |
| `homepage-hero` | Homepage hero with headline, subcopy, CTA, and background media |
| `mega-menu` | Multi-column navigation menu with locations and nested items |
| `multilingual-prep` | Prepare pages and content models for multilingual plugins |
| `page-migration` | Move a page layout between builders, or rebuild a page from its current content |
| `popup-lead-capture` | Lead-capture popup or modal section with a form shortcode |
| `portfolio-gallery` | Portfolio or project gallery with filters, cards, and detail links |
| `pricing-table` | Pricing comparison table with tiers, features, and CTA buttons |
| `section-refactor` | Refactor one page section for spacing, hierarchy, and builder-native structure |
| `seo-on-page-audit` | Audit a page for title, headings, alt text, internal links, and schema readiness |
| `services-grid` | Services or features grid with icons or images and consistent cards |
| `stock-image-fill` | Search and import Openverse stock images with license attribution |
| `testimonials-section` | Testimonials band with quotes, names, and optional avatars |
| `woocommerce-catalog-setup` | Products, categories, attributes, and catalog pages with the WooCommerce tools |

## Install for Claude Code

Claude Code loads skills from `~/.claude/skills/<name>/SKILL.md` (personal),
`.claude/skills/<name>/SKILL.md` (one project), or the `skills/` folder of a
Claude Code plugin. Copy or reference only the skills you need:

```bash
mkdir -p ~/.claude/skills
cp -r skills/stonewright skills/design-to-wordpress ~/.claude/skills/
cp -r skills/elementor-v3-builder skills/gutenberg-fse-builder ~/.claude/skills/
cp -r skills/woocommerce-catalog skills/wp-plugin-dev ~/.claude/skills/
```

For one project only, copy into `.claude/skills/` at the project root instead.

The plugin ZIP bundles this folder, so a site that runs the plugin already has
these skills under **Stonewright > Knowledge > Skills**, and MCP clients reach
them through `stonewright-task-start` and `stonewright-skills-get` without any
copy.

## Install for Codex

Use the sync script from the repository root. It compares each bundled
Stonewright skill against the installed Codex skill folder, creates backups for
changed skills outside the indexed skill root, then copies only the Stonewright
skill pack:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\sync-codex-skills.ps1 -WhatIf
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\sync-codex-skills.ps1
```

Restart Codex after syncing so slash and `$` search can index the updated
skills. To sync one skill only, pass `-SkillName stonewright`.

The script also fixes older local installs that caused duplicate `/stonewright`
entries in Codex. It removes stale nested skill copies such as
`~/.codex/skills/stonewright/stonewright/SKILL.md` and relocates old
`*.backup-*` skill directories from `~/.codex/skills` to
`~/.codex/skill-backups/stonewright`, where Codex does not index them as active
skills. Use `-SkipIndexedBackupCleanup` only if you deliberately want to inspect
old indexed backups before moving them.

## Requirements

- Stonewright WordPress MCP plugin installed and active on the target site.
- MCP client configured to reach the plugin.
- For Elementor skills: Elementor >= 3.x active.
- For V4 atomic skill: an Elementor build with the Atomic Widgets module
  (4.0.0, or a 3.31+ build that ships it) and `stonewright_elementor_v4_atomic`
  enabled. Certified native Elementor abilities run through
  `stonewright/elementor-native-execute` when native Elementor is available;
  that tool does not depend on the option.
- For FSE skills: a block theme active.
- For content-model skills: the target plugin active and discoverable through
  `stonewright/site-plugins-list`, official REST routes, or WP-CLI command
  discovery.
- For WooCommerce catalog skills: WooCommerce active. Plugin mode uses native
  typed catalog abilities first; tokenized `wp wc` is fallback for a missing
  operation or long batch. Direct mode is read-only.
- For WP-CLI acceleration: companion running with `wp` available on `PATH` or
  LocalWP-style PHP + `wp-cli.phar` discoverable from the WordPress root. If no
  WP-CLI is available, the direct companion tool `stonewright-wp-cli-install`
  can install `wp-cli.phar` into the Stonewright cache.

Skills are routing guidance, not substitutes for live MCP tools. If
`stonewright-task-start` is missing (or the compatibility tool
`stonewright-context-bootstrap`), restart or fix the MCP client instead of
reading repository files as a replacement. For WP-CLI work, use
`stonewright-wp-cli-status`, `stonewright-wp-cli-discover`,
`stonewright-wp-cli-run`, `stonewright-wp-cli-batch-run`,
`stonewright-wp-cli-job-start`, `stonewright-wp-cli-job-status`, or
`stonewright-wp-cli-install`; do not recover by running `wp ...` in a normal
shell or by switching to another PHP adapter. Use `stonewright/php-execute`
(`full` profile only) for direct runtime snippets and `stonewright-wp-cli-*` for
tokenized WP-CLI.

## Companion layer

The `companion/` directory provides WP-CLI, health checks, and the optional MCP
proxy.

```bash
cd companion
npm ci
npm run build
npm start
```

The companion exposes both `companion_wp_cli_*` tools and direct
`stonewright-wp-cli-*` aliases. The direct aliases do not require the optional
HTTP bridge on port `8765`.

Leave `PORT` unset for stdio-only clients. A stale `.env` `PORT` is ignored
unless `STONEWRIGHT_HTTP_ENABLE=1` or `STONEWRIGHT_HTTP_REQUIRED=1` is also set;
stdio MCP remains the primary transport for normal agent sessions.
Use `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` for Antigravity, Gemini API, or
other strict tool-cap clients; it keeps the client-visible startup surface under
30 tools. The default is `essential-static` for normal fast-path sessions. Use
specialist aliases such as `elementor`, `acf`, `cpt-ui`, `fse`, or `wp cli` when
a session needs one narrow surface. `full` (the only profile that lists
`stonewright/php-execute`) is chosen by a person, never by a tool: an
administrator in wp-admin (**Stonewright > Setup > Settings**) or whoever runs
the companion.
