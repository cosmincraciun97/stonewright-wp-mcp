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
			'- Before Elementor/Figma implementation, call stonewright/elementor-knowledge-search or stonewright/elementor-describe-widget when widget behavior, settings, Theme Builder, or editor V3/V4 behavior is uncertain.',
			'- Before choosing a widget from a prompt, image, or Figma node, call stonewright/widget-intent-resolve with the prompt or figma_node so Stonewright selects the native Elementor intent instead of the model guessing.',
			'- Use real Elementor widgets for the detected intent: nav-menu for navigation, countdown for countdowns, social-icons for social rows, icon-list for footer/link/bullet lists. Do not simulate these with headings, buttons, or arbitrary text blocks.',
			'- Do not use Elementor HTML widgets unless the user explicitly asks for HTML and the ability call passes allow_html_widget=true. Use Elementor V3 containers and native widgets first.',
			'- Custom CSS requires explicit user approval before writing. When approved, write organized CSS to the active theme style.css, not inline HTML widgets.',
			'- Build responsive desktop, tablet, and mobile layouts. Headers must use sticky settings where requested, real desktop/tablet/mobile visibility controls, and mobile navigation must use the native hamburger/dropdown behavior.',
			'- Preserve Figma layout intent: full-width outer sections, centered max-width inner containers, rows for two-column hero/content areas, native gallery widgets for galleries, native form widgets for forms, and no extra borders on assets that already include their border artwork.',
			'- For Figma backgrounds: if the background is a flat color, set it as an Elementor background color; if it is a simple linear gradient, use Elementor gradient controls; if it contains glow, radial blur, complex shadow, or blended effects, export the exact Figma background asset and place it on the relevant container background.',
			'- For Figma assets, export and place the exact sub-node asset required by the design. Do not use a parent composite screenshot when a child node is the actual asset.',
			'- For headers and footers, create separate Theme Builder templates and set include/general conditions; do not leave theme chrome as a substitute.',
			'- Validate every generated DesignSpec before render and snapshot before every Elementor or theme-backed write.',
			'- When a reference image or Figma screenshot exists, run stonewright/qa-verify-against-reference after the write and use the diff report to iterate.',
			'- Do not claim pixel-perfect, complete, or done until QA verification has run and the remaining diff is explicitly reported.',
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
		$parts[] = 'Only a compact skill index is injected to reduce token usage. Before ANY action, check the index; if a matching skill exists, call `stonewright/skills-get` for the full playbook and follow it exactly.';
		$parts[] = 'To list all available skills: call `stonewright/skills-list`.';
		$parts[] = 'To create or update a skill: call `stonewright/skills-save`.';
		$parts[] = 'To read an individual skill: call `stonewright/skills-get` with the slug.';

		return implode( "\n", $parts );
	}
}
