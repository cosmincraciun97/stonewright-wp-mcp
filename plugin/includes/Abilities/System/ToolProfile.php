<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
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
		return [ 'auto', 'low-tools', 'essential', 'elementor-design', 'content-model', 'gutenberg', 'wp-cli', 'site-admin', 'full' ];
	}

	public static function suggest_profile( string $task, string $surface = 'unknown', string $intent = 'unknown' ): string {
		$surface = strtolower( trim( $surface ) );
		$query   = self::normalise( $task . ' ' . $surface . ' ' . $intent );

		if ( 'elementor' === $surface || self::has_any_term( $query, [ 'elementor', 'figma', 'design', 'pixel', 'landing page', 'section' ] ) ) {
			return 'elementor-design';
		}

		if ( self::has_any_term( $query, [ 'acf', 'acpt', 'cpt ui', 'custom field', 'custom post type', 'field group', 'meta box', 'metabox', 'pods', 'woocommerce' ] ) ) {
			return 'content-model';
		}

		if ( 'gutenberg' === $surface || self::has_any_term( $query, [ 'block', 'block theme', 'fse', 'gutenberg', 'theme json', 'template part' ] ) ) {
			return 'gutenberg';
		}

		if ( 'wp-cli' === $surface || self::has_any_term( $query, [ 'cache', 'cli', 'plugin', 'rewrite', 'wp cli' ] ) ) {
			return 'wp-cli';
		}

		if ( self::has_any_term( $query, [ 'admin', 'health', 'menu', 'plugin list', 'settings', 'site info' ] ) ) {
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
				'essential_tools_mode'  => [ 'type' => 'boolean' ],
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
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
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

		$visible_rows = array_values(
			array_filter(
				AbilityRegistry::enabled_abilities(),
				static fn( array $ability ): bool => (bool) $ability['enabled']
			)
		);
		$all_visible  = array_fill_keys( array_column( $visible_rows, 'name' ), true );
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

		return [
			'ok'                    => true,
			'profile'               => $profile,
			'requested_profile'     => $requested,
			'max_tools'             => $max_tools,
			'tool_count'            => count( $limited_names ),
			'profile_tool_count'    => $profile_tool_count,
			'under_limit'           => $profile_tool_count <= $max_tools,
			'essential_tools_mode'  => (bool) get_option( 'stonewright_essential_tools_mode', true ),
			'profiles_available'    => self::profile_names(),
			'recommended_tools'     => $limited_names,
			'recommended_mcp_tools' => array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $limited_names ),
			'missing_profile_tools' => $missing_names,
			'missing_mcp_tools'     => array_map( [ AbilityRegistry::class, 'mcp_tool_name' ], $missing_names ),
			'tool_groups'           => $tool_groups,
			'next_best_tools'       => self::next_best_tools( $profile, $tool_groups ),
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
		];
	}

	/**
	 * @return list<string>
	 */
	private static function profile_tools( string $profile ): array {
		$base = [
			'stonewright/context-bootstrap',
			'stonewright/workflow-preflight',
			'stonewright/tool-profile',
			'stonewright/skills-get',
			'stonewright/php-execute',
		];

		return match ( $profile ) {
			'low-tools' => array_merge(
				$base,
				[
					'stonewright/security-create-one-time-link',
					'stonewright/site-info',
					'stonewright/content-bulk-upsert-posts',
					'stonewright/media-upload-batch',
					'stonewright/design-implementation-contract',
					'stonewright/widget-intent-resolve',
					'stonewright/elementor-widget-implementation-guide',
					'stonewright/elementor-v3-status',
					'stonewright/elementor-v3-capabilities-summary',
					'stonewright/elementor-v3-get-kit-globals',
					'stonewright/elementor-v3-container-schema',
					'stonewright/elementor-v3-get-widget-schema',
					'stonewright/elementor-v3-get-page-structure',
					'stonewright/elementor-v3-build-page-from-spec',
					'stonewright/elementor-v3-batch-mutate',
					'stonewright/elementor-v3-update-kit-colors',
					'stonewright/elementor-v3-update-kit-typography',
					'stonewright/gutenberg-apply-to-post',
					'stonewright/wp-cli-batch-run',
					'stonewright/wp-cli-job-start',
					'stonewright/wp-cli-job-status',
				]
			),
			'elementor-design' => array_merge(
				$base,
				[
					'stonewright/site-info',
					'stonewright/site-plugins-list',
					'stonewright/security-create-one-time-link',
					'stonewright/design-implementation-contract',
					'stonewright/widget-intent-resolve',
					'stonewright/elementor-widget-implementation-guide',
					'stonewright/elementor-v3-status',
					'stonewright/elementor-v3-capabilities-summary',
					'stonewright/elementor-v3-get-kit-globals',
					'stonewright/elementor-v3-container-schema',
					'stonewright/elementor-v3-list-widgets',
					'stonewright/elementor-v3-get-widget-schema',
					'stonewright/elementor-describe-widget',
					'stonewright/elementor-v4-status',
					'stonewright/elementor-v4-list-variables',
					'stonewright/elementor-v4-list-classes',
					'stonewright/elementor-v4-list-atomic-node-types',
					'stonewright/media-list',
					'stonewright/media-upload-batch',
					'stonewright/content-create-page',
					'stonewright/content-update-page',
					'stonewright/content-bulk-upsert-posts',
					'stonewright/elementor-v3-update-page-settings',
					'stonewright/elementor-v3-update-kit-colors',
					'stonewright/elementor-v3-update-kit-typography',
					'stonewright/design-validate-spec',
					'stonewright/elementor-v3-build-page-from-spec',
					'stonewright/elementor-v3-batch-mutate',
					'stonewright/elementor-v3-apply-bundle',
					'stonewright/wp-cli-status',
					'stonewright/wp-cli-discover',
					'stonewright/wp-cli-batch-run',
				]
			),
			'content-model' => array_merge(
				$base,
				[
					'stonewright/site-capabilities',
					'stonewright/site-plugins-list',
					'stonewright/system-abilities-list',
					'stonewright/content-bulk-upsert-posts',
					'stonewright/media-list',
					'stonewright/media-upload-batch',
					'stonewright/wp-cli-status',
					'stonewright/wp-cli-discover',
					'stonewright/wp-cli-batch-run',
					'stonewright/wp-cli-run',
					'stonewright/wp-cli-job-start',
					'stonewright/wp-cli-job-status',
				]
			),
			'gutenberg' => array_merge(
				$base,
				[
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
					'stonewright/design-validate-spec',
					'stonewright/design-spec-to-gutenberg',
					'stonewright/gutenberg-apply-to-post',
				]
			),
			'wp-cli' => array_merge(
				$base,
				[
					'stonewright/site-info',
					'stonewright/site-plugins-list',
					'stonewright/wp-cli-status',
					'stonewright/wp-cli-discover',
					'stonewright/wp-cli-batch-run',
					'stonewright/wp-cli-run',
					'stonewright/wp-cli-job-start',
					'stonewright/wp-cli-job-status',
				]
			),
			'site-admin' => array_merge(
				$base,
				[
					'stonewright/site-info',
					'stonewright/site-environment',
					'stonewright/site-health',
					'stonewright/site-plugins-list',
					'stonewright/site-theme',
					'stonewright/security-create-one-time-link',
					'stonewright/system-abilities-list',
					'stonewright/menu-list',
					'stonewright/wp-cli-status',
				]
			),
			default => array_merge(
				$base,
				[
					'stonewright/ping',
					'stonewright/site-info',
					'stonewright/site-capabilities',
					'stonewright/site-plugins-list',
					'stonewright/system-abilities-list',
					'stonewright/wp-cli-status',
				]
			),
		};
	}

	private static function why( string $name ): string {
		return match ( $name ) {
			'stonewright/context-bootstrap' => 'Issue the task token and load live site instructions, memory, skills, and visual gates.',
			'stonewright/workflow-preflight' => 'Choose the task-aware fast path and first call sequence.',
			'stonewright/tool-profile' => 'Keep the MCP tool surface compact for the current model, client, and task.',
			'stonewright/skills-get' => 'Load one matched site playbook on demand instead of injecting every skill into startup context.',
			'stonewright/php-execute' => 'Execute short PHP snippets inside the loaded WordPress runtime when direct plugin API or database inspection is faster than many typed calls.',
			'stonewright/security-create-one-time-link' => 'Create a short-lived wp-admin login URL for external browser MCP verification when needed.',
			'stonewright/design-implementation-contract' => 'Load global-style, native-widget, section-batch, and verification rules.',
			'stonewright/widget-intent-resolve' => 'Map visual intent to native Elementor widgets before writing controls.',
			'stonewright/elementor-widget-implementation-guide' => 'Get Content, Style, and Advanced controls before Elementor writes.',
			'stonewright/elementor-v3-get-kit-globals' => 'Read active Elementor kit colors and typography before global-style writes.',
			'stonewright/elementor-v3-get-widget-schema' => 'Read compact Content, Style, and Advanced widget controls; request full only for defaults.',
			'stonewright/elementor-v3-get-page-structure' => 'Read a compact Elementor outline first; request full tree only for raw setting drift or difficult edits.',
			'stonewright/elementor-v3-build-page-from-spec' => 'Render a validated Elementor section or page spec in one request.',
			'stonewright/elementor-v3-container-schema' => 'Get container layout, style, Advanced, alias, and blocked-key guidance before section writes.',
			'stonewright/elementor-v3-batch-mutate' => 'Apply grouped surgical Elementor mutations after screenshot review.',
			'stonewright/content-bulk-upsert-posts' => 'Create or update repeated posts, CPT rows, and meta values in one call.',
			'stonewright/wp-cli-batch-run' => 'Run repeated tokenized WP-CLI argv commands with compact output.',
			'stonewright/wp-cli-job-start' => 'Start long WP-CLI command or batch work without blocking the MCP request.',
			'stonewright/wp-cli-job-status' => 'Poll a WP-CLI background job until the compact result is ready.',
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
		if ( in_array( $name, [ 'stonewright/context-bootstrap', 'stonewright/workflow-preflight', 'stonewright/tool-profile', 'stonewright/skills-get' ], true ) ) {
			return 'startup';
		}

		if ( 'stonewright/php-execute' === $name ) {
			return 'runtime';
		}

		if ( str_contains( $name, '/wp-cli-' ) ) {
			return 'wp_cli';
		}

		if ( str_contains( $name, 'elementor' ) || str_contains( $name, 'design' ) || str_contains( $name, 'widget' ) ) {
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
	private static function next_best_tools( string $profile, array $tool_groups ): array {
		$preferred_abilities = match ( $profile ) {
			'elementor-design', 'low-tools' => [
				'stonewright/elementor-v3-build-page-from-spec',
				'stonewright/elementor-v3-batch-mutate',
				'stonewright/elementor-v3-get-kit-globals',
				'stonewright/content-bulk-upsert-posts',
				'stonewright/media-upload-batch',
				'stonewright/wp-cli-batch-run',
				'stonewright/wp-cli-job-start',
			],
			'content-model' => [
				'stonewright/php-execute',
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
				if ( in_array( $ability, [ 'stonewright/context-bootstrap', 'stonewright/workflow-preflight', 'stonewright/tool-profile', 'stonewright/skills-get' ], true ) ) {
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
			'Start with context-bootstrap or workflow-preflight, then keep the same compact profile for the task.',
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
