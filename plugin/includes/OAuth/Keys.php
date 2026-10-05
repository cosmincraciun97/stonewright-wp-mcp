<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/keys.php
 * Source SHA-256: 40e5b817d895463f19cff98380d773dc7bb5f4ddfd275f2dded62b5d3890dcf7
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

use League\OAuth2\Server\CryptKey;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth signing and encryption key storage.
 */
final class Keys {

	public const PRIVATE_KEY_OPTION = 'stonewright_oauth_private_key';

	public const ENCRYPTION_KEY_OPTION = 'stonewright_oauth_encryption_key';

	/**
	 * Last key-generation failure, kept so admins see why OAuth is unavailable.
	 */
	public const ERROR_OPTION = 'stonewright_oauth_key_error';

	public const RETRY_ACTION = 'stonewright_oauth_key_retry';

	/**
	 * Generate missing keys without throwing.
	 *
	 * Plugin activation must never fatal because the host OpenSSL setup is
	 * incomplete. On failure OAuth stays unavailable, the reason is recorded for
	 * an admin notice, and every later Keys::get() retries generation.
	 */
	public static function ensure(): bool {
		try {
			self::get();
		} catch ( \Throwable $exception ) {
			update_option( self::ERROR_OPTION, self::bounded_message( $exception->getMessage() ), false );
			return false;
		}
		if ( '' !== self::last_error() ) {
			delete_option( self::ERROR_OPTION );
		}
		return true;
	}

	public static function last_error(): string {
		return (string) get_option( self::ERROR_OPTION, '' );
	}

	/**
	 * Hook the admin notice and retry handler.
	 */
	public static function register_admin(): void {
		add_action( 'admin_notices', [ self::class, 'render_admin_notice' ] );
		add_action( 'admin_post_' . self::RETRY_ACTION, [ self::class, 'handle_retry' ] );
	}

	public static function render_admin_notice(): void {
		$error = self::last_error();
		if ( '' === $error || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$retry = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RETRY_ACTION ), self::RETRY_ACTION );
		echo '<div class="notice notice-warning"><p><strong>Stonewright:</strong> ';
		echo esc_html__(
			'OAuth is unavailable because this server could not generate its signing key. Application Password connections keep working. On Windows stacks such as Laragon or XAMPP this usually means PHP cannot find openssl.cnf; set OPENSSL_CONF to the openssl.cnf shipped with PHP and restart the web server, then retry.',
			'stonewright'
		);
		echo '</p><p><code>' . esc_html( $error ) . '</code></p>';
		echo '<p><a class="button" href="' . esc_url( $retry ) . '">' . esc_html__( 'Retry OAuth key generation', 'stonewright' ) . '</a></p></div>';
	}

	public static function handle_retry(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'stonewright' ), 403 );
		}
		check_admin_referer( self::RETRY_ACTION );
		self::ensure();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * OpenSSL configuration files to try, in order, when generation fails with
	 * the process default. Only readable files are returned.
	 *
	 * @return list<string>
	 */
	public static function openssl_config_candidates(): array {
		$candidates = [];
		$env        = getenv( 'OPENSSL_CONF' );
		if ( is_string( $env ) && '' !== $env ) {
			$candidates[] = $env;
		}
		if ( '' !== PHP_BINARY ) {
			// Windows PHP builds ship a config next to php.exe.
			$candidates[] = dirname( PHP_BINARY ) . '/extras/ssl/openssl.cnf';
		}
		$candidates[] = dirname( __DIR__, 2 ) . '/data/openssl/openssl.cnf';

		$readable = [];
		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) && is_readable( $candidate ) && ! in_array( $candidate, $readable, true ) ) {
				$readable[] = $candidate;
			}
		}
		return $readable;
	}

	/**
	 * Return persisted OAuth keys, generating them atomically on first use.
	 *
	 * @return array{private:CryptKey,public:CryptKey,encryption:string}
	 * @throws \RuntimeException When OpenSSL fails or key persistence is unavailable.
	 */
	public static function get(): array {
		$private = (string) get_option( self::PRIVATE_KEY_OPTION, '' );
		if ( '' === $private ) {
			add_option( self::PRIVATE_KEY_OPTION, self::generate_private_key(), '', false );
			delete_option( self::ERROR_OPTION );
			$private = (string) get_option( self::PRIVATE_KEY_OPTION, '' );
		}
		if ( '' === $private ) {
			throw new \RuntimeException( 'Failed to persist OAuth private key.' );
		}

		$encryption = (string) get_option( self::ENCRYPTION_KEY_OPTION, '' );
		if ( '' === $encryption ) {
			add_option( self::ENCRYPTION_KEY_OPTION, base64_encode( random_bytes( 32 ) ), '', false );
			$encryption = (string) get_option( self::ENCRYPTION_KEY_OPTION, '' );
		}
		if ( '' === $encryption ) {
			throw new \RuntimeException( 'Failed to persist OAuth encryption key.' );
		}

		return [
			'private'    => new CryptKey( $private, null, false ),
			'public'     => new CryptKey( self::derive_public_key( $private ), null, false ),
			'encryption' => $encryption,
		];
	}

	private static function generate_private_key(): string {
		$errors = [];
		// First the process default, then explicit configs: PHP on Windows
		// looks for openssl.cnf in a build-time path that rarely exists.
		foreach ( array_merge( [ null ], self::openssl_config_candidates() ) as $config ) {
			$private = self::try_generate( $config, $errors );
			if ( null !== $private ) {
				return $private;
			}
		}

		throw new \RuntimeException(
			self::bounded_message( 'openssl_pkey_new failed: ' . ( [] !== $errors ? implode( '; ', array_unique( $errors ) ) : 'no OpenSSL error reported' ) )
		);
	}

	/**
	 * @param list<string> $errors Collected OpenSSL diagnostics.
	 */
	private static function try_generate( ?string $config, array &$errors ): ?string {
		$options = [
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		];
		if ( null !== $config ) {
			$options['config'] = $config;
		}

		self::drain_openssl_errors();
		$resource = openssl_pkey_new( $options );
		$private  = '';
		if ( false !== $resource && openssl_pkey_export( $resource, $private, null, $options ) && '' !== $private ) {
			self::drain_openssl_errors();
			return $private;
		}

		foreach ( self::drain_openssl_errors() as $error ) {
			$errors[] = $error;
		}
		return null;
	}

	/**
	 * @return list<string>
	 */
	private static function drain_openssl_errors(): array {
		$errors = [];
		for ( $i = 0; $i < 32; $i++ ) {
			$error = openssl_error_string();
			if ( false === $error ) {
				break;
			}
			$errors[] = $error;
		}
		return $errors;
	}

	private static function bounded_message( string $message ): string {
		$message = trim( (string) preg_replace( '/\s+/', ' ', $message ) );
		return strlen( $message ) > 500 ? substr( $message, 0, 497 ) . '...' : $message;
	}

	private static function derive_public_key( string $private_pem ): string {
		$key = openssl_pkey_get_private( $private_pem );
		if ( false === $key ) {
			throw new \RuntimeException( 'openssl_pkey_get_private failed.' );
		}

		$details = openssl_pkey_get_details( $key );
		if ( false === $details || ! isset( $details['key'] ) ) {
			throw new \RuntimeException( 'openssl_pkey_get_details failed.' );
		}

		return (string) $details['key'];
	}
}
