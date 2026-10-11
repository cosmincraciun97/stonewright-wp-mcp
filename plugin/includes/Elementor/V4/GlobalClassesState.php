<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Support\Json;

/**
 * Captures and restores the Elementor global classes through Elementor's own repository.
 *
 * From Elementor 4.3 a class is a post of type e_global_class and the kit only holds lists of post ids, the
 * order and the labels, so a snapshot of kit meta no longer covers a class change. The state here is what the
 * repository returns (items, order and labels) for the frontend and, when it has set_preview(), the preview
 * context, and a restore writes it back with put() and reads it back before it says it worked.
 *
 * Where Elementor has no such repository (older versions) capture() is null and the kit meta keys of
 * Backup::KIT_V4_META_KEYS are the fallback.
 */
final class GlobalClassesState {

	private const REPOSITORY = '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';

	private const CONTEXTS = [ 'frontend', 'preview' ];

	/** @var callable(string):?object|null */
	private static $factory = null;

	/**
	 * @internal Tests only: a repository per context (null for none) instead of Elementor's.
	 *
	 * @param callable(string):?object|null $factory
	 */
	public static function use_factory_for_tests( ?callable $factory ): void {
		self::$factory = $factory;
	}

	/**
	 * @return array{v:int,contexts:array<string,array{items:array<string,mixed>,order:list<string>,labels:array<string,string>}>}|null Null when the repository is not there or cannot be read.
	 */
	public static function capture(): ?array {
		$contexts = [];
		foreach ( self::CONTEXTS as $context ) {
			$repository = self::repository( $context );
			if ( null === $repository ) {
				if ( 'frontend' === $context ) {
					return null;
				}
				continue;
			}
			try {
				$contexts[ $context ] = self::read( $repository );
			} catch ( \Throwable $failure ) {
				return null;
			}
		}
		return [ 'v' => 1, 'contexts' => $contexts ];
	}

	/**
	 * Writes a captured state back and reads it back.
	 *
	 * @param array<string, mixed> $state
	 * @return bool True only when every captured context now holds the items and the order of the state.
	 */
	public static function restore( array $state ): bool {
		$contexts = is_array( $state['contexts'] ?? null ) ? $state['contexts'] : [];
		if ( [] === $contexts ) {
			return false;
		}
		foreach ( $contexts as $context => $wanted ) {
			$repository = self::repository( (string) $context );
			if ( null === $repository || ! is_array( $wanted ) || ! method_exists( $repository, 'put' ) ) {
				return false;
			}
			try {
				$repository->put( (array) ( $wanted['items'] ?? [] ), array_values( (array) ( $wanted['order'] ?? [] ) ) );
				$live = self::read( self::repository( (string) $context ) ?? $repository );
			} catch ( \Throwable $failure ) {
				return false;
			}
			if ( Json::hash( self::core( $wanted ) ) !== Json::hash( self::core( $live ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether two states hold the same classes in the same order.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	public static function same( array $a, array $b ): bool {
		$cores = static function ( array $state ): array {
			$out = [];
			foreach ( (array) ( $state['contexts'] ?? [] ) as $context => $items ) {
				$out[ (string) $context ] = self::core( (array) $items );
			}
			return $out;
		};
		return Json::hash( $cores( $a ) ) === Json::hash( $cores( $b ) );
	}

	/**
	 * The part of a context state that a restore can bring back: the items and their order (the labels are
	 * derived from the items by Elementor).
	 *
	 * @param array<string, mixed> $context
	 * @return array{items:mixed,order:mixed}
	 */
	private static function core( array $context ): array {
		return [ 'items' => $context['items'] ?? [], 'order' => array_values( (array) ( $context['order'] ?? [] ) ) ];
	}

	private static function repository( string $context ): ?object {
		if ( null !== self::$factory ) {
			return ( self::$factory )( $context );
		}
		if ( ! class_exists( self::REPOSITORY ) || ! is_callable( [ self::REPOSITORY, 'make' ] ) ) {
			return null;
		}
		try {
			$repository = call_user_func( [ self::REPOSITORY, 'make' ] );
			if ( ! is_object( $repository ) ) {
				return null;
			}
			if ( 'frontend' === $context ) {
				return $repository;
			}
			// The preview context is a flag of the repository (Has_Preview_Context::set_preview()), set on a fresh instance.
			if ( ! method_exists( $repository, 'set_preview' ) ) {
				return null;
			}
			$scoped = $repository->set_preview( true );
			return is_object( $scoped ) ? $scoped : null;
		} catch ( \Throwable $failure ) {
			return null;
		}
	}

	/**
	 * @return array{items:array<string,mixed>,order:list<string>,labels:array<string,string>}
	 */
	private static function read( object $repository ): array {
		$items  = [];
		$order  = [];
		if ( method_exists( $repository, 'all' ) ) {
			// Forced, so a cached answer never hides what a put just wrote.
			$all = $repository->all( true );
			if ( is_object( $all ) && method_exists( $all, 'get_items' ) ) {
				$items = self::to_array( $all->get_items() );
				$order = method_exists( $all, 'get_order' ) ? self::to_array( $all->get_order() ) : [];
			} elseif ( is_array( $all ) && isset( $all['items'] ) ) {
				$items = self::to_array( $all['items'] );
				$order = self::to_array( $all['order'] ?? [] );
			} else {
				$items = self::to_array( $all );
			}
		} elseif ( method_exists( $repository, 'all_labels' ) && method_exists( $repository, 'get_by_ids' ) ) {
			$items = self::to_array( $repository->get_by_ids( array_keys( self::to_array( $repository->all_labels() ) ) ) );
		}
		if ( [] === $order && method_exists( $repository, 'get_order' ) ) {
			$order = self::to_array( $repository->get_order() );
		}
		$labels = method_exists( $repository, 'all_labels' ) ? self::to_array( $repository->all_labels() ) : [];

		return [
			'items'  => $items,
			'order'  => array_values( array_map( 'strval', $order ) ),
			'labels' => array_map( 'strval', $labels ),
		];
	}

	/** @return array<mixed> */
	private static function to_array( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( [ 'all', 'to_array', 'toArray' ] as $method ) {
				if ( method_exists( $value, $method ) ) {
					$out = $value->{$method}();
					return is_array( $out ) ? $out : [];
				}
			}
		}
		return [];
	}
}
