<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * wp_insert_post() and wp_update_post() expect slashed input and unslash it before the write, so
 * unslashed block markup or text silently loses its backslashes (for example the & escapes in
 * block comment attributes). Every call that carries free text has to pass its data through
 * wp_slash().
 */
final class PostWriteSlashingTest extends TestCase {

	private const WRITERS   = [ 'wp_insert_post', 'wp_update_post' ];
	private const FREE_TEXT = [ 'post_content', 'post_title', 'post_excerpt' ];

	public function test_every_post_write_that_carries_free_text_slashes_its_data(): void {
		$offenders = [];
		$calls     = 0;
		foreach ( $this->source_files() as $file ) {
			foreach ( $this->writer_calls( (string) file_get_contents( $file ) ) as $call ) {
				++$calls;
				if ( $call['slashed'] ) {
					continue;
				}
				// An unwrapped argument is only acceptable as a literal array without free text.
				if ( $call['literal'] && [] === array_intersect( self::FREE_TEXT, $call['keys'] ) ) {
					continue;
				}
				$offenders[] = substr( $file, strlen( dirname( __DIR__, 3 ) ) + 1 ) . ':' . $call['line'];
			}
		}

		self::assertGreaterThan( 30, $calls, 'The scan found too few post writes; the tokenizer is probably broken.' );
		self::assertSame( [], $offenders, 'Post writes with unslashed free text: ' . implode( ', ', $offenders ) );
	}

	/** @return list<string> */
	private function source_files(): array {
		$root  = dirname( __DIR__, 3 ) . '/includes';
		$files = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = str_replace( '\\', '/', $file->getPathname() );
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * @return list<array{line:int,slashed:bool,literal:bool,keys:list<string>}>
	 */
	private function writer_calls( string $source ): array {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		$calls  = [];
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], self::WRITERS, true ) ) {
				continue;
			}
			$before = $this->neighbor( $tokens, $i, -1 );
			$after  = $this->neighbor( $tokens, $i, 1 );
			if ( '(' !== $after || in_array( $before, [ '->', '::', 'function' ], true ) ) {
				continue;
			}
			$arg = $this->first_argument( $tokens, $i + 1 );
			$calls[] = [
				'line'    => (int) $token[2],
				'slashed' => [] !== $arg && 'wp_slash' === $this->text( $arg[0] ),
				'literal' => [] !== $arg && '[' === $this->text( $arg[0] ),
				'keys'    => $this->string_literals( $arg ),
			];
		}
		return $calls;
	}

	/** @param array<int,mixed> $tokens */
	private function neighbor( array $tokens, int $index, int $step ): string {
		for ( $i = $index + $step; isset( $tokens[ $i ] ); $i += $step ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			return $this->text( $token );
		}
		return '';
	}

	/**
	 * Tokens of the first argument of the call whose "(" sits at $open.
	 *
	 * @param array<int,mixed> $tokens
	 * @return list<mixed>
	 */
	private function first_argument( array $tokens, int $open ): array {
		$depth = 0;
		$arg   = [];
		for ( $i = $open; isset( $tokens[ $i ] ); ++$i ) {
			$token = $tokens[ $i ];
			$text  = $this->text( $token );
			if ( in_array( $text, [ '(', '[', '{' ], true ) ) {
				++$depth;
				if ( 1 === $depth && '(' === $text ) {
					continue;
				}
			} elseif ( in_array( $text, [ ')', ']', '}' ], true ) ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				break;
			}
			if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			$arg[] = $token;
		}
		return $arg;
	}

	/**
	 * @param list<mixed> $arg
	 * @return list<string>
	 */
	private function string_literals( array $arg ): array {
		$out = [];
		foreach ( $arg as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$out[] = trim( $token[1], '\'"' );
			}
		}
		return $out;
	}

	private function text( mixed $token ): string {
		return is_array( $token ) ? (string) $token[1] : (string) $token;
	}
}
