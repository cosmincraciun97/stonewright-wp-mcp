<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Skills\Skills;

/**
 * Default MCP-facing instructions that travel with the Stonewright server.
 * Includes the core build discipline, optional custom site instructions,
 * and all enabled site skills injected as playbooks.
 */
final class AgentInstructions {

	public static function default(): string {
		$parts = [
			'Stonewright build discipline:',
			'- Before Elementor/Figma implementation, call stonewright/elementor-knowledge-search or stonewright/elementor-describe-widget when widget behavior, settings, Theme Builder, or editor V3/V4 behavior is uncertain.',
			'- Use real Elementor widgets for the detected intent: nav-menu for navigation, countdown for countdowns, social-icons for social rows, icon-list for footer/link/bullet lists. Do not simulate these with headings, buttons, or arbitrary text blocks.',
			'- For Figma assets, export and place the exact sub-node asset required by the design. Do not use a parent composite screenshot when a child node is the actual asset.',
			'- For headers and footers, create separate Theme Builder templates and set include/general conditions; do not leave theme chrome as a substitute.',
			'- Validate every generated DesignSpec before render and snapshot before every Elementor or theme-backed write.',
			'- When a reference image or Figma screenshot exists, run stonewright/qa-verify-against-reference after the write and use the diff report to iterate.',
			'- Do not claim pixel-perfect, complete, or done until QA verification has run and the remaining diff is explicitly reported.',
		];

		// Append custom site instructions when enabled.
		$instructions_enabled = (bool) get_option( 'stonewright_custom_instructions_enabled', true );
		$custom_instructions  = (string) get_option( 'stonewright_custom_instructions', '' );

		if ( $instructions_enabled && '' !== $custom_instructions ) {
			$parts[] = '';
			$parts[] = '## Site-specific instructions';
			$parts[] = '';
			$parts[] = $custom_instructions;
		}

		// Append all enabled site skills as playbooks.
		$skills_block = Skills::instructions_block();
		if ( '' !== $skills_block ) {
			$parts[] = $skills_block;
		}

		// Meta-skill: teach the LLM HOW to use skills regardless of whether
		// any site skills are currently enabled.
		$parts[] = '';
		$parts[] = '## How to use Stonewright Skills';
		$parts[] = '';
		$parts[] = 'Skills are site-specific playbooks you MUST follow when the current task matches their description.';
		$parts[] = 'Before ANY action, check if an enabled skill applies. If a matching skill exists, follow it exactly — skills override your default behaviour.';
		$parts[] = 'To list all available skills: call `stonewright/skills-list`.';
		$parts[] = 'To create or update a skill: call `stonewright/skills-save`.';
		$parts[] = 'To read an individual skill: call `stonewright/skills-get` with the slug.';

		return implode( "\n", $parts );
	}
}
