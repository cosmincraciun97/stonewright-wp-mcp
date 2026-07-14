<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetRegistry;

/**
 * Catalog-backed ranker for choosing native Elementor widgets.
 *
 * This is intentionally pure: no WordPress globals, no I/O beyond the
 * WidgetCatalog's manifest load, and no model-specific behavior. The goal is
 * to make every LLM ask the same deterministic recommender before guessing.
 */
final class WidgetRecommender {

	/**
	 * @param array<string, mixed> $context
	 * @return array<int, array<string, mixed>>
	 */
	public static function recommend( string $prompt, int $limit = 5, array $context = [] ): array {
		$query = self::normalise( $prompt . ' ' . self::context_text( $context ) );
		if ( '' === $query ) {
			return [];
		}

		$allow_html = ! empty( $context['allow_html_widget'] );
		$rows       = [];

		foreach ( WidgetCatalog::widgets() as $slug => $entry ) {
			if ( 'html' === $slug && ! $allow_html ) {
				continue;
			}

			$score   = 0;
			$reasons = [];
			self::score_entry( (string) $slug, $entry, $query, $score, $reasons );

			if ( $score <= 0 ) {
				continue;
			}

			$rows[] = [
				'slug'                => (string) $slug,
				'title'               => (string) ( $entry['title'] ?? $slug ),
				'source'              => (string) ( $entry['source'] ?? 'free' ),
				'ability'             => 'stonewright/elementor-v3-batch-mutate',
				'legacy_ability'      => 'stonewright/elementor-add-' . $slug,
				'score'               => $score,
				'reasons'             => array_values( array_unique( $reasons ) ),
				'required_for_render' => array_values( array_filter( (array) ( $entry['required_for_render'] ?? [] ), 'is_string' ) ),
				'settings_highlights' => array_slice( array_values( (array) ( $entry['settings_highlights'] ?? [] ) ), 0, 4 ),
			];
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => ( $b['score'] <=> $a['score'] )
				?: strcmp( (string) $a['slug'], (string) $b['slug'] )
		);

		return array_slice( $rows, 0, max( 1, $limit ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<int, string>   $reasons
	 */
	private static function score_entry( string $slug, array $entry, string $query, int &$score, array &$reasons ): void {
		foreach ( self::native_pattern_boosts() as $pattern => $targets ) {
			if ( preg_match( $pattern, $query ) && isset( $targets[ $slug ] ) ) {
				$score    += $targets[ $slug ];
				$reasons[] = 'matched native pattern';
			}
		}

		$title = self::normalise( (string) ( $entry['title'] ?? '' ) );
		if ( '' !== $title && str_contains( $query, $title ) ) {
			$score    += 20;
			$reasons[] = 'matched title';
		}

		foreach ( (array) ( $entry['keywords'] ?? [] ) as $keyword ) {
			$needle = self::normalise( (string) $keyword );
			if ( '' !== $needle && str_contains( $query, $needle ) ) {
				$score    += 12;
				$reasons[] = 'matched keyword: ' . $needle;
			}
		}

		$intent = self::normalise( (string) ( $entry['intent'] ?? '' ) );
		if ( '' === $intent ) {
			return;
		}

		foreach ( self::query_terms( $query ) as $term ) {
			if ( strlen( $term ) >= 4 && str_contains( $intent, $term ) ) {
				$score += 2;
			}
		}
	}

	/**
	 * @return array<string, array<string, int>>
	 */
	private static function native_pattern_boosts(): array {
		return [
			'/\b(galerie|gallery|photo grid|foto|imagini)\b/u' => [
				'image-gallery' => 100,
				'gallery'       => 90,
			],
			'/\b(aftermovie|video|youtube|vimeo|mp4|poster|play)\b/u' => [
				'video' => 100,
			],
			'/\b(header|meniu|menu|navbar|hamburger|sticky)\b/u' => [
				'nav-menu'  => 100,
				'icon-list' => 15,
			],
			'/\b(newsletter|formular|form|contact|email|subscribe|aboneaz)\w*\b/u' => [
				'form' => 100,
			],
			'/\b(social|facebook|instagram|linkedin|youtube|tiktok)\b/u' => [
				'social-icons' => 100,
			],
			'/\b(footer|subsol|coloane|links|linkuri)\b/u' => [
				'icon-list'    => 90,
				'social-icons' => 30,
			],
			'/\b(countdown|timer|cronometru|numaratoare)\b/u' => [
				'countdown' => 100,
			],
			'/\b(tabs?|taburi)\b/u' => [
				'tabs' => 100,
			],
			'/\b(faq|accordion|intrebari)\b/u' => [
				'accordion' => 100,
				'toggle'    => 80,
			],
			'/\b(html|embed|script|code|cod)\b/u' => [
				'html' => 100,
			],
		];
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function context_text( array $context ): string {
		$parts = [];
		foreach ( $context as $value ) {
			if ( is_scalar( $value ) ) {
				$parts[] = (string) $value;
			}
		}
		return implode( ' ', $parts );
	}

	private static function normalise( string $text ): string {
		$text = strtolower(
			strtr(
				$text,
				[
					'ă' => 'a',
					'â' => 'a',
					'î' => 'i',
					'ș' => 's',
					'ş' => 's',
					'ț' => 't',
					'ţ' => 't',
					'Ă' => 'a',
					'Â' => 'a',
					'Î' => 'i',
					'Ș' => 's',
					'Ş' => 's',
					'Ț' => 't',
					'Ţ' => 't',
				]
			)
		);

		return trim( preg_replace( '/[^a-z0-9]+/u', ' ', $text ) ?? '' );
	}

	/**
	 * @return array<int, string>
	 */
	private static function query_terms( string $query ): array {
		return array_values(
			array_filter(
				explode( ' ', $query ),
				static fn( string $term ): bool => '' !== $term
			)
		);
	}
}
