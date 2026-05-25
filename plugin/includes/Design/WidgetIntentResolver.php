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
 *   2. `detect_from_design_tree( array $node ): ?string` — visual
 *      pattern matcher over a design tree + its children. Used to decide
 *      whether a generic layout node is actually
 *      a countdown / nav-menu / social row / footer column. Returns
 *      the resolved intent name or null when no signature matches.
 *
 * Keep this class pure: no WP globals, no I/O. All knowledge lives in
 * the static intent map below. To add a new intent: extend `intents()`
 * + (optionally) `detect_from_design_tree()`.
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
			'image-gallery' => [
				'widget'            => 'image-gallery',
				'settings_template' => [
					'images'     => [],
					'columns'    => 4,
					'image_size' => 'full',
					'link_to'    => 'none',
					'orderby'    => 'default',
				],
				'required_steps'    => [ 'stonewright/media-upload' ],
				'description'       => 'Native Elementor image gallery. Use for photo grids/galleries; never emit separate image widgets for a gallery grid.',
			],
			'video' => [
				'widget'            => 'video',
				'settings_template' => [
					'video_type' => 'hosted',
					'url'        => '',
					'poster'     => null,
				],
				'required_steps'    => [ 'stonewright/media-upload' ],
				'description'       => 'Native Elementor Video widget. Use for aftermovie/video poster/play patterns; do not fake video with image plus custom HTML.',
			],
			'section-label' => [
				'widget'            => null,
				'widgets'           => [ 'paragraph', 'divider' ],
				'settings_template' => [
					'paragraph' => [
						'style' => [
							'font_weight'    => '600',
							'letter_spacing' => '2px',
							'text_transform' => 'uppercase',
						],
					],
					'divider'   => [
						'style'  => 'solid',
						'weight' => 1,
					],
				],
				'required_steps'    => [],
				'description'       => 'Small uppercase section label with an underline/divider below or beside it.',
			],
			'newsletter-form' => [
				'widget'            => 'form',
				'settings_template' => [
					'form_name'   => 'Newsletter',
					'button_text' => 'Aboneaza-te la newsletter',
					'fields'      => [
						[ 'type' => 'text', 'label' => 'Nume', 'required' => true ],
						[ 'type' => 'text', 'label' => 'Prenume', 'required' => true ],
						[ 'type' => 'email', 'label' => 'Email', 'required' => true ],
						[ 'type' => 'select', 'label' => 'Interes', 'required' => true ],
					],
				],
				'required_steps'    => [],
				'description'       => 'Native Elementor form configured for newsletter signup. Use for newsletter/contact forms; never simulate forms with text boxes.',
			],
			'form' => [
				'widget'            => 'form',
				'settings_template' => [
					'form_name'   => 'Form',
					'button_text' => 'Trimite',
					'fields'      => [
						[ 'type' => 'text', 'label' => 'Nume', 'required' => true ],
						[ 'type' => 'email', 'label' => 'Email', 'required' => true ],
					],
				],
				'required_steps'    => [],
				'description'       => 'Native Elementor form for contact or generic form layouts.',
			],
			'speaker-grid' => [
				'widget'            => null,
				'widgets'           => [ 'container', 'image', 'heading', 'paragraph', 'icon-list' ],
				'settings_template' => [
					'container' => [ 'layout' => 'grid', 'columns' => 4 ],
				],
				'required_steps'    => [ 'stonewright/media-upload' ],
				'description'       => 'Speaker/team card grid composed with native containers, images, heading/text widgets, and link rows. Do not add borders when exported speaker artwork already contains its border.',
			],
			'sticky-header' => [
				'widget'            => null,
				'widgets'           => [ 'container', 'image', 'nav-menu', 'nav-menu', 'button' ],
				'settings_template' => [
					'container' => [
						'sticky'    => 'top',
						'sticky_on' => [ 'desktop', 'tablet', 'mobile' ],
						'z_index'   => 1000,
					],
				],
				'required_steps'    => [ 'stonewright/menu-create', 'stonewright/menu-add-item' ],
				'description'       => 'Sticky header template with desktop/mobile behavior and real WordPress menus.',
			],
			'mobile-nav' => [
				'widget'            => 'nav-menu',
				'settings_template' => [
					'menu'         => null,
					'layout'       => 'horizontal',
					'dropdown'     => 'mobile',
					'toggle'       => 'hamburger',
					'toggle_align' => 'end',
				],
				'required_steps'    => [ 'stonewright/menu-create', 'stonewright/menu-add-item' ],
				'description'       => 'Native Nav Menu configured for mobile hamburger/dropdown behavior.',
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
	 * Detect a widget intent from a plain-language build request.
	 *
	 * This is intentionally deterministic and small: the LLM can call the
	 * resolver with the user's prompt and gets a native Elementor intent back
	 * before it starts inventing text/image/button fallbacks.
	 */
	public static function detect_from_prompt( string $prompt ): ?string {
		$text = strtolower( trim( $prompt ) );
		if ( '' === $text ) {
			return null;
		}

		if ( preg_match( '/\b(label|underline|border bottom|\d{2}\s*-)\b/u', $text ) ) {
			return 'section-label';
		}
		if ( preg_match( '/\b(iconuri|icons?|svg|facebook|instagram|linkedin|youtube|tiktok)\b/u', $text ) && preg_match( '/\b(social|footer|subsol|facebook|instagram|linkedin|youtube|tiktok)\b/u', $text ) ) {
			return 'social-row';
		}
		if ( preg_match( '/\b(aftermovie|video|youtube|vimeo|mp4|poster|play)\b/u', $text ) ) {
			return 'video';
		}
		if ( preg_match( '/\b(mobile|telefon|responsive)\b/u', $text ) && preg_match( '/\b(hamburger|dropdown|toggle)\b/u', $text ) ) {
			return 'mobile-nav';
		}
		if ( preg_match( '/\b(sticky|fix|lipit)\b/u', $text ) && preg_match( '/\b(header|meniu|menu|navbar)\b/u', $text ) ) {
			return 'sticky-header';
		}
		if ( preg_match( '/\b(footer|subsol)\b/u', $text ) ) {
			return 'footer-template';
		}
		if ( preg_match( '/\b(header|meniu principal|navbar|nav bar)\b/u', $text ) ) {
			if ( str_contains( $text, 'logo' ) ) {
				return 'logo+nav';
			}
			return 'nav';
		}
		if ( preg_match( '/\b(galerie|gallery|foto|photo grid|imagini)\b/u', $text ) ) {
			return 'image-gallery';
		}
		if ( preg_match( '/\b(newsletter|formular|form|contact|email)\b/u', $text ) ) {
			return str_contains( $text, 'newsletter' ) ? 'newsletter-form' : 'form';
		}
		if ( preg_match( '/\b(social|facebook|instagram|linkedin|youtube|tiktok)\b/u', $text ) ) {
			return 'social-row';
		}
		if ( preg_match( '/\b(countdown|timer|numaratoare|cronometru)\b/u', $text ) ) {
			return 'countdown';
		}
		if ( preg_match( '/\b(speaker|speakeri|team|echipa|carduri)\b/u', $text ) ) {
			return 'speaker-grid';
		}
		if ( preg_match( '/\b(faq|intrebari|accordion)\b/u', $text ) ) {
			return 'accordion';
		}
		if ( preg_match( '/\b(tab|tabs)\b/u', $text ) ) {
			return 'tabs';
		}
		if ( preg_match( '/\b(testimonial|review|quote)\b/u', $text ) ) {
			return 'testimonial';
		}
		if ( preg_match( '/\b(pricing|preturi|planuri)\b/u', $text ) ) {
			return 'pricing-table';
		}

		return null;
	}

	/**
	 * Detect a widget intent from a design tree.
	 *
	 * Looks for the per-plan signatures (countdown digit pairs, social
	 * icon row, footer/header naming, icon-list bullets, button shape).
	 * Pure read-only over the supplied tree; never mutates.
	 *
	 * @param array<string, mixed> $node Design node (with optional `children`).
	 * @return string|null Intent name or null when no signature matches.
	 */
	public static function detect_from_design_tree( array $node ): ?string {
		$name = isset( $node['name'] ) && is_string( $node['name'] ) ? strtolower( $node['name'] ) : '';
		$children = ( isset( $node['children'] ) && is_array( $node['children'] ) ) ? $node['children'] : [];

		if ( ! empty( $children ) ) {
			if ( self::looks_like_section_label( $node ) ) {
				return 'section-label';
			}
			if ( self::looks_like_video_poster( $node ) ) {
				return 'video';
			}
			if ( self::looks_like_newsletter_form( $node ) ) {
				return 'newsletter-form';
			}
			if ( self::looks_like_image_gallery( $node ) ) {
				return 'image-gallery';
			}
			if ( self::looks_like_footer_link_column( $node ) ) {
				return 'footer-link-column';
			}
		}

		// Name-based hint for whole templates. More specific content
		// signatures above win, so a "Footer useful links" column does not
		// become an entire footer template.
		if ( $name !== '' ) {
			if ( str_contains( $name, 'footer' ) ) {
				return 'footer-template';
			}
			if ( str_contains( $name, 'header' ) ) {
				return 'header-template';
			}
		}

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

	/**
	 * @param array<string, mixed> $node
	 */
	private static function looks_like_newsletter_form( array $node ): bool {
		$name  = strtolower( (string) ( $node['name'] ?? '' ) );
		$texts = array_map( 'strtolower', self::text_descendants( $node ) );
		$blob  = $name . ' ' . implode( ' ', $texts );

		$has_form_hint = str_contains( $blob, 'newsletter' )
			|| str_contains( $blob, 'formular' )
			|| preg_match( '/\bform\b/', $blob );
		$has_email = str_contains( $blob, 'email' ) || str_contains( $blob, 'e-mail' );
		$has_submit = str_contains( $blob, 'aboneaza' )
			|| str_contains( $blob, 'aboneaz')
			|| str_contains( $blob, 'subscribe')
			|| str_contains( $blob, 'trimite');

		return (bool) ( $has_form_hint && $has_email && count( $texts ) >= 3 && $has_submit );
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function looks_like_section_label( array $node ): bool {
		$texts = self::text_descendants( $node );
		$has_label_text = false;
		foreach ( $texts as $text ) {
			if ( preg_match( '/^\s*\d{2}\s*-\s*[A-ZĂÂÎȘŞȚŢ0-9 ]+\s*$/u', $text ) || preg_match( '/^\s*\d{2}\s*-/u', $text ) ) {
				$has_label_text = true;
				break;
			}
		}

		return $has_label_text && self::has_line_or_thin_rectangle( $node );
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function looks_like_video_poster( array $node ): bool {
		$name = strtolower( (string) ( $node['name'] ?? '' ) );
		$has_video_hint = str_contains( $name, 'video' )
			|| str_contains( $name, 'aftermovie' )
			|| str_contains( $name, 'poster' );

		return $has_video_hint && self::count_image_fill_descendants( $node ) >= 1 && self::has_play_named_vector( $node );
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function looks_like_image_gallery( array $node ): bool {
		$name = strtolower( (string) ( $node['name'] ?? '' ) );
		if ( ! str_contains( $name, 'gallery' ) && ! str_contains( $name, 'galerie' ) && ! str_contains( $name, 'foto' ) && ! str_contains( $name, 'photo' ) ) {
			return false;
		}
		return self::count_image_fill_descendants( $node ) >= 4;
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function looks_like_footer_link_column( array $node ): bool {
		$name     = strtolower( (string) ( $node['name'] ?? '' ) );
		$layout   = (string) ( $node['layoutMode'] ?? '' );
		$texts    = self::text_descendants( $node );
		$name_hint = str_contains( $name, 'footer' )
			|| str_contains( $name, 'link' )
			|| str_contains( $name, 'coloana' )
			|| str_contains( $name, 'column' );

		return $name_hint && 'VERTICAL' === $layout && count( $texts ) >= 3;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<int, string>
	 */
	private static function text_descendants( array $node ): array {
		$out = [];
		$children = ( isset( $node['children'] ) && is_array( $node['children'] ) ) ? $node['children'] : [];
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			if ( 'TEXT' === ( $child['type'] ?? '' ) ) {
				$text = trim( (string) ( $child['characters'] ?? '' ) );
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
			foreach ( self::text_descendants( $child ) as $text ) {
				$out[] = $text;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function count_image_fill_descendants( array $node ): int {
		$count = self::has_image_fill( $node ) ? 1 : 0;
		$children = ( isset( $node['children'] ) && is_array( $node['children'] ) ) ? $node['children'] : [];
		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				$count += self::count_image_fill_descendants( $child );
			}
		}
		return $count;
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function has_image_fill( array $node ): bool {
		$fills = $node['fills'] ?? [];
		if ( ! is_array( $fills ) ) {
			return false;
		}
		foreach ( $fills as $fill ) {
			if ( is_array( $fill ) && ( $fill['type'] ?? '' ) === 'IMAGE' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function has_line_or_thin_rectangle( array $node ): bool {
		if ( 'LINE' === ( $node['type'] ?? '' ) ) {
			return true;
		}

		$type = (string) ( $node['type'] ?? '' );
		$box  = is_array( $node['absoluteBoundingBox'] ?? null ) ? $node['absoluteBoundingBox'] : [];
		$width = isset( $box['width'] ) && is_numeric( $box['width'] ) ? (float) $box['width'] : 0.0;
		$height = isset( $box['height'] ) && is_numeric( $box['height'] ) ? (float) $box['height'] : 0.0;
		if ( in_array( $type, [ 'RECTANGLE', 'VECTOR' ], true ) && $width >= 24 && $height > 0 && $height <= 2 ) {
			return true;
		}

		$children = ( isset( $node['children'] ) && is_array( $node['children'] ) ) ? $node['children'] : [];
		foreach ( $children as $child ) {
			if ( is_array( $child ) && self::has_line_or_thin_rectangle( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function has_play_named_vector( array $node ): bool {
		$type = (string) ( $node['type'] ?? '' );
		$name = strtolower( (string) ( $node['name'] ?? '' ) );
		if ( in_array( $type, [ 'VECTOR', 'BOOLEAN_OPERATION', 'REGULAR_POLYGON' ], true ) && str_contains( $name, 'play' ) ) {
			return true;
		}

		$children = ( isset( $node['children'] ) && is_array( $node['children'] ) ) ? $node['children'] : [];
		foreach ( $children as $child ) {
			if ( is_array( $child ) && self::has_play_named_vector( $child ) ) {
				return true;
			}
		}

		return false;
	}
}
