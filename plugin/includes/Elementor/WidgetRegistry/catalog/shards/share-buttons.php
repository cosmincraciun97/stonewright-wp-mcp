<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'share-buttons',
  'source' => 'pro',
  'widget_type' => 'share-buttons',
  'title' => 'Share Buttons',
  'icon' => 'eicon-share',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'sharing',
    1 => 'social',
    2 => 'icon',
    3 => 'button',
    4 => 'like',
  ),
  'file' => 'pro-elements/modules/share-buttons/widgets/share-buttons.php',
  'intent' => 'The Share Buttons Widget adds share buttons to any WordPress page or post. Share Buttons Widget gives you full control over your Share Buttons design & style.',
  'use_cases' =>
  array (
    0 => 'If custom is chosen, you may enter a valid URL, or use the Dynamic Tags to pull from site data or custom fields',
    1 => 'Organizing your layout design and structuring content elements inside Elementor.',
    2 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    3 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Custom Label – Enter the desired text into the field or use the Dynamic Tag options',
    1 => 'View – Choose between Icon & text, Icon only, and Text only',
    2 => 'Label – Show or Hide the Label',
    3 => 'Skin – Choose your Social Buttons Skin',
    4 => 'Shape – Choose your Social Buttons Shape',
    5 => 'Columns – Choose the number of Columns',
    6 => 'Alignment – Set the Social Buttons Alignment',
    7 => 'Target URL – Sets the Social Buttons URL you may choose between the current page or a custom one in the dropdown selector',
  ),
  'limits' =>
  array (
    0 => 'If a custom excerpt exists for a post, the Twitter share button will use the custom excerpt as the tweet’s content, followed by the post’s link.',
    1 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    2 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_buttons_content',
      'label' => 'Share Buttons',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'share_buttons',
          'type' => 'repeater',
          'label' => NULL,
          'default' =>
          array (
            0 =>
            array (
              'button' => 'facebook',
            ),
            1 =>
            array (
              'button' => 'x-twitter',
            ),
            2 =>
            array (
              'button' => 'linkedin',
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
          'key' => 'view',
          'type' => 'select',
          'label' => 'View',
          'default' => 'icon-text',
          'options' =>
          array (
            'icon-text' => 'Icon & Text',
            'icon' => 'Icon',
            'text' => 'Text',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'show_label',
          'type' => 'switcher',
          'label' => 'Label',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'view' => 'icon-text',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'skin',
          'type' => 'select',
          'label' => 'Skin',
          'default' => 'gradient',
          'options' =>
          array (
            'gradient' => 'Gradient',
            'minimal' => 'Minimal',
            'framed' => 'Framed',
            'boxed' => 'Boxed Icon',
            'flat' => 'Flat',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'shape',
          'type' => 'select',
          'label' => 'Shape',
          'default' => 'square',
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
        5 =>
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
        6 =>
        array (
          'key' => 'alignment',
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
            'justify' =>
            array (
              'title' => 'Justify',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' =>
          array (
            'columns' => '0',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'share_url_type',
          'type' => 'select',
          'label' => 'Target URL',
          'default' => 'current_page',
          'options' =>
          array (
            'current_page' => 'Current Page',
            'custom' => 'Custom',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'share_url',
          'type' => 'url',
          'label' => 'Link',
          'default' => NULL,
          'options' => false,
          'condition' =>
          array (
            'share_url_type' => 'custom',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
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
      'id' => 'section_buttons_style',
      'label' => 'Share Buttons',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'column_gap',
          'type' => 'slider',
          'label' => 'Columns Gap',
          'default' =>
          array (
            'size' => 10,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'row_gap',
          'type' => 'slider',
          'label' => 'Rows Gap',
          'default' =>
          array (
            'size' => 10,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'button_size',
          'type' => 'slider',
          'label' => 'Button Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'icon_size',
          'type' => 'slider',
          'label' => 'Icon Size',
          'default' =>
          array (
            'unit' => 'em',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'view!' => 'text',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'button_height',
          'type' => 'slider',
          'label' => 'Button Height',
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
          'key' => 'border_size',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' =>
          array (
            'size' => 2,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'skin' =>
            array (
              0 => 'framed',
              1 => 'boxed',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'color_source',
          'type' => 'select',
          'label' => 'Color',
          'default' => 'official',
          'options' =>
          array (
            'official' => 'Official',
            'custom' => 'Custom',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
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
        8 =>
        array (
          'key' => 'secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'skin!' => 'framed',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'primary_color_hover',
          'type' => 'color',
          'label' => 'Primary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'secondary_color_hover',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'text_padding',
          'type' => 'dimensions',
          'label' => 'Text Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view' => 'text',
          ),
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
          'selector' => '{{WRAPPER}} .elementor-share-btn__title',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'line_height',
          ),
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
      'selector' => '{{WRAPPER}} .elementor-share-btn__title',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'line_height',
      ),
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
          'key' => 'button',
          'type' => 'select',
          'label' => 'Network',
          'default' => 'facebook',
          'options' =>
          array (
            '__unresolved__' => 'array_reduce()',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'text',
          'type' => 'text',
          'label' => 'Custom Label',
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
      ),
    ),
  ),
  'settings_index' =>
  array (
    'share_buttons' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'button' => 'facebook',
        ),
        1 =>
        array (
          'button' => 'x-twitter',
        ),
        2 =>
        array (
          'button' => 'linkedin',
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'select',
      'default' => 'icon-text',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_label' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'view' => 'icon-text',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'skin' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'select',
      'default' => 'gradient',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shape' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'select',
      'default' => 'square',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'columns' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'select',
      'default' => '0',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alignment' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'columns' => '0',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'share_url_type' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'select',
      'default' => 'current_page',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'share_url' =>
    array (
      'section' => 'section_buttons_content',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'share_url_type' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'column_gap' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 10,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'row_gap' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 10,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_size' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_size' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'em',
      ),
      'responsive' => true,
      'condition' =>
      array (
        'view!' => 'text',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_height' =>
    array (
      'section' => 'section_buttons_style',
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
    'border_size' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 2,
      ),
      'responsive' => true,
      'condition' =>
      array (
        'skin' =>
        array (
          0 => 'framed',
          1 => 'boxed',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_source' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'select',
      'default' => 'official',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'primary_color' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'secondary_color' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'skin!' => 'framed',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'primary_color_hover' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'secondary_color_hover' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_padding' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view' => 'text',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_typography' =>
    array (
      'section' => 'section_buttons_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'typography',
    ),
  ),
  'group_activators' =>
  array (
    'typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/share-buttons-widget-pro.md',
    1 => 'docs/knowledge/elementor/widgets/share-buttons-widget-pro.md',
  ),
  'control_count' => 22,
);
