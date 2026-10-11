<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Support\Json;

/**
 * Captures and restores the Elementor default styles of the active kit through Elementor's own repository.
 *
 * From Elementor 4.3 a default style is a post of type e_default_style, and the kit meta only holds the map from
 * an HTML tag to the id of that post. A snapshot of kit meta therefore never covers a default style change. The
 * state here is what the repository returns for each tag (the type and the variants), and a restore writes it back
 * with put() and delete() and reads it back before it says it worked.
 *
 * Where Elementor has no such repository, capture() is null and nothing is claimed about default styles.
 */
final class DefaultStylesState {

	private const REPOSITORY = '\\Elementor\\Modules\\DefaultStyles\\Default_Styles_Repository';

	/** @var callable():?object|null */
	private static $factory = null;

	/**
	 * @internal Tests only: a repository (null for none) instead of Elementor's.
	 *
	 * @param callable():?object|null $factory
	 */
	public static function use_factory_for_tests( ?callable $factory ): void {
		self::$factory = $factory;
	}

	/**
	 * @return array{v:int,items:array<string,array{type:string,variants:array<mixed>}>}|null Null when the repository is not there or cannot be read.
	 */
	public static function capture(): ?array {
		$repository = self::repository();
		if ( null === $repository ) {
			return null;
		}
		$items = self::read( $repository );
		return null === $items ? null : [ 'v' => 1, 'items' => $items ];
	}

	/**
	 * Writes a captured state back and reads it back.
	 *
	 * @param array<string, mixed> $state
	 * @return bool True only when the repository now holds the tags and the styles of the state, and no other tag.
	 */
	public static function restore( array $state ): bool {
		if ( ! is_array( $state['items'] ?? null ) ) {
			return false;
		}
		$wanted     = self::core( $state['items'] );
		$repository = self::repository();
		if ( null === $repository || ! method_exists( $repository, 'put' ) || ! method_exists( $repository, 'delete' ) ) {
			return false;
		}
		try {
			$current = self::read( $repository );
			if ( null === $current ) {
				return false;
			}
			foreach ( array_keys( $current ) as $tag ) {
				if ( ! array_key_exists( $tag, $wanted ) ) {
					$repository->delete( (string) $tag );
				}
			}
			foreach ( $wanted as $tag => $style ) {
				if ( ! array_key_exists( $tag, $current ) || Json::hash( $current[ $tag ] ) !== Json::hash( $style ) ) {
					if ( false === $repository->put( (string) $tag, $style ) ) {
						return false;
					}
				}
			}
			$live = self::read( self::repository() ?? $repository );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return false;
		}
		return null !== $live && Json::hash( $wanted ) === Json::hash( $live );
	}

	/**
	 * Whether two states hold the same tags with the same styles.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	public static function same( array $a, array $b ): bool {
		return Json::hash( self::core( (array) ( $a['items'] ?? [] ) ) ) === Json::hash( self::core( (array) ( $b['items'] ?? [] ) ) );
	}

	/**
	 * The part of a style that a restore can bring back: its type and its variants (the id, the label and the CSS
	 * name are derived from the tag by Elementor).
	 *
	 * @param array<mixed> $items
	 * @return array<string,array{type:string,variants:array<mixed>}>
	 */
	private static function core( array $items ): array {
		$out = [];
		foreach ( $items as $tag => $style ) {
			if ( ! is_array( $style ) ) {
				continue;
			}
			$out[ (string) $tag ] = [
				'type'     => (string) ( $style['type'] ?? 'class' ),
				'variants' => (array) ( $style['variants'] ?? [] ),
			];
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** @return array<string,array{type:string,variants:array<mixed>}>|null */
	private static function read( object $repository ): ?array {
		if ( ! method_exists( $repository, 'all' ) ) {
			return null;
		}
		try {
			// Forced, so a cached answer never hides what a put just wrote.
			$all = $repository->all( true );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return null;
		}
		return is_array( $all ) ? self::core( $all ) : null;
	}

	private static function repository(): ?object {
		if ( null !== self::$factory ) {
			return ( self::$factory )();
		}
		if ( ! class_exists( self::REPOSITORY ) || ! is_callable( [ self::REPOSITORY, 'make' ] ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		try {
			$manager = \Elementor\Plugin::$instance->kits_manager ?? null;
			$kit     = is_object( $manager ) && method_exists( $manager, 'get_active_kit' ) ? $manager->get_active_kit() : null;
			if ( ! is_object( $kit ) ) {
				return null;
			}
			$repository = call_user_func( [ self::REPOSITORY, 'make' ], $kit );
			return is_object( $repository ) ? $repository : null;
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return null;
		}
	}
}
