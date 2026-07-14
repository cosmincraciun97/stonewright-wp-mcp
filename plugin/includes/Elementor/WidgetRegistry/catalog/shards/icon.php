<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'icon',
  'source' => 'free',
  'widget_type' => 'icon',
  'title' => 'Icon',
  'icon' => 'eicon-favorite',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
    0 => 'icon',
  ),
  'file' => 'elementor/includes/widgets/icon.php',
  'intent' => 'The Icon widget is useful for displaying FontAwesome icons in numerous styles on your page.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
    3 => 'All available widgets are displayed',
    4 => 'Click or drag the widget to the canvas',
    5 => 'For more information, see Add elements to a page',
    6 => 'What is the Icon Box widget',
    7 => 'How To Use The Font Awesome Pro Icons',
    8 => 'The first time you attempt to use the Icon Library, you may be presented with a Font Awesome Migration popup',
  ),
  'settings_highlights' =>
  array (
    0 => 'Icon – Choose from a list of Font Awesome icons',
    1 => 'View – Choose between default, stacked or framed',
    2 => 'Link – Enter the URL for the item’s link. Click the Link Options cog to either add rel=nofollow to the link or to open the link in a new window.',
    3 => 'Alignment – Align the icon to left, center or right.',
    4 => 'Primary – Choose the main and secondary colors for the icon',
    5 => 'Size – Increase or decrease the size of the icon',
    6 => 'Rotate – Rotate the icon',
    7 => 'Primary Color – Set colors for the hover',
    8 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    9 => 'Add an Icon Box widget – Step-by-step',
    10 => 'Primary Color – Choose icon color. When using a stacked or framed box, also select a secondary color. For more details, see Choose a color or Use global fonts and colors.Size: Resize the icon as needed.Rotate: Turn the icon to your preferred angle.',
    11 => 'Spacing – Adjust the gap between title and its description.Color: Modify the title’s color.Typography: Customize the font style of the title. For more details, see Typography.Text Stroke: Click the 🖋️ icon to apply a stroke effect to the title. Learn more about Text Stroke.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about Shadows.',
    12 => 'Color – Change the description’s color.Typography: Adjust the font style, and size for the description.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about Shadows.',
    13 => 'Widgets with inline controls – Click the + and – icons to choose icons from the icon Library or click the Upload icons to upload an SVG.',
    14 => 'Filter Icons By Name – Search by keyword in the search box above the icons in the right panel.',
    15 => 'Filter Icons By Families – Select the icon family in the left panel, choosing from All, Regular, Solid, or Brands',
    16 => 'Content options – Configure general content, title, tags, and icons.',
    17 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    18 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
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
      'label' => 'Icon',
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
          'condition' => NULL,
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
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
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
      'id' => 'section_style_icon',
      'label' => 'Icon',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => 'center',
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
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
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
        2 =>
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
        3 =>
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
        4 =>
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
          'key' => 'size',
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
          'key' => 'fit_to_size',
          'type' => 'switcher',
          'label' => 'Fit to Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'selected_icon[library]' => 'svg',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Avoid gaps around icons when width and height aren\'t equal',
        ),
        8 =>
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
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
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
        10 =>
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
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
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
  ),
  'group_controls' =>
  array (
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
      'condition' => NULL,
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
      ),
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
    'align' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'choose',
      'default' => 'center',
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
    'size' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'fit_to_size' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'selected_icon[library]' => 'svg',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_padding' =>
    array (
      'section' => 'section_style_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
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
      'responsive' => false,
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
  ),
  'group_activators' =>
  array (
  ),
  'required_for_render' =>
  array (
    0 => 'selected_icon',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/icon-widget.md',
    1 => 'docs/knowledge/elementor/widgets/icon-widget.md',
    2 => 'docs/knowledge/elementor/widgets/icon-box-widget.md',
    3 => 'docs/knowledge/elementor/widgets/icon-library.md',
  ),
  'control_count' => 16,
);
