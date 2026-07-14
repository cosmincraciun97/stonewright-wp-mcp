<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'button',
  'source' => 'free',
  'widget_type' => 'button',
  'title' => 'Button',
  'icon' => 'eicon-button',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
  ),
  'file' => 'elementor/includes/widgets/button.php',
  'intent' => 'The Button Widget allows designers to create customizable, clickable buttons without requiring additional plugins or shortcodes. This versatile element functions as a core interaction component for directing user actions toward specific destinations or triggering defined behaviors on web pages.',
  'use_cases' =>
  array (
    0 => 'Adding call-to-action elements that guide visitors toward conversions',
    1 => 'Creating navigation links with enhanced visual styling and interactivity',
    2 => 'Building form submission triggers or download prompts',
    3 => 'Designing multi-button layouts for product selections or feature highlights',
    4 => 'Establishing consistent clickable elements throughout page designs',
    5 => 'All available widgets are displayed',
    6 => 'Click or drag the widget to the canvas',
    7 => 'For more information, see Add elements to a page',
    8 => 'What is the Button widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'Link URL configuration for external sites, internal pages, or anchor links',
    1 => 'Button text customization with full typography controls (font, size, weight, color)',
    2 => 'Background color, gradient, and hover state styling options',
    3 => 'Padding and margin adjustments for spacing control',
    4 => 'Border radius settings for rounded or sharp corner styles',
    5 => 'Icon placement options (before/after text or standalone)',
    6 => 'Size presets (small, medium, large) or custom dimensions',
    7 => 'Advanced styling including shadow, opacity, and transform effects',
    8 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    9 => 'Add a Button widget – Step-by-step',
    10 => 'Choose from five button styles – Default, Info, Success, Warning, or Danger.',
    11 => 'Content options – Configure general content, title, tags, and icons.',
    12 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    13 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Button styling is limited to predefined widget settings; complex custom layouts require custom CSS',
    1 => 'Icon display depends on proper icon library integration and selection',
    2 => 'Link behavior respects standard HTML anchor attributes; dynamic redirects need form actions configured separately',
    3 => 'Choose from five button styles: Default, Info, Success, Warning, or Danger.',
    4 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    5 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_button',
      'label' => 'Button',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'button_type',
          'type' => 'select',
          'label' => 'Type',
          'default' => '',
          'options' =>
          array (
            '' => 'Default',
            'info' => 'Info',
            'success' => 'Success',
            'warning' => 'Warning',
            'danger' => 'Danger',
          ),
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'text',
          'type' => 'text',
          'label' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'default' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' =>
          array (
            'url' => '#',
          ),
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'size',
          'type' => 'select',
          'label' => 'Size',
          'default' => 'sm',
          'options' =>
          array (
            '__unresolved__' => 'self::get_button_sizes()',
          ),
          'condition' =>
          array (
            '__unresolved__' => 'array_merge()',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'selected_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'icon_align',
          'type' => 'choose',
          'label' => 'Icon Position',
          'default' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
          ),
          'options' =>
          array (
            'row' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'row-reverse' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            '__unresolved__' => 'array_merge()',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'icon_indent',
          'type' => 'slider',
          'label' => 'Icon Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'array_merge()',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'button_css_id',
          'type' => 'text',
          'label' => 'Button ID',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' =>
          array (
            '__unresolved__' => 'sprintf()',
          ),
        ),
      ),
      'group_controls' =>
      array (
      ),
      'repeaters' =>
      array (
      ),
    ),
    1 =>
    array (
      'id' => 'section_style',
      'label' => 'Button',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Position',
          'default' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-h-align-center',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
            'justify' =>
            array (
              'title' => 'Stretch',
              'icon' => 'eicon-h-align-stretch',
            ),
          ),
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'content_align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-text-align-right',
            ),
            'space-between' =>
            array (
              'title' => 'Space between',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' =>
          array (
            '__unresolved__' => 'array_merge()',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'button_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'hover_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'button_hover_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 's',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'hover_animation',
          'type' => 'hover_animation',
          'label' => 'Hover Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'text_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button',
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button',
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'background',
          'name' => 'background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button',
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'button_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button',
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'background',
          'name' => 'button_background_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button:hover, {{WRAPPER}} .elementor-button:focus',
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'box-shadow',
          'name' => 'button_hover_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button:hover, {{WRAPPER}} .elementor-button:focus',
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'border',
          'name' => 'border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button',
          'condition' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
  ),
  'group_controls' =>
  array (
    0 =>
    array (
      'group' => 'typography',
      'name' => 'typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button',
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-shadow',
      'name' => 'text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button',
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'background',
      'name' => 'background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button',
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'box-shadow',
      'name' => 'button_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button',
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'background',
      'name' => 'button_background_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button:hover, {{WRAPPER}} .elementor-button:focus',
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'box-shadow',
      'name' => 'button_hover_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button:hover, {{WRAPPER}} .elementor-button:focus',
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'border',
      'name' => 'border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button',
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'button_type' =>
    array (
      'section' => 'section_button',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text' =>
    array (
      'section' => 'section_button',
      'type' => 'text',
      'default' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_button',
      'type' => 'url',
      'default' =>
      array (
        'url' => '#',
      ),
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'size' =>
    array (
      'section' => 'section_button',
      'type' => 'select',
      'default' => 'sm',
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'array_merge()',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'selected_icon' =>
    array (
      'section' => 'section_button',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_align' =>
    array (
      'section' => 'section_button',
      'type' => 'choose',
      'default' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
      ),
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'array_merge()',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_indent' =>
    array (
      'section' => 'section_button',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'array_merge()',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_css_id' =>
    array (
      'section' => 'section_button',
      'type' => 'text',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_style',
      'type' => 'choose',
      'default' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'responsive' => true,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_align' =>
    array (
      'section' => 'section_style',
      'type' => 'choose',
      'default' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'responsive' => true,
      'condition' =>
      array (
        '__unresolved__' => 'array_merge()',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color' =>
    array (
      'section' => 'section_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_color' =>
    array (
      'section' => 'section_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_border_color' =>
    array (
      'section' => 'section_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_transition_duration' =>
    array (
      'section' => 'section_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 's',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_animation' =>
    array (
      'section' => 'section_style',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius' =>
    array (
      'section' => 'section_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_padding' =>
    array (
      'section' => 'section_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_typography' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => 'typography',
      'group_prefix' => 'typography',
    ),
    'text_shadow_text_shadow' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => 'text-shadow',
      'group_prefix' => 'text_shadow',
    ),
    'background_background' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => 'background',
      'group_prefix' => 'background',
    ),
    'button_box_shadow_box_shadow' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => 'box-shadow',
      'group_prefix' => 'button_box_shadow',
    ),
    'button_background_hover_background' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => 'background',
      'group_prefix' => 'button_background_hover',
    ),
    'button_hover_box_shadow_box_shadow' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => 'box-shadow',
      'group_prefix' => 'button_hover_box_shadow',
    ),
    'border_border' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
      ),
      'group' => 'border',
      'group_prefix' => 'border',
    ),
  ),
  'group_activators' =>
  array (
    'typography_typography' => 'custom',
    'text_shadow_text_shadow' => 'yes',
    'background_background' => 'classic',
    'button_box_shadow_box_shadow' => 'yes',
    'button_background_hover_background' => 'classic',
    'button_hover_box_shadow_box_shadow' => 'yes',
    'border_border' => 'solid',
  ),
  'required_for_render' =>
  array (
    0 => 'text',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/button.md',
    1 => 'docs/knowledge/elementor/widgets/button.md',
    2 => 'docs/knowledge/elementor/widgets/button-widget.md',
    3 => 'docs/knowledge/elementor/widgets/button-widget.md',
  ),
  'control_count' => 24,
);
