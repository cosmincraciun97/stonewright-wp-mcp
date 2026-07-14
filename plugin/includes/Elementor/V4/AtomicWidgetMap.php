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

	public static function widget_type( string $node_type ): ?string {
		$schema = AtomicSchemaRepository::for_design_type( $node_type );
		return null === $schema ? null : (string) $schema['atomic_type'];
	}

	public static function is_container( string $node_type ): bool {
		$schema = AtomicSchemaRepository::for_design_type( $node_type );
		return null !== $schema && 'layout' === ( $schema['kind'] ?? '' );
	}

	/**
	 * Returns every DesignSpec node type the map knows about. Used by
	 * introspection abilities so the model can see what's supported.
	 *
	 * @return array<int, string>
	 */
	public static function known_node_types(): array {
		$out = [];
		foreach ( AtomicSchemaRepository::all() as $schema ) {
			foreach ( (array) ( $schema['design_types'] ?? [] ) as $type ) {
				if ( is_string( $type ) ) {
					$out[] = $type;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}
}
