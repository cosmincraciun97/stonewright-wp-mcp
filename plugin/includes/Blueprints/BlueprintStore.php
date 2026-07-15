<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Blueprints;

/**
 * Loads bundled DesignSpec blueprints from plugin/blueprints/*.json.
 *
 * Each file is original Stonewright content (not derived from EMCP templates).
 * Compact list views omit the full DesignSpec; get() returns the full payload.
 */
final class BlueprintStore {

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list( string $industry = '', string $search = '' ): array {
		$out = [];
		foreach ( self::paths() as $path ) {
			$raw = self::decode_file( $path );
			if ( null === $raw ) {
				continue;
			}
			$summary = self::summarize( $raw, $path );
			if ( '' !== $industry && $industry !== (string) ( $summary['industry'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $search ) {
				$hay = mb_strtolower(
					(string) ( $summary['id'] ?? '' ) . ' '
					. (string) ( $summary['name'] ?? '' ) . ' '
					. (string) ( $summary['description'] ?? '' ) . ' '
					. (string) ( $summary['industry'] ?? '' )
				);
				if ( ! str_contains( $hay, mb_strtolower( $search ) ) ) {
					continue;
				}
			}
			$out[] = $summary;
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) )
		);

		return $out;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( string $id ) {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return new \WP_Error(
				'stonewright_blueprint_invalid_id',
				__( 'Blueprint id is required.', 'stonewright' )
			);
		}

		foreach ( self::paths() as $path ) {
			$raw = self::decode_file( $path );
			if ( null === $raw ) {
				continue;
			}
			$candidate = sanitize_key( (string) ( $raw['id'] ?? basename( $path, '.json' ) ) );
			if ( $candidate !== $id ) {
				continue;
			}
			return self::normalize( $raw, $path );
		}

		return new \WP_Error(
			'stonewright_blueprint_not_found',
			sprintf(
				/* translators: %s: blueprint id */
				__( 'Blueprint "%s" was not found.', 'stonewright' ),
				$id
			)
		);
	}

	/**
	 * @return list<string>
	 */
	public static function paths(): array {
		$dir = self::directory();
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$files = glob( $dir . '/*.json' );
		return is_array( $files ) ? array_values( array_filter( $files, 'is_readable' ) ) : [];
	}

	public static function directory(): string {
		return trailingslashit( STONEWRIGHT_DIR ) . 'blueprints';
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public static function summarize( array $raw, string $path = '' ): array {
		$normalized = self::normalize( $raw, $path );
		$spec       = is_array( $normalized['spec'] ?? null ) ? $normalized['spec'] : [];
		$spec_json  = (string) wp_json_encode( $spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return [
			'id'          => (string) $normalized['id'],
			'name'        => (string) $normalized['name'],
			'description' => (string) $normalized['description'],
			'industry'    => (string) $normalized['industry'],
			'section_ids' => array_values( (array) $normalized['section_ids'] ),
			'palette'     => (array) $normalized['palette'],
			'fonts'       => (array) $normalized['fonts'],
			'spec_sha8'   => substr( sha1( $spec_json ), 0, 8 ),
			'sections'    => count( (array) ( $spec['sections'] ?? [] ) ),
		];
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw, string $path = '' ): array {
		$id = sanitize_key( (string) ( $raw['id'] ?? ( '' !== $path ? basename( $path, '.json' ) : '' ) ) );
		$spec = isset( $raw['spec'] ) && is_array( $raw['spec'] ) ? $raw['spec'] : [];
		$section_ids = [];
		if ( isset( $raw['section_ids'] ) && is_array( $raw['section_ids'] ) ) {
			foreach ( $raw['section_ids'] as $sid ) {
				$section_ids[] = sanitize_key( (string) $sid );
			}
		} elseif ( isset( $spec['sections'] ) && is_array( $spec['sections'] ) ) {
			foreach ( $spec['sections'] as $i => $section ) {
				$section_ids[] = sanitize_key( (string) ( is_array( $section ) ? ( $section['id'] ?? 'section_' . $i ) : 'section_' . $i ) );
			}
		}

		return [
			'id'          => $id,
			'name'        => (string) ( $raw['name'] ?? $id ),
			'description' => (string) ( $raw['description'] ?? '' ),
			'industry'    => sanitize_key( (string) ( $raw['industry'] ?? 'general' ) ),
			'palette'     => isset( $raw['palette'] ) && is_array( $raw['palette'] ) ? $raw['palette'] : [],
			'fonts'       => isset( $raw['fonts'] ) && is_array( $raw['fonts'] ) ? $raw['fonts'] : [],
			'section_ids' => array_values( array_filter( $section_ids ) ),
			'spec'        => $spec,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function decode_file( string $path ): ?array {
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
