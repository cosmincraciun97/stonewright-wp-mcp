<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/**
 * DesignSpec node type → Elementor V4 atomic widget identifier.
 *
 * Every entry must be a widget that ships in current Elementor core's V4
 * atomic registry — no Pro placeholders. When Elementor adds new atomic
 * widgets, extend this list and the schema-method switch in
 * {@see AtomicCompiler::schema_method()} together.
 *
 * The map intentionally collapses every container-flavoured DesignSpec node
 * (Section / Column / Container) into the single `e-flexbox` atomic — V4
 * does not have separate row/column containers, only flexboxes whose
 * direction prop decides the axis.
 */
final class AtomicWidgetMap {

	private const MAP = [
		// Containers (all map to e-flexbox; direction set via prop).
		'Section'    => 'e-flexbox',
		'Column'     => 'e-flexbox',
		'Container'  => 'e-flexbox',
		// Leaf widgets.
		'Heading'    => 'e-heading',
		'TextEditor' => 'e-paragraph',
		'Image'      => 'e-image',
		'Button'     => 'e-button',
		'Divider'    => 'e-divider',
		'Icon'       => 'e-svg',
	];

	private const CONTAINER_TYPES = [ 'Section', 'Column', 'Container' ];

	public static function widget_type( string $node_type ): ?string {
		return self::MAP[ $node_type ] ?? null;
	}

	public static function is_container( string $node_type ): bool {
		return in_array( $node_type, self::CONTAINER_TYPES, true );
	}

	/**
	 * Returns every DesignSpec node type the map knows about. Used by
	 * introspection abilities so the model can see what's supported.
	 *
	 * @return array<int, string>
	 */
	public static function known_node_types(): array {
		return array_keys( self::MAP );
	}
}
