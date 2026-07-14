<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/** Selects at most three compatible packs and returns only compact handshake refs. */
final class ExpertiseResolver {

	/** @param array<string, string> $known_hashes @param array<string, mixed>|null $runtime @return list<array<string, mixed>> */
	public static function resolve( string $task, string $surface = 'unknown', array $known_hashes = [], ?array $runtime = null ): array {
		$runtime ??= RuntimeContext::capture();
		$query      = self::normalize( $task . ' ' . $surface );
		$rows       = [];
		foreach ( ExpertiseRegistry::all() as $pack ) {
			if ( ! in_array( (string) $pack['status'], [ 'verified', 'stable' ], true ) ) {
				continue;
			}
			$compatibility = self::compatibility( $pack, $runtime );
			if ( ! $compatibility['compatible'] ) {
				continue;
			}
			$score = self::score( $query, self::normalize( (string) $pack['id'] . ' ' . (string) $pack['domain'] . ' ' . (string) $pack['capability'] . ' ' . (string) $pack['trigger'] . ' ' . implode( ' ', (array) $pack['terms'] ) ) );
			if ( $score < 1 && (string) $pack['domain'] !== $surface && 'security' !== (string) $pack['domain'] ) {
				continue;
			}
			$pack['_score'] = $score + ( (string) $pack['domain'] === $surface ? 10 : 0 );
			$rows[]         = $pack;
		}
		usort( $rows, static fn( array $a, array $b ): int => (int) $b['_score'] <=> (int) $a['_score'] );
		return array_map(
			static function ( array $pack ) use ( $known_hashes ): array {
				$id     = (string) $pack['id'];
				$hash   = (string) $pack['hash'];
				$cached = isset( $known_hashes[ $id ] ) && hash_equals( $hash, (string) $known_hashes[ $id ] );
				$ref    = [ 'id' => $id, 'version' => (string) $pack['version'], 'status' => (string) $pack['status'], 'hash' => $hash, 'cached' => $cached, 'body_tool' => 'stonewright/expertise-get' ];
				if ( ! $cached ) {
					$ref['trigger'] = (string) $pack['trigger'];
				}
				return $ref;
			},
			array_slice( $rows, 0, 3 )
		);
	}

	/** @param array<string, mixed> $pack @param array<string, mixed> $runtime @return array{compatible:bool,reasons:list<string>} */
	public static function compatibility( array $pack, array $runtime ): array {
		$reasons  = [];
		$versions = (array) ( $runtime['versions'] ?? [] );
		foreach ( (array) ( $pack['supported_versions'] ?? [] ) as $component => $constraint ) {
			$constraint = (string) $constraint;
			$actual     = (string) ( $versions[ $component ] ?? '' );
			if ( 'optional' === $constraint && '' === $actual ) {
				continue;
			}
			if ( '' === $actual || ! self::version_matches( $actual, $constraint ) ) {
				$reasons[] = (string) $component . ':' . ( '' === $actual ? 'missing' : 'version_mismatch' );
			}
		}
		$capabilities = array_fill_keys( array_map( 'strval', (array) ( $runtime['capabilities'] ?? [] ) ), true );
		foreach ( (array) ( $pack['required_capabilities'] ?? [] ) as $capability ) {
			if ( ! isset( $capabilities[ (string) $capability ] ) ) {
				$reasons[] = 'capability:' . (string) $capability;
			}
		}
		$graph = ExpertiseRegistry::graph( (string) $pack['id'] );
		foreach ( $graph['missing_dependencies'] as $missing ) {
			$reasons[] = 'dependency:' . $missing;
		}
		return [ 'compatible' => [] === $reasons, 'reasons' => $reasons ];
	}

	private static function version_matches( string $actual, string $constraint ): bool {
		if ( 'optional' === $constraint ) {
			return true;
		}
		foreach ( preg_split( '/\s+/', trim( $constraint ) ) ?: [] as $part ) {
			if ( ! preg_match( '/^(>=|<=|>|<|=)?([0-9][0-9A-Za-z._-]*)$/', $part, $match ) ) {
				return false;
			}
			if ( ! version_compare( $actual, $match[2], '' !== $match[1] ? $match[1] : '=' ) ) {
				return false;
			}
		}
		return true;
	}

	private static function score( string $query, string $haystack ): int {
		$score = 0;
		foreach ( array_unique( array_filter( explode( ' ', $query ), static fn( string $term ): bool => strlen( $term ) > 2 ) ) as $term ) {
			$score += str_contains( $haystack, $term ) ? 1 : 0;
		}
		return $score;
	}

	private static function normalize( string $value ): string {
		$value = strtolower( function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $value ) ?? '' );
	}
}
