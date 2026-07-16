<?php
/**
 * Versioned MCP client catalog loaded from plugin/data/clients/*.json.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Loads and validates per-client connection definitions.
 */
final class ClientCatalog {

	/**
	 * @var list<array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Absolute directory containing client JSON definitions.
	 */
	public static function data_dir(): string {
		$dir = defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) : dirname( __DIR__, 2 ) . '/';
		return $dir . 'data/clients';
	}

	/**
	 * @return list<array{
	 *   slug:string,
	 *   label:string,
	 *   kind:string,
	 *   snippet_kind:string,
	 *   preferred_method:string,
	 *   official_cli_add:string,
	 *   config_paths:array<string,string>,
	 *   config_path:string,
	 *   notes:string,
	 *   verified_against_docs_on:string,
	 *   secret_storage:string
	 * }>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$dir = self::data_dir();
		if ( ! is_dir( $dir ) ) {
			self::$cache = [];
			return self::$cache;
		}

		$files = glob( $dir . '/*.json' );
		if ( ! is_array( $files ) ) {
			self::$cache = [];
			return self::$cache;
		}

		$clients = [];
		foreach ( $files as $file ) {
			$client = self::load_file( $file );
			if ( null !== $client ) {
				$clients[] = $client;
			}
		}

		usort(
			$clients,
			static fn( array $a, array $b ): int => strcasecmp( (string) $a['label'], (string) $b['label'] )
		);

		self::$cache = $clients;
		return self::$cache;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$slug = sanitize_key( $slug );
		foreach ( self::all() as $client ) {
			if ( (string) $client['slug'] === $slug ) {
				return $client;
			}
		}
		return null;
	}

	/**
	 * @return list<string>
	 */
	public static function slugs(): array {
		return array_values(
			array_map(
				static fn( array $c ): string => (string) $c['slug'],
				self::all()
			)
		);
	}

	/**
	 * Reset cache (tests only).
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$cache = null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function load_file( string $path ): ?array {
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		try {
			$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return null;
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::normalize( $data );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private static function normalize( array $data ): ?array {
		$slug  = isset( $data['slug'] ) ? sanitize_key( (string) $data['slug'] ) : '';
		$label = isset( $data['label'] ) ? (string) $data['label'] : '';
		if ( '' === $slug || '' === $label ) {
			return null;
		}

		$paths = [];
		if ( isset( $data['config_paths'] ) && is_array( $data['config_paths'] ) ) {
			foreach ( $data['config_paths'] as $os => $path ) {
				$paths[ sanitize_key( (string) $os ) ] = (string) $path;
			}
		}

		// Back-compat single config_path for ConnectClientConfig consumers.
		$config_path = isset( $data['config_path'] ) ? (string) $data['config_path'] : '';
		if ( '' === $config_path && isset( $paths['macos'] ) ) {
			$config_path = (string) $paths['macos'];
		}
		if ( '' === $config_path && [] !== $paths ) {
			$config_path = (string) reset( $paths );
		}

		$kind = isset( $data['kind'] ) ? sanitize_key( (string) $data['kind'] ) : 'editor';
		if ( ! in_array( $kind, [ 'cli', 'desktop', 'editor', 'generic' ], true ) ) {
			$kind = 'editor';
		}

		$snippet_kind = isset( $data['snippet_kind'] ) ? sanitize_key( (string) $data['snippet_kind'] ) : 'json';
		if ( ! in_array( $snippet_kind, [ 'json', 'toml', 'cli', 'mixed' ], true ) ) {
			$snippet_kind = 'json';
		}

		$method = isset( $data['preferred_method'] ) ? sanitize_key( (string) $data['preferred_method'] ) : 'stdio';
		if ( ! in_array( $method, [ 'stdio', 'http', 'application-password' ], true ) ) {
			$method = 'stdio';
		}

		$secret = isset( $data['secret_storage'] ) ? sanitize_key( (string) $data['secret_storage'] ) : 'user-level';
		if ( ! in_array( $secret, [ 'user-level', 'project-discouraged', 'none' ], true ) ) {
			$secret = 'user-level';
		}

		$verified = isset( $data['verified_against_docs_on'] ) ? (string) $data['verified_against_docs_on'] : '';
		if ( '' !== $verified && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $verified ) ) {
			$verified = '';
		}

		return [
			'slug'                     => $slug,
			'label'                    => $label,
			'kind'                     => $kind,
			'snippet_kind'             => $snippet_kind,
			'preferred_method'         => $method,
			'official_cli_add'         => isset( $data['official_cli_add'] ) ? (string) $data['official_cli_add'] : '',
			'config_paths'             => $paths,
			'config_path'              => $config_path,
			'notes'                    => isset( $data['notes'] ) ? (string) $data['notes'] : '',
			'verified_against_docs_on' => $verified,
			'secret_storage'           => $secret,
		];
	}
}
