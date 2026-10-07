<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support;

/**
 * Just enough of wpdb for the rescue runtime: the two option lookups it makes, on the
 * options store of the test harness ($GLOBALS['stonewright_test_options']).
 */
final class MuFakeWpdb extends \wpdb {

	/** A quoted SQL string: anything but a quote or a backslash, or a backslash and one character. */
	private const QUOTED = '\'((?:[^\'\\\\]|\\\\.)*)\'';

	public $options = 'wptests_options';

	public $prefix = 'wptests_';

	/** @var list<string> Every statement that was run. */
	public array $statements = [];

	/** @var (callable(string): void)|null Called with the option name after a lookup, to simulate another request. */
	public $after_select = null;

	/** @param mixed ...$args */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$position = 0;
		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $match ) use ( &$args, &$position ): string {
				$value = $args[ $position++ ] ?? '';
				return '%d' === $match[0]
					? (string) (int) $value
					: "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $value ) . "'";
			},
			(string) $query
		);
	}

	/** Rows inserted by AuditLog::record() are collected where the harness collects them for other tests. */
	public function insert( $table, $data, $format = [] ) {
		++$this->insert_id;
		$GLOBALS['stonewright_test_wpdb_inserts'][] = [ 'table' => (string) $table, 'data' => (array) $data ];
		return 1;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$this->statements[] = (string) $query;
		if ( 1 !== preg_match( '/^SELECT option_value FROM \S+ WHERE option_name = ' . self::QUOTED . ' LIMIT 1$/', (string) $query, $match ) ) {
			throw new \LogicException( 'Unexpected get_var(): ' . $query );
		}
		$name  = stripslashes( $match[1] );
		$store = $GLOBALS['stonewright_test_options'] ?? [];
		$value = array_key_exists( $name, $store ) && is_scalar( $store[ $name ] ) ? (string) $store[ $name ] : null;
		if ( null !== $this->after_select ) {
			( $this->after_select )( $name );
		}
		return $value;
	}

	public function get_results( $query = null, $output = OBJECT ) {
		$this->statements[] = (string) $query;
		if ( 1 !== preg_match( '/^SELECT option_name, option_value FROM \S+ WHERE option_name LIKE ' . self::QUOTED . '$/', (string) $query, $match ) ) {
			throw new \LogicException( 'Unexpected get_results(): ' . $query );
		}
		// The statement quotes the pattern once and esc_like() escaped it once before that.
		$prefix = rtrim( stripslashes( stripslashes( $match[1] ) ), '%' );
		$rows   = [];
		foreach ( $GLOBALS['stonewright_test_options'] ?? [] as $name => $value ) {
			if ( str_starts_with( (string) $name, $prefix ) && is_scalar( $value ) ) {
				$rows[] = [ 'option_name' => (string) $name, 'option_value' => (string) $value ];
			}
		}
		return $rows;
	}
}
