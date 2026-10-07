<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Stylesheet checks for the shell and the Abilities page: each test names the defect it keeps fixed.
 *
 * @coversNothing
 */
final class AdminShellCssTest extends TestCase {

	/**
	 * The most `!important` declarations each older stylesheet may contain. A fix must not add one; lowering a
	 * number is always fine.
	 */
	private const IMPORTANT_CEILING = [
		'admin/abilities.css'     => 1,
		'admin/admin.css'         => 2,
		'admin/audit.css'         => 18,
		'admin/block-queue.css'   => 0,
		'admin/blueprints.css'    => 0,
		'admin/dashboard.css'     => 3,
		'admin/design-studio.css' => 0,
		'admin/sandbox.css'       => 21,
		'admin/setup.css'         => 1,
		'admin/shell.css'         => 51,
		'admin/skills-memory.css' => 10,
		'admin/visual-workspace.css' => 0,
		'css/stonewright-admin.css'  => 5,
	];

	private static function shell(): string {
		return CssSource::read( 'admin/shell.css' );
	}

	/** Declarations of the one rule with exactly this selector list. @return list<array{name: string, value: string, important: bool}> */
	private static function rule( string $css, string $selector, string $context = '' ): array {
		return CssSource::rule_declarations( $css, $selector, $context );
	}

	private static function value( string $css, string $selector, string $property, string $context = '' ): ?string {
		return CssSource::value_of( self::rule( $css, $selector, $context ), $property );
	}

	/** The first rule whose selector list contains this selector. @return list<array{name: string, value: string, important: bool}> */
	private static function rule_with_selector( string $css, string $selector ): array {
		foreach ( CssSource::rules( $css ) as $rule ) {
			if ( ! $rule['at'] && in_array( $selector, CssSource::selectors( $rule['selector'] ), true ) ) {
				return CssSource::declarations( $rule['body'] );
			}
		}

		throw new \RuntimeException( 'No rule has the selector ' . $selector );
	}

	// ---------------------------------------------------------------------------------------------
	// Sticky chrome
	// ---------------------------------------------------------------------------------------------

	public function test_the_shell_is_not_a_scroll_container_so_sticky_children_can_stick(): void {
		$declarations = self::rule( self::shell(), '.sw-shell' );

		self::assertSame( 'clip', CssSource::value_of( $declarations, 'overflow-x' ), 'overflow-x: hidden made .sw-shell the sticky scroll container.' );
		foreach ( $declarations as $declaration ) {
			self::assertNotSame( 'hidden', $declaration['value'], $declaration['name'] . ' must not be hidden on the shell.' );
			self::assertNotSame( 'auto', $declaration['value'], $declaration['name'] );
		}
		// The block formatting context hidden used to give is kept, without the scroll container.
		self::assertSame( 'flow-root', CssSource::value_of( $declarations, 'display' ) );
	}

	public function test_the_shell_header_scrolls_with_the_page_until_the_compact_header_ships(): void {
		$declarations = self::rule( self::shell(), '.sw-shell__header' );

		self::assertSame( 'static', CssSource::value_of( $declarations, 'position' ) );
	}

	public function test_the_notice_drawer_sits_directly_under_the_header(): void {
		$css          = self::shell();
		$declarations = self::rule( $css, '.sw-notice-drawer' );

		self::assertSame( 'var(--sw-space-3) auto 0', CssSource::value_of( $declarations, 'margin' ) );
		self::assertStringNotContainsString( 'max(', (string) CssSource::value_of( $declarations, 'margin' ), 'The margin no longer follows the header height.' );
	}

	public function test_only_the_admin_bar_is_counted_as_fixed_chrome(): void {
		$css = self::shell();

		self::assertSame( 'var(--wp-admin--admin-bar--height, 32px)', CssSource::custom_properties( $css, static fn ( string $s ): bool => ':root' === $s )['--sw-shell-offset'] );
		self::assertSame( 'var(--sw-shell-offset, 32px)', self::value( $css, 'html.sw-has-shell', 'scroll-padding-top' ) );
	}

	public function test_the_abilities_filter_bar_sticks_under_the_admin_bar_and_not_on_a_phone(): void {
		$css = CssSource::read( 'admin/abilities.css' );

		self::assertSame( 'var(--wp-admin--admin-bar--height, 32px)', self::value( $css, '.sw-abilities-filters', 'top' ) );
		self::assertSame( 'sticky', self::value( $css, '.sw-abilities-filters', 'position' ) );
		self::assertSame( 'static', self::value( $css, '.sw-abilities-filters', 'position', '@media screen and (max-width: 782px)' ), 'A stacked filter bar must not be sticky on a phone.' );
		self::assertStringNotContainsString( '--sw-shell-offset', $css );
	}

	public function test_the_abilities_search_field_keeps_its_height_when_the_toolbar_stacks(): void {
		$css = CssSource::read( 'admin/abilities.css' );

		// In a column flex container a flex-basis is a height: 1 1 160px made the field 160px tall.
		self::assertSame( '0 0 auto', self::value( $css, '.sw-abilities-search', 'flex', '@media screen and (max-width: 960px)' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Buttons
	// ---------------------------------------------------------------------------------------------

	/**
	 * Resolve one property of a WordPress button inside .sw-shell the way the cascade does.
	 *
	 * @param array{tag: string, classes: list<string>, attrs: array<string, string>, hover: bool} $element
	 */
	private static function winner( string $css, array $element, string $property ): ?string {
		$ancestors = [ [ 'tag' => 'div', 'classes' => [ 'sw-shell' ], 'attrs' => [], 'hover' => false ] ];
		$best      = null;
		foreach ( CssSource::rules( $css ) as $rule ) {
			if ( $rule['at'] || '' !== $rule['context'] ) {
				continue;
			}
			foreach ( CssSource::selectors( $rule['selector'] ) as $selector ) {
				if ( ! self::selector_matches( $selector, $element, $ancestors ) ) {
					continue;
				}
				foreach ( CssSource::declarations( $rule['body'] ) as $declaration ) {
					if ( $declaration['name'] !== $property ) {
						continue;
					}
					$candidate = [ $declaration['important'] ? 1 : 0, CssSource::specificity( $selector ), $rule['order'], $declaration['value'] ];
					if ( null === $best || array_slice( $candidate, 0, 3 ) >= array_slice( $best, 0, 3 ) ) {
						$best = $candidate;
					}
				}
			}
		}

		return null === $best ? null : $best[3];
	}

	/**
	 * Descendant-only selector matching, enough for the .button rules of shell.css.
	 *
	 * @param array{tag: string, classes: list<string>, attrs: array<string, string>, hover: bool} $element
	 * @param list<array{tag: string, classes: list<string>, attrs: array<string, string>, hover: bool}> $ancestors
	 */
	private static function selector_matches( string $selector, array $element, array $ancestors ): bool {
		if ( 1 === preg_match( '/[>+~]/', $selector ) ) {
			return false;
		}
		$compounds = preg_split( '/\s+(?![^\[]*\])/', trim( $selector ) ) ?: [];
		$subject   = array_pop( $compounds );
		if ( ! self::compound_matches( (string) $subject, $element ) ) {
			return false;
		}
		foreach ( array_reverse( $compounds ) as $compound ) {
			$found = false;
			while ( [] !== $ancestors ) {
				$ancestor = array_shift( $ancestors );
				if ( self::compound_matches( $compound, $ancestor ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}

		return true;
	}

	/** @param array{tag: string, classes: list<string>, attrs: array<string, string>, hover: bool} $node */
	private static function compound_matches( string $compound, array $node ): bool {
		if ( 1 === preg_match( '/^([a-zA-Z][\w-]*|\*)/', $compound, $tag ) ) {
			if ( '*' !== $tag[1] && strtolower( $tag[1] ) !== $node['tag'] ) {
				return false;
			}
			$compound = substr( $compound, strlen( $tag[1] ) );
		}
		while ( '' !== $compound ) {
			if ( 1 === preg_match( '/^\.([\w-]+)/', $compound, $m ) ) {
				if ( ! in_array( $m[1], $node['classes'], true ) ) {
					return false;
				}
			} elseif ( 1 === preg_match( '/^\[([\w-]+)(?:=("?)([^\]"]*)\2)?\]/', $compound, $m ) ) {
				if ( ! array_key_exists( $m[1], $node['attrs'] ) || ( isset( $m[3] ) && $m[3] !== $node['attrs'][ $m[1] ] ) ) {
					return false;
				}
			} elseif ( 1 === preg_match( '/^:hover/', $compound, $m ) ) {
				if ( ! $node['hover'] ) {
					return false;
				}
			} else {
				return false; // Any other pseudo-class or functional selector: not modelled, so it does not match.
			}
			$compound = substr( $compound, strlen( $m[0] ) );
		}

		return true;
	}

	/** @return array{tag: string, classes: list<string>, attrs: array<string, string>, hover: bool} */
	private static function button( string $tag, array $classes, array $attrs = [], bool $hover = false ): array {
		return [ 'tag' => $tag, 'classes' => $classes, 'attrs' => $attrs, 'hover' => $hover ];
	}

	public function test_a_wordpress_primary_submit_renders_as_primary_not_secondary(): void {
		$css = self::shell();

		// submit_button() prints <input type="submit" class="button button-primary">. The element-qualified
		// secondary rule used to beat the primary rule on specificity and demoted every one of them.
		foreach ( [ 'background', 'border-color', 'color' ] as $property ) {
			$primary_input = self::winner( $css, self::button( 'input', [ 'button', 'button-primary' ], [ 'type' => 'submit' ] ), $property );
			$primary_tag   = self::winner( $css, self::button( 'button', [ 'button', 'button-primary' ], [ 'type' => 'submit' ] ), $property );
			$secondary     = self::winner( $css, self::button( 'input', [ 'button' ], [ 'type' => 'submit' ] ), $property );

			self::assertNotNull( $primary_input, $property );
			self::assertSame( $primary_tag, $primary_input, $property . ': an input.button-primary must look like a button.button-primary.' );
			self::assertNotSame( $secondary, $primary_input, $property . ': primary and secondary must differ.' );
		}

		self::assertSame( 'var(--sw-brand-fill)', self::winner( $css, self::button( 'input', [ 'button', 'button-primary' ], [ 'type' => 'submit' ] ), 'background' ) );
		self::assertSame( 'var(--sw-on-brand)', self::winner( $css, self::button( 'input', [ 'button', 'button-primary' ], [ 'type' => 'submit' ] ), 'color' ) );
		self::assertSame(
			'var(--sw-brand-fill)',
			self::winner( $css, self::button( 'input', [ 'button', 'button-primary' ], [ 'type' => 'submit' ], true ), 'background' ),
			'A primary submit stays primary under the pointer.'
		);
	}

	public function test_a_secondary_button_keeps_its_look_and_hover(): void {
		$css = self::shell();

		foreach ( [ self::button( 'input', [ 'button' ], [ 'type' => 'submit' ] ), self::button( 'button', [ 'button' ] ), self::button( 'a', [ 'button' ] ) ] as $element ) {
			self::assertSame( 'var(--sw-surface)', self::winner( $css, $element, 'background' ) );
			$hovered = $element;
			$hovered['hover'] = true;
			self::assertSame( 'var(--sw-bg)', self::winner( $css, $hovered, 'background' ) );
			self::assertSame( 'var(--sw-brand)', self::winner( $css, $hovered, 'color' ) );
		}
	}

	public function test_no_button_rule_is_qualified_by_an_input_type(): void {
		foreach ( CssSource::rules( self::shell() ) as $rule ) {
			foreach ( CssSource::selectors( $rule['selector'] ) as $selector ) {
				self::assertDoesNotMatchRegularExpression( '/input\[type=[^\]]*\]\.button/', $selector, 'Element-qualified .button selectors outrank the primary rule.' );
			}
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Code blocks
	// ---------------------------------------------------------------------------------------------

	public function test_inline_code_chips_do_not_apply_inside_a_pre_block(): void {
		$css = self::shell();

		// The chip rule: top-level rules that paint code. (The phone rule that lets code scroll is layout only.)
		$chip_selectors = [];
		foreach ( CssSource::rules( $css ) as $rule ) {
			if ( '' !== $rule['context'] || $rule['at'] ) {
				continue;
			}
			$painted = array_column( CssSource::declarations( $rule['body'] ), 'name' );
			if ( in_array( 'border-radius', $painted, true ) && in_array( 'padding', $painted, true ) && in_array( 'background', $painted, true ) ) {
				foreach ( CssSource::selectors( $rule['selector'] ) as $selector ) {
					if ( str_ends_with( explode( ':where', $selector )[0], 'code' ) ) {
						$chip_selectors[] = $selector;
					}
				}
			}
		}

		foreach ( [ '.sw-shell .wp-list-table code', '.sw-shell table.widefat code', '.sw-shell code' ] as $base ) {
			$selector = $base . ':where(:not(pre code))';
			self::assertContains( $selector, $chip_selectors, $selector . ' must be a chip selector.' );
			self::assertNotContains( $base, $chip_selectors, $base . ' would still chip code inside pre.' );
			self::assertSame( CssSource::specificity( $base ), CssSource::specificity( $selector ), 'Excluding pre code must not change specificity.' );
		}
	}

	public function test_code_inside_a_pre_block_is_plain_text_of_the_block(): void {
		$declarations = self::rule( self::shell(), '.sw-shell pre code' );

		self::assertSame( 'transparent', CssSource::value_of( $declarations, 'background' ) );
		self::assertSame( '0', CssSource::value_of( $declarations, 'border' ) );
		self::assertSame( '0', CssSource::value_of( $declarations, 'padding' ) );
		self::assertSame( 'inherit', CssSource::value_of( $declarations, 'color' ) );
		foreach ( $declarations as $declaration ) {
			self::assertFalse( $declaration['important'], 'The reset must not need !important.' );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Contrast of the legacy tokens (WCAG 1.4.3 and 1.4.11)
	// ---------------------------------------------------------------------------------------------

	/** @return array<string, string> The legacy token map from the :root block. */
	private static function legacy_tokens(): array {
		return CssSource::custom_properties( self::shell(), static fn ( string $s ): bool => ':root' === $s );
	}

	private static function token_rgb( string $name ): array {
		return CssSource::hex_to_rgb( self::legacy_tokens()[ $name ] );
	}

	public function test_muted_text_reads_on_every_surface_and_status_tint(): void {
		$muted = self::token_rgb( '--sw-text-muted' );

		foreach ( [ '--sw-surface', '--sw-bg', '--sw-surface-raised', '--sw-ok-soft', '--sw-warn-soft', '--sw-danger-soft', '--sw-info-soft', '--sw-brand-soft' ] as $background ) {
			self::assertGreaterThanOrEqual(
				4.5,
				CssSource::contrast( $muted, self::token_rgb( $background ) ),
				'--sw-text-muted on ' . $background . ' was 4.34:1 to 4.48:1 on four of these.'
			);
		}
	}

	public function test_the_shell_header_labels_and_focus_ring_read_on_the_dark_header(): void {
		$tokens = self::legacy_tokens();
		$header = CssSource::hex_to_rgb( $tokens['--sw-shell-header-bg'] );
		$alpha  = static function ( string $value ): array {
			self::assertSame( 1, preg_match( '/^rgba\(\s*(\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\s*\)$/', $value, $m ), $value );

			return [ [ (float) $m[1], (float) $m[2], (float) $m[3] ], (float) $m[4] ];
		};

		[ $label, $label_alpha ] = $alpha( $tokens['--sw-shell-header-muted'] );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( CssSource::over( $label, $label_alpha, $header ), $header ), 'Group labels were 4.32:1.' );

		// The focus ring on the header is the header text colour, set where the header is declared.
		self::assertSame( 'var(--sw-shell-header-fg)', self::value( self::shell(), '.sw-shell__header', '--sw-focus-ring' ) );
		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( CssSource::hex_to_rgb( $tokens['--sw-shell-header-fg'] ), $header ), 'The indigo ring was 2.82:1 on the header.' );
	}

	public function test_form_control_borders_meet_non_text_contrast(): void {
		$css    = self::shell();
		$border = self::token_rgb( '--sw-border-control' );

		foreach ( [ '--sw-surface', '--sw-bg' ] as $background ) {
			self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( $border, self::token_rgb( $background ) ), '--sw-border-control on ' . $background );
		}

		$selector = '.sw-shell input[type="text"]';
		$rule     = self::rule_with_selector( $css, $selector );
		self::assertSame( 'var(--sw-border-control)', CssSource::value_of( $rule, 'border-color' ), 'The field border was --sw-border: 1.26:1.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Shared stylesheet cleanup
	// ---------------------------------------------------------------------------------------------

	public function test_the_design_system_stylesheet_no_longer_redeclares_the_root_tokens(): void {
		$css = CssSource::read( 'css/stonewright-admin.css' );

		foreach ( CssSource::rules( $css ) as $rule ) {
			self::assertNotSame( ':root', $rule['selector'], 'Tokens are declared once, in shell.css.' );
		}

		// The values that block made effective live in shell.css now, so nothing on a page changes size.
		$tokens = self::legacy_tokens();
		self::assertSame( '8px', $tokens['--sw-radius'] );
		self::assertSame( '4px', $tokens['--sw-radius-sm'] );
		self::assertSame( '0 2px 8px rgba(0, 0, 0, .08)', $tokens['--sw-shadow'] );
		self::assertSame( '0 4px 16px rgba(0, 0, 0, .14)', $tokens['--sw-shadow-hover'] );
		self::assertSame( '.18s var(--sw-ease)', $tokens['--sw-transition'] );
	}

	public function test_badges_are_readable_size_and_not_forced_to_uppercase(): void {
		$declarations = self::rule( CssSource::read( 'css/stonewright-admin.css' ), '.sw-badge' );

		self::assertSame( 'var(--sw-text-xs)', CssSource::value_of( $declarations, 'font-size' ), 'Badges were 10px, below the 12px floor.' );
		self::assertNull( CssSource::value_of( $declarations, 'text-transform' ) );
		self::assertNull( CssSource::value_of( $declarations, 'letter-spacing' ), 'Letter spacing only made sense for uppercase.' );
	}

	public function test_rules_whose_text_colour_equals_their_background_are_gone(): void {
		$css = CssSource::read( 'css/stonewright-admin.css' );

		self::assertStringNotContainsString( '.sw-paste-block', $css );
		foreach ( CssSource::rules( $css ) as $rule ) {
			self::assertNotSame( '.sw-badge--category', $rule['selector'], 'It painted muted text on a muted background.' );
		}
	}

	/** @dataProvider classes_defined_once */
	public function test_a_class_that_had_no_style_is_defined_once( string $class ): void {
		$definitions = 0;
		foreach ( array_merge( glob( CssSource::assets_dir() . '/admin/*.css' ) ?: [], glob( CssSource::assets_dir() . '/css/*.css' ) ?: [] ) as $file ) {
			if ( str_ends_with( $file, 'design-studio.css' ) || str_ends_with( $file, 'visual-workspace.css' ) ) {
				continue; // Not registered on any page.
			}
			foreach ( CssSource::rules( (string) file_get_contents( $file ) ) as $rule ) {
				if ( ! $rule['at'] && in_array( $class, CssSource::selectors( $rule['selector'] ), true ) ) {
					++$definitions;
				}
			}
		}

		self::assertSame( 1, $definitions, $class . ' must have exactly one definition.' );
	}

	/** @return array<string, array{0: string}> */
	public static function classes_defined_once(): array {
		return [
			'muted badge' => [ '.sw-badge--muted' ],
			'pill'        => [ '.sw-pill' ],
		];
	}

	public function test_the_muted_badge_and_the_pill_have_readable_colours(): void {
		$tokens = self::legacy_tokens();
		$css    = CssSource::read( 'css/stonewright-admin.css' );

		$muted = self::rule( $css, '.sw-badge--muted' );
		self::assertSame( 'var(--sw-bg)', CssSource::value_of( $muted, 'background' ) );
		self::assertSame( 'var(--sw-text-secondary)', CssSource::value_of( $muted, 'color' ) );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( CssSource::hex_to_rgb( $tokens['--sw-text-secondary'] ), CssSource::hex_to_rgb( $tokens['--sw-bg'] ) ) );

		$pill = self::rule( $css, '.sw-pill' );
		self::assertSame( 'var(--sw-brand-soft)', CssSource::value_of( $pill, 'background' ) );
		self::assertSame( 'var(--sw-brand-strong)', CssSource::value_of( $pill, 'color' ) );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( CssSource::hex_to_rgb( $tokens['--sw-brand-strong'] ), CssSource::hex_to_rgb( $tokens['--sw-brand-soft'] ) ) );
		self::assertGreaterThanOrEqual( 12.0, (float) filter_var( $tokens['--sw-text-xs'], FILTER_SANITIZE_NUMBER_FLOAT ) );
	}

	// ---------------------------------------------------------------------------------------------
	// No new !important
	// ---------------------------------------------------------------------------------------------

	/** @dataProvider legacy_stylesheets */
	public function test_fixes_do_not_add_important_declarations( string $relative, int $ceiling ): void {
		$count = substr_count( CssSource::strip_comments( CssSource::read( $relative ) ), '!important' );

		self::assertLessThanOrEqual( $ceiling, $count, $relative . ' has ' . $count . ' !important declarations; it had ' . $ceiling . '.' );
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function legacy_stylesheets(): array {
		$cases = [];
		foreach ( self::IMPORTANT_CEILING as $file => $ceiling ) {
			$cases[ $file ] = [ $file, $ceiling ];
		}

		return $cases;
	}
}
