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
