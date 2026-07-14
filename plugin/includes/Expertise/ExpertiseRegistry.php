<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/** Registry and dependency/conflict graph for bundled and site expertise. */
final class ExpertiseRegistry {

	/** @return list<array<string, mixed>> */
	public static function all(): array {
		return ExpertiseStore::all();
	}

	/** @return array<string, mixed>|null */
	public static function get( string $id ): ?array {
		return ExpertiseStore::get( $id );
	}

	/** @return array{dependencies:list<string>,conflicts:list<string>,missing_dependencies:list<string>} */
	public static function graph( string $id ): array {
		$pack         = self::get( $id );
		$dependencies = array_values( array_map( 'strval', (array) ( $pack['dependencies'] ?? [] ) ) );
		$conflicts    = array_values( array_map( 'strval', (array) ( $pack['conflicts'] ?? [] ) ) );
		$known        = array_fill_keys( array_map( static fn( array $row ): string => (string) $row['id'], self::all() ), true );
		return [
			'dependencies'         => $dependencies,
			'conflicts'            => $conflicts,
			'missing_dependencies' => array_values( array_filter( $dependencies, static fn( string $dependency ): bool => ! isset( $known[ $dependency ] ) ) ),
		];
	}
}
