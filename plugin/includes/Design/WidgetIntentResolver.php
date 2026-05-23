<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design;

/**
 * Phase D smart-detection layer — maps high-level design intents to the
 * right Elementor widget choice + a settings template + the upstream
 * steps the caller must run first.
 *
 * Two surfaces:
 *
 *   1. `resolve( string $intent ): array` — named-intent lookup. Used by
 *      `stonewright/widget-intent-resolve` and by Phase E build scripts
 *      that want to say "give me the right thing for a footer column."
 *
 *   2. `detect_from_figma_signature( array $node ): ?string` — visual
 *      pattern matcher over a Figma node + its children. Used by the
 *      FigmaToSpec walk to decide whether a generic FRAME is actually
 *      a countdown / nav-menu / social row / footer column. Returns
 *      the resolved intent name or null when no signature matches.
 *
 * Keep this class pure: no WP globals, no I/O. All knowledge lives in
 * the static intent map below. To add a new intent: extend `intents()`
 * + (optionally) `detect_from_figma_signature()`.
 */
final class WidgetIntentResolver {

	/**
	 * Canonical intent map.
	 *
	 * Each entry:
	 *   - `widget`             : target widget slug (one of the
	 *                            Stonewright manifest slugs); null when
	 *                            the intent resolves to multiple widgets.
	 *   - `widgets`            : when `widget` is null, the ordered list
	 *                            of slugs to compose.
	 *   - `settings_template`  : starter dict for the widget's settings —
	 *                            sensible defaults the LLM can override.
	 *   - `required_steps`     : prerequisites the caller must run before
	 *                            inserting this widget (e.g. create a WP
	 *                            menu, upload a logo).
	 *   - `description`        : one-line explanation surfaced to the LLM.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function intents(): array {
		return [
			'nav' => [
				'widget'            => 'nav-menu',
				'settings_template' => [
					'menu'        => null, // caller fills in WP menu term_id
					'layout'      => 'horizontal',
					'align_items' => 'center',
					'pointer'     => 'underline',
				],
				'required_steps'    => [ 'stonewright/menu-create', 'stonewright/menu-add-item' ],
				'description'       => 'Horizontal site navigation linked to a WordPress menu. Use for header navbars.',
			],
			'vertical-nav' => [
				'widget'            => 'nav-menu',
				'settings_template' => [
					'menu'        => null,
					'layout'      => 'vertical',
					'align_items' => 'start',
				],
				'required_steps'    => [ 'stonewright/menu-create', 'stonewright/menu-add-item' ],
				'description'       => 'Vertical navigation (sidebar/footer column variant).',
			],
			'countdown' => [
				'widget'            => 'countdown',
				'settings_template' => [
					'countdown_type' => 'due_date',
					'due_date'       => '2026-12-31 09:00', // caller overrides
					'show_days'      => 'yes',
					'show_hours'     => 'yes',
					'show_minutes'   => 'yes',
					'show_seconds'   => 'yes',
					'label_days'     => 'DAYS',
					'label_hours'    => 'HOURS',
					'label_minutes'  => 'MIN',
					'label_seconds'  => 'SEC',
				],
				'required_steps'    => [],
				'description'       => 'Numeric countdown to a fixed datetime. USE THIS WHEN you see groups of large digits with `:` separators and labels like Days/Hours/Min/Sec — NEVER simulate with three Heading widgets.',
			],
			'social-row' => [
				'widget'            => 'social-icons',
				'settings_template' => [
					'social_icon_list' => [
						[ 'social_icon' => [ 'value' => 'fab fa-facebook', 'library' => 'fa-brands' ] ],
						[ 'social_icon' => [ 'value' => 'fab fa-instagram', 'library' => 'fa-brands' ] ],
						[ 'social_icon' => [ 'value' => 'fab fa-linkedin', 'library' => 'fa-brands' ] ],
					],
					'shape'              => 'rounded',
					'icon_size'          => [ 'unit' => 'px', 'size' => 24 ],
				],
				'required_steps'    => [],
				'description'       => 'Row of round social-platform icons (Facebook/Instagram/Twitter/LinkedIn/YouTube). NEVER use Button widgets styled as social icons.',
			],
			'icon-bullet-list' => [
				'widget'            => 'icon-list',
				'settings_template' => [
					'view'           => 'traditional',
					'icon_align'     => 'left',
					'link_click'     => 'full_width',
					'icon_list'      => [
						[ 'text' => 'List item 1', 'selected_icon' => [ 'value' => 'fas fa-check', 'library' => 'fa-solid' ] ],
					],
				],
				'required_steps'    => [],
				'description'       => 'Vertical list of items each with an icon prefix. Use for footer link columns, feature checklists, or any "icon + text" stack.',
			],
			'footer-link-column' => [
				'widget'            => 'icon-list',
				'settings_template' => [
					'view'           => 'traditional',
					'icon_align'     => 'left',
					'link_click'     => 'inline',
					'icon_list'      => [
						[ 'text' => 'Link 1', 'link' => [ 'url' => '#', 'is_external' => false, 'nofollow' => false ] ],
					],
				],
				'required_steps'    => [],
				'description'       => 'Footer column with a list of links (no visible icons usually — use a transparent icon). Each item carries text + link.url.',
			],
			'logo+nav' => [
				'widget'            => null,
				'widgets'           => [ 'image', 'nav-menu' ],
				'settings_template' => [
					'image'     => [ 'image' => [ 'id' => null, 'url' => null ], 'image_size' => 'medium' ],
					'nav-menu'  => [ 'menu' => null, 'layout' => 'horizontal' ],
				],
				'required_steps'    => [
					'stonewright/media-upload',
					'stonewright/menu-create',
					'stonewright/menu-add-item',
				],
				'description'       => 'Header logo + horizontal nav. Compose two widgets inside a flex container.',
			],
			'hero-cta-pair' => [
				'widget'            => null,
				'widgets'           => [ 'heading', 'paragraph', 'button', 'button' ],
				'settings_template' => [
					'heading'   => [ 'title' => 'Headline', 'header_size' => 'h1' ],
					'paragraph' => [ 'editor' => '<p>Supporting paragraph.</p>' ],
					'button'    => [ 'text' => 'Primary action' ],
				],
				'required_steps'    => [],
				'description'       => 'Hero block: H1 + supporting paragraph + primary CTA + secondary outline CTA.',
			],
			'header-template' => [
				'widget'            => null,
				'widgets'           => [ 'image', 'nav-menu', 'button' ],
				'settings_template' => [],
				'required_steps'    => [
					'stonewright/theme-builder-create-template',
					'stonewright/theme-builder-set-conditions',
				],
				'description'       => 'Full Theme Builder header template. Creates a separate Elementor template of type=header + sets conditions=include/general.',
			],
			'footer-template' => [
				'widget'            => null,
				'widgets'           => [ 'icon-list', 'social-icons', 'paragraph' ],
				'settings_template' => [],
				'required_steps'    => [
					'stonewright/theme-builder-create-template',
					'stonewright/theme-builder-set-conditions',
				],
				'description'       => 'Full Theme Builder footer template. Includes link columns + social row + bottom legal/copy strip.',
			],
			'cta-button' => [
				'widget'            => 'button',
				'settings_template' => [
					'text' => 'Click me',
					'link' => [ 'url' => '#', 'is_external' => false, 'nofollow' => false ],
				],
				'required_steps'    => [],
				'description'       => 'Single call-to-action button.',
			],
			'image-with-caption' => [
				'widget'            => 'image',
				'settings_template' => [
					'image'           => [ 'id' => null, 'url' => null ],
					'image_size'      => 'large',
					'caption_source'  => 'custom',
					'caption'         => '',
				],
				'required_steps'    => [ 'stonewright/media-upload' ],
				'description'       => 'Standalone image with optional caption. Use sub-node export — never composite the parent frame.',
			],
			'tabs' => [
				'widget'            => 'tabs',
				'settings_template' => [
					'tabs' => [
						[ 'tab_title' => 'Tab 1', 'tab_content' => '<p>Content for tab 1.</p>' ],
						[ 'tab_title' => 'Tab 2', 'tab_content' => '<p>Content for tab 2.</p>' ],
					],
				],
				'required_steps'    => [],
				'description'       => 'Horizontal tab strip with body content per tab.',
			],
			'accordion' => [
				'widget'            => 'accordion',
				'settings_template' => [
					'tabs' => [
						[ 'tab_title' => 'Question 1?', 'tab_content' => '<p>Answer body.</p>' ],
						[ 'tab_title' => 'Question 2?', 'tab_content' => '<p>Answer body.</p>' ],
					],
				],
				'required_steps'    => [],
				'description'       => 'Accordion (FAQ pattern) — stacked toggle rows revealing body content. Use for FAQ sections.',
			],
			'testimonial' => [
				'widget'            => 'testimonial',
				'settings_template' => [
					'testimonial_content' => 'Quoted text.',
					'testimonial_name'    => 'Author',
					'testimonial_job'     => 'Title',
				],
				'required_steps'    => [],
				'description'       => 'Quoted testimonial card with author name + role.',
			],
			'pricing-table' => [
				'widget'            => 'price-table',
				'settings_template' => [
					'heading'      => 'Plan',
					'price'        => '29',
					'currency_symbol' => '$',
					'period'       => 'month',
					'features_list'=> [ [ 'item_text' => 'Feature 1' ] ],
					'button_text'  => 'Buy now',
				],
				'required_steps'    => [],
				'description'       => 'Pricing tier card with title, price, feature list, and CTA button. Pro widget.',
			],
		];
	}

	/**
	 * Resolve an intent name to its widget + settings template.
	 *
	 * Returns an empty array (no `widget`/`widgets` key) when the intent
	 * name is unknown — caller should fall back to the universal escape
	 * hatch `stonewright/elementor-v3-add-widget`.
	 *
	 * @return array<string, mixed>
	 */
	public static function resolve( string $intent ): array {
		$map = self::intents();
		return $map[ $intent ] ?? [];
	}

	/** Returns the full list of recognised intent names. */
	public static function intent_names(): array {
		return array_keys( self::intents() );
	}

	/**
	 * Detect a widget intent from a Figma sub-tree.
	 *
	 * Looks for the per-plan signatures (countdown digit pairs, social
	 * icon row, footer/header naming, icon-list bullets, button shape).
	 * Pure read-only over the Figma node — never mutates.
	 *
	 * @param array<string, mixed> $node Figma node (with optional `children`).
	 * @return string|null Intent name or null when no signature matches.
	 */
	public static function detect_from_figma_signature( array $node ): ?string {
		// Name-based hint (header/footer frames are usually labelled).
		$name = isset( $node['name'] ) && is_string( $node['name'] ) ? strtolower( $node['name'] ) : '';
		if ( $name !== '' ) {
			if ( str_contains( $name, 'footer' ) ) {
				return 'footer-template';
			}
			if ( str_contains( $name, 'header' ) || str_starts_with( $name, 'nav' ) ) {
				return 'header-template';
			}
		}

		$children = ( isset( $node['children'] ) && is_array( $node['children'] ) ) ? $node['children'] : [];
		if ( empty( $children ) ) {
			return null;
		}

		// Countdown signature: ≥3 sibling frames each containing a short
		// numeric TEXT (1-2 chars). Tolerant of `:`/colon separators.
		$digit_groups = 0;
		foreach ( $children as $c ) {
			$type = $c['type'] ?? '';
			if ( $type === 'FRAME' || $type === 'GROUP' ) {
				$grand = ( isset( $c['children'] ) && is_array( $c['children'] ) ) ? $c['children'] : [];
				foreach ( $grand as $g ) {
					if ( ( $g['type'] ?? '' ) === 'TEXT' ) {
						$chars = (string) ( $g['characters'] ?? '' );
						if ( preg_match( '/^\s*\d{1,3}\s*$/', $chars ) ) {
							++$digit_groups;
							break; // one match per group is enough
						}
					}
				}
			}
		}
		if ( $digit_groups >= 3 ) {
			return 'countdown';
		}

		// Social row signature: ≥3 small circular frames each with a
		// single VECTOR child and no TEXT.
		$social_glyphs = 0;
		foreach ( $children as $c ) {
			$type = $c['type'] ?? '';
			if ( $type === 'FRAME' || $type === 'ELLIPSE' ) {
				$grand = ( isset( $c['children'] ) && is_array( $c['children'] ) ) ? $c['children'] : [];
				$vector_only = false;
				foreach ( $grand as $g ) {
					if ( ( $g['type'] ?? '' ) === 'VECTOR' ) {
						$vector_only = true;
					} elseif ( ( $g['type'] ?? '' ) === 'TEXT' ) {
						$vector_only = false;
						break;
					}
				}
				if ( $vector_only ) {
					++$social_glyphs;
				}
			}
		}
		if ( $social_glyphs >= 3 ) {
			return 'social-row';
		}

		// Nav-menu signature: a horizontal row of ≥3 TEXT siblings with
		// similar font size + same fill — emit nav intent so the LLM
		// builds a real menu instead of stacking buttons.
		$text_siblings = array_values( array_filter( $children, static fn( $c ) => ( $c['type'] ?? '' ) === 'TEXT' ) );
		if ( count( $text_siblings ) >= 3 ) {
			$sizes = array_values( array_filter( array_map(
				static fn( $c ) => $c['style']['fontSize'] ?? null,
				$text_siblings
			), 'is_numeric' ) );
			$variance = ! empty( $sizes ) ? ( max( $sizes ) - min( $sizes ) ) : 0;
			if ( $variance <= 2 ) {
				return 'nav';
			}
		}

		// Icon-bullet list: vertical stack of FRAME children where each
		// has one VECTOR + one TEXT child.
		$bullet_rows = 0;
		foreach ( $children as $c ) {
			if ( ( $c['type'] ?? '' ) !== 'FRAME' && ( $c['type'] ?? '' ) !== 'GROUP' ) {
				continue;
			}
			$grand = ( isset( $c['children'] ) && is_array( $c['children'] ) ) ? $c['children'] : [];
			$has_vector = false;
			$has_text   = false;
			foreach ( $grand as $g ) {
				$gt = $g['type'] ?? '';
				if ( $gt === 'VECTOR' || $gt === 'ELLIPSE' ) {
					$has_vector = true;
				} elseif ( $gt === 'TEXT' ) {
					$has_text = true;
				}
			}
			if ( $has_vector && $has_text ) {
				++$bullet_rows;
			}
		}
		if ( $bullet_rows >= 3 ) {
			return 'icon-bullet-list';
		}

		return null;
	}
}
