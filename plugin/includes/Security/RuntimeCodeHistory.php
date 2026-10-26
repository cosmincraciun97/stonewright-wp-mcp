<?php
/**
 * A redacted, size-capped copy of the code php-execute ran, for the audit log to point at.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Logger;

/**
 * The audit log keeps only the sha256 of the code a php-execute call ran, because the code itself may hold
 * secrets. That is enough to say two runs were the same and too little to say what one did. This class keeps a
 * copy of the code with its secret values masked (SecretRedactor), cut at MAX_BYTES, in a BlobStore under
 * uploads/stonewright-state/runtime/, and the audit row carries the reference. The folder is the store's own, not
 * the change ledger's blobs folder, so the ledger retention never counts a code blob as an orphan.
 *
 * A blob is named after the sha256 of the copy that was stored, so the same code run again shares one file, and
 * running it again refreshes the age retention reads. The daily audit job deletes the blobs older than the audit
 * retention window; with no window set, the audit rows stay and so do the copies, up to MAX_TOTAL_BYTES.
 *
 * An administrator turns the copy off with the setting OPTION on Setup > Settings, or with the constant
 * STONEWRIGHT_PHP_EXECUTE_KEEP_CODE set to false in wp-config.php. Keeping a copy never stops a snippet: a store
 * that cannot be written leaves the row with the hash alone.
 */
final class RuntimeCodeHistory {

	/** The most bytes of code one copy holds. Longer code is cut and the row says so. */
	public const MAX_BYTES = 262144;

	/** The most bytes all copies hold together, compressed. A full folder keeps no new copy. */
	public const MAX_TOTAL_BYTES = 52428800;

	/** The setting that keeps the copy; on unless an administrator turns it off. */
	public const OPTION = 'stonewright_php_execute_keep_code';

	/** Defined as false in wp-config.php to turn the copy off for the site, whatever the setting says. */
	public const CONSTANT = 'STONEWRIGHT_PHP_EXECUTE_KEEP_CODE';

	private static ?BlobStore $store_override = null;

	/** Replace the store for a test; null goes back to the default folder. */
	public static function use_blob_store_for_tests( ?BlobStore $store ): void {
		self::$store_override = $store;
	}

	/** The store of the copies, or null when the uploads folder cannot be used. */
	public static function blob_store(): ?BlobStore {
		return self::$store_override ?? BlobStore::default( self::MAX_TOTAL_BYTES, BlobStore::RUNTIME_DIRECTORY );
	}

	/** Whether wp-config.php turns the copy off for the site, whatever the setting says. */
	public static function forced_off(): bool {
		return defined( self::CONSTANT ) && ! filter_var( constant( self::CONSTANT ), FILTER_VALIDATE_BOOLEAN );
	}

	/** Whether the setting is on; it is until an administrator turns it off. */
	public static function setting_on(): bool {
		return (bool) get_option( self::OPTION, true );
	}

	/** Whether a copy is kept: the setting is on and the constant is not false. */
	public static function enabled(): bool {
		return ! self::forced_off() && self::setting_on();
	}

	/**
	 * Keep the code with its secrets masked.
	 *
	 * The whole code is masked first and the copy is cut afterwards, so a secret that straddles the cut is not left
	 * half in the copy.
	 *
	 * @return array{ref:string,bytes:int,truncated:bool}|null ref is the sha256 of the stored copy; bytes is the
	 *         length of the code that ran, and truncated says the copy is shorter than that. Null when the copy is
	 *         off or could not be stored.
	 */
	public static function keep( string $code ): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		try {
			$store = self::blob_store();
			if ( null === $store ) {
				return null;
			}
			$bytes    = strlen( $code );
			$redacted = SecretRedactor::redact( $code );
			$copy     = strlen( $redacted ) > self::MAX_BYTES ? mb_strcut( $redacted, 0, self::MAX_BYTES, 'UTF-8' ) : $redacted;
			$stored   = $store->put( $copy );
			if ( $stored instanceof \WP_Error ) {
				Logger::warning( 'runtime_code_not_kept', [ 'code' => $stored->get_error_code() ] );
				return null;
			}
			return [
				'ref'       => $stored['sha256'],
				'bytes'     => $bytes,
				'truncated' => strlen( $redacted ) > self::MAX_BYTES,
			];
		} catch ( \Throwable $throwable ) {
			Logger::warning( 'runtime_code_not_kept', [ 'error' => $throwable->getMessage() ] );
			return null;
		}
	}

	/**
	 * The kept copy a reference names, for an administrator.
	 *
	 * @return string|null Null for anyone who cannot manage options, a reference that is not a sha256, and a copy
	 *         that is gone, changed or unreadable.
	 */
	public static function read( string $ref ): ?string {
		if ( ! current_user_can( 'manage_options' ) || ! BlobStore::is_valid_hash( $ref ) ) {
			return null;
		}
		$store = self::blob_store();
		if ( null === $store ) {
			return null;
		}
		$text = $store->get( $ref );
		return is_string( $text ) ? $text : null;
	}

	/**
	 * Delete the copies not kept or run again within the audit retention window. With no window set nothing is
	 * deleted, as no audit row is.
	 *
	 * @return int Blobs deleted. It never throws: a failure here must not stop the daily audit job.
	 */
	public static function prune_expired(): int {
		try {
			$days = AuditLog::retention_days();
			if ( $days < 1 ) {
				return 0;
			}
			$store = self::blob_store();
			if ( null === $store ) {
				return 0;
			}
			$swept = $store->sweep( [], $days * DAY_IN_SECONDS );
			return $swept['deleted'];
		} catch ( \Throwable $throwable ) {
			Logger::warning( 'runtime_code_prune_failed', [ 'error' => $throwable->getMessage() ] );
			return 0;
		}
	}
}
