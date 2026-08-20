<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Groups discovered abilities by registering provider for the admin hub.
 */
final class AbilityHubCatalog {

	/**
	 * Providers that always appear, even when empty.
	 *
	 * @var list<string>
	 */
	public const ALWAYS_PROVIDERS = [ 'stonewright', 'elementor' ];

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function collect(): array {
		$items = [];
		$seen  = [];

		foreach ( AbilityRegistry::all_abilities() as $ability ) {
			$name = (string) ( $ability['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$ability['provider'] = 'stonewright';
			$items[]             = $ability;
			$seen[ $name ]       = true;
		}

		$external = self::wp_abilities();
		$filtered = apply_filters( 'stonewright_abilities_hub_external', $external );
		if ( ! is_array( $filtered ) ) {
			$filtered = $external;
		}

		foreach ( $filtered as $ability ) {
			if ( ! is_array( $ability ) ) {
				continue;
			}
			$name = (string) ( $ability['name'] ?? '' );
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$ability['provider'] = self::provider_for( $name );
			$items[]             = $ability;
			$seen[ $name ]       = true;
		}

		return $items;
	}

	/**
	 * @return list<string>
	 */
	public static function names(): array {
		return array_values( array_filter( array_map( 'strval', array_column( self::collect(), 'name' ) ) ) );
	}

	/**
	 * @return array<string, array{id:string,label:string,registered:bool,categories:array<string, list<array<string, mixed>>>}>
	 */
	public static function grouped(): array {
		$groups = [];
		foreach ( self::ALWAYS_PROVIDERS as $provider ) {
			$groups[ $provider ] = self::empty_group( $provider, 'stonewright' === $provider );
		}

		foreach ( self::collect() as $ability ) {
			$provider = self::provider_for( (string) ( $ability['name'] ?? '' ) );
			$groups[ $provider ] ??= self::empty_group( $provider, false );
			$groups[ $provider ]['registered'] = true;
			$category = sanitize_key( (string) ( $ability['category'] ?? 'general' ) );
			if ( '' === $category ) {
				$category = 'general';
			}
			$groups[ $provider ]['categories'][ $category ] ??= [];
			$groups[ $provider ]['categories'][ $category ][] = $ability;
		}

		foreach ( $groups as $provider => $group ) {
			ksort( $groups[ $provider ]['categories'] );
		}

		return $groups;
	}

	public static function provider_for( string $name ): string {
		$parts    = explode( '/', $name, 2 );
		$provider = sanitize_key( (string) ( $parts[0] ?? '' ) );

		return '' !== $provider ? $provider : 'unknown';
	}

	public static function provider_label( string $provider ): string {
		if ( 'stonewright' === $provider ) {
			return __( 'Stonewright', 'stonewright' );
		}
		if ( 'elementor' === $provider ) {
			return __( 'Elementor', 'stonewright' );
		}

		return ucwords( str_replace( [ '-', '_' ], ' ', $provider ) );
	}

	/**
	 * @return array{id:string,label:string,registered:bool,categories:array<string, list<array<string, mixed>>>}
	 */
	private static function empty_group( string $provider, bool $registered ): array {
		return [
			'id'         => $provider,
			'label'      => self::provider_label( $provider ),
			'registered' => $registered,
			'categories' => [],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function wp_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$disabled = array_map( 'strval', (array) get_option( 'stonewright_disabled_abilities', [] ) );
		$out      = [];

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) ) {
				continue;
			}
			$name = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
			if ( '' === $name || str_starts_with( $name, 'stonewright/' ) ) {
				continue;
			}

			$schema = [];
			if ( method_exists( $ability, 'get_input_schema' ) ) {
				$raw = $ability->get_input_schema();
				$schema = is_array( $raw ) ? $raw : [];
			}

			$out[] = [
				'name'          => $name,
				'label'         => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $name,
				'description'   => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
				'category'      => self::wp_category( $ability ),
				'mcp_tool_name' => AbilityRegistry::mcp_tool_name( $name ),
				'input_schema'  => $schema,
				'enabled'       => ! in_array( $name, $disabled, true ),
			];
		}

		return $out;
	}

	private static function wp_category( object $ability ): string {
		if ( method_exists( $ability, 'get_meta' ) ) {
			$meta = $ability->get_meta();
			if ( is_array( $meta ) && isset( $meta['category'] ) && is_string( $meta['category'] ) && '' !== $meta['category'] ) {
				return sanitize_key( $meta['category'] );
			}
		}

		return 'registered';
	}
}
