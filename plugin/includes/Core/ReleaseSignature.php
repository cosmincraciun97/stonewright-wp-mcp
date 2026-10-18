<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Ed25519 signature check for the SHA256SUMS.txt of a Stonewright release.
 *
 * The release workflow signs the exact bytes of SHA256SUMS.txt with the
 * maintainer's release key and publishes the detached signature next to it as
 * SHA256SUMS.txt.sig: one base64 signature per line. A manifest is trusted when
 * one of those signatures verifies against one of the public keys this plugin
 * version ships. Lines that are not a signature are skipped, so a later release
 * can add lines (a second signature during a key change, for example) without
 * breaking the versions already installed.
 */
final class ReleaseSignature {

	/** Detached signature published next to SHA256SUMS.txt in every signed release. */
	public const ASSET_NAME = 'SHA256SUMS.txt.sig';

	/**
	 * Ed25519 public keys trusted to sign SHA256SUMS.txt, base64 encoded (32
	 * bytes each): the active release key first, then the backup key kept offline.
	 *
	 * Because this version ships keys, it accepts an update only with a valid
	 * signature by one of them, and the release workflow signs every release and
	 * stops when it cannot. With an empty list the updater would compare the
	 * package only with the SHA-256 in its release's SHA256SUMS.txt. A site checks
	 * an update against the keys of the version it runs, so change this list only
	 * as docs/releases/checklist.md#release-signing describes. The release scripts
	 * (scripts/release-signature.mjs) read this list: keep one quoted key per line.
	 *
	 * @var list<string>
	 */
	public const PUBLIC_KEYS = [
		'5v6EcHKVuTu3PCZeDIY/tU7QEyQlUqUp+iUFjosoEfg=', // Active release key.
		'qv/qsboB9h4x8cmQHolEBrQWdwfBl2UEE4dWu0ndCLc=', // Backup key, kept offline.
	];

	/** Largest SHA256SUMS.txt.sig accepted; one signature line takes 89 bytes. */
	public const MAX_SIGNATURE_FILE_BYTES = 4096;

	/** Signature lines checked at most, so a crafted file cannot cost many verifications. */
	private const MAX_SIGNATURES = 4;

	private const PUBLIC_KEY_BYTES = 32;

	private const SIGNATURE_BYTES = 64;

	/**
	 * Verify the detached signature of a checksum manifest.
	 *
	 * @param string       $manifest       Exact bytes of SHA256SUMS.txt.
	 * @param string       $signature_file Contents of SHA256SUMS.txt.sig.
	 * @param list<string> $public_keys    Trusted base64 Ed25519 public keys.
	 * @return true|\WP_Error
	 */
	public static function verify( string $manifest, string $signature_file, array $public_keys ): bool|\WP_Error {
		$keys = self::decode_public_keys( $public_keys );
		if ( [] === $keys ) {
			return new \WP_Error( 'stonewright_update_signing_keys_invalid', __( 'This Stonewright version carries no valid release signing key, so the update package cannot be verified. The update was stopped.', 'stonewright' ) );
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new \WP_Error( 'stonewright_update_signature_unsupported', __( 'This server cannot check signatures, so the Stonewright update package cannot be verified. The update was stopped.', 'stonewright' ) );
		}
		$signatures = strlen( $signature_file ) > self::MAX_SIGNATURE_FILE_BYTES ? [] : self::decode_signatures( $signature_file );
		if ( [] === $signatures ) {
			return new \WP_Error( 'stonewright_update_signature_malformed', __( 'The signature of the Stonewright release is malformed. The update was stopped.', 'stonewright' ) );
		}

		foreach ( $signatures as $signature ) {
			foreach ( $keys as $key ) {
				try {
					if ( sodium_crypto_sign_verify_detached( $signature, $manifest, $key ) ) {
						return true;
					}
				} catch ( \Throwable $error ) {
					// The lengths are checked above; any other refusal counts as a failed pair.
					unset( $error );
				}
			}
		}

		return new \WP_Error( 'stonewright_update_signature_invalid', __( 'The signature of the Stonewright release does not match a trusted release key. The update was stopped.', 'stonewright' ) );
	}

	/**
	 * Raw keys, without duplicates. A malformed entry is skipped; a build check
	 * keeps the shipped list valid.
	 *
	 * @param array<mixed> $public_keys
	 * @return list<string>
	 */
	private static function decode_public_keys( array $public_keys ): array {
		$keys = [];
		foreach ( $public_keys as $key ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '#^[A-Za-z0-9+/]{43}=$#', $key ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a public key, not code.
			$raw = base64_decode( $key, true );
			if ( is_string( $raw ) && self::PUBLIC_KEY_BYTES === strlen( $raw ) ) {
				$keys[ $raw ] = $raw;
			}
		}
		return array_values( $keys );
	}

	/**
	 * Raw signatures from the lines that hold one base64 Ed25519 signature.
	 *
	 * @return list<string>
	 */
	private static function decode_signatures( string $signature_file ): array {
		$signatures = [];
		foreach ( preg_split( '/\r?\n/', $signature_file ) ?: [] as $line ) {
			$line = trim( $line );
			if ( 1 !== preg_match( '#^[A-Za-z0-9+/]{86}==$#', $line ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a signature, not code.
			$raw = base64_decode( $line, true );
			if ( ! is_string( $raw ) || self::SIGNATURE_BYTES !== strlen( $raw ) ) {
				continue;
			}
			$signatures[] = $raw;
			if ( count( $signatures ) >= self::MAX_SIGNATURES ) {
				break;
			}
		}
		return $signatures;
	}
}
