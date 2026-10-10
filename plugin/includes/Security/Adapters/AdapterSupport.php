<?php
/**
 * What the option, menu and widget adapters share: recording in the ledger without ever failing a write.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Support\Logger;

/**
 * The methods record() and settle() put a row in the ledger and close it. Both swallow every failure and say so in the
 * log, so that a ledger that cannot record never changes a write. The methods same() and has_mask() are the comparisons
 * a restore uses to read a resource back and to refuse an image that had a credential masked out of it.
 */
final class AdapterSupport {

	/** @var bool|null Whether the ledger table is usable; only a yes is remembered. */
	private static ?bool $ledger_ready = null;

	public static function reset_for_tests(): void {
		self::$ledger_ready = null;
	}

	public static function ledger_ready(): bool {
		if ( true === self::$ledger_ready ) {
			return true;
		}
		if ( ChangeLedger::table_schema_ok() ) {
			self::$ledger_ready = true;
			return true;
		}
		return false;
	}

	/**
	 * Record a change. The spec is the one ChangeLedger::record() takes.
	 *
	 * @param array<string, mixed> $spec
	 * @return string The change id, or '' when nothing was recorded.
	 */
	public static function record( array $spec ): string {
		try {
			if ( ! self::ledger_ready() ) {
				return '';
			}
			$row = ChangeLedger::record( $spec );
			if ( $row instanceof \WP_Error ) {
				Logger::warning(
					'change_ledger_record_failed',
					[
						'code'    => $row->get_error_code(),
						'ability' => isset( $spec['ability'] ) && is_string( $spec['ability'] ) ? $spec['ability'] : '',
					]
				);
				return '';
			}
			return (string) $row['change_id'];
		} catch ( \Throwable $failure ) {
			Logger::warning(
				'change_ledger_record_failed',
				[
					'error'   => $failure::class,
					'ability' => isset( $spec['ability'] ) && is_string( $spec['ability'] ) ? $spec['ability'] : '',
				]
			);
			return '';
		}
	}

	/**
	 * Close a recorded row with its status and, when the write left one, the image it produced.
	 *
	 * @param array<string, mixed>|null $after
	 */
	public static function settle( string $change_id, ?array $after, string $status ): bool {
		try {
			$result = [ 'status' => $status ];
			if ( null !== $after ) {
				$result['after'] = $after;
			}
			$row = ChangeLedger::settle( $change_id, $result );
			if ( $row instanceof \WP_Error ) {
				Logger::warning( 'change_ledger_settle_failed', [ 'code' => $row->get_error_code() ] );
				return false;
			}
			return true;
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_settle_failed', [ 'error' => $failure::class ] );
			return false;
		}
	}

	/** Whether two values are equal as the ledger would store them: key order and number types do not count. */
	public static function same( mixed $a, mixed $b ): bool {
		return self::canonical( $a ) === self::canonical( $b );
	}

	/**
	 * Whether any string in a value is a mask that the ledger put there.
	 */
	public static function has_mask( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return ChangeImage::MASK === $value || str_contains( $value, '[masked line ' ) || str_contains( $value, '[masked private key]' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_mask( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function canonical( mixed $value ): string {
		$json = json_encode( self::sorted( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return is_string( $json ) ? $json : '';
	}

	private static function sorted( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sorted( $item );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}
}
