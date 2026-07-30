<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Sandbox;

use Stonewright\WpMcp\Security\Permissions;

/**
 * Reads and writes per-file manifest metadata for sandbox files.
 *
 * A manifest is a sibling JSON file named <basename-without-extension>.manifest.json
 * stored in the sandbox draft directory alongside the PHP file.
 *
 * Schema (all fields optional except the object itself must be a JSON object):
 *   title       string
 *   description string
 *   author      string
 *   version     string
 *   category    "snippet"|"widget"|"plugin"
 *   created_at  ISO-8601 date-time string
 *   tags        array of strings
 */
final class SandboxManifest {

	private const VALID_CATEGORIES = [ 'snippet', 'widget', 'plugin' ];

	/**
	 * Returns the manifest path for a given PHP basename.
	 *
	 * @param string $basename e.g. "my-snippet.php"
	 */
	private static function manifest_path( string $basename ): string {
		// Strip .php suffix and replace with .manifest.json.
		$stem = basename( $basename, '.php' );
		return SandboxFiles::draft_dir() . '/' . $stem . '.manifest.json';
	}

	/**
	 * Reads and validates the manifest for a sandbox file.
	 *
	 * @param string $basename PHP basename (e.g. "my-snippet.php").
	 * @return array<string, mixed>|null Manifest data or null if missing/invalid.
	 */
	public static function read( string $basename ): ?array {
		$path = self::manifest_path( $basename );

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return null;
		}

		try {
			$data = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return null;
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( ! self::validate( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Validates a manifest data array against the expected schema.
	 *
	 * Returns true when valid; false on schema violations. All fields are
	 * optional — the call is valid with an empty array.
	 *
	 * @param array<mixed, mixed> $data
	 */
	public static function validate( array $data ): bool {
		// All keys must be from the allowed set.
		$allowed_keys = [ 'title', 'description', 'author', 'version', 'category', 'created_at', 'tags' ];
		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				return false;
			}
		}

		// String fields.
		$string_keys = [ 'title', 'description', 'author', 'version', 'created_at' ];
		foreach ( $string_keys as $k ) {
			if ( isset( $data[ $k ] ) && ! is_string( $data[ $k ] ) ) {
				return false;
			}
		}

		// category must be one of the allowed values.
		if ( isset( $data['category'] ) ) {
			if ( ! is_string( $data['category'] ) || ! in_array( $data['category'], self::VALID_CATEGORIES, true ) ) {
				return false;
			}
		}

		// tags must be an array of strings.
		if ( isset( $data['tags'] ) ) {
			if ( ! is_array( $data['tags'] ) ) {
				return false;
			}
			foreach ( $data['tags'] as $tag ) {
				if ( ! is_string( $tag ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Writes (or overwrites) the manifest file for a sandbox PHP file.
	 *
	 * Requires Permissions::can_manage_sandbox().
	 * Performs an atomic write via a temp file + rename.
	 *
	 * @param string              $basename PHP basename (e.g. "my-snippet.php").
	 * @param array<mixed, mixed> $data     Manifest data (must pass validate()).
	 * @return bool|\WP_Error
	 */
	public static function write( string $basename, array $data ): bool|\WP_Error {
		if ( ! Permissions::can_manage_sandbox() ) {
			return new \WP_Error(
				'stonewright_sandbox_manifest_permission',
				__( 'Insufficient permissions to write sandbox manifest.', 'stonewright' )
			);
		}

		if ( ! self::validate( $data ) ) {
			return new \WP_Error(
				'stonewright_sandbox_manifest_invalid',
				__( 'Manifest data failed schema validation.', 'stonewright' )
			);
		}

		$path    = self::manifest_path( $basename );
		$encoded = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			return new \WP_Error(
				'stonewright_sandbox_manifest_encode',
				__( 'Could not JSON-encode manifest data.', 'stonewright' )
			);
		}

		// Atomic write: write to a temp sibling then rename.
		$tmp = $path . '.tmp.' . getmypid();
		$result = file_put_contents( $tmp, $encoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $result ) {
			return new \WP_Error(
				'stonewright_sandbox_manifest_write',
				__( 'Could not write manifest temp file.', 'stonewright' )
			);
		}

		if ( ! rename( $tmp, $path ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error(
				'stonewright_sandbox_manifest_rename',
				__( 'Could not finalize manifest file (rename failed).', 'stonewright' )
			);
		}

		return true;
	}
}
