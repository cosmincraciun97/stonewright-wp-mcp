<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * Blocks Elementor custom-CSS writes unless a consumed custom_code_grant
 * accompanies the incoming payload. Reuses the Theme Custom CSS grant pipeline.
 */
final class ElementorCustomCssGate {

	public const ERROR_CODE = 'stonewright_custom_code_approval_required';
	public const CLASS_ERROR_CODE = 'stonewright_css_classes_not_approved';
	public const GRANT_PATH = 'elementor/custom-css';
	public const GRANT_HTML_PATH = 'elementor/html-style';
	public const GATED_TOOL = 'stonewright/theme-custom-css';
	public const GATED_MCP_TOOL = 'stonewright-theme-custom-css';
	public const OPTION = 'stonewright_approved_css_classes';

	/** Most rejected classes the message of the refusal lists; the data lists them all. */
	private const MESSAGE_CLASSES = 10;

	/** @var list<string> */
	private const CSS_KEYS = [
		'custom_css',
		'_custom_css',
		'customcss',
		'page_custom_css',
		'kit_custom_css',
		'additional_custom_css',
		'custom_css_pro',
	];

	/** @var list<string> */
	private static array $renderer_classes = [];

	private static bool $grant_active = false;

	public static function reset(): void {
		self::$renderer_classes = [];
		self::$grant_active     = false;
	}

	public static function register_renderer_class( string $class ): void {
		$class = sanitize_html_class( $class );
		if ( '' === $class ) {
			return;
		}
		self::$renderer_classes[] = $class;
	}

	/**
	 * @return list<string>
	 */
	public static function approved_css_classes(): array {
		$raw = get_option( self::OPTION, [] );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\s+/', $raw ) ?: [];
		}
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$classes = [];
		foreach ( $raw as $class ) {
			if ( ! is_scalar( $class ) ) {
				continue;
			}
			$clean = sanitize_html_class( (string) $class );
			if ( '' !== $clean ) {
				$classes[] = $clean;
			}
		}
		$classes = array_values( array_unique( array_merge( $classes, self::$renderer_classes ) ) );
		$filtered = apply_filters( 'stonewright_approved_css_classes', $classes );
		if ( ! is_array( $filtered ) ) {
			return $classes;
		}
		$out = [];
		foreach ( $filtered as $class ) {
			if ( ! is_scalar( $class ) ) {
				continue;
			}
			$clean = sanitize_html_class( (string) $class );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Inspect an incoming write payload (not a merged live document).
	 *
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $args
	 * @return true|\WP_Error
	 */
	public static function assert_incoming( array $payload, array $args = [], string $widget_type = '' ): bool|\WP_Error {
		$findings = self::collect( $payload, $widget_type );

		$class_error = self::assert_css_classes( $findings['classes'] );
		if ( $class_error instanceof \WP_Error ) {
			return $class_error;
		}

		if ( [] === $findings['css_keys'] && [] === $findings['html_style'] ) {
			return true;
		}

		if ( self::$grant_active ) {
			return true;
		}

		$grant = trim( (string) ( $args['custom_code_grant'] ?? '' ) );
		$first = $findings['css_keys'][0] ?? null;
		$offending = is_array( $first ) ? (string) $first['key'] : 'html';
		$candidate = self::candidate_body( $findings['css_keys'], $findings['html_style'] );
		if ( [] !== $findings['html_style'] && [] === $findings['css_keys'] ) {
			$offending = 'html';
		}

		if ( '' === $grant ) {
			return self::approval_required( $offending, $candidate, $findings );
		}

		$html_only = [] !== $findings['html_style'] && [] === $findings['css_keys'];
		$path      = $html_only ? self::GRANT_HTML_PATH : self::GRANT_PATH;
		$language  = $html_only ? 'html' : 'css';
		$consumed  = CustomCodeGrant::verify_and_consume(
			$grant,
			$path,
			hash( 'sha256', $candidate ),
			$language,
			strlen( $candidate )
		);
		if ( $consumed instanceof \WP_Error ) {
			return $consumed;
		}
		self::$grant_active = true;
		return true;
	}

	public static function is_css_key( string $key ): bool {
		$normalized = strtolower( str_replace( '-', '_', $key ) );
		if ( in_array( $normalized, self::CSS_KEYS, true ) ) {
			return true;
		}
		return (bool) preg_match( '/^_?custom_css(?:_|$)/', $normalized );
	}

	/**
	 * What a payload would trip when it is written, without writing it: the CSS classes the site has not approved,
	 * the custom CSS keys that need a human grant, and whether an HTML widget carries a style tag.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{rejected_classes: list<string>, css_keys: list<string>, html_style: bool}
	 */
	public static function inspect( array $payload ): array {
		$findings = self::collect( $payload, '' );

		return [
			'rejected_classes' => self::rejected_classes( $findings['classes'] ),
			'css_keys'         => array_values( array_unique( array_map( static fn( array $row ): string => $row['key'], $findings['css_keys'] ) ) ),
			'html_style'       => [] !== $findings['html_style'],
		];
	}

	/**
	 * The top-level settings the gate would refuse right now, by key, each with the reason: a CSS class the site has
	 * not approved, or custom CSS (or an HTML style tag) that needs a human-issued grant while none is consumed.
	 * It reads the settings and the site's allowlist only; it records nothing and asks for no grant.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, string> Key to `css_classes_not_approved` or `custom_css_needs_approval`.
	 */
	public static function refused_settings( array $settings ): array {
		$refused = [];
		foreach ( $settings as $key => $value ) {
			$findings = self::collect( [ (string) $key => $value ], '' );
			if ( [] !== self::rejected_classes( $findings['classes'] ) ) {
				$refused[ (string) $key ] = 'css_classes_not_approved';
			} elseif ( ! self::$grant_active && ( [] !== $findings['css_keys'] || [] !== $findings['html_style'] ) ) {
				$refused[ (string) $key ] = 'custom_css_needs_approval';
			}
		}

		return $refused;
	}

	/**
	 * The value with every custom CSS key that holds nothing removed, so a key that is only a placeholder is not
	 * mistaken for custom CSS. An Atomic style variant always has a `custom_css` key, null when it has none.
	 *
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	public static function without_empty_css_keys( array $value ): array {
		$out = [];
		foreach ( $value as $key => $member ) {
			if ( is_string( $key ) && self::is_css_key( $key ) && ( null === $member || '' === $member || [] === $member ) ) {
				continue;
			}
			$out[ $key ] = is_array( $member ) ? self::without_empty_css_keys( $member ) : $member;
		}

		return $out;
	}

	/**
	 * @param list<string> $class_strings
	 * @return list<string>
	 */
	private static function rejected_classes( array $class_strings ): array {
		$allow    = self::approved_css_classes();
		$rejected = [];
		foreach ( $class_strings as $raw ) {
			foreach ( preg_split( '/\s+/', trim( $raw ) ) ?: [] as $class ) {
				$class = sanitize_html_class( $class );
				if ( '' !== $class && ! in_array( $class, $allow, true ) ) {
					$rejected[] = $class;
				}
			}
		}

		return array_values( array_unique( $rejected ) );
	}

	/**
	 * @param list<string> $class_strings
	 */
	private static function assert_css_classes( array $class_strings ): ?\WP_Error {
		if ( [] === $class_strings ) {
			return null;
		}
		$allow    = self::approved_css_classes();
		$rejected = self::rejected_classes( $class_strings );
		if ( [] === $rejected ) {
			return null;
		}
		$shown = array_slice( $rejected, 0, self::MESSAGE_CLASSES );
		$more  = count( $rejected ) - count( $shown );
		return new \WP_Error(
			self::CLASS_ERROR_CODE,
			sprintf(
				/* translators: 1: the rejected classes, 2: the number of further classes (a sentence part, empty when none) */
				__( 'Elementor CSS classes must be present in the approved_css_classes allowlist. Not approved: %1$s%2$s. A site approves a class by adding it to the stonewright_approved_css_classes option (or through the stonewright_approved_css_classes filter); otherwise leave the class out and use native Elementor controls.', 'stonewright' ),
				implode( ', ', $shown ),
				$more > 0 ? sprintf( /* translators: %d: number of further classes */ __( ' and %d more', 'stonewright' ), $more ) : ''
			),
			[
				'status'                => 400,
				'retryable'             => true,
				'offending_key'         => '_css_classes',
				'rejected_classes'      => $rejected,
				'approved_css_classes'  => $allow,
				'repair'                => 'Use only classes from approved_css_classes, or omit _css_classes and use native Elementor controls.',
			]
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{css_keys: list<array{key:string,value:mixed}>, classes: list<string>, html_style: list<string>}
	 */
	private static function collect( array $payload, string $widget_type ): array {
		$css_keys   = [];
		$classes    = [];
		$html_style = [];

		if ( isset( $payload['setting'] ) && is_string( $payload['setting'] ) && self::is_css_key( $payload['setting'] ) ) {
			$css_keys[] = [
				'key'   => (string) $payload['setting'],
				'value' => $payload['value'] ?? '',
			];
		}

		$walk = static function ( mixed $data ) use ( &$walk, &$css_keys, &$classes, &$html_style ): void {
			if ( ! is_array( $data ) ) {
				return;
			}
			foreach ( $data as $key => $value ) {
				$key = (string) $key;
				if ( self::is_css_key( $key ) ) {
					$css_keys[] = [ 'key' => $key, 'value' => $value ];
					continue;
				}
				if ( in_array( $key, [ '_css_classes', 'css_classes' ], true ) && is_scalar( $value ) ) {
					$classes[] = (string) $value;
					continue;
				}
				if ( 'html' === $key && is_string( $value ) && self::contains_style_tag( $value ) ) {
					$html_style[] = $value;
					continue;
				}
				if ( is_array( $value ) ) {
					$walk( $value );
				}
			}
		};
		$walk( $payload );

		return [
			'css_keys'   => $css_keys,
			'classes'    => $classes,
			'html_style' => $html_style,
		];
	}

	public static function contains_style_tag( string $html ): bool {
		return 1 === preg_match( '/<style\b/i', $html );
	}

	/**
	 * @param list<array{key:string,value:mixed}> $css_keys
	 * @param list<string> $html_style
	 */
	private static function candidate_body( array $css_keys, array $html_style ): string {
		$parts = [];
		foreach ( $css_keys as $row ) {
			$value = $row['value'];
			$parts[] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		}
		foreach ( $html_style as $html ) {
			$parts[] = $html;
		}
		return implode( "\n", $parts );
	}

	/**
	 * @param array{css_keys: list<array{key:string,value:mixed}>, classes: list<string>, html_style: list<string>} $findings
	 */
	private static function approval_required( string $offending_key, string $candidate, array $findings ): \WP_Error {
		$proposal = CustomCodeGrant::missing_grant_proposal(
			[
				'path'                => [] !== $findings['html_style'] && [] === $findings['css_keys']
					? self::GRANT_HTML_PATH
					: self::GRANT_PATH,
				'language'            => [] !== $findings['html_style'] && [] === $findings['css_keys'] ? 'html' : 'css',
				'execution_status'    => 'blocked',
				'verification_status' => 'blocked',
				'rollback_status'     => 'not_needed',
				'effect_verified'     => false,
				'resource_type'       => 'custom_css',
				'resource_ref'        => self::GATED_TOOL,
			]
		);
		return new \WP_Error(
			self::ERROR_CODE,
			__( 'Elementor custom CSS requires a human-issued custom-code grant. Use stonewright/theme-custom-css with dry_run:true, show the approval URL, then stop.', 'stonewright' ),
			array_merge(
				[
					'status'              => 400,
					'retryable'           => false,
					'offending_key'       => $offending_key,
					'offending_keys'      => array_values(
						array_unique(
							array_merge(
								array_map( static fn( array $row ): string => $row['key'], $findings['css_keys'] ),
								[] !== $findings['html_style'] ? [ 'html' ] : []
							)
						)
					),
					'gated_tool'          => self::GATED_TOOL,
					'gated_mcp_tool'      => self::GATED_MCP_TOOL,
					'candidate'           => mb_substr( $candidate, 0, 4000 ),
					'approval_flow'       => [
						'Run stonewright/theme-custom-css (MCP: stonewright-theme-custom-css) with dry_run:true and a native_gap proving native Elementor controls cannot satisfy the effect.',
						'Show the user approval_url, exact path, byte counts, and change summary, then stop.',
						'Do not open the approval page, issue or retrieve a grant, or apply unless the user explicitly asks.',
						'After the human returns custom_code_grant, apply only the approved candidate on stonewright/theme-custom-css.',
					],
				],
				$proposal
			)
		);
	}
}
