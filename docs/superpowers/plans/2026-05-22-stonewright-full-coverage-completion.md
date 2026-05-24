# Stonewright Full-Coverage Completion Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every gap between the original Stonewright spec (native Elementor V3+V4 + Gutenberg, Figma/image/prompt → pixel-perfect responsive output, Theme Builder header/footer, screenshot-based verification, sandboxed widget code editor) and the current shipped plugin.

**Architecture:** Five phases. Phase 0 confirms baseline. Phase 1 lands the user's explicit must-haves (Theme Builder + reference-image verification + V3 responsive). Phase 2 makes V4 real and adds vision/prompt bridges. Phase 3 expands ability surface to match Novamira/msrbuilds/claudeus parity. Phase 4 hardens sandbox + confirmation tokens. Phase 5 final regression + docs.

**Tech Stack:** PHP 8.1+, WordPress Abilities API, Elementor V3 classic data shape, Elementor V4 atomic widget API, Playwright (companion), Sharp pixel-diff (companion), `nikic/php-parser` for AST guard, Anthropic vision API via companion.

---

## Phase 0: Baseline Confirmation

### Task 0.1: Confirm baseline green

**Files:** none modified

- [ ] **Step 1: Run full PHP suite**

Run: `cd plugin && composer test`
Expected: 1845 tests, 4517 assertions, all pass.

- [ ] **Step 2: Run companion suite**

Run: `cd companion && npm test`
Expected: Vitest 127 tests pass.

- [ ] **Step 3: Verify branch state**

Run: `git status -sb && git log -1 --oneline`
Expected: clean tree, last commit is the Elementor-first hardening series.

- [ ] **Step 4: Commit checkpoint marker**

```bash
git commit --allow-empty -m "chore: baseline confirmed before full-coverage push"
```

---

## Phase 1: User-Explicit Must-Haves

### Task 1.1: Responsive emit helper

**Files:**
- Create: `plugin/includes/Elementor/Renderer/Responsive.php`
- Test: `plugin/tests/Unit/Elementor/Renderer/ResponsiveTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

final class ResponsiveTest extends TestCase {
    public function test_apply_emits_base_and_breakpoint_keys(): void {
        $settings = [];
        $value    = [
            'desktop' => '32px',
            'tablet'  => '24px',
            'mobile'  => '16px',
        ];
        $out = Responsive::apply( $settings, 'typography_font_size', $value );
        $this->assertSame( '32px', $out['typography_font_size'] );
        $this->assertSame( '24px', $out['typography_font_size_tablet'] );
        $this->assertSame( '16px', $out['typography_font_size_mobile'] );
    }

    public function test_apply_passthrough_scalar(): void {
        $out = Responsive::apply( [], 'padding', '12px' );
        $this->assertSame( '12px', $out['padding'] );
    }

    public function test_apply_ignores_unknown_breakpoint(): void {
        $out = Responsive::apply( [], 'gap', [ 'desktop' => '8px', 'foo' => 'x' ] );
        $this->assertArrayHasKey( 'gap', $out );
        $this->assertArrayNotHasKey( 'gap_foo', $out );
    }
}
```

- [ ] **Step 2: Run, expect fail**

Run: `cd plugin && vendor/bin/phpunit --filter ResponsiveTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

/**
 * Apply a possibly-responsive value to an Elementor V3 settings array.
 * Elementor stores responsive variants under `<key>_tablet` and `<key>_mobile`.
 */
final class Responsive {

    private const ALLOWED_BREAKPOINTS = [ 'desktop', 'tablet', 'mobile' ];

    /**
     * @param array<string, mixed> $settings
     * @param mixed                $value
     * @return array<string, mixed>
     */
    public static function apply( array $settings, string $key, $value ): array {
        if ( ! is_array( $value ) ) {
            $settings[ $key ] = $value;
            return $settings;
        }
        foreach ( $value as $bp => $bp_value ) {
            if ( ! in_array( $bp, self::ALLOWED_BREAKPOINTS, true ) ) {
                continue;
            }
            $suffix = ( 'desktop' === $bp ) ? '' : '_' . $bp;
            $settings[ $key . $suffix ] = $bp_value;
        }
        return $settings;
    }
}
```

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && vendor/bin/phpunit --filter ResponsiveTest`
Expected: PASS (3/3).

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Elementor/Renderer/Responsive.php plugin/tests/Unit/Elementor/Renderer/ResponsiveTest.php
git commit -m "feat(elementor-v3): add Responsive::apply helper for breakpoint key emission"
```

---

### Task 1.2: Wire Responsive into existing V3 renderers

**Files:**
- Modify: `plugin/includes/Elementor/Renderer/Heading.php`
- Modify: `plugin/includes/Elementor/Renderer/TextEditor.php`
- Modify: `plugin/includes/Elementor/Renderer/Image.php`
- Modify: `plugin/includes/Elementor/Renderer/Button.php`
- Modify: `plugin/includes/Elementor/Renderer/Spacer.php`
- Modify: `plugin/includes/Elementor/Renderer/Divider.php`
- Modify: `plugin/includes/Elementor/Renderer/Icon.php`
- Modify: `plugin/includes/Elementor/Renderer/Video.php`
- Test: `plugin/tests/Unit/Elementor/Renderer/ResponsiveIntegrationTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Renderer\Heading;

final class ResponsiveIntegrationTest extends TestCase {
    public function test_heading_emits_responsive_font_size(): void {
        $node = [
            'type'  => 'Heading',
            'props' => [
                'text'      => 'Hi',
                'font_size' => [ 'desktop' => '48px', 'mobile' => '24px' ],
            ],
        ];
        $element = Heading::render( $node, [] );
        $this->assertSame( '48px', $element['settings']['typography_font_size'] );
        $this->assertSame( '24px', $element['settings']['typography_font_size_mobile'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

Run: `cd plugin && vendor/bin/phpunit --filter ResponsiveIntegrationTest`
Expected: FAIL (key not set or shape mismatch).

- [ ] **Step 3: Modify Heading renderer** — replace the existing `font_size` mapping with `Responsive::apply()`. Repeat the same `Responsive::apply()` swap for every responsive-eligible prop in TextEditor, Image, Button, Spacer, Divider, Icon, Video. Use the existing renderer source as the template — each renderer has a `render()` method that builds `$settings`. Change every scalar prop write that has a documented responsive variant into a `$settings = Responsive::apply($settings, '<key>', $value);` call.

Heading example diff:

```php
// Before:
if ( isset( $props['font_size'] ) ) {
    $settings['typography_font_size'] = $props['font_size'];
}

// After:
if ( isset( $props['font_size'] ) ) {
    $settings = \Stonewright\WpMcp\Elementor\Renderer\Responsive::apply(
        $settings,
        'typography_font_size',
        $props['font_size']
    );
}
```

Responsive-eligible keys per renderer (use this list — do not invent more):
- Heading: `typography_font_size`, `align`
- TextEditor: `typography_font_size`, `align`
- Image: `width`, `align`
- Button: `typography_font_size`, `align`, `padding`
- Spacer: `space`
- Divider: `gap`, `weight`, `align`
- Icon: `size`, `align`
- Video: `aspect_ratio`

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && vendor/bin/phpunit --filter Elementor`
Expected: full Elementor renderer suite green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Elementor/Renderer/ plugin/tests/Unit/Elementor/Renderer/ResponsiveIntegrationTest.php
git commit -m "feat(elementor-v3): emit responsive variants for built-in widget renderers"
```

---

### Task 1.3: WidgetDefine compiler emits add_responsive_control

**Files:**
- Modify: `plugin/includes/Elementor/WidgetBuilder/Compiler.php`
- Test: `plugin/tests/Unit/WidgetCompilerResponsiveTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler;

final class WidgetCompilerResponsiveTest extends TestCase {
    public function test_responsive_flag_emits_add_responsive_control(): void {
        $spec = [
            'slug'     => 'my-card',
            'title'    => 'My Card',
            'category' => 'general',
            'controls' => [
                [
                    'name'       => 'padding',
                    'type'       => 'slider',
                    'label'      => 'Padding',
                    'responsive' => true,
                ],
                [
                    'name'  => 'title',
                    'type'  => 'text',
                    'label' => 'Title',
                ],
            ],
            'template' => '<div>{{ title }}</div>',
        ];
        $src = ( new Compiler() )->compile( $spec );
        $this->assertStringContainsString( '$this->add_responsive_control(', $src );
        $this->assertStringContainsString( '$this->add_control(', $src );
    }
}
```

- [ ] **Step 2: Run, expect fail**

Run: `cd plugin && vendor/bin/phpunit --filter WidgetCompilerResponsiveTest`
Expected: FAIL.

- [ ] **Step 3: Modify Compiler** — in the control emission path (currently always emits `add_control`), branch on `! empty( $control['responsive'] )` and emit `add_responsive_control` instead. Add `responsive: boolean` to the WidgetDefine input schema.

Snippet to add inside the existing per-control loop:

```php
$method = ! empty( $control['responsive'] ) ? 'add_responsive_control' : 'add_control';
$lines[] = sprintf( '        $this->%s(', $method );
```

Also update `plugin/includes/Abilities/ElementorV3/WidgetDefine.php` input_schema controls.items to include `'responsive' => [ 'type' => 'boolean' ]`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && vendor/bin/phpunit --filter WidgetCompiler`
Expected: all compiler tests green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Elementor/WidgetBuilder/Compiler.php plugin/includes/Abilities/ElementorV3/WidgetDefine.php plugin/tests/Unit/WidgetCompilerResponsiveTest.php
git commit -m "feat(widget-builder): emit add_responsive_control when control.responsive=true"
```

---

### Task 1.4: ReferenceArtifacts store

**Files:**
- Create: `plugin/includes/QA/ReferenceArtifacts.php`
- Test: `plugin/tests/Unit/QA/ReferenceArtifactsTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\QA;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\QA\ReferenceArtifacts;

final class ReferenceArtifactsTest extends TestCase {
    public function test_register_and_resolve_returns_artifact_id(): void {
        $id = ReferenceArtifacts::register(
            'home-hero-desktop',
            __DIR__ . '/fixtures/sample.png',
            [ 'viewport' => 'desktop' ]
        );
        $this->assertNotEmpty( $id );
        $meta = ReferenceArtifacts::resolve( 'home-hero-desktop' );
        $this->assertSame( $id, $meta['artifact_id'] );
        $this->assertSame( 'desktop', $meta['viewport'] );
    }

    public function test_resolve_unknown_label_returns_null(): void {
        $this->assertNull( ReferenceArtifacts::resolve( 'never-existed' ) );
    }
}
```

Create `plugin/tests/Unit/QA/fixtures/sample.png` (1x1 transparent PNG; binary content is fine — copy from an existing test fixture if present, or write a 67-byte minimal PNG).

- [ ] **Step 2: Run, expect fail**

Run: `cd plugin && vendor/bin/phpunit --filter ReferenceArtifactsTest`
Expected: FAIL (class missing).

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\QA;

/**
 * Lightweight label → reference-image registry, backed by an option store
 * so MCP callers can reuse a Figma export or uploaded baseline by name.
 */
final class ReferenceArtifacts {

    private const OPTION_KEY = 'stonewright_reference_artifacts';

    public static function register( string $label, string $path, array $meta = [] ): string {
        if ( ! is_readable( $path ) ) {
            return '';
        }
        $artifact_id = wp_generate_uuid4();
        $all         = self::all();
        $all[ $label ] = array_merge(
            $meta,
            [
                'artifact_id' => $artifact_id,
                'path'        => $path,
                'registered'  => time(),
            ]
        );
        update_option( self::OPTION_KEY, $all, false );
        return $artifact_id;
    }

    /** @return array<string, mixed>|null */
    public static function resolve( string $label ): ?array {
        $all = self::all();
        return $all[ $label ] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array {
        $stored = get_option( self::OPTION_KEY, [] );
        return is_array( $stored ) ? $stored : [];
    }
}
```

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && vendor/bin/phpunit --filter ReferenceArtifactsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/QA/ReferenceArtifacts.php plugin/tests/Unit/QA/
git commit -m "feat(qa): add ReferenceArtifacts label registry for diff baselines"
```

---

### Task 1.5: VerifyAgainstReference composite ability

**Files:**
- Create: `plugin/includes/Abilities/QA/VerifyAgainstReference.php`
- Test: `plugin/tests/Unit/Abilities/QA/VerifyAgainstReferenceTest.php`
- Modify: `plugin/includes/Plugin.php` (register the ability)

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\QA;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\QA\VerifyAgainstReference;

final class VerifyAgainstReferenceTest extends TestCase {
    public function test_schema_requires_post_or_url_and_reference_label(): void {
        $schema   = ( new VerifyAgainstReference() )->input_schema();
        $required = $schema['required'] ?? [];
        $this->assertContains( 'reference_label', $required );
    }

    public function test_name_uses_stonewright_prefix(): void {
        $this->assertSame( 'stonewright/qa-verify-against-reference', ( new VerifyAgainstReference() )->name() );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\QA\ResponsiveCheck;
use Stonewright\WpMcp\Abilities\QA\DiffScreenshot;
use Stonewright\WpMcp\QA\ReferenceArtifacts;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Composite QA ability:
 *   1. Resolve a labelled reference image per viewport.
 *   2. Capture current screenshots at the same viewports.
 *   3. Pixel-diff each (current vs reference) against threshold.
 *   4. Return a single pass/fail summary plus per-viewport detail.
 */
final class VerifyAgainstReference extends AbilityKernel {

    public function name(): string {
        return 'stonewright/qa-verify-against-reference';
    }

    public function label(): string {
        return __( 'QA: Verify against reference', 'stonewright' );
    }

    public function description(): string {
        return __( 'Capture responsive screenshots and pixel-diff each against a labelled reference baseline. Returns pass/fail + per-viewport mismatch ratios.', 'stonewright' );
    }

    public function category(): string {
        return 'qa';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'post_id'         => [ 'type' => 'integer', 'minimum' => 1 ],
                'url'             => [ 'type' => 'string', 'format' => 'uri' ],
                'reference_label' => [ 'type' => 'string' ],
                'threshold'       => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1, 'default' => 0.01 ],
                'design_spec'     => [ 'type' => 'object' ],
            ],
            'required' => [ 'reference_label' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'passed'   => [ 'type' => 'boolean' ],
                'summary'  => [ 'type' => 'string' ],
                'results'  => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'object' ],
                ],
            ],
            'required'   => [ 'passed', 'results' ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_posts();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $ref = ReferenceArtifacts::resolve( (string) $args['reference_label'] );
                if ( null === $ref ) {
                    return $this->error( 'reference_not_found', __( 'Reference label not registered.', 'stonewright' ) );
                }
                $threshold = (float) ( $args['threshold'] ?? 0.01 );

                $screens = ( new ResponsiveCheck() )->execute( array_intersect_key(
                    $args,
                    array_flip( [ 'post_id', 'url', 'design_spec' ] )
                ) );
                if ( is_wp_error( $screens ) ) {
                    return $screens;
                }

                $diffs  = [];
                $passed = true;
                foreach ( $screens as $shot ) {
                    $diff = ( new DiffScreenshot() )->execute(
                        [
                            'reference_artifact_id' => $ref['artifact_id'] ?? '',
                            'actual_artifact_id'    => $shot['artifact_id'] ?? '',
                            'threshold'             => $threshold,
                        ]
                    );
                    if ( is_wp_error( $diff ) ) {
                        return $diff;
                    }
                    $vp_passed = ! empty( $diff['passed'] );
                    $passed    = $passed && $vp_passed;
                    $diffs[]   = [
                        'viewport'   => $shot['viewport'] ?? [],
                        'diff_ratio' => $diff['diff_ratio'] ?? null,
                        'diff_url'   => $diff['diff_url'] ?? '',
                        'passed'     => $vp_passed,
                    ];
                }

                return [
                    'passed'  => $passed,
                    'summary' => $passed
                        ? __( 'All viewports within threshold.', 'stonewright' )
                        : __( 'One or more viewports exceeded the pixel-diff threshold.', 'stonewright' ),
                    'results' => $diffs,
                ];
            }
        );
    }
}
```

Register in `Plugin.php` next to the other QA abilities.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && vendor/bin/phpunit --filter VerifyAgainstReferenceTest && composer test`
Expected: full suite green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/QA/VerifyAgainstReference.php plugin/tests/Unit/Abilities/QA/VerifyAgainstReferenceTest.php plugin/includes/Plugin.php
git commit -m "feat(qa): add VerifyAgainstReference composite ability (responsive + diff vs label)"
```

---

### Task 1.6: Theme Builder CreateTemplate ability

**Files:**
- Create: `plugin/includes/Abilities/ThemeBuilder/CreateTemplate.php`
- Create: `plugin/includes/ThemeBuilder/TemplateStore.php`
- Test: `plugin/tests/Unit/Abilities/ThemeBuilder/CreateTemplateTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate;

final class CreateTemplateTest extends TestCase {
    public function test_name_and_required_inputs(): void {
        $a = new CreateTemplate();
        $this->assertSame( 'stonewright/theme-builder-create-template', $a->name() );
        $schema = $a->input_schema();
        $this->assertContains( 'template_type', $schema['required'] );
        $this->assertContains( 'title', $schema['required'] );
        $allowed = $schema['properties']['template_type']['enum'];
        $this->assertContains( 'header', $allowed );
        $this->assertContains( 'footer', $allowed );
        $this->assertContains( 'single', $allowed );
        $this->assertContains( 'archive', $allowed );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement TemplateStore**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\ThemeBuilder;

/**
 * Thin facade over Elementor Theme Builder storage.
 * Stores templates as `elementor_library` posts with `_elementor_template_type` meta
 * and `_elementor_conditions` meta for display rules.
 */
final class TemplateStore {

    public const ALLOWED_TYPES = [
        'header', 'footer', 'single', 'single-post', 'single-page',
        'archive', 'search-results', 'error-404', 'loop-item',
    ];

    public static function is_allowed_type( string $type ): bool {
        return in_array( $type, self::ALLOWED_TYPES, true );
    }

    public static function create( string $title, string $type ): int|\WP_Error {
        if ( ! self::is_allowed_type( $type ) ) {
            return new \WP_Error( 'invalid_template_type', __( 'Unsupported template_type.', 'stonewright' ) );
        }
        $id = wp_insert_post(
            [
                'post_title'  => $title,
                'post_type'   => 'elementor_library',
                'post_status' => 'publish',
            ],
            true
        );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        update_post_meta( $id, '_elementor_template_type', $type );
        update_post_meta( $id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $id, '_elementor_data', wp_json_encode( [] ) );
        return (int) $id;
    }

    /**
     * @param array<int, array<string, mixed>> $conditions
     */
    public static function set_conditions( int $template_id, array $conditions ): bool {
        return (bool) update_post_meta( $template_id, '_elementor_conditions', $conditions );
    }

    public static function get_type( int $template_id ): string {
        return (string) get_post_meta( $template_id, '_elementor_template_type', true );
    }
}
```

Implement CreateTemplate ability:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

final class CreateTemplate extends AbilityKernel {

    public function name(): string {
        return 'stonewright/theme-builder-create-template';
    }

    public function label(): string {
        return __( 'Theme Builder: Create template', 'stonewright' );
    }

    public function description(): string {
        return __( 'Creates a real elementor_library template (header / footer / single / archive / search / 404 / loop-item).', 'stonewright' );
    }

    public function category(): string {
        return 'theme-builder';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'title'         => [ 'type' => 'string', 'minLength' => 1 ],
                'template_type' => [ 'type' => 'string', 'enum' => TemplateStore::ALLOWED_TYPES ],
            ],
            'required' => [ 'title', 'template_type' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'template_id'   => [ 'type' => 'integer' ],
                'template_type' => [ 'type' => 'string' ],
            ],
            'required' => [ 'template_id', 'template_type' ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_posts();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $id = TemplateStore::create( (string) $args['title'], (string) $args['template_type'] );
                if ( is_wp_error( $id ) ) {
                    return $id;
                }
                return [ 'template_id' => $id, 'template_type' => (string) $args['template_type'] ];
            }
        );
    }
}
```

Register in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && vendor/bin/phpunit --filter CreateTemplateTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ThemeBuilder/CreateTemplate.php plugin/includes/ThemeBuilder/TemplateStore.php plugin/tests/Unit/Abilities/ThemeBuilder/CreateTemplateTest.php plugin/includes/Plugin.php
git commit -m "feat(theme-builder): add CreateTemplate ability + TemplateStore facade"
```

---

### Task 1.7: Theme Builder SetConditions + List + Get + Delete

**Files:**
- Create: `plugin/includes/Abilities/ThemeBuilder/SetConditions.php`
- Create: `plugin/includes/Abilities/ThemeBuilder/ListTemplates.php`
- Create: `plugin/includes/Abilities/ThemeBuilder/GetTemplate.php`
- Create: `plugin/includes/Abilities/ThemeBuilder/DeleteTemplate.php`
- Test: `plugin/tests/Unit/Abilities/ThemeBuilder/ThemeBuilderAbilitiesTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ListTemplates;
use Stonewright\WpMcp\Abilities\ThemeBuilder\GetTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate;

final class ThemeBuilderAbilitiesTest extends TestCase {
    public function test_ability_names(): void {
        $this->assertSame( 'stonewright/theme-builder-set-conditions', ( new SetConditions() )->name() );
        $this->assertSame( 'stonewright/theme-builder-list-templates', ( new ListTemplates() )->name() );
        $this->assertSame( 'stonewright/theme-builder-get-template', ( new GetTemplate() )->name() );
        $this->assertSame( 'stonewright/theme-builder-delete-template', ( new DeleteTemplate() )->name() );
    }

    public function test_set_conditions_requires_template_id_and_conditions(): void {
        $schema = ( new SetConditions() )->input_schema();
        $this->assertContains( 'template_id', $schema['required'] );
        $this->assertContains( 'conditions', $schema['required'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement four abilities**

SetConditions:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

final class SetConditions extends AbilityKernel {

    public function name(): string { return 'stonewright/theme-builder-set-conditions'; }
    public function label(): string { return __( 'Theme Builder: Set conditions', 'stonewright' ); }
    public function description(): string { return __( 'Sets display rules (where the template renders) for an elementor_library template.', 'stonewright' ); }
    public function category(): string { return 'theme-builder'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'conditions'  => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'type' => [ 'type' => 'string', 'enum' => [ 'include', 'exclude' ] ],
                            'name' => [ 'type' => 'string' ],
                            'sub_name' => [ 'type' => 'string' ],
                            'sub_id'   => [ 'type' => 'integer' ],
                        ],
                        'required' => [ 'type', 'name' ],
                    ],
                ],
            ],
            'required' => [ 'template_id', 'conditions' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [ 'template_id' => [ 'type' => 'integer' ], 'updated' => [ 'type' => 'boolean' ] ],
            'required'   => [ 'template_id', 'updated' ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_posts();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $id = (int) $args['template_id'];
                $post = get_post( $id );
                if ( ! $post || 'elementor_library' !== $post->post_type ) {
                    return $this->error( 'not_a_template', __( 'Post is not an elementor_library template.', 'stonewright' ) );
                }
                $ok = TemplateStore::set_conditions( $id, (array) $args['conditions'] );
                return [ 'template_id' => $id, 'updated' => $ok ];
            }
        );
    }
}
```

ListTemplates:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

final class ListTemplates extends AbilityKernel {

    public function name(): string { return 'stonewright/theme-builder-list-templates'; }
    public function label(): string { return __( 'Theme Builder: List templates', 'stonewright' ); }
    public function description(): string { return __( 'Lists elementor_library templates, optionally filtered by template_type.', 'stonewright' ); }
    public function category(): string { return 'theme-builder'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'template_type' => [ 'type' => 'string', 'enum' => TemplateStore::ALLOWED_TYPES ],
            ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'template_id'   => [ 'type' => 'integer' ],
                    'title'         => [ 'type' => 'string' ],
                    'template_type' => [ 'type' => 'string' ],
                ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_posts();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $query_args = [
                    'post_type'      => 'elementor_library',
                    'posts_per_page' => 200,
                    'post_status'    => 'any',
                    'fields'         => 'ids',
                ];
                if ( ! empty( $args['template_type'] ) ) {
                    $query_args['meta_query'] = [
                        [
                            'key'   => '_elementor_template_type',
                            'value' => (string) $args['template_type'],
                        ],
                    ];
                }
                $ids = get_posts( $query_args );
                $out = [];
                foreach ( $ids as $id ) {
                    $out[] = [
                        'template_id'   => (int) $id,
                        'title'         => get_the_title( (int) $id ),
                        'template_type' => TemplateStore::get_type( (int) $id ),
                    ];
                }
                return $out;
            }
        );
    }
}
```

GetTemplate:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

final class GetTemplate extends AbilityKernel {

    public function name(): string { return 'stonewright/theme-builder-get-template'; }
    public function label(): string { return __( 'Theme Builder: Get template', 'stonewright' ); }
    public function description(): string { return __( 'Reads a Theme Builder template (data tree + conditions + type).', 'stonewright' ); }
    public function category(): string { return 'theme-builder'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'             => [ 'template_id' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'template_id'   => [ 'type' => 'integer' ],
                'title'         => [ 'type' => 'string' ],
                'template_type' => [ 'type' => 'string' ],
                'conditions'    => [ 'type' => 'array' ],
                'tree'          => [ 'type' => 'array' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_posts();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $id = (int) $args['template_id'];
                $post = get_post( $id );
                if ( ! $post || 'elementor_library' !== $post->post_type ) {
                    return $this->error( 'not_a_template', __( 'Post is not an elementor_library template.', 'stonewright' ) );
                }
                $conditions = get_post_meta( $id, '_elementor_conditions', true );
                return [
                    'template_id'   => $id,
                    'title'         => get_the_title( $id ),
                    'template_type' => TemplateStore::get_type( $id ),
                    'conditions'    => is_array( $conditions ) ? $conditions : [],
                    'tree'          => ElementorData::read( $id ),
                ];
            }
        );
    }
}
```

DeleteTemplate:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

final class DeleteTemplate extends AbilityKernel {

    public function name(): string { return 'stonewright/theme-builder-delete-template'; }
    public function label(): string { return __( 'Theme Builder: Delete template', 'stonewright' ); }
    public function description(): string { return __( 'Trashes an elementor_library template (snapshotted first).', 'stonewright' ); }
    public function category(): string { return 'theme-builder'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'force'       => [ 'type' => 'boolean', 'default' => false ],
            ],
            'required' => [ 'template_id' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'template_id' => [ 'type' => 'integer' ],
                'snapshot_id' => [ 'type' => 'string' ],
                'deleted'     => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_posts();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $id   = (int) $args['template_id'];
                $post = get_post( $id );
                if ( ! $post || 'elementor_library' !== $post->post_type ) {
                    return $this->error( 'not_a_template', __( 'Post is not an elementor_library template.', 'stonewright' ) );
                }
                $snapshot_id = Backup::snapshot_post( $id );
                $deleted     = wp_delete_post( $id, (bool) ( $args['force'] ?? false ) );
                return [
                    'template_id' => $id,
                    'snapshot_id' => $snapshot_id,
                    'deleted'     => false !== $deleted,
                ];
            }
        );
    }
}
```

Register all four in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: full suite green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ThemeBuilder/ plugin/tests/Unit/Abilities/ThemeBuilder/ThemeBuilderAbilitiesTest.php plugin/includes/Plugin.php
git commit -m "feat(theme-builder): add SetConditions, ListTemplates, GetTemplate, DeleteTemplate abilities"
```

---

## Phase 2: V4 Atomic + Vision + Prompt

### Task 2.1: AtomicWidgetMap — DesignSpec node type → V4 widget identifier

**Files:**
- Create: `plugin/includes/Elementor/V4/AtomicWidgetMap.php`
- Test: `plugin/tests/Unit/Elementor/V4/AtomicWidgetMapTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicWidgetMap;

final class AtomicWidgetMapTest extends TestCase {
    public function test_known_node_types_map_to_atomic_widgets(): void {
        $this->assertSame( 'e-heading', AtomicWidgetMap::widget_type( 'Heading' ) );
        $this->assertSame( 'e-paragraph', AtomicWidgetMap::widget_type( 'TextEditor' ) );
        $this->assertSame( 'e-image', AtomicWidgetMap::widget_type( 'Image' ) );
        $this->assertSame( 'e-button', AtomicWidgetMap::widget_type( 'Button' ) );
        $this->assertSame( 'e-divider', AtomicWidgetMap::widget_type( 'Divider' ) );
        $this->assertSame( 'e-svg', AtomicWidgetMap::widget_type( 'Icon' ) );
    }

    public function test_container_types_map_to_flexbox(): void {
        $this->assertSame( 'e-flexbox', AtomicWidgetMap::widget_type( 'Section' ) );
        $this->assertSame( 'e-flexbox', AtomicWidgetMap::widget_type( 'Column' ) );
        $this->assertSame( 'e-flexbox', AtomicWidgetMap::widget_type( 'Container' ) );
    }

    public function test_unknown_returns_null(): void {
        $this->assertNull( AtomicWidgetMap::widget_type( 'NotAWidget' ) );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/**
 * DesignSpec node type → Elementor V4 atomic widget identifier.
 * Keep this list in sync with the V4 atomic registry — every entry must be a
 * widget that ships in current Elementor core, no Pro placeholders.
 */
final class AtomicWidgetMap {

    private const MAP = [
        // Containers
        'Section'    => 'e-flexbox',
        'Column'     => 'e-flexbox',
        'Container'  => 'e-flexbox',
        // Leaf widgets
        'Heading'    => 'e-heading',
        'TextEditor' => 'e-paragraph',
        'Image'      => 'e-image',
        'Button'     => 'e-button',
        'Divider'    => 'e-divider',
        'Icon'       => 'e-svg',
    ];

    public static function widget_type( string $node_type ): ?string {
        return self::MAP[ $node_type ] ?? null;
    }

    public static function is_container( string $node_type ): bool {
        return in_array( $node_type, [ 'Section', 'Column', 'Container' ], true );
    }
}
```

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && vendor/bin/phpunit --filter AtomicWidgetMapTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Elementor/V4/AtomicWidgetMap.php plugin/tests/Unit/Elementor/V4/AtomicWidgetMapTest.php
git commit -m "feat(elementor-v4): add AtomicWidgetMap (DesignSpec node → V4 widget id)"
```

---

### Task 2.2: AtomicRenderer — real V4 emission

**Files:**
- Create: `plugin/includes/Elementor/V4/AtomicRenderer.php`
- Modify: `plugin/includes/Renderers/ElementorV4SpecRenderer.php`
- Test: `plugin/tests/Unit/Elementor/V4/AtomicRendererTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicRenderer;

final class AtomicRendererTest extends TestCase {
    public function test_renders_heading_with_atomic_props(): void {
        $node = [
            'type'  => 'Heading',
            'props' => [ 'text' => 'Hello', 'level' => 2 ],
        ];
        $out = AtomicRenderer::render_node( $node );
        $this->assertSame( 'e-heading', $out['widgetType'] );
        $this->assertSame( 'widget', $out['elType'] );
        $this->assertSame( 'Hello', $out['settings']['title']['$$type'] === 'string' ? $out['settings']['title']['value'] : $out['settings']['title'] );
    }

    public function test_renders_section_with_children(): void {
        $node = [
            'type'     => 'Section',
            'children' => [
                [ 'type' => 'Heading', 'props' => [ 'text' => 'A' ] ],
                [ 'type' => 'Heading', 'props' => [ 'text' => 'B' ] ],
            ],
        ];
        $out = AtomicRenderer::render_node( $node );
        $this->assertSame( 'e-flexbox', $out['widgetType'] );
        $this->assertCount( 2, $out['elements'] );
        $this->assertSame( 'e-heading', $out['elements'][0]['widgetType'] );
    }

    public function test_unknown_node_routes_to_diagnostic(): void {
        $out = AtomicRenderer::render_node( [ 'type' => 'UnknownXYZ', 'props' => [] ] );
        $this->assertArrayHasKey( '__unsupported', $out );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Support\ElementorData;

/**
 * Renders a DesignSpec node tree into Elementor V4 atomic shape.
 * V4 wraps every prop in a typed envelope `{ $$type: 'string'|'number'|'size'|..., value: ... }`.
 */
final class AtomicRenderer {

    /** @return array<string, mixed> */
    public static function render_node( array $node ): array {
        $type   = (string) ( $node['type'] ?? '' );
        $widget = AtomicWidgetMap::widget_type( $type );

        if ( null === $widget ) {
            return [
                'id'            => ElementorData::generate_id(),
                'elType'        => 'widget',
                'widgetType'    => 'e-paragraph',
                'settings'      => [ 'paragraph' => [ '$$type' => 'string', 'value' => '' ] ],
                'elements'      => [],
                '__unsupported' => $type,
            ];
        }

        $children = [];
        foreach ( (array) ( $node['children'] ?? [] ) as $child ) {
            $children[] = self::render_node( $child );
        }

        $base = [
            'id'         => ElementorData::generate_id(),
            'elType'     => AtomicWidgetMap::is_container( $type ) ? 'container' : 'widget',
            'widgetType' => $widget,
            'settings'   => self::build_settings( $type, (array) ( $node['props'] ?? [] ) ),
            'elements'   => $children,
        ];

        return $base;
    }

    /** @param array<string, mixed> $props */
    private static function build_settings( string $type, array $props ): array {
        switch ( $type ) {
            case 'Heading':
                $s = [];
                if ( isset( $props['text'] ) ) {
                    $s['title'] = [ '$$type' => 'string', 'value' => (string) $props['text'] ];
                }
                if ( isset( $props['level'] ) ) {
                    $s['tag'] = [ '$$type' => 'string', 'value' => 'h' . (int) $props['level'] ];
                }
                return $s;
            case 'TextEditor':
                return isset( $props['text'] )
                    ? [ 'paragraph' => [ '$$type' => 'string', 'value' => (string) $props['text'] ] ]
                    : [];
            case 'Image':
                $s = [];
                if ( isset( $props['url'] ) ) {
                    $s['image'] = [ '$$type' => 'image', 'value' => [ 'src' => (string) $props['url'] ] ];
                }
                if ( isset( $props['alt'] ) ) {
                    $s['alt'] = [ '$$type' => 'string', 'value' => (string) $props['alt'] ];
                }
                return $s;
            case 'Button':
                $s = [];
                if ( isset( $props['text'] ) ) {
                    $s['text'] = [ '$$type' => 'string', 'value' => (string) $props['text'] ];
                }
                if ( isset( $props['link'] ) ) {
                    $s['link'] = [ '$$type' => 'link', 'value' => [ 'href' => (string) $props['link'] ] ];
                }
                return $s;
            case 'Divider':
                return [];
            case 'Icon':
                return isset( $props['svg'] )
                    ? [ 'svg' => [ '$$type' => 'svg', 'value' => (string) $props['svg'] ] ]
                    : [];
            case 'Section':
            case 'Column':
            case 'Container':
                $s = [];
                if ( isset( $props['direction'] ) ) {
                    $s['flex-direction'] = [ '$$type' => 'string', 'value' => (string) $props['direction'] ];
                }
                if ( isset( $props['gap'] ) ) {
                    $s['gap'] = [ '$$type' => 'size', 'value' => (string) $props['gap'] ];
                }
                return $s;
            default:
                return [];
        }
    }
}
```

Modify `plugin/includes/Renderers/ElementorV4SpecRenderer.php` so its `render()` method delegates to `AtomicRenderer::render_node()` instead of emitting empty shells. Keep the diagnostics array — collect every `__unsupported` value encountered while walking and return it alongside the tree.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: full suite green; AtomicRendererTest 3/3.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Elementor/V4/ plugin/includes/Renderers/ElementorV4SpecRenderer.php plugin/tests/Unit/Elementor/V4/AtomicRendererTest.php
git commit -m "feat(elementor-v4): replace stub renderer with real AtomicRenderer"
```

---

### Task 2.3: AtomicCompiler + AtomicWidgetDefine ability

**Files:**
- Create: `plugin/includes/Elementor/V4/AtomicCompiler.php`
- Create: `plugin/includes/Abilities/ElementorV4/AtomicWidgetDefine.php`
- Test: `plugin/tests/Unit/Elementor/V4/AtomicCompilerTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicCompiler;

final class AtomicCompilerTest extends TestCase {
    public function test_emits_class_extending_atomic_widget_base(): void {
        $spec = [
            'slug'       => 'my-atomic-card',
            'title'      => 'My Atomic Card',
            'props'      => [
                [ 'name' => 'title', 'type' => 'string', 'default' => 'Hi' ],
                [ 'name' => 'size',  'type' => 'size',   'default' => '32px' ],
            ],
            'template'   => '<div class="card"><h2>{{ title }}</h2></div>',
        ];
        $src = ( new AtomicCompiler() )->compile( $spec );
        $this->assertStringContainsString( 'extends Atomic_Widget_Base', $src );
        $this->assertStringContainsString( 'define_atomic_controls', $src );
        $this->assertStringContainsString( "'title'", $src );
        $this->assertStringContainsString( "'size'", $src );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement AtomicCompiler**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/**
 * Compiles a V4 atomic widget spec into a PHP source string.
 * Emitted class extends Atomic_Widget_Base, declares props via define_atomic_controls,
 * and renders via a sandboxed template walker (no DSL eval at runtime).
 */
final class AtomicCompiler {

    public function compile( array $spec ): string {
        $slug  = (string) $spec['slug'];
        $class = $this->slug_to_class( $slug );
        $title = (string) ( $spec['title'] ?? $slug );
        $props = (array) ( $spec['props'] ?? [] );

        $props_php = [];
        foreach ( $props as $prop ) {
            $props_php[] = sprintf(
                "            '%s' => Atomic_Prop_Schemas::%s()->default(%s),",
                addslashes( (string) $prop['name'] ),
                $this->schema_method( (string) ( $prop['type'] ?? 'string' ) ),
                var_export( $prop['default'] ?? null, true )
            );
        }
        $props_block = implode( "\n", $props_php );

        $template = addslashes( (string) ( $spec['template'] ?? '' ) );

        return <<<PHP
<?php
declare( strict_types=1 );

use Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Widget_Base;
use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Atomic_Prop_Schemas;

final class {$class} extends Atomic_Widget_Base {

    public static function get_element_type(): string {
        return '{$slug}';
    }

    public function get_title(): string {
        return '{$title}';
    }

    protected static function define_atomic_controls(): array {
        return [
{$props_block}
        ];
    }

    protected function render(): void {
        \$values = \$this->get_atomic_settings();
        \$template = '{$template}';
        echo \\Stonewright\\WpMcp\\Elementor\\V4\\AtomicTemplate::interpolate( \$template, \$values );
    }
}
PHP;
    }

    private function slug_to_class( string $slug ): string {
        $parts = array_map( 'ucfirst', preg_split( '/[-_]/', strtolower( $slug ) ) ?: [] );
        return 'Stonewright_Atomic_' . implode( '', $parts );
    }

    private function schema_method( string $type ): string {
        return match ( $type ) {
            'size'   => 'size',
            'color'  => 'color',
            'image'  => 'image',
            'link'   => 'link',
            'number' => 'number',
            default  => 'string',
        };
    }
}
```

Also create `plugin/includes/Elementor/V4/AtomicTemplate.php` with a single `interpolate( $template, $values )` method that ONLY replaces `{{ name }}` tokens using `esc_html( $values['name'] )` — no expressions, no conditionals, no PHP execution.

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

final class AtomicTemplate {

    /** @param array<string, mixed> $values */
    public static function interpolate( string $template, array $values ): string {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function ( array $m ) use ( $values ) {
                $key = $m[1];
                return isset( $values[ $key ] ) ? esc_html( (string) $values[ $key ] ) : '';
            },
            $template
        );
    }
}
```

Implement AtomicWidgetDefine ability (mirrors V3 WidgetDefine; calls AtomicCompiler; writes to the Sandbox Library):

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicCompiler;
use Stonewright\WpMcp\Sandbox\Library;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\Permissions;

final class AtomicWidgetDefine extends AbilityKernel {

    public function name(): string { return 'stonewright/elementor-v4-widget-define'; }
    public function label(): string { return __( 'Elementor V4: Define atomic widget', 'stonewright' ); }
    public function description(): string { return __( 'Compiles a V4 atomic widget spec into a sandboxed PHP class.', 'stonewright' ); }
    public function category(): string { return 'elementor-v4'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'slug'     => [ 'type' => 'string', 'pattern' => '^[a-z][a-z0-9-]*$' ],
                'title'    => [ 'type' => 'string' ],
                'props'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'name'    => [ 'type' => 'string' ],
                            'type'    => [ 'type' => 'string', 'enum' => [ 'string', 'number', 'size', 'color', 'image', 'link' ] ],
                            'default' => [],
                        ],
                        'required'   => [ 'name', 'type' ],
                    ],
                ],
                'template' => [ 'type' => 'string' ],
            ],
            'required' => [ 'slug', 'title', 'template' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'slug'  => [ 'type' => 'string' ],
                'file'  => [ 'type' => 'string' ],
                'class' => [ 'type' => 'string' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::manage();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $src   = ( new AtomicCompiler() )->compile( $args );
                $check = StaticGuard::scan( $src );
                if ( is_wp_error( $check ) ) {
                    return $check;
                }
                $written = Library::write_widget( (string) $args['slug'], $src, 'v4' );
                if ( is_wp_error( $written ) ) {
                    return $written;
                }
                return $written;
            }
        );
    }
}
```

Register in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: full suite green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Elementor/V4/ plugin/includes/Abilities/ElementorV4/AtomicWidgetDefine.php plugin/tests/Unit/Elementor/V4/AtomicCompilerTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v4): add AtomicCompiler + AtomicWidgetDefine ability"
```

---

### Task 2.4: Companion vision-bridge — image → DesignSpec

**Files:**
- Create: `companion/src/vision-bridge.ts`
- Create: `companion/test/vision-bridge.test.ts`
- Modify: `companion/src/server.ts` (mount `/vision/image-to-spec` route)
- Modify: `companion/package.json` (add `@anthropic-ai/sdk` dep)

- [ ] **Step 1: Failing test**

```typescript
import { describe, it, expect, vi } from 'vitest';
import { imageToSpec } from '../src/vision-bridge';

describe('vision-bridge', () => {
  it('returns a DesignSpec-shaped object from a base64 image', async () => {
    const fakeClient = {
      messages: {
        create: vi.fn().mockResolvedValue({
          content: [
            { type: 'text', text: JSON.stringify({ version: '1.0.0', root: { type: 'Section', children: [] } }) },
          ],
        }),
      },
    };
    const spec = await imageToSpec({
      imageBase64: 'iVBORw0KGgo=',
      mimeType: 'image/png',
      hint: 'a hero section with a heading and a button',
      client: fakeClient as any,
    });
    expect(spec.version).toBe('1.0.0');
    expect(spec.root.type).toBe('Section');
  });
});
```

- [ ] **Step 2: Run, expect fail**

Run: `cd companion && npm test -- vision-bridge`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement**

```typescript
import Anthropic from '@anthropic-ai/sdk';

const SYSTEM_PROMPT = `You convert a UI screenshot into a Stonewright DesignSpec JSON.
Return ONLY valid JSON, no markdown. Schema:
{
  "version": "1.0.0",
  "root": {
    "type": "Section" | "Container" | "Column",
    "props": { ... },
    "children": [ ... ]
  }
}
Allowed leaf types: Heading, TextEditor, Image, Button, Divider, Icon.
Props use responsive maps where relevant: { desktop, tablet, mobile }.`;

export interface ImageToSpecInput {
  imageBase64: string;
  mimeType: 'image/png' | 'image/jpeg' | 'image/webp';
  hint?: string;
  client?: Anthropic;
}

export async function imageToSpec(input: ImageToSpecInput): Promise<Record<string, unknown>> {
  const client = input.client ?? new Anthropic();
  const response = await client.messages.create({
    model: 'claude-opus-4-7',
    max_tokens: 4096,
    system: SYSTEM_PROMPT,
    messages: [
      {
        role: 'user',
        content: [
          {
            type: 'image',
            source: { type: 'base64', media_type: input.mimeType, data: input.imageBase64 },
          },
          { type: 'text', text: input.hint ?? 'Convert this screenshot to a DesignSpec.' },
        ],
      },
    ],
  });
  const text = response.content.find((c: { type: string }) => c.type === 'text');
  if (!text || !('text' in text)) {
    throw new Error('vision_no_text_response');
  }
  return JSON.parse(text.text);
}
```

Wire a route in `server.ts`:

```typescript
import { imageToSpec } from './vision-bridge';

app.post('/vision/image-to-spec', async (req, res) => {
  try {
    const { image_base64, mime_type, hint } = req.body ?? {};
    if (!image_base64 || !mime_type) return res.status(400).json({ error: 'image_base64 and mime_type required' });
    const spec = await imageToSpec({ imageBase64: image_base64, mimeType: mime_type, hint });
    res.json({ ok: true, spec });
  } catch (err) {
    res.status(500).json({ error: (err as Error).message });
  }
});
```

Add `@anthropic-ai/sdk` to `companion/package.json` dependencies.

- [ ] **Step 4: Run, expect pass**

Run: `cd companion && npm install && npm test`
Expected: vitest green.

- [ ] **Step 5: Commit**

```bash
git add companion/src/vision-bridge.ts companion/src/server.ts companion/test/vision-bridge.test.ts companion/package.json companion/package-lock.json
git commit -m "feat(companion): add vision-bridge (image → DesignSpec via Anthropic vision)"
```

---

### Task 2.5: ImageToSpec PHP ability

**Files:**
- Create: `plugin/includes/Abilities/Vision/ImageToSpec.php`
- Test: `plugin/tests/Unit/Abilities/Vision/ImageToSpecTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Vision;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Vision\ImageToSpec;

final class ImageToSpecTest extends TestCase {
    public function test_name_and_required_inputs(): void {
        $a = new ImageToSpec();
        $this->assertSame( 'stonewright/vision-image-to-spec', $a->name() );
        $schema = $a->input_schema();
        $this->assertContains( 'image_base64', $schema['required'] );
        $this->assertContains( 'mime_type', $schema['required'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Vision;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

final class ImageToSpec extends AbilityKernel {

    public function name(): string { return 'stonewright/vision-image-to-spec'; }
    public function label(): string { return __( 'Vision: Image to DesignSpec', 'stonewright' ); }
    public function description(): string { return __( 'Posts a screenshot to the companion vision bridge and returns a validated DesignSpec.', 'stonewright' ); }
    public function category(): string { return 'vision'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'image_base64' => [ 'type' => 'string', 'minLength' => 1 ],
                'mime_type'    => [ 'type' => 'string', 'enum' => [ 'image/png', 'image/jpeg', 'image/webp' ] ],
                'hint'         => [ 'type' => 'string' ],
            ],
            'required' => [ 'image_base64', 'mime_type' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'spec'       => [ 'type' => 'object' ],
                'validation' => [ 'type' => 'object' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_posts();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $result = CompanionClient::post(
                    '/vision/image-to-spec',
                    [
                        'image_base64' => (string) $args['image_base64'],
                        'mime_type'    => (string) $args['mime_type'],
                        'hint'         => (string) ( $args['hint'] ?? '' ),
                    ]
                );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
                $spec       = $result['spec'] ?? [];
                $validation = Validator::validate( $spec );
                if ( is_wp_error( $validation ) ) {
                    return $validation;
                }
                return [ 'spec' => $spec, 'validation' => [ 'ok' => true ] ];
            }
        );
    }
}
```

Register in `Plugin.php`. Strip `image_base64` from audit logs via `audit_redacted_keys()`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/Vision/ImageToSpec.php plugin/tests/Unit/Abilities/Vision/ImageToSpecTest.php plugin/includes/Plugin.php
git commit -m "feat(vision): add ImageToSpec ability bridging companion vision endpoint"
```

---

### Task 2.6: Companion prompt-to-spec + PromptToSpec ability

**Files:**
- Create: `companion/src/prompt-to-spec.ts`
- Create: `companion/test/prompt-to-spec.test.ts`
- Modify: `companion/src/server.ts`
- Create: `plugin/includes/Abilities/Vision/PromptToSpec.php`
- Test: `plugin/tests/Unit/Abilities/Vision/PromptToSpecTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing tests**

`companion/test/prompt-to-spec.test.ts`:

```typescript
import { describe, it, expect, vi } from 'vitest';
import { promptToSpec } from '../src/prompt-to-spec';

describe('prompt-to-spec', () => {
  it('returns DesignSpec JSON from a text prompt', async () => {
    const fakeClient = {
      messages: {
        create: vi.fn().mockResolvedValue({
          content: [
            { type: 'text', text: JSON.stringify({ version: '1.0.0', root: { type: 'Section', children: [] } }) },
          ],
        }),
      },
    };
    const out = await promptToSpec({ prompt: 'A pricing section', client: fakeClient as any });
    expect(out.version).toBe('1.0.0');
  });
});
```

`plugin/tests/Unit/Abilities/Vision/PromptToSpecTest.php`:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Vision;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Vision\PromptToSpec;

final class PromptToSpecTest extends TestCase {
    public function test_required_prompt(): void {
        $schema = ( new PromptToSpec() )->input_schema();
        $this->assertContains( 'prompt', $schema['required'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

`companion/src/prompt-to-spec.ts`:

```typescript
import Anthropic from '@anthropic-ai/sdk';

const SYSTEM_PROMPT = `Convert a layout description into a Stonewright DesignSpec JSON.
Return ONLY valid JSON.`;

export interface PromptToSpecInput {
  prompt: string;
  client?: Anthropic;
}

export async function promptToSpec(input: PromptToSpecInput): Promise<Record<string, unknown>> {
  const client = input.client ?? new Anthropic();
  const response = await client.messages.create({
    model: 'claude-opus-4-7',
    max_tokens: 4096,
    system: SYSTEM_PROMPT,
    messages: [{ role: 'user', content: input.prompt }],
  });
  const text = response.content.find((c: { type: string }) => c.type === 'text');
  if (!text || !('text' in text)) throw new Error('prompt_no_text_response');
  return JSON.parse(text.text);
}
```

Wire `/vision/prompt-to-spec` in `server.ts`.

`PromptToSpec.php` mirrors `ImageToSpec` — POST `/vision/prompt-to-spec` with `{ prompt }`, validate, return.

- [ ] **Step 4: Run, expect pass**

Run: `cd companion && npm test && cd ../plugin && composer test`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add companion/src/prompt-to-spec.ts companion/test/prompt-to-spec.test.ts companion/src/server.ts plugin/includes/Abilities/Vision/PromptToSpec.php plugin/tests/Unit/Abilities/Vision/PromptToSpecTest.php plugin/includes/Plugin.php
git commit -m "feat(vision): add PromptToSpec (text → DesignSpec via companion)"
```

---

## Phase 3: Ability Surface Parity

### Task 3.1: DuplicateElement

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/DuplicateElement.php`
- Test: `plugin/tests/Unit/Abilities/ElementorV3/DuplicateElementTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\DuplicateElement;

final class DuplicateElementTest extends TestCase {
    public function test_name_and_required(): void {
        $a = new DuplicateElement();
        $this->assertSame( 'stonewright/elementor-v3-duplicate-element', $a->name() );
        $schema = $a->input_schema();
        $this->assertContains( 'post_id', $schema['required'] );
        $this->assertContains( 'element_id', $schema['required'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

final class DuplicateElement extends AbilityKernel {

    public function name(): string { return 'stonewright/elementor-v3-duplicate-element'; }
    public function label(): string { return __( 'Elementor: Duplicate element', 'stonewright' ); }
    public function description(): string { return __( 'Clones an element (with all children, fresh ids) next to the original.', 'stonewright' ); }
    public function category(): string { return 'elementor'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id' => [ 'type' => 'string' ],
            ],
            'required' => [ 'post_id', 'element_id' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id'        => [ 'type' => 'integer' ],
                'new_element_id' => [ 'type' => 'string' ],
                'snapshot_id'    => [ 'type' => 'string' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $post_id     = (int) $args['post_id'];
                $snapshot_id = Backup::snapshot_post( $post_id );
                $tree        = ElementorData::read( $post_id );
                $path        = ElementorData::find_path( $tree, (string) $args['element_id'] );
                if ( null === $path ) {
                    return $this->error( 'not_found', __( 'Element not found.', 'stonewright' ) );
                }
                $node = $this->walk( $tree, $path );
                if ( null === $node ) {
                    return $this->error( 'not_found', __( 'Element node missing.', 'stonewright' ) );
                }
                $clone     = $this->reassign_ids( $node );
                $parent    = array_slice( $path, 0, -1 );
                $position  = (int) end( $path ) + 1;
                $new_tree  = ElementorData::insert( $tree, $parent, $position, $clone );
                if ( ! ElementorData::write( $post_id, $new_tree ) ) {
                    return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
                }
                return [ 'post_id' => $post_id, 'new_element_id' => $clone['id'], 'snapshot_id' => $snapshot_id ];
            }
        );
    }

    /** @return array<string, mixed>|null */
    private function walk( array $tree, array $path ): ?array {
        $cursor = $tree;
        foreach ( $path as $i ) {
            if ( ! isset( $cursor[ $i ] ) ) {
                return null;
            }
            $cursor = $cursor[ $i ];
            if ( isset( $cursor['elements'] ) && $i !== end( $path ) ) {
                $cursor = $cursor['elements'];
            }
        }
        return is_array( $cursor ) ? $cursor : null;
    }

    /** @param array<string, mixed> $node */
    private function reassign_ids( array $node ): array {
        $node['id'] = ElementorData::generate_id();
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            foreach ( $node['elements'] as $k => $child ) {
                $node['elements'][ $k ] = $this->reassign_ids( (array) $child );
            }
        }
        return $node;
    }
}
```

Register in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/DuplicateElement.php plugin/tests/Unit/Abilities/ElementorV3/DuplicateElementTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v3): add DuplicateElement ability (cloned subtree with fresh ids)"
```

---

### Task 3.2: ReorderChildren

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/ReorderChildren.php`
- Test: `plugin/tests/Unit/Abilities/ElementorV3/ReorderChildrenTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\ReorderChildren;

final class ReorderChildrenTest extends TestCase {
    public function test_name_and_required(): void {
        $a = new ReorderChildren();
        $this->assertSame( 'stonewright/elementor-v3-reorder-children', $a->name() );
        $schema = $a->input_schema();
        $this->assertContains( 'post_id', $schema['required'] );
        $this->assertContains( 'parent_id', $schema['required'] );
        $this->assertContains( 'order', $schema['required'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

final class ReorderChildren extends AbilityKernel {

    public function name(): string { return 'stonewright/elementor-v3-reorder-children'; }
    public function label(): string { return __( 'Elementor: Reorder children', 'stonewright' ); }
    public function description(): string { return __( 'Reorders direct children of a container by listing their element ids in the desired order.', 'stonewright' ); }
    public function category(): string { return 'elementor'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'post_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                'parent_id' => [ 'type' => 'string' ],
                'order'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
            'required' => [ 'post_id', 'parent_id', 'order' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id'     => [ 'type' => 'integer' ],
                'snapshot_id' => [ 'type' => 'string' ],
                'applied'     => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $post_id     = (int) $args['post_id'];
                $snapshot_id = Backup::snapshot_post( $post_id );
                $tree        = ElementorData::read( $post_id );
                $parent_path = ElementorData::find_path( $tree, (string) $args['parent_id'] );
                if ( null === $parent_path ) {
                    return $this->error( 'parent_not_found', __( 'Parent not found.', 'stonewright' ) );
                }
                $new_tree = $this->reorder( $tree, $parent_path, (array) $args['order'] );
                if ( ! ElementorData::write( $post_id, $new_tree ) ) {
                    return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
                }
                return [ 'post_id' => $post_id, 'snapshot_id' => $snapshot_id, 'applied' => true ];
            }
        );
    }

    /**
     * @param array<int, mixed> $tree
     * @param array<int, int>   $parent_path
     * @param array<int, string> $desired_order
     * @return array<int, mixed>
     */
    private function reorder( array $tree, array $parent_path, array $desired_order ): array {
        $ref = &$tree;
        foreach ( $parent_path as $i ) {
            $ref = &$ref[ $i ];
            if ( isset( $ref['elements'] ) ) {
                $ref = &$ref['elements'];
            }
        }
        $by_id = [];
        foreach ( $ref as $child ) {
            if ( isset( $child['id'] ) ) {
                $by_id[ (string) $child['id'] ] = $child;
            }
        }
        $sorted = [];
        foreach ( $desired_order as $id ) {
            if ( isset( $by_id[ $id ] ) ) {
                $sorted[] = $by_id[ $id ];
                unset( $by_id[ $id ] );
            }
        }
        foreach ( $by_id as $leftover ) {
            $sorted[] = $leftover;
        }
        $ref = $sorted;
        return $tree;
    }
}
```

Register in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/ReorderChildren.php plugin/tests/Unit/Abilities/ElementorV3/ReorderChildrenTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v3): add ReorderChildren ability"
```

---

### Task 3.3: BatchUpdate

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/BatchUpdate.php`
- Test: `plugin/tests/Unit/Abilities/ElementorV3/BatchUpdateTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchUpdate;

final class BatchUpdateTest extends TestCase {
    public function test_name_and_required(): void {
        $a = new BatchUpdate();
        $this->assertSame( 'stonewright/elementor-v3-batch-update', $a->name() );
        $schema = $a->input_schema();
        $this->assertContains( 'post_id', $schema['required'] );
        $this->assertContains( 'updates', $schema['required'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

final class BatchUpdate extends AbilityKernel {

    public function name(): string { return 'stonewright/elementor-v3-batch-update'; }
    public function label(): string { return __( 'Elementor: Batch update widgets', 'stonewright' ); }
    public function description(): string { return __( 'Applies multiple {element_id, settings} updates in one snapshot.', 'stonewright' ); }
    public function category(): string { return 'elementor'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'updates' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'element_id' => [ 'type' => 'string' ],
                            'settings'   => [ 'type' => 'object' ],
                        ],
                        'required' => [ 'element_id', 'settings' ],
                    ],
                ],
            ],
            'required' => [ 'post_id', 'updates' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id'     => [ 'type' => 'integer' ],
                'snapshot_id' => [ 'type' => 'string' ],
                'applied'     => [ 'type' => 'integer' ],
                'missing'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $post_id     = (int) $args['post_id'];
                $snapshot_id = Backup::snapshot_post( $post_id );
                $tree        = ElementorData::read( $post_id );
                $applied     = 0;
                $missing     = [];
                foreach ( (array) $args['updates'] as $u ) {
                    $eid   = (string) ( $u['element_id'] ?? '' );
                    $patch = (array) ( $u['settings'] ?? [] );
                    $path  = ElementorData::find_path( $tree, $eid );
                    if ( null === $path ) {
                        $missing[] = $eid;
                        continue;
                    }
                    $tree = $this->merge_settings( $tree, $path, $patch );
                    $applied++;
                }
                if ( ! ElementorData::write( $post_id, $tree ) ) {
                    return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
                }
                return [
                    'post_id'     => $post_id,
                    'snapshot_id' => $snapshot_id,
                    'applied'     => $applied,
                    'missing'     => $missing,
                ];
            }
        );
    }

    /**
     * @param array<int, mixed> $tree
     * @param array<int, int>   $path
     * @param array<string, mixed> $patch
     * @return array<int, mixed>
     */
    private function merge_settings( array $tree, array $path, array $patch ): array {
        $ref = &$tree;
        foreach ( $path as $depth => $i ) {
            $ref = &$ref[ $i ];
            $is_last = ( $depth === array_key_last( $path ) );
            if ( ! $is_last && isset( $ref['elements'] ) ) {
                $ref = &$ref['elements'];
            }
        }
        $ref['settings'] = array_merge( (array) ( $ref['settings'] ?? [] ), $patch );
        return $tree;
    }
}
```

Register in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/BatchUpdate.php plugin/tests/Unit/Abilities/ElementorV3/BatchUpdateTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v3): add BatchUpdate ability"
```

---

### Task 3.4: FindElement

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/FindElement.php`
- Test: `plugin/tests/Unit/Abilities/ElementorV3/FindElementTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\FindElement;

final class FindElementTest extends TestCase {
    public function test_name_and_required(): void {
        $a = new FindElement();
        $this->assertSame( 'stonewright/elementor-v3-find-element', $a->name() );
        $this->assertContains( 'post_id', $a->input_schema()['required'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement** — searches the flattened tree by widget_type and/or settings substring; returns matching `element_id`s.

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

final class FindElement extends AbilityKernel {

    public function name(): string { return 'stonewright/elementor-v3-find-element'; }
    public function label(): string { return __( 'Elementor: Find elements', 'stonewright' ); }
    public function description(): string { return __( 'Finds elements by widget_type and/or settings-text substring.', 'stonewright' ); }
    public function category(): string { return 'elementor'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'widget_type'  => [ 'type' => 'string' ],
                'text_contains'=> [ 'type' => 'string' ],
            ],
            'required' => [ 'post_id' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'element_id' => [ 'type' => 'string' ],
                    'widget_type'=> [ 'type' => 'string' ],
                    'elType'     => [ 'type' => 'string' ],
                ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $tree     = ElementorData::read( (int) $args['post_id'] );
                $flat     = ElementorData::flatten( $tree );
                $wanted_w = (string) ( $args['widget_type'] ?? '' );
                $needle   = (string) ( $args['text_contains'] ?? '' );
                $out      = [];
                foreach ( $flat as $node ) {
                    if ( '' !== $wanted_w && ( $node['widgetType'] ?? '' ) !== $wanted_w ) {
                        continue;
                    }
                    if ( '' !== $needle ) {
                        $blob = wp_json_encode( $node['settings'] ?? [] );
                        if ( ! is_string( $blob ) || false === stripos( $blob, $needle ) ) {
                            continue;
                        }
                    }
                    $out[] = [
                        'element_id'  => (string) ( $node['id'] ?? '' ),
                        'widget_type' => (string) ( $node['widgetType'] ?? '' ),
                        'elType'      => (string) ( $node['elType'] ?? '' ),
                    ];
                }
                return $out;
            }
        );
    }
}
```

Register in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/FindElement.php plugin/tests/Unit/Abilities/ElementorV3/FindElementTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v3): add FindElement ability"
```

---

### Task 3.5: Twelve V3 widget shortcut abilities

**Goal:** Match Novamira/msrbuilds parity — one ability per common widget so prompts can say `add_button` instead of `add_widget(widget_type=button)`.

**Files (one ability per widget, all identical pattern):**
- `plugin/includes/Abilities/Shortcuts/AddHeading.php`
- `plugin/includes/Abilities/Shortcuts/AddText.php`
- `plugin/includes/Abilities/Shortcuts/AddImage.php`
- `plugin/includes/Abilities/Shortcuts/AddButton.php`
- `plugin/includes/Abilities/Shortcuts/AddIcon.php`
- `plugin/includes/Abilities/Shortcuts/AddSpacer.php`
- `plugin/includes/Abilities/Shortcuts/AddDivider.php`
- `plugin/includes/Abilities/Shortcuts/AddVideo.php`
- `plugin/includes/Abilities/Shortcuts/AddIconBox.php`
- `plugin/includes/Abilities/Shortcuts/AddImageBox.php`
- `plugin/includes/Abilities/Shortcuts/AddTestimonial.php`
- `plugin/includes/Abilities/Shortcuts/AddSocialIcons.php`
- Create: `plugin/includes/Abilities/Shortcuts/ShortcutKernel.php` (shared base)
- Test: `plugin/tests/Unit/Abilities/Shortcuts/ShortcutsTest.php` (single test exercising all 12)
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Shortcuts;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Shortcuts\AddHeading;
use Stonewright\WpMcp\Abilities\Shortcuts\AddText;
use Stonewright\WpMcp\Abilities\Shortcuts\AddImage;
use Stonewright\WpMcp\Abilities\Shortcuts\AddButton;
use Stonewright\WpMcp\Abilities\Shortcuts\AddIcon;
use Stonewright\WpMcp\Abilities\Shortcuts\AddSpacer;
use Stonewright\WpMcp\Abilities\Shortcuts\AddDivider;
use Stonewright\WpMcp\Abilities\Shortcuts\AddVideo;
use Stonewright\WpMcp\Abilities\Shortcuts\AddIconBox;
use Stonewright\WpMcp\Abilities\Shortcuts\AddImageBox;
use Stonewright\WpMcp\Abilities\Shortcuts\AddTestimonial;
use Stonewright\WpMcp\Abilities\Shortcuts\AddSocialIcons;

final class ShortcutsTest extends TestCase {
    public function test_all_shortcut_names(): void {
        $expected = [
            AddHeading::class      => 'stonewright/elementor-v3-add-heading',
            AddText::class         => 'stonewright/elementor-v3-add-text',
            AddImage::class        => 'stonewright/elementor-v3-add-image',
            AddButton::class       => 'stonewright/elementor-v3-add-button',
            AddIcon::class         => 'stonewright/elementor-v3-add-icon',
            AddSpacer::class       => 'stonewright/elementor-v3-add-spacer',
            AddDivider::class      => 'stonewright/elementor-v3-add-divider',
            AddVideo::class        => 'stonewright/elementor-v3-add-video',
            AddIconBox::class      => 'stonewright/elementor-v3-add-icon-box',
            AddImageBox::class     => 'stonewright/elementor-v3-add-image-box',
            AddTestimonial::class  => 'stonewright/elementor-v3-add-testimonial',
            AddSocialIcons::class  => 'stonewright/elementor-v3-add-social-icons',
        ];
        foreach ( $expected as $class => $name ) {
            $a = new $class();
            $this->assertSame( $name, $a->name(), "expected {$name}" );
            $schema = $a->input_schema();
            $this->assertContains( 'post_id', $schema['required'] );
            $this->assertContains( 'parent_id', $schema['required'] );
        }
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement ShortcutKernel + all 12**

`ShortcutKernel.php`:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Shortcuts;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Shared base for "add <widget>" shortcuts. Subclasses declare the widget_type
 * and a per-widget settings schema. ShortcutKernel forwards to AddWidget.
 */
abstract class ShortcutKernel extends AbilityKernel {

    abstract protected function widget_type(): string;
    /** @return array<string, mixed> */
    abstract protected function widget_settings_schema(): array;
    /** @param array<string, mixed> $args */
    abstract protected function build_settings( array $args ): array;

    public function category(): string {
        return 'elementor-shortcuts';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => array_merge(
                [
                    'post_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                    'parent_id' => [ 'type' => 'string' ],
                    'position'  => [ 'type' => 'integer' ],
                ],
                $this->widget_settings_schema()
            ),
            'required' => [ 'post_id', 'parent_id' ],
        ];
    }

    public function output_schema(): array {
        return ( new AddWidget() )->output_schema();
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                return ( new AddWidget() )->execute(
                    [
                        'post_id'     => (int) $args['post_id'],
                        'parent_id'   => (string) $args['parent_id'],
                        'widget_type' => $this->widget_type(),
                        'settings'    => $this->build_settings( $args ),
                        'position'    => $args['position'] ?? null,
                    ]
                );
            }
        );
    }
}
```

Twelve concrete shortcuts follow the same shape. Sample for `AddHeading`:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Shortcuts;

final class AddHeading extends ShortcutKernel {

    public function name(): string { return 'stonewright/elementor-v3-add-heading'; }
    public function label(): string { return __( 'Add heading', 'stonewright' ); }
    public function description(): string { return __( 'Adds a heading widget.', 'stonewright' ); }

    protected function widget_type(): string { return 'heading'; }

    protected function widget_settings_schema(): array {
        return [
            'title' => [ 'type' => 'string' ],
            'level' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 6 ],
            'align' => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right' ] ],
        ];
    }

    protected function build_settings( array $args ): array {
        $s = [];
        if ( isset( $args['title'] ) ) $s['title'] = (string) $args['title'];
        if ( isset( $args['level'] ) ) $s['header_size'] = 'h' . (int) $args['level'];
        if ( isset( $args['align'] ) ) $s['align'] = (string) $args['align'];
        return $s;
    }
}
```

Repeat per widget. Widget types and their primary settings keys:
- `AddText` → widget `text-editor`, settings: `editor` (string).
- `AddImage` → `image`, settings: `image.url`, `image.alt`, `image_size`.
- `AddButton` → `button`, settings: `text`, `link.url`.
- `AddIcon` → `icon`, settings: `selected_icon` (object: `value`, `library`).
- `AddSpacer` → `spacer`, settings: `space.size`, `space.unit`.
- `AddDivider` → `divider`, settings: `style`, `weight.size`.
- `AddVideo` → `video`, settings: `youtube_url` (or `hosted_url`).
- `AddIconBox` → `icon-box`, settings: `title_text`, `description_text`, `selected_icon`.
- `AddImageBox` → `image-box`, settings: `title_text`, `description_text`, `image.url`.
- `AddTestimonial` → `testimonial`, settings: `testimonial_content`, `testimonial_name`, `testimonial_job`, `testimonial_image.url`.
- `AddSocialIcons` → `social-icons`, settings: `social_icon_list` (array).

Register all 12 in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: green; ShortcutsTest exercises all 12.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/Shortcuts/ plugin/tests/Unit/Abilities/Shortcuts/ShortcutsTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v3): add 12 widget shortcut abilities + ShortcutKernel base"
```

---

### Task 3.6: Five Pro widget shortcuts (gated)

**Files:**
- Create: `plugin/includes/Support/Requirements.php`
- Create: `plugin/includes/Abilities/Pro/AddForm.php`
- Create: `plugin/includes/Abilities/Pro/AddPosts.php`
- Create: `plugin/includes/Abilities/Pro/AddSlides.php`
- Create: `plugin/includes/Abilities/Pro/AddPricingTable.php`
- Create: `plugin/includes/Abilities/Pro/AddNavMenu.php`
- Test: `plugin/tests/Unit/Abilities/Pro/ProAbilitiesTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Pro;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Pro\AddForm;
use Stonewright\WpMcp\Abilities\Pro\AddPosts;
use Stonewright\WpMcp\Abilities\Pro\AddSlides;
use Stonewright\WpMcp\Abilities\Pro\AddPricingTable;
use Stonewright\WpMcp\Abilities\Pro\AddNavMenu;

final class ProAbilitiesTest extends TestCase {
    public function test_pro_ability_names(): void {
        $this->assertSame( 'stonewright/elementor-pro-add-form', ( new AddForm() )->name() );
        $this->assertSame( 'stonewright/elementor-pro-add-posts', ( new AddPosts() )->name() );
        $this->assertSame( 'stonewright/elementor-pro-add-slides', ( new AddSlides() )->name() );
        $this->assertSame( 'stonewright/elementor-pro-add-pricing-table', ( new AddPricingTable() )->name() );
        $this->assertSame( 'stonewright/elementor-pro-add-nav-menu', ( new AddNavMenu() )->name() );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement Requirements + 5 Pro shortcuts**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

final class Requirements {

    public static function has_elementor_pro(): bool {
        return defined( 'ELEMENTOR_PRO_VERSION' );
    }
}
```

Pro shortcuts use `ShortcutKernel` but override `permission_callback` to short-circuit when Pro is missing:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Pro;

use Stonewright\WpMcp\Abilities\Shortcuts\ShortcutKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\Requirements;

final class AddForm extends ShortcutKernel {

    public function name(): string { return 'stonewright/elementor-pro-add-form'; }
    public function label(): string { return __( 'Add Pro form', 'stonewright' ); }
    public function description(): string { return __( 'Adds an Elementor Pro Form widget. Requires Elementor Pro.', 'stonewright' ); }

    public function category(): string { return 'elementor-pro'; }

    protected function widget_type(): string { return 'form'; }

    protected function widget_settings_schema(): array {
        return [
            'form_name'      => [ 'type' => 'string' ],
            'button_text'    => [ 'type' => 'string' ],
            'success_message'=> [ 'type' => 'string' ],
        ];
    }

    protected function build_settings( array $args ): array {
        $s = [];
        if ( isset( $args['form_name'] ) )       $s['form_name'] = (string) $args['form_name'];
        if ( isset( $args['button_text'] ) )     $s['button_text'] = (string) $args['button_text'];
        if ( isset( $args['success_message'] ) ) $s['success_message'] = (string) $args['success_message'];
        return $s;
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        if ( ! Requirements::has_elementor_pro() ) {
            return new \WP_Error( 'requires_pro', __( 'This ability requires Elementor Pro.', 'stonewright' ) );
        }
        return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
    }
}
```

Repeat for `AddPosts` (`posts`), `AddSlides` (`slides`), `AddPricingTable` (`price-table`), `AddNavMenu` (`nav-menu`). Each has its own per-widget settings schema/build map.

Register in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Support/Requirements.php plugin/includes/Abilities/Pro/ plugin/tests/Unit/Abilities/Pro/ProAbilitiesTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-pro): add 5 Pro widget shortcuts (Form, Posts, Slides, PricingTable, NavMenu), gated by Requirements"
```

---

### Task 3.7: AddCustomCss + AddCustomJs (page-level)

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/AddCustomCss.php`
- Create: `plugin/includes/Abilities/ElementorV3/AddCustomJs.php`
- Test: `plugin/tests/Unit/Abilities/ElementorV3/CustomCodeTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddCustomCss;
use Stonewright\WpMcp\Abilities\ElementorV3\AddCustomJs;

final class CustomCodeTest extends TestCase {
    public function test_names(): void {
        $this->assertSame( 'stonewright/elementor-v3-add-custom-css', ( new AddCustomCss() )->name() );
        $this->assertSame( 'stonewright/elementor-v3-add-custom-js', ( new AddCustomJs() )->name() );
    }
    public function test_required(): void {
        $this->assertContains( 'css', ( new AddCustomCss() )->input_schema()['required'] );
        $this->assertContains( 'js', ( new AddCustomJs() )->input_schema()['required'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement** — both write to `_elementor_page_settings.custom_css` / `custom_js`. Sanitise CSS via `wp_strip_all_tags`; for JS, store as-is but require explicit confirmation token (so callers can't silently inject script).

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

final class AddCustomCss extends AbilityKernel {

    public function name(): string { return 'stonewright/elementor-v3-add-custom-css'; }
    public function label(): string { return __( 'Elementor: Add custom CSS', 'stonewright' ); }
    public function description(): string { return __( 'Appends CSS to a post\'s Elementor page settings.', 'stonewright' ); }
    public function category(): string { return 'elementor'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'css'     => [ 'type' => 'string' ],
                'replace' => [ 'type' => 'boolean', 'default' => false ],
            ],
            'required' => [ 'post_id', 'css' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id'     => [ 'type' => 'integer' ],
                'snapshot_id' => [ 'type' => 'string' ],
                'applied'     => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $post_id     = (int) $args['post_id'];
                $snapshot_id = Backup::snapshot_post( $post_id );
                $settings    = (array) get_post_meta( $post_id, '_elementor_page_settings', true );
                $current     = (string) ( $settings['custom_css'] ?? '' );
                $new_css     = wp_strip_all_tags( (string) $args['css'] );
                $settings['custom_css'] = ! empty( $args['replace'] )
                    ? $new_css
                    : trim( $current . "\n" . $new_css );
                $ok = update_post_meta( $post_id, '_elementor_page_settings', $settings );
                return [ 'post_id' => $post_id, 'snapshot_id' => $snapshot_id, 'applied' => (bool) $ok ];
            }
        );
    }
}
```

`AddCustomJs` requires confirmation token — use the existing `ConfirmationGate` pattern. Skip if the codebase doesn't yet have it; gate by an explicit `confirm: true` arg plus an audit-log entry.

Register both in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/AddCustomCss.php plugin/includes/Abilities/ElementorV3/AddCustomJs.php plugin/tests/Unit/Abilities/ElementorV3/CustomCodeTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v3): add AddCustomCss + AddCustomJs page-level code abilities"
```

---

### Task 3.8: SetDynamicTag

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/SetDynamicTag.php`
- Test: `plugin/tests/Unit/Abilities/ElementorV3/SetDynamicTagTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\SetDynamicTag;

final class SetDynamicTagTest extends TestCase {
    public function test_name_and_required(): void {
        $a = new SetDynamicTag();
        $this->assertSame( 'stonewright/elementor-v3-set-dynamic-tag', $a->name() );
        $required = $a->input_schema()['required'];
        $this->assertContains( 'post_id', $required );
        $this->assertContains( 'element_id', $required );
        $this->assertContains( 'setting_key', $required );
        $this->assertContains( 'tag_name', $required );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement** — writes the Elementor dynamic-tag descriptor `__dynamic__` map onto the target element's settings.

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

final class SetDynamicTag extends AbilityKernel {

    public function name(): string { return 'stonewright/elementor-v3-set-dynamic-tag'; }
    public function label(): string { return __( 'Elementor: Set dynamic tag', 'stonewright' ); }
    public function description(): string { return __( 'Binds a setting key on a widget to an Elementor dynamic tag.', 'stonewright' ); }
    public function category(): string { return 'elementor'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id'  => [ 'type' => 'string' ],
                'setting_key' => [ 'type' => 'string' ],
                'tag_name'    => [ 'type' => 'string' ],
                'tag_settings'=> [ 'type' => 'object' ],
            ],
            'required' => [ 'post_id', 'element_id', 'setting_key', 'tag_name' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id'     => [ 'type' => 'integer' ],
                'snapshot_id' => [ 'type' => 'string' ],
                'applied'     => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $post_id     = (int) $args['post_id'];
                $snapshot_id = Backup::snapshot_post( $post_id );
                $tree        = ElementorData::read( $post_id );
                $path        = ElementorData::find_path( $tree, (string) $args['element_id'] );
                if ( null === $path ) {
                    return $this->error( 'not_found', __( 'Element not found.', 'stonewright' ) );
                }
                $ref = &$tree;
                foreach ( $path as $depth => $i ) {
                    $ref = &$ref[ $i ];
                    if ( $depth !== array_key_last( $path ) && isset( $ref['elements'] ) ) {
                        $ref = &$ref['elements'];
                    }
                }
                $ref['settings'] = (array) ( $ref['settings'] ?? [] );
                $dyn             = (array) ( $ref['settings']['__dynamic__'] ?? [] );
                $payload         = sprintf(
                    '[elementor-tag id="%s" name="%s" settings="%s"]',
                    wp_generate_uuid4(),
                    (string) $args['tag_name'],
                    rawurlencode( (string) wp_json_encode( (array) ( $args['tag_settings'] ?? [] ) ) )
                );
                $dyn[ (string) $args['setting_key'] ] = $payload;
                $ref['settings']['__dynamic__'] = $dyn;
                $ok = ElementorData::write( $post_id, $tree );
                return [ 'post_id' => $post_id, 'snapshot_id' => $snapshot_id, 'applied' => (bool) $ok ];
            }
        );
    }
}
```

Register in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/SetDynamicTag.php plugin/tests/Unit/Abilities/ElementorV3/SetDynamicTagTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v3): add SetDynamicTag ability"
```

---

### Task 3.9: ExportPage + ImportTemplate

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/ExportPage.php`
- Create: `plugin/includes/Abilities/ElementorV3/ImportTemplate.php`
- Test: `plugin/tests/Unit/Abilities/ElementorV3/PortabilityTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\ExportPage;
use Stonewright\WpMcp\Abilities\ElementorV3\ImportTemplate;

final class PortabilityTest extends TestCase {
    public function test_names(): void {
        $this->assertSame( 'stonewright/elementor-v3-export-page', ( new ExportPage() )->name() );
        $this->assertSame( 'stonewright/elementor-v3-import-template', ( new ImportTemplate() )->name() );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement**

`ExportPage.php` — returns a JSON payload with `version`, `tree`, `page_settings`, `template_type`.

`ImportTemplate.php` — takes that payload + a target post id, snapshots the target, validates `version`, writes `tree` via `ElementorData::write()`, restores `page_settings`. Reject if version doesn't match.

Use `STONEWRIGHT_EXPORT_VERSION = '1.0'` constant.

Register both in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/ExportPage.php plugin/includes/Abilities/ElementorV3/ImportTemplate.php plugin/tests/Unit/Abilities/ElementorV3/PortabilityTest.php plugin/includes/Plugin.php
git commit -m "feat(elementor-v3): add ExportPage + ImportTemplate (versioned portability)"
```

---

## Phase 4: Security Hardening

### Task 4.1: AstGuard — replace regex token sweep with PHP-Parser AST allowlist

**Files:**
- Create: `plugin/includes/Sandbox/AstGuard.php`
- Test: `plugin/tests/Unit/Sandbox/AstGuardTest.php`
- Test fixtures: `plugin/tests/Unit/Sandbox/fixtures/safe-widget.php`, `plugin/tests/Unit/Sandbox/fixtures/danger-codegen.php`, `plugin/tests/Unit/Sandbox/fixtures/danger-shell.php`
- Modify: `plugin/composer.json` (add `nikic/php-parser` as require)
- Modify: `plugin/includes/Sandbox/Library.php` (call AstGuard after StaticGuard)

**Design notes:** `Sandbox/StaticGuard.php` already maintains the canonical blocklist of disallowed function names — reuse `StaticGuard::BLOCKED_TOKENS`. `AstGuard` walks the parsed tree and flags:
- `Node\Expr\FuncCall` whose name matches `StaticGuard::BLOCKED_TOKENS`
- `Node\Expr\Eval_` (dynamic-code AST node)
- `Node\Expr\ShellExec` (backtick operator)
- `Node\Expr\Include_` with a non-literal path expression
- `Node\Expr\Variable` named `GLOBALS`
- `Node\Expr\StaticCall` into reflection helpers that re-introduce dynamic call paths

Keep the dangerous-function list in `StaticGuard::BLOCKED_TOKENS` as the single source of truth; AstGuard imports it.

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Sandbox;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\AstGuard;

final class AstGuardTest extends TestCase {
    public function test_safe_source_passes(): void {
        $src = file_get_contents( __DIR__ . '/fixtures/safe-widget.php' );
        $this->assertNull( AstGuard::scan( $src ) );
    }

    public function test_dynamic_codegen_is_blocked(): void {
        $src = file_get_contents( __DIR__ . '/fixtures/danger-codegen.php' );
        $err = AstGuard::scan( $src );
        $this->assertInstanceOf( \WP_Error::class, $err );
        $this->assertSame( 'sandbox_ast_violation', $err->get_error_code() );
    }

    public function test_shell_call_is_blocked(): void {
        $src = file_get_contents( __DIR__ . '/fixtures/danger-shell.php' );
        $err = AstGuard::scan( $src );
        $this->assertInstanceOf( \WP_Error::class, $err );
    }
}
```

Fixtures (each a tiny PHP file in `tests/Unit/Sandbox/fixtures/`):

- `safe-widget.php` — a minimal class that defines a method, instantiates `WP_Query`, calls `esc_html`. Nothing dangerous.
- `danger-codegen.php` — one statement invoking the dynamic-code builtin (the PHP construct that the `Eval_` AST node represents) against a variable.
- `danger-shell.php` — one statement invoking the canonical shell-execution builtin (the PHP function `shell_exec`, which the AST exposes as `Node\Expr\FuncCall` with name `"shell_exec"`) against a variable.

Write the dangerous fixtures from inside Task 4.1 Step 3 via a Bash here-doc so they live on disk separately from the plan document. Keep each fixture to one statement.

- [ ] **Step 2: Run, expect fail**

Run: `cd plugin && vendor/bin/phpunit --filter AstGuardTest`
Expected: FAIL (class missing).

- [ ] **Step 3: Add dependency**

```bash
cd plugin && composer require nikic/php-parser:^5.0
```

- [ ] **Step 4: Implement AstGuard**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Sandbox;

use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * AST-based safety scan. Authoritative source for "what's dangerous" lives in
 * StaticGuard::BLOCKED_TOKENS — AstGuard imports that list and additionally
 * rejects whole AST node classes (dynamic-code builtin, shell exec backticks,
 * include/require of variable paths, $GLOBALS escapes).
 */
final class AstGuard {

    public static function scan( string $source ): ?\WP_Error {
        $parser = ( new ParserFactory() )->createForHostVersion();
        try {
            $ast = $parser->parse( $source );
        } catch ( ParserError $e ) {
            return new \WP_Error( 'sandbox_parse_error', $e->getMessage() );
        }
        if ( null === $ast ) {
            return new \WP_Error( 'sandbox_parse_empty', 'Empty AST.' );
        }

        $visitor   = new class extends NodeVisitorAbstract {
            /** @var array<int, string> */
            public array $violations = [];

            public function enterNode( Node $node ) {
                if ( $node instanceof Node\Expr\Eval_ ) {
                    $this->violations[] = 'dynamic_codegen';
                }
                if ( $node instanceof Node\Expr\ShellExec ) {
                    $this->violations[] = 'shell_backtick';
                }
                if ( $node instanceof Node\Expr\Include_ && ! ( $node->expr instanceof Node\Scalar\String_ ) ) {
                    $this->violations[] = 'dynamic_include';
                }
                if ( $node instanceof Node\Expr\Variable && 'GLOBALS' === $node->name ) {
                    $this->violations[] = 'globals_access';
                }
                if ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name ) {
                    $fn = (string) $node->name;
                    if ( in_array( $fn, StaticGuard::BLOCKED_TOKENS, true ) ) {
                        $this->violations[] = 'blocked_func:' . $fn;
                    }
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor( $visitor );
        $traverser->traverse( $ast );

        if ( ! empty( $visitor->violations ) ) {
            return new \WP_Error(
                'sandbox_ast_violation',
                __( 'AST guard rejected source.', 'stonewright' ),
                [ 'violations' => array_values( array_unique( $visitor->violations ) ) ]
            );
        }
        return null;
    }
}
```

- [ ] **Step 5: Wire into Library.php**

Locate `Library::write_widget()` (and any sibling writer) where `StaticGuard::scan()` is called. After the StaticGuard check, add:

```php
$ast_check = AstGuard::scan( $source );
if ( is_wp_error( $ast_check ) ) {
    return $ast_check;
}
```

- [ ] **Step 6: Run, expect pass**

Run: `cd plugin && composer test`
Expected: full suite green; AstGuardTest 3/3.

- [ ] **Step 7: Commit**

```bash
git add plugin/composer.json plugin/composer.lock plugin/includes/Sandbox/AstGuard.php plugin/tests/Unit/Sandbox/ plugin/includes/Sandbox/Library.php
git commit -m "feat(sandbox): add AstGuard (PHP-Parser AST allowlist on top of StaticGuard)"
```

---

### Task 4.2: ConfirmationGate on every V3 mutator

**Goal:** Every ability that writes to a post's Elementor data must require a valid confirmation token. Closes the silent-write gap.

**Files:**
- Modify: `plugin/includes/Abilities/ElementorV3/AddWidget.php`
- Modify: `plugin/includes/Abilities/ElementorV3/UpdateWidget.php`
- Modify: `plugin/includes/Abilities/ElementorV3/DeleteElement.php`
- Modify: `plugin/includes/Abilities/ElementorV3/MoveElement.php`
- Modify: `plugin/includes/Abilities/ElementorV3/AddContainer.php`
- Modify: every new V3 mutator from Phase 3 (DuplicateElement, BatchUpdate, ReorderChildren, AddCustomCss, AddCustomJs, SetDynamicTag, ImportTemplate)
- Modify: every Theme Builder write ability (CreateTemplate, SetConditions, DeleteTemplate)
- Verify: every Shortcuts/* ability inherits the gate via AddWidget (no separate plumbing needed)
- Create: `plugin/includes/Security/ConfirmationGate.php`
- Create: `plugin/includes/Abilities/Security/IssueConfirmation.php`
- Test: `plugin/tests/Unit/Security/ConfirmationGateCoverageTest.php`
- Modify: `plugin/includes/Plugin.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\DeleteElement;
use Stonewright\WpMcp\Abilities\ElementorV3\MoveElement;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;

final class ConfirmationGateCoverageTest extends TestCase {
    public function test_every_v3_mutator_requires_confirmation(): void {
        $mutators = [ AddWidget::class, UpdateWidget::class, DeleteElement::class, MoveElement::class, AddContainer::class ];
        foreach ( $mutators as $class ) {
            $schema = ( new $class() )->input_schema();
            $this->assertArrayHasKey( 'confirmation_token', $schema['properties'], "{$class} missing confirmation_token" );
        }
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement ConfirmationGate**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * HMAC-SHA256 confirmation tokens. Tokens encode (ability, args-hash, expiry)
 * and are single-use via a 5-minute transient-backed replay store.
 */
final class ConfirmationGate {

    private const TTL_SECONDS = 300;

    public static function issue( string $ability, array $args ): string {
        $payload = [
            'ability' => $ability,
            'args'    => self::canonical_hash( $args ),
            'exp'     => time() + self::TTL_SECONDS,
            'nonce'   => wp_generate_uuid4(),
        ];
        $body    = base64_encode( wp_json_encode( $payload ) );
        $sig     = hash_hmac( 'sha256', $body, self::secret() );
        return $body . '.' . $sig;
    }

    public static function verify( string $token, string $ability, array $args ): bool|\WP_Error {
        if ( '' === $token || false === strpos( $token, '.' ) ) {
            return new \WP_Error( 'confirmation_required', __( 'Confirmation token required for this write.', 'stonewright' ) );
        }
        [ $body, $sig ] = explode( '.', $token, 2 );
        $expected = hash_hmac( 'sha256', $body, self::secret() );
        if ( ! hash_equals( $expected, $sig ) ) {
            return new \WP_Error( 'confirmation_invalid', __( 'Invalid confirmation signature.', 'stonewright' ) );
        }
        $decoded = json_decode( (string) base64_decode( $body ), true );
        if ( ! is_array( $decoded ) ) {
            return new \WP_Error( 'confirmation_malformed', __( 'Malformed confirmation token.', 'stonewright' ) );
        }
        if ( ( $decoded['exp'] ?? 0 ) < time() ) {
            return new \WP_Error( 'confirmation_expired', __( 'Confirmation token expired.', 'stonewright' ) );
        }
        if ( ( $decoded['ability'] ?? '' ) !== $ability ) {
            return new \WP_Error( 'confirmation_mismatch_ability', __( 'Confirmation token belongs to a different ability.', 'stonewright' ) );
        }
        if ( ( $decoded['args'] ?? '' ) !== self::canonical_hash( $args ) ) {
            return new \WP_Error( 'confirmation_mismatch_args', __( 'Confirmation token does not match the supplied arguments.', 'stonewright' ) );
        }
        $nonce_key = 'stonewright_confirm_used_' . ( $decoded['nonce'] ?? '' );
        if ( get_transient( $nonce_key ) ) {
            return new \WP_Error( 'confirmation_replay', __( 'Confirmation token already used.', 'stonewright' ) );
        }
        set_transient( $nonce_key, 1, self::TTL_SECONDS );
        return true;
    }

    private static function canonical_hash( array $args ): string {
        unset( $args['confirmation_token'] );
        ksort( $args );
        return hash( 'sha256', (string) wp_json_encode( $args ) );
    }

    private static function secret(): string {
        if ( defined( 'STONEWRIGHT_CONFIRMATION_SECRET' ) ) {
            return (string) STONEWRIGHT_CONFIRMATION_SECRET;
        }
        return wp_salt( 'stonewright_confirmation' );
    }
}
```

Issue ability:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\ConfirmationGate;
use Stonewright\WpMcp\Security\Permissions;

final class IssueConfirmation extends AbilityKernel {

    public function name(): string { return 'stonewright/security-issue-confirmation'; }
    public function label(): string { return __( 'Security: Issue confirmation token', 'stonewright' ); }
    public function description(): string { return __( 'Issues a single-use HMAC token authorising one mutator call.', 'stonewright' ); }
    public function category(): string { return 'security'; }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'ability' => [ 'type' => 'string' ],
                'args'    => [ 'type' => 'object' ],
            ],
            'required' => [ 'ability', 'args' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [ 'confirmation_token' => [ 'type' => 'string' ] ],
            'required'   => [ 'confirmation_token' ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        return Permissions::manage();
    }

    public function execute( array $args ): array|\WP_Error {
        return $this->audit(
            $args,
            function ( array $args ) {
                $token = ConfirmationGate::issue( (string) $args['ability'], (array) $args['args'] );
                return [ 'confirmation_token' => $token ];
            }
        );
    }
}
```

In every mutator's `execute()`, immediately inside the `$this->audit()` callback, add:

```php
$gate = \Stonewright\WpMcp\Security\ConfirmationGate::verify(
    (string) ( $args['confirmation_token'] ?? '' ),
    $this->name(),
    $args
);
if ( is_wp_error( $gate ) ) {
    return $gate;
}
```

And add `'confirmation_token' => [ 'type' => 'string' ]` to each mutator's `input_schema()['properties']`. Update each mutator's existing test fixtures to first call `IssueConfirmation` (or build the token inline) so they keep passing.

Register `IssueConfirmation` in `Plugin.php`.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`
Expected: full suite green.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Security/ConfirmationGate.php plugin/includes/Abilities/Security/IssueConfirmation.php plugin/includes/Abilities/ plugin/tests/Unit/Security/ConfirmationGateCoverageTest.php plugin/tests/ plugin/includes/Plugin.php
git commit -m "feat(security): add ConfirmationGate + IssueConfirmation, gate all V3 + Theme Builder mutators"
```

---

### Task 4.3: SaveTemplate slug-collision backup

**Files:**
- Modify: `plugin/includes/Sandbox/Library.php`
- Test: `plugin/tests/Unit/Sandbox/LibraryCollisionTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Sandbox;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\Library;

final class LibraryCollisionTest extends TestCase {
    public function test_overwriting_existing_widget_backs_up_old_file(): void {
        $first  = Library::write_widget( 'collision-widget', "<?php // v1\n", 'v3' );
        $this->assertIsArray( $first );
        $second = Library::write_widget( 'collision-widget', "<?php // v2\n", 'v3' );
        $this->assertIsArray( $second );
        $this->assertNotEmpty( $second['backup_path'] ?? '' );
        $this->assertFileExists( $second['backup_path'] );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement** — in `Library::write_widget()`, before overwriting, if the target file already exists, rename it to `<slug>.<timestamp>.bak.php` and return the backup path in the response payload.

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Sandbox/Library.php plugin/tests/Unit/Sandbox/LibraryCollisionTest.php
git commit -m "feat(sandbox): back up existing widget file before overwrite (slug collision safety)"
```

---

### Task 4.4: Monaco editor for the Sandbox Library admin UI

**Files:**
- Create: `plugin/includes/Admin/SandboxLibraryScreen.php`
- Create: `plugin/assets/admin/sandbox-library.js`
- Modify: `plugin/includes/Plugin.php`
- Test: `plugin/tests/Unit/Admin/SandboxLibraryScreenTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SandboxLibraryScreen;

final class SandboxLibraryScreenTest extends TestCase {
    public function test_menu_slug(): void {
        $this->assertSame( 'stonewright-sandbox-library', SandboxLibraryScreen::MENU_SLUG );
    }
    public function test_capability(): void {
        $this->assertSame( 'manage_options', SandboxLibraryScreen::REQUIRED_CAP );
    }
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Implement** — admin page that lists every file under the Sandbox library directory, grouped by category (V3 widgets, V4 atomic widgets, themes, snippets). Each entry opens in a Monaco editor (loaded from a pinned CDN). Saving POSTs to the existing `stonewright/v1/sandbox/write` REST route, which already runs `StaticGuard::scan` + (after Task 4.1) `AstGuard::scan`.

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

final class SandboxLibraryScreen {

    public const MENU_SLUG    = 'stonewright-sandbox-library';
    public const REQUIRED_CAP = 'manage_options';

    public static function register(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    public static function add_menu(): void {
        add_menu_page(
            __( 'Stonewright Sandbox', 'stonewright' ),
            __( 'Stonewright', 'stonewright' ),
            self::REQUIRED_CAP,
            self::MENU_SLUG,
            [ self::class, 'render' ],
            'dashicons-editor-code',
            81
        );
    }

    public static function enqueue( string $hook ): void {
        if ( false === strpos( $hook, self::MENU_SLUG ) ) {
            return;
        }
        wp_enqueue_script(
            'stonewright-sandbox-library',
            plugins_url( 'assets/admin/sandbox-library.js', STONEWRIGHT_PLUGIN_FILE ),
            [ 'wp-api-fetch', 'wp-i18n' ],
            STONEWRIGHT_VERSION,
            true
        );
    }

    public static function render(): void {
        echo '<div id="stonewright-sandbox-library-root"></div>';
    }
}
```

JS file — uses ONLY DOM-construction APIs (no string-to-HTML interpolation, no template literals assigned to .innerHTML). Every node is built via `document.createElement` and populated with `.textContent`, then appended.

```javascript
import apiFetch from '@wordpress/api-fetch';

const MONACO_CDN = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.46.0/min/vs';

async function bootstrap() {
  const root = document.getElementById('stonewright-sandbox-library-root');
  if (!root) return;
  const list = await apiFetch({ path: '/stonewright/v1/sandbox/list' });
  renderShell(root, list);
  await loadMonaco();
}

function renderShell(root, list) {
  list.categories.forEach((cat) => {
    const section = document.createElement('section');
    section.className = 'sw-sandbox-cat';
    const heading = document.createElement('h2');
    heading.textContent = cat.label;
    section.appendChild(heading);
    const ul = document.createElement('ul');
    cat.files.forEach((file) => {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.textContent = file.name;
      btn.dataset.path = file.path;
      btn.addEventListener('click', () => openEditor(file.path));
      li.appendChild(btn);
      ul.appendChild(li);
    });
    section.appendChild(ul);
    root.appendChild(section);
  });
}

async function loadMonaco() {
  await new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = `${MONACO_CDN}/loader.js`;
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
  window.require.config({ paths: { vs: MONACO_CDN } });
  await new Promise((resolve) => window.require(['vs/editor/editor.main'], resolve));
}

async function openEditor(path) {
  const file = await apiFetch({ path: `/stonewright/v1/sandbox/read?path=${encodeURIComponent(path)}` });
  const host = document.createElement('div');
  host.className = 'sw-editor-host';
  host.style.height = '600px';
  document.body.appendChild(host);
  const editor = window.monaco.editor.create(host, { value: file.contents, language: file.language });
  const save = document.createElement('button');
  save.textContent = 'Save';
  save.addEventListener('click', async () => {
    await apiFetch({
      path: '/stonewright/v1/sandbox/write',
      method: 'POST',
      data: { path, contents: editor.getValue() },
    });
  });
  document.body.appendChild(save);
}

bootstrap();
```

Register in `Plugin.php`: `SandboxLibraryScreen::register();`

- [ ] **Step 4: Run, expect pass**

Run: `cd plugin && composer test`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Admin/SandboxLibraryScreen.php plugin/assets/admin/sandbox-library.js plugin/tests/Unit/Admin/SandboxLibraryScreenTest.php plugin/includes/Plugin.php
git commit -m "feat(admin): add Monaco-backed Sandbox Library editor screen"
```

---

## Phase 5: Final Regression + Docs

### Task 5.1: Full regression

- [ ] **Step 1: PHP tests + static analysis**

Run: `cd plugin && composer test && composer phpstan && composer phpcs`
Expected: all green; no new violations.

- [ ] **Step 2: Companion tests**

Run: `cd companion && npm test`
Expected: green.

- [ ] **Step 3: Manual smoke against a WordPress Studio site**

1. Run `stonewright/elementor-v3-add-widget` with a heading.
2. Capture a baseline with the screenshot ability, register via `ReferenceArtifacts::register()`.
3. Mutate the page, then call `stonewright/qa-verify-against-reference` — confirm pass/fail behaviour matches the diff threshold.
4. Create a header template via `stonewright/theme-builder-create-template`, set conditions to "Entire site", verify it renders on the frontend.
5. Define a custom widget via `stonewright/elementor-v3-widget-define`, place it on a page.

- [ ] **Step 4: Commit smoke notes**

```bash
git commit --allow-empty -m "chore: full-coverage regression green on plugin + companion + Studio smoke"
```

---

### Task 5.2: Docs + CHANGELOG

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `AGENTS.md`, `docs/abilities-reference.md`

- [ ] **Step 1: CHANGELOG**

Add a `## [Unreleased] — Full Coverage` section listing every new ability and behaviour from Phases 1–4. Group by category (Theme Builder, QA, V4 Atomic, Vision, Shortcuts, Security, Sandbox).

- [ ] **Step 2: README**

Add sections: "Theme Builder" (header/footer flow), "Visual regression" (verify-against-reference), "Elementor Pro" (which abilities require Pro).

- [ ] **Step 3: AGENTS.md**

Add two rules:

> When the user asks for a header, footer, or any per-template chrome, ALWAYS use `stonewright/theme-builder-create-template` + `stonewright/theme-builder-set-conditions`. NEVER stuff header/footer widgets into a page's body content.

> Before declaring a page "done", capture screenshots at the project's responsive breakpoints with `stonewright/qa-responsive-check`, and if a reference baseline is registered, run `stonewright/qa-verify-against-reference`. Treat any breakpoint with diff_ratio above 0.01 as a fail.

- [ ] **Step 4: Final test**

Run: `cd plugin && composer test`

- [ ] **Step 5: Commit**

```bash
git add README.md CHANGELOG.md AGENTS.md docs/
git commit -m "docs: document Theme Builder, visual verification, Pro gating, and Sandbox editor flows"
```

---

## Self-Review Notes

**Spec coverage:**
- Native Elementor V3 + Gutenberg → existing surface + Tasks 1.2, 3.x.
- Native Elementor V4 atomic → Tasks 2.1, 2.2, 2.3.
- Figma/image/prompt → DesignSpec → Tasks 2.4–2.6.
- Pixel-perfect responsive verification → Tasks 1.1, 1.2, 1.4, 1.5.
- Theme Builder header/footer → Tasks 1.6, 1.7.
- Custom widget creation V3 + V4 → existing WidgetDefine + Tasks 1.3, 2.3.
- Sandbox with categories, editable → Task 4.4 + hardened in 4.1.
- Security gaps → Tasks 4.1, 4.2, 4.3.
- Parity with Novamira/msrbuilds/claudeus → Tasks 3.1–3.9 (28 new abilities).

**Type consistency:**
- `Responsive::apply` signature matches its call sites in Task 1.2.
- `AtomicWidgetMap::widget_type()` / `is_container()` match `AtomicRenderer` usage in Task 2.2.
- `TemplateStore::create()` / `set_conditions()` / `get_type()` match every Theme Builder ability in Tasks 1.6 + 1.7.
- `ConfirmationGate::issue()` / `verify()` match `IssueConfirmation` and the mutator integration in Task 4.2.
- `ReferenceArtifacts::register()` / `resolve()` shape matches `VerifyAgainstReference` consumption in Task 1.5.

---

## Execution

Use `superpowers:subagent-driven-development`. Recommended model routing:
- Mechanical tasks (1.1, 1.3, 3.1–3.9 except 3.5/3.6, 4.3, 5.x) → `sonnet`.
- Integration tasks (1.2, 1.5, 2.2, 2.5, 2.6, 3.5, 3.6, 4.2) → `sonnet`.
- Architecture-heavy tasks (2.1, 2.3, 4.1) → `opus`.

Run Phase 0 → Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 strictly in order.

---


