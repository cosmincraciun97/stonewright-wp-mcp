<?php
/**
 * History of the changes Stonewright makes, with the images needed to see and undo them.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Logger;

/**
 * One row per change in {prefix}stonewright_changes, and the before and after images of the change in
 * BlobStore. The ledger is a library: an adapter calls record() before a write, with the image of the
 * resource as it is, and settle() after it, with the image the write produced and the final status.
 * get(), list(), count(), chain() and children() read the history, and read_image() reads an image back
 * through the integrity check of the blob store.
 *
 * It keeps history, not secrets. Every image goes through ChangeImage first: a wp-config file, keys and
 * salts, passwords, application passwords, OAuth tokens and keys, and options on the secret list are
 * never stored, and any other credential in an image is masked. A row for such a change still exists,
 * without the image, and says in restorable_reason why it cannot be restored. Retention (age, count and
 * size, daily) is ChangeLedgerRetention.
 *
 * Statuses follow the change journal: a row is armed when it is recorded and moves to verified, failed,
 * rolled_back, rollback_failed, probe_unavailable or incident. rolled_back_by marks a change that a
 * later rollback row undid. A rollback or a redo is a row of its own whose parent_id is the row it acts on.
 *
 * Schema versions: SCHEMA_VERSION is raised whenever COLUMNS or the CREATE TABLE statement gains a column
 * or a key. maybe_install_table() then runs dbDelta, which adds what is missing and keeps every row.
 */
final class ChangeLedger {

	public const TABLE = 'stonewright_changes';

	public const SCHEMA_VERSION = 1;

	public const SCHEMA_OPTION = 'stonewright_changes_schema_version';

	/** Columns of the table, in the order a row is returned. */
	public const COLUMNS = [
		'id',
		'change_id',
		'parent_id',
		'kind',
		'change_set_id',
		'ability',
		'actor',
		'client',
		'family',
		'resource_type',
		'resource_id',
		'status',
		'before_ref',
		'before_sha256',
		'before_bytes',
		'after_ref',
		'after_sha256',
		'after_bytes',
		'restorable',
		'restorable_reason',
		'summary',
		'created_at',
		'settled_at',
	];

	public const FAMILIES = [
		'post',
		'elementor',
		'gutenberg',
		'fse',
		'global_styles',
		'theme_file',
		'custom_code',
		'sandbox',
		'option',
		'menu',
		'widget',
		'user',
		'media',
		'plugin',
		'skill',
		'design_direction',
		'woocommerce',
		'comment',
		'memory',
		'other',
	];

	public const KINDS = [ 'change', 'rollback', 'redo', 'restore_point' ];

	public const STATUSES = [ 'armed', 'verified', 'failed', 'rolled_back', 'rolled_back_by', 'rollback_failed', 'probe_unavailable', 'incident' ];

	/** Statuses of a change that is not finished: retention never deletes it. */
	public const OPEN_STATUSES = [ 'armed', 'incident', 'rollback_failed' ];

	public const DEFAULT_PER_PAGE = 25;

	public const MAX_PER_PAGE = 100;

	/** Most rows chain() follows. */
	public const MAX_CHAIN = 50;

	/** Longest resource id kept as it is; a longer one is shortened by normalize_resource_id(). */
	public const MAX_RESOURCE_ID = 150;

	private const MAX_SUMMARY = 191;
	private const MAX_CLIENT  = 96;

	/** @var bool|null Per-request healthy-schema cache for maybe_install_table(). */
	private static ?bool $schema_healthy = null;

	private static ?BlobStore $store_override = null;

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function reset_schema_health_cache_for_tests(): void {
		self::$schema_healthy = null;
	}

	/** Use this store instead of the default folder, or the default again with null. For tests. */
	public static function use_blob_store_for_tests( ?BlobStore $store ): void {
		self::$store_override = $store;
	}

	/** The blob store for images, or null when the uploads folder cannot be used. */
	public static function blob_store(): ?BlobStore {
		return self::$store_override ?? BlobStore::default( ChangeLedgerRetention::max_bytes() );
	}

	// -----------------------------------------------------------------------
	// Schema.
	// -----------------------------------------------------------------------

	public static function table_schema_ok(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return false;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is internal (prefix + const).
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! is_array( $columns ) || [] === $columns ) {
			return false;
		}
		return [] === array_diff( self::COLUMNS, array_map( 'strval', $columns ) );
	}

	/**
	 * Create the table, or bring an older one up to date. Cheap when the version is current and the
	 * columns are all there.
	 */
	public static function maybe_install_table(): void {
		global $wpdb;

		if ( true === self::$schema_healthy ) {
			return;
		}
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION && self::table_schema_ok() ) {
			self::$schema_healthy = true;
			return;
		}

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			change_id VARCHAR(40) NOT NULL,
			parent_id VARCHAR(40) NOT NULL DEFAULT '',
			kind VARCHAR(16) NOT NULL DEFAULT 'change',
			change_set_id VARCHAR(96) NOT NULL DEFAULT '',
			ability VARCHAR(120) NOT NULL DEFAULT '',
			actor BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			client VARCHAR(96) NOT NULL DEFAULT '',
			family VARCHAR(32) NOT NULL DEFAULT 'other',
			resource_type VARCHAR(32) NOT NULL DEFAULT '',
			resource_id VARCHAR(150) NOT NULL DEFAULT '',
			status VARCHAR(24) NOT NULL DEFAULT 'armed',
			before_ref CHAR(64) NOT NULL DEFAULT '',
			before_sha256 CHAR(64) NOT NULL DEFAULT '',
			before_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			after_ref CHAR(64) NOT NULL DEFAULT '',
			after_sha256 CHAR(64) NOT NULL DEFAULT '',
			after_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			restorable TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			restorable_reason VARCHAR(48) NOT NULL DEFAULT '',
			summary VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			settled_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY change_id_idx (change_id),
			KEY parent_idx (parent_id),
			KEY change_set_idx (change_set_id),
			KEY ability_idx (ability),
			KEY actor_idx (actor),
			KEY resource_idx (resource_type, resource_id),
			KEY family_idx (family, created_at),
			KEY status_idx (status),
			KEY created_idx (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( self::table_schema_ok() ) {
			self::$schema_healthy = true;
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		} else {
			self::$schema_healthy = false;
			Logger::error(
				'change_ledger_schema_install_failed',
				[
					'table'          => $table,
					'target_version' => self::SCHEMA_VERSION,
				]
			);
		}
	}

	// -----------------------------------------------------------------------
	// Write.
	// -----------------------------------------------------------------------

	/**
	 * Record a change before the write, with the image of the resource as it is.
	 *
	 * Spec keys: ability, family, resource_type and resource_id are required. before is the image: an
	 * array of fields or the text of a file; null when there is none. Optional: kind (change, rollback,
	 * redo or restore_point), parent_id (a row that exists), change_set_id, summary, client, actor,
	 * change_id (the id the journal uses), and restorable (false, or true for a change that is undone
	 * without a before image, such as the creation of a post) with restorable_reason.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>|\WP_Error The row as get() returns it.
	 */
	public static function record( array $spec ): array|\WP_Error {
		$ability = isset( $spec['ability'] ) && is_scalar( $spec['ability'] ) ? strtolower( trim( (string) $spec['ability'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,47}\/[a-z0-9][a-z0-9_-]{0,63}$/D', $ability ) ) {
			return self::invalid( 'stonewright_change_invalid_ability', 'The ability name is not valid.' );
		}
		$type = isset( $spec['resource_type'] ) && is_scalar( $spec['resource_type'] ) ? substr( sanitize_key( (string) $spec['resource_type'] ), 0, 32 ) : '';
		$raw  = isset( $spec['resource_id'] ) && is_scalar( $spec['resource_id'] ) ? self::clean_id( (string) $spec['resource_id'] ) : '';
		if ( '' === $type || '' === $raw ) {
			return self::invalid( 'stonewright_change_invalid_resource', 'A change needs a resource type and a resource id.' );
		}
		$kind = isset( $spec['kind'] ) && is_scalar( $spec['kind'] ) && '' !== (string) $spec['kind'] ? (string) $spec['kind'] : 'change';
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return self::invalid( 'stonewright_change_invalid_kind', 'The kind of change is not known.' );
		}
		$family = isset( $spec['family'] ) && is_scalar( $spec['family'] ) ? (string) $spec['family'] : 'other';
		$family = in_array( $family, self::FAMILIES, true ) ? $family : 'other';

		$change_id = isset( $spec['change_id'] ) && is_scalar( $spec['change_id'] ) ? (string) $spec['change_id'] : '';
		if ( '' === $change_id ) {
			$change_id = 'cs-' . bin2hex( random_bytes( 12 ) );
		} elseif ( ! self::is_valid_id( $change_id ) ) {
			return self::invalid( 'stonewright_change_invalid_id', 'The change id is not valid.' );
		}
		$parent = isset( $spec['parent_id'] ) && is_scalar( $spec['parent_id'] ) ? (string) $spec['parent_id'] : '';
		if ( '' !== $parent ) {
			if ( ! self::is_valid_id( $parent ) ) {
				return self::invalid( 'stonewright_change_invalid_id', 'The parent id is not valid.' );
			}
			if ( null === self::get( $parent ) ) {
				return self::invalid( 'stonewright_change_parent_missing', 'The parent change does not exist.' );
			}
		}
		$change_set = isset( $spec['change_set_id'] ) && is_scalar( $spec['change_set_id'] ) ? (string) $spec['change_set_id'] : '';
		if ( '' !== $change_set && 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $change_set ) ) {
			return self::invalid( 'stonewright_change_invalid_change_set', 'The change set id is not valid.' );
		}

		$prepared = ChangeImage::prepare( $spec['before'] ?? null, $type, $raw );
		$image    = self::store_image( $prepared );
		$reason   = $image['problem'];
		if ( '' === $reason && $prepared['masked'] ) {
			$reason = 'masked_secret';
		}
		if ( '' === $reason ) {
			$asked = $spec['restorable'] ?? null;
			if ( false === $asked ) {
				$given  = isset( $spec['restorable_reason'] ) && is_scalar( $spec['restorable_reason'] ) ? substr( sanitize_key( (string) $spec['restorable_reason'] ), 0, 48 ) : '';
				$reason = '' !== $given ? $given : 'not_restorable';
			} elseif ( true !== $asked && null === $prepared['bytes'] ) {
				$reason = 'no_before_image';
			}
		}

		$now  = ChangeJournal::now();
		$data = [
			'change_id'         => $change_id,
			'parent_id'         => $parent,
			'kind'              => $kind,
			'change_set_id'     => $change_set,
			'ability'           => $ability,
			'actor'             => isset( $spec['actor'] ) && is_numeric( $spec['actor'] ) ? max( 0, (int) $spec['actor'] ) : ( function_exists( 'get_current_user_id' ) ? max( 0, (int) get_current_user_id() ) : 0 ),
			'client'            => isset( $spec['client'] ) && is_string( $spec['client'] ) ? ChangeImage::safe_text( $spec['client'], self::MAX_CLIENT ) : self::client_hint(),
			'family'            => $family,
			'resource_type'     => $type,
			'resource_id'       => self::normalize_resource_id( $raw ),
			'status'            => 'armed',
			'before_ref'        => $image['ref'],
			'before_sha256'     => $image['sha256'],
			'before_bytes'      => $image['bytes'],
			'after_ref'         => '',
			'after_sha256'      => '',
			'after_bytes'       => 0,
			'restorable'        => '' === $reason ? 1 : 0,
			'restorable_reason' => $reason,
			'summary'           => isset( $spec['summary'] ) && is_string( $spec['summary'] ) ? ChangeImage::safe_text( $spec['summary'], self::MAX_SUMMARY ) : '',
			'created_at'        => gmdate( 'Y-m-d H:i:s', $now ),
			'settled_at'        => null,
		];

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned table.
		$inserted = $wpdb->insert( self::table_name(), $data );
		if ( false === $inserted ) {
			$duplicate = str_contains( strtolower( (string) ( $wpdb->last_error ?? '' ) ), 'duplicate' );
			return self::invalid(
				$duplicate ? 'stonewright_change_exists' : 'stonewright_change_ledger_unavailable',
				$duplicate ? 'A change with this id is already recorded.' : 'The change could not be recorded.'
			);
		}
		$data['id'] = (int) ( $wpdb->insert_id ?? 0 );
		$row        = self::normalize_row( $data );

		self::audit(
			'stonewright/change-ledger-record',
			[
				'_meta'      => [
					'operation_class'  => 'change_ledger',
					'resource_type'    => $type,
					'resource_ref'     => $family . ':' . $data['resource_id'],
					'execution_status' => 'executed',
					'change_set_id'    => $change_id,
					'before_sha256'    => $data['before_sha256'],
					'changed_bytes'    => $data['before_bytes'],
				],
				'change_id'  => $change_id,
				'ability'    => $ability,
				'family'     => $family,
				'kind'       => $kind,
				'restorable' => 1 === $data['restorable'],
				'reason'     => $reason,
			]
		);

		return $row;
	}

	/**
	 * Settle a recorded change after the write: set its status and, when the write produced one, the
	 * after image. The after image is written once. A later call can still move the status, for example
	 * to rolled_back_by. An after image that had a credential masked out of it makes a restorable row not
	 * restorable (masked_secret), as a masked before image does at record().
	 *
	 * @param array<string, mixed> $result status (required); after (an image); summary.
	 * @return array<string, mixed>|\WP_Error The row as get() returns it.
	 */
	public static function settle( string $change_id, array $result ): array|\WP_Error {
		if ( ! self::is_valid_id( $change_id ) ) {
			return self::invalid( 'stonewright_change_invalid_id', 'The change id is not valid.' );
		}
		$row = self::get( $change_id );
		if ( null === $row ) {
			return self::invalid( 'stonewright_change_not_found', 'The change is not recorded.' );
		}
		$status = isset( $result['status'] ) && is_scalar( $result['status'] ) ? (string) $result['status'] : '';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return self::invalid( 'stonewright_change_invalid_status', 'The status is not known.' );
		}

		$data = [
			'status'     => $status,
			'settled_at' => gmdate( 'Y-m-d H:i:s', ChangeJournal::now() ),
		];
		if ( isset( $result['summary'] ) && is_string( $result['summary'] ) ) {
			$data['summary'] = ChangeImage::safe_text( $result['summary'], self::MAX_SUMMARY );
		}
		if ( isset( $result['after'] ) ) {
			if ( '' !== $row['after_ref'] || '' !== $row['after_sha256'] ) {
				return self::invalid( 'stonewright_change_already_settled', 'The after image of this change is already recorded.' );
			}
			$prepared = ChangeImage::prepare( $result['after'], (string) $row['resource_type'], (string) $row['resource_id'] );
			if ( '' === $prepared['refused'] && in_array( $row['restorable_reason'], [ 'secret_file', 'secret_option', 'secret_resource' ], true ) ) {
				$prepared = [ 'refused' => (string) $row['restorable_reason'], 'bytes' => null, 'masked' => false, 'sha256' => '', 'size' => 0 ];
			}
			$image                = self::store_image( $prepared );
			$data['after_ref']    = $image['ref'];
			$data['after_sha256'] = $image['sha256'];
			$data['after_bytes']  = $image['bytes'];
			// An image that had credentials masked out of it cannot be written back, so a change whose result was masked is not restorable.
			if ( $prepared['masked'] && $row['restorable'] ) {
				$data['restorable']        = 0;
				$data['restorable_reason'] = 'masked_secret';
			}
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$updated = $wpdb->update( self::table_name(), $data, [ 'change_id' => $change_id ] );
		if ( false === $updated ) {
			return self::invalid( 'stonewright_change_ledger_unavailable', 'The change could not be settled.' );
		}
		return self::get( $change_id ) ?? self::invalid( 'stonewright_change_not_found', 'The change is not recorded.' );
	}

	/**
	 * Move a row to the outcome the change journal reached for the same id. For a caller that cannot
	 * report a failure: a row that does not exist, a status the row already has, and a ledger that cannot
	 * be reached are all ignored.
	 */
	public static function sync_status( string $change_id, string $status ): void {
		if ( ! self::is_valid_id( $change_id ) || ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		try {
			$row = self::get( $change_id );
			if ( null !== $row && $status !== $row['status'] ) {
				self::settle( $change_id, [ 'status' => $status ] );
			}
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}

	// -----------------------------------------------------------------------
	// Read.
	// -----------------------------------------------------------------------

	/** @return array<string, mixed>|null */
	public static function get( string $change_id ): ?array {
		if ( ! self::is_valid_id( $change_id ) ) {
			return null;
		}
		global $wpdb;
		$table   = self::table_name();
		$columns = implode( ', ', self::COLUMNS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name and column list are internal; the id is prepared.
		$found = $wpdb->get_row( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE change_id = %s LIMIT 1", $change_id ), ARRAY_A );
		// phpcs:enable
		return is_array( $found ) ? self::normalize_row( $found ) : null;
	}

	/**
	 * Changes, newest first.
	 *
	 * Filters (an empty value is ignored; a list matches any of its values): family, resource_type,
	 * resource_id, ability, actor, status, kind, parent_id, change_set_id, restorable (bool), open (bool),
	 * since and until (a timestamp, Y-m-d or Y-m-d H:i:s, in UTC). A value that no row can have, such as
	 * an unknown status, matches nothing.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
	 */
	public static function list( array $filters = [], int $per_page = self::DEFAULT_PER_PAGE, int $page = 1 ): array {
		global $wpdb;
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = max( 1, $page );
		$where    = self::build_where( $filters );
		if ( null === $where ) {
			return [
				'items'    => [],
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => 0,
			];
		}
		[ $clause, $params ] = $where;
		$table               = self::table_name();
		$columns             = implode( ', ', self::COLUMNS );
		$total               = self::count_with( $clause, $params );

		$sql = "SELECT {$columns} FROM {$table} {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal names; every value is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] ) ), ARRAY_A );
		// phpcs:enable
		$items = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$items[] = self::normalize_row( $row );
			}
		}
		return [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Number of changes that match the filters of list().
	 *
	 * @param array<string, mixed> $filters
	 */
	public static function count( array $filters = [] ): int {
		$where = self::build_where( $filters );
		return null === $where ? 0 : self::count_with( $where[0], $where[1] );
	}

	/**
	 * The parent chain of a change: the first change it descends from, then each row down to the one
	 * asked for, which is last. The chain stops at a row whose parent is gone, at a loop, and at $max rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function chain( string $change_id, int $max = self::MAX_CHAIN ): array {
		$max     = max( 1, min( self::MAX_CHAIN, $max ) );
		$chain   = [];
		$visited = [];
		$next    = $change_id;
		$length  = 0;
		while ( '' !== $next && ! isset( $visited[ $next ] ) && $length < $max ) {
			$visited[ $next ] = true;
			$row              = self::get( $next );
			if ( null === $row ) {
				break;
			}
			array_unshift( $chain, $row );
			++$length;
			$next = (string) $row['parent_id'];
		}
		return $chain;
	}

	/**
	 * The rows that act on a change: its rollbacks and redos, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function children( string $change_id, int $limit = 100 ): array {
		if ( ! self::is_valid_id( $change_id ) ) {
			return [];
		}
		global $wpdb;
		$table   = self::table_name();
		$columns = implode( ', ', self::COLUMNS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal names; every value is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE parent_id = %s ORDER BY id ASC LIMIT %d", $change_id, max( 1, min( 500, $limit ) ) ), ARRAY_A );
		// phpcs:enable
		$items = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$items[] = self::normalize_row( $row );
			}
		}
		return $items;
	}

	/**
	 * The before or after image of a change, read through the integrity check of the blob store.
	 *
	 * @param string $which 'before' or 'after'.
	 * @return string|array<mixed>|\WP_Error The image as it was given to record() or settle(), masked.
	 */
	public static function read_image( string $change_id, string $which ): string|array|\WP_Error {
		if ( ! in_array( $which, [ 'before', 'after' ], true ) ) {
			return self::invalid( 'stonewright_change_invalid_image', 'Choose the before or the after image.' );
		}
		$row = self::get( $change_id );
		if ( null === $row ) {
			return self::invalid( 'stonewright_change_not_found', 'The change is not recorded.' );
		}
		$ref = (string) $row[ $which . '_ref' ];
		if ( '' === $ref ) {
			return self::invalid( 'stonewright_change_no_image', 'No image is stored for this change.' );
		}
		$store = self::blob_store();
		if ( null === $store ) {
			return self::invalid( 'stonewright_blob_unavailable', 'The blob folder cannot be used.' );
		}
		$bytes = $store->get( $ref );
		if ( $bytes instanceof \WP_Error ) {
			return $bytes;
		}
		$image = ChangeImage::decode( $bytes );
		return null === $image ? self::invalid( 'stonewright_change_image_invalid', 'The stored image cannot be read.' ) : $image;
	}

	/**
	 * Delete every row and every blob. Settings stay. For a purge the site owner asked for.
	 *
	 * @return int Number of rows that existed before.
	 */
	public static function purge_all(): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return 0;
		}
		$table = self::table_name();
		$count = self::count();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owned table, no input.
		$result = $wpdb->query( "DELETE FROM {$table}" );
		if ( false === $result ) {
			return 0;
		}
		self::blob_store()?->erase();
		return $count;
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	public static function is_valid_id( string $change_id ): bool {
		return 1 === preg_match( '/^cs-[a-f0-9]{24}$/D', $change_id );
	}

	/**
	 * A resource id as the table holds it: control characters removed, and an id longer than
	 * MAX_RESOURCE_ID characters shortened to its start, a hash of the whole id, and its end, so that two
	 * long ids stay apart and a file name at the end stays readable. A filter uses the same form.
	 */
	public static function normalize_resource_id( string $resource_id ): string {
		$id = self::clean_id( $resource_id );
		if ( strlen( $id ) <= self::MAX_RESOURCE_ID ) {
			return $id;
		}
		return substr( $id, 0, 40 ) . '~' . substr( sha1( $id ), 0, 16 ) . '~' . substr( $id, -80 );
	}

	private static function clean_id( string $id ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $id ) );
	}

	/**
	 * Put an image in the blob store and describe what became of it.
	 *
	 * @param array{refused:string,bytes:?string,masked:bool,sha256:string,size:int} $prepared
	 * @return array{ref:string,sha256:string,bytes:int,problem:string}
	 */
	private static function store_image( array $prepared ): array {
		$out = [
			'ref'     => '',
			'sha256'  => $prepared['sha256'],
			'bytes'   => $prepared['size'],
			'problem' => '',
		];
		if ( '' !== $prepared['refused'] ) {
			$out['problem'] = $prepared['refused'];
			return $out;
		}
		if ( null === $prepared['bytes'] ) {
			return $out;
		}
		$store = self::blob_store();
		if ( null === $store ) {
			$out['problem'] = 'store_unavailable';
			return $out;
		}
		$stored = $store->put( $prepared['bytes'] );
		if ( $stored instanceof \WP_Error ) {
			$out['problem'] = match ( $stored->get_error_code() ) {
				'stonewright_blob_too_large' => 'too_large',
				'stonewright_blob_store_full' => 'store_full',
				default                       => 'store_unavailable',
			};
			return $out;
		}
		$out['ref'] = $stored['sha256'];
		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function normalize_row( array $row ): array {
		$out = [];
		foreach ( self::COLUMNS as $column ) {
			$value = $row[ $column ] ?? null;
			$out[ $column ] = match ( $column ) {
				'id', 'actor', 'before_bytes', 'after_bytes' => max( 0, (int) $value ),
				'restorable'                                 => 1 === (int) $value,
				'settled_at'                                 => null === $value || '' === $value ? null : (string) $value,
				default                                      => (string) ( $value ?? '' ),
			};
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{0:string,1:list<mixed>}|null The WHERE clause and its values, or null when no row can match.
	 */
	private static function build_where( array $filters ): ?array {
		$clauses = [];
		$params  = [];

		foreach ( [
			'family'        => static fn ( string $v ): ?string => in_array( $v, self::FAMILIES, true ) ? $v : null,
			'resource_type' => static fn ( string $v ): ?string => '' !== sanitize_key( $v ) ? substr( sanitize_key( $v ), 0, 32 ) : null,
			'resource_id'   => static fn ( string $v ): ?string => '' !== self::clean_id( $v ) ? self::normalize_resource_id( $v ) : null,
			'ability'       => static fn ( string $v ): ?string => 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,47}\/[a-z0-9][a-z0-9_-]{0,63}$/D', strtolower( $v ) ) ? strtolower( $v ) : null,
			'status'        => static fn ( string $v ): ?string => in_array( $v, self::STATUSES, true ) ? $v : null,
			'kind'          => static fn ( string $v ): ?string => in_array( $v, self::KINDS, true ) ? $v : null,
			'parent_id'     => static fn ( string $v ): ?string => self::is_valid_id( $v ) ? $v : null,
			'change_set_id' => static fn ( string $v ): ?string => 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $v ) ? $v : null,
		] as $key => $check ) {
			$wanted = self::wanted( $filters[ $key ] ?? null );
			if ( [] === $wanted ) {
				continue;
			}
			$values = [];
			foreach ( $wanted as $value ) {
				$valid = $check( (string) $value );
				if ( null === $valid ) {
					return null;
				}
				$values[] = $valid;
			}
			$clauses[] = self::in_clause( $key, count( $values ), '%s' );
			array_push( $params, ...$values );
		}

		$actors = self::wanted( $filters['actor'] ?? null );
		if ( [] !== $actors ) {
			$ids = array_values( array_filter( array_map( 'intval', $actors ), static fn ( int $id ): bool => $id > 0 ) );
			if ( [] === $ids ) {
				return null;
			}
			$clauses[] = self::in_clause( 'actor', count( $ids ), '%d' );
			array_push( $params, ...$ids );
		}

		if ( isset( $filters['restorable'] ) && is_bool( $filters['restorable'] ) ) {
			$clauses[] = 'restorable = %d';
			$params[]  = $filters['restorable'] ? 1 : 0;
		}
		if ( isset( $filters['open'] ) && is_bool( $filters['open'] ) ) {
			$clauses[] = 'status ' . ( $filters['open'] ? 'IN' : 'NOT IN' ) . ' (' . implode( ', ', array_fill( 0, count( self::OPEN_STATUSES ), '%s' ) ) . ')';
			array_push( $params, ...self::OPEN_STATUSES );
		}

		foreach ( [ 'since' => '>=', 'until' => '<=' ] as $key => $operator ) {
			if ( ! isset( $filters[ $key ] ) || '' === $filters[ $key ] || false === $filters[ $key ] ) {
				continue;
			}
			$time = self::datetime( $filters[ $key ], 'until' === $key );
			if ( null === $time ) {
				return null;
			}
			$clauses[] = 'created_at ' . $operator . ' %s';
			$params[]  = $time;
		}

		return [ [] === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ), $params ];
	}

	/**
	 * The non-empty values of a filter given as one value or a list.
	 *
	 * @return list<scalar>
	 */
	private static function wanted( mixed $value ): array {
		$items = is_array( $value ) ? array_values( $value ) : [ $value ];
		$out   = [];
		foreach ( $items as $item ) {
			if ( is_scalar( $item ) && ! is_bool( $item ) && '' !== (string) $item && 0 !== $item && '0' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	private static function in_clause( string $column, int $count, string $placeholder ): string {
		return 1 === $count
			? $column . ' = ' . $placeholder
			: $column . ' IN (' . implode( ', ', array_fill( 0, $count, $placeholder ) ) . ')';
	}

	/** A UTC datetime for a timestamp or a date string, or null when it is neither. */
	private static function datetime( mixed $value, bool $end_of_day ): ?string {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) && strlen( $value ) >= 9 ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
			return $value . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' );
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			return $value;
		}
		return null;
	}

	/**
	 * @param list<mixed> $params
	 */
	private static function count_with( string $clause, array $params ): int {
		global $wpdb;
		$table = self::table_name();
		$sql   = "SELECT COUNT(*) FROM {$table} {$clause}";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal names; every value is prepared.
		$total = [] === $params ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
		// phpcs:enable
		return max( 0, (int) $total );
	}

	/** The product token of the caller's User-Agent, or wp-cli, or nothing. */
	private static function client_hint(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
		if ( 1 === preg_match( '#^([A-Za-z0-9][A-Za-z0-9._+/-]{0,59})#', trim( $agent ), $match ) ) {
			return $match[1];
		}
		return defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ? 'wp-cli' : '';
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function audit( string $ability, array $args ): void {
		try {
			AuditLog::record( $ability, $args, 'ok' );
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}

	private static function invalid( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}
