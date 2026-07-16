<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Loads the searchable admin prompt library from plugin/data/prompts/catalog.json.
 */
final class PromptCatalog {

	/**
	 * @return array{
	 *   version: int,
	 *   description: string,
	 *   prompts: list<array{
	 *     id: string,
	 *     title: string,
	 *     outcome: string,
	 *     summary: string,
	 *     prerequisites: list<string>,
	 *     tools: list<string>,
	 *     prompt: string,
	 *     verification: string
	 *   }>
	 * }
	 */
	public static function load(): array {
		$path = self::catalog_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return [
				'version'     => 0,
				'description' => '',
				'prompts'     => [],
			];
		}

		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [
				'version'     => 0,
				'description' => '',
				'prompts'     => [],
			];
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [
				'version'     => 0,
				'description' => '',
				'prompts'     => [],
			];
		}

		$prompts = [];
		foreach ( (array) ( $decoded['prompts'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$prompts[] = [
				'id'             => $id,
				'title'          => (string) ( $row['title'] ?? $id ),
				'outcome'        => sanitize_key( (string) ( $row['outcome'] ?? 'general' ) ),
				'summary'        => (string) ( $row['summary'] ?? '' ),
				'prerequisites'  => self::string_list( $row['prerequisites'] ?? [] ),
				'tools'          => self::string_list( $row['tools'] ?? [] ),
				'prompt'         => (string) ( $row['prompt'] ?? '' ),
				'verification'   => (string) ( $row['verification'] ?? '' ),
			];
		}

		return [
			'version'     => (int) ( $decoded['version'] ?? 1 ),
			'description' => (string) ( $decoded['description'] ?? '' ),
			'prompts'     => $prompts,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		return self::load()['prompts'];
	}

	/**
	 * Case-insensitive filter across title, outcome, summary, tools, prompt.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function search( string $query, string $outcome = '' ): array {
		$query   = strtolower( trim( $query ) );
		$outcome = sanitize_key( $outcome );
		$out     = [];

		foreach ( self::all() as $prompt ) {
			if ( '' !== $outcome && (string) ( $prompt['outcome'] ?? '' ) !== $outcome ) {
				continue;
			}
			if ( '' === $query ) {
				$out[] = $prompt;
				continue;
			}
			$hay = strtolower(
				implode(
					' ',
					[
						(string) ( $prompt['id'] ?? '' ),
						(string) ( $prompt['title'] ?? '' ),
						(string) ( $prompt['outcome'] ?? '' ),
						(string) ( $prompt['summary'] ?? '' ),
						(string) ( $prompt['prompt'] ?? '' ),
						implode( ' ', (array) ( $prompt['tools'] ?? [] ) ),
					]
				)
			);
			if ( str_contains( $hay, $query ) ) {
				$out[] = $prompt;
			}
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	public static function outcomes(): array {
		$out = [];
		foreach ( self::all() as $prompt ) {
			$outcome = (string) ( $prompt['outcome'] ?? '' );
			if ( '' !== $outcome ) {
				$out[ $outcome ] = true;
			}
		}
		$keys = array_keys( $out );
		sort( $keys );
		return $keys;
	}

	public static function catalog_path(): string {
		if ( defined( 'STONEWRIGHT_PATH' ) ) {
			return (string) constant( 'STONEWRIGHT_PATH' ) . 'data/prompts/catalog.json';
		}
		$fallback = dirname( __DIR__, 2 ) . '/data/prompts/catalog.json';
		return is_readable( $fallback ) ? $fallback : '';
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$s = trim( (string) $item );
				if ( '' !== $s ) {
					$out[] = $s;
				}
			}
		}
		return $out;
	}
}
