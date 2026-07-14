<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

use Stonewright\WpMcp\Abilities\Design\ImplementationContract;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
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

		$is_visual = self::is_visual_task( $task, $surface, $intent );

		$visual_quality_contract = $is_visual ? self::visual_quality_contract() : self::visual_context_stub();
		$visual_build_gate       = $is_visual ? self::visual_build_gate() : self::visual_build_gate_stub();

		return [
			'ok'                       => true,
			'context_token'            => $token['token'],
			'expires_at'               => $token['expires_at'],
			'instructions'             => AgentInstructions::default( $is_visual ),
			'mcp_tool_naming'          => self::mcp_tool_naming(),
			'tool_profile_hint'        => ToolProfile::profile_hint( $task, $surface, $intent ),
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
			'recommended_external_mcps'      => self::recommended_external_mcps( $is_visual ),
			'visual_quality_contract'        => $visual_quality_contract,
			'visual_build_gate'              => $visual_build_gate,
			'design_implementation_contract' => ImplementationContract::contract(),
			'required_followups'             => self::required_followups( $surface, $intent, $is_visual ),
		];
	}

	public static function is_visual_task( string $task, string $surface = 'unknown', string $intent = 'unknown' ): bool {
		$surface = strtolower( trim( $surface ) );
		$intent  = strtolower( trim( $intent ) );
		$query   = self::normalise( $task . ' ' . $surface . ' ' . $intent );

		$visual_sources = [
			'design',
			'design system',
			'figma',
			'image',
			'mockup',
			'reference',
			'screenshot',
			'wireframe',
		];
		if ( self::has_any_term( $query, $visual_sources ) ) {
			return true;
		}

		$visual_work_terms = [
			'breakpoint',
			'build',
			'create',
			'front end',
			'frontend',
			'hero',
			'implement',
			'landing page',
			'layout',
			'mobile',
			'page',
			'pixel',
			'prompt',
			'responsive',
			'section',
			'tablet',
			'template',
			'visual',
		];

		return in_array( $surface, [ 'elementor', 'gutenberg', 'wordpress' ], true )
			&& (
				in_array( $intent, [ 'write', 'create', 'update', 'design' ], true )
				|| self::has_any_term( $query, $visual_work_terms )
			)
			&& self::has_any_term( $query, $visual_work_terms );
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
			if ( ! Memory::is_active( $entry ) ) {
				continue;
			}
			$haystack = self::normalise(
				(string) ( $entry['scope'] ?? '' ) . ' ' .
				(string) ( $entry['topic'] ?? '' ) . ' ' .
				(string) ( $entry['memory_key'] ?? '' ) . ' ' .
				(string) ( $entry['name'] ?? '' ) . ' ' .
				self::stringify( $entry['value'] ?? null )
			);
			$score       = self::score( $query, $haystack );
			$type        = (string) ( $entry['type'] ?? 'generic' );
			$scope_match = '' !== $surface && strtolower( (string) ( $entry['scope'] ?? '' ) ) === strtolower( $surface );
			if ( 'user' === $type || $score > 0 || $scope_match ) {
				$entry['_score']    = $score;
				$entry['_priority'] = self::memory_priority( $type ) + (int) ( $entry['precedence'] ?? 0 );
				$rows[]             = $entry;
			}
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => ( (int) $b['_priority'] <=> (int) $a['_priority'] )
				?: ( (int) $b['_score'] <=> (int) $a['_score'] )
		);

		$selected = array_slice( $rows, 0, 5 );
		return array_map(
			static function ( array $entry ) use ( $selected ): array {
				$topic     = self::normalise( (string) ( $entry['topic'] ?? $entry['memory_key'] ?? '' ) );
				$value_hash = hash( 'sha256', wp_json_encode( $entry['value'] ?? null ) ?: '' );
				$conflicts = [];
				foreach ( $selected as $other ) {
					if ( (int) ( $other['id'] ?? 0 ) === (int) ( $entry['id'] ?? 0 ) ) {
						continue;
					}
					$other_topic = self::normalise( (string) ( $other['topic'] ?? $other['memory_key'] ?? '' ) );
					$other_hash  = hash( 'sha256', wp_json_encode( $other['value'] ?? null ) ?: '' );
					if ( '' !== $topic && $topic === $other_topic && $value_hash !== $other_hash ) {
						$conflicts[] = (int) ( $other['id'] ?? 0 );
					}
				}
				return [
					'id'                  => (int) ( $entry['id'] ?? 0 ),
					'type'                => (string) ( $entry['type'] ?? '' ),
					'scope'               => (string) ( $entry['scope'] ?? '' ),
					'topic'               => (string) ( $entry['topic'] ?? '' ),
					'memory_key'          => (string) ( $entry['memory_key'] ?? '' ),
					'name'                => (string) ( $entry['name'] ?? '' ),
					'confidence'          => (float) ( $entry['confidence'] ?? 0 ),
					'version_fingerprint' => (string) ( $entry['version_fingerprint'] ?? '' ),
					'precedence_rank'     => (int) $entry['_priority'],
					'conflict_with'       => $conflicts,
					'body_tool'           => 'stonewright/memory-get',
				];
			},
			$selected
		);
	}

	private static function memory_priority( string $type ): int {
		return match ( $type ) {
			'user'      => 5000,
			'feedback'  => 4000,
			'project'   => 3000,
			'reference' => 2000,
			default     => 1000,
		};
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
				'stonewright/tool-profile'      => 'stonewright-tool-profile',
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
	private static function recommended_external_mcps( bool $is_visual ): array {
		if ( ! $is_visual ) {
			return [];
		}

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
			'section_batching'                 => self::section_batching_contract(),
			'playwright_mcp_gate'              => [
				'timing'            => 'before_first_write',
				'required_surfaces' => [ 'elementor', 'gutenberg', 'wordpress' ],
				'task_keywords'     => [ 'figma', 'design', 'pixel', 'responsive', 'visual', 'screenshot' ],
				'pass_condition'    => 'A Playwright/browser MCP tool is visible in the AI client and can capture the target URL.',
			],
			'principle'                        => 'Do not implement visual work blind. Measure the reference, build with native controls, screenshot the result, then iterate.',
			'required_steps'                   => [
				'Extract measured tokens from the reference screenshot before writing: canvas size, section bounds, max widths, colors, typography, spacing, and asset crop bounds.',
				'Treat the Figma layer tree as extraction context, not implementation authority; build the WordPress structure that best matches the visual reference images.',
				'For long designs, capture section reference screenshots and compare section-by-section before attempting full-page signoff.',
				'Before any visual write, verify Playwright/browser MCP is connected; if not, install it, restart the client, and stop until the tool appears.',
				'Before uploading assets, audit existing WordPress media by filename, alt text, dimensions, and likely source layer so already-downloaded assets are reused.',
				'Before the first Elementor write, create a global-style plan: reusable color/typography tokens, Elementor kit updates if approved, and page-local values that should remain local.',
				'Normalize Figma, screenshot, image, or brief observations into DesignEvidence 1.0 and call stonewright/design-native-plan before compiling any builder settings.',
				'Block buttons, CTAs, links, navigation, forms, and images whose real action, data source, or asset policy is unresolved.',
				'Implement the complete native phase first; emit custom CSS, JS, or PHP only as a separate unapplied proposal that requires explicit approval, diff, risk, rollback, and tests.',
				'Create a section-by-section implementation plan with outer section, inner max-width container, rows/columns, widget choices, and responsive breakpoints.',
				'Before the first write, produce a section-by-section plan mapping Figma nodes to native Elementor widgets, containers, breakpoints, assets, and any approved CSS classes.',
				'Implement visual pages in batches of one section at a time, or two sections only when they are simple and tightly coupled.',
				'After each section batch, verify desktop, tablet, and mobile breakpoints before starting the next batch.',
				'Auto-continue to the next section batch when screenshots, overflow checks, and diagnostics pass; do not wait for user approval between batches.',
				'Use the exact Elementor control keys from widget schema or stonewright/elementor-describe-widget; do not invent CSS-like setting names.',
				'Use stonewright/elementor-schema and schema-validated batch mutation for all widgets, including third-party widgets. Per-widget add abilities are deprecated compatibility tools.',
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
				'Mirroring broken design-tool grouping instead of reproducing the visible layout in the reference screenshot.',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function visual_build_gate(): array {
		return [
			'blocks_completion_without_evidence' => true,
			'section_batching'                   => self::section_batching_contract(),
			'required_before_discovery'          => [
				'Call stonewright-context-bootstrap before Figma, browser, or write tools unless stonewright-workflow-preflight is the explicit bootstrap fast path.',
				'Read matched skills, memory, visual_quality_contract, and required_followups before extracting design data.',
			],
			'source_authority'                  => [
				'primary'           => 'reference_screenshots',
				'secondary'         => [
					'figma_styles',
					'figma_tokens',
					'figma_assets',
					'figma_text',
				],
				'not_authoritative' => [
					'figma_layer_structure',
					'figma_group_names',
					'figma_auto_layout_nesting',
				],
			],
			'evidence_required_before_first_write' => [
				'design_evidence_1_0',
				'native_plan_without_blockers',
				'figma_token_table',
				'existing_media_asset_audit',
				'section_implementation_plan',
				'section_reference_screenshots',
				'section_batch_plan',
			],
			'evidence_required_before_completion' => [
				'desktop_screenshot_diff',
				'tablet_screenshot_diff',
				'mobile_screenshot_diff',
				'logged_out_viewport_checks',
			],
			'completion_stop_conditions'        => [
				'Do not write visual pages from memory; stop if no measured Figma/reference token table exists.',
				'Do not copy the Figma layer tree as the WordPress or Elementor tree when the visual screenshot implies a different, cleaner structure.',
				'Do not upload duplicate assets until existing WordPress media has been checked by filename, alt text, dimensions, and visible crop.',
				'Do not start repeated single-widget patching until a section plan maps reference nodes to native Elementor widgets and breakpoints.',
				'Do not implement more than two visual page sections in one write-and-verify batch.',
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
				'section_reference_screenshots' => [
					'section_id',
					'reference_image',
					'crop_bounds',
					'viewport',
					'expected_visual_structure',
				],
				'section_batch_plan'            => [
					'batch_id',
					'section_ids',
					'max_sections',
					'reference_viewports',
					'write_tool',
					'verification_breakpoints',
					'auto_continue_when_passed',
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
	 * @return array<string, mixed>
	 */
	private static function section_batching_contract(): array {
		return [
			'preferred_sections_per_pass'       => 1,
			'max_sections_per_pass'             => 2,
			'auto_continue_when_batch_passes'   => true,
			'applies_to_sources'                => [ 'figma', 'image', 'prompt', 'design_system' ],
			'breakpoints'                       => [
				'elementor' => [ 'desktop', 'tablet', 'mobile' ],
				'gutenberg' => [ 'desktop', 'tablet', 'mobile' ],
			],
			'pass_condition'                    => 'Batch screenshots, renderer diagnostics, and overflow checks pass at all relevant breakpoints.',
			'approval_policy'                   => 'Do not wait for user approval between passing section batches; continue automatically until all requested sections are implemented or a real operator boundary appears.',
			'operator_boundaries_still_required' => [
				'production-safe confirmation tokens',
				'missing credentials or unavailable MCP tools',
				'explicit approval for custom CSS, SVG enablement, or other global/security-affecting changes',
			],
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function visual_context_stub(): array {
		return [
			'status' => 'exempt',
			'reason' => 'Non-visual task',
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function visual_build_gate_stub(): array {
		return [
			'status' => 'exempt',
			'reason' => 'Non-visual task',
		];
	}

	/**
	 * @return array<int, string>
	 */
	private static function required_followups( string $surface, string $intent, bool $is_visual ): array {
		$steps = [
			'Read all matched skill playbooks and memory entries before acting.',
			'If the user corrects the agent or a repeatable mistake is detected, call stonewright/learning-record.',
			'Use MCP tool names with hyphens, for example stonewright-context-bootstrap, not slash-separated ability names.',
			'Use fast_path.tool_profile from stonewright/workflow-preflight before making a separate stonewright/tool-profile call; call tool-profile only to switch or verify a compact profile.',
		];

		if ( $is_visual ) {
			$steps[] = 'When a task needs browser testing, screenshots, or visual inspection, ensure the external Playwright MCP is installed and connected before implementation.';
			$steps[] = 'If the external Playwright MCP is unavailable during a visual implementation task, stop before writing and tell the user the exact MCP setup command plus restart requirement.';
			$steps[] = 'For design-derived backgrounds, create an asset selection plan and never use a full-page screenshot as a section background.';
			$steps[] = 'Implement visual pages in one-section batches, or two sections only when simple and tightly coupled; auto-continue after passing desktop, tablet, and mobile verification.';
			$steps[] = 'Before declaring a visual task done, verify no horizontal overflow with document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1 at all requested breakpoints.';
			$steps[] = 'For pixel-perfect tasks, report the visual_build_gate evidence before completion: token table, asset audit, section plan, screenshot deltas, and logged-out viewport checks.';
			$steps[] = 'If SVG uploads are blocked, do not create sandbox or mu-plugin workarounds without explicit user approval.';
		}

		if ( 'elementor' === $surface ) {
			$steps[] = 'Call stonewright/design-native-plan with normalized DesignEvidence before choosing or writing Elementor widgets.';
			if ( $is_visual ) {
				$steps[] = 'Before building design-derived pages, plan Elementor kit colors/typography first; if site-wide changes are approved, update the active kit before writing page elements.';
			}
			$steps[] = 'Configure relevant Content, Style, and Advanced controls, including responsive values.';
			$steps[] = 'When the guide asks for online research, use official Elementor documentation before writing.';
		}

		if ( in_array( $surface, [ 'wordpress', 'elementor', 'gutenberg', 'acf', 'cpt-ui', 'wp-cli' ], true ) ) {
			$steps[] = 'Use stonewright/wp-cli-status and stonewright/wp-cli-discover before relying on WP-CLI commands that may not be installed.';
			$steps[] = 'When the Node companion exposes stonewright-wp-cli-* MCP tools, use those direct aliases before assuming the WordPress-side HTTP bridge on port 8765 is required.';
			$steps[] = 'Use stonewright/wp-cli-run for tokenized WordPress commands; use stonewright/php-execute for PHP runtime snippets instead of WP-CLI eval, shell, package, --exec, or --require entry points.';
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

	/**
	 * @param list<string> $terms
	 */
	private static function has_any_term( string $normalised_text, array $terms ): bool {
		foreach ( $terms as $term ) {
			$needle = self::normalise( $term );
			if ( '' !== $needle && preg_match( '/(^| )' . preg_quote( $needle, '/' ) . '( |$)/', $normalised_text ) ) {
				return true;
			}
		}
		return false;
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
