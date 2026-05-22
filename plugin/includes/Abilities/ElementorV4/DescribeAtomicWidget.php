<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicWidgetMap;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Describes a single DesignSpec node type — its target atomic widget, whether
 * it is a container, and the props the V4 atomic renderer accepts.
 *
 * The prop catalog below mirrors what {@see \Stonewright\WpMcp\Elementor\V4\AtomicRenderer::build_settings()}
 * actually translates. Keep it in sync whenever build_settings() learns a
 * new prop.
 *
 * Catalog source-of-truth (mirrors AtomicRenderer::build_settings):
 *   Heading    : text, level                 → settings.title, settings.tag
 *   TextEditor : text                        → settings.paragraph
 *   Image      : url, alt                    → settings.image, settings.alt
 *   Button     : text, link                  → settings.text, settings.link
 *   Divider    : (none)
 *   Icon       : svg                         → settings.svg
 *   Section / Column / Container :
 *                direction, gap              → settings.flex-direction, settings.gap
 *
 * @stonewright-status stable
 */
final class DescribeAtomicWidget extends AbilityKernel {

	/**
	 * Per-type prop catalog. Each entry is what the renderer is willing to
	 * forward into the V4 atomic envelope — not every Elementor-side option,
	 * just the surface Stonewright actively supports today.
	 *
	 * @var array<string, array<int, array{name: string, type: string, default?: mixed}>>
	 */
	private const PROP_CATALOG = [
		'Heading'    => [
			[ 'name' => 'text',  'type' => 'string',  'default' => '' ],
			[ 'name' => 'level', 'type' => 'number',  'default' => 2 ],
		],
		'TextEditor' => [
			[ 'name' => 'text', 'type' => 'string', 'default' => '' ],
		],
		'Image'      => [
			[ 'name' => 'url', 'type' => 'string', 'default' => '' ],
			[ 'name' => 'alt', 'type' => 'string', 'default' => '' ],
		],
		'Button'     => [
			[ 'name' => 'text', 'type' => 'string', 'default' => '' ],
			[ 'name' => 'link', 'type' => 'string', 'default' => '' ],
		],
		'Divider'    => [],
		'Icon'       => [
			[ 'name' => 'svg', 'type' => 'string', 'default' => '' ],
		],
		'Section'    => [
			[ 'name' => 'direction', 'type' => 'string', 'default' => 'column' ],
			[ 'name' => 'gap',       'type' => 'size',   'default' => '0px' ],
		],
		'Column'     => [
			[ 'name' => 'direction', 'type' => 'string', 'default' => 'column' ],
			[ 'name' => 'gap',       'type' => 'size',   'default' => '0px' ],
		],
		'Container'  => [
			[ 'name' => 'direction', 'type' => 'string', 'default' => 'column' ],
			[ 'name' => 'gap',       'type' => 'size',   'default' => '0px' ],
		],
	];

	public function name(): string {
		return 'stonewright/elementor-v4-describe-atomic-widget';
	}

	public function label(): string {
		return __( 'Describe Elementor V4 atomic widget', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the prop catalog the V4 atomic renderer supports for a given DesignSpec node type.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'node_type' ],
			'properties'           => [
				'node_type' => [
					'type'    => 'string',
					'minLength' => 1,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'node_type'    => [ 'type' => 'string' ],
				'widget'       => [ 'type' => 'string' ],
				'is_container' => [ 'type' => 'boolean' ],
				'props'        => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'    => [ 'type' => 'string' ],
							'type'    => [ 'type' => 'string' ],
							'default' => [],
						],
						'required'   => [ 'name', 'type' ],
					],
				],
			],
			'required'   => [ 'node_type', 'widget', 'is_container', 'props' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$node_type = (string) ( $a['node_type'] ?? '' );

				$widget = AtomicWidgetMap::widget_type( $node_type );
				if ( null === $widget ) {
					return $this->error(
						'unknown_node_type',
						sprintf(
							/* translators: %s: requested node type */
							__( 'Unknown DesignSpec node type: %s.', 'stonewright' ),
							$node_type
						)
					);
				}

				$props = self::PROP_CATALOG[ $node_type ] ?? [];

				return [
					'node_type'    => $node_type,
					'widget'       => $widget,
					'is_container' => AtomicWidgetMap::is_container( $node_type ),
					'props'        => $props,
				];
			}
		);
	}
}
