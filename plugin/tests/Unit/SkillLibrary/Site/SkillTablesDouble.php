<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site;

/**
 * In-memory stand-in for the two skill tables.
 *
 * It answers the statements the site adapters issue with the database behavior
 * that matters to them: unique keys, text column values, NULL, affected-row
 * counts that report changed rows only, and transactions that roll back.
 */
final class SkillTablesDouble {

	public string $prefix = 'wp_';

	public int $insert_id = 0;

	public string $last_error = '';

	/** @var array<int, array<string, string|null>> */
	public array $skills = [];

	/** @var array<int, array<string, string|null>> */
	public array $versions = [];

	/** @var list<string> */
	public array $statements = [];

	/** @var list<array{table: string, data: array<string, mixed>}> */
	public array $inserts = [];

	/** Runs once, right before the next UPDATE of the skills table, like a concurrent writer. @var callable|null */
	public $before_update = null;

	public bool $fail_version_insert = false;

	public bool $fail_skill_update = false;

	private int $next_skill_id = 1;

	private int $next_version_id = 1;

	/** @var array{0: array<int, array<string, string|null>>, 1: array<int, array<string, string|null>>}|null */
	private ?array $saved = null;

	public function skills_table(): string {
		return $this->prefix . 'stonewright_skills';
	}

	public function versions_table(): string {
		return $this->prefix . 'stonewright_skill_versions';
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$out    = '';
		$index  = 0;
		$length = strlen( $query );
		for ( $position = 0; $position < $length; $position++ ) {
			$char = $query[ $position ];
			$next = $position + 1 < $length ? $query[ $position + 1 ] : '';
			if ( '%' !== $char || ! in_array( $next, [ 'd', 's', 'f' ], true ) || $index >= count( $args ) ) {
				$out .= $char;
				continue;
			}
			$arg  = $args[ $index++ ];
			$out .= match ( $next ) {
				'd'     => (string) (int) $arg,
				'f'     => (string) (float) $arg,
				default => "'" . str_replace( "'", "''", (string) $arg ) . "'",
			};
			++$position;
		}
		return $out;
	}

	public function query( string $query ): int|bool {
		$this->statements[] = $query;
		$statement          = strtoupper( trim( $query ) );
		if ( str_starts_with( $statement, 'START TRANSACTION' ) ) {
			$this->saved = [ $this->skills, $this->versions ];
			return 0;
		}
		if ( str_starts_with( $statement, 'COMMIT' ) ) {
			$this->saved = null;
			return 0;
		}
		if ( str_starts_with( $statement, 'ROLLBACK' ) ) {
			if ( null !== $this->saved ) {
				[ $this->skills, $this->versions ] = $this->saved;
			}
			$this->saved = null;
			return 0;
		}
		if ( preg_match( "/^UPDATE\s+(\S+)\s+SET\s+(\w+)\s*=\s*'([^']*)'\s+WHERE\s+(\w+)\s*=\s*''$/i", trim( $query ), $match ) ) {
			$changed = 0;
			foreach ( $this->rows_for( $match[1] ) as $id => $row ) {
				if ( '' === (string) ( $row[ $match[4] ] ?? '' ) ) {
					$this->write_row( $match[1], $id, [ $match[2] => $match[3] ] + $row );
					++$changed;
				}
			}
			return $changed;
		}
		return 0;
	}

	/** @return array<string, string|null>|null */
	public function get_row( string $query, string $output = 'OBJECT' ): ?array {
		$rows = $this->select( $query );
		return [] === $rows ? null : $rows[0];
	}

	/** @return list<array<string, string|null>> */
	public function get_results( string $query, string $output = 'OBJECT' ): array {
		return $this->select( $query );
	}

	public function get_var( string $query ): ?string {
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/i", $query, $match ) ) {
			return in_array( $match[1], [ $this->skills_table(), $this->versions_table() ], true ) ? $match[1] : null;
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<int, string>   $format
	 */
	public function insert( string $table, array $data, array $format = [] ): int|false {
		$this->inserts[] = [
			'table' => $table,
			'data'  => $data,
		];
		$row = array_map( [ self::class, 'text' ], $data );
		if ( $this->skills_table() === $table ) {
			foreach ( $this->skills as $existing ) {
				if ( $existing['slug'] === $row['slug'] ) {
					$this->last_error = "Duplicate entry '" . $row['slug'] . "' for key 'slug'";
					return false;
				}
			}
			$id                   = $this->next_skill_id++;
			$this->skills[ $id ]  = [ 'id' => (string) $id ] + $row + [
				'trashed_at' => null,
				'created_at' => '2026-10-06 08:00:00',
				'updated_at' => '2026-10-06 08:00:00',
			];
			$this->insert_id      = $id;
			$this->last_error     = '';
			return 1;
		}
		if ( $this->versions_table() === $table ) {
			if ( $this->fail_version_insert ) {
				$this->last_error = 'Simulated storage failure';
				return false;
			}
			foreach ( $this->versions as $existing ) {
				if ( $existing['skill_id'] === $row['skill_id'] && $existing['revision'] === $row['revision'] ) {
					$this->last_error = "Duplicate entry for key 'skill_revision'";
					return false;
				}
			}
			$id                    = $this->next_version_id++;
			$this->versions[ $id ] = [ 'id' => (string) $id ] + $row;
			$this->insert_id       = $id;
			$this->last_error      = '';
			return 1;
		}
		$this->insert_id = 0;
		return 1;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
		if ( $this->skills_table() === $table && is_callable( $this->before_update ) ) {
			$hook                = $this->before_update;
			$this->before_update = null;
			$hook( $this );
		}
		if ( $this->skills_table() === $table && $this->fail_skill_update ) {
			$this->last_error = 'Simulated storage failure';
			return false;
		}
		$changed = 0;
		foreach ( $this->rows_for( $table ) as $id => $row ) {
			if ( ! self::matches( $row, $where ) ) {
				continue;
			}
			$next = array_replace( $row, array_map( [ self::class, 'text' ], $data ) );
			if ( $next !== $row ) {
				$this->write_row( $table, $id, $next );
				++$changed;
			}
		}
		return $changed;
	}

	/** @param array<string, mixed> $where */
	public function delete( string $table, array $where, mixed $where_format = null ): int|false {
		$removed = 0;
		foreach ( $this->rows_for( $table ) as $id => $row ) {
			if ( self::matches( $row, $where ) ) {
				if ( $this->skills_table() === $table ) {
					unset( $this->skills[ $id ] );
				} else {
					unset( $this->versions[ $id ] );
				}
				++$removed;
			}
		}
		return $removed;
	}

	/** Stores a row exactly as an earlier release could have left it. @param array<string, mixed> $row */
	public function seed_skill( array $row ): int {
		$id                  = isset( $row['id'] ) ? (int) $row['id'] : $this->next_skill_id;
		$this->next_skill_id = max( $this->next_skill_id, $id + 1 );
		$this->skills[ $id ] = array_map( [ self::class, 'text' ], [ 'id' => $id ] + $row + self::defaults() );
		return $id;
	}

	/** @param array<string, mixed> $row */
	public function seed_version( array $row ): int {
		$id                    = $this->next_version_id++;
		$this->versions[ $id ] = array_map( [ self::class, 'text' ], [ 'id' => $id ] + $row );
		return $id;
	}

	/** @return array<string, string|null>|null */
	public function skill_by_slug( string $slug ): ?array {
		foreach ( $this->skills as $row ) {
			if ( $row['slug'] === $slug ) {
				return $row;
			}
		}
		return null;
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return [
			'title'                    => '',
			'description'              => '',
			'content'                  => '',
			'enabled'                  => 1,
			'enable_agentic'           => 1,
			'enable_prompt'            => 1,
			'source'                   => 'user',
			'status'                   => 'active',
			'topic'                    => '',
			'semantic_fingerprint'     => '',
			'version_constraints_json' => '[]',
			'verification_count'       => 0,
			'revision'                 => 1,
			'conflict_json'            => '[]',
			'trashed_at'               => null,
			'created_at'               => '2026-09-01 10:00:00',
			'updated_at'               => '2026-09-01 10:00:00',
		];
	}

	/** @return list<array<string, string|null>> */
	private function select( string $query ): array {
		$rows = $this->rows_for( $this->table_in( $query ) );
		foreach ( [ 'id', 'skill_id', 'revision' ] as $column ) {
			if ( preg_match( '/\b' . $column . '\s*=\s*(\d+)/', $query, $match ) ) {
				$rows = array_filter( $rows, static fn( array $row ): bool => (string) $row[ $column ] === $match[1] );
			}
		}
		if ( preg_match( "/\bslug\s*=\s*'((?:[^']|'')*)'/", $query, $match ) ) {
			$slug = str_replace( "''", "'", $match[1] );
			$rows = array_filter( $rows, static fn( array $row ): bool => $row['slug'] === $slug );
		}
		$order = str_contains( $query, 'ORDER BY revision' ) ? 'revision' : 'id';
		uasort( $rows, static fn( array $a, array $b ): int => (int) $a[ $order ] <=> (int) $b[ $order ] );
		return array_values( $rows );
	}

	private function table_in( string $query ): string {
		return str_contains( $query, $this->versions_table() ) ? $this->versions_table() : $this->skills_table();
	}

	/** @return array<int, array<string, string|null>> */
	private function rows_for( string $table ): array {
		return $this->versions_table() === $table ? $this->versions : $this->skills;
	}

	/** @param array<string, string|null> $row */
	private function write_row( string $table, int $id, array $row ): void {
		if ( $this->versions_table() === $table ) {
			$this->versions[ $id ] = $row;
		} else {
			$this->skills[ $id ] = $row;
		}
	}

	/**
	 * @param array<string, string|null> $row
	 * @param array<string, mixed>       $where
	 */
	private static function matches( array $row, array $where ): bool {
		foreach ( $where as $column => $expected ) {
			if ( ! array_key_exists( $column, $row ) || self::text( $expected ) !== $row[ $column ] ) {
				return false;
			}
		}
		return true;
	}

	private static function text( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return (string) $value;
	}
}
