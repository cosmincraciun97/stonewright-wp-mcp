<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'heading',
  'source' => 'free',
  'widget_type' => 'heading',
  'title' => 'Heading',
  'icon' => 'eicon-t-letter',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
    0 => 'heading',
    1 => 'title',
    2 => 'text',
  ),
  'file' => 'elementor/includes/widgets/heading.php',
  'intent' => 'The Heading widget enables creation of stylized title headings throughout WordPress sites. It provides formatting controls for typography, size, color, and styling to make prominent text elements that organize page hierarchy and content structure.',
  'use_cases' =>
  array (
    0 => 'Creating page titles, section headers, or content divisions',
    1 => 'Emphasizing important text with custom font styling',
    2 => 'Establishing visual hierarchy within page layouts',
    3 => 'Building branded heading designs consistent with site identity',
    4 => 'Combining headings with other widgets for structured content sections',
    5 => 'All available widgets are displayed',
    6 => 'Click or drag the widget to the canvas',
    7 => 'For more information, see Add elements to a page',
    8 => 'What is the Heading widget',
  ),
  'settings_highlights' =>
  array (
    0 => '**HTML Tag selection** — Choose heading levels (H1-H6) for semantic structure',
    1 => '**Text content field** — Enter and edit heading copy directly',
    2 => '**Typography controls** — Modify font family, size, weight, and style',
    3 => '**Color options** — Set text color with global color integration',
    4 => '**Alignment controls** — Position heading left, center, or right',
    5 => '**Text effects** — Apply shadows, text stroke, or custom effects',
    6 => '**Responsive sizing** — Adjust heading size per device breakpoint',
    7 => '**Advanced styling** — Add padding, margins, borders, and backgrounds',
    8 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    9 => 'Interactive Call – to-Action (CTA) titles.',
    10 => 'Add a Heading widget – Step-by-step',
    11 => 'Content options – Configure general content, title, tags, and icons.',
    12 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    13 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Heading widgets display as block-level elements requiring intentional spacing management',
    1 => 'Excessive nesting of multiple heading levels may confuse page structure and accessibility',
    2 => 'Custom font sizes on mobile devices require responsive rule configuration to prevent layout breaking',
    3 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    4 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_title',
      'label' => 'Heading',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title',
          'type' => 'textarea',
          'label' => 'Title',
          'default' => 'Add Your Heading Text Here',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' =>
          array (
            'url' => '',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'size',
          'type' => 'select',
          'label' => 'Size',
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Default',
            'small' => 'Small',
            'medium' => 'Medium',
            'large' => 'Large',
            'xl' => 'XL',
            'xxl' => 'XXL',
          ),
          'condition' =>
          array (
            'size!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'header_size',
          'type' => 'select',
          'label' => 'HTML Tag',
          'default' => 'h2',
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
            'span' => 'span',
            'p' => 'p',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
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
      'id' => 'section_title_style',
      'label' => 'Heading',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => '',
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
            'justify' =>
            array (
              'title' => 'Justified',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'blend_mode',
          'type' => 'select',
          'label' => 'Blend Mode',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Normal',
            'multiply' => 'Multiply',
            'screen' => 'Screen',
            'overlay' => 'Overlay',
            'darken' => 'Darken',
            'lighten' => 'Lighten',
            'color-dodge' => 'Color Dodge',
            'saturation' => 'Saturation',
            'color' => 'Color',
            'difference' => 'Difference',
            'exclusion' => 'Exclusion',
            'hue' => 'Hue',
            'luminosity' => 'Luminosity',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'separator',
          'type' => 'divider',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'title_hover_color',
          'type' => 'color',
          'label' => 'Link Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'title_hover_color_transition_duration',
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
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-heading-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-heading-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-heading-title',
          'condition' => NULL,
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
      'selector' => '{{WRAPPER}} .elementor-heading-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-heading-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-heading-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'title' =>
    array (
      'section' => 'section_title',
      'type' => 'textarea',
      'default' => 'Add Your Heading Text Here',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_title',
      'type' => 'url',
      'default' =>
      array (
        'url' => '',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'size' =>
    array (
      'section' => 'section_title',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' =>
      array (
        'size!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'header_size' =>
    array (
      'section' => 'section_title',
      'type' => 'select',
      'default' => 'h2',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_title_style',
      'type' => 'choose',
      'default' => '',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'blend_mode' =>
    array (
      'section' => 'section_title_style',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'separator' =>
    array (
      'section' => 'section_title_style',
      'type' => 'divider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_title_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_hover_color' =>
    array (
      'section' => 'section_title_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_hover_color_transition_duration' =>
    array (
      'section' => 'section_title_style',
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
    'typography_typography' =>
    array (
      'section' => 'section_title_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'typography',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_title_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'text_shadow_text_shadow' =>
    array (
      'section' => 'section_title_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'text_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'typography_typography' => 'custom',
    'text_stroke_text_stroke' => 'yes',
    'text_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'title',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/heading.md',
    1 => 'docs/knowledge/elementor/widgets/heading.md',
    2 => 'docs/knowledge/elementor/widgets/heading-widget.md',
    3 => 'docs/knowledge/elementor/widgets/heading-widget.md',
  ),
  'control_count' => 13,
);
