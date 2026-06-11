<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

use Stonewright\WpMcp\Core\AgentInstructions;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Skills\Skills;

/**
 * Builds the mandatory context packet agents must read before Stonewright work.
 */
final class ContextBuilder {

	/**
	 * @return array<string, mixed>
	 */
	public static function build( string $task, string $surface = 'unknown', string $intent = 'unknown' ): array {
		$token = ContextToken::issue( $task );

		$matched_skills = self::matched_skills( $task, $surface );
		$matched_memory = self::matched_memory( $task, $surface );

		return [
			'ok'                       => true,
			'context_token'            => $token['token'],
			'expires_at'               => $token['expires_at'],
			'instructions'             => AgentInstructions::default(),
			'mcp_tool_naming'          => self::mcp_tool_naming(),
			'matched_skills'           => array_map(
				static fn( array $skill ): array => [
					'slug'        => (string) ( $skill['slug'] ?? '' ),
					'title'       => (string) ( $skill['title'] ?? '' ),
					'description' => (string) ( $skill['description'] ?? '' ),
				],
				$matched_skills
			),
			'matched_skill_playbooks'  => array_map(
				static fn( array $skill ): array => [
					'slug'    => (string) ( $skill['slug'] ?? '' ),
					'title'   => (string) ( $skill['title'] ?? '' ),
					'content' => (string) ( $skill['content'] ?? '' ),
				],
				$matched_skills
			),
			'memory_entries'           => $matched_memory,
			'specializations'          => SpecializationCatalog::match( $task, $surface ),
			'recommended_external_mcps' => self::recommended_external_mcps(),
			'visual_quality_contract'  => self::visual_quality_contract(),
			'visual_build_gate'        => self::visual_build_gate(),
			'required_followups'       => self::required_followups( $surface, $intent ),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function matched_skills( string $task, string $surface ): array {
		$skills = Skills::list_agentic();
		if ( [] === $skills ) {
			return [];
		}

		$query = self::normalise( $task . ' ' . $surface );
		$rows  = [];
		foreach ( $skills as $skill ) {
			$haystack = self::normalise(
				(string) ( $skill['slug'] ?? '' ) . ' ' .
				(string) ( $skill['title'] ?? '' ) . ' ' .
				(string) ( $skill['description'] ?? '' )
			);
			$score = self::score( $query, $haystack );
			if ( $score > 0 || ( 'elementor' === $surface && str_contains( $haystack, 'elementor' ) ) ) {
				$skill['_score'] = $score;
				$rows[]          = $skill;
			}
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => ( (int) $b['_score'] <=> (int) $a['_score'] )
		);

		return array_slice( $rows, 0, 5 );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function matched_memory( string $task, string $surface ): array {
		if ( ! get_option( 'stonewright_memory_enabled', true ) ) {
			return [];
		}

		$entries = Memory::list_all( 50, 0 );
		$query   = self::normalise( $task . ' ' . $surface );
		$rows    = [];

		foreach ( $entries as $entry ) {
			$haystack = self::normalise(
				(string) ( $entry['scope'] ?? '' ) . ' ' .
				(string) ( $entry['memory_key'] ?? '' ) . ' ' .
				(string) ( $entry['name'] ?? '' ) . ' ' .
				self::stringify( $entry['value'] ?? null )
			);
			$score = self::score( $query, $haystack );
			if ( $score > 0 || in_array( (string) ( $entry['type'] ?? '' ), [ 'feedback', 'project', 'reference' ], true ) ) {
				$entry['_score'] = $score;
				$rows[]          = $entry;
			}
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => ( (int) $b['_score'] <=> (int) $a['_score'] )
		);

		return array_map(
			static function ( array $entry ): array {
				unset( $entry['_score'] );
				return $entry;
			},
			array_slice( $rows, 0, 10 )
		);
	}

	/**
	 * @return array<int, string>
	 */
	/**
	 * @return array<string, mixed>
	 */
	private static function mcp_tool_naming(): array {
		return [
			'rule'     => 'MCP tool names replace ability slashes with hyphens.',
			'examples' => [
				'stonewright/context-bootstrap' => 'stonewright-context-bootstrap',
				'stonewright/wp-cli-status'     => 'stonewright-wp-cli-status',
				'stonewright/wp-cli-discover'   => 'stonewright-wp-cli-discover',
				'stonewright/wp-cli-run'        => 'stonewright-wp-cli-run',
				'stonewright/skills-get'        => 'stonewright-skills-get',
				'stonewright/learning-record'   => 'stonewright-learning-record',
			],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function recommended_external_mcps(): array {
		return [
			[
				'id'            => 'playwright',
				'name'          => 'External Playwright MCP',
				'purpose'       => 'Browser testing, screenshots, and visual inspection outside Stonewright.',
				'command'       => 'npx',
				'args'          => [ '-y', '@playwright/mcp@latest', '--caps=testing,vision,devtools' ],
				'claude_code'   => 'claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools',
				'setup_steps'   => [
					'Install external Playwright MCP before visual work: claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools',
					'Restart the AI client after adding the MCP server so the tool list refreshes.',
					'Verify a Playwright/browser tool is visible before the first Stonewright write.',
				],
				'required_when' => 'Use when the task needs browser interaction, screenshots, visual checks, or front-end debugging.',
				'boundary'      => 'Keep this as a separate MCP server; do not add browser or screenshot abilities back into Stonewright.',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function visual_quality_contract(): array {
		return [
			'hard_stop_if_browser_unavailable' => true,
			'playwright_mcp_gate'              => [
				'timing'            => 'before_first_write',
				'required_surfaces' => [ 'elementor', 'gutenberg', 'wordpress' ],
				'task_keywords'     => [ 'figma', 'design', 'pixel', 'responsive', 'visual', 'screenshot' ],
				'pass_condition'    => 'A Playwright/browser MCP tool is visible in the AI client and can capture the target URL.',
			],
			'principle'                        => 'Do not implement visual work blind. Measure the reference, build with native controls, screenshot the result, then iterate.',
			'required_steps'                   => [
				'Extract measured tokens from the reference screenshot before writing: canvas size, section bounds, max widths, colors, typography, spacing, and asset crop bounds.',
				'Before any visual write, verify Playwright/browser MCP is connected; if not, install it, restart the client, and stop until the tool appears.',
				'Before uploading assets, audit existing WordPress media by filename, alt text, dimensions, and likely source layer so already-downloaded assets are reused.',
				'Before the first Elementor write, create a global-style plan: reusable color/typography tokens, Elementor kit updates if approved, and page-local values that should remain local.',
				'Create a section-by-section implementation plan with outer section, inner max-width container, rows/columns, widget choices, and responsive breakpoints.',
				'Before the first write, produce a section-by-section plan mapping Figma nodes to native Elementor widgets, containers, breakpoints, assets, and any approved CSS classes.',
				'Use the exact Elementor control keys from widget schema or stonewright/elementor-describe-widget; do not invent CSS-like setting names.',
				'Use dedicated stonewright/elementor-add-* widget abilities for known widgets. Use stonewright/elementor-v3-add-widget only for unknown or third-party widgets.',
				'Set page template to Elementor Canvas when the user asks for no header and no footer.',
				'Do not use the design canvas width as a fixed live page width; translate it into max-width, percentage widths, and responsive padding.',
				'Before full-page screenshots, scroll through the page or otherwise preload lazy-loaded media so missing assets are not mistaken for layout failures.',
				'Fail the implementation if document.documentElement.scrollWidth is greater than document.documentElement.clientWidth by more than 1px at desktop, tablet, or mobile viewport.',
				'Verify the public logged-out page at desktop, tablet, and mobile sizes; admin bars, editor chrome, and authenticated-only UI do not count as responsive proof.',
				'After each write pass, capture a browser screenshot at the same viewport as the reference and list visible deltas: width, alignment, spacing, color, font size, overflow, and missing assets.',
				'Iterate until the screenshot matches the reference in the main layout before declaring completion.',
			],
			'failure_patterns'                 => [
				'Full-width inner content when the reference has a narrow centered canvas.',
				'Horizontal scrollbar or page content wider than viewport.',
				'WordPress page title or theme chrome visible when Elementor Canvas/no header/footer was requested.',
				'White/default form fields when the reference uses translucent fields.',
				'Full-page screenshot used as a background asset.',
				'Legacy or invented Elementor settings such as icon instead of selected_icon, icon_primary_color instead of primary_color, or width instead of Advanced layout width keys.',
				'Skipping screenshot verification because the browser MCP is unavailable.',
				'Starting repeated single-widget writes before proving the browser verification loop works.',
				'Declaring pixel-perfect without a reference-to-live screenshot delta list for every requested breakpoint.',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function visual_build_gate(): array {
		return [
			'blocks_completion_without_evidence' => true,
			'required_before_discovery'          => [
				'Call stonewright-context-bootstrap before Figma, browser, or write tools unless stonewright-workflow-preflight is the explicit bootstrap fast path.',
				'Read matched skills, memory, visual_quality_contract, and required_followups before extracting design data.',
			],
			'evidence_required_before_first_write' => [
				'figma_token_table',
				'existing_media_asset_audit',
				'section_implementation_plan',
			],
			'evidence_required_before_completion' => [
				'desktop_screenshot_diff',
				'tablet_screenshot_diff',
				'mobile_screenshot_diff',
				'logged_out_viewport_checks',
			],
			'completion_stop_conditions'        => [
				'Do not write visual pages from memory; stop if no measured Figma/reference token table exists.',
				'Do not upload duplicate assets until existing WordPress media has been checked by filename, alt text, dimensions, and visible crop.',
				'Do not start repeated single-widget patching until a section plan maps reference nodes to native Elementor widgets and breakpoints.',
				'Do not declare pixel-perfect or responsive unless logged-out desktop, tablet, and mobile viewport checks pass without theme/admin chrome contamination.',
				'Do not sign off while any required visual delta lacks a status: matched, fixed, accepted limitation, or blocked by missing user approval.',
			],
			'evidence_template'                 => [
				'figma_token_table'             => [
					'section_id',
					'node_id',
					'viewport_width',
					'section_bounds',
					'max_width',
					'colors',
					'typography',
					'spacing',
					'assets',
				],
				'existing_media_asset_audit'    => [
					'asset_name',
					'figma_node_id',
					'expected_dimensions',
					'matched_media_id_or_url',
					'reuse_or_upload_decision',
				],
				'section_implementation_plan'   => [
					'section_id',
					'outer_container',
					'inner_container',
					'native_widgets',
					'responsive_breakpoints',
					'approved_css_classes',
				],
				'screenshot_diff'              => [
					'viewport',
					'reference_screenshot',
					'live_screenshot',
					'delta_px',
					'delta_type',
					'status',
				],
				'logged_out_viewport_checks'    => [
					'viewport',
					'public_url',
					'client_width',
					'scroll_width',
					'admin_bar_absent',
					'overflow_passed',
				],
			],
		];
	}

	/**
	 * @return array<int, string>
	 */
	private static function required_followups( string $surface, string $intent ): array {
		$steps = [
			'Read all matched skill playbooks and memory entries before acting.',
			'If the user corrects the agent or a repeatable mistake is detected, call stonewright/learning-record.',
			'Use MCP tool names with hyphens, for example stonewright-context-bootstrap, not slash-separated ability names.',
			'When a task needs browser testing, screenshots, or visual inspection, ensure the external Playwright MCP is installed and connected before implementation.',
			'If the external Playwright MCP is unavailable during a visual implementation task, stop before writing and tell the user the exact MCP setup command plus restart requirement.',
			'For design-derived backgrounds, create an asset selection plan and never use a full-page screenshot as a section background.',
			'Before declaring a visual task done, verify no horizontal overflow with document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1 at all requested breakpoints.',
			'For pixel-perfect tasks, report the visual_build_gate evidence before completion: token table, asset audit, section plan, screenshot deltas, and logged-out viewport checks.',
			'If SVG uploads are blocked, do not create sandbox or mu-plugin workarounds without explicit user approval.',
		];

		if ( 'elementor' === $surface ) {
			$steps[] = 'Call stonewright/widget-intent-resolve before choosing Elementor widgets.';
			$steps[] = 'Call stonewright/elementor-widget-implementation-guide before writing Elementor elements.';
			$steps[] = 'Before building design-derived pages, plan Elementor kit colors/typography first; if site-wide changes are approved, update the active kit before writing page elements.';
			$steps[] = 'Configure relevant Content, Style, and Advanced controls, including responsive values.';
			$steps[] = 'When the guide asks for online research, use official Elementor documentation before writing.';
		}

		if ( in_array( $surface, [ 'wordpress', 'elementor', 'gutenberg', 'acf', 'cpt-ui', 'wp-cli' ], true ) ) {
			$steps[] = 'Use stonewright/wp-cli-status and stonewright/wp-cli-discover before relying on WP-CLI commands that may not be installed.';
			$steps[] = 'When the Node companion exposes stonewright-wp-cli-* MCP tools, use those direct aliases before assuming the WordPress-side HTTP bridge on port 8765 is required.';
			$steps[] = 'Use stonewright/wp-cli-run for safe tokenized WordPress commands; never use wp eval, wp eval-file, wp shell, wp package, --exec, or --require.';
			$steps[] = 'If stonewright/wp-cli-status returns available=false, use direct companion_wp_cli_* MCP tools when exposed, otherwise use normal Stonewright REST abilities instead of sandbox/REST workarounds.';
		}

		if ( in_array(
			$surface,
			[ 'wordpress', 'acf', 'acpt', 'meta-box', 'metabox', 'ase', 'pods', 'woocommerce', 'fields', 'content-model' ],
			true
		) ) {
			$steps[] = 'For ACF, ACPT, Meta Box, ASE, Pods, WooCommerce, or custom field work, call stonewright/workflow-preflight and follow the returned specialization guidance before writing.';
			$steps[] = 'For content-model work, use stonewright/skills-get with stonewright-content-model-integrations when matched.';
			$steps[] = 'For WooCommerce catalog work, use stonewright/skills-get with stonewright-woocommerce-catalog when matched.';
		}

		if ( in_array( $intent, [ 'write', 'delete' ], true ) ) {
			$steps[] = 'Pass stonewright_context_token to every write or destructive Stonewright ability.';
		}

		return $steps;
	}

	private static function normalise( string $text ): string {
		return trim( preg_replace( '/[^a-z0-9]+/i', ' ', strtolower( $text ) ) ?? '' );
	}

	private static function score( string $query, string $haystack ): int {
		$score = 0;
		foreach ( array_filter( explode( ' ', $query ) ) as $term ) {
			if ( strlen( $term ) >= 3 && str_contains( $haystack, $term ) ) {
				++$score;
			}
		}
		return $score;
	}

	private static function stringify( mixed $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			return implode( ' ', array_map( [ self::class, 'stringify' ], $value ) );
		}
		return '';
	}
}
