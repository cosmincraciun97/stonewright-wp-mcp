<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core\Fixtures;

/**
 * The part of wpdb the uninstall handler uses, over in-memory tables and the options of the
 * test bootstrap. Every statement is recorded. DROP TABLE removes the table from $tables.
 * An option-name search follows MySQL: the prepared value is un-escaped the way MySQL reads a
 * string literal, then a backslash escapes the next character of the LIKE pattern while %
 * and _ are wildcards. Any other statement fails the test.
 */
final class UninstallWpdb extends \wpdb {

	/** @var list<string> Statements run, in order. */
	public array $statements = [];

	/** @var array<string, true> Full names of the tables that exist. */
	public array $tables = [];

	/** Returns true to make a write throw, as a failing database layer would. */
	public ?\Closure $fail = null;

	public function __construct( string $prefix = 'wptests_' ) {
		$this->use_prefix( $prefix );
	}

	/** The way switch_to_blog() repoints the connection at another site's tables. */
	public function use_prefix( string $prefix ): void {
		$this->prefix = $prefix;
		$this->options = $prefix . 'options';
	}

	/** @param mixed ...$args */
	public function prepare( $query, ...$args ) {
		$index = 0;
		$prepared = preg_replace_callback(
			'/%[a-z%]/',
			static function ( array $placeholder ) use ( &$args, &$index ): string {
				if ( '%s' !== $placeholder[0] ) {
					throw new \LogicException( 'Only %s is supported.' );
				}
				return "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $args[ $index++ ] ) . "'";
			},
			(string) $query
		);
		return (string) $prepared;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_col( $query = null, $x = 0 ) {
		$this->statements[] = (string) $query;
		if ( ! preg_match( '/^SELECT option_name FROM (\S+) WHERE option_name LIKE \'((?:[^\'\\\\]|\\\\.)*)\'$/s', (string) $query, $match ) ) {
			throw new \LogicException( 'Unexpected read: ' . $query );
		}
		if ( $this->options !== $match[1] ) {
			throw new \LogicException( 'The search must read the options table of the current site, not ' . $match[1] );
		}
		$regex = self::like_to_regex( (string) preg_replace( '/\\\\(.)/s', '$1', $match[2] ) );
		$names = array_map( 'strval', array_keys( $GLOBALS['stonewright_test_options'] ?? [] ) );
		return array_values( array_filter( $names, static fn ( string $name ): bool => 1 === preg_match( $regex, $name ) ) );
	}

	public function query( $query ) {
		$this->statements[] = (string) $query;
		if ( null !== $this->fail && ( $this->fail )( (string) $query ) ) {
			throw new \RuntimeException( 'Synthetic failure.' );
		}
		if ( ! preg_match( '/^DROP TABLE IF EXISTS `([A-Za-z0-9_]+)`$/', (string) $query, $match ) ) {
			throw new \LogicException( 'Unexpected statement: ' . $query );
		}
		unset( $this->tables[ $match[1] ] );
		return true;
	}

	private static function like_to_regex( string $like ): string {
		$regex = '';
		$length = strlen( $like );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $like[ $index ];
			if ( '\\' === $char && $index + 1 < $length ) {
				$regex .= preg_quote( $like[ ++$index ], '/' );
			} elseif ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}
		return '/^' . $regex . '$/is';
	}
}
