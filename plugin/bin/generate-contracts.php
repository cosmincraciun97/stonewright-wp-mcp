#!/usr/bin/env php
<?php
/**
 * Generate public ability + direct-tool contract snapshots.
 *
 * Usage: php bin/generate-contracts.php
 *
 * Run from the plugin/ directory or via `composer contracts:generate`.
 * Writes:
 *   <repo-root>/docs/contracts/public-api-v1.json
 *   <repo-root>/docs/contracts/direct-tools-v1.json (via companion script when present)
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

define( 'PLUGIN_DIR', dirname( __DIR__ ) );
define( 'REPO_ROOT', dirname( PLUGIN_DIR ) );
define( 'OUTPUT_FILE', REPO_ROOT . '/docs/contracts/public-api-v1.json' );

$autoload = PLUGIN_DIR . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "ERROR: vendor/autoload.php not found. Run `composer install` first.\n" );
	exit( 1 );
}
require_once $autoload;

/**
 * Stub the minimum WordPress functions needed for ability instantiation.
 * Schemas and name() do not touch WP at runtime for registered abilities.
 */
if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * @param array<string, mixed> $args
	 */
	function wp_register_ability( string $name, array $args ): void {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return $default;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return false;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return false;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', PLUGIN_DIR . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			public readonly string $code = '',
			public readonly string $message = '',
			public readonly mixed $data = '',
		) {}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

use Stonewright\WpMcp\Support\PublicApiContractSnapshot;

// Preserve intentional allowlist entries from an existing frozen contract.
$allowlist = [
	'removed'        => [],
	'renamed'        => new \stdClass(),
	'schema_changes' => [],
];
if ( is_readable( OUTPUT_FILE ) ) {
	try {
		$existing = PublicApiContractSnapshot::load( OUTPUT_FILE );
		if ( isset( $existing['allowlist'] ) && is_array( $existing['allowlist'] ) ) {
			$allowlist = [
				'removed'        => array_values( array_map( 'strval', (array) ( $existing['allowlist']['removed'] ?? [] ) ) ),
				'renamed'        => (object) (array) ( $existing['allowlist']['renamed'] ?? [] ),
				'schema_changes' => array_values( array_map( 'strval', (array) ( $existing['allowlist']['schema_changes'] ?? [] ) ) ),
			];
		}
	} catch ( \Throwable $e ) {
		fwrite( STDERR, 'WARNING: Could not preserve existing allowlist: ' . $e->getMessage() . "\n" );
	}
}

$document               = PublicApiContractSnapshot::collect();
$document['allowlist']  = $allowlist;
$encoded                = PublicApiContractSnapshot::encode_document( $document );

$out_dir = dirname( OUTPUT_FILE );
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0755, true ) && ! is_dir( $out_dir ) ) {
	fwrite( STDERR, "ERROR: Failed to create {$out_dir}\n" );
	exit( 1 );
}

if ( false === file_put_contents( OUTPUT_FILE, $encoded ) ) {
	fwrite( STDERR, 'ERROR: Failed to write ' . OUTPUT_FILE . "\n" );
	exit( 1 );
}

$count = count( $document['abilities'] );
fwrite( STDOUT, sprintf( "Wrote %d abilities to %s\n", $count, OUTPUT_FILE ) );

// Also regenerate direct-tools contract when the companion script is present.
$direct_script = REPO_ROOT . '/companion/scripts/generate-direct-tools-contract.mjs';
if ( is_readable( $direct_script ) ) {
	$cmd = 'node ' . escapeshellarg( $direct_script );
	passthru( $cmd, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, "ERROR: direct-tools contract generation failed (exit {$code})\n" );
		exit( $code );
	}
}

exit( 0 );
