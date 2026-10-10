<?php
/**
 * Inline SVG icons of the admin UI layer, drawn from one printed sprite.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * Icons are 24 x 24 stroke drawings in the colour of the text around them (currentColor), so a state is never
 * told by an icon colour alone and one glyph set serves every theme.
 *
 * The render() method returns a <use> reference and makes sure the sprite is printed once in the page footer. Markup built
 * somewhere the footer will not run (an AJAX response, a REST fragment) must print Icon::sprite() itself.
 */
final class Icon {

	/** @var array<string, string> Name to the SVG shapes inside a 24 x 24 view box. */
	private const SHAPES = [
		'check'     => '<polyline points="20 6 9 17 4 12"/>',
		'x'         => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		'alert'     => '<path d="M12 3 2 21h20L12 3z"/><line x1="12" y1="10" x2="12" y2="15"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
		'info'      => '<circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
		'copy'      => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
		'chev-r'    => '<polyline points="9 6 15 12 9 18"/>',
		'chev-d'    => '<polyline points="6 9 12 15 18 9"/>',
		'search'    => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/>',
		'plug'      => '<path d="M9 3v5M15 3v5M7 8h10v4a5 5 0 0 1-10 0V8zM12 17v4"/>',
		'shield'    => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/>',
		'trash'     => '<polyline points="4 7 20 7"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
		'edit'      => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><line x1="13" y1="7" x2="17" y2="11"/>',
		'eye'       => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		'external'  => '<path d="M14 4h6v6"/><line x1="20" y1="4" x2="11" y2="13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		'refresh'   => '<path d="M20 11a8 8 0 0 0-14-4L4 9"/><polyline points="4 4 4 9 9 9"/><path d="M4 13a8 8 0 0 0 14 4l2-2"/><polyline points="20 20 20 15 15 15"/>',
		'clock'     => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
		'lock'      => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		'bolt'      => '<polyline points="13 3 5 14 12 14 11 21 19 10 12 10 13 3"/>',
		'filter'    => '<polygon points="3 4 21 4 14 12 14 19 10 21 10 12 3 4"/>',
		'plus'      => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
	];

	private static bool $footer_hooked = false;

	/** @return list<string> */
	public static function names(): array {
		return array_keys( self::SHAPES );
	}

	public static function exists( string $name ): bool {
		return isset( self::SHAPES[ $name ] );
	}

	/**
	 * A reference to an icon, hidden from assistive technology. An icon that carries meaning on its own
	 * needs a text alternative next to it, never an icon-only state.
	 *
	 * @param array{size?: string, class?: string|list<string>} $args size is "lg" for 20px, otherwise 16px.
	 */
	public static function render( string $name, array $args = [] ): string {
		if ( ! self::exists( $name ) ) {
			return '';
		}
		self::ensure_sprite();

		$classes = Html::classes( 'sw-ui-icon', 'lg' === ( $args['size'] ?? '' ) ? 'sw-ui-icon--lg' : '', $args['class'] ?? '' );

		return '<svg' . Html::attrs( [ 'class' => $classes, 'aria-hidden' => 'true' ] ) . '><use href="#' . esc_attr( self::symbol_id( $name ) ) . '"></use></svg>';
	}

	public static function symbol_id( string $name ): string {
		return 'sw-ui-icon-' . $name;
	}

	/** The sprite: one hidden SVG holding a <symbol> per icon. */
	public static function sprite(): string {
		$symbols = '';
		foreach ( self::SHAPES as $name => $shapes ) {
			$symbols .= '<symbol id="' . esc_attr( self::symbol_id( $name ) ) . '" viewBox="0 0 24 24">' . $shapes . '</symbol>';
		}

		return '<svg class="sw-ui-sprite" aria-hidden="true" width="0" height="0" xmlns="http://www.w3.org/2000/svg">' . $symbols . '</svg>';
	}

	/** Print the sprite in the footer of the page that uses an icon, once. */
	public static function ensure_sprite(): void {
		if ( self::$footer_hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$footer_hooked = true;
		add_action( 'admin_footer', [ self::class, 'print_sprite' ], 1 );
	}

	/** Footer callback: the sprite is built from fixed shapes and escaped ids, so it is safe to print. */
	public static function print_sprite(): void {
		echo self::sprite(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup from this class.
	}

	/** Forget that the footer hook was added. For tests. */
	public static function reset_for_tests(): void {
		self::$footer_hooked = false;
	}
}
