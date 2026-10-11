<?php
/**
 * The options family in the change ledger: the image of a set of options and theme mods, and the restore.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Abilities\Settings\SettingsGet;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * Site settings, the front page, the custom instructions, the content model (custom post types, taxonomies
 * and ACF field groups), the tool profile, theme chrome and the brand kit all live in options and theme mods.
 * This adapter describes the ones an ability writes to the ledger in one shape, an image, and writes an image
 * back with the functions the abilities use (update_option, delete_option, set_theme_mod, remove_theme_mod).
 *
 * Two lists decide what an image may hold. Each ability has an allowlist of the options, the entries of
 * shared array options (the slug of a custom post type in the option of the content model plugin) and the
 * theme mods it may write; a name outside it is neither imaged nor restored. Then a veto removes every name
 * that ChangeImage treats as a secret, or would mask as a key. A vetoed name is never read: its value is not
 * stored, and not even a hash of it. The image lists the name in "vetoed", and the row is recorded as not
 * restorable with the reason SKIPPED_REASON.
 *
 * An image holds:
 *
 *   v, kind "options"
 *   options    name => { exists, value }
 *   entries    option => entry => { exists, value }
 *   theme_mods name => { exists, value }
 *   vetoed     names that were left out
 *
 * restore() checks no permission, token or newer change: the code that calls it does.
 */
final class OptionsAdapter {

	public const IMAGE_VERSION = 1;

	/** Reason of a row whose image had a secret name left out. */
	public const SKIPPED_REASON = 'secret_option_skipped';

	/** Most names a resource id lists. */
	private const ID_NAMES = 8;

	/**
	 * What each ability may write. options and entries name the options; entries are the options that hold an
	 * array of records the ability adds one record to. theme_mods is a list, or '*' for any theme mod of a
	 * valid name. autoload is what the ability passes to update_option, or null when it passes nothing.
	 *
	 * @var array<string, array{options:list<string>,entries:list<string>,theme_mods:list<string>|string,autoload:bool|null}>
	 */
	private const STATIC_RULES = [
		'stonewright/site-set-front-page'     => [ 'options' => [ 'show_on_front', 'page_on_front' ], 'entries' => [], 'theme_mods' => [], 'autoload' => null ],
		'stonewright/system-instructions-set' => [ 'options' => [ 'stonewright_custom_instructions', 'stonewright_custom_instructions_enabled' ], 'entries' => [], 'theme_mods' => [], 'autoload' => null ],
		'stonewright/cpt-register'            => [ 'options' => [], 'entries' => [ 'cptui_post_types' ], 'theme_mods' => [], 'autoload' => false ],
		'stonewright/taxonomy-register'       => [ 'options' => [], 'entries' => [ 'cptui_taxonomies' ], 'theme_mods' => [], 'autoload' => false ],
		'stonewright/acf-field-group-save'    => [ 'options' => [], 'entries' => [ 'stonewright_acf_field_groups' ], 'theme_mods' => [], 'autoload' => false ],
		'stonewright/tool-profile'            => [
			'options'    => [ 'stonewright_last_tool_profile', 'stonewright_tools_changed_at', 'stonewright_essential_extra_abilities', 'stonewright_mcp_surface', 'stonewright_essential_tools_mode' ],
			'entries'    => [],
			'theme_mods' => [],
			'autoload'   => false,
		],
		'stonewright/theme-chrome-update'     => [ 'options' => [ 'generate_settings' ], 'entries' => [], 'theme_mods' => '*', 'autoload' => false ],
	];

	/** A name of an option or a theme mod that the adapter reads: plain characters, bounded. */
	private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_\-.]{0,190}$/D';

	// -----------------------------------------------------------------------
	// Scope: what an ability call may image.
	// -----------------------------------------------------------------------

	/**
	 * The scope of an ability call that is known from its arguments, or null when the ability is not in this
	 * family, or the call names nothing the ability may write.
	 *
	 * @param array<string, mixed> $args
	 * @return array{options:list<string>,entries:array<string,list<string>>,theme_mods:list<string>,vetoed:list<string>}|null
	 */
	public static function scope( string $ability, array $args = [] ): ?array {
		$rules = self::rules( $ability );
		if ( null === $rules ) {
			return null;
		}
		$options = [];
		$entries = [];
		switch ( $ability ) {
			case 'stonewright/settings-update':
				foreach ( is_array( $args['settings'] ?? null ) ? array_keys( $args['settings'] ) : [] as $key ) {
					$options[] = (string) $key;
				}
				break;
			case 'stonewright/cpt-register':
				$entries['cptui_post_types'] = [ sanitize_key( (string) ( $args['slug'] ?? '' ) ) ];
				break;
			case 'stonewright/taxonomy-register':
				$entries['cptui_taxonomies'] = [ sanitize_key( (string) ( $args['slug'] ?? '' ) ) ];
				break;
			case 'stonewright/acf-field-group-save':
				$group                                    = is_array( $args['group'] ?? null ) ? $args['group'] : [];
				$entries['stonewright_acf_field_groups'] = [ (string) ( $group['key'] ?? '' ) ];
				break;
			case 'stonewright/theme-chrome-update':
			case 'stonewright/brand-kit-apply':
				// These write through a restore point taken by Backup::snapshot_options(), which names the keys.
				return null;
			default:
				$options = $rules['options'];
				break;
		}
		return self::build( $rules, $options, $entries, [] );
	}

	/**
	 * The scope of a write that names its keys when it takes an option restore point.
	 *
	 * @param list<string> $option_keys
	 * @param list<string> $theme_mod_keys
	 * @return array{options:list<string>,entries:array<string,list<string>>,theme_mods:list<string>,vetoed:list<string>}|null
	 */
	public static function snapshot_scope( string $ability, array $option_keys, array $theme_mod_keys = [] ): ?array {
		$rules = self::rules( $ability );
		if ( null === $rules ) {
			return null;
		}
		return self::build( $rules, array_values( array_filter( $option_keys, 'is_string' ) ), [], array_values( array_filter( $theme_mod_keys, 'is_string' ) ) );
	}

	/**
	 * Whether a name is on the secret list, or would be masked as a key of an image. Its value is never read.
	 */
	public static function is_vetoed( string $name ): bool {
		if ( '' !== ChangeImage::refusal( 'option', $name ) ) {
			return true;
		}
		return ChangeImage::prepare( [ $name => 0 ], 'option_map', 'names' )['masked'];
	}

	// -----------------------------------------------------------------------
	// Image.
	// -----------------------------------------------------------------------

	/**
	 * The image of a scope as it is now.
	 *
	 * @param array{options:list<string>,entries:array<string,list<string>>,theme_mods:list<string>,vetoed:list<string>} $scope
	 * @return array<string, mixed>
	 */
	public static function image( array $scope ): array {
		$options = [];
		foreach ( $scope['options'] as $name ) {
			$options[ $name ] = self::read_option( $name );
		}
		$entries = [];
		foreach ( $scope['entries'] as $option => $names ) {
			$stored = self::read_option( $option );
			$array  = $stored['exists'] && is_array( $stored['value'] ) ? $stored['value'] : [];
			foreach ( $names as $name ) {
				$has                         = array_key_exists( $name, $array );
				$entries[ $option ][ $name ] = [ 'exists' => $has, 'value' => $has ? $array[ $name ] : null ];
			}
		}
		$mods = [];
		foreach ( $scope['theme_mods'] as $name ) {
			$mods[ $name ] = self::read_theme_mod( $name );
		}
		ksort( $options, SORT_STRING );
		ksort( $entries, SORT_STRING );
		ksort( $mods, SORT_STRING );
		return [
			'v'          => self::IMAGE_VERSION,
			'kind'       => 'options',
			'options'    => $options,
			'entries'    => $entries,
			'theme_mods' => $mods,
			'vetoed'     => $scope['vetoed'],
		];
	}

	/**
	 * The scope that an image describes, to read the live state with.
	 *
	 * @param array<string, mixed> $image
	 * @return array{options:list<string>,entries:array<string,list<string>>,theme_mods:list<string>,vetoed:list<string>}
	 */
	public static function scope_of( array $image ): array {
		$entries = [];
		foreach ( (array) ( $image['entries'] ?? [] ) as $option => $map ) {
			$entries[ (string) $option ] = array_map( 'strval', array_keys( (array) $map ) );
		}
		return [
			'options'    => array_map( 'strval', array_keys( (array) ( $image['options'] ?? [] ) ) ),
			'entries'    => $entries,
			'theme_mods' => array_map( 'strval', array_keys( (array) ( $image['theme_mods'] ?? [] ) ) ),
			'vetoed'     => array_values( array_filter( (array) ( $image['vetoed'] ?? [] ), 'is_string' ) ),
		];
	}

	/**
	 * The names whose value differs between two images, as a resource id for the ledger row: option names,
	 * "option:entry" for an entry, and "theme_mod:name". At most ID_NAMES are listed.
	 *
	 * @param array<string, mixed>      $before
	 * @param array<string, mixed>|null $after
	 */
	public static function resource_id( array $before, ?array $after ): string {
		$keys = self::differing( $before, $after );
		if ( [] === $keys ) {
			$keys = array_keys( self::flatten( $before ) );
		}
		$names = array_map( [ self::class, 'id_of' ], $keys );
		if ( [] === $names ) {
			// Only secret names were involved. The row is named after them, so the ledger refuses the image.
			$names = array_values( array_filter( (array) ( $before['vetoed'] ?? [] ), 'is_string' ) );
		}
		sort( $names, SORT_STRING );
		$total = count( $names );
		$shown = array_slice( $names, 0, self::ID_NAMES );
		return implode( ',', $shown ) . ( $total > self::ID_NAMES ? ',+' . ( $total - self::ID_NAMES ) : '' );
	}

	/**
	 * A short plain summary of a change, without any value.
	 *
	 * @param array<string, mixed>      $before
	 * @param array<string, mixed>|null $after
	 */
	public static function summary( array $before, ?array $after ): string {
		$names = array_map( [ self::class, 'id_of' ], self::differing( $before, $after ) );
		sort( $names, SORT_STRING );
		$text = [] === $names ? 'Options' : 'Options: ' . implode( ', ', array_slice( $names, 0, 4 ) ) . ( count( $names ) > 4 ? ' and ' . ( count( $names ) - 4 ) . ' more' : '' );
		$left = count( (array) ( $before['vetoed'] ?? [] ) );
		if ( $left > 0 ) {
			$text .= ' (' . $left . ' secret not imaged)';
		}
		return $text;
	}

	/**
	 * Whether the two images differ in any name they hold.
	 *
	 * @param array<string, mixed>      $before
	 * @param array<string, mixed>|null $after
	 */
	public static function changed( array $before, ?array $after ): bool {
		// A secret name is never read, so a write to it cannot be compared: a call that named one is recorded.
		return null === $after || [] !== (array) ( $before['vetoed'] ?? [] ) || [] !== self::differing( $before, $after );
	}

	// -----------------------------------------------------------------------
	// Restore.
	// -----------------------------------------------------------------------

	/**
	 * Write an image back, then read the options to confirm.
	 *
	 * Only what the ability may write is written. A name outside its allowlist, and a vetoed name, is skipped
	 * and reported; an image that was masked, or is not an options image, is refused and nothing is written.
	 *
	 * @param array<string, mixed> $image
	 * @return array{ok:bool,skipped:list<string>,differences:list<string>}|\WP_Error ok is true only when every name that was written now equals the image.
	 */
	public static function restore( array $image, string $ability ): array|\WP_Error {
		if ( self::IMAGE_VERSION !== ( $image['v'] ?? null ) || 'options' !== ( $image['kind'] ?? '' ) || ! is_array( $image['options'] ?? null ) || ! is_array( $image['entries'] ?? null ) || ! is_array( $image['theme_mods'] ?? null ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The image is not an options image this version can restore.', 'stonewright' ) );
		}
		if ( AdapterSupport::has_mask( [ $image['options'], $image['entries'], $image['theme_mods'] ] ) ) {
			return new \WP_Error( 'stonewright_image_masked', __( 'The image had credentials masked out of it, so it cannot be written back.', 'stonewright' ) );
		}
		$rules = self::rules( $ability );
		if ( null === $rules ) {
			return new \WP_Error( 'stonewright_option_not_allowed', __( 'This ability does not write options that the ledger restores.', 'stonewright' ) );
		}

		$skipped  = [];
		$wanted   = [ 'options' => [], 'entries' => [], 'theme_mods' => [], 'vetoed' => [] ];
		$autoload = $rules['autoload'];
		foreach ( (array) ( $image['vetoed'] ?? [] ) as $name ) {
			if ( is_string( $name ) ) {
				$skipped[] = 'vetoed.' . $name;
			}
		}

		foreach ( $image['options'] as $name => $payload ) {
			$name = (string) $name;
			if ( ! is_array( $payload ) || ! self::may_write_option( $rules, $name ) ) {
				$skipped[] = 'option.' . $name;
				continue;
			}
			if ( ! empty( $payload['exists'] ) && 'stonewright_mcp_surface' === $name && is_string( $payload['value'] ?? null ) ) {
				// The registry also sets the tools mode and raises the surface revision, which only goes up.
				AbilityRegistry::set_mcp_surface( $payload['value'] );
			} elseif ( ! empty( $payload['exists'] ) ) {
				self::write_option( $name, $payload['value'] ?? null, $autoload );
			} elseif ( self::read_option( $name )['exists'] ) {
				delete_option( $name );
			}
			$wanted['options'][] = $name;
		}

		foreach ( $image['entries'] as $option => $map ) {
			$option = (string) $option;
			if ( ! is_array( $map ) || ! in_array( $option, $rules['entries'], true ) ) {
				$skipped[] = 'option.' . $option;
				continue;
			}
			$stored = self::read_option( $option );
			$array  = $stored['exists'] && is_array( $stored['value'] ) ? $stored['value'] : [];
			$done   = [];
			foreach ( $map as $entry => $payload ) {
				$entry = (string) $entry;
				if ( ! is_array( $payload ) || 1 !== preg_match( self::NAME_PATTERN, $entry ) || self::is_vetoed( $entry ) ) {
					$skipped[] = 'entry.' . $option . '.' . $entry;
					continue;
				}
				if ( ! empty( $payload['exists'] ) ) {
					$array[ $entry ] = $payload['value'] ?? null;
				} else {
					unset( $array[ $entry ] );
				}
				$done[] = $entry;
			}
			if ( [] !== $done ) {
				self::write_option( $option, $array, $autoload );
				$wanted['entries'][ $option ] = $done;
			}
		}

		foreach ( $image['theme_mods'] as $name => $payload ) {
			$name = (string) $name;
			if ( ! is_array( $payload ) || ! self::may_write_theme_mod( $rules, $name ) ) {
				$skipped[] = 'theme_mod.' . $name;
				continue;
			}
			if ( ! empty( $payload['exists'] ) ) {
				set_theme_mod( $name, $payload['value'] ?? null );
			} else {
				remove_theme_mod( $name );
			}
			$wanted['theme_mods'][] = $name;
		}

		$differences = array_map(
			[ self::class, 'label_of' ],
			self::differing( self::narrow( $image, $wanted ), self::image( $wanted ) )
		);
		return [
			'ok'          => [] === $differences,
			'skipped'     => $skipped,
			'differences' => $differences,
		];
	}

	/**
	 * Undo one ledger row of this family: write its before image back.
	 *
	 * @return array{ok:bool,skipped:list<string>,differences:list<string>}|\WP_Error
	 */
	public static function undo( string $change_id ): array|\WP_Error {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return new \WP_Error( 'stonewright_change_not_found', __( 'The change is not recorded.', 'stonewright' ) );
		}
		if ( 'option' !== $row['family'] ) {
			return new \WP_Error( 'stonewright_change_not_options', __( 'The change is not a change of options.', 'stonewright' ) );
		}
		if ( ! $row['restorable'] ) {
			return new \WP_Error( 'stonewright_change_not_restorable', __( 'The change cannot be undone from the ledger.', 'stonewright' ), [ 'reason' => $row['restorable_reason'] ] );
		}
		$image = ChangeLedger::read_image( $change_id, 'before' );
		if ( $image instanceof \WP_Error ) {
			return $image;
		}
		if ( ! is_array( $image ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The stored image is not an options image.', 'stonewright' ) );
		}
		return self::restore( $image, (string) $row['ability'] );
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * @return array{options:list<string>,entries:list<string>,theme_mods:list<string>|string,autoload:bool|null}|null
	 */
	private static function rules( string $ability ): ?array {
		if ( 'stonewright/settings-update' === $ability ) {
			return [ 'options' => SettingsGet::ALLOWLIST, 'entries' => [], 'theme_mods' => [], 'autoload' => null ];
		}
		if ( 'stonewright/brand-kit-apply' === $ability ) {
			return [ 'options' => [ BrandKit::OPTION_ACTIVE ], 'entries' => [], 'theme_mods' => BrandKit::managed_theme_mod_keys(), 'autoload' => false ];
		}
		return self::STATIC_RULES[ $ability ] ?? null;
	}

	/**
	 * Cut what a call names to the allowlist, then to the names that pass the veto.
	 *
	 * @param array{options:list<string>,entries:list<string>,theme_mods:list<string>|string,autoload:bool|null} $rules
	 * @param list<string>                                                                                      $options
	 * @param array<string, list<string>>                                                                       $entries
	 * @param list<string>                                                                                      $theme_mods
	 * @return array{options:list<string>,entries:array<string,list<string>>,theme_mods:list<string>,vetoed:list<string>}|null
	 */
	private static function build( array $rules, array $options, array $entries, array $theme_mods ): ?array {
		$out    = [ 'options' => [], 'entries' => [], 'theme_mods' => [], 'vetoed' => [] ];
		$vetoed = [];
		foreach ( array_unique( $options ) as $name ) {
			if ( ! self::may_write_option( $rules, $name ) ) {
				continue;
			}
			if ( self::is_vetoed( $name ) ) {
				$vetoed[] = $name;
				continue;
			}
			$out['options'][] = $name;
		}
		foreach ( $entries as $option => $names ) {
			if ( ! in_array( $option, $rules['entries'], true ) ) {
				continue;
			}
			foreach ( array_unique( $names ) as $name ) {
				if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
					continue;
				}
				if ( self::is_vetoed( $name ) ) {
					$vetoed[] = $option . ':' . $name;
					continue;
				}
				$out['entries'][ $option ][] = $name;
			}
		}
		foreach ( array_unique( $theme_mods ) as $name ) {
			if ( ! self::may_write_theme_mod( $rules, $name ) ) {
				continue;
			}
			if ( self::is_vetoed( $name ) ) {
				$vetoed[] = $name;
				continue;
			}
			$out['theme_mods'][] = $name;
		}
		sort( $out['options'], SORT_STRING );
		sort( $out['theme_mods'], SORT_STRING );
		$vetoed        = array_values( array_unique( $vetoed ) );
		sort( $vetoed, SORT_STRING );
		$out['vetoed'] = $vetoed;
		if ( [] === $out['options'] && [] === $out['entries'] && [] === $out['theme_mods'] && [] === $out['vetoed'] ) {
			return null;
		}
		return $out;
	}

	/**
	 * @param array{options:list<string>,entries:list<string>,theme_mods:list<string>|string,autoload:bool|null} $rules
	 */
	private static function may_write_option( array $rules, string $name ): bool {
		return 1 === preg_match( self::NAME_PATTERN, $name ) && in_array( $name, $rules['options'], true );
	}

	/**
	 * @param array{options:list<string>,entries:list<string>,theme_mods:list<string>|string,autoload:bool|null} $rules
	 */
	private static function may_write_theme_mod( array $rules, string $name ): bool {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			return false;
		}
		return '*' === $rules['theme_mods'] || ( is_array( $rules['theme_mods'] ) && in_array( $name, $rules['theme_mods'], true ) );
	}

	/**
	 * @return array{exists:bool,value:mixed}
	 */
	private static function read_option( string $name ): array {
		$sentinel = new \stdClass();
		$value    = get_option( $name, $sentinel );
		$exists   = $value !== $sentinel;
		return [ 'exists' => $exists, 'value' => $exists ? $value : null ];
	}

	/**
	 * @return array{exists:bool,value:mixed}
	 */
	private static function read_theme_mod( string $name ): array {
		$sentinel = new \stdClass();
		$value    = function_exists( 'get_theme_mod' ) ? get_theme_mod( $name, $sentinel ) : $sentinel;
		$exists   = $value !== $sentinel;
		return [ 'exists' => $exists, 'value' => $exists ? $value : null ];
	}

	private static function write_option( string $name, mixed $value, ?bool $autoload ): void {
		if ( null === $autoload ) {
			update_option( $name, $value );
			return;
		}
		update_option( $name, $value, $autoload );
	}

	/**
	 * The part of an image that a restore wrote.
	 *
	 * @param array<string, mixed>                                                                                          $image
	 * @param array{options:list<string>,entries:array<string,list<string>>,theme_mods:list<string>,vetoed:list<string>} $wanted
	 * @return array<string, mixed>
	 */
	private static function narrow( array $image, array $wanted ): array {
		$out = [ 'options' => [], 'entries' => [], 'theme_mods' => [] ];
		foreach ( $wanted['options'] as $name ) {
			$out['options'][ $name ] = $image['options'][ $name ];
		}
		foreach ( $wanted['entries'] as $option => $names ) {
			foreach ( $names as $name ) {
				$out['entries'][ $option ][ $name ] = $image['entries'][ $option ][ $name ];
			}
		}
		foreach ( $wanted['theme_mods'] as $name ) {
			$out['theme_mods'][ $name ] = $image['theme_mods'][ $name ];
		}
		return $out;
	}

	/**
	 * Keys of the payloads that differ between two images, or that only one holds.
	 *
	 * @param array<string, mixed>      $a
	 * @param array<string, mixed>|null $b
	 * @return list<string>
	 */
	private static function differing( array $a, ?array $b ): array {
		$left  = self::flatten( $a );
		$right = null === $b ? [] : self::flatten( $b );
		$out   = [];
		foreach ( $left as $key => $payload ) {
			if ( ! array_key_exists( $key, $right ) || ! AdapterSupport::same( $payload, $right[ $key ] ) ) {
				$out[] = $key;
			}
		}
		foreach ( array_keys( $right ) as $key ) {
			if ( ! array_key_exists( $key, $left ) ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Every payload of an image under one key: "option|name", "entry|option|entry" or "mod|name".
	 *
	 * @param array<string, mixed> $image
	 * @return array<string, mixed>
	 */
	private static function flatten( array $image ): array {
		$out = [];
		foreach ( (array) ( $image['options'] ?? [] ) as $name => $payload ) {
			$out[ 'option|' . $name ] = $payload;
		}
		foreach ( (array) ( $image['entries'] ?? [] ) as $option => $map ) {
			foreach ( (array) $map as $entry => $payload ) {
				$out[ 'entry|' . $option . '|' . $entry ] = $payload;
			}
		}
		foreach ( (array) ( $image['theme_mods'] ?? [] ) as $name => $payload ) {
			$out[ 'mod|' . $name ] = $payload;
		}
		return $out;
	}

	/** The name a flatten() key has in a resource id. */
	private static function id_of( string $key ): string {
		$parts = explode( '|', $key );
		return match ( $parts[0] ) {
			'entry' => ( $parts[1] ?? '' ) . ':' . ( $parts[2] ?? '' ),
			'mod'   => 'theme_mod:' . ( $parts[1] ?? '' ),
			default => $parts[1] ?? '',
		};
	}

	/** The name a flatten() key has in the list of differences a restore reports. */
	private static function label_of( string $key ): string {
		$parts = explode( '|', $key );
		return match ( $parts[0] ) {
			'entry' => 'entry.' . ( $parts[1] ?? '' ) . '.' . ( $parts[2] ?? '' ),
			'mod'   => 'theme_mod.' . ( $parts[1] ?? '' ),
			default => 'option.' . ( $parts[1] ?? '' ),
		};
	}
}
