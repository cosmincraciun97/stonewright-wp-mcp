<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\ElementorV3\CapabilitiesSummary;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Context\SpecializationCatalog;
use Stonewright\WpMcp\Security\Permissions;

/**
 * One-call task preflight for faster, lower-token Stonewright workflows.
 *
 * @stonewright-status stable
 */
final class WorkflowPreflight extends AbilityKernel {

	public function name(): string {
		return 'stonewright/workflow-preflight';
	}

	public function label(): string {
		return __( 'Stonewright workflow preflight', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns compact task context, auth guidance, mode, and first-pass tool choices so MCP agents can start with fewer discovery calls.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'task' ],
			'properties'           => [
				'task'    => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'The user request or task summary.',
				],
				'surface' => [
					'type'        => 'string',
					'default'     => 'unknown',
					'description' => 'Primary work surface, e.g. elementor, gutenberg, wordpress, wp-cli.',
				],
				'intent'  => [
					'type'        => 'string',
					'default'     => 'unknown',
					'description' => 'Task intent, e.g. read, write, delete, debug.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'context_token' => [ 'type' => 'string' ],
				'expires_at'    => [ 'type' => 'string' ],
				'mode'          => [ 'type' => 'string' ],
				'auth_guidance' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'fast_path'     => [ 'type' => 'object' ],
				'elementor'     => [ 'type' => 'object' ],
				'site'          => [ 'type' => 'object' ],
				'context'       => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'context_token', 'mode', 'auth_guidance', 'fast_path' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$task = isset( $args['task'] ) && is_string( $args['task'] ) ? trim( $args['task'] ) : '';
		if ( '' === $task ) {
			return $this->error( 'missing_task', __( 'A non-empty task is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$surface = isset( $args['surface'] ) && is_string( $args['surface'] ) ? strtolower( trim( $args['surface'] ) ) : 'unknown';
		$intent  = isset( $args['intent'] ) && is_string( $args['intent'] ) ? strtolower( trim( $args['intent'] ) ) : 'unknown';
		$context = ContextBuilder::build(
			$task,
			'' !== $surface ? $surface : 'unknown',
			'' !== $intent ? $intent : 'unknown'
		);
		$mode            = (string) get_option( 'stonewright_mode', 'development' );
		$specializations = SpecializationCatalog::match( $task, $surface );
		$task_profile    = self::task_profile( $task, $surface, $intent, $mode, $specializations );
		$recommended     = self::recommended_tools( $task_profile );

		$elementor = ( new CapabilitiesSummary() )->execute( [] );
		if ( is_wp_error( $elementor ) ) {
			return $elementor;
		}

		return [
			'ok'            => true,
			'context_token' => (string) ( $context['context_token'] ?? '' ),
			'expires_at'    => (string) ( $context['expires_at'] ?? '' ),
			'mode'          => $mode,
			'auth_guidance' => [
				'Use a WordPress Application Password for HTTP MCP authentication.',
				'Keep the Mcp-Session-Id response header on later JSON-RPC calls.',
				'Use MCP tool names with hyphens, for example stonewright-context-bootstrap.',
			],
			'fast_path'     => [
				'task_profile'          => $task_profile,
				'recommended_tools'     => $recommended,
				'recommended_mcp_tools' => array_map( [ self::class, 'mcp_tool_name' ], $recommended ),
				'call_sequence'         => self::call_sequence( $task, $task_profile ),
				'specializations'       => $specializations,
				'visual_build_gate'     => $context['visual_build_gate'] ?? [],
				'visual_setup'          => self::visual_setup( $task_profile ),
				'batching_rules'        => self::batching_rules( $task_profile ),
				'quality_gates'         => self::quality_gates( $task_profile ),
				'external_mcps'         => [
					'Use external Figma MCP for design extraction.',
					'Use external Playwright/browser MCP for screenshots and visual QA.',
				],
			],
			'elementor'     => $elementor,
			'site'          => [
				'ability_count'        => count( AbilityRegistry::list() ),
				'public_ability_count' => count( AbilityRegistry::enabled_abilities() ),
				'essential_tools_mode' => (bool) get_option( 'stonewright_essential_tools_mode', false ),
				'mcp_server_id'        => 'stonewright',
				'ability_prefix'       => 'stonewright/',
			],
			'context'       => [
				'matched_skills'          => $context['matched_skills'] ?? [],
				'matched_skill_playbooks' => self::compact_playbooks( $context['matched_skill_playbooks'] ?? [] ),
				'memory_entries'          => $context['memory_entries'] ?? [],
				'required_followups'      => $context['required_followups'] ?? [],
			],
		];
	}

	/**
	 * @param mixed $playbooks
	 * @return list<array<string, string|int>>
	 */
	private static function compact_playbooks( mixed $playbooks ): array {
		$out = [];
		foreach ( is_array( $playbooks ) ? $playbooks : [] as $playbook ) {
			if ( ! is_array( $playbook ) ) {
				continue;
			}
			$content = (string) ( $playbook['content'] ?? '' );
			$out[] = [
				'slug'           => (string) ( $playbook['slug'] ?? '' ),
				'title'          => (string) ( $playbook['title'] ?? '' ),
				'content_length' => strlen( $content ),
			];
		}
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $specializations
	 * @return array<string, bool|string>
	 */
	private static function task_profile( string $task, string $surface, string $intent, string $mode, array $specializations ): array {
		$surface = '' !== $surface ? $surface : 'unknown';
		$intent  = '' !== $intent ? $intent : 'unknown';
		$query   = self::normalise( $task . ' ' . $surface . ' ' . $intent );

		$is_write = in_array( $intent, [ 'write', 'create', 'update', 'delete' ], true )
			|| self::has_any_term( $query, [ 'add', 'apply', 'build', 'create', 'edit', 'import', 'publish', 'save', 'set', 'update', 'upload', 'write' ] );
		$is_destructive = 'delete' === $intent
			|| self::has_any_term( $query, [ 'delete', 'destroy', 'force delete', 'overwrite', 'permanent', 'remove', 'replace', 'reset', 'trash' ] );
		$is_surgical = self::has_any_term( $query, [ 'adjust', 'fix', 'insert', 'move', 'remove', 'surgical', 'tweak', 'update existing' ] );
		$needs_visual_check = ContextBuilder::is_visual_task( $task, $surface, $intent );
		$needs_wp_cli = [] !== $specializations || in_array(
			$surface,
			[ 'acf', 'acpt', 'ase', 'cpt-ui', 'fields', 'gutenberg', 'meta-box', 'metabox', 'pods', 'woocommerce', 'wordpress', 'wp-cli' ],
			true
		);

		return [
			'surface'                         => $surface,
			'intent'                          => $intent,
			'is_write'                        => $is_write,
			'is_destructive'                  => $is_destructive,
			'is_surgical_mutation'            => $is_surgical,
			'needs_visual_check'              => $needs_visual_check,
			'needs_wp_cli_discovery'          => $needs_wp_cli,
			'production_safe_token_required'  => 'production-safe' === $mode && $is_destructive,
			'context_token_required_for_write' => $is_write,
		];
	}

	/**
	 * @param array<string, bool|string> $profile
	 * @return list<string>
	 */
	private static function recommended_tools( array $profile ): array {
		$tools = [ 'stonewright/workflow-preflight' ];

		if ( 'elementor' === $profile['surface'] ) {
			$tools[] = 'stonewright/widget-intent-resolve';
			$tools[] = 'stonewright/elementor-widget-implementation-guide';
			$tools[] = 'stonewright/elementor-v3-capabilities-summary';
			$tools[] = 'stonewright/elementor-v3-get-widget-schema';
			if ( $profile['is_write'] ) {
				$tools[] = 'stonewright/media-upload-batch';
				$tools[] = 'stonewright/content-bulk-upsert-posts';
				$tools[] = 'stonewright/elementor-v3-build-page-from-spec';
				$tools[] = 'stonewright/elementor-v3-batch-mutate';
				$tools[] = 'stonewright/elementor-v3-apply-bundle';
			}
		}

		if ( 'gutenberg' === $profile['surface'] ) {
			$tools[] = 'stonewright/blocks-list-registered';
			$tools[] = 'stonewright/blocks-get-schema';
			if ( $profile['is_write'] ) {
				$tools[] = 'stonewright/design-validate-spec';
				$tools[] = 'stonewright/design-spec-to-gutenberg';
				$tools[] = 'stonewright/gutenberg-apply-to-post';
			}
		}

		if ( $profile['needs_wp_cli_discovery'] ) {
			$tools[] = 'stonewright/site-plugins-list';
			$tools[] = 'stonewright/wp-cli-status';
			$tools[] = 'stonewright/wp-cli-discover';
			if ( $profile['is_write'] ) {
				$tools[] = 'stonewright/content-bulk-upsert-posts';
				$tools[] = 'stonewright/wp-cli-run';
			}
		}

		if ( $profile['production_safe_token_required'] ) {
			$tools[] = 'stonewright/security-issue-confirmation-token';
		}

		return array_values( array_unique( $tools ) );
	}

	/**
	 * @param array<string, bool|string> $profile
	 * @return list<string>
	 */
	private static function visual_setup( array $profile ): array {
		if ( empty( $profile['needs_visual_check'] ) ) {
			return [];
		}

		return [
			'Install external Playwright MCP before visual work and restart the AI client so the browser tools appear.',
			'Verify the Playwright/browser tool can open the target URL before the first Stonewright write.',
		];
	}

	/**
	 * @param array<string, bool|string> $profile
	 * @return list<string>
	 */
	private static function batching_rules( array $profile ): array {
		$rules = [
			'Use stonewright/media-upload-batch for multiple assets instead of one upload call per image.',
			'Use stonewright/content-bulk-upsert-posts for repeated post/CPT/custom-field rows instead of many post/meta commands.',
			'Use stonewright/elementor-v3-batch-mutate for surgical Elementor add/update/move/remove edits instead of many single calls.',
			'Use individual add/update/move calls only for one-off debugging when batch diagnostics are not enough.',
		];

		if ( ! empty( $profile['needs_visual_check'] ) ) {
			array_unshift(
				$rules,
				'Implement visual pages in write-and-verify batches of one section, or two sections only when they are simple and tightly coupled.',
				'After each batch, verify desktop, tablet, and mobile screenshots plus overflow before starting the next batch.',
				'Auto-continue to the next section batch when screenshots, diagnostics, and overflow checks pass; do not wait for user approval between passing batches.'
			);
		} elseif ( ! empty( $profile['is_write'] ) ) {
			array_unshift(
				$rules,
				'Use stonewright/elementor-v3-build-page-from-spec dry_run first, then write with mode append, replace, or replace_section; use apply-bundle only when multiple posts must change together.'
			);
		}

		return $rules;
	}

	/**
	 * @param array<string, bool|string> $profile
	 * @return list<string>
	 */
	private static function quality_gates( array $profile ): array {
		$gates = [
			'Validate the design spec before render.',
			'Snapshot before each Elementor or global-style write.',
			'Inspect renderer diagnostics before browser iteration.',
		];

		if ( ! empty( $profile['needs_visual_check'] ) ) {
			array_unshift(
				$gates,
				'Stop before writing if a visual task has no connected external Playwright/browser MCP.',
				'Provide visual_build_gate evidence before signoff: Figma token table, media reuse audit, section plan, screenshot deltas, and logged-out viewport checks.',
				'Use design-tool structure for tokens and asset hints, but match implementation structure to the captured reference screenshots.',
				'For long visual designs, capture multiple section reference screenshots and compare each section before full-page signoff.',
				'Never write more than two visual page sections in a single implementation batch.',
				'Before uploading assets, audit existing media and reuse matching filenames, alt text, dimensions, and crops.'
			);
			$gates[] = 'Verify desktop, tablet, and mobile breakpoints with an external browser MCP.';
		}

		return $gates;
	}

	/**
	 * @param array<string, bool|string> $profile
	 * @return list<array<string, mixed>>
	 */
	private static function call_sequence( string $task, array $profile ): array {
		$task = self::compact_task( $task );
		$out  = [
			self::call_step(
				'stonewright/context-bootstrap',
				'Bootstrap Stonewright context before Figma, browser, or write tools; workflow-preflight may serve as the explicit fast-path bootstrap when already called.',
				[
					'task'    => $task,
					'surface' => $profile['surface'],
					'intent'  => $profile['intent'],
				]
			),
			self::call_step(
				'stonewright/workflow-preflight',
				'Issue context token and select the task-specific fast path.',
				[
					'task'    => $task,
					'surface' => $profile['surface'],
					'intent'  => $profile['intent'],
				]
			),
		];

		if ( 'elementor' === $profile['surface'] ) {
			$out[] = self::call_step(
				'stonewright/widget-intent-resolve',
				'Map design intent to native Elementor widgets before writing settings.',
				[
					'prompt'             => $task,
					'forbid_html_widget' => true,
				]
			);
			$out[] = self::call_step(
				'stonewright/elementor-widget-implementation-guide',
				'Get Content, Style, and Advanced control checklist for selected widgets.',
				[
					'task'              => $task,
					'candidate_widgets' => [ '<widgets from widget-intent-resolve>' ],
					'design_context'    => '<short design notes>',
				]
			);
			if ( $profile['is_write'] ) {
				$out[] = self::call_step(
					'stonewright/media-upload-batch',
					'Upload all known remote assets in one call before page render.',
					[ 'items' => [ '<remote assets>' ] ]
				);
				$out[] = self::call_step(
					'stonewright/content-bulk-upsert-posts',
					'Create or update repeated CPT/post rows and custom fields in one guarded call when the Elementor page uses dynamic content.',
					[
						'post_type'                 => '<post type>',
						'items'                     => [ '<rows with slug/title/meta>' ],
						'stonewright_context_token' => '<context_token>',
					]
				);
				$out[] = self::call_step(
					'stonewright/elementor-v3-build-page-from-spec',
					! empty( $profile['needs_visual_check'] )
						? 'Dry-run the current visual section batch, then write the same validated spec after checking diagnostics.'
						: 'Dry-run one validated first-pass page spec, then write it instead of many single-widget calls.',
					[
						'post_id'                  => '<target post id>',
						'spec'                     => ! empty( $profile['needs_visual_check'] ) ? '<validated Stonewright Design Spec for current section batch>' : '<validated Stonewright Design Spec>',
						'mode'                     => '<replace|append|replace_section>',
						'dry_run'                  => true,
						'stonewright_context_token' => '<context_token>',
					]
				);
				$out[] = self::call_step(
					'stonewright/elementor-v3-batch-mutate',
					'Apply surgical Elementor add/update/move/remove changes in one guarded request when editing an existing tree.',
					[
						'post_id'                  => '<target post id>',
						'operations'               => [ '<batched mutations>' ],
						'dry_run'                  => true,
						'stonewright_context_token' => '<context_token>',
					]
				);
			}
		}

		if ( $profile['needs_wp_cli_discovery'] ) {
			$out[] = self::call_step(
				'stonewright/site-plugins-list',
				'Confirm installed plugins before choosing plugin-specific surfaces.',
				[]
			);
			$out[] = self::call_step(
				'stonewright/wp-cli-status',
				'Check companion WP-CLI availability.',
				[]
			);
			$out[] = self::call_step(
				'stonewright/wp-cli-discover',
				'Discover installed WP-CLI command groups before running commands.',
				[]
			);
		}

		if ( $profile['production_safe_token_required'] ) {
			$out[] = self::call_step(
				'stonewright/security-issue-confirmation-token',
				'Issue token before destructive production-safe write.',
				[
					'ability' => '<destructive ability name>',
					'args'    => '<exact write args without confirmation_token>',
				]
			);
		}

		if ( $profile['needs_wp_cli_discovery'] && $profile['is_write'] ) {
			if ( 'elementor' !== $profile['surface'] ) {
				$out[] = self::call_step(
					'stonewright/content-bulk-upsert-posts',
					'Prefer this for repeated post/CPT rows and meta after the post type exists; reserve WP-CLI for plugin-specific commands and discovery.',
					[
						'post_type'                 => '<post type>',
						'items'                     => [ '<rows with slug/title/meta>' ],
						'stonewright_context_token' => '<context_token>',
					]
				);
			}
			$out[] = self::call_step(
				'stonewright/wp-cli-run',
				'Run only tokenized WP-CLI argv after discovery; no shell or eval entry points.',
				[
					'command'                  => [ '<command-group>', '<subcommand>', '--format=json' ],
					'parseJson'                => true,
					'stonewright_context_token' => '<context_token>',
				]
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function call_step( string $ability, string $why, array $args ): array {
		return [
			'ability' => $ability,
			'tool'    => self::mcp_tool_name( $ability ),
			'why'     => $why,
			'args'    => $args,
		];
	}

	private static function mcp_tool_name( string $ability ): string {
		return str_replace( '/', '-', $ability );
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

	private static function compact_task( string $task ): string {
		$task = trim( $task );
		if ( strlen( $task ) <= 160 ) {
			return $task;
		}
		return rtrim( substr( $task, 0, 157 ) ) . '...';
	}

	private static function normalise( string $text ): string {
		return trim( preg_replace( '/[^a-z0-9]+/i', ' ', strtolower( $text ) ) ?? '' );
	}
}
