<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\ThemeJson;

use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * Theme.json custom CSS (`styles.css` and per-block `css`) requires the same
 * custom-code grant pipeline as Customizer CSS. Values are never stripped.
 */
final class CssGrantGate {

	public const GRANT_PATH = 'fse/global-styles/css';

	/**
	 * @param array<string, mixed> $theme_json
	 */
	public static function assert( array $theme_json, string $grant, bool $consume = true ): ?\WP_Error {
		$payloads = self::collect( $theme_json );
		if ( [] === $payloads ) {
			return null;
		}

		$hash = self::candidate_hash( $payloads );
		$bytes = 0;
		foreach ( $payloads as $css ) {
			$bytes += strlen( $css );
		}

		if ( '' !== $grant ) {
			if ( ! $consume ) {
				return null;
			}
			$ok = CustomCodeGrant::verify_and_consume( $grant, self::GRANT_PATH, $hash, 'css', $bytes );
			return $ok instanceof \WP_Error ? $ok : null;
		}

		$paths    = array_keys( $payloads );
		$proposal = CustomCodeGrant::missing_grant_proposal(
			[
				'path'                => self::GRANT_PATH,
				'language'            => 'css',
				'after_sha256'        => $hash,
				'changed_bytes'       => $bytes,
				'resource_type'       => 'theme_json_css',
				'resource_ref'        => self::GRANT_PATH,
				'execution_status'    => 'blocked',
				'verification_status' => 'blocked',
			]
		);

		return new \WP_Error(
			'stonewright_custom_code_approval_required',
			__( 'theme.json custom CSS requires a human-issued custom_code_grant. Prefer styles.color, styles.typography, styles.spacing, and settings.color.palette presets. Do not strip the CSS.', 'stonewright' ),
			array_merge(
				[
					'status'                     => 400,
					'retryable'                  => false,
					'offending_path'             => $paths[0],
					'offending_paths'            => $paths,
					'native_alternative'         => __( 'Use theme.json color, typography, and spacing presets, or Gutenberg block supports. Custom CSS uses stonewright-theme-custom-css or this ability with custom_code_grant after dry_run.', 'stonewright' ),
					'path'                       => self::GRANT_PATH,
					'language'                   => 'css',
					'after_sha256'               => $hash,
					'custom_code_grant_required' => true,
					'dry_run_tool'               => 'stonewright-theme-custom-css',
				],
				$proposal
			)
		);
	}

	/**
	 * @param array<string, string> $payloads
	 */
	public static function candidate_hash( array $payloads ): string {
		ksort( $payloads );
		return hash( 'sha256', (string) wp_json_encode( $payloads ) );
	}

	/**
	 * @param array<string, mixed> $theme_json
	 * @return array<string, string>
	 */
	public static function collect( array $theme_json ): array {
		$found = [];
		self::walk( $theme_json, [], $found );
		return $found;
	}

	/**
	 * @param array<string, mixed>|mixed $node
	 * @param list<string>               $path
	 * @param array<string, string>      $found
	 */
	private static function walk( mixed $node, array $path, array &$found ): void {
		if ( ! is_array( $node ) ) {
			return;
		}
		foreach ( $node as $key => $value ) {
			$key  = (string) $key;
			$here = array_merge( $path, [ $key ] );
			if ( 'css' === $key && is_string( $value ) && '' !== trim( $value ) ) {
				$found[ implode( '.', $here ) ] = $value;
				continue;
			}
			if ( is_array( $value ) ) {
				self::walk( $value, $here, $found );
			}
		}
	}
}
