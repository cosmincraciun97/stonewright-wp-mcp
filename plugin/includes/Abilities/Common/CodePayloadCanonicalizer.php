<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Common;

use Stonewright\WpMcp\Abilities\Themes\ThemeFilePaths;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;
use Stonewright\WpMcp\Support\CodeFormatter;

/**
 * Canonicalizes code payload args before security decisions bind to their bytes.
 */
final class CodePayloadCanonicalizer {

	private const DECODE_FLAG = 'decode_escaped_layout';

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function canonicalize( string $ability, array $args ): array|\WP_Error {
		if ( ! in_array( $ability, self::targets(), true ) ) {
			return $args;
		}

		$decode = false;
		if ( array_key_exists( self::DECODE_FLAG, $args ) ) {
			if ( ! is_bool( $args[ self::DECODE_FLAG ] ) ) {
				return self::invalid_payload(
					$ability,
					self::DECODE_FLAG,
					__( 'decode_escaped_layout must be a boolean.', 'stonewright' )
				);
			}
			$decode = true === $args[ self::DECODE_FLAG ];
		}

		// False is the canonical default representation. True remains in the
		// args so confirmation tokens stay bound to the explicit opt-in.
		if ( $decode ) {
			$args[ self::DECODE_FLAG ] = true;
		} else {
			unset( $args[ self::DECODE_FLAG ] );
		}

		switch ( $ability ) {
			case 'stonewright/php-execute':
				return self::canonicalize_php_body( $args, $decode );

			case 'stonewright/theme-file-patch':
				return self::canonicalize_theme_patch( $args, $decode );

			case 'stonewright/sandbox-write':
				return self::canonicalize_field( $args, 'contents', 'php', $decode, $ability );

			case 'stonewright/theme-custom-css':
				return self::canonicalize_field( $args, 'css', 'css', $decode, $ability );
		}

		return $args;
	}

	/**
	 * @return list<string>
	 */
	private static function targets(): array {
		return [
			'stonewright/php-execute',
			'stonewright/theme-file-patch',
			'stonewright/sandbox-write',
			'stonewright/theme-custom-css',
		];
	}

	/**
	 * Preserve php-execute's established tag stripping and trim semantics while
	 * making the resulting body the only bytes guards, tokens, audit, and eval see.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function canonicalize_php_body( array $args, bool $decode ): array|\WP_Error {
		if ( ! array_key_exists( 'code', $args ) ) {
			return $args;
		}
		if ( ! is_string( $args['code'] ) ) {
			return self::invalid_payload(
				'stonewright/php-execute',
				'code',
				__( 'code must be a string.', 'stonewright' )
			);
		}

		$code = trim( $args['code'] );
		if ( str_starts_with( $code, '<?php' ) ) {
			$code = substr( $code, 5 );
		} elseif ( str_starts_with( $code, '<?' ) ) {
			$code = substr( $code, 2 );
		}

		$args['code'] = trim( CodeFormatter::normalize( trim( $code ), 'php', $decode ) );
		return $args;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function canonicalize_theme_patch( array $args, bool $decode ): array|\WP_Error {
		if ( ! isset( $args['path'] ) || ! is_string( $args['path'] ) ) {
			return new \WP_Error(
				'stonewright_code_payload_language_undetermined',
				__( 'A string theme path is required before code payload language can be determined.', 'stonewright' ),
				[
					'status'    => 400,
					'ability'   => 'stonewright/theme-file-patch',
					'field'     => 'path',
					'retryable' => false,
				]
			);
		}

		$path = ThemeFilePaths::normalise_relative( $args['path'] );
		if ( $path instanceof \WP_Error ) {
			return $path;
		}

		$args['path'] = $path;
		$language     = ThemeWriteTransaction::detect_language( $path );

		return self::canonicalize_field(
			$args,
			'content',
			$language,
			$decode,
			'stonewright/theme-file-patch'
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function canonicalize_field(
		array $args,
		string $field,
		string $language,
		bool $decode,
		string $ability
	): array|\WP_Error {
		if ( ! array_key_exists( $field, $args ) ) {
			return $args;
		}
		if ( ! is_string( $args[ $field ] ) ) {
			return self::invalid_payload(
				$ability,
				$field,
				sprintf(
					/* translators: %s: input field name. */
					__( '%s must be a string.', 'stonewright' ),
					$field
				)
			);
		}

		$args[ $field ] = CodeFormatter::normalize(
			$args[ $field ],
			$language,
			$decode
		);
		return $args;
	}

	private static function invalid_payload( string $ability, string $field, string $message ): \WP_Error {
		return new \WP_Error(
			'stonewright_code_payload_invalid',
			$message,
			[
				'status'    => 400,
				'ability'   => $ability,
				'field'     => $field,
				'retryable' => false,
			]
		);
	}
}
