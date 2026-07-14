<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'icon-box',
  'source' => 'free',
  'widget_type' => 'icon-box',
  'title' => 'Icon Box',
  'icon' => 'eicon-icon-box',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'icon box',
    1 => 'icon',
  ),
  'file' => 'elementor/includes/widgets/icon-box.php',
  'intent' => 'Choose the icon. Either select from Font Awesome’s entire icon library or upload SVG. View',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Icon Box widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Add an Icon Box widget – Step-by-step',
    2 => 'Primary Color – Choose icon color. When using a stacked or framed box, also select a secondary color. For more details, see Choose a color or Use global fonts and colors.Size: Resize the icon as needed.Rotate: Turn the icon to your preferred angle.',
    3 => 'Spacing – Adjust the gap between title and its description.Color: Modify the title’s color.Typography: Customize the font style of the title. For more details, see Typography.Text Stroke: Click the 🖋️ icon to apply a stroke effect to the title. Learn more about Text Stroke.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about Shadows.',
    4 => 'Color – Change the description’s color.Typography: Adjust the font style, and size for the description.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about Shadows.',
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
      'id' => 'section_icon',
      'label' => 'Icon Box',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'selected_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            'value' => 'fas fa-star',
            'library' => 'fa-solid',
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
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Default',
            'stacked' => 'Stacked',
            'framed' => 'Framed',
          ),
          'condition' =>
          array (
            'selected_icon[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'shape',
          'type' => 'select',
          'label' => 'Shape',
          'default' => 'circle',
          'options' =>
          array (
            'square' => 'Square',
            'rounded' => 'Rounded',
            'circle' => 'Circle',
          ),
          'condition' =>
          array (
            'view!' => 'default',
            'selected_icon[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'title_text',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'This is the heading',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'description_text',
          'type' => 'textarea',
          'label' => 'Description',
          'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
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
        6 =>
        array (
          'key' => 'title_size',
          'type' => 'select',
          'label' => 'Title HTML Tag',
          'default' => 'h3',
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
      'id' => 'section_style_box',
      'label' => 'Box',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'position',
          'type' => 'choose',
          'label' => 'Icon Position',
          'default' => 'block-start',
          'options' =>
          array (
            'inline-start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'inline-end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
            'block-start' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'block-end' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'selected_icon[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'content_vertical_alignment',
          'type' => 'choose',
          'label' => 'Vertical Alignment',
          'default' => 'top',
          'options' =>
          array (
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'middle' =>
            array (
              'title' => 'Middle',
              'icon' => 'eicon-v-align-middle',
            ),
            'bottom' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'selected_icon[value]!' => '',
            'position' =>
            array (
              0 => 'left',
              1 => 'right',
              2 => 'inline-start',
              3 => 'inline-end',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'text_align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
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
        3 =>
        array (
          'key' => 'icon_space',
          'type' => 'slider',
          'label' => 'Icon Spacing',
          'default' =>
          array (
            'size' => 15,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'selected_icon[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'title_bottom_space',
          'type' => 'slider',
          'label' => 'Content Spacing',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'section_style_icon',
      'label' => 'Icon',
      'tab' => 'style',
      'condition' =>
      array (
        'selected_icon[value]!' => '',
      ),
      'controls' =>
      array (
        0 =>
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
        1 =>
        array (
          'key' => 'secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'hover_primary_color',
          'type' => 'color',
          'label' => 'Primary Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'hover_secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'hover_icon_colors_transition_duration',
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
        5 =>
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
        6 =>
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
        7 =>
        array (
          'key' => 'icon_padding',
          'type' => 'slider',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
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
        9 =>
        array (
          'key' => 'border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view' => 'framed',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view!' => 'default',
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
    3 =>
    array (
      'id' => 'section_style_content',
      'label' => 'Content',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'hover_title_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'hover_title_color_transition_duration',
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
        4 =>
        array (
          'key' => 'heading_description',
          'type' => 'heading',
          'label' => 'Description',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'description_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
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
          'name' => 'title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-icon-box-title, {{WRAPPER}} .elementor-icon-box-title a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-icon-box-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-icon-box-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'description_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-icon-box-description',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'text-shadow',
          'name' => 'description_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-icon-box-description',
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
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-icon-box-title, {{WRAPPER}} .elementor-icon-box-title a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-icon-box-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-icon-box-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'description_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-icon-box-description',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'text-shadow',
      'name' => 'description_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-icon-box-description',
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
    'selected_icon' =>
    array (
      'section' => 'section_icon',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-star',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view' =>
    array (
      'section' => 'section_icon',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' =>
      array (
        'selected_icon[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shape' =>
    array (
      'section' => 'section_icon',
      'type' => 'select',
      'default' => 'circle',
      'responsive' => false,
      'condition' =>
      array (
        'view!' => 'default',
        'selected_icon[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_text' =>
    array (
      'section' => 'section_icon',
      'type' => 'text',
      'default' => 'This is the heading',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_text' =>
    array (
      'section' => 'section_icon',
      'type' => 'textarea',
      'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_icon',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_size' =>
    array (
      'section' => 'section_icon',
      'type' => 'select',
      'default' => 'h3',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'position' =>
    array (
      'section' => 'section_style_box',
      'type' => 'choose',
      'default' => 'block-start',
      'responsive' => true,
      'condition' =>
      array (
        'selected_icon[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_vertical_alignment' =>
    array (
      'section' => 'section_style_box',
      'type' => 'choose',
      'default' => 'top',
      'responsive' => true,
      'condition' =>
      array (
        'selected_icon[value]!' => '',
        'position' =>
        array (
          0 => 'left',
          1 => 'right',
          2 => 'inline-start',
          3 => 'inline-end',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_align' =>
    array (
      'section' => 'section_style_box',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_space' =>
    array (
      'section' => 'section_style_box',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 15,
      ),
      'responsive' => true,
      'condition' =>
      array (
        'selected_icon[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_bottom_space' =>
    array (
      'section' => 'section_style_box',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'primary_color' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'secondary_color' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_primary_color' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_secondary_color' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_icon_colors_transition_duration' =>
    array (
      'section' => 'section_style_icon',
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
      'section' => 'section_style_icon',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_size' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_padding' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rotate' =>
    array (
      'section' => 'section_style_icon',
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
    'border_width' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'view' => 'framed',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_title' =>
    array (
      'section' => 'section_style_content',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_style_content',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_title_color' =>
    array (
      'section' => 'section_style_content',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_title_color_transition_duration' =>
    array (
      'section' => 'section_style_content',
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
    'heading_description' =>
    array (
      'section' => 'section_style_content',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_color' =>
    array (
      'section' => 'section_style_content',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_typography_typography' =>
    array (
      'section' => 'section_style_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'title_typography',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_style_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'title_shadow_text_shadow' =>
    array (
      'section' => 'section_style_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'title_shadow',
    ),
    'description_typography_typography' =>
    array (
      'section' => 'section_style_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'description_typography',
    ),
    'description_shadow_text_shadow' =>
    array (
      'section' => 'section_style_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'description_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'title_typography_typography' => 'custom',
    'text_stroke_text_stroke' => 'yes',
    'title_shadow_text_shadow' => 'yes',
    'description_typography_typography' => 'custom',
    'description_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'title_text',
    1 => 'selected_icon',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/icon-box-widget.md',
    1 => 'docs/knowledge/elementor/widgets/icon-box-widget.md',
  ),
  'control_count' => 34,
);
