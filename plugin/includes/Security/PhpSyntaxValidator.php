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
		$result = self::run_validation( $source, $php_runtime );

		// A candidate that cannot parse is a rule block, not a soft failure.
		if ( $result instanceof \WP_Error && 'stonewright_php_candidate_invalid' === $result->get_error_code() ) {
			return RuleEnforcer::attribute( $result, 'php-writes-must-parse' );
		}

		return $result;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function run_validation( string $source, string $php_runtime ) {
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

		// No secondary heuristic runs here. TOKEN_PARSE is the only authority on
		// PHP grammar: a bare assignment is already a parse error, and a regex
		// that guesses at one also rejects class constants, enum cases, heredoc
		// bodies, and inline HTML attributes.
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
}
