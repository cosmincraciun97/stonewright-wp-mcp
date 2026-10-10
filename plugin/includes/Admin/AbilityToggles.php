<?php
/**
 * Changes which abilities are switched off. Shared by the admin-post handlers and the REST routes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\LiveAbilities;
use Stonewright\WpMcp\Security\Adapters\SiteAdapter;

/**
 * The single writer of the `stonewright_disabled_abilities` option for the AI Abilities page.
 *
 * Callers check the capability and the nonce; this class only applies the change. The form handlers
 * (`stonewright_toggle_ability`, `stonewright_bulk_abilities`) and the page's REST routes call the same two
 * methods, so a switch behaves the same whether the page reloads or not.
 *
 * @phpstan-type Outcome array{ok: bool, code: string, enabled: bool, names: list<string>, changed: int}
 */
final class AbilityToggles {

	public const OPTION = 'stonewright_disabled_abilities';

	/** Bulk actions the page offers. */
	public const BULK_ACTIONS = [ 'enable_selected', 'disable_selected', 'enable_category', 'disable_category' ];

	/**
	 * Switch one ability on or off.
	 *
	 * @return Outcome
	 */
	public static function set_enabled( string $name, bool $enable ): array {
		if ( '' === $name ) {
			return self::outcome( false, 'missing-name', $enable, [], 0 );
		}

		$disabled     = (array) get_option( self::OPTION, [] );
		$was_disabled = in_array( $name, $disabled, true );

		if ( $enable ) {
			$disabled = array_values( array_filter( $disabled, static fn ( mixed $stored ): bool => $stored !== $name ) );
		} elseif ( ! $was_disabled ) {
			$disabled[] = $name;
		}

		update_option( self::OPTION, $disabled, false );

		$changed = $enable ? $was_disabled : ! $was_disabled;
		if ( $changed ) {
			SiteAdapter::note_admin_write( 'admin', self::OPTION, ( $enable ? 'Turned on ability ' : 'Turned off ability ' ) . $name );
		}

		return self::outcome( true, $enable ? 'enabled' : 'disabled', $enable, [ $name ], $changed ? 1 : 0 );
	}

	/**
	 * Apply a bulk action to the selected abilities or to every ability of one category.
	 *
	 * The stored list is cut to abilities that exist, as it always was. A request that names no action, no
	 * selection or no category is refused and the option is left alone.
	 *
	 * @param array<array-key, mixed> $selected Names from the row checkboxes.
	 * @return Outcome
	 */
	public static function bulk( string $action, string $category, array $selected ): array {
		if ( ! in_array( $action, self::BULK_ACTIONS, true ) ) {
			return self::outcome( false, 'bulk-no-action', true, [], 0 );
		}

		$enable = in_array( $action, [ 'enable_selected', 'enable_category' ], true );
		$all    = AbilityHubCatalog::collect();
		$known  = AbilityHubCatalog::names();

		if ( in_array( $action, [ 'enable_category', 'disable_category' ], true ) ) {
			$targets = self::names_for_category( $all, $category );
			if ( '' === $category || [] === $targets ) {
				return self::outcome( false, 'bulk-no-category', $enable, [], 0 );
			}
		} else {
			$targets = array_values( array_intersect( array_map( 'strval', array_filter( $selected, 'is_scalar' ) ), $known ) );
			if ( [] === $targets ) {
				return self::outcome( false, 'bulk-no-selection', $enable, [], 0 );
			}
		}

		$disabled = array_values( array_intersect( array_map( 'strval', (array) get_option( self::OPTION, [] ) ), $known ) );
		$was      = $disabled;

		if ( $enable ) {
			$disabled = array_values( array_diff( $disabled, $targets ) );
		} else {
			$disabled = array_values( array_unique( array_merge( $disabled, $targets ) ) );
		}

		update_option( self::OPTION, $disabled, false );

		$changed = $enable
			? count( array_intersect( $targets, $was ) )
			: count( array_diff( $targets, $was ) );
		if ( $changed > 0 ) {
			SiteAdapter::note_admin_write( 'admin', self::OPTION, ( $enable ? 'Turned on ' : 'Turned off ' ) . $changed . ' abilities: ' . implode( ', ', array_slice( $targets, 0, 5 ) ) . ( count( $targets ) > 5 ? ', and more' : '' ) );
		}

		return self::outcome( true, $enable ? 'bulk-enabled' : 'bulk-disabled', $enable, $targets, $changed );
	}

	/**
	 * The sentence for an outcome code, shown by the page after a form post and returned by the REST routes.
	 * An unknown code has no sentence.
	 */
	public static function message( string $code, int $changed = 0 ): string {
		return match ( $code ) {
			'enabled'           => __( 'Ability turned on.', 'stonewright' ),
			'disabled'          => __( 'Ability turned off.', 'stonewright' ),
			'bulk-enabled'      => $changed > 0
				? sprintf(
					/* translators: %d: number of abilities */
					_n( '%d ability turned on.', '%d abilities turned on.', $changed, 'stonewright' ),
					$changed
				)
				: __( 'Nothing changed: those abilities were already on.', 'stonewright' ),
			'bulk-disabled'     => $changed > 0
				? sprintf(
					/* translators: %d: number of abilities */
					_n( '%d ability turned off.', '%d abilities turned off.', $changed, 'stonewright' ),
					$changed
				)
				: __( 'Nothing changed: those abilities were already off.', 'stonewright' ),
			'bulk-no-action'    => __( 'Choose a bulk action, then press Apply.', 'stonewright' ),
			'bulk-no-selection' => __( 'Select at least one ability, then press Apply.', 'stonewright' ),
			'bulk-no-category'  => __( 'Choose a category for that action, then press Apply.', 'stonewright' ),
			'missing-name'      => __( 'No ability was named.', 'stonewright' ),
			default             => '',
		};
	}

	/**
	 * What the page counts in its toolbar: abilities that are on and live, and how many are writes and reads.
	 *
	 * @return array{enabled: int, write: int, read: int, total: int}
	 */
	public static function stats(): array {
		$disabled = array_map( 'strval', (array) get_option( self::OPTION, [] ) );
		$enabled  = 0;
		$write    = 0;
		$read     = 0;
		$all      = AbilityHubCatalog::collect();

		foreach ( $all as $ability ) {
			if ( LiveAbilities::counts_as_enabled( $ability, $disabled ) ) {
				++$enabled;
			}
			if ( AbilityKind::READ === AbilityKind::of( (string) $ability['name'] ) ) {
				++$read;
			} else {
				++$write;
			}
		}

		return [
			'enabled' => $enabled,
			'write'   => $write,
			'read'    => $read,
			'total'   => count( $all ),
		];
	}

	/**
	 * @param list<array<string, mixed>> $abilities
	 * @return list<string>
	 */
	private static function names_for_category( array $abilities, string $category ): array {
		$names = [];
		foreach ( $abilities as $ability ) {
			if ( (string) $ability['category'] === $category ) {
				$names[] = (string) $ability['name'];
			}
		}

		return $names;
	}

	/**
	 * @param list<string> $names
	 * @return Outcome
	 */
	private static function outcome( bool $ok, string $code, bool $enabled, array $names, int $changed ): array {
		return [
			'ok'      => $ok,
			'code'    => $code,
			'enabled' => $enabled,
			'names'   => $names,
			'changed' => $changed,
		];
	}
}
