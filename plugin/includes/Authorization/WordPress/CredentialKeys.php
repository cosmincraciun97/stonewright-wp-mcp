<?php
/**
 * Signing and encryption key lifecycle.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Support\Logger;

/**
 * Owns the two key options: an RSA-2048 private key (PKCS#8 PEM) that signs access
 * credentials, and an encryption key (base64 of 32 random bytes) that seals codes and
 * refresh credentials.
 *
 * - A stored key is never overwritten. Creation inserts the option row and lets the
 *   unique option name decide between concurrent activations; every process then reads
 *   the stored winner. An empty stored value is filled once.
 * - Creation never throws. Key generation tries the process default OpenSSL
 *   configuration, then OPENSSL_CONF, then extras/ssl/openssl.cnf next to the PHP
 *   binary, then the configuration bundled in data/openssl. A failure is kept,
 *   bounded, in stonewright_oauth_key_error for the admin notice; Application
 *   Passwords do not depend on these keys.
 * - Options are written with autoload off.
 */
final class CredentialKeys {

	public const PRIVATE_KEY_OPTION = 'stonewright_oauth_private_key';
	public const ENCRYPTION_KEY_OPTION = 'stonewright_oauth_encryption_key';
	public const ERROR_OPTION = 'stonewright_oauth_key_error';
	public const ERROR_LIMIT = 500;
	public const RSA_BITS = 2048;

	/** @var \Closure(?string): array{0: ?string, 1: string} */
	private \Closure $rsa;

	/** @var \Closure(int): string */
	private \Closure $random;

	/** @var list<?string> */
	private array $configurations;

	/** @var array<string, string> sha256 of a private key => its public key. */
	private array $public_keys = [];

	/** @var array<string, bool> sha256 of a private key => whether it is usable. */
	private static array $usable = [];

	/**
	 * @param (\Closure(?string): array{0: ?string, 1: string})|null $rsa One generation attempt with an optional configuration file.
	 * @param (\Closure(int): string)|null                          $random Cryptographically secure random bytes.
	 * @param list<?string>|null                                    $configurations Ordered configuration candidates.
	 */
	public function __construct( private Database $db, ?\Closure $rsa = null, ?\Closure $random = null, ?array $configurations = null ) {
		$this->rsa = $rsa ?? static fn ( ?string $configuration ): array => self::openssl_attempt( $configuration );
		$this->random = $random ?? static fn ( int $length ): string => random_bytes( $length );
		$this->configurations = $configurations ?? self::default_configurations();
	}

	/** Create whichever key is missing; true when both keys exist and are usable. Never throws. */
	public function ensure(): bool {
		$problems = [];
		$private = $this->ensure_key(
			self::PRIVATE_KEY_OPTION,
			function () use ( &$problems ): ?string {
				[ $pem, $notes ] = self::generate_rsa( $this->configurations, $this->rsa );
				if ( null === $pem ) {
					$problems[] = 'OpenSSL could not create the signing key (' . ( [] === $notes ? 'no configuration worked' : implode( '; ', $notes ) ) . ').';
				}
				return $pem;
			},
			$problems
		);
		if ( null !== $private && ! self::usable_private_key( $private ) ) {
			$problems[] = 'The stored signing key is unusable and was left in place.';
		}
		$encryption = $this->ensure_key(
			self::ENCRYPTION_KEY_OPTION,
			function (): string {
				$bytes = ( $this->random )( 32 );
				if ( 32 !== strlen( $bytes ) ) {
					throw new \RuntimeException( 'Short random read.' );
				}
				return base64_encode( $bytes );
			},
			$problems
		);
		if ( null !== $encryption && ! self::usable_encryption_key( $encryption ) ) {
			$problems[] = 'The stored encryption key is unusable and was left in place.';
		}
		if ( [] === $problems && null !== $private && null !== $encryption ) {
			delete_option( self::ERROR_OPTION );
			return true;
		}
		$this->record( [] === $problems ? [ 'The OAuth keys could not be stored.' ] : $problems );
		return false;
	}

	public function ready(): bool {
		return null !== $this->private_key() && null !== $this->encryption_key();
	}

	/** The stored signing key when it is a usable RSA key; never generated here. */
	public function private_key(): ?string {
		$value = get_option( self::PRIVATE_KEY_OPTION, '' );
		return is_string( $value ) && self::usable_private_key( $value ) ? $value : null;
	}

	/** Public key computed from the stored private key. */
	public function public_key(): ?string {
		$private = $this->private_key();
		if ( null === $private ) {
			return null;
		}
		$fingerprint = hash( 'sha256', $private );
		if ( ! isset( $this->public_keys[ $fingerprint ] ) ) {
			$key = openssl_pkey_get_private( $private );
			$details = false === $key ? false : openssl_pkey_get_details( $key );
			if ( ! is_array( $details ) || ! isset( $details['key'] ) || ! is_string( $details['key'] ) ) {
				return null;
			}
			$this->public_keys[ $fingerprint ] = $details['key'];
		}
		return $this->public_keys[ $fingerprint ];
	}

	/** The stored encryption key string, used as the payload encryption password. */
	public function encryption_key(): ?string {
		$value = get_option( self::ENCRYPTION_KEY_OPTION, '' );
		return is_string( $value ) && self::usable_encryption_key( $value ) ? $value : null;
	}

	public function error(): ?string {
		$value = get_option( self::ERROR_OPTION, '' );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Try each configuration in order until one produces a usable key.
	 *
	 * @param list<?string>                                          $configurations
	 * @param (\Closure(?string): array{0: ?string, 1: string})|null $attempt
	 * @return array{0: ?string, 1: list<string>} The PEM (or null) and one note per failed attempt.
	 */
	public static function generate_rsa( array $configurations, ?\Closure $attempt = null ): array {
		$attempt ??= static fn ( ?string $configuration ): array => self::openssl_attempt( $configuration );
		$notes = [];
		foreach ( $configurations as $configuration ) {
			try {
				[ $pem, $note ] = $attempt( $configuration );
			} catch ( \Throwable $error ) {
				$pem = null;
				$note = 'generation raised ' . get_class( $error );
			}
			if ( is_string( $pem ) && self::usable_private_key( $pem ) ) {
				return [ $pem, $notes ];
			}
			$notes[] = ( null === $configuration ? 'default configuration' : $configuration ) . ': ' . ( '' === $note ? 'no usable key' : $note );
		}
		return [ null, $notes ];
	}

	/**
	 * Ordered candidates: the process default (null), OPENSSL_CONF, extras/ssl/openssl.cnf
	 * beside the PHP binary, then the bundled file. Unreadable or repeated paths are skipped.
	 *
	 * @param (\Closure(string): bool)|null $readable
	 * @return list<?string>
	 */
	public static function configuration_candidates( ?string $environment, string $php_binary, string $bundled, ?\Closure $readable = null ): array {
		$readable ??= static fn ( string $path ): bool => is_readable( $path );
		$paths = [];
		if ( null !== $environment && '' !== $environment ) {
			$paths[] = $environment;
		}
		if ( '' !== $php_binary ) {
			$paths[] = dirname( $php_binary ) . '/extras/ssl/openssl.cnf';
		}
		$paths[] = $bundled;
		$candidates = [ null ];
		foreach ( $paths as $path ) {
			if ( ! in_array( $path, $candidates, true ) && $readable( $path ) ) {
				$candidates[] = $path;
			}
		}
		return $candidates;
	}

	/** @return list<?string> */
	public static function default_configurations(): array {
		$environment = getenv( 'OPENSSL_CONF' );
		$plugin = defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) : dirname( __DIR__, 3 );
		return self::configuration_candidates( is_string( $environment ) ? $environment : null, PHP_BINARY, rtrim( $plugin, '/\\' ) . '/data/openssl/openssl.cnf' );
	}

	/**
	 * One OpenSSL generation and PKCS#8 export with an optional configuration file.
	 *
	 * @return array{0: ?string, 1: string} The PEM or null, plus the OpenSSL errors.
	 */
	public static function openssl_attempt( ?string $configuration ): array {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return [ null, 'the OpenSSL extension is not loaded' ];
		}
		$options = null === $configuration ? [] : [ 'config' => $configuration ];
		self::openssl_errors();
		$key = @openssl_pkey_new( $options + [ 'private_key_bits' => self::RSA_BITS, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		if ( false === $key ) {
			return [ null, self::openssl_errors() ];
		}
		$pem = '';
		if ( ! @openssl_pkey_export( $key, $pem, null, $options ) || ! is_string( $pem ) || '' === $pem ) {
			return [ null, self::openssl_errors() ];
		}
		self::openssl_errors();
		return [ $pem, '' ];
	}

	/**
	 * Return the existing value of a key option, or create it once.
	 *
	 * @param \Closure(): ?string $generate
	 * @param list<string>        $problems
	 */
	private function ensure_key( string $option, \Closure $generate, array &$problems ): ?string {
		try {
			$stored = $this->stored( $option );
			if ( null !== $stored && '' !== $stored ) {
				return $stored;
			}
			$value = $generate();
			if ( null === $value ) {
				return null;
			}
			$inserted = null === $stored && $this->db->insert(
				$this->db->options_table(),
				[
					'option_name'  => $option,
					'option_value' => $value,
					'autoload'     => 'off',
				]
			);
			if ( ! $inserted ) {
				// The row exists: fill it only while it is still empty, never replace a key.
				$this->db->execute( 'UPDATE ' . $this->db->options_table() . " SET option_value = %s WHERE option_name = %s AND option_value = ''", [ $value, $option ] );
			}
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$final = $this->stored( $option );
			if ( null === $final || '' === $final ) {
				$problems[] = 'The ' . self::label( $option ) . ' could not be stored.';
				return null;
			}
			return $final;
		} catch ( \Throwable $error ) {
			$problems[] = 'Creating the ' . self::label( $option ) . ' failed (' . get_class( $error ) . ').';
			return null;
		}
	}

	/** Raw stored value read past the object cache; null when the option row is absent. */
	private function stored( string $option ): ?string {
		return $this->db->value( 'SELECT option_value FROM ' . $this->db->options_table() . ' WHERE option_name = %s LIMIT 1', [ $option ] );
	}

	/** @param list<string> $problems */
	private function record( array $problems ): void {
		$message = (string) preg_replace( '/[^\x20-\x7e]/', '?', implode( ' ', $problems ) );
		if ( strlen( $message ) > self::ERROR_LIMIT ) {
			$message = substr( $message, 0, self::ERROR_LIMIT - 3 ) . '...';
		}
		update_option( self::ERROR_OPTION, $message, false );
		Logger::warning( 'oauth_key_setup_failed', [ 'problems' => count( $problems ) ] );
	}

	private static function label( string $option ): string {
		return self::PRIVATE_KEY_OPTION === $option ? 'signing key' : 'encryption key';
	}

	private static function usable_private_key( string $pem ): bool {
		if ( ! str_contains( $pem, '-----BEGIN' ) ) {
			return false;
		}
		$fingerprint = hash( 'sha256', $pem );
		if ( ! isset( self::$usable[ $fingerprint ] ) ) {
			$key = @openssl_pkey_get_private( $pem );
			$details = false === $key ? false : openssl_pkey_get_details( $key );
			self::$usable[ $fingerprint ] = is_array( $details ) && OPENSSL_KEYTYPE_RSA === ( $details['type'] ?? null ) && (int) ( $details['bits'] ?? 0 ) >= self::RSA_BITS;
			self::openssl_errors();
		}
		return self::$usable[ $fingerprint ];
	}

	private static function usable_encryption_key( string $value ): bool {
		return '' !== trim( $value );
	}

	/** Drain the OpenSSL error queue and return a short summary. */
	private static function openssl_errors(): string {
		$errors = [];
		for ( $index = 0; $index < 64; $index++ ) {
			$message = openssl_error_string();
			if ( false === $message ) {
				break;
			}
			$errors[ $message ] = true;
		}
		return implode( ' | ', array_slice( array_keys( $errors ), -3 ) );
	}
}
