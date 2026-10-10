<?php
/**
 * Database access for the authorization adapters.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * Thin wrapper over one wpdb connection: prepared reads, writes that throw on
 * refusal, explicit transactions, and the UTC datetime and JSON forms the OAuth
 * tables use. Every statement passes its values through wpdb::prepare(); only
 * internal table names are interpolated.
 */
final class Database {

	private bool $in_transaction = false;

	public function __construct( private \wpdb $wpdb ) {}

	public static function wordpress(): self {
		global $wpdb;
		return new self( $wpdb );
	}

	public function connection(): \wpdb {
		return $this->wpdb;
	}

	/** Full name of one OAuth table, for example "clients" or "refresh_tokens". */
	public function table( string $name ): string {
		return $this->wpdb->prefix . 'stonewright_oauth_' . $name;
	}

	public function options_table(): string {
		return (string) $this->wpdb->options;
	}

	public function charset_collate(): string {
		return (string) $this->wpdb->get_charset_collate();
	}

	/**
	 * @param list<int|string|null> $args
	 * @return array<string, mixed>|null
	 */
	public function row( string $sql, array $args = [] ): ?array {
		$row = $this->wpdb->get_row( $this->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare() below.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param list<int|string|null> $args
	 * @return list<array<string, mixed>>
	 */
	public function rows( string $sql, array $args = [] ): array {
		$rows = $this->wpdb->get_results( $this->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare() below.
		if ( ! is_array( $rows ) ) {
			return [];
		}
		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * First column of every result row.
	 *
	 * @param list<int|string|null> $args
	 * @return list<string>
	 */
	public function column( string $sql, array $args = [] ): array {
		$values = $this->wpdb->get_col( $this->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare() below.
		if ( ! is_array( $values ) ) {
			return [];
		}
		return array_values( array_map( 'strval', array_filter( $values, static fn ( $value ): bool => null !== $value ) ) );
	}

	/** @param list<int|string|null> $args */
	public function value( string $sql, array $args = [] ): ?string {
		$value = $this->wpdb->get_var( $this->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare() below.
		return null === $value ? null : (string) $value;
	}

	/**
	 * Run one write and return how many rows it changed.
	 *
	 * @param list<int|string|null> $args
	 * @throws StorageFailure When the database refuses the statement.
	 */
	public function execute( string $sql, array $args = [] ): int {
		$result = $this->wpdb->query( $this->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare() below.
		if ( false === $result ) {
			throw new StorageFailure( 'The authorization store refused a statement.' );
		}
		return (int) $result;
	}

	/**
	 * Run a compare-and-swap claim: the number of rows it changed, or null when the
	 * database refused it (a busy or locked database, or a deadlock victim). Callers
	 * treat both 0 and null as a lost claim and decide again on fresh state.
	 *
	 * @param list<int|string|null> $args
	 */
	public function claim( string $sql, array $args ): ?int {
		$suppressed = $this->wpdb->suppress_errors( true );
		try {
			$result = $this->wpdb->query( $this->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare() below.
		} finally {
			$this->wpdb->suppress_errors( $suppressed );
		}
		return false === $result ? null : (int) $result;
	}

	/**
	 * Insert one row; false when the database refuses it, for example on a duplicate key.
	 * The expected refusal is not reported as a database error.
	 *
	 * @param array<string, int|string|null> $data
	 */
	public function insert( string $table, array $data ): bool {
		$suppressed = $this->wpdb->suppress_errors( true );
		try {
			return 1 === $this->wpdb->insert( $table, $data );
		} finally {
			$this->wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * @param array<string, int|string|null> $data
	 * @throws StorageFailure When the database refuses the row.
	 */
	public function insert_or_fail( string $table, array $data ): void {
		if ( 1 !== $this->wpdb->insert( $table, $data ) ) {
			throw new StorageFailure( 'The authorization store refused a new row.' );
		}
	}

	/**
	 * @throws \LogicException When a transaction is already open on this connection.
	 * @throws StorageFailure When the database cannot start one.
	 */
	public function begin(): void {
		if ( $this->in_transaction ) {
			throw new \LogicException( 'An authorization transaction is already open.' );
		}
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			throw new StorageFailure( 'The authorization store could not start a transaction.' );
		}
		$this->in_transaction = true;
	}

	/** @throws StorageFailure When the commit is refused; the transaction is then rolled back. */
	public function commit(): void {
		if ( ! $this->in_transaction ) {
			return;
		}
		$this->in_transaction = false;
		if ( false === $this->wpdb->query( 'COMMIT' ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw new StorageFailure( 'The authorization store could not commit.' );
		}
	}

	public function rollback(): void {
		if ( ! $this->in_transaction ) {
			return;
		}
		$this->in_transaction = false;
		$this->wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Run $work in one transaction; any failure rolls everything back and is rethrown.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 * @throws \Throwable Whatever $work or the commit raised, after the rollback.
	 */
	public function transaction( callable $work ): mixed {
		$this->begin();
		try {
			$result = $work();
			$this->commit();
			return $result;
		} catch ( \Throwable $error ) {
			$this->rollback();
			throw $error;
		}
	}

	/** UTC "Y-m-d H:i:s", the form every OAuth datetime column holds. */
	public static function datetime( int $epoch ): string {
		return gmdate( 'Y-m-d H:i:s', $epoch );
	}

	/** Epoch seconds of a stored UTC datetime, or null for an empty or zero value. */
	public static function epoch( mixed $value ): ?int {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			return null;
		}
		$epoch = $date->getTimestamp();
		return $epoch > 0 ? $epoch : null;
	}

	/**
	 * JSON for list columns, with unescaped slashes as the stored rows use.
	 *
	 * @param array<int|string, mixed> $value
	 * @throws StorageFailure When the value cannot be encoded.
	 */
	public static function json( array $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			throw new StorageFailure( 'A value could not be encoded for storage.' );
		}
		return $encoded;
	}

	/**
	 * A stored JSON list of strings, or null when the column holds anything else.
	 *
	 * @return list<string>|null
	 */
	public static function string_list( mixed $json ): ?array {
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$value = json_decode( $json, true );
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return null;
			}
		}
		return $value;
	}

	/** @param list<int|string|null> $args */
	private function prepare( string $sql, array $args ): string {
		if ( [] === $args ) {
			return $sql;
		}
		// Callers pass constant SQL with placeholders; only internal table names are interpolated.
		return (string) $this->wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant SQL with placeholders.
	}
}
