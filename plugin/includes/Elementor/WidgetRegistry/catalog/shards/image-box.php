<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'image-box',
  'source' => 'free',
  'widget_type' => 'image-box',
  'title' => 'Image Box',
  'icon' => 'eicon-image-box',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'image',
    1 => 'photo',
    2 => 'visual',
    3 => 'box',
  ),
  'file' => 'elementor/includes/widgets/image-box.php',
  'intent' => 'Click the default image to select an image from the media library or upload a new one. Image Resolution',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Image Box widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Add an Image Box widget – Step-by-step',
    2 => 'Adjust image size – thumbnail, full, or your custom size.Does not appear if no image is chosen.',
    3 => 'Spacing – Use the slider to adjust the gap between the title and the description.Color: Define the color of the title.Typography: Choose the font and adjust the size of the title. For more details, see Typography.Text Stroke: Click the 🖋️ icon to apply a stroke effect to the title. Learn more about Text Stroke.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about Shadows.',
    4 => 'Color – Change the color of the description text.',
    5 => 'Typography – Customize the font and adjust the size for the description.',
    6 => 'Text Shadow – Click on the 🖋️icon to apply a shadow effect to the description. For more details see, Shadows.',
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
      'id' => 'section_image',
      'label' => 'Image Box',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'image',
          'type' => 'media',
          'label' => 'Choose Image',
          'default' =>
          array (
            'url' =>
            array (
              '__unresolved__' => 'Utils::get_placeholder_image_src()',
            ),
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
        1 =>
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
        2 =>
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
        4 =>
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
        0 =>
        array (
          'group' => 'image-size',
          'name' => 'thumbnail',
          'label' => NULL,
          'selector' => NULL,
          'condition' =>
          array (
            'image[url]!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
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
          'label' => 'Image Position',
          'default' => 'top',
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'image[url]!' => '',
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
            'position!' => 'top',
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
          'key' => 'image_space',
          'type' => 'slider',
          'label' => 'Image Spacing',
          'default' =>
          array (
            'size' => 15,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'image[url]!' => '',
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
      'id' => 'section_style_image',
      'label' => 'Image',
      'tab' => 'style',
      'condition' =>
      array (
        'image[url]!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'image_size',
          'type' => 'slider',
          'label' => 'Width',
          'default' =>
          array (
            'size' => 30,
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'image_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'image_object_fit',
          'type' => 'select',
          'label' => 'Object Fit',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Default',
            'fill' => 'Fill',
            'cover' => 'Cover',
            'contain' => 'Contain',
            'scale-down' => 'Scale Down',
          ),
          'condition' =>
          array (
            'image_height[size]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'image_object_position',
          'type' => 'select',
          'label' => 'Object Position',
          'default' => 'center center',
          'options' =>
          array (
            'center center' => 'Center Center',
            'center left' => 'Center Left',
            'center right' => 'Center Right',
            'top center' => 'Top Center',
            'top left' => 'Top Left',
            'top right' => 'Top Right',
            'bottom center' => 'Bottom Center',
            'bottom left' => 'Bottom Left',
            'bottom right' => 'Bottom Right',
          ),
          'condition' =>
          array (
            'image_height[size]!' => '',
            'image_object_fit' =>
            array (
              0 => 'cover',
              1 => 'contain',
              2 => 'scale-down',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'image_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'separator_panel_style',
          'type' => 'divider',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'image_opacity',
          'type' => 'slider',
          'label' => 'Opacity',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'image_opacity_hover',
          'type' => 'slider',
          'label' => 'Opacity',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'background_hover_transition',
          'type' => 'slider',
          'label' => 'Transition Duration (s)',
          'default' =>
          array (
            'size' => 0.3,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
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
        0 =>
        array (
          'group' => 'border',
          'name' => 'image_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-box-img img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'box-shadow',
          'name' => 'image_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-box-img img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'css-filter',
          'name' => 'css_filters',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-box-img img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'css-filter',
          'name' => 'css_filters_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}}:hover .elementor-image-box-img img',
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
          'selector' => '{{WRAPPER}} .elementor-image-box-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'title_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-box-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-box-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'description_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-box-description',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'text-shadow',
          'name' => 'description_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-box-description',
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
      'group' => 'image-size',
      'name' => 'thumbnail',
      'label' => NULL,
      'selector' => NULL,
      'condition' =>
      array (
        'image[url]!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'border',
      'name' => 'image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-box-img img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'box-shadow',
      'name' => 'image_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-box-img img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'css-filter',
      'name' => 'css_filters',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-box-img img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'css-filter',
      'name' => 'css_filters_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}}:hover .elementor-image-box-img img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-box-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'text-stroke',
      'name' => 'title_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-box-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-box-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'typography',
      'name' => 'description_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-box-description',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'text-shadow',
      'name' => 'description_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-box-description',
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
    'image' =>
    array (
      'section' => 'section_image',
      'type' => 'media',
      'default' =>
      array (
        'url' =>
        array (
          '__unresolved__' => 'Utils::get_placeholder_image_src()',
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_text' =>
    array (
      'section' => 'section_image',
      'type' => 'text',
      'default' => 'This is the heading',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_text' =>
    array (
      'section' => 'section_image',
      'type' => 'textarea',
      'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_image',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_size' =>
    array (
      'section' => 'section_image',
      'type' => 'select',
      'default' => 'h3',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'thumbnail_image_size' =>
    array (
      'section' => 'section_image',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'image[url]!' => '',
      ),
      'group' => 'image-size',
      'group_prefix' => 'thumbnail',
    ),
    'position' =>
    array (
      'section' => 'section_style_box',
      'type' => 'choose',
      'default' => 'top',
      'responsive' => true,
      'condition' =>
      array (
        'image[url]!' => '',
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
        'position!' => 'top',
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
    'image_space' =>
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
        'image[url]!' => '',
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
    'image_size' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 30,
        'unit' => '%',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_height' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_object_fit' =>
    array (
      'section' => 'section_style_image',
      'type' => 'select',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'image_height[size]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_object_position' =>
    array (
      'section' => 'section_style_image',
      'type' => 'select',
      'default' => 'center center',
      'responsive' => true,
      'condition' =>
      array (
        'image_height[size]!' => '',
        'image_object_fit' =>
        array (
          0 => 'cover',
          1 => 'contain',
          2 => 'scale-down',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_radius' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'separator_panel_style' =>
    array (
      'section' => 'section_style_image',
      'type' => 'divider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_opacity' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_opacity_hover' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_hover_transition' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0.3,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_animation' =>
    array (
      'section' => 'section_style_image',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_border' =>
    array (
      'section' => 'section_style_image',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'image_border',
    ),
    'image_box_shadow_box_shadow' =>
    array (
      'section' => 'section_style_image',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'image_box_shadow',
    ),
    'css_filters_css_filter' =>
    array (
      'section' => 'section_style_image',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'css_filters',
    ),
    'css_filters_hover_css_filter' =>
    array (
      'section' => 'section_style_image',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'css_filters_hover',
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
    'title_stroke_text_stroke' =>
    array (
      'section' => 'section_style_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'title_stroke',
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
    'thumbnail_image_size' => 'custom',
    'image_border_border' => 'solid',
    'image_box_shadow_box_shadow' => 'yes',
    'css_filters_css_filter' => 'custom',
    'css_filters_hover_css_filter' => 'custom',
    'title_typography_typography' => 'custom',
    'title_stroke_text_stroke' => 'yes',
    'title_shadow_text_shadow' => 'yes',
    'description_typography_typography' => 'custom',
    'description_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'image',
    1 => 'title_text',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/image-box-widget.md',
    1 => 'docs/knowledge/elementor/widgets/image-box-widget.md',
  ),
  'control_count' => 36,
);
