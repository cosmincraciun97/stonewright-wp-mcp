<?php
/**
 * Turns an option, menu or widget write into a ledger row: the entry the rescue guard holds for the call, and the row it becomes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * RescueGuard keeps one entry per resource that an ability call is about to write: its kind (options, widget,
 * menu or location), the scope that says what the image covers, the image before the write, whether the call
 * created the resource, and the id of the journal entry that watches the same write, when there is one.
 * Nothing is stored while the call runs. When it ends, the guard reads the image the write left (capture())
 * and calls record(), which writes the row with both images and the status. A call that changed nothing has
 * no row.
 *
 * The entry holds an image in memory, so an image is never written anywhere but the ledger, which refuses or
 * masks what must not be kept. Every method here is called inside the guard's own try block.
 *
 * @phpstan-type Entry array{kind:string,scope:array<string,mixed>,before:array<string,mixed>|null,created:bool,journal:string}
 */
final class FamilyRecorder {

	/**
	 * The entries of an ability call that are known from its name and arguments, with their images as they are now.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, Entry>
	 */
	public static function begin( string $ability, array $args ): array {
		$out = [];
		$options = OptionsAdapter::scope( $ability, $args );
		if ( null !== $options ) {
			$out[ 'options:' . $ability ] = self::entry( 'options', $options, OptionsAdapter::image( $options ), false, '' );
		}
		$widget = WidgetAdapter::scope( $ability, $args );
		if ( null !== $widget ) {
			$out[ 'widget:' . $widget['sidebar'] ] = self::entry( 'widget', $widget, WidgetAdapter::image( $widget['sidebar'], $widget['widgets'] ), false, '' );
		}
		return $out;
	}

	/**
	 * The entry of a write that takes an option restore point: the keys it names, cut to what the ability may write.
	 *
	 * @param list<string> $option_keys
	 * @param list<string> $theme_mod_keys
	 * @return Entry|null
	 */
	public static function options_entry( string $ability, array $option_keys, array $theme_mod_keys, string $journal ): ?array {
		$scope = OptionsAdapter::snapshot_scope( $ability, $option_keys, $theme_mod_keys );
		return null === $scope ? null : self::entry( 'options', $scope, OptionsAdapter::image( $scope ), false, $journal );
	}

	/**
	 * The entry of a menu that is about to change.
	 *
	 * @return Entry|null Null when the menu does not exist.
	 */
	public static function menu_entry( int $menu_id ): ?array {
		$image = MenuAdapter::image( $menu_id );
		return null === $image ? null : self::entry( 'menu', [ 'menu_id' => $menu_id ], $image, false, '' );
	}

	/**
	 * The entry of a menu that the call has just created.
	 *
	 * @return Entry
	 */
	public static function created_menu_entry( int $menu_id ): array {
		return self::entry( 'menu', [ 'menu_id' => $menu_id ], null, true, '' );
	}

	/**
	 * The entry of a theme location that is about to be assigned.
	 *
	 * @return Entry
	 */
	public static function location_entry( string $location ): array {
		return self::entry( 'location', [ 'location' => $location ], MenuAdapter::location_image( $location ), false, '' );
	}

	/**
	 * The image of the resource of an entry as the write left it, or null when the resource is gone.
	 *
	 * @param Entry $entry
	 * @return array<string, mixed>|null
	 */
	public static function capture( array $entry ): ?array {
		$scope = $entry['scope'];
		return match ( $entry['kind'] ) {
			'options'  => OptionsAdapter::image( $scope ),
			'widget'   => WidgetAdapter::image( (string) $scope['sidebar'], array_map( 'strval', (array) $scope['widgets'] ) ),
			'menu'     => MenuAdapter::image( (int) $scope['menu_id'] ),
			'location' => MenuAdapter::location_image( (string) $scope['location'] ),
			default    => null,
		};
	}

	/**
	 * Whether the write changed what the entry covers.
	 *
	 * @param Entry                     $entry
	 * @param array<string, mixed>|null $after
	 */
	public static function changed( array $entry, ?array $after ): bool {
		if ( $entry['created'] ) {
			return true;
		}
		$before = $entry['before'];
		if ( null === $before || null === $after ) {
			return $before !== $after;
		}
		if ( 'options' === $entry['kind'] ) {
			return OptionsAdapter::changed( $before, $after );
		}
		return ! AdapterSupport::same( $before, $after );
	}

	/**
	 * Write the row of an entry and settle it. Returns the change id, or '' when nothing was recorded.
	 *
	 * @param Entry                     $entry
	 * @param array<string, mixed>|null $after
	 */
	public static function record( string $ability, array $entry, ?array $after, string $status ): string {
		$spec = self::spec( $ability, $entry, $after );
		if ( '' !== $entry['journal'] && ChangeLedger::is_valid_id( $entry['journal'] ) ) {
			$spec['change_id'] = $entry['journal'];
		}
		$id = AdapterSupport::record( $spec );
		if ( '' !== $id ) {
			AdapterSupport::settle( $id, $after, $status );
		}
		return $id;
	}

	/**
	 * @param Entry                     $entry
	 * @param array<string, mixed>|null $after
	 * @return array<string, mixed>
	 */
	private static function spec( string $ability, array $entry, ?array $after ): array {
		$before = $entry['before'];
		$scope  = $entry['scope'];
		switch ( $entry['kind'] ) {
			case 'options':
				$spec = [
					'ability'       => $ability,
					'family'        => 'option',
					'resource_type' => 'option',
					'resource_id'   => OptionsAdapter::resource_id( (array) $before, $after ),
					'before'        => $before,
					'summary'       => OptionsAdapter::summary( (array) $before, $after ),
				];
				return self::with_veto( $spec, $before );
			case 'widget':
				$spec = [
					'ability'       => $ability,
					'family'        => 'widget',
					'resource_type' => 'sidebar',
					'resource_id'   => (string) $scope['sidebar'],
					'before'        => $before,
					'summary'       => WidgetAdapter::summary( (array) $before ),
				];
				return self::with_veto( $spec, $before );
			case 'location':
				return [
					'ability'       => $ability,
					'family'        => 'menu',
					'resource_type' => 'menu_location',
					'resource_id'   => (string) $scope['location'],
					'before'        => $before,
					'summary'       => 'Menu location: ' . (string) $scope['location'],
				];
			default:
				$image = $entry['created'] ? $after : $before;
				$spec  = [
					'ability'       => $ability,
					'family'        => 'menu',
					'resource_type' => 'menu',
					'resource_id'   => (string) $scope['menu_id'],
					'before'        => $before,
					'summary'       => ( $entry['created'] ? MenuAdapter::CREATED_SUMMARY : '' ) . ( null === $image ? 'menu' : MenuAdapter::summary( $image ) ),
				];
				if ( $entry['created'] ) {
					$spec['restorable'] = true;
				}
				return $spec;
		}
	}

	/**
	 * A row whose image had a secret name left out cannot be restored as a whole.
	 *
	 * @param array<string, mixed>      $spec
	 * @param array<string, mixed>|null $before
	 * @return array<string, mixed>
	 */
	private static function with_veto( array $spec, ?array $before ): array {
		if ( null !== $before && [] !== (array) ( $before['vetoed'] ?? [] ) ) {
			$spec['restorable']        = false;
			$spec['restorable_reason'] = OptionsAdapter::SKIPPED_REASON;
		}
		return $spec;
	}

	/**
	 * @param array<string, mixed>      $scope
	 * @param array<string, mixed>|null $before
	 * @return Entry
	 */
	private static function entry( string $kind, array $scope, ?array $before, bool $created, string $journal ): array {
		return [
			'kind'    => $kind,
			'scope'   => $scope,
			'before'  => $before,
			'created' => $created,
			'journal' => $journal,
		];
	}
}
