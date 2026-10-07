<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Rules the shared admin UI layer (assets/admin/sw-ui.css) keeps, written as checks:
 * a rule nobody checks is a rule that erodes.
 *
 * @coversNothing
 */
final class SwUiCssContractTest extends TestCase {

	private const FILE   = 'admin/sw-ui.css';
	private const MARKER = '/* === components';

	/** Highest specificity any selector may have. */
	private const MAX_SPECIFICITY = [ 0, 2, 0 ];

	/** Properties that may move: layout never does. The two discrete ones are allowed only with allow-discrete. */
	private const ANIMATABLE = [ 'opacity', 'transform', 'color', 'background-color', 'border-color', 'background-position' ];
	private const DISCRETE   = [ 'display', 'overlay' ];

	/** Classes the layer's own rules may name that are not its own: core's body classes for the colour scheme. */
	private const EXTERNAL_CLASS = '/^admin-color-[a-z]+$/';

	/** Custom properties WordPress defines that the layer reads. */
	private const EXTERNAL_TOKENS = [
		'--wp-admin-theme-color',
		'--wp-admin-theme-color-darker-10',
		'--wp-admin-theme-color-darker-20',
		'--wp-admin-border-width-focus',
		'--wp-admin--admin-bar--height',
	];

	/**
	 * Files that may name the layer. Pages that adopt it in a later phase join this list in the same change;
	 * until then no older file may use a class, token hook or handle of the layer.
	 */
	private const ALLOWED_TO_NAME_THE_LAYER = [
		'includes/Admin/AdminBootstrap.php',
		// The frame prints the page header and the hub tab bar with the layer's helpers.
		'includes/Admin/AdminShell.php',
		// The Overview is the first page built wholly from the layer; its stylesheet only places the sparkline.
		'includes/Admin/Pages/StatusPage.php',
		'assets/admin/pages/overview.css',
	];

	private static function css(): string {
		return CssSource::read( self::FILE );
	}

	/** The part of the file that may define raw colours. */
	private static function token_section(): string {
		$css = self::css();
		$cut = strpos( $css, self::MARKER );
		self::assertNotFalse( $cut, 'The file needs the "' . self::MARKER . '" marker between tokens and components.' );

		return substr( $css, 0, (int) $cut );
	}

	private static function components_section(): string {
		$css = self::css();

		return substr( $css, (int) strpos( $css, self::MARKER ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Shape of the file
	// ---------------------------------------------------------------------------------------------

	public function test_the_file_ships_with_a_licence_header_and_stays_small(): void {
		$css = self::css();

		self::assertStringStartsWith( '/* SPDX-License-Identifier: GPL-2.0-or-later */', $css );
		self::assertLessThan( 64 * 1024, strlen( $css ), 'The component layer should stay under 64 KB before compression.' );
		self::assertStringNotContainsString( "\r", $css, 'LF line endings.' );
		self::assertStringNotContainsString( 'prefers-color-scheme', $css, 'The admin is light only.' );
	}

	public function test_there_is_no_important_and_no_cascade_layer(): void {
		$css = CssSource::strip_comments( self::css() );

		self::assertStringNotContainsString( '!important', $css );
		self::assertDoesNotMatchRegularExpression( '/@layer\b/', $css, 'WordPress core CSS is unlayered and would beat layered rules.' );
	}

	public function test_no_selector_is_more_specific_than_two_classes(): void {
		$violations = [];
		foreach ( CssSource::rules( self::css() ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			foreach ( CssSource::selectors( $rule['selector'] ) as $selector ) {
				$specificity = CssSource::specificity( $selector );
				if ( $specificity > self::MAX_SPECIFICITY ) {
					$violations[] = CssSource::format_specificity( $specificity ) . ' ' . $selector . ( '' !== $rule['context'] ? '  [' . $rule['context'] . ']' : '' );
				}
			}
		}

		self::assertSame( [], $violations, "Selectors above (0,2,0):\n" . implode( "\n", $violations ) );
	}

	public function test_the_heading_and_paragraph_reset_beats_the_element_rules_of_wordpress_core(): void {
		// Core sets `h2, h3 { margin: 1em 0 }` and `p { margin: 1em 0 }` at element specificity. A reset wrapped in
		// :where() has no specificity, so it lost and every card title carried 16px above and below it.
		foreach ( [ '.sw-ui :where(h1, h2, h3, h4)', '.sw-ui :where(p, dl, dd, figure)' ] as $selector ) {
			$declarations = CssSource::rule_declarations( self::css(), $selector );
			self::assertSame( '0', CssSource::value_of( $declarations, 'margin' ), $selector );
			self::assertGreaterThanOrEqual( [ 0, 1, 0 ], CssSource::specificity( $selector ), $selector . ' must outrank an element selector.' );
		}
		self::assertStringNotContainsString( ':where(.sw-ui) :where(h1', self::css() );
		self::assertStringNotContainsString( ':where(.sw-ui) :where(p,', self::css() );
	}

	public function test_a_standalone_link_is_a_24px_target_in_both_directions(): void {
		// A one-digit link (a count in a list of facts) was 7px wide; WCAG 2.5.8 wants 24 x 24.
		$declarations = CssSource::rule_declarations( self::css(), '.sw-ui .sw-ui-link' );

		self::assertSame( 'var(--sw-control-h-xs)', CssSource::value_of( $declarations, 'min-height' ) );
		self::assertSame( 'var(--sw-control-h-xs)', CssSource::value_of( $declarations, 'min-width' ) );
	}

	public function test_raw_colours_exist_only_in_the_token_sections(): void {
		$components = CssSource::strip_comments( self::components_section() );

		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $components, 'Hex colours belong in the token sections.' );
		self::assertDoesNotMatchRegularExpression( '/\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/i', $components, 'Colour functions belong in the token sections.' );
		self::assertDoesNotMatchRegularExpression( '/\bcolor\(\s*(?!in )/', $components );
		// color-mix of tokens is allowed in components; of literals it is not.
		self::assertDoesNotMatchRegularExpression( '/color-mix\([^)]*#/', $components );
	}

	public function test_inline_style_hooks_and_legacy_prefixes_are_not_used(): void {
		$css = CssSource::strip_comments( self::css() );

		self::assertStringNotContainsString( 'expression(', $css );
		self::assertStringNotContainsString( '@import', $css, 'One request, no imports.' );
		self::assertStringNotContainsString( 'url(', $css, 'No external or inlined assets: icons come from the sprite.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Names: the layer owns the `sw-ui` namespace and nothing else may
	// ---------------------------------------------------------------------------------------------

	/** @return list<string> Every class named by a selector. */
	private static function classes_in_selectors(): array {
		$classes = [];
		foreach ( CssSource::rules( self::css() ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			preg_match_all( '/\.([A-Za-z_][\w-]*)/', preg_replace( '/\[[^\]]*\]/', '', $rule['selector'] ) ?? '', $matches );
			foreach ( $matches[1] as $class ) {
				$classes[ $class ] = true;
			}
		}

		return array_keys( $classes );
	}

	public function test_every_class_belongs_to_the_sw_ui_namespace(): void {
		foreach ( self::classes_in_selectors() as $class ) {
			self::assertTrue(
				'sw-ui' === $class || str_starts_with( $class, 'sw-ui-' ) || 1 === preg_match( self::EXTERNAL_CLASS, $class ),
				'.' . $class . ' is outside the sw-ui namespace; an older stylesheet could already define it.'
			);
		}
	}

	public function test_keyframes_are_namespaced_too(): void {
		foreach ( CssSource::rules( self::css() ) as $rule ) {
			if ( $rule['at'] && str_starts_with( $rule['selector'], '@keyframes' ) ) {
				self::assertStringStartsWith( '@keyframes sw-ui-', $rule['selector'] );
			}
		}
		self::assertNotEmpty( self::classes_in_selectors() );
	}

	/** @return list<string> Relative paths of every older file that could style or script the layer's markup. */
	private static function older_files(): array {
		$plugin = dirname( __DIR__, 3 );
		$files  = [];
		$walk   = static function ( string $directory ) use ( &$walk, &$files, $plugin ): void {
			foreach ( scandir( $directory ) ?: [] as $name ) {
				if ( '.' === $name || '..' === $name || in_array( $name, [ 'vendor', 'node_modules', 'tests', 'Ui' ], true ) ) {
					continue;
				}
				$path = $directory . '/' . $name;
				if ( is_dir( $path ) ) {
					$walk( $path );
				} elseif ( 1 === preg_match( '/\.(php|js|css)$/', $name ) ) {
					$files[] = ltrim( str_replace( [ $plugin, '\\' ], [ '', '/' ], $path ), '/' );
				}
			}
		};
		$walk( $plugin . '/includes' );
		$walk( $plugin . '/assets/admin' );
		$walk( $plugin . '/assets/css' );

		return array_values( array_filter(
			$files,
			static fn ( string $file ): bool => ! in_array( $file, [ 'assets/admin/sw-ui.css', 'assets/admin/sw-ui.js' ], true )
				&& ! in_array( $file, self::ALLOWED_TO_NAME_THE_LAYER, true )
		) );
	}

	public function test_no_older_file_uses_a_name_of_the_layer(): void {
		$plugin = dirname( __DIR__, 3 );
		$files  = self::older_files();
		self::assertGreaterThan( 100, count( $files ), 'The walk must cover the plugin.' );

		$offenders = [];
		foreach ( $files as $file ) {
			if ( str_contains( (string) file_get_contents( $plugin . '/' . $file ), 'sw-ui' ) ) {
				$offenders[] = $file;
			}
		}

		self::assertSame( [], $offenders, 'Existing pages must not meet the layer: these files mention it.' );
	}

	public function test_the_layer_defines_no_class_an_older_stylesheet_or_template_already_uses(): void {
		$plugin  = dirname( __DIR__, 3 );
		$legacy  = [];
		$sources = '';
		foreach ( self::older_files() as $file ) {
			$sources .= "\n" . file_get_contents( $plugin . '/' . $file );
		}
		preg_match_all( '/(?<![\w-])((?:sw|stonewright)-[A-Za-z0-9_-]+)/', $sources, $matches );
		foreach ( $matches[1] as $name ) {
			$legacy[ $name ] = true;
		}

		foreach ( self::classes_in_selectors() as $class ) {
			self::assertArrayNotHasKey( $class, $legacy, '.' . $class . ' collides with a name an older file uses.' );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Motion
	// ---------------------------------------------------------------------------------------------

	/** @return array<string, string> Custom properties declared on .sw-ui by the first token rule. */
	private static function tokens(): array {
		return CssSource::custom_properties( self::token_section(), static fn ( string $s ): bool => '.sw-ui' === $s );
	}

	private static function milliseconds( string $token_value ): float {
		self::assertSame( 1, preg_match( '/^calc\(\s*(\d+)ms \* var\(--sw-motion-scale\)\s*\)$/', $token_value, $m ), 'Every duration is calc(<ms> * var(--sw-motion-scale)): ' . $token_value );

		return (float) $m[1];
	}

	public function test_interaction_durations_are_between_100_and_240_milliseconds_and_scaled(): void {
		$tokens = self::tokens();
		$names  = [ '--sw-dur-fast', '--sw-dur', '--sw-dur-slow', '--sw-dur-reveal', '--sw-dur-enter', '--sw-dur-exit', '--sw-dur-toast' ];

		foreach ( $names as $name ) {
			self::assertArrayHasKey( $name, $tokens, $name );
			$ms = self::milliseconds( $tokens[ $name ] );
			self::assertGreaterThanOrEqual( 100.0, $ms, $name );
			self::assertLessThanOrEqual( 240.0, $ms, $name );
		}
		self::assertSame( 100.0, self::milliseconds( $tokens['--sw-dur-fast'] ) );
		self::assertSame( 150.0, self::milliseconds( $tokens['--sw-dur'] ) );
		self::assertSame( 240.0, self::milliseconds( $tokens['--sw-dur-slow'] ) );
	}

	public function test_loops_and_the_flash_are_scaled_like_everything_else(): void {
		$tokens = self::tokens();

		foreach ( [ '--sw-dur-spin', '--sw-dur-shimmer', '--sw-dur-flash' ] as $name ) {
			self::assertArrayHasKey( $name, $tokens, $name );
			self::milliseconds( $tokens[ $name ] );
		}
		// Every token that holds a time is one of the scaled ones.
		foreach ( $tokens as $name => $value ) {
			if ( 1 === preg_match( '/\d+m?s\b/', $value ) && ! str_starts_with( $name, '--sw-dur' ) ) {
				self::fail( $name . ' holds a literal time: ' . $value );
			}
		}
	}

	public function test_reduced_motion_zeroes_the_scale_that_every_duration_is_multiplied_by(): void {
		$found = false;
		foreach ( CssSource::rules( self::css() ) as $rule ) {
			if ( '@media (prefers-reduced-motion: reduce)' === $rule['context'] && '.sw-ui' === $rule['selector'] ) {
				self::assertSame( '0', CssSource::value_of( CssSource::declarations( $rule['body'] ), '--sw-motion-scale' ) );
				$found = true;
			}
		}
		self::assertTrue( $found, 'prefers-reduced-motion must set --sw-motion-scale: 0 on .sw-ui.' );
		self::assertSame( '1', self::tokens()['--sw-motion-scale'], 'Motion is on by default.' );
	}

	/** @return list<array{selector: string, name: string, value: string}> */
	private static function timing_declarations(): array {
		$out = [];
		foreach ( CssSource::rules( self::components_section() ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			foreach ( CssSource::declarations( $rule['body'] ) as $declaration ) {
				if ( in_array( $declaration['name'], [ 'transition', 'transition-duration', 'transition-delay', 'animation', 'animation-duration', 'animation-delay' ], true ) ) {
					$out[] = [ 'selector' => $rule['selector'], 'name' => $declaration['name'], 'value' => $declaration['value'] ];
				}
			}
		}

		return $out;
	}

	public function test_no_duration_is_a_literal_and_none_is_unscaled(): void {
		$declarations = self::timing_declarations();
		self::assertNotEmpty( $declarations );

		foreach ( $declarations as $declaration ) {
			$without_vars = (string) preg_replace( '/var\([^)]*\)/', '', $declaration['value'] );
			self::assertDoesNotMatchRegularExpression( '/(?<![\w.-])\d*\.?\d+m?s\b/', $without_vars, $declaration['selector'] . ' { ' . $declaration['name'] . ': ' . $declaration['value'] . ' } uses a literal time.' );
			self::assertStringNotContainsString( 'all', preg_split( '/[\s,]+/', $declaration['value'] )[0] ?? '', 'No transition: all.' );
		}
	}

	public function test_only_opacity_transform_and_colour_properties_ever_transition(): void {
		foreach ( self::timing_declarations() as $declaration ) {
			if ( 'transition' !== $declaration['name'] ) {
				continue;
			}
			foreach ( CssSource::split_top_level( $declaration['value'], ',' ) as $item ) {
				$tokens   = preg_split( '/\s+/', trim( $item ) ) ?: [];
				$property = (string) $tokens[0];
				if ( in_array( $property, self::DISCRETE, true ) ) {
					self::assertContains( 'allow-discrete', $tokens, $declaration['selector'] . ': ' . $property . ' may only transition with allow-discrete' );
					continue;
				}
				self::assertContains( $property, self::ANIMATABLE, $declaration['selector'] . ' transitions ' . $property . '; layout never moves.' );
			}
		}
	}

	public function test_keyframes_move_only_allowed_properties(): void {
		$animated = 0;
		foreach ( CssSource::rules( self::css() ) as $rule ) {
			if ( ! $rule['at'] || ! str_starts_with( $rule['selector'], '@keyframes' ) ) {
				continue;
			}
			++$animated;
			foreach ( CssSource::rules( $rule['body'] ) as $frame ) {
				foreach ( CssSource::declarations( $frame['body'] ) as $declaration ) {
					self::assertContains( $declaration['name'], self::ANIMATABLE, $rule['selector'] . ' animates ' . $declaration['name'] );
				}
			}
		}
		self::assertGreaterThanOrEqual( 6, $animated );
	}

	public function test_every_looping_animation_has_a_static_equivalent_under_reduced_motion(): void {
		$looping = [];
		foreach ( self::timing_declarations() as $declaration ) {
			if ( 'animation' === $declaration['name'] && str_contains( $declaration['value'], 'infinite' ) ) {
				$looping[] = $declaration['selector'];
			}
		}
		self::assertNotEmpty( $looping );

		$silenced = [];
		foreach ( CssSource::rules( self::css() ) as $rule ) {
			if ( '@media (prefers-reduced-motion: reduce)' !== $rule['context'] || $rule['at'] ) {
				continue;
			}
			if ( 'none' === CssSource::value_of( CssSource::declarations( $rule['body'] ), 'animation' ) ) {
				$silenced[] = $rule['selector'];
			}
		}

		foreach ( $looping as $selector ) {
			self::assertNotEmpty(
				array_filter( $silenced, static fn ( string $reduced ): bool => str_contains( $reduced, trim( (string) preg_replace( '/^.*\s/', '', $selector ) ) ) || str_contains( $reduced, explode( ':where', $selector )[0] ) || str_contains( $reduced, '.sw-ui-skeleton' ) && str_contains( $selector, '.sw-ui-skeleton' ) || str_contains( $reduced, 'aria-busy' ) && str_contains( $selector, 'aria-busy' ) ),
				$selector . ' loops, so it needs animation: none in the reduced motion block.'
			);
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Tokens and aliases
	// ---------------------------------------------------------------------------------------------

	/** @return array<string, string> Custom properties every .sw-ui rule of the token sections declares. */
	private static function island_tokens(): array {
		return CssSource::custom_properties( self::token_section(), static fn ( string $s ): bool => '.sw-ui' === $s );
	}

	public function test_every_legacy_token_name_has_an_alias_inside_the_scope(): void {
		$legacy = CssSource::custom_properties( CssSource::read( 'admin/shell.css' ), static fn ( string $s ): bool => ':root' === $s );
		self::assertGreaterThan( 100, count( $legacy ), 'shell.css declares the legacy token map.' );
		$island = self::island_tokens();

		$missing = array_values( array_diff( array_keys( $legacy ), array_keys( $island ) ) );
		self::assertSame( [], $missing, 'These legacy tokens would be undefined or stale inside .sw-ui: ' . implode( ', ', $missing ) );
	}

	public function test_aliases_point_at_tokens_of_the_layer(): void {
		$island = self::island_tokens();
		$legacy = array_keys( CssSource::custom_properties( CssSource::read( 'admin/shell.css' ), static fn ( string $s ): bool => ':root' === $s ) );

		foreach ( $legacy as $name ) {
			$value = $island[ $name ] ?? '';
			if ( 1 === preg_match( '/^var\((--[\w-]+)\)$/', $value, $m ) ) {
				self::assertArrayHasKey( $m[1], $island, $name . ' is an alias of ' . $m[1] . ', which the layer does not define.' );
			}
		}
		// The rename that removes a trap: --sw-text-md is a size, --sw-text-muted a colour.
		self::assertSame( 'var(--sw-fs-md)', $island['--sw-text-md'] );
		self::assertSame( 'var(--sw-ok)', $island['--sw-ok-text'] );
		self::assertSame( 'var(--sw-accent-fill)', $island['--sw-brand-fill'] );
	}

	public function test_every_var_reference_resolves(): void {
		$css      = CssSource::strip_comments( self::css() );
		$declared = [];
		foreach ( CssSource::rules( $css ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			foreach ( CssSource::declarations( $rule['body'] ) as $declaration ) {
				if ( str_starts_with( $declaration['name'], '--' ) ) {
					$declared[ $declaration['name'] ] = true;
				}
			}
		}

		preg_match_all( '/var\(\s*(--[\w-]+)/', $css, $matches );
		$unknown = array_values( array_unique( array_filter(
			$matches[1],
			static fn ( string $name ): bool => ! isset( $declared[ $name ] ) && ! in_array( $name, self::EXTERNAL_TOKENS, true )
		) ) );

		self::assertSame( [], $unknown, 'var() reads tokens nobody declares: ' . implode( ', ', $unknown ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Contrast of the neutral and status tokens (WCAG 1.4.3 and 1.4.11)
	// ---------------------------------------------------------------------------------------------

	private static function token_rgb( string $name ): array {
		return CssSource::hex_to_rgb( self::island_tokens()[ $name ] );
	}

	public function test_muted_text_reads_on_every_surface_and_status_tint(): void {
		$muted = self::token_rgb( '--sw-text-muted' );

		foreach ( [ '--sw-surface', '--sw-bg', '--sw-surface-raised', '--sw-surface-sunken', '--sw-ok-soft', '--sw-warn-soft', '--sw-danger-soft', '--sw-info-soft', '--sw-neutral-soft' ] as $background ) {
			self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $muted, self::token_rgb( $background ) ), '--sw-text-muted on ' . $background );
		}
	}

	public function test_status_text_reads_on_its_own_fill_and_on_white(): void {
		foreach ( [ 'ok', 'warn', 'danger', 'info' ] as $status ) {
			$text = self::token_rgb( '--sw-' . $status );
			self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $text, self::token_rgb( '--sw-' . $status . '-soft' ) ), $status . ' on its fill' );
			self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $text, self::token_rgb( '--sw-surface' ) ), $status . ' on white' );
		}
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::token_rgb( '--sw-neutral' ), self::token_rgb( '--sw-neutral-soft' ) ) );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::token_rgb( '--sw-text-invert' ), self::token_rgb( '--sw-danger' ) ), 'The solid danger button.' );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::token_rgb( '--sw-text-invert' ), self::token_rgb( '--sw-danger-strong' ) ), 'Its hover.' );
	}

	public function test_text_and_secondary_text_read_on_every_surface(): void {
		foreach ( [ '--sw-text', '--sw-text-secondary' ] as $foreground ) {
			foreach ( [ '--sw-surface', '--sw-bg', '--sw-surface-raised', '--sw-ok-soft', '--sw-warn-soft', '--sw-danger-soft', '--sw-info-soft' ] as $background ) {
				self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::token_rgb( $foreground ), self::token_rgb( $background ) ), $foreground . ' on ' . $background );
			}
		}
	}

	public function test_control_borders_and_switch_tracks_meet_non_text_contrast(): void {
		$border = self::token_rgb( '--sw-border-control' );

		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( $border, self::token_rgb( '--sw-surface' ) ), 'On white; core uses 3.03:1 and the old shell 1.26:1.' );
		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( $border, self::token_rgb( '--sw-bg' ) ), 'On the page background.' );
	}

	public function test_text_on_the_inverse_surfaces_reads(): void {
		$inverse = self::token_rgb( '--sw-inverse-bg' );

		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::token_rgb( '--sw-inverse-fg' ), $inverse ) );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::token_rgb( '--sw-inverse-muted' ), $inverse ), 'The code block title.' );
		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( self::token_rgb( '--sw-inverse-control' ), $inverse ), 'Outlines of controls on a dark surface.' );
	}

	public function test_type_sizes_never_fall_below_twelve_pixels(): void {
		foreach ( self::island_tokens() as $name => $value ) {
			if ( str_starts_with( $name, '--sw-fs-' ) ) {
				self::assertGreaterThanOrEqual( 12, (int) $value, $name );
			}
		}
		foreach ( CssSource::rules( self::components_section() ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			$size = CssSource::value_of( CssSource::declarations( $rule['body'] ), 'font-size' );
			if ( null !== $size && 1 === preg_match( '/^(\d+(?:\.\d+)?)px$/', $size, $m ) ) {
				self::assertGreaterThanOrEqual( 12.0, (float) $m[1], $rule['selector'] );
			}
		}
	}

	public function test_controls_keep_the_target_size_tiers(): void {
		$tokens = self::island_tokens();

		self::assertSame( '40px', $tokens['--sw-control-h'] );
		self::assertSame( '32px', $tokens['--sw-control-h-sm'] );
		self::assertSame( '24px', $tokens['--sw-control-h-xs'], 'The WCAG 2.5.8 floor.' );
		foreach ( CssSource::rules( self::components_section() ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			$declarations = CssSource::declarations( $rule['body'] );
			foreach ( [ 'min-height', 'height' ] as $property ) {
				$value = CssSource::value_of( $declarations, $property );
				// Pseudo-elements (the 14px spinner) are glyphs inside a control, not targets.
				if ( null !== $value && 1 === preg_match( '/^(\d+)px$/', $value, $m ) && str_contains( $rule['selector'], '.sw-ui-btn' ) && ! str_contains( $rule['selector'], '::' ) ) {
					self::assertGreaterThanOrEqual( 24, (int) $m[1], $rule['selector'] . ' ' . $property );
				}
			}
		}
	}

	public function test_the_accent_follows_the_wordpress_colour_scheme_with_a_fallback(): void {
		$tokens = self::island_tokens();

		self::assertSame( 'var(--wp-admin-theme-color, #4f46e5)', $tokens['--sw-accent'] );
		self::assertSame( 'var(--wp-admin-theme-color-darker-10, #4338ca)', $tokens['--sw-accent-strong'] );
		self::assertSame( 'var(--wp-admin-theme-color-darker-20, #3730a3)', $tokens['--sw-accent-stronger'] );
		self::assertSame( 'var(--wp-admin-border-width-focus, 2px)', $tokens['--sw-focus-width'] );
		self::assertSame( 'var(--wp-admin--admin-bar--height, 32px)', $tokens['--sw-adminbar-h'] );
		self::assertSame( 'var(--sw-accent-strong)', $tokens['--sw-accent-text'], 'Accent text defaults to the darker-10 step; schemes that need more override it.' );
	}
}
