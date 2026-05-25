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
			'- If the user corrects the agent, or the agent detects a repeatable mistake, call stonewright/learning-record so future tasks update persistent memory and, when useful, skills.',
			'- For browser testing, screenshots, or visual inspection, use an external Playwright MCP. If the MCP client has no browser tool available, install/connect the external Playwright MCP with command `npx @playwright/mcp@latest` before implementation. Stonewright itself does not expose browser or screenshot tools.',
			'- For WordPress implementation or debugging work, call stonewright/wp-cli-status first when CLI availability is unknown, then stonewright/wp-cli-discover before choosing plugin-specific commands.',
			'- Use stonewright/wp-cli-run for tokenized WP-CLI commands that speed up WordPress, Elementor, Gutenberg, ACF, CPT UI, cache, rewrite, plugin, option, post, media, menu, and taxonomy tasks. Pass stonewright_context_token for every write command.',
			'- Do not use wp eval, wp eval-file, wp shell, wp package, --exec, or --require through Stonewright. The companion blocks arbitrary PHP and shell entry points by design.',
			'- Before Elementor implementation, call stonewright/elementor-knowledge-search or stonewright/elementor-describe-widget when widget behavior, settings, Theme Builder, editor V3/V4 behavior, or documentation freshness is uncertain.',
			'- Before choosing a widget from a prompt, design reference, image, or task, call stonewright/widget-intent-resolve so Stonewright selects the native Elementor intent instead of the model guessing.',
			'- Before writing Elementor elements, call stonewright/elementor-widget-implementation-guide with the task, candidate widgets, and design context.',
			'- Use real Elementor widgets for the detected intent: nav-menu for navigation, countdown for countdowns, social-icons for social rows, icon-list for footer/link/bullet lists. Do not simulate these with headings, buttons, or arbitrary text blocks.',
			'- Do not use Elementor HTML widgets unless the user explicitly asks for HTML and the ability call passes allow_html_widget=true. Use Elementor V3 containers and native widgets first.',
			'- Do not only place widgets. Configure the relevant Content, Style, and Advanced controls, including animations, absolute/fixed positioning, width, z-index, motion effects, background and background overlay, borders, responsive values, attributes, transform, display conditions, cache settings, order, align self, margin, and padding.',
			'- If internal widget docs, harvested marketing docs, or stonewright/elementor-describe-widget are incomplete or stale, research official Elementor documentation online before configuring the widget.',
			'- Custom CSS requires explicit user approval before writing. When approved, write organized CSS to the active theme style.css, not inline HTML widgets.',
			'- Build responsive desktop, tablet, and mobile layouts. Headers must use sticky settings where requested, real desktop/tablet/mobile visibility controls, and mobile navigation must use the native hamburger/dropdown behavior.',
			'- Preserve design layout intent: full-width outer sections, centered max-width inner containers, rows for two-column hero/content areas, native gallery widgets for galleries, native form widgets for forms, and no extra borders on assets that already include their border artwork.',
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
