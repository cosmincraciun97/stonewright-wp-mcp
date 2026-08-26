<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

use Stonewright\WpMcp\CustomCode\Providers\CodeSnippetsProvider;
use Stonewright\WpMcp\CustomCode\Providers\CustomizerCssProvider;
use Stonewright\WpMcp\CustomCode\Providers\ThemeFileProvider;
use Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider;

/**
 * First-party custom-code provider adapters.
 */
final class ProviderRegistry {

	/** @var array<string, ProviderInterface>|null */
	private static ?array $providers = null;

	/**
	 * @return array<string, ProviderInterface>
	 */
	public static function all(): array {
		if ( null === self::$providers ) {
			$instances = [
				new WpCodeProvider(),
				new CodeSnippetsProvider(),
				new CustomizerCssProvider(),
				new ThemeFileProvider(),
			];
			self::$providers = [];
			foreach ( $instances as $provider ) {
				self::$providers[ $provider->id() ] = $provider;
			}
		}
		return self::$providers;
	}

	public static function get( string $id ): ?ProviderInterface {
		$id = sanitize_key( $id );
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Map sanitized post types to a single owner. Duplicate claims are conflicts
	 * and still block generic content writes.
	 *
	 * @return array{
	 *   owners: array<string, string>,
	 *   conflicts: array<string, list<string>>
	 * }
	 */
	public static function post_type_ownership(): array {
		$owners    = [];
		$conflicts = [];

		foreach ( self::all() as $provider ) {
			if ( ! $provider instanceof OwnsPostTypesInterface ) {
				continue;
			}

			$seen = [];
			foreach ( $provider->owned_post_types() as $raw ) {
				$type = sanitize_key( (string) $raw );
				if ( '' === $type || isset( $seen[ $type ] ) ) {
					continue;
				}
				$seen[ $type ] = true;
				$id            = $provider->id();

				if ( isset( $conflicts[ $type ] ) ) {
					if ( ! in_array( $id, $conflicts[ $type ], true ) ) {
						$conflicts[ $type ][] = $id;
					}
					continue;
				}

				if ( isset( $owners[ $type ] ) && $owners[ $type ] !== $id ) {
					$conflicts[ $type ] = [ $owners[ $type ], $id ];
					unset( $owners[ $type ] );
					continue;
				}

				$owners[ $type ] = $id;
			}
		}

		return [
			'owners'    => $owners,
			'conflicts' => $conflicts,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function discover_all(): array {
		$out = [];
		foreach ( self::all() as $provider ) {
			$out[] = $provider->discover();
		}
		return $out;
	}

	/** @internal tests */
	public static function reset_for_tests(): void {
		self::$providers = null;
	}

	/**
	 * @param array<string, ProviderInterface> $providers
	 * @internal tests
	 */
	public static function set_for_tests( array $providers ): void {
		self::$providers = $providers;
	}
}
