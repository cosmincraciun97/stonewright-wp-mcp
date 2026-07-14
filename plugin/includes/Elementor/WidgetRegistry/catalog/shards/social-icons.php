<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'social-icons',
  'source' => 'free',
  'widget_type' => 'social-icons',
  'title' => 'Social Icons',
  'icon' => 'eicon-social-icons',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'social',
    1 => 'icon',
    2 => 'link',
  ),
  'file' => 'elementor/includes/widgets/social-icons.php',
  'intent' => 'Under the Social Icons option, click the + Add Item button or the default social icon items to open its settings.',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Social Icons widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Add a Social Icons widget – Step-by-step',
    2 => 'Icon – Select the social media icons from the Icon Library or Upload SVG. You can choose from various platforms such as Facebook, Twitter, LinkedIn, Instagram, etc.',
    3 => 'Link – Here, add the URLs of your social media profiles (e.g., Facebook, Twitter, Instagram). Input the appropriate links into the respective fields. You can also use the Dynamic Content selection to choose a link dynamically if you prefer.',
    4 => 'Color – Pick the official color for social media logos, or go for a custom color. If you choose custom, you can also select primary and secondary colors for the social icon.Repeat these steps for each social icon you want to include. Choose icons from the library, enter the corresponding link URLs, and customize as needed.',
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
      'id' => 'section_social_icon',
      'label' => 'Social Icons',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'social_icon_list',
          'type' => 'repeater',
          'label' => 'Social Icons',
          'default' =>
          array (
            0 =>
            array (
              'social_icon' =>
              array (
                'value' => 'fab fa-facebook',
                'library' => 'fa-brands',
              ),
            ),
            1 =>
            array (
              'social_icon' =>
              array (
                'value' => 'fab fa-x-twitter',
                'library' => 'fa-brands',
              ),
            ),
            2 =>
            array (
              'social_icon' =>
              array (
                'value' => 'fab fa-youtube',
                'library' => 'fa-brands',
              ),
            ),
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'shape',
          'type' => 'select',
          'label' => 'Shape',
          'default' => 'rounded',
          'options' =>
          array (
            'square' => 'Square',
            'rounded' => 'Rounded',
            'circle' => 'Circle',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'columns',
          'type' => 'select',
          'label' => 'Columns',
          'default' => '0',
          'options' =>
          array (
            0 => 'Auto',
            1 => '1',
            2 => '2',
            3 => '3',
            4 => '4',
            5 => '5',
            6 => '6',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => 'center',
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
      'id' => 'section_social_style',
      'label' => 'Icon',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon_color',
          'type' => 'select',
          'label' => 'Color',
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Official Color',
            'custom' => 'Custom',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'icon_primary_color',
          'type' => 'color',
          'label' => 'Primary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_color' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'icon_secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_color' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
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
        4 =>
        array (
          'key' => 'icon_padding',
          'type' => 'slider',
          'label' => 'Padding',
          'default' =>
          array (
            'unit' => 'em',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'icon_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' =>
          array (
            'size' => 5,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'row_gap',
          'type' => 'slider',
          'label' => 'Rows Gap',
          'default' =>
          array (
            'size' => 0,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
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
          'group' => 'border',
          'name' => 'image_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-social-icon',
          'condition' => NULL,
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
      'id' => 'section_social_hover',
      'label' => 'Icon Hover',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'hover_primary_color',
          'type' => 'color',
          'label' => 'Primary Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'icon_color' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'hover_secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'icon_color' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'image_border_border!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'hover_animation',
          'type' => 'hover_animation',
          'label' => 'Hover Animation',
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
    0 =>
    array (
      'group' => 'border',
      'name' => 'image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-social-icon',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
    0 =>
    array (
      'var' => 'repeater',
      'fields' =>
      array (
        0 =>
        array (
          'key' => 'social_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            'value' => 'fab fa-wordpress',
            'library' => 'fa-brands',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
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
            'is_external' => 'true',
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
          'key' => 'item_icon_color',
          'type' => 'select',
          'label' => 'Color',
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Official Color',
            'custom' => 'Custom',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'item_icon_primary_color',
          'type' => 'color',
          'label' => 'Primary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'item_icon_color' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'item_icon_secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'item_icon_color' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
    ),
  ),
  'settings_index' =>
  array (
    'social_icon_list' =>
    array (
      'section' => 'section_social_icon',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'social_icon' =>
          array (
            'value' => 'fab fa-facebook',
            'library' => 'fa-brands',
          ),
        ),
        1 =>
        array (
          'social_icon' =>
          array (
            'value' => 'fab fa-x-twitter',
            'library' => 'fa-brands',
          ),
        ),
        2 =>
        array (
          'social_icon' =>
          array (
            'value' => 'fab fa-youtube',
            'library' => 'fa-brands',
          ),
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shape' =>
    array (
      'section' => 'section_social_icon',
      'type' => 'select',
      'default' => 'rounded',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'columns' =>
    array (
      'section' => 'section_social_icon',
      'type' => 'select',
      'default' => '0',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_social_icon',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_color' =>
    array (
      'section' => 'section_social_style',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_primary_color' =>
    array (
      'section' => 'section_social_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'icon_color' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_secondary_color' =>
    array (
      'section' => 'section_social_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'icon_color' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_size' =>
    array (
      'section' => 'section_social_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_padding' =>
    array (
      'section' => 'section_social_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'em',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_spacing' =>
    array (
      'section' => 'section_social_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 5,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'row_gap' =>
    array (
      'section' => 'section_social_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius' =>
    array (
      'section' => 'section_social_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_border' =>
    array (
      'section' => 'section_social_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'image_border',
    ),
    'hover_primary_color' =>
    array (
      'section' => 'section_social_hover',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'icon_color' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_secondary_color' =>
    array (
      'section' => 'section_social_hover',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'icon_color' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_border_color' =>
    array (
      'section' => 'section_social_hover',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'image_border_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_animation' =>
    array (
      'section' => 'section_social_hover',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'image_border_border' => 'solid',
  ),
  'required_for_render' =>
  array (
    0 => 'social_icon_list',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/social-icons-widget.md',
    1 => 'docs/knowledge/elementor/widgets/social-icons-widget.md',
  ),
  'control_count' => 17,
);
