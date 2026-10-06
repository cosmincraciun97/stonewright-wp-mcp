<?php
/**
 * Mapping between stored skill rows, logical records, and the rows callers read.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

/**
 * Stored rows keep every value as text. Logical records carry the types the
 * library checks. Callers read the stored text plus decoded constraints and
 * conflicts, which is also the shape of a revision snapshot.
 */
final class RowFormat {

	/** Columns of the skills table, in table order. */
	public const COLUMNS = [ 'id', 'slug', 'title', 'description', 'content', 'enabled', 'enable_agentic', 'enable_prompt', 'source', 'status', 'topic', 'semantic_fingerprint', 'version_constraints_json', 'verification_count', 'revision', 'conflict_json', 'trashed_at', 'created_at', 'updated_at' ];

	/** Fields whose change records the previous state as a revision. */
	public const REVISED = [ 'title', 'description', 'content', 'source', 'topic', 'semantic_fingerprint', 'version_constraints', 'verification_count', 'conflicts', 'enable_agentic', 'enable_prompt' ];

	private const TEXT_LIMITS = [
		'slug'                 => 191,
		'title'                => 255,
		'topic'                => 191,
		'source'               => 20,
		'status'               => 20,
		'semantic_fingerprint' => 64,
	];

	private const BYTE_LIMITS = [
		'description'              => 65535,
		'content'                  => 16777215,
		'version_constraints_json' => 65535,
		'conflict_json'            => 65535,
	];

	/**
	 * A stored row, or a revision snapshot of one, as the library's logical record.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function logical( array $row ): array {
		$source = self::text( $row['source'] ?? '' );
		$status = self::text( $row['status'] ?? '' );

		return [
			'id'                   => (int) self::text( $row['id'] ?? '0' ),
			'slug'                 => self::text( $row['slug'] ?? '' ),
			'title'                => self::text( $row['title'] ?? '' ),
			'description'          => self::text( $row['description'] ?? '' ),
			'content'              => self::text( $row['content'] ?? '' ),
			'enabled'              => self::flag( $row['enabled'] ?? true ),
			'enable_agentic'       => self::flag( $row['enable_agentic'] ?? true ),
			'enable_prompt'        => self::flag( $row['enable_prompt'] ?? true ),
			'source'               => '' === $source ? 'user' : $source,
			'status'               => '' === $status ? 'active' : $status,
			'topic'                => self::text( $row['topic'] ?? '' ),
			'semantic_fingerprint' => self::text( $row['semantic_fingerprint'] ?? '' ),
			'version_constraints'  => self::decoded( $row['version_constraints'] ?? null, $row['version_constraints_json'] ?? '' ),
			'verification_count'   => max( 0, (int) self::text( $row['verification_count'] ?? '0' ) ),
			'revision'             => max( 1, (int) self::text( $row['revision'] ?? '1' ) ),
			'conflicts'            => self::findings( self::decoded( $row['conflicts'] ?? null, $row['conflict_json'] ?? '' ) ),
			'trashed_at'           => self::moment( $row['trashed_at'] ?? null ),
			'created_at'           => self::text( $row['created_at'] ?? '' ),
			'updated_at'           => self::text( $row['updated_at'] ?? '' ),
		];
	}

	/**
	 * The row callers read: every stored column as text, as the table returns it,
	 * plus decoded constraints and conflicts. Presentation keys such as a catalog
	 * source kind are kept.
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	public static function exposed( array $record ): array {
		$logical = self::logical( self::stored( $record ) + $record );
		$row     = [
			'id'                       => (string) $logical['id'],
			'slug'                     => $logical['slug'],
			'title'                    => $logical['title'],
			'description'              => $logical['description'],
			'content'                  => $logical['content'],
			'enabled'                  => $logical['enabled'] ? '1' : '0',
			'enable_agentic'           => $logical['enable_agentic'] ? '1' : '0',
			'enable_prompt'            => $logical['enable_prompt'] ? '1' : '0',
			'source'                   => $logical['source'],
			'status'                   => $logical['status'],
			'topic'                    => $logical['topic'],
			'semantic_fingerprint'     => $logical['semantic_fingerprint'],
			'version_constraints_json' => self::encode_map( $logical['version_constraints'] ),
			'verification_count'       => (string) $logical['verification_count'],
			'revision'                 => (string) $logical['revision'],
			'conflict_json'            => self::encode_list( $logical['conflicts'] ),
			'trashed_at'               => $logical['trashed_at'],
			'created_at'               => $logical['created_at'],
			'updated_at'               => $logical['updated_at'],
			'version_constraints'      => $logical['version_constraints'],
			'conflicts'                => $logical['conflicts'],
		];
		foreach ( $record as $key => $value ) {
			if ( is_string( $key ) && ! array_key_exists( $key, $row ) ) {
				$row[ $key ] = $value;
			}
		}
		return $row;
	}

	/**
	 * Column values for an insert or update, without the id and the timestamps.
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, int|string>
	 */
	public static function stored( array $record ): array {
		return [
			'slug'                     => self::text( $record['slug'] ?? '' ),
			'title'                    => self::text( $record['title'] ?? '' ),
			'description'              => self::text( $record['description'] ?? '' ),
			'content'                  => self::text( $record['content'] ?? '' ),
			'enabled'                  => self::flag( $record['enabled'] ?? true ) ? 1 : 0,
			'enable_agentic'           => self::flag( $record['enable_agentic'] ?? true ) ? 1 : 0,
			'enable_prompt'            => self::flag( $record['enable_prompt'] ?? true ) ? 1 : 0,
			'source'                   => self::text( $record['source'] ?? 'user' ),
			'status'                   => self::text( $record['status'] ?? 'active' ),
			'topic'                    => self::text( $record['topic'] ?? '' ),
			'semantic_fingerprint'     => self::text( $record['semantic_fingerprint'] ?? '' ),
			'version_constraints_json' => self::encode_map( self::decoded( $record['version_constraints'] ?? null, $record['version_constraints_json'] ?? '' ) ),
			'verification_count'       => max( 0, (int) self::text( $record['verification_count'] ?? '0' ) ),
			'revision'                 => max( 1, (int) self::text( $record['revision'] ?? '1' ) ),
			'conflict_json'            => self::encode_list( self::findings( self::decoded( $record['conflicts'] ?? null, $record['conflict_json'] ?? '' ) ) ),
		];
	}

	/**
	 * Placeholders for the values of a column map, in the same order.
	 *
	 * @param array<string, mixed> $columns
	 * @return array<int, string>
	 */
	public static function formats( array $columns ): array {
		return array_values( array_map( static fn( mixed $value ): string => is_int( $value ) ? '%d' : '%s', $columns ) );
	}

	/**
	 * A revision snapshot: the full stored row with every value as text, plus the
	 * decoded constraints and conflicts.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function snapshot( array $row ): string {
		$snapshot = [];
		foreach ( self::COLUMNS as $column ) {
			$value                = $row[ $column ] ?? null;
			$snapshot[ $column ] = null === $value ? null : self::text( $value );
		}
		$logical                         = self::logical( $row );
		$snapshot['version_constraints'] = $logical['version_constraints'];
		$snapshot['conflicts']           = $logical['conflicts'];
		$encoded                         = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * Refuses values the table cannot hold, so nothing is truncated silently.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function storage_problem( array $record ): ?\WP_Error {
		$columns = self::stored( $record );
		if ( '' === $columns['slug'] ) {
			return self::invalid( 'The skill needs an identifier.' );
		}
		foreach ( self::TEXT_LIMITS as $column => $limit ) {
			if ( mb_strlen( (string) $columns[ $column ], 'UTF-8' ) > $limit ) {
				return self::invalid( sprintf( 'The %1$s is longer than %2$d characters.', str_replace( '_', ' ', $column ), $limit ) );
			}
		}
		foreach ( self::BYTE_LIMITS as $column => $limit ) {
			if ( strlen( (string) $columns[ $column ] ) > $limit ) {
				return self::invalid( sprintf( 'The %s is too large to store.', str_replace( '_', ' ', $column ) ) );
			}
		}
		return null;
	}

	/** Stored text for an empty constraint map is an empty JSON list, as earlier releases wrote it. @param array<mixed> $constraints */
	public static function encode_map( array $constraints ): string {
		if ( [] === $constraints ) {
			return '[]';
		}
		$encoded = wp_json_encode( array_is_list( $constraints ) ? $constraints : (object) $constraints, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '[]';
	}

	/** @param array<mixed> $items */
	public static function encode_list( array $items ): string {
		$encoded = wp_json_encode( array_values( $items ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '[]';
	}

	public static function flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return is_scalar( $value ) && 0 !== (int) $value;
	}

	private static function text( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function moment( mixed $value ): ?string {
		$text = self::text( $value );
		return '' === $text || str_starts_with( $text, '0000-00-00' ) ? null : $text;
	}

	/** @return array<mixed> */
	private static function decoded( mixed $value, mixed $json ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		$text = is_string( $value ) ? $value : self::text( $json );
		if ( '' === trim( $text ) ) {
			return [];
		}
		$decoded = json_decode( $text, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** @param array<mixed> $items @return array<int, string> */
	private static function findings( array $items ): array {
		return array_values( array_filter( $items, 'is_string' ) );
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_skill_record_invalid', $message, [ 'status' => 400 ] );
	}
}
