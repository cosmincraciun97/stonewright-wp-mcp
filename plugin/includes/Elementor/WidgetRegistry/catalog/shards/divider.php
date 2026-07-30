<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'divider',
  'source' => 'free',
  'widget_type' => 'divider',
  'title' => 'Divider',
  'icon' => 'eicon-divider',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
    0 => 'divider',
    1 => 'hr',
    2 => 'line',
    3 => 'border',
  ),
  'file' => 'elementor/includes/widgets/divider.php',
  'intent' => 'The Divider Widget allows you to add styled horizontal lines that divide your content.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Style – Choose between many classic and patterned styles, such as solid, curly, wavy, tribal patterns, arrows, pluses and more.',
    1 => 'Width – Control the width of the divider as percentage from 0 to 100 percent.',
    2 => 'Alignment – Align the divider to the left, center or right of the page.',
    3 => 'Add Element – Select from None, Text, or Icon. Select Text or Icon allows you to either enter the Text to be included or select or upload an icon from the Icon Library.',
    4 => 'Color – Choose the color of the divider',
    5 => 'Size – Set the size/height of the divider, from 1 to 100, in either pixels or as a percentage',
    6 => 'Amount – Set the number of patterned elements to show',
    7 => 'Gap – Slide to set the gap above and below the divider, from 1 to 50',
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
      'id' => 'section_divider',
      'label' => 'Divider',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'style',
          'type' => 'select',
          'label' => 'Style',
          'default' => 'solid',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'separator_type',
          'type' => 'hidden',
          'label' => NULL,
          'default' => 'pattern',
          'options' => NULL,
          'condition' =>
          array (
            'style!' =>
            array (
              0 => '',
              1 => 'solid',
              2 => 'double',
              3 => 'dotted',
              4 => 'dashed',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'pattern_spacing_flag',
          'type' => 'hidden',
          'label' => NULL,
          'default' => 'no-spacing',
          'options' => NULL,
          'condition' =>
          array (
            'style' =>
            array (
              '__unresolved__' => 'array_keys()',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'pattern_round_flag',
          'type' => 'hidden',
          'label' => NULL,
          'default' => 'bg-round',
          'options' => NULL,
          'condition' =>
          array (
            'style' =>
            array (
              '__unresolved__' => 'array_keys()',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'width',
          'type' => 'slider',
          'label' => 'Width',
          'default' =>
          array (
            'size' => 100,
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
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
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'look',
          'type' => 'choose',
          'label' => 'Add Element',
          'default' => 'line',
          'options' =>
          array (
            'line' =>
            array (
              'title' => 'None',
              'icon' => 'eicon-ban',
            ),
            'line_text' =>
            array (
              'title' => 'Text',
              'icon' => 'eicon-t-letter-bold',
            ),
            'line_icon' =>
            array (
              'title' => 'Icon',
              'icon' => 'eicon-star',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'text',
          'type' => 'text',
          'label' => 'Text',
          'default' => 'Divider',
          'options' => NULL,
          'condition' =>
          array (
            'look' => 'line_text',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'html_tag',
          'type' => 'select',
          'label' => 'HTML Tag',
          'default' => 'span',
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
          'condition' =>
          array (
            'look' => 'line_text',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            'value' => 'fas fa-star',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'look' => 'line_icon',
          ),
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
      'id' => 'section_divider_style',
      'label' => 'Divider',
      'tab' => 'style',
      'condition' =>
      array (
        'style!' => 'none',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '#000',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' =>
          array (
            'size' => 1,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'style' =>
            array (
              '__unresolved__' => 'array_keys()',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'pattern_height',
          'type' => 'slider',
          'label' => 'Size',
          'default' =>
          array (
            'size' => 20,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'style!' =>
            array (
              0 => '',
              1 => 'solid',
              2 => 'double',
              3 => 'dotted',
              4 => 'dashed',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'pattern_size',
          'type' => 'slider',
          'label' => 'Amount',
          'default' =>
          array (
            'size' => 20,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'style!' =>
            array (
              '__unresolved__' => 'array_merge()',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'gap',
          'type' => 'slider',
          'label' => 'Gap',
          'default' =>
          array (
            'size' => 15,
          ),
          'options' => NULL,
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
    2 =>
    array (
      'id' => 'section_text_style',
      'label' => 'Text',
      'tab' => 'style',
      'condition' =>
      array (
        'look' => 'line_text',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'text_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'text_align',
          'type' => 'choose',
          'label' => 'Position',
          'default' => 'center',
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
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'text_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
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
          'selector' => '{{WRAPPER}} .elementor-divider__text',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-divider__text',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    3 =>
    array (
      'id' => 'section_icon_style',
      'label' => 'Icon',
      'tab' => 'style',
      'condition' =>
      array (
        'look' => 'line_icon',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon_view',
          'type' => 'select',
          'label' => 'View',
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Default',
            'stacked' => 'Stacked',
            'framed' => 'Framed',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
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
        2 =>
        array (
          'key' => 'icon_padding',
          'type' => 'slider',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'primary_color',
          'type' => 'color',
          'label' => 'Primary Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'icon_view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'icon_align',
          'type' => 'choose',
          'label' => 'Position',
          'default' => 'center',
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
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'icon_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'rotate',
          'type' => 'slider',
          'label' => 'Rotate',
          'default' =>
          array (
            'unit' => 'deg',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'icon_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_view' => 'framed',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_view!' => 'default',
          ),
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
    0 =>
    array (
      'group' => 'typography',
      'name' => 'typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-divider__text',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-divider__text',
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
    'style' =>
    array (
      'section' => 'section_divider',
      'type' => 'select',
      'default' => 'solid',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'separator_type' =>
    array (
      'section' => 'section_divider',
      'type' => 'hidden',
      'default' => 'pattern',
      'responsive' => false,
      'condition' =>
      array (
        'style!' =>
        array (
          0 => '',
          1 => 'solid',
          2 => 'double',
          3 => 'dotted',
          4 => 'dashed',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pattern_spacing_flag' =>
    array (
      'section' => 'section_divider',
      'type' => 'hidden',
      'default' => 'no-spacing',
      'responsive' => false,
      'condition' =>
      array (
        'style' =>
        array (
          '__unresolved__' => 'array_keys()',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pattern_round_flag' =>
    array (
      'section' => 'section_divider',
      'type' => 'hidden',
      'default' => 'bg-round',
      'responsive' => false,
      'condition' =>
      array (
        'style' =>
        array (
          '__unresolved__' => 'array_keys()',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'width' =>
    array (
      'section' => 'section_divider',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 100,
        'unit' => '%',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_divider',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'look' =>
    array (
      'section' => 'section_divider',
      'type' => 'choose',
      'default' => 'line',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text' =>
    array (
      'section' => 'section_divider',
      'type' => 'text',
      'default' => 'Divider',
      'responsive' => false,
      'condition' =>
      array (
        'look' => 'line_text',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'html_tag' =>
    array (
      'section' => 'section_divider',
      'type' => 'select',
      'default' => 'span',
      'responsive' => false,
      'condition' =>
      array (
        'look' => 'line_text',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon' =>
    array (
      'section' => 'section_divider',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-star',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'look' => 'line_icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color' =>
    array (
      'section' => 'section_divider_style',
      'type' => 'color',
      'default' => '#000',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'weight' =>
    array (
      'section' => 'section_divider_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'style' =>
        array (
          '__unresolved__' => 'array_keys()',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pattern_height' =>
    array (
      'section' => 'section_divider_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 20,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'style!' =>
        array (
          0 => '',
          1 => 'solid',
          2 => 'double',
          3 => 'dotted',
          4 => 'dashed',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pattern_size' =>
    array (
      'section' => 'section_divider_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 20,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'style!' =>
        array (
          '__unresolved__' => 'array_merge()',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gap' =>
    array (
      'section' => 'section_divider_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 15,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color' =>
    array (
      'section' => 'section_text_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_align' =>
    array (
      'section' => 'section_text_style',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_spacing' =>
    array (
      'section' => 'section_text_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_typography' =>
    array (
      'section' => 'section_text_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'typography',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_text_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'icon_view' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
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
    'icon_padding' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'icon_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'primary_color' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'secondary_color' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'icon_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_align' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_spacing' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rotate' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'deg',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_border_width' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'icon_view' => 'framed',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'icon_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'typography_typography' => 'custom',
    'text_stroke_text_stroke' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/divider.md',
    1 => 'docs/knowledge/elementor/widgets/divider.md',
    2 => 'docs/knowledge/elementor/widgets/divider-widget.md',
    3 => 'docs/knowledge/elementor/widgets/divider-widget.md',
  ),
  'control_count' => 30,
);
