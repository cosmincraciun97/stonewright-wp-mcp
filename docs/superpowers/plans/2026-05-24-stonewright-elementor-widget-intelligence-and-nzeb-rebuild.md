# Stonewright Elementor Widget Intelligence and nZEB Rebuild Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Stonewright choose and implement native Elementor V3/V4 widgets deterministically from prompts, images, and Figma nodes, then rebuild the nZEB page without HTML widget fallbacks.

**Architecture:** Add a catalog-backed widget recommender on top of the existing harvested Elementor manifest, wire it into `stonewright/widget-intent-resolve`, hard-block HTML widget usage unless explicitly approved, improve Figma pattern detection for native widgets/layouts, and only then apply the nZEB design through official Stonewright abilities with backup, validation, QA, and no ad hoc PHP scripts.

**Tech Stack:** WordPress plugin PHP 8.1, PHPUnit, PHPStan, PHPCS, Elementor V3/V4 data JSON, Stonewright DesignSpec, Node companion, Vitest, Figma REST/Desktop Bridge, Playwright QA.

---

## Current State

The previous interrupted turn already created failing tests. They are intentionally red and must be made green without deleting the tests.

Current red test command:

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'WidgetRecommenderTest|WidgetIntentResolveAbilityTest|HtmlWidgetGateTest'
```

Current failure meaning:

- `WidgetRecommender` does not exist yet.
- `stonewright/widget-intent-resolve` does not return ranked `recommendations` yet.
- HTML widget abilities still reach normal post checks before rejecting HTML fallback usage.
- `AddHtml` schema does not expose an explicit `allow_html_widget` gate yet.

Do not change live WordPress content until the MCP/plugin behavior is verified.

---

## Files and Responsibilities

### New Files

- `plugin/includes/Elementor/WidgetRegistry/WidgetRecommender.php`
  - Pure PHP ranker over `WidgetCatalog::widgets()`.
  - Excludes `html` unless `allow_html_widget` is explicitly true.
  - Returns ranked widget slugs, ability names, source, score, reasons, required settings, and highlights.

- `plugin/tests/Unit/Elementor/WidgetRegistry/WidgetRecommenderTest.php`
  - Tests prompt-driven widget selection from the real manifest.
  - Tests HTML exclusion by default.

- `plugin/tests/Unit/ElementorWidgets/HtmlWidgetGateTest.php`
  - Tests dedicated `stonewright/elementor-add-html` and raw `stonewright/elementor-v3-add-widget` rejection unless explicitly allowed.

### Existing Files To Modify

- `plugin/includes/Abilities/Design/WidgetIntentResolve.php`
  - Add `recommendations` to output.
  - Call `WidgetRecommender::recommend()` after intent detection.
  - Surface recommendation source and reasons.

- `plugin/includes/Design/WidgetIntentResolver.php`
  - Expand canonical intents beyond the small manual map.
  - Add prompt/Figma signatures for section labels, aftermovie/video, gallery, newsletter form, speaker grid, sticky header, mobile nav, footer link columns, and social icons.

- `plugin/includes/Design/FigmaToSpec.php`
  - Convert detected native intents to native DesignSpec blocks.
  - Improve grid detection and form/gallery/social/footer mapping.

- `plugin/includes/Abilities/ElementorWidgets/WidgetAbilityBase.php`
  - Add `allow_html_widget` schema key only for slug `html`.
  - Reject HTML widget before post lookup unless explicitly allowed.

- `plugin/includes/Abilities/ElementorV3/AddWidget.php`
  - Add `allow_html_widget` schema key.
  - Reject `widget_type=html` before post lookup unless explicitly allowed.

- `plugin/includes/Core/AgentInstructions.php`
  - Strengthen instructions: call `widget-intent-resolve`; use native widget recommendations; no HTML; custom CSS only with approval and only in active theme `style.css`.

- `plugin/includes/Admin/MemoryInstructionsPage.php`
  - Verify edit buttons work for memory.
  - Verify import/export UI includes instructions, memory, and skills.

- `plugin/includes/Knowledge/KnowledgeBundle.php`
  - Verify export/import bundle format includes custom instructions, memory entries, and skills.

- `companion/src/figma-bridge.ts`
  - Improve Figma heuristics for native Elementor patterns.
  - Export complex glow/radial blur background assets.
  - Preserve centered full-width section structure.

- `companion/src/prompt-to-spec.ts`
  - Make model instructions use native widget intents and background rules.

- `skills/design-to-wordpress/SKILL.md`
- `skills/elementor-v3-builder/SKILL.md`
- `skills/pixel-perfect-qa/SKILL.md`
  - Make persistent agent skills match the same rules.

---

## Task 1: Make the Red Widget Recommender Tests Pass

**Files:**

- Create: `D:\Work\stonewright-wp-mcp\plugin\includes\Elementor\WidgetRegistry\WidgetRecommender.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Elementor\WidgetRegistry\WidgetRecommenderTest.php`

- [ ] **Step 1: Run the red tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'WidgetRecommenderTest'
```

Expected: FAIL because `Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetRecommender` is missing.

- [ ] **Step 2: Create the recommender**

Create `plugin/includes/Elementor/WidgetRegistry/WidgetRecommender.php`:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetRegistry;

final class WidgetRecommender {

	/**
	 * @param array<string, mixed> $context
	 * @return array<int, array<string, mixed>>
	 */
	public static function recommend( string $prompt, int $limit = 5, array $context = [] ): array {
		$query = self::normalise( $prompt . ' ' . self::context_text( $context ) );
		if ( '' === $query ) {
			return [];
		}

		$allow_html = ! empty( $context['allow_html_widget'] );
		$rows       = [];

		foreach ( WidgetCatalog::widgets() as $slug => $entry ) {
			if ( 'html' === $slug && ! $allow_html ) {
				continue;
			}

			$score   = 0;
			$reasons = [];
			self::score_entry( $slug, $entry, $query, $score, $reasons );

			if ( $score <= 0 ) {
				continue;
			}

			$rows[] = [
				'slug'                => $slug,
				'title'               => (string) ( $entry['title'] ?? $slug ),
				'source'              => (string) ( $entry['source'] ?? 'free' ),
				'ability'             => 'stonewright/elementor-add-' . $slug,
				'score'               => $score,
				'reasons'             => $reasons,
				'required_for_render' => array_values( (array) ( $entry['required_for_render'] ?? [] ) ),
				'settings_highlights' => array_slice( array_values( (array) ( $entry['settings_highlights'] ?? [] ) ), 0, 4 ),
			];
		}

		usort(
			$rows,
			static fn ( array $a, array $b ): int => ( $b['score'] <=> $a['score'] )
				?: strcmp( (string) $a['slug'], (string) $b['slug'] )
		);

		return array_slice( $rows, 0, max( 1, $limit ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<int, string>   $reasons
	 */
	private static function score_entry( string $slug, array $entry, string $query, int &$score, array &$reasons ): void {
		$boosts = self::native_pattern_boosts();
		foreach ( $boosts as $pattern => $targets ) {
			if ( preg_match( $pattern, $query ) && isset( $targets[ $slug ] ) ) {
				$score += $targets[ $slug ];
				$reasons[] = 'matched native pattern';
			}
		}

		$title = self::normalise( (string) ( $entry['title'] ?? '' ) );
		if ( '' !== $title && str_contains( $query, $title ) ) {
			$score += 20;
			$reasons[] = 'matched title';
		}

		foreach ( (array) ( $entry['keywords'] ?? [] ) as $keyword ) {
			$needle = self::normalise( (string) $keyword );
			if ( '' !== $needle && str_contains( $query, $needle ) ) {
				$score += 12;
				$reasons[] = 'matched keyword: ' . $needle;
			}
		}

		$intent = self::normalise( (string) ( $entry['intent'] ?? '' ) );
		foreach ( self::query_terms( $query ) as $term ) {
			if ( strlen( $term ) >= 4 && str_contains( $intent, $term ) ) {
				$score += 2;
			}
		}
	}

	/**
	 * @return array<string, array<string, int>>
	 */
	private static function native_pattern_boosts(): array {
		return [
			'/\b(galerie|gallery|photo grid|foto|imagini)\b/u' => [
				'image-gallery' => 100,
				'gallery'       => 90,
			],
			'/\b(aftermovie|video|youtube|vimeo|mp4|poster|play)\b/u' => [
				'video' => 100,
			],
			'/\b(header|meniu|menu|navbar|hamburger|sticky)\b/u' => [
				'nav-menu' => 100,
				'icon-list' => 15,
			],
			'/\b(newsletter|formular|form|contact|email|subscribe|aboneaz)\w*\b/u' => [
				'form' => 100,
			],
			'/\b(social|facebook|instagram|linkedin|youtube|tiktok)\b/u' => [
				'social-icons' => 100,
			],
			'/\b(footer|subsol|coloane|links|linkuri)\b/u' => [
				'icon-list'    => 90,
				'social-icons' => 30,
			],
			'/\b(countdown|timer|cronometru|numaratoare)\b/u' => [
				'countdown' => 100,
			],
			'/\b(tabs?|taburi)\b/u' => [
				'tabs' => 100,
			],
			'/\b(faq|accordion|intrebari)\b/u' => [
				'accordion' => 100,
				'toggle'    => 80,
			],
			'/\b(html|embed|script|code|cod)\b/u' => [
				'html' => 100,
			],
		];
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function context_text( array $context ): string {
		$parts = [];
		foreach ( $context as $value ) {
			if ( is_scalar( $value ) ) {
				$parts[] = (string) $value;
			}
		}
		return implode( ' ', $parts );
	}

	private static function normalise( string $text ): string {
		$text = strtolower( remove_accents( $text ) );
		return trim( preg_replace( '/[^a-z0-9]+/u', ' ', $text ) ?? '' );
	}

	/**
	 * @return array<int, string>
	 */
	private static function query_terms( string $query ): array {
		return array_values( array_filter( explode( ' ', $query ), static fn ( string $term ): bool => '' !== $term ) );
	}
}
```

- [ ] **Step 3: Run the recommender tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'WidgetRecommenderTest'
```

Expected: PASS for recommender tests.

---

## Task 2: Wire Ranked Recommendations Into `stonewright/widget-intent-resolve`

**Files:**

- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\Design\WidgetIntentResolve.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Design\WidgetIntentResolveAbilityTest.php`

- [ ] **Step 1: Run the red ability tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'WidgetIntentResolveAbilityTest'
```

Expected: FAIL because `recommendations` is missing.

- [ ] **Step 2: Import the recommender**

Add this import:

```php
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetRecommender;
```

- [ ] **Step 3: Extend output schema**

Add this property under `output_schema()`:

```php
'recommendations' => [
	'type'  => 'array',
	'items' => [
		'type'                 => 'object',
		'additionalProperties' => true,
		'properties'           => [
			'slug'    => [ 'type' => 'string' ],
			'title'   => [ 'type' => 'string' ],
			'source'  => [ 'type' => 'string' ],
			'ability' => [ 'type' => 'string' ],
			'score'   => [ 'type' => 'integer' ],
		],
	],
],
```

- [ ] **Step 4: Return recommendations from execute**

Before returning the payload in `execute()`, compute:

```php
$prompt = isset( $args['prompt'] ) && is_string( $args['prompt'] ) ? $args['prompt'] : $intent;
$context = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : [];
$recommendations = WidgetRecommender::recommend( $prompt, 5, $context );
```

Then add:

```php
'recommendations' => $recommendations,
```

- [ ] **Step 5: Run the ability tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'WidgetIntentResolveAbilityTest|WidgetRecommenderTest'
```

Expected: PASS.

---

## Task 3: Hard-Block Elementor HTML Widgets Unless Explicitly Approved

**Files:**

- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\ElementorWidgets\WidgetAbilityBase.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Abilities\ElementorV3\AddWidget.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\ElementorWidgets\HtmlWidgetGateTest.php`

- [ ] **Step 1: Run the red HTML gate tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'HtmlWidgetGateTest'
```

Expected: FAIL because HTML is not gated yet.

- [ ] **Step 2: Add `allow_html_widget` to dedicated widget schema**

In `WidgetAbilityBase::input_schema()`, after the `settings` property is built, add this only when `$this->slug() === 'html'`:

```php
if ( 'html' === $this->slug() ) {
	$schema['properties']['allow_html_widget'] = [
		'type'        => 'boolean',
		'description' => 'Must be true only when the user explicitly asked for an Elementor HTML widget. Do not set this for fallback layout or styling.',
		'default'     => false,
	];
}
return $schema;
```

To do this cleanly, store the existing returned array in `$schema` instead of returning it immediately.

- [ ] **Step 3: Reject dedicated HTML widget before post lookup**

At the top of the audited callback in `WidgetAbilityBase::execute()`, before `$post_id` is read:

```php
if ( 'html' === $this->slug() && empty( $args['allow_html_widget'] ) ) {
	return new \WP_Error(
		'html_widget_requires_explicit_approval',
		__( 'Elementor HTML widgets are disabled by default. Use native Elementor widgets first, or pass allow_html_widget=true only when the user explicitly requested HTML.', 'stonewright' ),
		[ 'status' => 400 ]
	);
}
```

- [ ] **Step 4: Add `allow_html_widget` to raw add-widget schema**

In `AddWidget::input_schema()`, add:

```php
'allow_html_widget' => [
	'type'        => 'boolean',
	'description' => 'Must be true only when the user explicitly asked for widget_type=html. Native Elementor widgets must be used first.',
	'default'     => false,
],
```

- [ ] **Step 5: Reject raw HTML widget before post lookup**

At the top of the audited callback in `AddWidget::execute()`:

```php
$widget_type = isset( $args['widget_type'] ) ? (string) $args['widget_type'] : '';
if ( 'html' === $widget_type && empty( $args['allow_html_widget'] ) ) {
	return new \WP_Error(
		'html_widget_requires_explicit_approval',
		__( 'Elementor HTML widgets are disabled by default. Use native Elementor widgets first, or pass allow_html_widget=true only when the user explicitly requested HTML.', 'stonewright' ),
		[ 'status' => 400 ]
	);
}
```

- [ ] **Step 6: Run the HTML gate tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'HtmlWidgetGateTest'
```

Expected: PASS.

---

## Task 4: Expand Intent Detection For Real Elementor Decisions

**Files:**

- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Design\WidgetIntentResolver.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Design\WidgetIntentResolverTest.php`

- [ ] **Step 1: Add failing tests**

Add tests for these prompt detections:

```php
public function test_prompt_detection_covers_real_elementor_patterns(): void {
	self::assertSame( 'video', WidgetIntentResolver::detect_from_prompt( 'sectiune aftermovie cu video poster si play button' ) );
	self::assertSame( 'section-label', WidgetIntentResolver::detect_from_prompt( 'label 01 - Aftermovie cu border bottom sub text' ) );
	self::assertSame( 'sticky-header', WidgetIntentResolver::detect_from_prompt( 'header sticky desktop si mobile cu doua meniuri' ) );
	self::assertSame( 'mobile-nav', WidgetIntentResolver::detect_from_prompt( 'header mobile cu hamburger dropdown' ) );
	self::assertSame( 'social-row', WidgetIntentResolver::detect_from_prompt( 'footer cu iconuri svg facebook instagram linkedin youtube tiktok' ) );
}
```

Add tests for these Figma signatures:

```php
public function test_figma_signature_detects_section_label_with_underline(): void {
	$node = [
		'type'     => 'FRAME',
		'name'     => 'Label',
		'children' => [
			[ 'type' => 'TEXT', 'characters' => '01 - AFTERMOVIE', 'style' => [ 'fontSize' => 14 ] ],
			[ 'type' => 'LINE', 'absoluteBoundingBox' => [ 'width' => 136, 'height' => 1, 'x' => 0, 'y' => 24 ] ],
		],
	];
	self::assertSame( 'section-label', WidgetIntentResolver::detect_from_figma_signature( $node ) );
}

public function test_figma_signature_detects_video_poster(): void {
	$node = [
		'type'     => 'FRAME',
		'name'     => 'Aftermovie video',
		'children' => [
			[ 'type' => 'RECTANGLE', 'fills' => [ [ 'type' => 'IMAGE', 'imageRef' => 'poster' ] ] ],
			[ 'type' => 'VECTOR', 'name' => 'play' ],
		],
	];
	self::assertSame( 'video', WidgetIntentResolver::detect_from_figma_signature( $node ) );
}
```

- [ ] **Step 2: Extend `intents()`**

Add entries:

```php
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
		'paragraph' => [ 'style' => [ 'font_weight' => '600', 'letter_spacing' => '2px', 'text_transform' => 'uppercase' ] ],
		'divider'   => [ 'style' => 'solid', 'weight' => 1 ],
	],
	'required_steps'    => [],
	'description'       => 'Small uppercase section label with an underline/divider below or beside it.',
],
'sticky-header' => [
	'widget'            => null,
	'widgets'           => [ 'container', 'image', 'nav-menu', 'nav-menu', 'button' ],
	'settings_template' => [ 'container' => [ 'sticky' => 'top', 'sticky_on' => [ 'desktop', 'tablet', 'mobile' ], 'z_index' => 1000 ] ],
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
```

- [ ] **Step 3: Update prompt detection order**

Rules:

- `mobile + hamburger` beats generic header.
- `sticky + header` returns `sticky-header`.
- `aftermovie|video|play` returns `video`.
- `01 -`, `02 -`, `label`, `underline`, `border bottom` returns `section-label`.
- `footer` can still return `footer-template`, but `footer links column` returns `footer-link-column`.

- [ ] **Step 4: Update Figma signature detection**

Add helpers:

- `looks_like_section_label()`
- `looks_like_video_poster()`
- `has_line_or_thin_rectangle()`
- `has_play_named_vector()`

Keep this class pure: no WordPress globals and no I/O.

- [ ] **Step 5: Run resolver tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'WidgetIntentResolverTest|WidgetIntentResolveAbilityTest'
```

Expected: PASS.

---

## Task 5: Improve Figma To DesignSpec Native Block Mapping

**Files:**

- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Design\FigmaToSpec.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Design\FigmaToSpecTest.php`

- [ ] **Step 1: Add failing tests for native mapping**

Add tests that assert:

- Section label frame maps to container with paragraph and divider.
- Aftermovie video frame maps to `video`, not image plus custom HTML.
- Speaker grid frame maps to grid container with `columns => 4`.
- Newsletter form maps to native `form`.
- Footer direct link column maps to `icon-list`.

Example for video:

```php
public function test_video_poster_intent_maps_to_native_video_block(): void {
	$spec = FigmaToSpec::to_spec(
		[
			'id'       => 'root',
			'name'     => 'Page',
			'type'     => 'FRAME',
			'children' => [
				[
					'id'       => 'video',
					'name'     => 'Aftermovie video',
					'type'     => 'FRAME',
					'children' => [
						[ 'id' => 'poster', 'name' => 'Poster', 'type' => 'RECTANGLE', 'fills' => [ [ 'type' => 'IMAGE', 'imageRef' => 'poster-ref' ] ] ],
						[ 'id' => 'play', 'name' => 'play', 'type' => 'VECTOR' ],
					],
				],
			],
		]
	);

	self::assertSame( 'video', $spec['sections'][0]['blocks'][0]['type'] );
}
```

- [ ] **Step 2: Implement `intent_to_block()` cases**

Add cases:

- `video`: return `type => video`, `poster => image ref`, `url => ''`.
- `section-label`: return `type => container`, `layout => flex`, `direction => column`, `blocks => paragraph + divider`.
- `mobile-nav`: return `type => nav-menu` with dropdown/mobile/hamburger.

- [ ] **Step 3: Improve grid inference**

In `frame_to_column()` and companion mapping later, infer grid when:

- Child count is at least 4.
- Children have comparable width/height.
- Their `absoluteBoundingBox` positions form rows/columns.

Emit:

```php
$block['type'] = 'container';
$block['layout'] = 'grid';
$block['columns'] = 4;
$block['gap'] = self::extract_gap( $node ) ?? 24;
```

- [ ] **Step 4: Run FigmaToSpec tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'FigmaToSpecTest|WidgetIntentResolverTest'
```

Expected: PASS.

---

## Task 6: Improve Companion Figma Ingest For The Actual nZEB File

**Files:**

- Modify: `D:\Work\stonewright-wp-mcp\companion\src\figma-bridge.ts`
- Modify: `D:\Work\stonewright-wp-mcp\companion\tests\figma-bridge.test.ts`

- [ ] **Step 1: Add failing companion tests**

Add Vitest tests for:

- Complex glow/radial blur nodes are exported as section background assets.
- Flat color backgrounds stay as `background.color`.
- Linear/simple gradient is represented as Elementor-capable background where possible.
- Image-only gallery grid collapses to `image-gallery`.
- Newsletter form collapses to `form`.
- Speaker row/grid becomes `container` with `layout: grid`, `columns: 4`.
- Section label becomes paragraph plus divider.
- No `html` block is ever emitted.

- [ ] **Step 2: Add deterministic pattern helpers**

Implement helpers in `figma-bridge.ts`:

```ts
function textDescendants(block: MappedBlock): string[] { /* pure traversal */ }
function looksLikeNewsletterForm(block: MappedBlock): boolean { /* labels + email + submit */ }
function looksLikeSectionLabel(block: MappedBlock): boolean { /* 01 - label + line */ }
function looksLikeVideoPoster(block: MappedBlock): boolean { /* image poster + play vector */ }
function inferGridColumnsFromBounds(children: MappedBlock[]): number | null { /* positions */ }
function shouldPreserveAsNativeContainer(block: MappedBlock): boolean { /* header/footer/forms/grids */ }
```

- [ ] **Step 3: Emit native blocks**

Rules:

- `newsletter` tree: emit `type: 'form'`.
- Gallery tree: emit `type: 'image-gallery'`.
- Aftermovie/video poster tree: emit `type: 'video'`.
- Speaker grids: emit `type: 'container', layout: 'grid', columns: 4`, child speaker cards preserved without synthetic borders.
- Section labels: emit `paragraph` plus `divider`, not random line/rectangle widgets.
- Header/footer if included in selected node: emit native `nav-menu`, `icon-list`, `social-icons`, and sticky container settings.

- [ ] **Step 4: Run companion targeted tests**

```powershell
cd D:\Work\stonewright-wp-mcp\companion
npm test -- tests/figma-bridge.test.ts
```

Expected: PASS.

- [ ] **Step 5: Build and restart local companion**

```powershell
cd D:\Work\stonewright-wp-mcp\companion
npm run build
$existing = Get-NetTCPConnection -LocalPort 8765 -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty OwningProcess
if ($existing) { Stop-Process -Id $existing -Force; Start-Sleep -Milliseconds 700 }
$env:PORT='8765'
$env:STONEWRIGHT_DEV_INSECURE='1'
if (-not $env:FIGMA_TOKEN -and $env:FIGMA_ACCESS_TOKEN) { $env:FIGMA_TOKEN=$env:FIGMA_ACCESS_TOKEN }
Start-Process -FilePath 'node' -ArgumentList 'dist/index.js' -WorkingDirectory 'D:\Work\stonewright-wp-mcp\companion' -WindowStyle Hidden
```

Expected: companion health returns ok:

```powershell
Invoke-RestMethod -Method Get -Uri 'http://127.0.0.1:8765/health'
```

---

## Task 7: Fix Memory, Instructions, Skills Edit And Import/Export Path

**Files:**

- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Admin\MemoryInstructionsPage.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Knowledge\KnowledgeBundle.php`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Core\AgentInstructions.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Admin\MemoryInstructionsPageTest.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Knowledge\KnowledgeBundleTest.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Core\AgentInstructionsTest.php`

- [ ] **Step 1: Verify current UI tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'MemoryInstructionsPageTest|KnowledgeBundleTest|KnowledgeBundleAbilitiesTest|AgentInstructionsTest|SkillsTest'
```

Expected: PASS or reveal exactly which edit/import/export path is still missing.

- [ ] **Step 2: Required behavior**

Ensure:

- Memory rows have Edit and Delete actions.
- Edit form persists `name`, `type`, `scope`, `memory_key`, and `value_json`.
- Export JSON includes:
  - `format: stonewright-knowledge-bundle`
  - `instructions.text`
  - `memory.entries`
  - `skills.entries`
- Import JSON restores those values.
- `AgentInstructions::default()` includes enabled memory entries and enabled skills, not just hard-coded defaults.

- [ ] **Step 3: Add missing tests if any path is untested**

Add assertions:

```php
self::assertStringContainsString( 'Edit', $html );
self::assertStringContainsString( 'Export JSON', $html );
self::assertStringContainsString( 'Import JSON', $html );
self::assertStringContainsString( 'stonewright_memory_update', $html );
```

- [ ] **Step 4: Run tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'MemoryInstructionsPageTest|KnowledgeBundleTest|KnowledgeBundleAbilitiesTest|AgentInstructionsTest|SkillsTest'
```

Expected: PASS.

---

## Task 8: Strengthen Persistent Skills And Agent Instructions

**Files:**

- Modify: `D:\Work\stonewright-wp-mcp\skills\design-to-wordpress\SKILL.md`
- Modify: `D:\Work\stonewright-wp-mcp\skills\elementor-v3-builder\SKILL.md`
- Modify: `D:\Work\stonewright-wp-mcp\skills\pixel-perfect-qa\SKILL.md`
- Modify: `D:\Work\stonewright-wp-mcp\plugin\includes\Core\AgentInstructions.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Core\AgentInstructionsTest.php`
- Test: `D:\Work\stonewright-wp-mcp\plugin\tests\Unit\Skills\SkillsTest.php`

- [ ] **Step 1: Add explicit rules**

Rules to encode:

- Always call `stonewright/widget-intent-resolve` before choosing widgets from prompt/image/Figma.
- Always prefer native Elementor widgets and V3/V4 containers.
- Never use Elementor HTML widget unless user explicitly requested HTML and `allow_html_widget=true`.
- Custom CSS requires explicit approval and must be written to active theme `style.css`.
- For complex Figma glow/radial blur/shadow backgrounds, export a background asset and apply it to the container.
- For flat color backgrounds, use Elementor background color.
- For simple gradients, use Elementor background controls when possible.
- Header must use real nav menus and sticky settings.
- Mobile header must use real nav-menu hamburger/dropdown behavior.
- Footer link columns must use native link/icon-list/social-icons widgets.
- Gallery must use native gallery widget.
- Newsletter must use native form widget.
- Speaker images/cards must not receive extra synthetic borders when exported artwork already contains border graphics.

- [ ] **Step 2: Add tests**

In `AgentInstructionsTest`, assert these substrings:

```php
'stonewright/widget-intent-resolve'
'allow_html_widget'
'active theme style.css'
'complex Figma glow'
'native gallery'
'native form'
'sticky'
'hamburger'
```

- [ ] **Step 3: Run tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
php .\vendor\phpunit\phpunit\phpunit --filter 'AgentInstructionsTest|SkillsTest'
```

Expected: PASS.

---

## Task 9: Verify Figma Console MCP And Companion Ingest Separately

**Files:** No repo code unless a Stonewright bug is discovered.

- [ ] **Step 1: Try Figma Console MCP**

Use Figma Console MCP:

- `figma_search_components`
- `figma_list_open_files`
- `figma_navigate` to `https://www.figma.com/design/zfoLm0i7YDmVCowIsHlBDH/nz1?node-id=97-8306&m=dev`
- If connected, inspect node `97:8306`.

Expected: active file `nz1`, key `zfoLm0i7YDmVCowIsHlBDH`.

- [ ] **Step 2: If Figma Console MCP still says no files connected**

Do not assume Figma is broken. Check:

```powershell
Get-NetTCPConnection -LocalPort 9227 -ErrorAction SilentlyContinue | Select-Object LocalAddress,LocalPort,State,OwningProcess
```

Expected: if port is listening but MCP still sees no files, report it as Figma Console MCP session/port mismatch and continue via Stonewright companion REST for ingest.

- [ ] **Step 3: Verify live companion ingest**

```powershell
$body=@{
  request_id='codex-ingest'
  file_key='zfoLm0i7YDmVCowIsHlBDH'
  node_id='97:8306'
} | ConvertTo-Json
$r=Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:8765/figma-ingest' -ContentType 'application/json' -Body $body
[pscustomobject]@{
  sections=$r.spec.sections.Count
  assets=$r.asset_count
  warnings=$r.warnings.Count
  warnings_sample=($r.warnings | Select-Object -First 10)
} | ConvertTo-Json -Depth 5
```

Expected after fixes:

- `sections` is at least 5.
- `asset_count` is non-zero.
- warnings are low and mostly harmless decorative skips.
- spec contains native `image-gallery`, `form`, `video`, `nav-menu`, `icon-list`, `social-icons` where applicable.
- spec does not contain `type: html`.

---

## Task 10: Official Stonewright Dry Run Before Any WordPress Write

**Files:** No direct file edits.

- [ ] **Step 1: Locate official ability execution path**

Preferred order:

1. Use exposed Stonewright MCP tools if present.
2. Use authenticated WordPress Abilities API only if it goes through Stonewright ability classes.
3. Do not use ad hoc PHP scripts, sandbox snippets, or direct database writes.

- [ ] **Step 2: Dry-run `stonewright/design-build-from-figma-reference`**

Inputs:

```json
{
  "file_key": "zfoLm0i7YDmVCowIsHlBDH",
  "node_id": "97:8306",
  "post_id": 352,
  "renderer": "elementor_v3",
  "dry_run": true,
  "skip_qa": false
}
```

Expected:

- Validator passes.
- Renderer diagnostics do not include unsupported `html`.
- Quality gate does not block on severe ingest warnings.
- Returned spec includes native widget blocks.

- [ ] **Step 3: Stop if dry run is not clean**

If dry run fails or has severe warnings, do not apply. Fix ingest/rendering first.

---

## Task 11: Rebuild nZEB Page/Header/Footer With Native Elementor Widgets

**Files:** WordPress content via official Stonewright abilities only.

- [ ] **Step 1: Snapshot targets**

Known IDs from earlier context:

- Page A: `315`
- Header template: `316`
- Footer template: `317`
- Page B: `352`

Before writing any target, ability must call:

```php
Stonewright\WpMcp\Security\Backup::snapshot_post( $post_id );
```

- [ ] **Step 2: Rebuild header**

Required output:

- Full-width sticky header.
- Desktop and mobile sticky behavior.
- Two rows.
- Real WordPress menu for first row.
- Real WordPress menu for second row.
- Mobile uses nav-menu hamburger/dropdown, not fake stacked links.
- Logo is image asset from design.
- CTA buttons use native button widgets with correct font weight/colors.
- No HTML widget.
- No custom CSS unless user approves.

- [ ] **Step 3: Rebuild page B**

Required output:

- Outer sections full width.
- Inner content centered and constrained to design max width.
- Hero is a two-column row.
- Hero glow background comes from Figma-exported background asset.
- Hero fact icons use exact Figma/uploaded assets.
- Do not invent extra cards/borders; preserve Figma fills/strokes only.
- Aftermovie section uses native video or exact poster/image plus native play treatment if widget limitations require it.
- Section labels have correct typography and divider/underline.
- Speaker section uses grid container, 4 columns desktop, responsive tablet/mobile.
- Speaker card images keep exported border artwork without extra border.
- Gallery uses native `image-gallery`.
- Newsletter uses native `form`.
- No HTML widget.

- [ ] **Step 4: Rebuild footer**

Required output:

- Footer layout is a real multi-column container.
- Link columns are native `icon-list` or nav-menu/link widgets, not one long column.
- Contact rows use native text/icon-list widgets.
- Social icons use exact SVG/design assets where available; otherwise native social-icons only after reporting missing design assets.
- Bottom legal links are real links.
- No HTML widget.

- [ ] **Step 5: Verify Elementor data has no HTML widgets**

Inspect Elementor JSON for targets and assert:

```text
"widgetType":"html"
```

does not appear.

---

## Task 12: Browser QA And Responsive Verification

**Files:** No direct code unless QA finds a plugin bug.

- [ ] **Step 1: Desktop screenshot**

Open:

```text
http://mcp-test.local/editie-anterioara-b/?codex-verify=<timestamp>
```

Verify:

- Header sticky at top.
- Hero two-column layout.
- No giant white empty gutters.
- No stray “Rectangle 3” labels.
- No broken encoding if plugin can control text source.
- Gallery inside container.
- Footer multi-column.

- [ ] **Step 2: Scroll sticky behavior**

Scroll down and verify header remains sticky on desktop.

- [ ] **Step 3: Mobile screenshot**

Set mobile viewport and verify:

- Header uses hamburger/dropdown.
- Hero stacks cleanly.
- Speaker grid becomes 1 or 2 columns.
- Newsletter form stacks fields properly.
- Footer columns stack without becoming a long unstyled strip.

- [ ] **Step 4: Pixel/reference comparison**

Compare against Figma screenshots for:

- Hero.
- Aftermovie.
- Speaker.
- Gallery.
- Newsletter.
- Footer.

Do not claim pixel-perfect if warnings remain or visible drift remains.

---

## Task 13: Full Verification Before Claiming Completion

**Files:** No code edits.

- [ ] **Step 1: PHP unit tests**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
composer test
```

Expected: PASS.

- [ ] **Step 2: PHPStan**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
composer phpstan
```

Expected: PASS, no errors.

- [ ] **Step 3: PHPCS**

```powershell
cd D:\Work\stonewright-wp-mcp\plugin
composer phpcs
```

Expected: PASS, no violations.

- [ ] **Step 4: Companion tests**

```powershell
cd D:\Work\stonewright-wp-mcp\companion
npm test
```

Expected: PASS.

- [ ] **Step 5: Companion build**

```powershell
cd D:\Work\stonewright-wp-mcp\companion
npm run build
```

Expected: PASS.

- [ ] **Step 6: Final live QA**

Run browser verification after rebuild. Completion can only be claimed if:

- No HTML widgets are present.
- Dry-run and apply went through official Stonewright abilities.
- Backups were created.
- Validator ran before render.
- QA screenshots were inspected.
- Remaining drift is reported honestly.

---

## Non-Negotiable Guardrails

- Do not copy Novamira code, prompts, schemas, docs, identifiers, or implementation. Stonewright clean-room rule applies. High-level product comparison is allowed, source copying is not.
- Do not use arbitrary PHP execution.
- Do not bypass Stonewright ability validation to write WordPress content.
- Do not write custom CSS unless the user explicitly approves.
- If custom CSS is approved, write it only to the active theme `style.css`, organized and documented.
- Do not use Elementor HTML widget unless the user explicitly asks for an HTML widget and the call passes `allow_html_widget=true`.
- Do not claim “done”, “fixed”, “pixel perfect”, or “passes” without fresh verification output.

---

## Execution Recommendation

Use inline execution for Tasks 1-8 because they touch shared plugin classes and tests. Use official Stonewright ability execution for Tasks 10-11 only after the test suite is green. Use browser QA only after live rebuild.
