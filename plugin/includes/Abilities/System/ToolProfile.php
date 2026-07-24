<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact MCP tool profiles for low-token, tool-cap aware clients.
 *
 * @stonewright-status stable
 */
final class ToolProfile extends AbilityKernel {

	/**
	 * @return list<string>
	 */
	public static function profile_names(): array {
		return [ 'auto', 'bootstrap', 'low-tools', 'essential', 'elementor-design', 'content-model', 'gutenberg', 'wp-cli', 'site-admin', 'full' ];
	}

	public static function suggest_profile( string $task, string $surface = 'unknown', string $intent = 'unknown' ): string {
		$surface = strtolower( trim( $surface ) );
		$query   = self::normalise( $task . ' ' . $surface . ' ' . $intent );

		if ( in_array( $surface, [ 'elementor', 'theme-builder' ], true ) || self::has_any_term( $query, [ 'elementor', 'theme builder', 'theme-builder', 'figma', 'design', 'pixel', 'landing page', 'section' ] ) ) {
			return 'elementor-design';
		}

		if ( self::has_any_term( $query, [
			'acf',
			'acpt',
			'cpt ui',
			'custom field',
			'custom post type',
			'field group',
			'meta box',
			'metabox',
			'pods',
			'woocommerce',
			'sales report',
			'product catalog',
			'seo',
			'sitemap',
			'meta description',
			'focus keyword',
			'rank math',
			'yoast',
		] ) ) {
			return 'content-model';
		}

		if ( 'gutenberg' === $surface || self::has_any_term( $query, [ 'block', 'block theme', 'fse', 'gutenberg', 'theme json', 'template part' ] ) ) {
			return 'gutenberg';
		}

		if ( 'wp-cli' === $surface || self::has_any_term( $query, [ 'cache', 'cli', 'plugin', 'rewrite', 'wp cli' ] ) ) {
			return 'wp-cli';
		}

		if ( self::has_any_term( $query, [
			'admin',
			'app password',
			'application password',
			'comment',
			'comments',
			'custom css',
			'health',
			'menu',
			'moderate',
			'moderation',
			'plugin list',
			'revision',
			'revisions',
			'settings',
			'sidebar',
			'site info',
			'spam',
			'active theme',
			'switch theme',
			'user',
			'users',
			'user account',
			'user role',
			'widget',
			'widgets',
			'new user',
			'delete user',
			'site health',
		] ) ) {
			return 'site-admin';
		}

		return 'essential';
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function profile_hint( string $task, string $surface = 'unknown', string $intent = 'unknown', int $max_tools = 40 ): array {
		$profile = self::suggest_profile( $task, $surface, $intent );

		return [
			'profile'              => $profile,
			'tool'                 => 'stonewright-tool-profile',
			'call_after_bootstrap' => [ 'stonewright/tool-profile' ],
			'max_tools'            => $max_tools,
			'why'                  => 'Use a compact, task-aware MCP tool set before broad ability discovery.',
		];
	}

	public function name(): string {
		return 'stonewright/tool-profile';
	}

	public function label(): string {
		return __( 'Stonewright tool profile', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact task-aware Stonewright MCP tool profile for faster, lower-token client workflows.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action'    => [
					'type'        => 'string',
					'default'     => 'activate',
					'enum'        => [ 'activate', 'resolve' ],
					'description' => 'activate selects a live profile (may emit tools_changed). resolve returns the ordered tool list for a profile without changing session state.',
				],
				'profile'   => [
					'type'        => 'string',
					'default'     => 'auto',
					'enum'        => self::profile_names(),
					'description' => 'Tool profile to return. Use auto for task-aware routing.',
				],
				'task'      => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'Optional task summary used when profile is auto.',
				],
				'surface'   => [
					'type'        => 'string',
					'default'     => 'unknown',
					'description' => 'Primary surface such as elementor, gutenberg, wordpress, acf, cpt-ui, or wp-cli.',
				],
				'intent'    => [
					'type'        => 'string',
					'default'     => 'unknown',
					'description' => 'Task intent such as read, write, delete, or debug.',
				],
				'max_tools' => [
					'type'        => 'integer',
					'default'     => 50,
					'minimum'     => 5,
					'maximum'     => 200,
					'description' => 'Maximum tools the current MCP client should receive in this profile.',
				],
				'extras'    => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'default'     => [],
					'description' => 'Extra stonewright/* ability names to pin into the essential surface for this site.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                    => [ 'type' => 'boolean' ],
				'profile'               => [ 'type' => 'string' ],
				'requested_profile'     => [ 'type' => 'string' ],
				'max_tools'             => [ 'type' => 'integer' ],
				'tool_count'            => [ 'type' => 'integer' ],
				'profile_tool_count'    => [ 'type' => 'integer' ],
				'under_limit'           => [ 'type' => 'boolean' ],
				'degraded'              => [ 'type' => 'boolean' ],
				'truncated_tools'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'truncated_mcp_tools'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'truncation_hint'       => [ 'type' => 'string' ],
				'essential_tools_mode'  => [ 'type' => 'boolean' ],
				'mcp_surface'            => [ 'type' => 'string' ],
				'surface_revision'       => [ 'type' => 'integer' ],
				'profiles_available'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'recommended_tools'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'recommended_mcp_tools' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'missing_profile_tools' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'missing_mcp_tools'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'tool_groups'           => [ 'type' => 'object' ],
				'next_best_tools'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'ability'  => [ 'type' => 'string' ],
							'mcp_tool' => [ 'type' => 'string' ],
							'group'    => [ 'type' => 'string' ],
						],
						'required'   => [ 'ability', 'mcp_tool', 'group' ],
					],
				],
				'tools'                 => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'ability'  => [ 'type' => 'string' ],
							'mcp_tool' => [ 'type' => 'string' ],
							'priority' => [ 'type' => 'integer' ],
							'why'      => [ 'type' => 'string' ],
						],
						'required'   => [ 'ability', 'mcp_tool', 'priority', 'why' ],
					],
				],
				'recovery_hints'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'discovery_policy'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'workflow_rules'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'token_rules'           => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'counts'                => [ 'type' => 'object' ],
				'tools_changed'         => [ 'type' => 'boolean' ],
				'session_profile_applied' => [ 'type' => 'boolean' ],
				'session_profile_reason'  => [ 'type' => 'string' ],
				'tools_changed_at'      => [ 'type' => 'string' ],
				'extras_applied'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				're_list_instruction'   => [ 'type' => 'string' ],
				'source'                => [ 'type' => 'string' ],
				'ordered'               => [ 'type' => 'boolean' ],
				'action'                => [ 'type' => 'string' ],
			],
			'required'   => [
				'ok',
				'profile',
				'requested_profile',
				'max_tools',
				'tool_count',
				'profile_tool_count',
				'under_limit',
				'profiles_available',
				'recommended_tools',
				'recommended_mcp_tools',
				'missing_profile_tools',
				'missing_mcp_tools',
				'tool_groups',
				'next_best_tools',
				'tools',
				'recovery_hints',
				'discovery_policy',
				'workflow_rules',
				'token_rules',
				'tools_changed',
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$action = isset( $args['action'] ) && is_string( $args['action'] )
			? strtolower( trim( $args['action'] ) )
			: 'activate';
		$extras = $args['extras'] ?? [];
		if ( 'activate' === $action || ( is_array( $extras ) && [] !== $extras ) ) {
			return Permissions::manage_options();
		}
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$action = isset( $args['action'] ) && is_string( $args['action'] )
			? strtolower( trim( $args['action'] ) )
			: 'activate';
		if ( ! in_array( $action, [ 'activate', 'resolve' ], true ) ) {
			$action = 'activate';
		}

		$requested = isset( $args['profile'] ) && is_string( $args['profile'] )
			? strtolower( trim( $args['profile'] ) )
			: 'auto';

		if ( '' === $requested ) {
			$requested = 'auto';
		}

		if ( ! in_array( $requested, self::profile_names(), true ) ) {
			return $this->error(
				'invalid_tool_profile',
				__( 'Unknown Stonewright tool profile.', 'stonewright' ),
				[
					'status'             => 400,
					'profiles_available' => self::profile_names(),
				]
			);
		}

		$task      = isset( $args['task'] ) && is_string( $args['task'] ) ? $args['task'] : '';
		$surface   = isset( $args['surface'] ) && is_string( $args['surface'] ) ? $args['surface'] : 'unknown';
		$intent    = isset( $args['intent'] ) && is_string( $args['intent'] ) ? $args['intent'] : 'unknown';
		$max_tools = isset( $args['max_tools'] ) && is_int( $args['max_tools'] ) ? $args['max_tools'] : 50;
		$max_tools = max( 5, min( 200, $max_tools ) );
		$profile   = 'auto' === $requested ? self::suggest_profile( $task, $surface, $intent ) : $requested;

		// Read-only resolve: ordered MCP tool names for companion / clients. No option writes.
		if ( 'resolve' === $action ) {
			$ordered_abilities = self::profile_tools( $profile );
			$visible_rows      = array_values(
				array_filter(
					AbilityRegistry::all_abilities(),
					static fn( array $ability ): bool => (bool) $ability['enabled']
				)
			);
			$all_visible       = array_fill_keys( array_column( $visible_rows, 'name' ), true );
			$missing_names     = 'full' === $profile
				? []
				: array_values(
					array_filter(
						$ordered_abilities,
						static fn( string $name ): bool => ! isset( $all_visible[ $name ] )
					)
				);
			$ordered_abilities = array_values(
				array_filter(
					$ordered_abilities,
					static fn( string $name ): bool => isset( $all_visible[ $name ] )
				)
			);
			$profile_count     = count( $ordered_abilities );
			$dropped_names     = array_slice( $ordered_abilities, $max_tools );
			$ordered_abilities = array_slice( $ordered_abilities, 0, $max_tools );
			$mcp_tools         = array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $ordered_abilities );
			$tool_groups       = self::tool_groups( $ordered_abilities );

			return [
				'ok'                   => true,
				'action'               => 'resolve',
				'profile'              => $profile,
				'requested_profile'    => $requested,
				'mcp_surface'           => AbilityRegistry::mcp_surface(),
				'surface_revision'      => AbilityRegistry::surface_revision(),
				'tools'                => array_values( $mcp_tools ),
				'recommended_tools'    => $ordered_abilities,
				'recommended_mcp_tools' => array_values( $mcp_tools ),
				'missing_profile_tools' => $missing_names,
				'missing_mcp_tools'     => array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $missing_names ),
				'tool_groups'           => $tool_groups,
				'next_best_tools'       => self::next_best_tools( $profile, $tool_groups, $task, $surface, $intent ),
				'recovery_hints'        => self::recovery_hints( $missing_names ),
				'discovery_policy'      => self::discovery_policy(),
				'ordered'              => true,
				'source'               => 'plugin',
				'essential_tools_mode' => (bool) get_option( 'stonewright_essential_tools_mode', true ),
				'max_tools'            => $max_tools,
				'tool_count'           => count( $mcp_tools ),
				'profile_tool_count'   => $profile_count,
				'under_limit'          => $profile_count <= $max_tools,
				'degraded'             => [] !== $dropped_names,
				'truncated_tools'      => $dropped_names,
				'truncated_mcp_tools'  => array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $dropped_names ),
				'truncation_hint'      => self::truncation_hint( $dropped_names, $max_tools ),
				'tools_changed'        => false,
			];
		}

		$extras_result = self::apply_extras( is_array( $args['extras'] ?? null ) ? $args['extras'] : [] );
		if ( $extras_result instanceof \WP_Error ) {
			return $extras_result;
		}

		$tools_changed = (bool) ( $extras_result['changed'] ?? false )
			|| $profile !== get_option( 'stonewright_last_tool_profile', '' );
		if ( $tools_changed ) {
			update_option( 'stonewright_tools_changed_at', gmdate( 'c' ), false );
			update_option( 'stonewright_last_tool_profile', $profile, false );
			// Progressive discovery: activating a non-bootstrap profile must expand
			// the public tools/list surface so recommended tools become visible.
			self::expand_mcp_surface_for_profile( $profile );
		}

		// Activation must change what this session can call. The option surface
		// stays operator-controlled; session expansion uses Mcp-Session-Id.
		$session_applied = false;
		$session_reason  = 'surface_full_already_exposes_all_tools';
		if ( 'full' !== AbilityRegistry::mcp_surface() && 'bootstrap' !== $profile ) {
			$session_applied = AbilityRegistry::set_session_tool_profile(
				$profile,
				'full' === $profile ? [] : self::profile_tools( $profile )
			);
			$session_reason  = $session_applied
				? 'session_transient_written'
				: 'missing_or_invalid_mcp_session_id_header';
		} elseif ( 'bootstrap' === $profile ) {
			$session_reason = 'bootstrap_profile_needs_no_expansion';
		}
		$tools_changed = $tools_changed || $session_applied;

		$visible_rows = array_values(
			array_filter(
				AbilityRegistry::all_abilities(),
				static fn( array $ability ): bool => (bool) $ability['enabled']
			)
		);
		$all_visible   = array_fill_keys( array_column( $visible_rows, 'name' ), true );
		$profile_names = 'full' === $profile ? array_keys( $all_visible ) : self::profile_tools( $profile );
		$profile_names = array_values( array_unique( $profile_names ) );
		$missing_names = 'full' === $profile
			? []
			: array_values(
				array_filter(
					$profile_names,
					static fn( string $name ): bool => ! isset( $all_visible[ $name ] )
				)
			);
		$names         = array_values(
			array_filter(
				$profile_names,
				static fn( string $name ): bool => isset( $all_visible[ $name ] )
			)
		);

		$profile_tool_count = count( $names );
		$dropped_names      = array_slice( $names, $max_tools );
		$limited_names      = array_slice( $names, 0, $max_tools );
		$tools              = [];
		$tool_groups        = self::tool_groups( $limited_names );

		foreach ( $limited_names as $index => $name ) {
			$tools[] = [
				'ability'  => $name,
				'mcp_tool' => AbilityRegistry::mcp_tool_name( $name ),
				'priority' => $index + 1,
				'why'      => self::why( $name ),
			];
		}

		$changed_at = (string) get_option( 'stonewright_tools_changed_at', '' );

		return [
			'ok'                    => true,
			'profile'               => $profile,
			'requested_profile'     => $requested,
			'max_tools'             => $max_tools,
			'tool_count'            => count( $limited_names ),
			'profile_tool_count'    => $profile_tool_count,
			'under_limit'           => $profile_tool_count <= $max_tools,
			'degraded'              => [] !== $dropped_names,
			'truncated_tools'       => $dropped_names,
			'truncated_mcp_tools'   => array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $dropped_names ),
			'truncation_hint'       => self::truncation_hint( $dropped_names, $max_tools ),
			'essential_tools_mode'  => (bool) get_option( 'stonewright_essential_tools_mode', true ),
			'mcp_surface'            => AbilityRegistry::mcp_surface(),
			'surface_revision'       => AbilityRegistry::surface_revision(),
			'profiles_available'    => self::profile_names(),
			'recommended_tools'     => $limited_names,
			'recommended_mcp_tools' => array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $limited_names ),
			'missing_profile_tools' => $missing_names,
			'missing_mcp_tools'     => array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $missing_names ),
			'tool_groups'           => $tool_groups,
			'next_best_tools'       => self::next_best_tools( $profile, $tool_groups, $task, $surface, $intent ),
			'tools'                 => $tools,
			'recovery_hints'        => self::recovery_hints( $missing_names ),
			'discovery_policy'      => self::discovery_policy(),
			'workflow_rules'        => self::workflow_rules( $profile ),
			'token_rules'           => self::token_rules(),
			'counts'                => [
				'all_registered' => count( AbilityRegistry::list() ),
				'visible'        => count( $all_visible ),
				'profile'        => $profile_tool_count,
				'returned'       => count( $limited_names ),
				'missing'        => count( $missing_names ),
			],
			'tools_changed'         => $tools_changed,
			'session_profile_applied' => $session_applied,
			'session_profile_reason'  => $session_reason,
			'tools_changed_at'      => $changed_at,
			'extras_applied'        => array_values( (array) ( $extras_result['extras'] ?? [] ) ),
			// Always emit a re-list instruction on activate so stdio companions that
			// missed tools_changed still expand (Grok / Cursor cache quirks).
			're_list_instruction'   => $tools_changed
				? 'Re-list tools now (tools/list). New tools are available. If your client ignores tools/list_changed, call tools/list again before continuing. If php-execute is still missing after re-list, restart the MCP client — do not call /abilities/run.'
				: ( 'activate' === (string) ( $args['action'] ?? 'activate' )
					? 'Re-list tools now (tools/list) to confirm the active profile surface matches the server.'
					: '' ),
			'source'                => 'plugin',
			'ordered'               => true,
		];
	}

	/**
	 * Expand stonewright_mcp_surface when leaving the bootstrap cold-start set.
	 *
	 * This only widens the persistent option surface from bootstrap. Essential
	 * surfaces are never silently promoted to full here; per-session expansion
	 * beyond the option surface uses the Mcp-Session-Id transient from execute().
	 */
	public static function expand_mcp_surface_for_profile( string $profile ): void {
		$current = AbilityRegistry::mcp_surface();
		if ( 'bootstrap' !== $current ) {
			return;
		}

		$profile = strtolower( trim( $profile ) );
		if ( 'bootstrap' === $profile || '' === $profile ) {
			return;
		}

		$target = 'full' === $profile ? 'full' : 'essential';
		AbilityRegistry::set_mcp_surface( $target );
	}

	/**
	 * Merge validated extras into the essential extra abilities option.
	 *
	 * @param list<mixed> $raw_extras
	 * @return array{changed: bool, extras: list<string>}|\WP_Error
	 */
	public static function apply_extras( array $raw_extras ): array|\WP_Error {
		if ( [] === $raw_extras ) {
			return [
				'changed' => false,
				'extras'  => array_values( array_map( 'strval', (array) get_option( 'stonewright_essential_extra_abilities', [] ) ) ),
			];
		}

		$registered = [];
		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			/** @var \Stonewright\WpMcp\Abilities\Ability $ability */
			$ability = new $class();
			$registered[ $ability->name() ] = true;
		}

		$normalized = [];
		foreach ( $raw_extras as $raw ) {
			if ( ! is_string( $raw ) ) {
				return new \WP_Error(
					'stonewright_tool_profile_extras_invalid',
					__( 'Each extras entry must be a string ability name.', 'stonewright' ),
					[ 'status' => 400 ]
				);
			}
			$name = trim( $raw );
			if ( ! str_starts_with( $name, 'stonewright/' ) ) {
				return new \WP_Error(
					'stonewright_tool_profile_extras_invalid',
					sprintf(
						/* translators: %s: ability name */
						__( 'Extra ability "%s" must use the stonewright/ prefix.', 'stonewright' ),
						$name
					),
					[ 'status' => 400, 'ability' => $name ]
				);
			}
			if ( ! isset( $registered[ $name ] ) ) {
				return new \WP_Error(
					'stonewright_tool_profile_extras_unknown',
					sprintf(
						/* translators: %s: ability name */
						__( 'Extra ability "%s" is not registered.', 'stonewright' ),
						$name
					),
					[ 'status' => 400, 'ability' => $name ]
				);
			}
			$normalized[] = $name;
		}

		$existing = array_values( array_map( 'strval', (array) get_option( 'stonewright_essential_extra_abilities', [] ) ) );
		$merged   = array_values( array_unique( array_merge( $existing, $normalized ) ) );
		$changed  = $merged !== $existing;
		if ( $changed ) {
			update_option( 'stonewright_essential_extra_abilities', $merged, false );
			AuditLog::record(
				'stonewright/tool-profile',
				[
					'action' => 'extras_update',
					'extras' => $merged,
					'added'  => $normalized,
				],
				'ok'
			);
		}

		return [
			'changed' => $changed,
			'extras'  => $merged,
		];
	}

	/**
	 * Ordered ability names for a named profile (single source of truth for companion + MCP).
	 *
	 * Priority bands (front of list survives client tool caps):
	 * 1) startup (task-start / bootstrap / tool-profile)
	 * 2) blueprints + brand kits
	 * 3) engine write paths
	 * 4) media / content batch
	 * 5) remainder
	 *
	 * @return list<string>
	 */
	public static function profile_tools( string $profile ): array {
		if ( 'bootstrap' === $profile ) {
			return AbilityRegistry::bootstrap_ability_names();
		}

		$startup = [
			'stonewright/context-bootstrap',
			'stonewright/task-start',
			'stonewright/tool-profile',
			'stonewright/skills-get',
			'stonewright/expertise-get',
			'stonewright/php-execute',
		];
		$blueprints = [
			'stonewright/blueprint-list',
			'stonewright/blueprint-get',
			'stonewright/blueprint-apply',
			'stonewright/brand-kit-list',
			'stonewright/brand-kit-apply',
		];

		$rest = match ( $profile ) {
			'low-tools' => [
				'stonewright/security-create-one-time-link',
				'stonewright/site-info',
				'stonewright/elementor-v3-build-page-from-spec',
				'stonewright/theme-builder-apply-template',
				'stonewright/elementor-v3-batch-mutate',
				'stonewright/elementor-wire-loop',
				'stonewright/gutenberg-apply-to-post',
				'stonewright/elementor-page-digest',
				'stonewright/content-bulk-upsert-posts',
				'stonewright/content-model-loop-grid-flow',
				'stonewright/media-upload-batch',
				'stonewright/design-native-plan',
				'stonewright/knowledge-candidate-record',
				'stonewright/elementor-v3-get-kit-globals',
				'stonewright/elementor-schema',
				'stonewright/elementor-v3-get-page-structure',
				'stonewright/wp-cli-batch-run',
				'stonewright/wp-cli-job-start',
				'stonewright/wp-cli-job-status',
			],
			'elementor-design' => [
				// Write-critical path first so capped clients keep every write gate.
				'stonewright/security-issue-confirmation-token',
				'stonewright/design-validate-spec',
				'stonewright/design-native-plan',
				'stonewright/elementor-v3-build-page-from-spec',
				'stonewright/theme-builder-apply-template',
				'stonewright/elementor-v3-batch-mutate',
				'stonewright/elementor-wire-loop',
				'stonewright/elementor-v3-apply-bundle',
				'stonewright/elementor-page-digest',
				'stonewright/elementor-document-health',
				'stonewright/elementor-build-tree',
				'stonewright/elementor-v4-read-atomic-tree',
				'stonewright/elementor-v4-update-node',
				'stonewright/theme-file-read',
				'stonewright/theme-file-patch',
				'stonewright/theme-custom-css',
				'stonewright/elementor-v3-update-page-settings',
				'stonewright/elementor-v3-update-kit-colors',
				'stonewright/elementor-v3-update-kit-typography',
				'stonewright/elementor-v3-get-kit-globals',
				'stonewright/gutenberg-apply-to-post',
				'stonewright/content-create-page',
				'stonewright/content-update-page',
				'stonewright/content-get-page',
				'stonewright/media-list',
				'stonewright/media-upload-batch',
				'stonewright/stock-image-search',
				'stonewright/stock-image-import',
				'stonewright/content-bulk-upsert-posts',
				'stonewright/content-model-loop-grid-flow',
				'stonewright/design-implementation-contract',
				'stonewright/widget-intent-resolve',
				'stonewright/elementor-widget-implementation-guide',
				'stonewright/site-info',
				'stonewright/security-create-one-time-link',
				'stonewright/knowledge-candidate-record',
				'stonewright/elementor-v3-repair-document',
				// Discovery and diagnostics are the first candidates dropped by low caps.
				'stonewright/elementor-schema',
				'stonewright/elementor-describe-widget',
				'stonewright/elementor-v3-list-widgets',
				'stonewright/elementor-v3-container-schema',
				'stonewright/elementor-v3-status',
				'stonewright/elementor-v3-capabilities-summary',
				'stonewright/elementor-v4-status',
				'stonewright/elementor-v4-list-variables',
				'stonewright/elementor-v4-list-classes',
				'stonewright/elementor-v4-list-atomic-node-types',
				'stonewright/site-plugins-list',
				'stonewright/wp-cli-status',
				'stonewright/wp-cli-discover',
				'stonewright/wp-cli-batch-run',
			],
			'content-model' => [
				'stonewright/content-bulk-upsert-posts',
				'stonewright/content-model-loop-grid-flow',
				'stonewright/media-list',
				'stonewright/media-upload-batch',
				'stonewright/site-capabilities',
				'stonewright/site-plugins-list',
				'stonewright/system-abilities-list',
				// Keep WP-CLI near the front so compact max_tools caps still retain it.
				'stonewright/wp-cli-status',
				'stonewright/wp-cli-discover',
				'stonewright/wp-cli-batch-run',
				'stonewright/wp-cli-run',
				'stonewright/wp-cli-job-start',
				'stonewright/wp-cli-job-status',
				'stonewright/wc-product-list',
				'stonewright/wc-order-list',
				'stonewright/wc-sales-report',
				'stonewright/acf-field-group-list',
				'stonewright/acf-field-group-get',
				'stonewright/acf-field-group-save',
				'stonewright/acf-values-get',
				'stonewright/acf-value-update',
				'stonewright/cpt-register',
				'stonewright/cpt-list',
				'stonewright/taxonomy-register',
			],
			'gutenberg' => [
				'stonewright/gutenberg-apply-to-post',
				'stonewright/design-spec-to-gutenberg',
				'stonewright/design-validate-spec',
				'stonewright/media-list',
				'stonewright/media-upload-batch',
				'stonewright/site-theme',
				'stonewright/fse-get-theme-json',
				'stonewright/fse-read-template',
				'stonewright/fse-write-template',
				'stonewright/fse-write-global-styles',
				'stonewright/blocks-list-registered',
				'stonewright/blocks-get-schema',
				'stonewright/blocks-parse',
				'stonewright/blocks-serialize',
				'stonewright/gutenberg-render-blocks',
			],
			'wp-cli' => [
				'stonewright/site-info',
				'stonewright/site-plugins-list',
				'stonewright/wp-cli-status',
				'stonewright/wp-cli-discover',
				'stonewright/wp-cli-batch-run',
				'stonewright/wp-cli-run',
				'stonewright/wp-cli-job-start',
				'stonewright/wp-cli-job-status',
			],
			'site-admin' => [
				'stonewright/site-info',
				'stonewright/site-environment',
				'stonewright/site-health',
				'stonewright/site-pulse',
				'stonewright/site-plugins-list',
				'stonewright/site-theme',
				'stonewright/change-log',
				'stonewright/change-restore',
				'stonewright/security-create-one-time-link',
				'stonewright/system-abilities-list',
				'stonewright/menu-list',
				'stonewright/wp-cli-status',
				// Wave-3 admin ops (REST parity).
				'stonewright/comment-list',
				'stonewright/comment-get',
				'stonewright/comment-create',
				'stonewright/comment-update',
				'stonewright/comment-delete',
				'stonewright/user-list',
				'stonewright/user-get',
				'stonewright/user-create',
				'stonewright/user-update',
				'stonewright/user-delete',
				'stonewright/user-app-passwords',
				'stonewright/widget-list',
				'stonewright/widget-get',
				'stonewright/widget-save',
				'stonewright/widget-delete',
				'stonewright/settings-get',
				'stonewright/settings-update',
				'stonewright/theme-list',
				'stonewright/theme-activate',
				'stonewright/theme-custom-css',
				'stonewright/plugin-activate',
				'stonewright/plugin-deactivate',
				'stonewright/plugin-delete',
				'stonewright/post-revision-list',
				'stonewright/post-revision-get',
				'stonewright/post-revision-restore',
				'stonewright/site-health-test',
				'stonewright/search-query',
				'stonewright/oembed-resolve',
				'stonewright/seo-status',
			],
			// essential and unknown aliases: compact public surface from AbilityRegistry.
			default => ( static function () use ( $startup, $blueprints ): array {
				$skip = array_fill_keys( array_merge( $startup, $blueprints ), true );
				$out  = [];
				foreach ( AbilityRegistry::essential_ability_names_for_test() as $name ) {
					if ( ! isset( $skip[ $name ] ) ) {
						$out[] = $name;
					}
				}
				return $out;
			} )(),
		};

		// low-tools stays tiny (strict client caps). wp-cli skips blueprints.
		// All other profiles put blueprints right after startup so client caps keep them.
		$with_blueprints = match ( $profile ) {
			'low-tools', 'wp-cli' => array_merge( $startup, $rest ),
			default               => array_merge( $startup, $blueprints, $rest ),
		};

		return array_values( array_unique( $with_blueprints ) );
	}

	private static function why( string $name ): string {
		return match ( $name ) {
			'stonewright/context-bootstrap' => 'Issue the task token and load live site instructions, memory, skills, and visual gates.',
			'stonewright/task-start' => 'Issue the task token and choose the compact task-aware fast path in one call.',
			'stonewright/workflow-preflight' => 'Choose the task-aware fast path and first call sequence.',
			'stonewright/tool-profile' => 'Keep the MCP tool surface compact for the current model, client, and task.',
			'stonewright/skills-get' => 'Load one matched site playbook on demand instead of injecting every skill into startup context.',
			'stonewright/php-execute' => 'Execute short PHP snippets inside the loaded WordPress runtime when direct plugin API or database inspection is faster than many typed calls.',
			'stonewright/security-create-one-time-link' => 'Create a short-lived wp-admin login URL for external browser MCP verification when needed.',
			'stonewright/design-implementation-contract' => 'Load global-style, native-widget, section-batch, and verification rules.',
			'stonewright/design-native-plan' => 'Validate compact DesignEvidence and map semantic nodes to live native schemas without writing.',
			'stonewright/widget-intent-resolve' => 'Map visual intent to native Elementor widgets before writing controls.',
			'stonewright/elementor-widget-implementation-guide' => 'Get Content, Style, and Advanced controls before Elementor writes.',
			'stonewright/elementor-v3-get-kit-globals' => 'Read active Elementor kit colors and typography before global-style writes.',
			'stonewright/elementor-schema' => 'List/search live widgets, read compact controls, or request one complete control without guessing settings.',
			'stonewright/elementor-v3-get-page-structure' => 'Read a compact Elementor outline first; request full tree only for raw setting drift or difficult edits.',
			'stonewright/elementor-document-health' => 'Measure document size, V3/V4 composition, atomic paragraph count, and bounded schema issues before repair.',
			'stonewright/elementor-v3-build-page-from-spec' => 'Render a validated Elementor section or page spec in one request.',
			'stonewright/theme-builder-apply-template' => 'Create or update a real Elementor Theme Builder template, render the spec, apply conditions, and return verification hints in one request.',
			'stonewright/elementor-v3-container-schema' => 'Get container layout, style, Advanced, alias, and blocked-key guidance before section writes.',
			'stonewright/elementor-v3-batch-mutate' => 'Apply grouped surgical Elementor mutations after screenshot review.',
			'stonewright/elementor-wire-loop' => 'Plan or transactionally add a native Elementor Pro Loop Carousel or Loop Grid using an existing loop-item template or a validated template spec.',
			'stonewright/content-bulk-upsert-posts' => 'Create or update repeated posts, CPT rows, and meta values in one call.',
			'stonewright/content-model-loop-grid-flow' => 'Create CPT UI-style config, ACF field contract, repeated CPT rows, optional loop item, and Loop Grid settings in one call.',
			'stonewright/wp-cli-batch-run' => 'Run repeated tokenized WP-CLI argv commands with compact output.',
			'stonewright/wp-cli-job-start' => 'Start long WP-CLI command or batch work without blocking the MCP request.',
			'stonewright/wp-cli-job-status' => 'Poll a WP-CLI background job until the compact result is ready.',
			'stonewright/comment-update' => 'Moderate comments via status enum (approve/hold/spam/trash) without separate tools.',
			'stonewright/settings-update' => 'Update allowlisted settings only; siteurl/home are blocked.',
			'stonewright/post-revision-restore' => 'Snapshot the post, then restore a revision.',
			'stonewright/user-delete' => 'Delete a user with reassign; confirmation token required in production-safe.',
			'stonewright/site-health-test' => 'Run one named Site Health check.',
			'stonewright/wc-order-list' => 'List WooCommerce orders when WooCommerce is active.',
			default => 'Use this tool only when it is needed by the selected profile step.',
		};
	}

	/**
	 * @param list<string> $names
	 * @return array<string,array{abilities:list<string>,mcp_tools:list<string>,count:int}>
	 */
	private static function tool_groups( array $names ): array {
		$groups = [
			'startup'          => [],
			'runtime'          => [],
			'elementor_design' => [],
			'content_media'    => [],
			'gutenberg_fse'    => [],
			'wp_cli'           => [],
			'site_admin'       => [],
			'other'            => [],
		];

		foreach ( $names as $name ) {
			$groups[ self::tool_group_name( $name ) ][] = $name;
		}

		$out = [];
		foreach ( $groups as $group => $abilities ) {
			if ( [] === $abilities ) {
				continue;
			}

			$out[ $group ] = [
				'abilities' => array_values( $abilities ),
				'mcp_tools' => array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $abilities ),
				'count'     => count( $abilities ),
			];
		}

		return $out;
	}

	private static function tool_group_name( string $name ): string {
		if ( in_array( $name, [ 'stonewright/context-bootstrap', 'stonewright/task-start', 'stonewright/workflow-preflight', 'stonewright/tool-profile', 'stonewright/skills-get' ], true ) ) {
			return 'startup';
		}

		if ( 'stonewright/php-execute' === $name ) {
			return 'runtime';
		}

		if ( str_contains( $name, '/wp-cli-' ) ) {
			return 'wp_cli';
		}

		// Core sidebar widgets / users / comments — not Elementor widgets.
		if (
			str_starts_with( $name, 'stonewright/comment-' )
			|| str_starts_with( $name, 'stonewright/user-' )
			|| str_starts_with( $name, 'stonewright/widget-' )
			|| str_starts_with( $name, 'stonewright/settings-' )
			|| str_starts_with( $name, 'stonewright/theme-' )
			|| str_starts_with( $name, 'stonewright/plugin-' )
			|| str_starts_with( $name, 'stonewright/post-revision-' )
			|| str_starts_with( $name, 'stonewright/search-' )
			|| str_starts_with( $name, 'stonewright/oembed-' )
			|| 'stonewright/site-health-test' === $name
		) {
			return 'site_admin';
		}

		if ( str_starts_with( $name, 'stonewright/wc-' ) ) {
			return 'content_media';
		}

		if ( str_contains( $name, 'elementor' ) || str_contains( $name, 'design' ) || str_contains( $name, 'widget' ) || str_contains( $name, 'theme-builder' ) ) {
			return 'elementor_design';
		}

		if ( str_contains( $name, 'content' ) || str_contains( $name, 'media' ) ) {
			return 'content_media';
		}

		if ( str_contains( $name, 'gutenberg' ) || str_contains( $name, 'blocks' ) || str_contains( $name, 'fse' ) ) {
			return 'gutenberg_fse';
		}

		if ( str_contains( $name, 'site' ) || str_contains( $name, 'system' ) || str_contains( $name, 'security' ) || str_contains( $name, 'menu' ) || 'stonewright/ping' === $name ) {
			return 'site_admin';
		}

		return 'other';
	}

	/**
	 * @param array<string,array{abilities:list<string>,mcp_tools:list<string>,count:int}> $tool_groups
	 * @return list<array{ability:string,mcp_tool:string,group:string}>
	 */
	private static function next_best_tools( string $profile, array $tool_groups, string $task = '', string $surface = 'unknown', string $intent = 'unknown' ): array {
		$elementor_preferences = self::is_theme_builder_task( $task, $surface, $intent )
			? [
				'stonewright/theme-builder-apply-template',
				'stonewright/elementor-v3-build-page-from-spec',
			]
			: [
				'stonewright/elementor-v3-build-page-from-spec',
				'stonewright/theme-builder-apply-template',
			];

		$preferred_abilities = match ( $profile ) {
			'elementor-design', 'low-tools' => array_merge( $elementor_preferences, [
				'stonewright/elementor-v3-batch-mutate',
				'stonewright/elementor-wire-loop',
				'stonewright/elementor-v3-get-kit-globals',
				'stonewright/content-model-loop-grid-flow',
				'stonewright/content-bulk-upsert-posts',
				'stonewright/media-upload-batch',
				'stonewright/wp-cli-batch-run',
				'stonewright/wp-cli-job-start',
			] ),
			'content-model' => [
				'stonewright/php-execute',
				'stonewright/content-model-loop-grid-flow',
				'stonewright/content-bulk-upsert-posts',
				'stonewright/wp-cli-discover',
				'stonewright/wp-cli-batch-run',
				'stonewright/wp-cli-job-start',
			],
			'gutenberg' => [
				'stonewright/gutenberg-apply-to-post',
				'stonewright/design-spec-to-gutenberg',
				'stonewright/blocks-get-schema',
			],
			'wp-cli' => [
				'stonewright/wp-cli-status',
				'stonewright/wp-cli-discover',
				'stonewright/wp-cli-batch-run',
				'stonewright/wp-cli-job-start',
			],
			default => [],
		};
		$preferred_groups = match ( $profile ) {
			'elementor-design', 'low-tools' => [ 'elementor_design', 'content_media', 'runtime', 'wp_cli', 'gutenberg_fse', 'startup' ],
			'content-model' => [ 'runtime', 'content_media', 'wp_cli', 'site_admin', 'startup' ],
			'gutenberg' => [ 'gutenberg_fse', 'content_media', 'runtime', 'startup' ],
			'wp-cli' => [ 'wp_cli', 'runtime', 'site_admin', 'startup' ],
			'site-admin' => [ 'site_admin', 'runtime', 'wp_cli', 'startup' ],
			default => [ 'startup', 'runtime', 'site_admin', 'elementor_design', 'content_media', 'wp_cli' ],
		};

		$out = [];
		$ability_to_group = [];
		foreach ( $tool_groups as $group => $data ) {
			foreach ( $data['abilities'] as $ability ) {
				$ability_to_group[ $ability ] = $group;
			}
		}

		foreach ( $preferred_abilities as $ability ) {
			if ( ! isset( $ability_to_group[ $ability ] ) ) {
				continue;
			}

			$out[] = [
				'ability'  => $ability,
				'mcp_tool' => AbilityRegistry::mcp_tool_name( $ability ),
				'group'    => $ability_to_group[ $ability ],
			];
		}

		foreach ( $preferred_groups as $group ) {
			foreach ( $tool_groups[ $group ]['abilities'] ?? [] as $ability ) {
				if ( in_array( $ability, [ 'stonewright/context-bootstrap', 'stonewright/task-start', 'stonewright/workflow-preflight', 'stonewright/tool-profile', 'stonewright/skills-get' ], true ) ) {
					continue;
				}
				if ( in_array( $ability, array_column( $out, 'ability' ), true ) ) {
					continue;
				}

				$out[] = [
					'ability'  => $ability,
					'mcp_tool' => AbilityRegistry::mcp_tool_name( $ability ),
					'group'    => $group,
				];

				if ( count( $out ) >= 8 ) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	private static function discovery_policy(): array {
		return [
			'Use tool_groups before system-abilities-list or full tools/list discovery.',
			'Use next_best_tools for the next write/read step when the selected profile matches the task.',
			'Use system-abilities-list only when a required group is missing or a plugin-specific specialist tool is needed.',
		];
	}

	/**
	 * @return list<string>
	 */
	private static function workflow_rules( string $profile ): array {
		$rules = [
			'Start with task-start; use context-bootstrap or workflow-preflight only for compatibility, then keep the same compact profile for the task.',
			'Use profile tools before full ability discovery when the client has a strict tool cap.',
			'Prefer batch abilities over repeated single-item calls once the target shape is known.',
			'Use stonewright/php-execute for direct WordPress runtime inspection or compact plugin API calls when typed abilities would require many exploratory requests.',
		];

		if ( 'elementor-design' === $profile ) {
			$rules[] = 'Set global colors and typography first when site-wide style changes are approved.';
			$rules[] = 'Implement one visual section per write-and-verify pass, or two only when simple and coupled.';
			$rules[] = 'Use native Elementor widgets and schema-confirmed Content, Style, and Advanced controls.';
			$rules[] = 'Use get-page-structure summary before existing-page edits; request full tree only when raw settings are needed.';
		}

		if ( 'low-tools' === $profile ) {
			$rules[] = 'Use this profile for strict tool-cap clients, then switch to a specialist profile only when a required tool is missing.';
			$rules[] = 'Keep to composite writes: content-bulk-upsert-posts, media-upload-batch, build-page-from-spec, batch-mutate, gutenberg-apply-to-post, and wp-cli-batch-run.';
		}

		if ( 'content-model' === $profile ) {
			$rules[] = 'Discover plugin command groups once, then batch repeated CPT, field, post, meta, term, option, cache, and rewrite work.';
			$rules[] = 'Use content-bulk-upsert-posts for repeated rows after the post type exists.';
			$rules[] = 'Use WP-CLI background jobs for long imports, cache rebuilds, or plugin-maintenance commands.';
		}

		if ( 'gutenberg' === $profile ) {
			$rules[] = 'Read theme.json, templates, registered blocks, and block supports before writing blocks or FSE templates.';
		}

		return $rules;
	}

	/**
	 * @return list<string>
	 */
	private static function token_rules(): array {
		return [
			'Use profile tools before full ability discovery when the client has a strict tool cap.',
			'Use low-tools for Antigravity, Gemini API, or other strict tool-cap clients before switching to a specialist profile.',
			'Use responseMode=summary for WP-CLI and batch tools unless full JSON is needed for the next write.',
			'Use php-execute for short runtime snippets instead of full ability discovery when the needed WordPress/plugin API call is already known.',
			'Use WP-CLI background jobs only for long-running commands; keep short commands synchronous.',
			'Read schemas for only the widgets or block types used in the current section batch.',
			'Prefer dry_run diagnostics and one section write over many exploratory writes.',
			'Use system-abilities-list only when the selected profile is missing a needed capability.',
		];
	}

	/**
	 * Human-readable summary of tools omitted by max_tools.
	 *
	 * @param list<string> $dropped_names
	 */
	private static function truncation_hint( array $dropped_names, int $max_tools ): string {
		if ( [] === $dropped_names ) {
			return '';
		}

		return sprintf(
			'%d profile tools were dropped from this list by max_tools=%d: %s. They stay callable once the session profile is active even when your client does not list them; re-run with a higher max_tools to see the full ordered list.',
			count( $dropped_names ),
			$max_tools,
			implode( ', ', array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $dropped_names ) )
		);
	}

	/**
	 * @param list<string> $missing_names
	 * @return list<string>
	 */
	private static function recovery_hints( array $missing_names ): array {
		if ( [] === $missing_names ) {
			return [];
		}

		return [
			'Use stonewright/system-abilities-list to inspect disabled or gated abilities before falling back to slower single-call workflows.',
			'If a required profile tool is intentionally disabled, re-enable it in Stonewright AI Abilities or choose the closest available batch tool from this profile.',
			'Use full ability discovery only for specialist recovery sessions; keep compact profiles for normal implementation work.',
		];
	}

	private static function is_theme_builder_task( string $task, string $surface, string $intent ): bool {
		$surface = strtolower( trim( $surface ) );
		if ( 'theme-builder' === $surface ) {
			return true;
		}

		return self::has_any_term(
			self::normalise( $task . ' ' . $surface . ' ' . $intent ),
			[
				'theme builder',
				'theme-builder',
				'display conditions',
				'template conditions',
				'single template',
				'archive template',
				'header template',
				'footer template',
				'elementor template',
				'apply template',
			]
		);
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
}
