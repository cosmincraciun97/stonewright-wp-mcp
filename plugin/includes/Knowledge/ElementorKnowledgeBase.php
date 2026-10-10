<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Knowledge;

use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * Read-only query facade over the private Elementor knowledge store under uploads.
 * The store is empty until `stonewright/elementor-knowledge-refresh` has filled it.
 */
final class ElementorKnowledgeBase {

	/**
	 * @return array<string, mixed>
	 */
	public static function search( string $query, ?string $area = null, int $limit = 5 ): array {
		$query = trim( $query );
		$limit = max( 1, min( 20, $limit ) );
		$area  = self::normalise_area( $area );

		$results = [];
		foreach ( self::documents( $area ) as $doc ) {
			$score = self::score( $query, $doc );
			if ( $score <= 0 ) {
				continue;
			}
			$doc['score'] = $score;
			$results[]    = $doc;
		}

		usort(
			$results,
			static fn ( array $a, array $b ): int => ( $b['score'] <=> $a['score'] )
				?: strcmp( (string) $a['path'], (string) $b['path'] )
		);

		$results = array_slice( $results, 0, $limit );

		$response = [
			'query'         => $query,
			'area'          => $area,
			'limit'         => $limit,
			'max_age_days'  => self::max_age_days(),
			'results'       => $results,
			'refresh_ability' => 'stonewright/elementor-knowledge-refresh',
		];
		if ( [] === $results && [] === ElementorKnowledgeStore::article_files() ) {
			$response['hint'] = self::empty_store_hint();
		}

		return $response;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function describe_widget( string $slug ): array {
		$slug     = self::normalise_slug( $slug );
		$manifest = WidgetCatalog::entry( $slug );
		$title    = is_string( $manifest['title'] ?? null ) ? (string) $manifest['title'] : $slug;
		$search   = self::search( $slug . ' ' . $title . ' widget', 'widgets', 8 );

		$response = [
			'widget'          => $slug,
			'manifest'        => $manifest,
			'documents'       => $search['results'],
			'stale'           => self::has_stale_documents( $search['results'] ),
			'max_age_days'    => self::max_age_days(),
			'refresh_ability' => 'stonewright/elementor-knowledge-refresh',
		];
		if ( isset( $search['hint'] ) ) {
			$response['hint'] = $search['hint'];
		}

		return $response;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function explain_editor( string $topic, ?string $area = null, int $limit = 5 ): array {
		$area = self::normalise_area( $area );
		if ( null === $area ) {
			$area = 'editor';
		}
		return self::search( $topic, $area, $limit );
	}

	private static function empty_store_hint(): string {
		return __( 'The Elementor knowledge store is empty on this site. Call stonewright/elementor-knowledge-refresh with an elementor.com or developers.elementor.com URL to add articles; the readers return results after the first refresh.', 'stonewright' );
	}

	private static function normalise_area( ?string $area ): ?string {
		if ( null === $area || '' === trim( $area ) ) {
			return null;
		}
		$area = strtolower( trim( $area ) );
		return match ( $area ) {
			'widget' => 'widgets',
			'docs' => null,
			default => $area,
		};
	}

	private static function normalise_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = preg_replace( '/[^a-z0-9._-]+/', '-', $slug ) ?? '';
		return trim( $slug, '-' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function documents( ?string $area ): array {
		$out = [];
		foreach ( ElementorKnowledgeStore::article_files( $area ) as $path ) {
			$out[] = self::parse_document( $path );
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parse_document( string $path ): array {
		$content = (string) file_get_contents( $path );
		$front   = [];
		$body    = $content;
		if ( preg_match( '/^---\s*\R(.*?)\R---\s*(.*)$/s', $content, $m ) ) {
			$front = self::parse_frontmatter( $m[1] );
			$body  = (string) $m[2];
		}

		$title = (string) ( $front['title'] ?? self::title_from_path( $path ) );

		return [
			'title'      => $title,
			'path'       => ElementorKnowledgeStore::relative( $path ),
			'source_url' => (string) ( $front['source_url'] ?? '' ),
			'fetched_at' => (string) ( $front['fetched_at'] ?? '' ),
			'stale'      => self::is_stale( (string) ( $front['fetched_at'] ?? '' ) ),
			'summary'    => self::summary( $body ),
			'frontmatter'=> $front,
			'body'       => $body,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parse_frontmatter( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\R/', $raw ) ?: [] as $line ) {
			if ( ! preg_match( '/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m ) ) {
				continue;
			}
			$value = trim( $m[2] );
			if ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) ) {
				$value = trim( $value, '[]' );
				$out[ $m[1] ] = $value === '' ? [] : array_map(
					static fn ( string $v ): string => trim( $v, " \t\n\r\0\x0B\"'" ),
					explode( ',', $value )
				);
				continue;
			}
			$out[ $m[1] ] = trim( $value, "\"'" );
		}
		return $out;
	}

	private static function summary( string $body ): string {
		$body = preg_replace( '/^#+\s+/m', '', $body ) ?? $body;
		$body = preg_replace( '/\s+/', ' ', strip_tags( $body ) ) ?? '';
		$body = trim( $body );
		if ( mb_strlen( $body ) <= 360 ) {
			return $body;
		}
		return rtrim( mb_substr( $body, 0, 357 ) ) . '...';
	}

	/**
	 * @param array<string, mixed> $doc
	 */
	private static function score( string $query, array $doc ): int {
		if ( '' === $query ) {
			return 1;
		}
		$terms = preg_split( '/[^a-z0-9]+/i', strtolower( $query ) ) ?: [];
		$terms = array_values( array_filter( array_unique( $terms ), static fn ( string $t ): bool => strlen( $t ) >= 2 ) );
		if ( [] === $terms ) {
			return 1;
		}

		$title   = strtolower( (string) $doc['title'] );
		$path    = strtolower( (string) $doc['path'] );
		$summary = strtolower( (string) $doc['summary'] );
		$body    = strtolower( (string) $doc['body'] );

		$score = 0;
		foreach ( $terms as $term ) {
			if ( str_contains( $title, $term ) ) {
				$score += 8;
			}
			if ( str_contains( $path, $term ) ) {
				$score += 5;
			}
			if ( str_contains( $summary, $term ) ) {
				$score += 3;
			}
			if ( str_contains( $body, $term ) ) {
				++$score;
			}
		}
		return $score;
	}

	private static function title_from_path( string $path ): string {
		$stem = pathinfo( $path, PATHINFO_FILENAME );
		return ucwords( str_replace( [ '-', '_' ], ' ', $stem ) );
	}

	private static function max_age_days(): int {
		return max( 1, (int) get_option( 'stonewright_knowledge_max_age_days', 30 ) );
	}

	private static function is_stale( string $fetched_at ): bool {
		if ( '' === $fetched_at ) {
			return true;
		}
		$timestamp = strtotime( $fetched_at );
		if ( false === $timestamp ) {
			return true;
		}
		return ( time() - $timestamp ) > self::max_age_days() * 86400;
	}

	/**
	 * @param array<int, array<string, mixed>> $documents
	 */
	private static function has_stale_documents( array $documents ): bool {
		foreach ( $documents as $doc ) {
			if ( ! empty( $doc['stale'] ) ) {
				return true;
			}
		}
		return false;
	}
}
