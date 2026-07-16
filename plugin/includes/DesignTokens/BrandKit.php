<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignTokens;

use Stonewright\WpMcp\Security\Backup;

/**
 * Loads and applies bundled brand kits from plugin/brand-kits/*.json.
 *
 * Apply scope (conservative by design):
 * - Always persists the active kit under option `stonewright_active_brand_kit`.
 * - Sets theme mods for primary/secondary/accent colors and heading/body fonts
 *   when the keys are simple strings (no Elementor dependency).
 * - When an Elementor active kit post exists, merges custom_colors and
 *   custom_typography into `_elementor_page_settings` after Backup::snapshot_post.
 * - Does NOT rewrite Elementor system_colors, global classes, or site CSS
 *   completely — full kit replacement is out of scope for brand-kit-apply.
 */
final class BrandKit {

	public const OPTION_ACTIVE = 'stonewright_active_brand_kit';

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list( string $search = '' ): array {
		$out = [];
		foreach ( self::paths() as $path ) {
			$raw = self::decode_file( $path );
			if ( null === $raw ) {
				continue;
			}
			$summary = self::summarize( $raw, $path );
			if ( '' !== $search ) {
				$hay = mb_strtolower(
					(string) ( $summary['id'] ?? '' ) . ' '
					. (string) ( $summary['name'] ?? '' ) . ' '
					. (string) ( $summary['description'] ?? '' )
				);
				if ( ! str_contains( $hay, mb_strtolower( $search ) ) ) {
					continue;
				}
			}
			$out[] = $summary;
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) )
		);

		return $out;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( string $id ) {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return new \WP_Error(
				'stonewright_brand_kit_invalid_id',
				__( 'Brand kit id is required.', 'stonewright' )
			);
		}

		foreach ( self::paths() as $path ) {
			$raw = self::decode_file( $path );
			if ( null === $raw ) {
				continue;
			}
			$candidate = sanitize_key( (string) ( $raw['id'] ?? basename( $path, '.json' ) ) );
			if ( $candidate !== $id ) {
				continue;
			}
			return self::normalize( $raw, $path );
		}

		return new \WP_Error(
			'stonewright_brand_kit_not_found',
			sprintf(
				/* translators: %s: brand kit id */
				__( 'Brand kit "%s" was not found.', 'stonewright' ),
				$id
			)
		);
	}

	/**
	 * Apply a brand kit to site options / theme mods / Elementor kit (best-effort).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function apply( string $id ) {
		$kit = self::get( $id );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		update_option( self::OPTION_ACTIVE, $kit, false );

		$theme_mods = [];
		$colors     = is_array( $kit['colors'] ?? null ) ? $kit['colors'] : [];
		$fonts      = is_array( $kit['fonts'] ?? null ) ? $kit['fonts'] : [];

		foreach ( [ 'primary', 'secondary', 'accent', 'background', 'surface', 'text' ] as $key ) {
			if ( ! empty( $colors[ $key ] ) && is_string( $colors[ $key ] ) ) {
				$mod = 'stonewright_color_' . $key;
				set_theme_mod( $mod, $colors[ $key ] );
				$theme_mods[] = $mod;
			}
		}
		foreach ( [ 'heading', 'body' ] as $key ) {
			if ( ! empty( $fonts[ $key ] ) && is_string( $fonts[ $key ] ) ) {
				$mod = 'stonewright_font_' . $key;
				set_theme_mod( $mod, $fonts[ $key ] );
				$theme_mods[] = $mod;
			}
		}

		$elementor = self::apply_elementor_kit( $kit );

		return [
			'ok'              => true,
			'brand_kit_id'    => (string) $kit['id'],
			'name'            => (string) $kit['name'],
			'theme_mods'      => $theme_mods,
			'elementor'       => $elementor,
			'scope'           => [
				'options'   => [ self::OPTION_ACTIVE ],
				'theme_mods'=> $theme_mods,
				'elementor' => $elementor['applied'] ?? false,
				'notes'     => 'Conservative apply: options + theme mods always; Elementor custom_colors/custom_typography only when an active kit post exists. System colors, global classes, and full kit replacement are out of scope.',
			],
		];
	}

	/**
	 * Merge brand kit colors/fonts into a DesignSpec tokens section.
	 *
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $kit
	 * @return array<string, mixed>
	 */
	public static function merge_into_spec( array $spec, array $kit ): array {
		$tokens = isset( $spec['tokens'] ) && is_array( $spec['tokens'] ) ? $spec['tokens'] : [];
		$colors = isset( $tokens['colors'] ) && is_array( $tokens['colors'] ) ? $tokens['colors'] : [];
		$fonts  = isset( $tokens['fonts'] ) && is_array( $tokens['fonts'] ) ? $tokens['fonts'] : [];
		$spacing = isset( $tokens['spacing'] ) && is_array( $tokens['spacing'] ) ? $tokens['spacing'] : [];

		foreach ( (array) ( $kit['colors'] ?? [] ) as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && '' !== $value ) {
				$colors[ $key ] = $value;
			}
		}
		foreach ( (array) ( $kit['fonts'] ?? [] ) as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && '' !== $value ) {
				$fonts[ $key ] = $value;
			}
		}
		foreach ( (array) ( $kit['spacing'] ?? [] ) as $key => $value ) {
			if ( is_string( $key ) && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$spacing[ $key ] = (string) $value;
			}
		}

		if ( [] !== $colors ) {
			$tokens['colors'] = $colors;
		}
		if ( [] !== $fonts ) {
			$tokens['fonts'] = $fonts;
		}
		if ( [] !== $spacing ) {
			$tokens['spacing'] = $spacing;
		}
		if ( [] !== $tokens ) {
			$spec['tokens'] = $tokens;
		}

		return $spec;
	}

	/**
	 * @return list<string>
	 */
	public static function paths(): array {
		$dir = self::directory();
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$files = glob( $dir . '/*.json' );
		return is_array( $files ) ? array_values( array_filter( $files, 'is_readable' ) ) : [];
	}

	public static function directory(): string {
		return trailingslashit( STONEWRIGHT_DIR ) . 'brand-kits';
	}

	/**
	 * Semantic color roles expected in brand kits v2.
	 *
	 * @return list<string>
	 */
	public static function semantic_color_roles(): array {
		return [
			'primary',
			'secondary',
			'accent',
			'background',
			'surface',
			'text',
			'text_muted',
			'border',
			'success',
			'warning',
			'danger',
		];
	}

	/**
	 * Default foreground/background pairs that should meet WCAG contrast when present.
	 *
	 * @return list<array{fg: string, bg: string, min_ratio: float}>
	 */
	public static function contrast_pairs(): array {
		return [
			[ 'fg' => 'text', 'bg' => 'background', 'min_ratio' => 4.5 ],
			[ 'fg' => 'text', 'bg' => 'surface', 'min_ratio' => 4.5 ],
			[ 'fg' => 'primary', 'bg' => 'background', 'min_ratio' => 3.0 ],
			[ 'fg' => 'accent', 'bg' => 'background', 'min_ratio' => 3.0 ],
		];
	}

	/**
	 * Validate semantic color roles and contrast pairs for a brand kit.
	 *
	 * @param array<string, mixed> $kit Normalized or raw kit.
	 * @return array{
	 *   ok: bool,
	 *   missing_roles: list<string>,
	 *   pairs: list<array{fg: string, bg: string, ratio: float|null, min_ratio: float, pass: bool|null, reason: string}>
	 * }
	 */
	public static function validate_contrast_pairs( array $kit ): array {
		$colors = isset( $kit['colors'] ) && is_array( $kit['colors'] ) ? $kit['colors'] : [];
		$missing = [];
		// Soft-required roles for v2 kits.
		foreach ( [ 'primary', 'background', 'text' ] as $role ) {
			if ( empty( $colors[ $role ] ) || ! is_string( $colors[ $role ] ) ) {
				$missing[] = $role;
			}
		}

		$pairs = [];
		foreach ( self::contrast_pairs() as $pair ) {
			$fg = (string) $pair['fg'];
			$bg = (string) $pair['bg'];
			$min = (float) $pair['min_ratio'];
			$fg_hex = isset( $colors[ $fg ] ) && is_string( $colors[ $fg ] ) ? $colors[ $fg ] : '';
			$bg_hex = isset( $colors[ $bg ] ) && is_string( $colors[ $bg ] ) ? $colors[ $bg ] : '';

			if ( '' === $fg_hex || '' === $bg_hex ) {
				$pairs[] = [
					'fg'        => $fg,
					'bg'        => $bg,
					'ratio'     => null,
					'min_ratio' => $min,
					'pass'      => null,
					'reason'    => 'missing_color',
				];
				continue;
			}

			$ratio = self::contrast_ratio( $fg_hex, $bg_hex );
			if ( null === $ratio ) {
				$pairs[] = [
					'fg'        => $fg,
					'bg'        => $bg,
					'ratio'     => null,
					'min_ratio' => $min,
					'pass'      => null,
					'reason'    => 'unparseable_color',
				];
				continue;
			}

			$pairs[] = [
				'fg'        => $fg,
				'bg'        => $bg,
				'ratio'     => round( $ratio, 2 ),
				'min_ratio' => $min,
				'pass'      => $ratio >= $min,
				'reason'    => $ratio >= $min ? 'ok' : 'below_min_ratio',
			];
		}

		$hard_fail = [] !== $missing;
		foreach ( $pairs as $row ) {
			if ( false === $row['pass'] ) {
				$hard_fail = true;
				break;
			}
		}

		return [
			'ok'            => ! $hard_fail,
			'missing_roles' => $missing,
			'pairs'         => $pairs,
		];
	}

	/**
	 * Relative luminance contrast ratio for two CSS hex colors (WCAG).
	 */
	public static function contrast_ratio( string $foreground, string $background ): ?float {
		$l1 = self::relative_luminance( $foreground );
		$l2 = self::relative_luminance( $background );
		if ( null === $l1 || null === $l2 ) {
			return null;
		}
		$lighter = max( $l1, $l2 );
		$darker  = min( $l1, $l2 );
		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * @return float|null 0..1
	 */
	public static function relative_luminance( string $color ): ?float {
		$rgb = self::parse_hex_color( $color );
		if ( null === $rgb ) {
			return null;
		}
		$channels = array_map(
			static function ( int $channel ): float {
				$c = $channel / 255;
				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);
		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	public static function parse_hex_color( string $color ): ?array {
		$color = trim( $color );
		if ( 1 !== preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color, $m ) ) {
			return null;
		}
		$hex = $m[1];
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return [
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		];
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public static function summarize( array $raw, string $path = '' ): array {
		$normalized = self::normalize( $raw, $path );
		$contrast   = self::validate_contrast_pairs( $normalized );
		return [
			'id'              => (string) $normalized['id'],
			'name'            => (string) $normalized['name'],
			'description'     => (string) $normalized['description'],
			'version'         => (string) $normalized['version'],
			'colors'          => (array) $normalized['colors'],
			'semantic_roles'  => (array) $normalized['semantic_roles'],
			'fonts'           => (array) $normalized['fonts'],
			'radius'          => (array) $normalized['radius'],
			'spacing'         => (array) $normalized['spacing'],
			'contrast'        => [
				'ok'            => $contrast['ok'],
				'missing_roles' => $contrast['missing_roles'],
			],
		];
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw, string $path = '' ): array {
		$id = sanitize_key( (string) ( $raw['id'] ?? ( '' !== $path ? basename( $path, '.json' ) : '' ) ) );
		$colors = isset( $raw['colors'] ) && is_array( $raw['colors'] ) ? $raw['colors'] : [];
		$roles  = [];
		foreach ( self::semantic_color_roles() as $role ) {
			if ( isset( $colors[ $role ] ) && is_string( $colors[ $role ] ) && '' !== $colors[ $role ] ) {
				$roles[ $role ] = $colors[ $role ];
			}
		}
		return [
			'id'             => $id,
			'name'           => (string) ( $raw['name'] ?? $id ),
			'description'    => (string) ( $raw['description'] ?? '' ),
			'version'        => (string) ( $raw['version'] ?? '2.0.0' ),
			'colors'         => $colors,
			'semantic_roles' => $roles,
			'fonts'          => isset( $raw['fonts'] ) && is_array( $raw['fonts'] ) ? $raw['fonts'] : [],
			'radius'         => isset( $raw['radius'] ) && is_array( $raw['radius'] ) ? $raw['radius'] : [],
			'spacing'        => isset( $raw['spacing'] ) && is_array( $raw['spacing'] ) ? $raw['spacing'] : [],
		];
	}

	/**
	 * @param array<string, mixed> $kit
	 * @return array<string, mixed>
	 */
	private static function apply_elementor_kit( array $kit ): array {
		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		if ( $kit_id <= 0 ) {
			return [
				'applied'     => false,
				'reason'      => 'no_active_kit',
				'kit_id'      => 0,
				'snapshot_id' => '',
			];
		}

		$post = get_post( $kit_id );
		if ( ! $post ) {
			return [
				'applied'     => false,
				'reason'      => 'kit_post_missing',
				'kit_id'      => $kit_id,
				'snapshot_id' => '',
			];
		}

		$snapshot_id = Backup::snapshot_post( $kit_id );
		if ( '' === $snapshot_id ) {
			return [
				'applied'     => false,
				'reason'      => 'backup_failed',
				'kit_id'      => $kit_id,
				'snapshot_id' => '',
			];
		}

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$custom_colors = isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] )
			? $settings['custom_colors']
			: [];
		$by_id = [];
		foreach ( $custom_colors as $row ) {
			if ( is_array( $row ) && isset( $row['_id'] ) ) {
				$by_id[ (string) $row['_id'] ] = $row;
			}
		}

		foreach ( (array) ( $kit['colors'] ?? [] ) as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$id = 'sw_' . sanitize_key( $key );
			$by_id[ $id ] = [
				'_id'   => $id,
				'title' => ucwords( str_replace( [ '-', '_' ], ' ', $key ) ),
				'color' => $value,
			];
		}
		$settings['custom_colors'] = array_values( $by_id );

		$custom_typo = isset( $settings['custom_typography'] ) && is_array( $settings['custom_typography'] )
			? $settings['custom_typography']
			: [];
		$typo_by_id = [];
		foreach ( $custom_typo as $row ) {
			if ( is_array( $row ) && isset( $row['_id'] ) ) {
				$typo_by_id[ (string) $row['_id'] ] = $row;
			}
		}
		foreach ( (array) ( $kit['fonts'] ?? [] ) as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$id = 'sw_font_' . sanitize_key( $key );
			$typo_by_id[ $id ] = [
				'_id'              => $id,
				'title'            => ucwords( str_replace( [ '-', '_' ], ' ', $key ) ),
				'typography_font_family' => $value,
			];
		}
		$settings['custom_typography'] = array_values( $typo_by_id );

		update_post_meta( $kit_id, '_elementor_page_settings', $settings );

		return [
			'applied'     => true,
			'reason'      => 'merged_custom_colors_typography',
			'kit_id'      => $kit_id,
			'snapshot_id' => $snapshot_id,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function decode_file( string $path ): ?array {
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
