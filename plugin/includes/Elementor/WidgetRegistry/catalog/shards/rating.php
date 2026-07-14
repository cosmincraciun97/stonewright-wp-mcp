<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'rating',
  'source' => 'free',
  'widget_type' => 'rating',
  'title' => 'Rating',
  'icon' => 'eicon-rating',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'star',
    1 => 'rating',
    2 => 'review',
    3 => 'score',
    4 => 'scale',
  ),
  'file' => 'elementor/includes/widgets/rating.php',
  'intent' => 'In the Rating field, enter the number of rating icons you want to appear. This can be either whole numbers or halves, such as a rating of 3.5.',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Rating widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Add a Rating widget – Step-by-Step',
    2 => 'Content options – Configure general content, title, tags, and icons.',
    3 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    4 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_rating',
      'label' => 'Rating',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'rating_scale',
          'type' => 'slider',
          'label' => 'Rating Scale',
          'default' =>
          array (
            'size' => 5,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'rating_value',
          'type' => 'number',
          'label' => 'Rating',
          'default' => NULL,
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
          'key' => 'rating_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            'value' => 'eicon-star',
            'library' => 'eicons',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'icon_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-align-center-h',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
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
      'id' => 'section_icon_style',
      'label' => 'Icon',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'icon_gap',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'icon_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'icon_unmarked_color',
          'type' => 'color',
          'label' => 'Unmarked Color',
          'default' => NULL,
          'options' => NULL,
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
  ),
  'group_controls' =>
  array (
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'rating_scale' =>
    array (
      'section' => 'section_rating',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 5,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rating_value' =>
    array (
      'section' => 'section_rating',
      'type' => 'number',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rating_icon' =>
    array (
      'section' => 'section_rating',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'eicon-star',
        'library' => 'eicons',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_alignment' =>
    array (
      'section' => 'section_rating',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_size' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_gap' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_color' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_unmarked_color' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/rating-widget.md',
    1 => 'docs/knowledge/elementor/widgets/rating-widget.md',
  ),
  'control_count' => 8,
);
