<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\WidgetRegistry\EditorTabKnowledge;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetWidgetSchema extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-get-widget-schema';
	}

	public function label(): string {
		return __( 'Get Elementor widget schema', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns compact Content, Style, and Advanced control groups for a single Elementor widget by default, or full control defaults when responseMode=full.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name'         => [ 'type' => 'string' ],
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'full' ],
					'default'     => 'summary',
					'description' => 'Use summary for control names, types, labels, sections, and editor tabs; use full only when default values are required.',
				],
				'tabFilter'    => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Optional editor tab names to include, such as Content, Style, Advanced.',
				],
				'controlFilter' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Optional case-insensitive substrings matched against control name, type, label, and section.',
				],
				'maxControls'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Maximum controls returned after filters. Defaults to all matching controls.',
				],
			],
			'required'             => [ 'name' ],
		];
	}

	public function output_schema(): array {
		$tab_group_schema = [
			'type'       => 'object',
			'properties' => [
				'count'           => [ 'type' => 'integer' ],
				'controls'        => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'    => [ 'type' => 'string' ],
							'type'    => [ 'type' => 'string' ],
							'label'   => [ 'type' => 'string' ],
							'tab'     => [ 'type' => 'string' ],
							'section' => [ 'type' => 'string' ],
						],
					],
				],
				'global_controls' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'name'              => [ 'type' => 'string' ],
				'response_mode'     => [ 'type' => 'string' ],
				'title'             => [ 'type' => 'string' ],
				'categories'        => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'controls'          => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'tab_groups'        => [
					'type'       => 'object',
					'properties' => [
						'Content'  => $tab_group_schema,
						'Style'    => $tab_group_schema,
						'Advanced' => $tab_group_schema,
						'Unknown'  => $tab_group_schema,
					],
				],
				'research_guidance' => [ 'type' => 'string' ],
				'defaults_omitted'  => [ 'type' => 'boolean' ],
				'full_mode_hint'    => [ 'type' => 'string' ],
				'filters'           => [ 'type' => 'object' ],
				'schema_hash'       => [ 'type' => 'string' ],
				'runtime_fingerprint' => [ 'type' => 'string' ],
			],
			'required'   => [ 'name', 'controls', 'tab_groups', 'research_guidance' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$schema = WidgetSchemaRepository::get( (string) $args['name'] );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}

		$controls = [];
		foreach ( (array) $schema['controls'] as $key => $control ) {
			$controls[] = [ 'name' => (string) $key ] + (array) $control;
		}

		$response_mode = (string) ( $args['responseMode'] ?? 'summary' );
		$filters          = self::filters( $args );
		$compact_controls = self::filter_controls( self::compact_controls( $controls ), $filters );
		$full_controls    = self::filter_controls( $controls, $filters );
		$output_controls  = 'full' === $response_mode ? $full_controls : $compact_controls;

		return [
			'name'              => (string) $args['name'],
			'response_mode'     => 'full' === $response_mode ? 'full' : 'summary',
			'title'             => (string) $schema['title'],
			'categories'        => (array) $schema['categories'],
			'schema_hash'       => (string) $schema['schema_hash'],
			'runtime_fingerprint' => (string) $schema['runtime_fingerprint'],
			'controls'          => $output_controls,
			'tab_groups'        => EditorTabKnowledge::group_controls( $compact_controls ),
			'research_guidance' => 'Research official Elementor documentation online when this widget schema lacks enough Content or Style controls for the requested design.',
			'defaults_omitted'  => 'full' !== $response_mode,
			'full_mode_hint'    => 'Call with responseMode=full only when default values are required for the next write.',
			'filters'           => [
				'tab_filter'     => $filters['tab_filter'],
				'control_filter' => $filters['control_filter'],
				'max_controls'   => $filters['max_controls'],
			],
		];
	}

	/**
	 * @param list<array<string, mixed>> $controls
	 * @return list<array<string, string>>
	 */
	private static function compact_controls( array $controls ): array {
		return array_map(
			static fn( array $control ): array => [
				'name'    => (string) ( $control['name'] ?? '' ),
				'type'    => (string) ( $control['type'] ?? '' ),
				'label'   => (string) ( $control['label'] ?? '' ),
				'tab'     => (string) ( $control['tab'] ?? '' ),
				'section' => (string) ( $control['section'] ?? '' ),
			],
			$controls
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{tab_filter:list<string>,tab_lookup:array<string,true>,control_filter:list<string>,max_controls:int|null}
	 */
	private static function filters( array $args ): array {
		$tab_filter = [];
		foreach ( (array) ( $args['tabFilter'] ?? [] ) as $tab ) {
			$tab = self::canonical_tab( (string) $tab );
			if ( '' !== $tab ) {
				$tab_filter[] = $tab;
			}
		}
		$tab_filter = array_values( array_unique( $tab_filter ) );

		$control_filter = [];
		foreach ( (array) ( $args['controlFilter'] ?? [] ) as $filter ) {
			$filter = strtolower( trim( (string) $filter ) );
			if ( '' !== $filter ) {
				$control_filter[] = $filter;
			}
		}

		$max_controls = null;
		if ( isset( $args['maxControls'] ) ) {
			$max_controls = max( 1, min( 500, (int) $args['maxControls'] ) );
		}

		return [
			'tab_filter'     => $tab_filter,
			'tab_lookup'     => array_fill_keys( $tab_filter, true ),
			'control_filter' => array_values( array_unique( $control_filter ) ),
			'max_controls'   => $max_controls,
		];
	}

	/**
	 * @param list<array<string, mixed>> $controls
	 * @param array{tab_filter:list<string>,tab_lookup:array<string,true>,control_filter:list<string>,max_controls:int|null} $filters
	 * @return list<array<string, mixed>>
	 */
	private static function filter_controls( array $controls, array $filters ): array {
		$out = [];
		foreach ( $controls as $control ) {
			if ( [] !== $filters['tab_filter'] && ! isset( $filters['tab_lookup'][ self::canonical_tab( (string) ( $control['tab'] ?? '' ) ) ] ) ) {
				continue;
			}
			if ( [] !== $filters['control_filter'] && ! self::control_matches_filter( $control, $filters['control_filter'] ) ) {
				continue;
			}
			$out[] = $control;
			if ( null !== $filters['max_controls'] && count( $out ) >= $filters['max_controls'] ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $control
	 * @param list<string>        $filters
	 */
	private static function control_matches_filter( array $control, array $filters ): bool {
		$haystack = strtolower(
			implode(
				' ',
				[
					(string) ( $control['name'] ?? '' ),
					(string) ( $control['type'] ?? '' ),
					(string) ( $control['label'] ?? '' ),
					(string) ( $control['section'] ?? '' ),
				]
			)
		);
		foreach ( $filters as $filter ) {
			if ( str_contains( $haystack, $filter ) ) {
				return true;
			}
		}
		return false;
	}

	private static function canonical_tab( string $tab ): string {
		$tab = strtolower( trim( $tab ) );
		return match ( $tab ) {
			'content' => 'Content',
			'style'   => 'Style',
			'advanced' => 'Advanced',
			default   => '' !== $tab ? ucfirst( $tab ) : '',
		};
	}
}
