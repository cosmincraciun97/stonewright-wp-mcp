<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/**
 * Typed read/write helpers for Blocksy, Kadence Theme, and GeneratePress chrome.
 *
 * Only keys that already exist in the live theme API are readable or writable.
 * Page body composition stays on the Gutenberg finalizer.
 */
final class ThemeChrome {

	public const THEMES = [ 'blocksy', 'kadence', 'generatepress' ];

	/**
	 * @return array{
	 *   theme:string,
	 *   active:bool,
	 *   version:string,
	 *   status:string,
	 *   colors:array<string, mixed>,
	 *   typography:array<string, mixed>,
	 *   header:array<string, mixed>,
	 *   footer:array<string, mixed>,
	 *   writable:list<array{bucket:string,key:string,storage:string,option?:string}>
	 * }
	 */
	public static function read( string $theme ): array {
		$row    = self::catalog_row( $theme );
		$active = '' !== (string) ( $row['version'] ?? '' );
		$empty  = [
			'theme'      => $theme,
			'active'     => false,
			'version'    => '',
			'status'     => (string) ( $row['status'] ?? 'unavailable' ),
			'colors'     => [],
			'typography' => [],
			'header'     => [],
			'footer'     => [],
			'writable'   => [],
		];
		if ( ! $active ) {
			return $empty;
		}

		$buckets = self::live_buckets( $theme );
		return [
			'theme'      => $theme,
			'active'     => true,
			'version'    => (string) ( $row['version'] ?? '' ),
			'status'     => (string) ( $row['status'] ?? 'supported' ),
			'colors'     => $buckets['colors'],
			'typography' => $buckets['typography'],
			'header'     => $buckets['header'],
			'footer'     => $buckets['footer'],
			'writable'   => $buckets['writable'],
		];
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function plan( string $theme, array $patch ): array|\WP_Error {
		$current = self::read( $theme );
		if ( ! $current['active'] ) {
			return new \WP_Error(
				'stonewright_theme_inactive',
				__( 'The requested theme chrome adapter is not active.', 'stonewright' ),
				[ 'status' => 400, 'theme' => $theme ]
			);
		}

		$writable = [];
		foreach ( $current['writable'] as $item ) {
			$writable[ $item['bucket'] . "\0" . $item['key'] ] = $item;
		}

		$planned = [];
		foreach ( [ 'colors', 'typography', 'header', 'footer' ] as $bucket ) {
			$values = $patch[ $bucket ] ?? null;
			if ( ! is_array( $values ) || [] === $values ) {
				continue;
			}
			foreach ( $values as $key => $value ) {
				$key = (string) $key;
				$item = $writable[ $bucket . "\0" . $key ] ?? null;
				if ( null === $item ) {
					return new \WP_Error(
						'stonewright_unknown_chrome_key',
						sprintf(
							/* translators: 1: bucket name, 2: setting key */
							__( 'Theme chrome key "%1$s.%2$s" is not in the live theme API.', 'stonewright' ),
							$bucket,
							$key
						),
						[ 'status' => 400, 'bucket' => $bucket, 'key' => $key ]
					);
				}
				$planned[] = [
					'bucket'  => $bucket,
					'key'     => $key,
					'storage' => $item['storage'],
					'option'  => $item['option'] ?? '',
					'before'  => $current[ $bucket ][ $key ] ?? null,
					'after'   => $value,
				];
			}
		}

		return [
			'current' => $current,
			'changes' => $planned,
		];
	}

	/**
	 * @param list<array{bucket:string,key:string,storage:string,option:string,before:mixed,after:mixed}> $changes
	 * @return array{option_keys:list<string>,theme_mod_keys:list<string>}
	 */
	public static function snapshot_targets( array $changes ): array {
		$options = [];
		$mods    = [];
		foreach ( $changes as $change ) {
			if ( 'option' === $change['storage'] ) {
				$option = (string) $change['option'];
				if ( '' !== $option ) {
					$options[] = $option;
				}
			} elseif ( 'theme_mod' === $change['storage'] ) {
				$mods[] = $change['key'];
			}
		}
		return [
			'option_keys'     => array_values( array_unique( $options ) ),
			'theme_mod_keys'  => array_values( array_unique( $mods ) ),
		];
	}

	/**
	 * @param list<array{bucket:string,key:string,storage:string,option:string,before:mixed,after:mixed}> $changes
	 */
	public static function apply( array $changes ): void {
		foreach ( $changes as $change ) {
			if ( 'option' === $change['storage'] ) {
				$option = (string) $change['option'];
				$stored = get_option( $option, [] );
				$stored = is_array( $stored ) ? $stored : [];
				$stored[ $change['key'] ] = $change['after'];
				update_option( $option, $stored, false );
				continue;
			}
			if ( 'theme_mod' === $change['storage'] && function_exists( 'set_theme_mod' ) ) {
				set_theme_mod( $change['key'], $change['after'] );
			}
		}
	}

	/**
	 * @return array{id:string,version:string,status:string}|array{}
	 */
	private static function catalog_row( string $theme ): array {
		foreach ( IntegrationCatalog::inspect() as $row ) {
			if ( $theme === (string) ( $row['id'] ?? '' ) ) {
				return $row;
			}
		}
		return [];
	}

	/**
	 * @return array{
	 *   colors:array<string, mixed>,
	 *   typography:array<string, mixed>,
	 *   header:array<string, mixed>,
	 *   footer:array<string, mixed>,
	 *   writable:list<array{bucket:string,key:string,storage:string,option?:string}>
	 * }
	 */
	private static function live_buckets( string $theme ): array {
		$buckets = [
			'colors'     => [],
			'typography' => [],
			'header'     => [],
			'footer'     => [],
			'writable'   => [],
		];

		foreach ( self::live_entries( $theme ) as $entry ) {
			$bucket = self::bucket_for_key( $entry['key'] );
			if ( null === $bucket ) {
				continue;
			}
			$buckets[ $bucket ][ $entry['key'] ] = $entry['value'];
			$writable = [
				'bucket'  => $bucket,
				'key'     => $entry['key'],
				'storage' => $entry['storage'],
			];
			if ( 'option' === $entry['storage'] ) {
				$writable['option'] = $entry['option'];
			}
			$buckets['writable'][] = $writable;
		}

		return $buckets;
	}

	/**
	 * @return list<array{key:string,value:mixed,storage:string,option:string}>
	 */
	private static function live_entries( string $theme ): array {
		if ( 'generatepress' === $theme ) {
			return self::generatepress_entries();
		}

		$mods = [];
		if ( function_exists( 'get_theme_mods' ) ) {
			$mods = (array) get_theme_mods();
		} elseif ( isset( $GLOBALS['stonewright_test_theme_mods'] ) && is_array( $GLOBALS['stonewright_test_theme_mods'] ) ) {
			$mods = $GLOBALS['stonewright_test_theme_mods'];
		}

		$entries = [];
		foreach ( $mods as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || str_starts_with( $key, 'nav_menu' ) ) {
				continue;
			}
			$live = $value;
			if ( 'blocksy' === $theme && function_exists( 'blocksy_get_theme_mod' ) ) {
				$live = blocksy_get_theme_mod( $key, $value );
			}
			$entries[] = [
				'key'     => $key,
				'value'   => $live,
				'storage' => 'theme_mod',
				'option'  => '',
			];
		}

		return $entries;
	}

	/**
	 * @return list<array{key:string,value:mixed,storage:string,option:string}>
	 */
	private static function generatepress_entries(): array {
		$values = [];
		if ( function_exists( 'generate_get_defaults' ) && function_exists( 'generate_get_option' ) ) {
			$defaults = generate_get_defaults();
			if ( is_array( $defaults ) ) {
				foreach ( array_keys( $defaults ) as $key ) {
					$values[ (string) $key ] = generate_get_option( (string) $key );
				}
			}
		} else {
			$stored = get_option( 'generate_settings', [] );
			$values = is_array( $stored ) ? $stored : [];
		}

		$entries = [];
		foreach ( $values as $key => $value ) {
			$entries[] = [
				'key'     => (string) $key,
				'value'   => $value,
				'storage' => 'option',
				'option'  => 'generate_settings',
			];
		}
		return $entries;
	}

	private static function bucket_for_key( string $key ): ?string {
		$normalized = strtolower( $key );
		if ( str_contains( $normalized, 'header' ) ) {
			return 'header';
		}
		if ( str_contains( $normalized, 'footer' ) ) {
			return 'footer';
		}
		if ( str_contains( $normalized, 'font' ) || str_contains( $normalized, 'typography' ) ) {
			return 'typography';
		}
		if ( str_contains( $normalized, 'color' ) || str_contains( $normalized, 'palette' ) || str_contains( $normalized, 'hue' ) ) {
			return 'colors';
		}
		return null;
	}
}
