<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Skills\Skills;
use Stonewright\WpMcp\Memory\Memory;

/**
 * Default MCP-facing instructions that travel with the Stonewright server.
 * Includes core build discipline, optional custom site instructions, and a
 * compact enabled-skill index.
 */
final class AgentInstructions {

	public static function default(): string {
		$parts = [
			'Stonewright build discipline:',
			'- MCP clients expose Stonewright tools with hyphens. When calling tools via MCP, replace `/` with `-`: ability `stonewright/context-bootstrap` is MCP tool `stonewright-context-bootstrap`, and ability `stonewright/wp-cli-run` is MCP tool `stonewright-wp-cli-run`.',
			'- At the start of every Stonewright task, call MCP tool stonewright-context-bootstrap with the user request, surface, and intent. Read the returned instructions, matched skill playbooks, memory entries, and required followups before acting.',
			'- Every write or destructive ability must include the stonewright_context_token returned by stonewright/context-bootstrap.',
			'- Persistent skills and memory are authoritative across sessions. Call stonewright/skills-get for every matched skill and stonewright/memory-get or stonewright/memory-list for relevant memory before planning or writing.',
			'- Subagents must call stonewright-context-bootstrap themselves in their own session. Do not delegate only a copied context token; the subagent must read the returned instructions, memory, skills, followups, and visual contract before writing.',
			'- If the user corrects the agent, or the agent detects a repeatable mistake, call stonewright/learning-record so future tasks update persistent memory and, when useful, skills.',
			'- For browser testing, screenshots, or visual inspection, use an external Playwright MCP. If the MCP client has no browser tool available, install/connect the external Playwright MCP with command `npx -y @playwright/mcp@latest --caps=testing,vision,devtools`, restart the AI client so the tool list refreshes, and stop before the first visual write until the browser tool is visible. Stonewright itself does not expose browser or screenshot tools.',
			'- For visual implementation tasks, do not write blind. Extract measured tokens from the reference screenshot first: canvas size, section bounds, max widths, colors, typography, spacing, and asset crop bounds. Then build, screenshot the live page at the same viewport, list visible deltas, and iterate. Horizontal scroll is a hard failure.',
			'- Before the first Elementor write for a design build, create a global-style plan: map reusable colors and typography to the Elementor kit, decide which values stay local to one page, and only mutate global kit settings when the user request approves site-wide design changes. When approved, call stonewright/elementor-v3-update-kit-colors and stonewright/elementor-v3-update-kit-typography before building page elements so generated specs can reuse tokens instead of repeating raw values.',
			'- Before full-page screenshots, scroll through the page or otherwise preload lazy-loaded media so missing assets are not mistaken for layout failures.',
			'- Before claiming visual completion, run a browser check that document.documentElement.scrollWidth is not greater than document.documentElement.clientWidth + 1 at desktop, tablet, and mobile viewports.',
			'- For WordPress implementation or debugging work, call stonewright/wp-cli-status first when CLI availability is unknown, then stonewright/wp-cli-discover before choosing plugin-specific commands.',
			'- When Stonewright is installed through the Node companion MCP, the MCP tools stonewright-wp-cli-status, stonewright-wp-cli-discover, and stonewright-wp-cli-run are direct companion aliases. They do not require the WordPress-side HTTP bridge on port 8765.',
			'- If WP-CLI is unavailable and the direct companion installer tool is exposed, call stonewright-wp-cli-install only after user approval or when the user explicitly asked Stonewright to install WP-CLI. It downloads the official wp-cli.phar into the Stonewright companion cache; it does not write to system PATH.',
			'- Use stonewright/wp-cli-run for tokenized WP-CLI commands that speed up WordPress, Elementor, Gutenberg, ACF, CPT UI, cache, rewrite, plugin, option, post, media, menu, and taxonomy tasks. Pass stonewright_context_token for every write command.',
			'- If stonewright/wp-cli-status returns available=false in a WordPress-proxied client, do not assume WP-CLI is missing. Use direct companion MCP tools stonewright-wp-cli-status / stonewright-wp-cli-discover / stonewright-wp-cli-run or companion_wp_cli_status / companion_wp_cli_discover / companion_wp_cli_run when exposed. If those are not available, use normal Stonewright REST abilities; do not create sandbox files or arbitrary REST workarounds for basic page/template/meta writes.',
			'- For ACF, ACPT, Meta Box, ASE, Pods, WooCommerce, custom fields, content-model, or product-catalog work, call stonewright/workflow-preflight first and follow the returned specialization guidance before writing.',
			'- If a matching site skill is returned, call stonewright/skills-get for stonewright-content-model-integrations or stonewright-woocommerce-catalog and follow that playbook. Use plugin-specific official REST or WP-CLI surfaces when present; otherwise use native Stonewright content, media, taxonomy, menu, and guarded WP-CLI abilities only.',
			'- For content-model writes, confirm plugin active state, discover post types, taxonomies, value targets, field groups, and available command groups before choosing a write path. Do not invent hidden storage keys for ACF, ACPT, Meta Box, ASE, or Pods.',
			'- For WooCommerce catalog writes, verify WooCommerce is active, discover wp wc support, check SKU uniqueness, create attributes before variations, soft-delete by default, and read back parent products plus generated variations.',
			'- Do not use wp eval, wp eval-file, wp shell, wp package, --exec, or --require through Stonewright. The companion blocks arbitrary PHP and shell entry points by design.',
			'- Before Elementor implementation, call stonewright/elementor-knowledge-search or stonewright/elementor-describe-widget when widget behavior, settings, Theme Builder, editor V3/V4 behavior, or documentation freshness is uncertain.',
			'- Before choosing a widget from a prompt, design reference, image, or task, call stonewright/widget-intent-resolve so Stonewright selects the native Elementor intent instead of the model guessing.',
			'- Before writing Elementor elements, call stonewright/elementor-widget-implementation-guide with the task, candidate widgets, and design context.',
			'- Use real Elementor widgets for the detected intent: nav-menu for navigation, countdown for countdowns, social-icons for social rows, icon-list for footer/link/bullet lists. Do not simulate these with headings, buttons, or arbitrary text blocks.',
			'- Do not use Elementor HTML widgets unless the user explicitly asks for HTML and the ability call passes allow_html_widget=true. Use Elementor V3 containers and native widgets first.',
			'- Do not use stonewright/elementor-v3-add-widget for built-in Elementor widgets unless the dedicated stonewright/elementor-add-* ability cannot express the widget. Raw known-widget writes can corrupt editor controls if the model invents setting names.',
			'- For repeated visual structures such as team cards, speaker cards, logos, galleries, and pricing grids, build the first pass with stonewright/elementor-v3-build-page-from-spec or stonewright/elementor-v3-apply-bundle. Use dozens of single add/update calls only for post-screenshot surgical fixes.',
			'- Use exact Elementor control keys from widget schemas and stonewright/elementor-describe-widget. Do not invent CSS-like setting keys such as `icon`, `icon_primary_color`, `icon_background_color`, or `width` when the schema expects keys such as `selected_icon`, `primary_color`, `secondary_color`, or Advanced layout keys.',
			'- Do not only place widgets. Configure the relevant Content, Style, and Advanced controls, including animations, absolute/fixed positioning, width, z-index, motion effects, background and background overlay, borders, responsive values, attributes, transform, display conditions, cache settings, order, align self, margin, and padding.',
			'- If internal widget docs, harvested marketing docs, or stonewright/elementor-describe-widget are incomplete or stale, research official Elementor documentation online before configuring the widget.',
			'- Custom CSS requires explicit user approval before writing. When approved, write organized CSS to the active theme style.css, not inline HTML widgets.',
			'- If SVG upload is blocked, do not create sandbox or mu-plugin workarounds without explicit user approval. Prefer Elementor icon-library controls when an equivalent native icon is acceptable, or ask approval for a safe SVG enablement path.',
			'- Build responsive desktop, tablet, and mobile layouts. Headers must use sticky settings where requested, real desktop/tablet/mobile visibility controls, and mobile navigation must use the native hamburger/dropdown behavior.',
			'- Do not use the design canvas width as a fixed live page width. Convert canvas measurements into responsive max-width, percentage width, and padding rules so the page never exceeds the viewport.',
			'- Preserve design layout intent: full-width outer sections, centered max-width inner containers, rows for two-column hero/content areas, native gallery widgets for galleries, native form widgets for forms, and no extra borders on assets that already include their border artwork.',
			'- When the user asks for a page without header and footer, set the page template to Elementor Canvas and verify the live page has no theme chrome.',
			'- To remove theme header/footer from an existing page, call stonewright/content-update-page with template=elementor_canvas. Do not use ad hoc REST or sandbox code for this.',
			'- For backgrounds: if the background is a flat color, set it as an Elementor background color; if it is a simple linear gradient, use Elementor gradient controls; if it contains glow, radial blur, complex shadow, or blended effects, use an appropriate background asset or Elementor background overlay on the relevant container.',
			'- Do not use a full-page screenshot as a section background. Before using a design-derived background image, write an asset selection plan that names the target section, source layer/node or crop bounds, WordPress media item, and why it is the exact section asset rather than a parent composite.',
			'- For assets, place the exact asset required by the design. Do not use a parent composite image when a child asset is the actual asset.',
			'- For headers and footers, create separate Theme Builder templates and set include/general conditions; do not leave theme chrome as a substitute.',
			'- Validate every generated DesignSpec before render and snapshot before every Elementor or theme-backed write.',
		];

		$instructions_enabled = (bool) get_option( 'stonewright_custom_instructions_enabled', true );
		$custom_instructions  = (string) get_option( 'stonewright_custom_instructions', '' );

		if ( $instructions_enabled && '' !== $custom_instructions ) {
			$parts[] = '';
			$parts[] = '## Site-specific instructions';
			$parts[] = '';
			$parts[] = $custom_instructions;
		}

		$skills_block = Skills::instructions_block();
		if ( '' !== $skills_block ) {
			$parts[] = $skills_block;
		}

		$memory_block = Memory::instructions_block();
		if ( '' !== $memory_block ) {
			$parts[] = $memory_block;
		}

		$parts[] = '';
		$parts[] = '## How to use Stonewright Skills';
		$parts[] = '';
		$parts[] = 'Skills are site-specific playbooks you MUST follow when the current task matches their description.';
		$parts[] = 'Call MCP tool `stonewright-context-bootstrap` first; it returns matched skill playbooks directly. If you need another playbook, call ability `stonewright/skills-get` (MCP tool `stonewright-skills-get`) and follow it exactly.';
		$parts[] = 'To list all available skills: call `stonewright/skills-list`.';
		$parts[] = 'To create or update a skill: call `stonewright/skills-save`.';
		$parts[] = 'To read an individual skill: call `stonewright/skills-get` with the slug.';

		return implode( "\n", $parts );
	}
}
