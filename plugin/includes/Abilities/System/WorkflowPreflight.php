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
				'visual_setup'       => [
					'Install external Playwright MCP before visual work and restart the AI client so the browser tools appear.',
					'Verify the Playwright/browser tool can open the target URL before the first Stonewright write.',
				],
				'batching_rules'     => [
					'Build the first page pass with stonewright/elementor-v3-build-page-from-spec or stonewright/elementor-v3-apply-bundle; avoid dozens of single-widget calls for repeated cards.',
					'Use stonewright/media-upload-batch for multiple assets instead of one upload call per image.',
					'Use individual add/update/move calls only for surgical fixes after screenshot comparison.',
				],
				'quality_gates'      => [
					'Stop before writing if a visual task has no connected external Playwright/browser MCP.',
					'Validate the design spec before render.',
					'Snapshot before each Elementor or global-style write.',
					'Inspect renderer diagnostics before browser iteration.',
					'Verify desktop, tablet, and mobile breakpoints with an external browser MCP.',
				],
				'external_mcps'      => [
					'Use external Figma MCP for design extraction.',
					'Use external Playwright/browser MCP for screenshots and visual QA.',
				],
			],
			'elementor'     => $elementor,
			'site'          => [
				'ability_count' => count( AbilityRegistry::list() ),
				'mcp_server_id' => 'stonewright',
				'ability_prefix'=> 'stonewright/',
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
		$needs_visual_check = self::has_any_term( $query, [ 'browser', 'design', 'figma', 'front end', 'frontend', 'landing page', 'layout', 'mobile', 'page', 'pixel', 'responsive', 'screenshot', 'tablet', 'visual' ] );
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
				$tools[] = 'stonewright/elementor-v3-build-page-from-spec';
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
	 * @return list<array<string, mixed>>
	 */
	private static function call_sequence( string $task, array $profile ): array {
		$task = self::compact_task( $task );
		$out  = [
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
					'stonewright/elementor-v3-build-page-from-spec',
					'Write one validated first-pass page spec instead of many single-widget calls.',
					[
						'post_id'                  => '<target post id>',
						'spec'                     => '<validated Stonewright Design Spec>',
						'replace'                  => true,
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
