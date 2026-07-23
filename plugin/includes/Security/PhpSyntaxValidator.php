<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * In-process PHP syntax validation for complete candidate files.
 *
 * Uses token_get_all(..., TOKEN_PARSE) so production hosts need no shell php -l.
 */
final class PhpSyntaxValidator {

	/**
	 * Validate a complete PHP source unit (e.g. full functions.php candidate).
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_complete_file( string $source, string $php_runtime = PHP_VERSION ) {
		$source = self::ensure_php_open_tag( $source );

		if ( ! defined( 'TOKEN_PARSE' ) ) {
			return new \WP_Error(
				'stonewright_php_validator_unavailable',
				__( 'TOKEN_PARSE is unavailable; cannot validate PHP candidates in-process.', 'stonewright' ),
				[ 'status' => 500, 'retryable' => false ]
			);
		}

		try {
			// TOKEN_PARSE throws ParseError on invalid complete units.
			$tokens = token_get_all( $source, TOKEN_PARSE );
			if ( ! is_array( $tokens ) || [] === $tokens ) {
				return new \WP_Error(
					'stonewright_php_candidate_invalid',
					__( 'Complete PHP candidate produced no tokens.', 'stonewright' ),
					[ 'status' => 400, 'retryable' => false, 'error_code' => 'php_candidate_invalid' ]
				);
			}
		} catch ( \ParseError $e ) {
			return new \WP_Error(
				'stonewright_php_candidate_invalid',
				sprintf(
					/* translators: %s: parse error message */
					__( 'Complete PHP candidate failed syntax validation: %s', 'stonewright' ),
					$e->getMessage()
				),
				[
					'status'         => 400,
					'retryable'      => false,
					'error_code'     => 'php_candidate_invalid',
					'parse_message'  => $e->getMessage(),
					'parse_line'     => $e->getLine(),
					'php_runtime'    => $php_runtime,
					'validator'      => 'token_get_all:TOKEN_PARSE',
				]
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'stonewright_php_candidate_invalid',
				sprintf(
					/* translators: %s: error message */
					__( 'Complete PHP candidate failed validation: %s', 'stonewright' ),
					$e->getMessage()
				),
				[
					'status'     => 400,
					'retryable'  => false,
					'error_code' => 'php_candidate_invalid',
				]
			);
		}

		// Reject bare assignment statements that are common toxic appends:
		// identifiers used without $ in statement position after a semicolon.
		if ( self::looks_like_bare_assignment_corruption( $source ) ) {
			return new \WP_Error(
				'stonewright_php_candidate_invalid',
				__( 'Complete PHP candidate contains bare assignment statements without variable prefixes (invalid PHP).', 'stonewright' ),
				[
					'status'     => 400,
					'retryable'  => false,
					'error_code' => 'php_candidate_bare_assignment',
					'cause_key'  => 'php_bare_assignment',
				]
			);
		}

		return true;
	}

	/**
	 * Ensure the candidate is evaluated as a full file unit.
	 */
	public static function ensure_php_open_tag( string $source ): string {
		$trim = ltrim( $source );
		if ( str_starts_with( $trim, '<?php' ) || str_starts_with( $trim, '<?=' ) || str_starts_with( $trim, '<?' ) ) {
			return $source;
		}
		return "<?php\n" . $source;
	}

	/**
	 * Heuristic for the incident class: `obfuscated = ...` without `$`.
	 */
	private static function looks_like_bare_assignment_corruption( string $source ): bool {
		// Strip strings and comments roughly via tokens when possible.
		$tokens = @token_get_all( $source );
		if ( ! is_array( $tokens ) ) {
			return (bool) preg_match( '/(?:^|[\n;{}])\s*[A-Za-z_][A-Za-z0-9_]*\s*=\s*[^=]/m', $source );
		}

		$prev_significant = null;
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				[ $id, $text ] = $token;
				if ( in_array( $id, [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
					continue;
				}
				if ( T_STRING === $id ) {
					// T_STRING followed by '=' after statement boundary is invalid bare assign
					// only when previous was ; { } or start — token_get_all with TOKEN_PARSE
					// would already reject true parse errors; this catches edge encodings.
					$prev_significant = [ 'string', $text ];
					continue;
				}
				$prev_significant = [ 'id', $id, $text ];
				continue;
			}
			if ( '=' === $token && is_array( $prev_significant ) && 'string' === $prev_significant[0] ) {
				// Could be class const / enum; only flag if previous boundary was terminator.
				// Rely primarily on TOKEN_PARSE; keep this as soft secondary check via regex.
			}
			$prev_significant = [ 'char', $token ];
		}

		// Direct pattern for the known incident shape outside strings.
		$stripped = preg_replace( '/[\'"][^\'"]*[\'"]/', '""', $source ) ?? $source;
		$stripped = preg_replace( '#//.*$#m', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '#/\*.*?\*/#s', '', $stripped ) ?? $stripped;
		return (bool) preg_match( '/(?:^|[\n;{}])\s*[A-Za-z_][A-Za-z0-9_]*\s*=\s*(?![=])/', $stripped );
	}
}
