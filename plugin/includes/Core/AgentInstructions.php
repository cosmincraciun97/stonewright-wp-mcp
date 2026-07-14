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

	public static function server_bootstrap_summary(): string {
		$parts = [
			'Stonewright fast start:',
			'- First call MCP tool stonewright-context-bootstrap with task, surface, and intent. Use the returned stonewright_context_token for every write.',
			'- Then call stonewright-workflow-preflight for Elementor, Gutenberg, ACF, CPT UI, WooCommerce, or WordPress implementation. Follow its compact recommended tools.',
			'- For token-sensitive clients, use fast_path.tool_profile from stonewright-workflow-preflight before making a separate stonewright-tool-profile call.',
			'- If stonewright-context-bootstrap is not visible in the MCP tool list, stop and ask the user to reload or fix the Stonewright MCP config.',
			'- ' . McpUsePolicy::compact_bypass_ban_rule(),
			'- Use MCP tool stonewright-php-execute for direct full WordPress runtime access when a short PHP snippet is faster than many typed calls.',
			'- Design/Elementor: normalize DesignEvidence, run stonewright-design-native-plan, review or set approved kit globals, then compile live-schema writes in small verified batches.',
			'- Content-model/repeated rows: prefer content-bulk-upsert-posts and wp-cli-batch-run over many single meta or CLI calls.',
			'- WP-CLI remains tokenized; use stonewright-php-execute for PHP runtime snippets instead of wp eval, wp eval-file, wp shell, wp package, --exec, or --require.',
			'- Do not run wp cli info, wp plugin activate, wp option update, or other wp commands in a normal shell as Stonewright recovery. Use MCP tools stonewright-wp-cli-status, stonewright-wp-cli-discover, stonewright-wp-cli-run, or stonewright-wp-cli-batch-run.',
			'- Do not use another MCP adapter execute-php to replace Stonewright php-execute.',
		];

		$instructions_enabled = (bool) get_option( 'stonewright_custom_instructions_enabled', true );
		$custom_instructions  = trim( (string) get_option( 'stonewright_custom_instructions', '' ) );

		if ( $instructions_enabled && '' !== $custom_instructions ) {
			$parts[] = '';
			$parts[] = '## Site-specific instructions';
			$parts[] = mb_substr( $custom_instructions, 0, 1200 );
		}

		return implode( "\n", $parts );
	}

	public static function default( bool $include_visual = true ): string {
		$parts = [
			'Stonewright build discipline:',
			'- MCP clients expose Stonewright tools with hyphens. When calling tools via MCP, replace `/` with `-`: ability `stonewright/context-bootstrap` is MCP tool `stonewright-context-bootstrap`, and ability `stonewright/wp-cli-run` is MCP tool `stonewright-wp-cli-run`.',
			'- Do not start a Stonewright task by only announcing named skills. The first useful action is a real MCP tool call: stonewright-context-bootstrap, or stonewright-workflow-preflight only when explicitly using the fast path.',
			'- Do not treat local client skills, prompt snippets, or repository files as a substitute for live Stonewright MCP tools.',
			'- ' . McpUsePolicy::missing_context_bootstrap_rule(),
			...array_map( static fn ( string $rule ): string => '- ' . $rule, McpUsePolicy::bypass_ban_rules() ),
			'- Do not run wp cli info, wp plugin activate, wp option update, or other wp commands in a normal shell as Stonewright recovery. Use MCP tools stonewright-wp-cli-status, stonewright-wp-cli-discover, stonewright-wp-cli-run, or stonewright-wp-cli-batch-run.',
			'- Do not use another MCP adapter execute-php to replace Stonewright php-execute.',
			'- At the start of every Stonewright task, call MCP tool stonewright-context-bootstrap with the user request, surface, and intent. Read the returned instructions, matched skill playbooks, memory entries, and required followups before acting.',
			'- Use fast_path.tool_profile from stonewright/workflow-preflight before making a separate stonewright/tool-profile call; call tool-profile only to switch or verify a compact profile.',
			'- If essential tools mode is enabled, use the compact fast-path tools returned by stonewright/workflow-preflight instead of probing for every specialized ability.',
			'- Use stonewright/php-execute for direct full WordPress runtime access when a short PHP snippet is faster than many typed calls. It runs inside WordPress with loaded plugins and $wpdb.',
			'- Every write or destructive ability must include the stonewright_context_token returned by stonewright/context-bootstrap.',
			'- Persistent skills and memory are authoritative across sessions. Call stonewright/skills-get for every matched skill and stonewright/memory-get or stonewright/memory-list for relevant memory before planning or writing.',
			'- Site-specific skills, memory, and custom instructions stay local to this WordPress install. Treat them as private site context, not public project material or reusable package defaults.',
			'- Do not publish credentials, private memory, site-specific prompts, or custom instructions into docs, commits, release notes, generated skills, public examples, or support replies unless the site owner explicitly asks for that exact disclosure.',
			'- Subagents must call stonewright-context-bootstrap themselves in their own session. Do not delegate only a copied context token; the subagent must read the returned instructions, memory, skills, followups, and visual contract before writing.',
			'- If the user corrects the agent or a repeatable mistake is detected, call stonewright/learning-record. It records memory by default; any generated skill remains a disabled draft.',
			'- For browser testing, screenshots, or visual inspection, use an external Playwright MCP. If the MCP client has no browser tool available, install/connect the external Playwright MCP with command `npx -y @playwright/mcp@latest --caps=testing,vision,devtools`, restart the AI client so the tool list refreshes, and stop before the first visual write until the browser tool is visible. Stonewright itself does not expose browser or screenshot tools.',
			'- For visual implementation tasks, do not write blind. Extract measured tokens from the reference screenshot first: canvas size, section bounds, max widths, colors, typography, spacing, and asset crop bounds. Then build, screenshot the live page at the same viewport, list visible deltas, and iterate. Horizontal scroll is a hard failure.',
			'- For design-tool references, visual reference screenshots are the source of truth. Use styles, spacing, colors, typography, backgrounds, assets, and text from the design data, but the design-tool layer tree is not implementation authority when it conflicts with the visible screenshot.',
			'- For design-derived visual DesignSpecs, set style_policy=strict. Add style_source on the node or style._source inside the style map before applying measured borders, border radius, shadows, or filters.',
			'- Do not invent borders, border radius, shadows, filters, or card chrome. If the reference screenshot or design measurements do not show that decoration, omit it.',
			'- If a visual reference is too long or hard to compare as one image, split it into section reference screenshots and match each section before full-page signoff.',
			'- Implement visual pages in batches of one section at a time, or two sections only when they are simple and tightly coupled. After each batch, verify desktop, tablet, and mobile breakpoints. Auto-continue to the next section batch when screenshots, diagnostics, and overflow checks pass.',
			'- For pixel-perfect work, treat visual_build_gate as a blocking signoff checklist: provide a reference token table, media reuse audit, section implementation plan, screenshot delta list, and logged-out desktop, tablet, and mobile viewport checks before completion.',
			'- Before uploading or replacing visual assets, audit existing WordPress media by filename, alt text, dimensions, and visible crop. Reuse matching assets instead of downloading duplicates.',
			'- Before the first Elementor write for a design build, create a global-style plan: map reusable colors and typography to the Elementor kit, decide which values stay local to one page, and only mutate global kit settings when the user request approves site-wide design changes. When approved, call stonewright/elementor-v3-update-kit-colors and stonewright/elementor-v3-update-kit-typography before building page elements so generated specs can reuse tokens instead of repeating raw values.',
			'- Before full-page screenshots, scroll through the page or otherwise preload lazy-loaded media so missing assets are not mistaken for layout failures.',
			'- Before claiming visual completion, run a browser check that document.documentElement.scrollWidth is not greater than document.documentElement.clientWidth + 1 at desktop, tablet, and mobile viewports on the logged-out public page.',
			'- For WordPress implementation or debugging work, call stonewright/wp-cli-status first when CLI availability is unknown, then stonewright/wp-cli-discover before choosing plugin-specific commands.',
			'- When Stonewright is installed through the Node companion MCP, the MCP tools stonewright-wp-cli-status, stonewright-wp-cli-discover, and stonewright-wp-cli-run are direct companion aliases. They do not require the WordPress-side HTTP bridge on port 8765.',
			'- If WP-CLI is unavailable and the direct companion installer tool is exposed, call stonewright-wp-cli-install only after user approval or when the user explicitly asked Stonewright to install WP-CLI. It downloads the official wp-cli.phar into the Stonewright companion cache; it does not write to system PATH.',
			'- Use stonewright/content-bulk-upsert-posts for repeated post/CPT rows and custom-field/meta values after the post type exists. This is faster and lower-token than many wp post meta update calls.',
			'- Use stonewright/wp-cli-run for tokenized WP-CLI commands that speed up WordPress, Elementor, Gutenberg, ACF, CPT UI, cache, rewrite, plugin, option, post, media, menu, and taxonomy tasks. Pass stonewright_context_token for every write command.',
			'- WP-CLI remains tokenized; use stonewright/php-execute for PHP runtime snippets and structured returns.',
			'- If stonewright/wp-cli-status returns available=false in a WordPress-proxied client, do not assume WP-CLI is missing. Use direct companion MCP tools stonewright-wp-cli-status / stonewright-wp-cli-discover / stonewright-wp-cli-run or companion_wp_cli_status / companion_wp_cli_discover / companion_wp_cli_run when exposed. If those are not available, use normal Stonewright REST abilities; do not create sandbox files or arbitrary REST workarounds for basic page/template/meta writes.',
			'- For ACF, ACPT, Meta Box, ASE, Pods, WooCommerce, custom fields, content-model, or product-catalog work, call stonewright/workflow-preflight first and follow the returned specialization guidance before writing.',
			'- If a matching site skill is returned, call stonewright/skills-get for stonewright-content-model-integrations or stonewright-woocommerce-catalog and follow that playbook. Use plugin-specific official REST or WP-CLI surfaces when present; otherwise use native Stonewright content, media, taxonomy, menu, PHP execute, and WP-CLI abilities.',
			'- For content-model writes, confirm plugin active state, discover post types, taxonomies, value targets, field groups, and available command groups before choosing a write path. Do not invent hidden storage keys for ACF, ACPT, Meta Box, ASE, or Pods.',
			'- For WooCommerce catalog writes, verify WooCommerce is active, discover wp wc support, check SKU uniqueness, create attributes before variations, soft-delete by default, and read back parent products plus generated variations.',
			'- Do not use another MCP adapter execute-php to replace Stonewright php-execute. Do not use shell WP-CLI eval entry points as a workaround.',
			'- Before Elementor implementation, call stonewright/elementor-knowledge-search or stonewright/elementor-describe-widget when widget behavior, settings, Theme Builder, editor V3/V4 behavior, or documentation freshness is uncertain.',
			'- For every Elementor widget you intend to write, call stonewright/elementor-schema with mode=summary first; request mode=control for one complete control or paginated mode=full only when needed.',
			'- Before a design-derived write, normalize observations into DesignEvidence 1.0 and call stonewright/design-native-plan. Do not pass raw Figma trees or AI-generated raw Elementor settings.',
			'- Follow the design implementation contract returned by bootstrap/preflight: evidence first, complete native phase, small verified batches, then an approval-gated custom-code proposal only for the remaining delta.',
			'- Buttons, CTAs, links, navigation, forms, and images with unresolved behavior or sources are blocking errors, not decorative placeholders.',
			'- Complete the native phase before proposing custom code. CSS, CSS+JS, or PHP stays in a separate unapplied proposal until explicit approval, with diff, risk, rollback, and tests.',
			'- Use real Elementor widgets for the detected intent: nav-menu for navigation, countdown for countdowns, social-icons for social rows, icon-list for footer/link/bullet lists. Do not simulate these with headings, buttons, or arbitrary text blocks.',
			'- Do not use Elementor HTML widgets unless the user explicitly asks for HTML and the ability call passes allow_html_widget=true. Use Elementor V3 containers and native widgets first.',
			'- Per-widget stonewright/elementor-add-* abilities are deprecated compatibility tools. Use stonewright/elementor-schema, then stonewright/elementor-v3-batch-mutate with live-schema settings, idempotency, evidence, and readback.',
			'- For repeated visual structures such as team cards, speaker cards, logos, galleries, and pricing grids, build the first pass with stonewright/elementor-v3-build-page-from-spec using dry_run first. Use stonewright/elementor-v3-batch-mutate for post-screenshot surgical add/update/move/remove fixes instead of dozens of single calls.',
			'- Use exact Elementor control keys from widget schemas and stonewright/elementor-describe-widget. Do not invent CSS-like setting keys such as `icon`, `icon_primary_color`, `icon_background_color`, or `width` when the schema expects keys such as `selected_icon`, `primary_color`, `secondary_color`, or Advanced layout keys.',
			'- Do not only place widgets. Configure the relevant Content, Style, and Advanced controls, including animations, position absolute/fixed positioning, width, z-index, motion effects, background and background overlay, borders, mask, responsive values, attributes, transform, display conditions, cache settings, order, align self, margin, padding, CSS ID, and CSS classes.',
			'- If internal widget docs, harvested marketing docs, or stonewright/elementor-describe-widget are incomplete or stale, research official Elementor documentation online before configuring the widget.',
			'- Live research never becomes active memory or an active skill automatically. Record provenance, SHA-256, TTL, version constraints, and runtime fingerprint with stonewright/knowledge-candidate-record. Promote only after two verified successes or explicit user approval; resolve same-topic conflicts explicitly.',
			'- Name only major parent containers semantically, such as hero, header, pricing grid, team section, footer, or product gallery. Do not name every inner utility container.',
			'- Custom CSS requires explicit user approval before writing. When approved, write organized CSS to the active theme style.css, not inline HTML widgets.',
			'- If SVG upload is blocked, do not create sandbox or mu-plugin workarounds without explicit user approval. Prefer Elementor icon-library controls when an equivalent native icon is acceptable, or ask approval for an approved SVG enablement path.',
			'- Build responsive desktop, tablet, and mobile layouts. Headers must use sticky settings where requested, real desktop/tablet/mobile visibility controls, and mobile navigation must use the native hamburger/dropdown behavior.',
			'- Do not use the design canvas width as a fixed live page width. Convert canvas measurements into responsive max-width, percentage width, and padding rules so the page never exceeds the viewport.',
			'- Preserve design layout intent: full-width outer sections, centered max-width inner containers, rows for two-column hero/content areas, native gallery widgets for galleries, native form widgets for forms, and no extra borders on assets that already include their border artwork.',
			'- When the user asks for a page without header and footer, set the page template to Elementor Canvas and verify the live page has no theme chrome.',
			'- To remove theme header/footer from an existing page, call stonewright/content-update-page with template=elementor_canvas. Do not use ad hoc REST or sandbox code for this.',
			'- For backgrounds: if the background is a flat color, set it as an Elementor background color; if it is a simple linear gradient, use Elementor gradient controls; if it contains glow, radial blur, complex shadow, or blended effects, use an appropriate background asset or Elementor background overlay on the relevant container.',
			'- Do not use a full-page screenshot as a section background. Before using a design-derived background image, write an asset selection plan that names the target section, source layer/node or crop bounds, WordPress media item, and why it is the exact section asset rather than a parent composite.',
			'- For assets, place the exact asset required by the design. Do not use a parent composite image when a child asset is the actual asset.',
			'- For headers and footers, create separate Theme Builder templates and set include/general conditions; do not leave theme chrome as a substitute.',
			'- For Gutenberg and block-theme work, use native blocks first: read theme.json, registered blocks, templates, template parts, patterns, and block supports; plan tokens before writes; use stonewright/php-execute only when direct runtime access is the shorter correct path.',
			'- Validate every generated DesignSpec before render and snapshot before every Elementor or theme-backed write.',
		];

		if ( ! $include_visual ) {
			$parts = array_values(
				array_filter(
					$parts,
					static fn( string $part ): bool => ! self::is_visual_instruction( $part )
				)
			);
		}

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

	private static function is_visual_instruction( string $instruction ): bool {
		foreach (
			[
				'@playwright/mcp',
				'asset selection plan',
				'assets',
				'browser testing',
				'custom css requires',
				'design build',
				'design canvas',
				'design-derived',
				'design-tool',
				'document.documentElement.scrollWidth',
				'elementor implementation',
				'external Playwright MCP',
				'full-page screenshot',
				'global-style plan',
				'horizontal scroll',
				'lazy-loaded media',
				'media reuse audit',
				'pixel-perfect',
				'reference screenshot',
				'reference token table',
				'responsive desktop',
				'screenshot',
				'style_policy',
				'visual',
				'visual_build_gate',
			] as $needle
		) {
			if ( str_contains( strtolower( $instruction ), strtolower( $needle ) ) ) {
				return true;
			}
		}

		return false;
	}
}
