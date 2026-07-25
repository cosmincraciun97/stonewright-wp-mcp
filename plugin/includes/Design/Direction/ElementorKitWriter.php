<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use Stonewright\WpMcp\Security\Backup;
use WP_Error;

/**
 * Typed reader and writer for the Elementor kit globals sync touches.
 *
 * Kit globals live in one serialized settings array, which makes a careless
 * write catastrophic: replacing the array drops every setting Stonewright does
 * not know about. This class exists so no sync code ever handles that array
 * directly.
 *
 * - Reads project the kit down to the two groups sync understands, tagged with
 *   the bucket (system or custom) each entry lives in, so the writer can put an
 *   update back exactly where it came from.
 * - Writes merge: the settings array is read, only the planned entry properties
 *   are set, and everything else — unknown keys, unknown entry properties, entry
 *   order — is written back untouched.
 * - `Backup::snapshot_post()` runs before the mutation, and a kit that cannot be
 *   snapshotted is not written at all.
 *
 * The value grammar lives in ElementorKitSyncPlanner; this class only knows
 * where Elementor keeps things.
 */
final class ElementorKitWriter {

	/** @var string Structured error code for a kit write that did not land. */
	public const ERROR_CODE = 'stonewright_direction_sync_write_failed';

	/** @var string Structured error code for a kit that could not be snapshotted. */
	public const BACKUP_CODE = 'stonewright_direction_sync_backup_missing';

	/** @var string Post meta key holding Elementor kit settings. */
	public const META_KEY = '_elementor_page_settings';

	/**
	 * Settings keys holding color entries, by bucket.
	 *
	 * @var array<string,string>
	 */
	private const COLOR_BUCKETS = [
		'system' => 'system_colors',
		'custom' => 'custom_colors',
	];

	/**
	 * Settings keys holding typography entries, by bucket.
	 *
	 * @var array<string,string>
	 */
	private const TYPOGRAPHY_BUCKETS = [
		'system' => 'system_typography',
		'custom' => 'custom_typography',
	];

	/**
	 * Contract property names mapped to Elementor typography control names.
	 *
	 * @var array<string,string>
	 */
	private const TYPOGRAPHY_KEYS = [
		'font-family'    => 'typography_font_family',
		'font-size'      => 'typography_font_size',
		'font-weight'    => 'typography_font_weight',
		'letter-spacing' => 'typography_letter_spacing',
		'line-height'    => 'typography_line_height',
	];

	/**
	 * Reads the kit globals sync understands.
	 *
	 * @param int $kit_id Elementor kit post id.
	 * @return array{kit_id:int,colors:list<array<string,mixed>>,typography:list<array<string,mixed>>}
	 */
	public static function read( int $kit_id ): array {
		$settings = get_post_meta( $kit_id, self::META_KEY, true );
		$settings = is_array( $settings ) ? $settings : [];

		return [
			'kit_id'     => $kit_id,
			'colors'     => self::read_colors( $settings ),
			'typography' => self::read_typography( $settings ),
		];
	}

	/**
	 * Refuses a kit that cannot be snapshotted, before anything is compared.
	 *
	 * A missing kit post reads as a kit with no globals at all, which would
	 * otherwise surface as a staleness error and point the caller at the wrong
	 * problem. Callers run this before planning so the reported reason is the
	 * real one.
	 *
	 * @param int $kit_id Elementor kit post id.
	 * @return WP_Error|null Error when the kit cannot be snapshotted, else null.
	 */
	public static function snapshot_blocker( int $kit_id ): ?WP_Error {
		if ( get_post( $kit_id ) ) {
			return null;
		}

		return new WP_Error(
			self::BACKUP_CODE,
			__( 'The Elementor kit post does not exist, so it cannot be snapshotted or written.', 'stonewright' ),
			[
				'status' => 409,
				'kit_id' => $kit_id,
			]
		);
	}

	/**
	 * Applies planned operations to the kit, snapshotting it first.
	 *
	 * @param int                       $kit_id     Elementor kit post id.
	 * @param list<array<string,mixed>> $operations Operations from the planner.
	 * @return array{snapshot_id:string,applied:int}|WP_Error
	 */
	public static function apply( int $kit_id, array $operations ) {
		if ( [] === $operations ) {
			return [
				'snapshot_id' => '',
				'applied'     => 0,
			];
		}

		$snapshot_id = Backup::snapshot_post( $kit_id );
		if ( '' === $snapshot_id ) {
			return new WP_Error(
				self::BACKUP_CODE,
				__( 'The Elementor kit could not be snapshotted, so it was not written.', 'stonewright' ),
				[
					'status' => 409,
					'kit_id' => $kit_id,
				]
			);
		}

		$settings = get_post_meta( $kit_id, self::META_KEY, true );
		$settings = is_array( $settings ) ? $settings : [];

		foreach ( $operations as $operation ) {
			$updated = self::apply_operation( $settings, $operation );
			if ( $updated instanceof WP_Error ) {
				return $updated;
			}

			$settings = $updated;
		}

		if ( false === update_post_meta( $kit_id, self::META_KEY, $settings ) ) {
			return new WP_Error(
				self::ERROR_CODE,
				__( 'The Elementor kit settings could not be saved.', 'stonewright' ),
				[
					'status' => 500,
					'kit_id' => $kit_id,
				]
			);
		}

		return [
			'snapshot_id' => $snapshot_id,
			'applied'     => count( $operations ),
		];
	}

	/**
	 * @param array<string,mixed> $settings  Current kit settings.
	 * @param array<string,mixed> $operation One planned operation.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function apply_operation( array $settings, array $operation ) {
		$group    = (string) ( $operation['group'] ?? '' );
		$bucket   = 'system' === ( $operation['bucket'] ?? '' ) ? 'system' : 'custom';
		$target   = (string) ( $operation['target'] ?? '' );
		$property = (string) ( $operation['property'] ?? '' );
		$value    = $operation['to'] ?? null;

		if ( '' === $target || ! is_string( $value ) ) {
			return self::write_error();
		}

		if ( 'colors' !== $group && 'typography' !== $group ) {
			return self::write_error();
		}

		$buckets = 'colors' === $group ? self::COLOR_BUCKETS : self::TYPOGRAPHY_BUCKETS;
		$key     = $buckets[ $bucket ];
		$entries = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? array_values( $settings[ $key ] ) : [];
		$index   = self::index_of( $entries, $target );

		if ( null === $index ) {
			$entries[] = [
				'_id'   => $target,
				'title' => $target,
			];
			$index     = count( $entries ) - 1;
		}

		$entry = is_array( $entries[ $index ] ) ? $entries[ $index ] : [];

		if ( 'colors' === $group ) {
			if ( 'color' !== $property ) {
				return self::write_error();
			}

			$entry['color'] = $value;
		} else {
			if ( ! isset( self::TYPOGRAPHY_KEYS[ $property ] ) ) {
				return self::write_error();
			}

			$control = self::TYPOGRAPHY_KEYS[ $property ];

			if ( ElementorKitSyncPlanner::is_dimension_property( $property ) ) {
				$parts = ElementorKitSyncPlanner::dimension_parts( $value );
				if ( null === $parts ) {
					return self::write_error();
				}

				// Keep whatever else Elementor stored alongside size and unit.
				$existing        = isset( $entry[ $control ] ) && is_array( $entry[ $control ] ) ? $entry[ $control ] : [];
				$existing['size'] = $parts['size'];
				$existing['unit'] = $parts['unit'];

				$entry[ $control ] = $existing;
			} else {
				$entry[ $control ] = $value;
			}
		}

		$entries[ $index ]   = $entry;
		$settings[ $key ]    = $entries;

		return $settings;
	}

	/**
	 * @param array<string,mixed> $settings Kit settings.
	 * @return list<array<string,mixed>>
	 */
	private static function read_colors( array $settings ): array {
		$entries = [];

		foreach ( self::COLOR_BUCKETS as $bucket => $key ) {
			foreach ( self::entries( $settings, $key ) as $entry ) {
				$id = self::entry_id( $entry );
				if ( '' === $id ) {
					continue;
				}

				$entries[] = [
					'slug'   => self::entry_slug( $entry, $id ),
					'id'     => $id,
					'title'  => isset( $entry['title'] ) && is_string( $entry['title'] ) ? $entry['title'] : '',
					'color'  => isset( $entry['color'] ) && is_string( $entry['color'] ) ? trim( $entry['color'] ) : '',
					'bucket' => $bucket,
				];
			}
		}

		return $entries;
	}

	/**
	 * @param array<string,mixed> $settings Kit settings.
	 * @return list<array<string,mixed>>
	 */
	private static function read_typography( array $settings ): array {
		$entries = [];

		foreach ( self::TYPOGRAPHY_BUCKETS as $bucket => $key ) {
			foreach ( self::entries( $settings, $key ) as $entry ) {
				$id = self::entry_id( $entry );
				if ( '' === $id ) {
					continue;
				}

				$properties = [];

				foreach ( self::TYPOGRAPHY_KEYS as $property => $control ) {
					if ( ! array_key_exists( $control, $entry ) ) {
						continue;
					}

					$value = ElementorKitSyncPlanner::canonical_value( $property, $entry[ $control ] );
					if ( null === $value ) {
						continue;
					}

					$properties[ $property ] = $value;
				}

				ksort( $properties );

				$entries[] = [
					'slug'       => self::entry_slug( $entry, $id ),
					'id'         => $id,
					'title'      => isset( $entry['title'] ) && is_string( $entry['title'] ) ? $entry['title'] : '',
					'bucket'     => $bucket,
					'properties' => $properties,
				];
			}
		}

		return $entries;
	}

	/**
	 * Position of the entry carrying an id, or null when the bucket lacks it.
	 *
	 * @param list<mixed> $entries Bucket entries.
	 */
	private static function index_of( array $entries, string $id ): ?int {
		foreach ( $entries as $index => $entry ) {
			if ( is_array( $entry ) && self::entry_id( $entry ) === $id ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $settings Kit settings.
	 * @param string              $key      Settings key holding entries.
	 * @return list<array<string,mixed>>
	 */
	private static function entries( array $settings, string $key ): array {
		$raw = $settings[ $key ] ?? [];
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$entries = [];

		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @param array<string,mixed> $entry Kit entry.
	 */
	private static function entry_id( array $entry ): string {
		$id = $entry['_id'] ?? '';

		return is_string( $id ) || is_int( $id ) ? trim( (string) $id ) : '';
	}

	/**
	 * Token slug for a kit entry: its title when usable, otherwise its id.
	 *
	 * Capture derives contract token names the same way, so a captured direction
	 * matches the kit it came from without a translation table.
	 *
	 * @param array<string,mixed> $entry Kit entry.
	 * @param string              $id    Kit entry id.
	 */
	private static function entry_slug( array $entry, string $id ): string {
		$title = isset( $entry['title'] ) && is_string( $entry['title'] ) ? sanitize_title( $entry['title'] ) : '';

		return '' !== $title ? $title : sanitize_title( $id );
	}

	private static function write_error(): WP_Error {
		return new WP_Error(
			self::ERROR_CODE,
			__( 'The Elementor kit sync operation was not writable.', 'stonewright' ),
			[ 'status' => 500 ]
		);
	}
}
