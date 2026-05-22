---
title: How to Create a Custom WordPress Widget with Elementor
source_url: https://elementor.com/blog/custom-wordpress-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [custom-widget]
related_widgets: []
---

## Purpose

Custom WordPress widgets extend page functionality beyond static sidebars by delivering dynamic, intent-driven components that respond to visitor context in real time. Modern widget development prioritizes performance, accessibility, and visual editor integration rather than legacy PHP registration patterns. Visitors expect dynamic, intent-driven interfaces that react to their specific needs in real time, making scalable, modular components essential for conversion optimization. The Elementor Widget API allows developers to create widgets that appear natively in the Elementor panel, expose controls through the visual editor, and render both server-side (PHP `render()`) and client-side (JavaScript `content_template()`).

## Use this when

- Building data displays that query local WordPress posts, ACF fields, or relational taxonomies without heavy plugin overhead
- Creating client-editable components where non-developers need drag-drop control via the visual editor
- Implementing personalized content based on user intent (estimated to increase conversions significantly versus static alternatives)
- Fetching real-time data from third-party APIs (weather, stock tickers, social feeds) with transient caching
- Designing components requiring custom color pickers, typography controls, or repeating field groups
- Optimizing site performance where conditional asset loading prevents unused CSS/JS from loading globally

## PHP Class Skeleton

```php
<?php
namespace Elementor_Custom_Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;

class Custom_Widget extends Widget_Base {

    public function get_name() {
        return 'custom_widget';  // Unique machine name; used as widget_type in Elementor data
    }

    public function get_title() {
        return __( 'Custom Widget', 'plugin-domain' );  // Label in the Elementor panel
    }

    public function get_icon() {
        return 'eicon-posts-grid';  // Elementor icon or Font Awesome class
    }

    public function get_categories() {
        return [ 'general', 'posts' ];  // Panel category tabs
    }

    public function get_keywords() {
        return [ 'custom', 'posts', 'grid', 'dynamic' ];  // Search keywords in the panel
    }

    public function get_script_depends() {
        return [ 'custom-widget-script' ];  // Handles registered via wp_register_script()
    }

    public function get_style_depends() {
        return [ 'custom-widget-style' ];  // Handles registered via wp_register_style()
    }

    protected function register_controls() {
        // --- Content Section ---
        $this->start_controls_section(
            'content_section',
            [
                'label' => __( 'Content', 'plugin-domain' ),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'widget_title',
            [
                'label'       => __( 'Title', 'plugin-domain' ),
                'type'        => Controls_Manager::TEXT,
                'default'     => __( 'Default Title', 'plugin-domain' ),
                'placeholder' => __( 'Enter widget title', 'plugin-domain' ),
            ]
        );

        $this->add_control(
            'post_count',
            [
                'label'   => __( 'Number of Posts', 'plugin-domain' ),
                'type'    => Controls_Manager::SLIDER,
                'default' => [ 'size' => 3 ],
                'range'   => [ 'px' => [ 'min' => 1, 'max' => 50 ] ],
            ]
        );

        $this->add_control(
            'post_category',
            [
                'label'       => __( 'Category', 'plugin-domain' ),
                'type'        => Controls_Manager::SELECT,
                'options'     => [],  // Populate dynamically: get_categories() query
                'default'     => '',
                'label_block' => true,
            ]
        );

        $this->end_controls_section();

        // --- Style Section ---
        $this->start_controls_section(
            'style_section',
            [
                'label' => __( 'Style', 'plugin-domain' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'title_color',
            [
                'label'     => __( 'Title Color', 'plugin-domain' ),
                'type'      => Controls_Manager::COLOR,
                'default'   => '#000000',
                'selectors' => [
                    '{{WRAPPER}} .widget-title' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'title_typography',
                'selector' => '{{WRAPPER}} .widget-title',
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'widget_border',
                'selector' => '{{WRAPPER}} .custom-widget',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $args = [
            'posts_per_page' => $settings['post_count']['size'],
            'post_type'      => 'post',
        ];

        if ( ! empty( $settings['post_category'] ) ) {
            $args['cat'] = $settings['post_category'];
        }

        $query = new \WP_Query( $args );

        echo '<div class="custom-widget">';
        echo '<h2 class="widget-title">' . esc_html( $settings['widget_title'] ) . '</h2>';

        if ( $query->have_posts() ) {
            echo '<ul class="posts-list">';
            while ( $query->have_posts() ) {
                $query->the_post();
                echo '<li><a href="' . esc_url( get_permalink() ) . '">';
                echo esc_html( get_the_title() );
                echo '</a></li>';
            }
            echo '</ul>';
            wp_reset_postdata();
        }

        echo '</div>';
    }

    protected function content_template() {
        // JavaScript (Backbone.js Underscore template) — renders live in the editor
        ?>
        <div class="custom-widget">
            <h2 class="widget-title" style="color: {{ settings.title_color }}">
                {{{ settings.widget_title }}}
            </h2>
            <p><?php echo esc_html__( 'Dynamic content renders on frontend', 'plugin-domain' ); ?></p>
        </div>
        <?php
    }
}
```

## Control Registration

| Control Type | Key pattern | Type constant | Notes |
|---|---|---|---|
| TEXT | `text_field` | `Controls_Manager::TEXT` | `placeholder`, `label_block` for long labels |
| TEXTAREA | `textarea_field` | `Controls_Manager::TEXTAREA` | `rows` to set visible height |
| COLOR | `color_field` | `Controls_Manager::COLOR` | pair with `selectors` for live CSS injection |
| SELECT | `dropdown_field` | `Controls_Manager::SELECT` | `options` is `['value' => 'Label']` array |
| SLIDER | `slider_field` | `Controls_Manager::SLIDER` | `range` key controls min/max/step per unit |
| SWITCHER | `toggle_field` | `Controls_Manager::SWITCHER` | returns `'yes'` or `''`; `label_on` / `label_off` |
| MEDIA | `image_field` | `Controls_Manager::MEDIA` | `media_types => ['image']`; returns `['url', 'id']` |
| ICONS | `icon_field` | `Controls_Manager::ICONS` | returns `['value', 'library']`; use `Icons_Manager::render_icon()` |
| REPEATER | `list_field` | `Controls_Manager::REPEATER` | nest `add_control()` calls; returns indexed array |

## Render Pattern

- Retrieve settings: `$settings = $this->get_settings_for_display();` (applies dynamic tag values automatically)
- Escape output: `esc_html()` for text, `esc_url()` for URLs, `esc_attr()` for attributes, `wp_kses_post()` for HTML
- Enqueue assets conditionally: register scripts/styles on `wp_enqueue_scripts` hook with `wp_register_*()`, then declare handles in `get_script_depends()` / `get_style_depends()` so Elementor loads them only when the widget is on the page
- Transient caching for API calls: `get_transient('key')` → fetch if false → `set_transient('key', $data, HOUR_IN_SECONDS)`
- `content_template()` uses Underscore.js syntax: `{{{ }}}` for unescaped HTML, `{{ }}` for escaped text, `<# #>` for logic blocks; access settings as `settings.key_name`

## Settings highlights

- `get_name()` — machine name used as the Elementor `widgetType` field in stored JSON; must be unique across all plugins
- `get_categories()` — controls which panel tab the widget appears under; `'general'` is the default free category
- `start_controls_section()` / `end_controls_section()` — groups controls under a collapsible panel section; `tab` param routes to Content or Style tabs
- `add_control()` — registers a single control; key is the settings array index at render time
- `add_group_control()` — registers a multi-property group (Typography, Border, Background, Box Shadow); emits multiple CSS rules from one UI block
- `selectors` on COLOR controls — maps `{{WRAPPER}}` (the widget's root element) to a CSS rule; live-updates in editor without page reload
- `get_script_depends()` — returns array of registered JS handles; Elementor enqueues these only on pages containing this widget
- `get_style_depends()` — same for CSS; prevents global asset bloat
- `get_settings_for_display()` — preferred over `get_settings()` in `render()`; resolves dynamic tags to their actual values
- `wp_reset_postdata()` — mandatory after any custom `WP_Query` inside `render()` to restore global `$post`

## Limits / gotchas

- Namespace conflicts: always use a vendor-specific namespace; two plugins registering `class Custom_Widget extends Widget_Base` without namespacing cause fatal PHP errors
- `WP_Query` in `render()` runs on every page load; cache results with `get_transient()` or use Elementor's `get_cached_template()` for template fragments
- `content_template()` only mirrors static settings; dynamic data (post queries, API calls) cannot be replicated in the JS preview — show a placeholder message instead
- `get_script_depends()` / `get_style_depends()` require the handles to be registered (not just enqueued) before `wp_enqueue_scripts` fires; register on `init` or `wp_enqueue_scripts` with `wp_register_*`
- Group controls like Typography emit multiple settings keys under a common prefix; access them as `$settings['typography_font_size']['size']` not `$settings['typography']['font_size']`
- PHP 8.x strict typing: avoid deprecated `register_controls()` method signature differences between Elementor versions; check the changelog before upgrading
