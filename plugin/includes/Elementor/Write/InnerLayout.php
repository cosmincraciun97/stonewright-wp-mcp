<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

use Stonewright\WpMcp\Support\ElementorData;

/** The layout element Elementor stores for an inner section. */
final class InnerLayout {

	/**
	 * A nested container inside a container, an inner section with one column inside a column. Null when the
	 * parent cannot hold an inner section.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, int>                  $parent_path
	 * @return array<string, mixed>|null
	 */
	public static function element( array $tree, array $parent_path ): ?array {
		$parent_type = (string) ( ElementorData::element_at( $tree, $parent_path )['elType'] ?? '' );
		if ( 'container' === $parent_type ) {
			return [
				'id'       => ElementorData::generate_id(),
				'elType'   => 'container',
				'isInner'  => true,
				'settings' => [],
				'elements' => [],
			];
		}
		if ( 'column' !== $parent_type ) {
			return null;
		}
		return [
			'id'       => ElementorData::generate_id(),
			'elType'   => 'section',
			'isInner'  => true,
			'settings' => [],
			'elements' => [
				[
					'id'       => ElementorData::generate_id(),
					'elType'   => 'column',
					'isInner'  => true,
					'settings' => [ '_column_size' => 100 ],
					'elements' => [],
				],
			],
		];
	}
}
