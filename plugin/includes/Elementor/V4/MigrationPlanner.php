<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Explicit V3-to-V4 migration planner with a complete per-element loss report. */
final class MigrationPlanner {
	/**
	 * @param array<int, mixed> $tree
	 * @return array<string, mixed>
	 */
	public static function plan( array $tree ): array {
		$losses = [];
		$counts = [ 'preserved_v4' => 0, 'converted_v3' => 0, 'unsupported' => 0 ];
		$out    = [];
		foreach ( $tree as $index => $element ) {
			$converted = self::convert( is_array( $element ) ? $element : [], [ (int) $index ], $losses, $counts );
			if ( null !== $converted ) {
$out[] = $converted; }
		}
		return [
			'converted_tree'      => $out,
			'loss_report'        => $losses,
			'counts'             => $counts,
			'write_ready'        => [] === $losses,
			'implicit_conversion' => false,
			'schema_fingerprint' => AtomicSchemaRepository::fingerprint(),
		];
	}

	/** @param array<string, mixed> $element @param list<int|string> $path @param list<array<string, mixed>> $losses @param array<string, int> $counts @return array<string, mixed>|null */
	private static function convert( array $element, array $path, array &$losses, array &$counts ): ?array {
		$el_type = (string) ( $element['elType'] ?? '' );
		$type    = 'widget' === $el_type ? (string) ( $element['widgetType'] ?? '' ) : $el_type;
		if ( str_starts_with( $type, 'e-' ) ) {
			++$counts['preserved_v4'];
			return $element;
		}

		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
		$node     = self::node( $el_type, $type, $settings );
		if ( is_wp_error( $node ) ) {
			++$counts['unsupported'];
			$losses[] = [ 'path' => $path, 'element_id' => (string) ( $element['id'] ?? '' ), 'type' => $type, 'reason' => $node->get_error_message(), 'action' => 'keep_v3_or_rebuild_explicitly' ];
			return null;
		}
		$children = [];
		foreach ( (array) ( $element['elements'] ?? [] ) as $index => $child ) {
			if ( ! is_array( $child ) ) {
continue; }
			$converted = self::convert( $child, array_merge( $path, [ 'elements', (int) $index ] ), $losses, $counts );
			if ( null !== $converted ) {
$children[] = $converted; }
		}
		$rendered = AtomicRenderer::render_node( $node, $path );
		if ( is_wp_error( $rendered ) ) {
			++$counts['unsupported'];
			$losses[] = [ 'path' => $path, 'element_id' => (string) ( $element['id'] ?? '' ), 'type' => $type, 'reason' => $rendered->get_error_message(), 'action' => 'refresh_schema_or_keep_v3' ];
			return null;
		}
		$rendered['elements'] = $children;
		++$counts['converted_v3'];
		return $rendered;
	}

	/** @param array<string, mixed> $settings @return array<string, mixed>|\WP_Error */
	private static function node( string $el_type, string $type, array $settings ): array|\WP_Error {
		$mapping = [
			'container'   => [ 'Container', [ 'flex_direction' => 'direction', 'flex_gap' => 'gap' ] ],
			'heading'     => [ 'Heading', [ 'title' => 'text', 'header_size' => 'level' ] ],
			'text-editor' => [ 'TextEditor', [ 'editor' => 'text' ] ],
			'button'      => [ 'Button', [ 'text' => 'text', 'link' => 'link' ] ],
			'image'       => [ 'Image', [ 'image' => 'url', 'alt' => 'alt' ] ],
			'divider'     => [ 'Divider', [] ],
		];
		$key = 'widget' === $el_type ? $type : $el_type;
		if ( ! isset( $mapping[ $key ] ) ) {
return new \WP_Error( 'stonewright_v4_migration_unsupported', 'No verified V4 mapping exists.' ); }
		[ $node_type, $field_map ] = $mapping[ $key ];
		$props = [];
		foreach ( $settings as $source => $value ) {
			if ( ! isset( $field_map[ $source ] ) ) {
				if ( '' !== $value && [] !== $value && null !== $value ) {
return new \WP_Error( 'stonewright_v4_migration_loss', 'The V3 element contains settings with no verified V4 mapping: ' . $source ); }
				continue;
			}
			$target = $field_map[ $source ];
			if ( 'level' === $target ) {
$value = (int) str_replace( 'h', '', (string) $value ); }
			if ( 'link' === $target && is_array( $value ) ) {
$value = (string) ( $value['url'] ?? '' ); }
			if ( 'url' === $target && is_array( $value ) ) {
$value = (string) ( $value['url'] ?? '' ); }
			if ( 'gap' === $target && is_array( $value ) ) {
$value = [ 'unit' => (string) ( $value['unit'] ?? 'px' ), 'size' => (float) ( $value['size'] ?? 0 ) ]; }
			$props[ $target ] = $value;
		}
		if ( 'Button' === $node_type && empty( $props['link'] ) ) {
return new \WP_Error( 'stonewright_v4_unresolved_action', 'Button destination is missing.' ); }
		return [ 'type' => $node_type, 'props' => $props ];
	}
}
