<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Plans mixed V3/V4 work without implicit conversion. */
final class CoexistencePlanner {
	/**
	 * @param array<int, mixed> $tree
	 * @return array<string, mixed>
	 */
	public static function plan( array $tree, string $requested_architecture ): array|\WP_Error {
		if ( ! in_array( $requested_architecture, [ 'preserve', 'v3', 'v4' ], true ) ) {
			return new \WP_Error( 'stonewright_architecture_invalid', 'Choose preserve, v3, or v4.' );
		}
		$inventory = AtomicTreeInspector::inspect( $tree );
		$blocked   = [];
		if ( 'preserve' !== $requested_architecture && $inventory['architecture'] !== $requested_architecture && 'empty' !== $inventory['architecture'] ) {
			$blocked[] = [
				'code'   => 'explicit_migration_required',
				'reason' => 'Architecture conversion requires a separate compatibility/loss report and approval.',
			];
		}
		if ( [] !== $inventory['unknown_atomic'] ) {
			$blocked[] = [ 'code' => 'unknown_atomic_schema', 'reason' => 'Refresh live schemas before mutating unknown Atomic nodes.' ];
		}
		return [
			'current_architecture'   => $inventory['architecture'],
			'requested_architecture' => $requested_architecture,
			'strategy'               => [] === $blocked ? 'preserve_native_subtrees' : 'blocked',
			'implicit_conversion'    => false,
			'blocked'                => $blocked,
			'inventory'              => $inventory,
		];
	}
}
