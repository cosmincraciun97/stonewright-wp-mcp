<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Design\ImplementationContract;
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
				'include_design_contract' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Inline the full design implementation contract. Defaults to false; use design_contract_ref for compact startup.',
				],
				'responseMode'            => [
					'type'        => 'string',
					'enum'        => [ 'full', 'compact' ],
					'default'     => 'full',
					'description' => 'Use compact to return hashes and small refs for long context sections.',
				],
				'knownHashes'             => [
					'type'        => 'object',
					'description' => 'Optional client-known payload hashes keyed by response field, used to return changed/unchanged key lists.',
				],
			],
		];
	}

	public function output_schema(): array {
		$elementor_summary_schema = ( new CapabilitiesSummary() )->output_schema();
		$elementor_properties     = is_array( $elementor_summary_schema['properties'] ?? null )
			? $elementor_summary_schema['properties']
			: [];
		$elementor_properties     = array_merge(
			[
				'included'     => [ 'type' => 'boolean' ],
				'reason'       => [ 'type' => 'string' ],
				'request_tool' => [ 'type' => 'string' ],
			],
			$elementor_properties
		);

		return [
			'type'       => 'object',
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'context_token' => [ 'type' => 'string' ],
				'expires_at'    => [ 'type' => 'string' ],
				'mode'          => [ 'type' => 'string' ],
				'auth_guidance' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'fast_path'     => [ 'type' => 'object' ],
				'elementor'     => [
					'type'       => 'object',
					'properties' => $elementor_properties,
				],
				'site'          => [ 'type' => 'object' ],
				'context'       => [ 'type' => 'object' ],
				'response_mode' => [ 'type' => 'string' ],
				'payload_hashes' => [ 'type' => 'object' ],
				'changed_keys'  => [ 'type' => 'array' ],
				'unchanged_keys' => [ 'type' => 'array' ],
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
		if ( self::should_offer_skill_get( $context, $specializations ) ) {
			$recommended[] = 'stonewright/skills-get';
			$recommended   = array_values( array_unique( $recommended ) );
		}
		$tool_profile = self::compact_tool_profile( $task, $surface, $intent );
		if ( is_wp_error( $tool_profile ) ) {
			return $tool_profile;
		}
		$compact_playbooks = self::compact_playbooks( $context['matched_skill_playbooks'] ?? [] );

		$elementor = self::elementor_context( $task_profile, $task, $surface, $intent );
		if ( is_wp_error( $elementor ) ) {
			return $elementor;
		}
		$include_design_contract = true === ( $args['include_design_contract'] ?? false );
		$fast_path               = [
			'task_profile'          => $task_profile,
			'tool_profile'          => $tool_profile,
			'recommended_tools'     => $recommended,
			'recommended_mcp_tools' => array_map( [ self::class, 'mcp_tool_name' ], $recommended ),
			'call_sequence'         => self::call_sequence( $task, $task_profile, $compact_playbooks, $specializations ),
			'specializations'       => $specializations,
			'visual_build_gate'     => $context['visual_build_gate'] ?? [],
			'visual_setup'          => self::visual_setup( $task_profile ),
			'batching_rules'        => self::batching_rules( $task_profile ),
			'quality_gates'         => self::quality_gates( $task_profile ),
			'external_mcps'         => [
				'Use external Figma MCP for design extraction.',
				'Use external Playwright/browser MCP for screenshots and visual QA.',
			],
		];
		if ( self::should_reference_design_contract( $task_profile ) ) {
			$fast_path['design_contract_ref'] = self::design_contract_ref( $include_design_contract );
			if ( $include_design_contract ) {
				$fast_path['design_implementation_contract'] = ImplementationContract::contract();
			}
		}

		$response = [
			'ok'            => true,
			'context_token' => (string) ( $context['context_token'] ?? '' ),
			'expires_at'    => (string) ( $context['expires_at'] ?? '' ),
			'mode'          => $mode,
			'auth_guidance' => [
				'Use a WordPress Application Password for HTTP MCP authentication.',
				'Keep the Mcp-Session-Id response header on later JSON-RPC calls.',
				'Use MCP tool names with hyphens, for example stonewright-context-bootstrap.',
			],
			'fast_path'     => $fast_path,
			'elementor'     => $elementor,
			'site'          => [
				'ability_count'        => count( AbilityRegistry::list() ),
				'public_ability_count' => count( AbilityRegistry::enabled_abilities() ),
				'essential_tools_mode' => (bool) get_option( 'stonewright_essential_tools_mode', true ),
				'mcp_server_id'        => 'stonewright',
				'ability_prefix'       => 'stonewright/',
			],
			'context'       => [
				'matched_skills'          => $context['matched_skills'] ?? [],
				'matched_skill_playbooks' => $compact_playbooks,
				'memory_entries'          => $context['memory_entries'] ?? [],
				'required_followups'      => $context['required_followups'] ?? [],
			],
		];

		if ( 'compact' === (string) ( $args['responseMode'] ?? 'full' ) ) {
			return self::compact_response( $response, is_array( $args['knownHashes'] ?? null ) ? $args['knownHashes'] : [] );
		}

		$response['response_mode'] = 'full';
		return $response;
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $known_hashes
	 * @return array<string, mixed>
	 */
	private static function compact_response( array $response, array $known_hashes ): array {
		$hash_keys = [ 'auth_guidance', 'fast_path', 'elementor', 'site', 'context' ];
		[ $payload_hashes, $changed, $unchanged ] = self::hash_delta( $response, $known_hashes, $hash_keys );

		$response['response_mode']  = 'compact';
		$response['payload_hashes'] = $payload_hashes;
		$response['changed_keys']   = $changed;
		$response['unchanged_keys'] = $unchanged;

		if ( isset( $response['fast_path']['visual_build_gate'] ) ) {
			$response['fast_path']['visual_build_gate'] = self::compact_object_ref( 'visual_build_gate', $response['fast_path']['visual_build_gate'] );
		}
		if ( isset( $response['fast_path']['design_implementation_contract'] ) ) {
			unset( $response['fast_path']['design_implementation_contract'] );
		}
		if ( isset( $response['context']['memory_entries'] ) && is_array( $response['context']['memory_entries'] ) ) {
			$response['context']['memory_entries'] = self::compact_memory_entries( $response['context']['memory_entries'] );
		}

		return $response;
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $known_hashes
	 * @param list<string>        $keys
	 * @return array{0:array<string,string>,1:list<string>,2:list<string>}
	 */
	private static function hash_delta( array $response, array $known_hashes, array $keys ): array {
		$payload_hashes = [];
		$changed        = [];
		$unchanged      = [];

		foreach ( $keys as $key ) {
			$hash                   = self::hash_value( $response[ $key ] ?? null );
			$payload_hashes[ $key ] = $hash;
			if ( isset( $known_hashes[ $key ] ) && (string) $known_hashes[ $key ] === $hash ) {
				$unchanged[] = $key;
			} else {
				$changed[] = $key;
			}
		}

		return [ $payload_hashes, $changed, $unchanged ];
	}

	private static function hash_value( mixed $value ): string {
		return hash( 'sha256', wp_json_encode( $value ) ?: serialize( $value ) );
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	private static function compact_object_ref( string $key, mixed $value ): array {
		return [
			'compact' => true,
			'key'     => $key,
			'hash'    => self::hash_value( $value ),
			'length'  => strlen( wp_json_encode( $value ) ?: '' ),
		];
	}

	/**
	 * @param mixed $entries
	 * @return list<array<string, mixed>>
	 */
	private static function compact_memory_entries( mixed $entries ): array {
		$out = [];
		foreach ( is_array( $entries ) ? $entries : [] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$value = $entry['value'] ?? $entry['value_json'] ?? null;
			$out[] = [
				'id'         => (string) ( $entry['id'] ?? '' ),
				'type'       => (string) ( $entry['type'] ?? '' ),
				'scope'      => (string) ( $entry['scope'] ?? '' ),
				'memory_key' => (string) ( $entry['memory_key'] ?? '' ),
				'name'       => (string) ( $entry['name'] ?? '' ),
				'value_hash' => self::hash_value( $value ),
			];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $context
	 * @param list<array<string, mixed>> $specializations
	 */
	private static function should_offer_skill_get( array $context, array $specializations ): bool {
		return ! empty( $context['matched_skills'] )
			|| ! empty( $context['matched_skill_playbooks'] )
			|| [] !== $specializations;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compact_tool_profile( string $task, string $surface, string $intent ): array|\WP_Error {
		$profile = ( new ToolProfile() )->execute(
			[
				'profile'   => ToolProfile::suggest_profile( $task, $surface, $intent ),
				'task'      => $task,
				'surface'   => $surface,
				'intent'    => $intent,
				'max_tools' => 40,
			]
		);
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		return [
			'profile'            => $profile['profile'],
			'tool_count'         => $profile['tool_count'],
			'profile_tool_count' => $profile['profile_tool_count'],
			'under_limit'        => $profile['under_limit'],
			'tool_groups'        => $profile['tool_groups'],
			'next_best_tools'    => $profile['next_best_tools'],
			'discovery_policy'   => $profile['discovery_policy'],
		];
	}

	/**
	 * @param array<string, bool|string> $profile
	 */
	private static function should_reference_design_contract( array $profile ): bool {
		return ! empty( $profile['needs_visual_check'] )
			|| ( 'elementor' === $profile['surface'] && ! empty( $profile['is_write'] ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function design_contract_ref( bool $inlined ): array {
		$contract = ImplementationContract::contract();

		return [
			'inlined'            => $inlined,
			'ability'            => 'stonewright/design-implementation-contract',
			'mcp_tool'           => self::mcp_tool_name( 'stonewright/design-implementation-contract' ),
			'hash'               => self::hash_value( $contract ),
			'load_when'          => 'Before the first visual Elementor write, or when planning native widget and global-style mapping.',
			'sequence'           => $contract['sequence'],
			'global_style_tools' => $contract['global_styles_first']['tools'],
			'section_batch'      => [
				'default_sections_per_pass' => $contract['section_batch']['default_sections_per_pass'],
				'max_sections_per_pass'     => $contract['section_batch']['max_sections_per_pass'],
				'primary_write_tool'        => $contract['section_batch']['primary_write_tool'],
				'surgical_fix_tool'         => $contract['section_batch']['surgical_fix_tool'],
			],
		];
	}

	/**
	 * @param array<string, bool|string> $profile
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function elementor_context( array $profile, string $task, string $surface, string $intent ): array|\WP_Error {
		$should_include = 'elementor' === $profile['surface']
			|| 'elementor-design' === ToolProfile::suggest_profile( $task, $surface, $intent );

		if ( $should_include ) {
			$summary = ( new CapabilitiesSummary() )->execute( [] );
			if ( is_wp_error( $summary ) ) {
				return $summary;
			}
			return array_merge( [ 'included' => true ], $summary );
		}

		return [
			'included'     => false,
			'reason'       => 'Omitted for non-Elementor preflight to keep content-model, Gutenberg, WP-CLI, and site-admin startup compact.',
			'request_tool' => 'stonewright/elementor-v3-capabilities-summary',
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
		$tools = [ 'stonewright/workflow-preflight', 'stonewright/tool-profile' ];

		if ( 'elementor' === $profile['surface'] ) {
			$tools[] = 'stonewright/design-implementation-contract';
			$tools[] = 'stonewright/widget-intent-resolve';
			$tools[] = 'stonewright/elementor-widget-implementation-guide';
			$tools[] = 'stonewright/elementor-v3-capabilities-summary';
			$tools[] = 'stonewright/elementor-v3-get-kit-globals';
			$tools[] = 'stonewright/elementor-v3-container-schema';
			$tools[] = 'stonewright/elementor-v3-get-widget-schema';
			if ( $profile['is_write'] ) {
				$tools[] = 'stonewright/media-list';
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
				$tools[] = 'stonewright/wp-cli-batch-run';
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
			'Use stonewright-wp-cli-batch-run with responseMode=summary for repeated CPT UI, ACF, post, meta, term, option, and plugin command work.',
			'Use stonewright-wp-cli-job-start plus stonewright-wp-cli-job-status for long guarded WP-CLI work so MCP requests do not block.',
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
				'For design-derived visual specs, set style_policy=strict and include style_source or style._source for any measured border, radius, shadow, or filter values; do not invent card chrome.',
				'Before uploading assets, audit existing media and reuse matching filenames, alt text, dimensions, and crops.'
			);
			$gates[] = 'Verify desktop, tablet, and mobile breakpoints with an external browser MCP.';
		}

		return $gates;
	}

	/**
	 * @param array<string, bool|string> $profile
	 * @param list<array<string, mixed>> $compact_playbooks
	 * @param list<array<string, mixed>> $specializations
	 * @return list<array<string, mixed>>
	 */
	private static function call_sequence( string $task, array $profile, array $compact_playbooks = [], array $specializations = [] ): array {
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
		if ( [] !== $compact_playbooks || [] !== $specializations ) {
			$out[] = self::call_step(
				'stonewright/skills-get',
				'Load one matched site playbook only when its compact preflight summary applies.',
				[
					'slug' => self::primary_skill_slug( $compact_playbooks, $specializations ),
				]
			);
		}

		if ( 'elementor' === $profile['surface'] ) {
			$out[] = self::call_step(
				'stonewright/design-implementation-contract',
				'Load the compact section-batch, global-style, native-widget, and token-efficiency contract before planning writes.',
				[]
			);
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
			$out[] = self::call_step(
				'stonewright/elementor-v3-container-schema',
				'Get safe container layout, style, Advanced, alias, and blocked-key guidance before section layout writes.',
				[ 'include_controls' => false ]
			);
			if ( $profile['is_write'] ) {
				$out[] = self::call_step(
					'stonewright/media-list',
					'Search existing WordPress media by title, alt text, caption, mime, and filename before uploading design assets.',
					[
						'search'    => '<asset filename or alt clue>',
						'mime_type' => 'image',
						'per_page'  => 20,
					]
				);
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
				'stonewright/wp-cli-batch-run',
				'Run repeated tokenized WP-CLI commands in one request with compact output; use for CPT UI/ACF option updates, repeated post/meta/term commands, and plugin commands after discovery.',
				[
					'commands'                 => [
						[ '<command-group>', '<subcommand>', '--format=json' ],
						[ '<command-group>', '<subcommand>', '--format=json' ],
					],
					'parseJson'                => true,
					'responseMode'             => 'summary',
					'stonewright_context_token' => '<context_token>',
				]
			);
			$out[] = self::call_step(
				'stonewright/wp-cli-run',
				'Run one tokenized WP-CLI argv after discovery; prefer batch-run for repeated work. No shell or eval entry points.',
				[
					'command'                  => [ '<command-group>', '<subcommand>', '--format=json' ],
					'parseJson'                => true,
					'responseMode'             => 'summary',
					'stonewright_context_token' => '<context_token>',
				]
			);
		}

		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $compact_playbooks
	 * @param list<array<string, mixed>> $specializations
	 */
	private static function primary_skill_slug( array $compact_playbooks, array $specializations ): string {
		$first = $compact_playbooks[0]['slug'] ?? '';
		if ( is_string( $first ) && '' !== $first ) {
			return $first;
		}

		$ids = array_filter(
			array_map(
				static fn( array $specialization ): string => is_string( $specialization['id'] ?? null ) ? $specialization['id'] : '',
				$specializations
			)
		);

		return in_array( 'woocommerce', $ids, true )
			? 'stonewright-woocommerce-catalog'
			: 'stonewright-content-model-integrations';
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
