<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'image',
  'source' => 'free',
  'widget_type' => 'image',
  'title' => 'Image',
  'icon' => 'eicon-image',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
    0 => 'image',
    1 => 'photo',
    2 => 'visual',
  ),
  'file' => 'elementor/includes/widgets/image.php',
  'intent' => 'Add your image or select dynamic tags to automatically use the post’s featured image, site logo, or author profile picture. Image Resolution',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Image widget',
    4 => 'What is the Image Box widget',
    5 => 'What is the Image Carousel widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Add an Image widget – Step-by-step',
    2 => 'Tip – If you don’t want to add an image manually, you can use the Dynamic option. This feature automatically pulls images from posts (like featured images), your site’s logo, or the author’s profile picture.',
    3 => 'Fill – Makes the image completely cover the space, even if it changes the image’s shape.',
    4 => 'Cover – Stretches the image to fit the width but might cut off parts of it.',
    5 => 'Contain – Keeps the whole image inside, but you might see blank areas around it.',
    6 => 'Opacity – Adjust the image transparency.',
    7 => 'CSS Filters – Apply filters such as Blur, Brightness, Contrast, and Saturation.',
    8 => 'Add an Image Box widget – Step-by-step',
    9 => 'Adjust image size – thumbnail, full, or your custom size.Does not appear if no image is chosen.',
    10 => 'Spacing – Use the slider to adjust the gap between the title and the description.Color: Define the color of the title.Typography: Choose the font and adjust the size of the title. For more details, see Typography.Text Stroke: Click the 🖋️ icon to apply a stroke effect to the title. Learn more about Text Stroke.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about Shadows.',
    11 => 'Color – Change the color of the description text.',
    12 => 'Typography – Customize the font and adjust the size for the description.',
    13 => 'Text Shadow – Click on the 🖋️icon to apply a shadow effect to the description. For more details see, Shadows.',
    14 => 'Add an Image Carousel widget – Step-by-step',
    15 => 'Choose the carousel movement – from left or right',
    16 => 'Position – Choose the position of the arrows inside or outside the slider.',
    17 => 'Size – Use the slider to set the size of the arrows. Size can be in PX, EM, REM, or Custom. Learn more about Units of measurement.',
    18 => 'Color – Select the arrow color.',
    19 => 'Position – Choose the position of the dots inside or outside the slider.',
    20 => 'Size – Define the dot size in PX, EM, REM or add custom size. Learn more about Units of measurement.',
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
      'label' => 'Image',
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
          'key' => 'caption_source',
          'type' => 'select',
          'label' => 'Caption',
          'default' => 'none',
          'options' =>
          array (
            'none' => 'None',
            'attachment' => 'Attachment Caption',
            'custom' => 'Custom Caption',
          ),
          'condition' =>
          array (
            'image[url]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'caption',
          'type' => 'text',
          'label' => 'Custom Caption',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'image[url]!' => '',
            'caption_source' => 'custom',
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
          'key' => 'link_to',
          'type' => 'select',
          'label' => 'Link',
          'default' => 'none',
          'options' =>
          array (
            'none' => 'None',
            'file' => 'Media File',
            'custom' => 'Custom URL',
          ),
          'condition' =>
          array (
            'image[url]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'image[url]!' => '',
            'link_to' => 'custom',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'open_lightbox',
          'type' => 'select',
          'label' => 'Lightbox',
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Default',
            'yes' => 'Yes',
            'no' => 'No',
          ),
          'condition' =>
          array (
            'image[url]!' => '',
            'link_to' => 'file',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' =>
          array (
            '__unresolved__' => 'sprintf()',
          ),
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'image-size',
          'name' => 'image',
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
      'id' => 'section_style_image',
      'label' => 'Image',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'align',
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
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'width',
          'type' => 'slider',
          'label' => 'Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'space',
          'type' => 'slider',
          'label' => 'Max Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'object-fit',
          'type' => 'select',
          'label' => 'Object Fit',
          'default' => '',
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
            'height[size]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'object-position',
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
            'height[size]!' => '',
            'object-fit' =>
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
        6 =>
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
        7 =>
        array (
          'key' => 'opacity',
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
          'key' => 'opacity_hover',
          'type' => 'slider',
          'label' => 'Opacity',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'background_hover_transition',
          'type' => 'slider',
          'label' => 'Transition Duration (s)',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
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
        11 =>
        array (
          'key' => 'image_border_radius',
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
          'group' => 'css-filter',
          'name' => 'css_filters',
          'label' => NULL,
          'selector' => '{{WRAPPER}} img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'css-filter',
          'name' => 'css_filters_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}}:hover img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'border',
          'name' => 'image_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'image_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} img',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'box_shadow_position',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'section_style_caption',
      'label' => 'Caption',
      'tab' => 'style',
      'condition' =>
      array (
        'image[url]!' => '',
        'caption_source!' => 'none',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'caption_align',
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
          'key' => 'text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'caption_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'caption_space',
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
          'name' => 'caption_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .widget-image-caption',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'caption_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .widget-image-caption',
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
      'name' => 'image',
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
      'group' => 'css-filter',
      'name' => 'css_filters',
      'label' => NULL,
      'selector' => '{{WRAPPER}} img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'css-filter',
      'name' => 'css_filters_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}}:hover img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'border',
      'name' => 'image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'box-shadow',
      'name' => 'image_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} img',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'box_shadow_position',
      ),
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'caption_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .widget-image-caption',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'text-shadow',
      'name' => 'caption_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .widget-image-caption',
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
    'caption_source' =>
    array (
      'section' => 'section_image',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' =>
      array (
        'image[url]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption' =>
    array (
      'section' => 'section_image',
      'type' => 'text',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'image[url]!' => '',
        'caption_source' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_to' =>
    array (
      'section' => 'section_image',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' =>
      array (
        'image[url]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_image',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'image[url]!' => '',
        'link_to' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'open_lightbox' =>
    array (
      'section' => 'section_image',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' =>
      array (
        'image[url]!' => '',
        'link_to' => 'file',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_image_size' =>
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
      'group_prefix' => 'image',
    ),
    'align' =>
    array (
      'section' => 'section_style_image',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'width' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'space' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'height' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'object-fit' =>
    array (
      'section' => 'section_style_image',
      'type' => 'select',
      'default' => '',
      'responsive' => true,
      'condition' =>
      array (
        'height[size]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'object-position' =>
    array (
      'section' => 'section_style_image',
      'type' => 'select',
      'default' => 'center center',
      'responsive' => true,
      'condition' =>
      array (
        'height[size]!' => '',
        'object-fit' =>
        array (
          0 => 'cover',
          1 => 'contain',
          2 => 'scale-down',
        ),
      ),
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
    'opacity' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'opacity_hover' =>
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
      'default' => NULL,
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
    'image_border_radius' =>
    array (
      'section' => 'section_style_image',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
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
    'caption_align' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'choose',
      'default' => '',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_background_color' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_space' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_typography_typography' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'caption_typography',
    ),
    'caption_text_shadow_text_shadow' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'caption_text_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'image_image_size' => 'custom',
    'css_filters_css_filter' => 'custom',
    'css_filters_hover_css_filter' => 'custom',
    'image_border_border' => 'solid',
    'image_box_shadow_box_shadow' => 'yes',
    'caption_typography_typography' => 'custom',
    'caption_text_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'image',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/image-widget.md',
    1 => 'docs/knowledge/elementor/widgets/image-widget.md',
    2 => 'docs/knowledge/elementor/widgets/image-box-widget.md',
    3 => 'docs/knowledge/elementor/widgets/image-carousel-widget.md',
  ),
  'control_count' => 29,
);
