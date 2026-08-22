<?php
declare( strict_types=1 );

/**
 * Token-based security-audit detectors shared with unit tests.
 *
 * @package Stonewright\WpMcp
 */

/**
 * Find language-level assert() calls whose first argument is a string or variable.
 *
 * Ignores method calls (::assert, ->assert, ?->assert), `new assert()`, and
 * function/method declarations named assert. Detection is token-based, not regex.
 *
 * @param array<int, string> $files
 * @param array<int, string> $skip_basenames
 * @return array<int, array{file:string, line:int, text:string}>
 */
function sw_find_unsafe_assert_calls( array $files, array $skip_basenames = [] ): array {
	$hits = [];

	foreach ( $files as $path ) {
		if ( in_array( basename( $path ), $skip_basenames, true ) ) {
			continue;
		}

		$src = file_get_contents( $path );
		if ( false === $src ) {
			continue;
		}

		$tokens = sw_assert_tokenize( $src );
		$lines  = preg_split( '/\R/', $src ) ?: [];
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! sw_assert_is_language_assert_name( $token ) ) {
				continue;
			}

			$prev = sw_assert_significant_index( $tokens, $i, -1 );
			if ( null !== $prev && sw_assert_is_forbidden_predecessor( $tokens[ $prev ] ) ) {
				continue;
			}

			$next = sw_assert_significant_index( $tokens, $i, 1 );
			if ( null === $next || '(' !== sw_assert_token_text( $tokens[ $next ] ) ) {
				continue;
			}

			$first = sw_assert_significant_index( $tokens, $next, 1 );
			if ( null === $first ) {
				continue;
			}

			if ( ! sw_assert_is_unsafe_first_arg( $tokens[ $first ] ) ) {
				continue;
			}

			$line  = sw_assert_token_line( $token );
			$hits[] = [
				'file' => $path,
				'line' => $line,
				'text' => trim( $lines[ $line - 1 ] ?? sw_assert_token_text( $token ) ),
			];
		}
	}

	return $hits;
}

/**
 * @return array<int, mixed>
 */
function sw_assert_tokenize( string $src ): array {
	if ( defined( 'TOKEN_PARSE' ) ) {
		try {
			return token_get_all( $src, TOKEN_PARSE );
		} catch ( ParseError ) {
			return token_get_all( $src );
		}
	}

	return token_get_all( $src );
}

/**
 * @param mixed $token
 */
function sw_assert_is_language_assert_name( $token ): bool {
	if ( ! is_array( $token ) ) {
		return false;
	}

	if ( T_STRING === $token[0] && 'assert' === $token[1] ) {
		return true;
	}

	return defined( 'T_NAME_FULLY_QUALIFIED' )
		&& T_NAME_FULLY_QUALIFIED === $token[0]
		&& '\\assert' === $token[1];
}

/**
 * @param mixed $token
 */
function sw_assert_is_forbidden_predecessor( $token ): bool {
	$id = sw_assert_token_id( $token );
	if ( T_OBJECT_OPERATOR === $id || T_DOUBLE_COLON === $id || T_FUNCTION === $id || T_NEW === $id ) {
		return true;
	}

	return defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $id;
}

/**
 * @param mixed $token
 */
function sw_assert_is_unsafe_first_arg( $token ): bool {
	$id = sw_assert_token_id( $token );
	if ( T_VARIABLE === $id || T_CONSTANT_ENCAPSED_STRING === $id || T_START_HEREDOC === $id ) {
		return true;
	}

	return '"' === sw_assert_token_text( $token );
}

/**
 * @param mixed $token
 */
function sw_assert_is_ignorable( $token ): bool {
	return is_array( $token )
		&& in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true );
}

/**
 * @param array<int, mixed> $tokens
 */
function sw_assert_significant_index( array $tokens, int $from, int $direction ): ?int {
	$i     = $from + $direction;
	$count = count( $tokens );
	while ( $i >= 0 && $i < $count ) {
		if ( ! sw_assert_is_ignorable( $tokens[ $i ] ) ) {
			return $i;
		}
		$i += $direction;
	}

	return null;
}

/**
 * @param mixed $token
 * @return int|string
 */
function sw_assert_token_id( $token ) {
	return is_array( $token ) ? $token[0] : $token;
}

/**
 * @param mixed $token
 */
function sw_assert_token_text( $token ): string {
	return is_array( $token ) ? (string) $token[1] : (string) $token;
}

/**
 * @param mixed $token
 */
function sw_assert_token_line( $token ): int {
	return is_array( $token ) ? (int) $token[2] : 1;
}
