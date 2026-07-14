<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'progress-tracker',
  'source' => 'pro',
  'widget_type' => 'progress-tracker',
  'title' => 'Progress Tracker',
  'icon' => 'eicon-progress-tracker',
  'categories' =>
  array (
    0 => 'pro-elements',
    1 => 'theme-elements-single',
  ),
  'keywords' =>
  array (
    0 => 'progress',
    1 => 'tracker',
    2 => 'read',
    3 => 'scroll',
  ),
  'file' => 'pro-elements/modules/progress-tracker/widgets/progress-tracker.php',
  'intent' => 'Encourage users to engage with your content and to continue to keep reading as they’ll know exactly how much is left. Full style customization is available for the Progress Indicator and Tracker Background.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Tracker Type – From the dropdown menu select between Horizontal, or Circular',
    1 => 'Progress Relative To – Select between Entire Page, Post Content, or Selector from the dropdown menu',
    2 => 'Direction – Select the appropriate alignment icon',
    3 => 'Percentage – Use the toggle to choose to hide/show the percentage text of the progress',
    4 => 'Size – Use the slider control or manually enter the value desired',
    5 => 'Color – Use the color picker to set the progress indicator color',
    6 => 'Width – Use the slider control or manually enter the width for the progress indicator (PX)',
    7 => 'Alignment – Use the appropriate icon to set the alignment of the progress indicator',
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
      'id' => 'section_content_scrolling_tracker',
      'label' => 'Progress Tracker',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'type',
          'type' => 'select',
          'label' => 'Tracker Type',
          'default' => 'horizontal',
          'options' =>
          array (
            'horizontal' => 'Horizontal',
            'circular' => 'Circular',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'relative_to',
          'type' => 'select',
          'label' => 'Progress relative to',
          'default' => 'entire_page',
          'options' =>
          array (
            'entire_page' => 'Entire Page',
            'post_content' => 'Post Content',
            'selector' => 'Selector',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'selector',
          'type' => 'text',
          'label' => 'Selector',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relative_to' => 'selector',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Add the CSS ID or Class of a specific element on this page to track its progress separately',
        ),
        3 =>
        array (
          'key' => 'relative_to_description',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relative_to' => 'post_content',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'direction',
          'type' => 'choose',
          'label' => 'Direction',
          'default' => NULL,
          'options' =>
          array (
            'ltr' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'rtl' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'percentage',
          'type' => 'switcher',
          'label' => 'Percentage',
          'default' => 'no',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'percentage_position',
          'type' => 'choose',
          'label' => 'Percentage Position',
          'default' => NULL,
          'options' =>
          array (
            'rtl' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'ltr' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'type' => 'horizontal',
            'percentage' => 'yes',
          ),
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
      'id' => 'section_style_scrolling_tracker',
      'label' => 'Tracker',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'circular_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'circular',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'heading_progress_style',
          'type' => 'heading',
          'label' => 'Progress Indicator',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'circular_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'circular',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'circular_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'circular',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-text-align-right',
            ),
          ),
          'condition' =>
          array (
            'type' => 'circular',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'horizontal_border_style',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => 'none',
          'options' =>
          array (
            'none' => 'None',
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'groove' => 'Groove',
          ),
          'condition' =>
          array (
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'horizontal_border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'horizontal_border_style!' => 'none',
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'horizontal_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'horizontal_border_style!' => 'none',
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'horizontal_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'heading_tracker_background_style',
          'type' => 'heading',
          'label' => 'Tracker Background',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'circular_background_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'circular',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'circular_background_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'circular',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'horizontal_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'horizontal_tracker_border_style',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => 'none',
          'options' =>
          array (
            'none' => 'None',
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'groove' => 'Groove',
          ),
          'condition' =>
          array (
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'horizontal_tracker_border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'horizontal_tracker_border_style!' => 'none',
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'horizontal_tracker_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'horizontal_tracker_border_style!' => 'none',
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'horizontal_tracker_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'horizontal_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'horizontal',
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
          'group' => 'background',
          'name' => 'horizontal_color',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .current-progress',
          'condition' =>
          array (
            'type' => 'horizontal',
          ),
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' => 'horizontal_background_color',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-scrolling-tracker-horizontal',
          'condition' =>
          array (
            'type' => 'horizontal',
          ),
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'box-shadow',
          'name' => 'box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-scrolling-tracker',
          'condition' =>
          array (
            'type' => 'horizontal',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'section__content_style_scrolling_tracker',
      'label' => 'Content',
      'tab' => 'style',
      'condition' =>
      array (
        'percentage' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_percentage_style',
          'type' => 'heading',
          'label' => 'Percentage',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'percentage_color',
          'type' => 'color',
          'label' => 'Color',
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
        0 =>
        array (
          'group' => 'typography',
          'name' => 'percentage_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .current-progress-percentage',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'percentage_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .current-progress-percentage',
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
      'group' => 'background',
      'name' => 'horizontal_color',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .current-progress',
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'background',
      'name' => 'horizontal_background_color',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-scrolling-tracker-horizontal',
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'box-shadow',
      'name' => 'box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-scrolling-tracker',
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'percentage_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .current-progress-percentage',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'text-shadow',
      'name' => 'percentage_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .current-progress-percentage',
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
    'type' =>
    array (
      'section' => 'section_content_scrolling_tracker',
      'type' => 'select',
      'default' => 'horizontal',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'relative_to' =>
    array (
      'section' => 'section_content_scrolling_tracker',
      'type' => 'select',
      'default' => 'entire_page',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'selector' =>
    array (
      'section' => 'section_content_scrolling_tracker',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relative_to' => 'selector',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'relative_to_description' =>
    array (
      'section' => 'section_content_scrolling_tracker',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relative_to' => 'post_content',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'direction' =>
    array (
      'section' => 'section_content_scrolling_tracker',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'percentage' =>
    array (
      'section' => 'section_content_scrolling_tracker',
      'type' => 'switcher',
      'default' => 'no',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'percentage_position' =>
    array (
      'section' => 'section_content_scrolling_tracker',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'horizontal',
        'percentage' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'circular_size' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'circular',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_progress_style' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'circular_color' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'circular',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'circular_width' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'circular',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'circular',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_border_style' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_border_width' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'horizontal_border_style!' => 'none',
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_border_color' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'horizontal_border_style!' => 'none',
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_border_radius' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_tracker_background_style' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'circular_background_color' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'circular',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'circular_background_width' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'circular',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_height' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_tracker_border_style' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_tracker_border_width' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'horizontal_tracker_border_style!' => 'none',
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_tracker_border_color' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'horizontal_tracker_border_style!' => 'none',
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_tracker_border_radius' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_padding' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_color_background' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => 'background',
      'group_prefix' => 'horizontal_color',
    ),
    'horizontal_background_color_background' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => 'background',
      'group_prefix' => 'horizontal_background_color',
    ),
    'box_shadow_box_shadow' =>
    array (
      'section' => 'section_style_scrolling_tracker',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => 'box-shadow',
      'group_prefix' => 'box_shadow',
    ),
    'heading_percentage_style' =>
    array (
      'section' => 'section__content_style_scrolling_tracker',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'percentage_color' =>
    array (
      'section' => 'section__content_style_scrolling_tracker',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'percentage_typography_typography' =>
    array (
      'section' => 'section__content_style_scrolling_tracker',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'percentage_typography',
    ),
    'percentage_text_shadow_text_shadow' =>
    array (
      'section' => 'section__content_style_scrolling_tracker',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'percentage_text_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'horizontal_color_background' => 'classic',
    'horizontal_background_color_background' => 'classic',
    'box_shadow_box_shadow' => 'yes',
    'percentage_typography_typography' => 'custom',
    'percentage_text_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/progress-tracker.md',
    1 => 'docs/knowledge/elementor/widgets/progress-tracker.md',
  ),
  'control_count' => 32,
);
