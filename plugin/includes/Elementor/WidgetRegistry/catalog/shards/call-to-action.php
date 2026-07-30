<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'call-to-action',
  'source' => 'pro',
  'widget_type' => 'call-to-action',
  'title' => 'Call to Action',
  'icon' => 'eicon-image-rollover',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'call to action',
    1 => 'cta',
    2 => 'button',
  ),
  'file' => 'pro-elements/modules/call-to-action/widgets/call-to-action.php',
  'intent' => 'The Call to Action (CTA) widget enables designers to create compelling conversion-focused sections that combine text, imagery, and buttons to prompt specific user actions. This widget is essential for marketing initiatives, promotional campaigns, and guiding visitors toward key conversion points on your website.',
  'use_cases' =>
  array (
    0 => 'Building landing pages that require strong conversion elements',
    1 => 'Creating promotional sections within larger page designs',
    2 => 'Designing email signup or download incentive areas',
    3 => 'Establishing clear next-step guidance for website visitors',
    4 => 'Combining visual elements with actionable button directives',
    5 => 'In Elementor Editor, click +',
    6 => 'All available widgets are displayed',
    7 => 'Click or drag the widget to the canvas',
    8 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content area supports text, headings, and descriptive copy',
    1 => 'Button configuration — customizable button text, styling, and link destinations',
    2 => 'Background options — image, color, or gradient background support',
    3 => 'Layout controls — alignment and spacing adjustments for content positioning',
    4 => 'Responsive design — breakpoint-specific adjustments for mobile and tablet views',
    5 => 'Typography styling — font family, size, color, and weight customization',
    6 => 'Advanced options — CSS classes, custom attributes, and hover effects',
    7 => 'Display conditions — show/hide rules based on user or page criteria',
    8 => 'Add a Call to Action widget – Step-by-step',
    9 => 'Position – Set the image position: Left, Above, or Right.Choose Image: Select or upload an image.Image Resolution: Adjust the image resolution from thumbnail to full or set a custom size.',
    10 => 'Choose Image – Select or upload an image in the Image field.Image Resolution: Adjust the image resolution from thumbnail to full or set a custom size.',
    11 => 'Choose a graphical element – None, Image, or Icon.If an Image is chosen, select or upload an image and set its size.If Icon is chosen, select an icon and customize its appearance and shape.',
    12 => 'Set the title’s HTML tag to H1 – H6, Div, or Span.',
    13 => 'Choose the description’s HTML tag to H1 – H6, Div, or Span.',
    14 => 'Choose the ribbon’s position – Left or Right.',
    15 => 'Width – Specify the width of the image.Height: Allows you to set the height of the image.',
  ),
  'limits' =>
  array (
    0 => 'Widget effectiveness depends heavily on clear, action-oriented copy and visual hierarchy',
    1 => 'Background images may impact load times; optimize file sizes accordingly',
    2 => 'Button contrast and readability are critical for accessibility compliance',
    3 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    4 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_main_image',
      'label' => 'Image',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'skin',
          'type' => 'select',
          'label' => 'Skin',
          'default' => 'classic',
          'options' =>
          array (
            'classic' => 'Classic',
            'cover' => 'Cover',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'layout',
          'type' => 'choose',
          'label' => 'Position',
          'default' => NULL,
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'above' =>
            array (
              'title' => 'Above',
              'icon' => 'eicon-v-align-top',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
            'below' =>
            array (
              'title' => 'Below',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'skin!' => 'cover',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'bg_image',
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
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'image-size',
          'name' => 'bg_image',
          'label' => 'Image Resolution',
          'selector' => NULL,
          'condition' =>
          array (
            'bg_image[id]!' => '',
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
      'id' => 'section_content',
      'label' => 'Content',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'graphic_element',
          'type' => 'choose',
          'label' => 'Graphic Element',
          'default' => 'none',
          'options' =>
          array (
            'none' =>
            array (
              'title' => 'None',
              'icon' => 'eicon-ban',
            ),
            'image' =>
            array (
              'title' => 'Image',
              'icon' => 'eicon-image-bold',
            ),
            'icon' =>
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
        1 =>
        array (
          'key' => 'graphic_image',
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
          'condition' =>
          array (
            'graphic_element' => 'image',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
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
          'condition' =>
          array (
            'graphic_element' => 'icon',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'title',
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
          'key' => 'title_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
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
          ),
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'description',
          'type' => 'textarea',
          'label' => 'Description',
          'default' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
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
          'key' => 'description_tag',
          'type' => 'select',
          'label' => 'Description HTML Tag',
          'default' => 'div',
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
          ),
          'condition' =>
          array (
            'description!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'button',
          'type' => 'text',
          'label' => 'Button Text',
          'default' => 'Click Here',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
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
        9 =>
        array (
          'key' => 'link_click',
          'type' => 'select',
          'label' => 'Apply Link On',
          'default' => 'button',
          'options' =>
          array (
            'box' => 'Whole Box',
            'button' => 'Button Only',
          ),
          'condition' =>
          array (
            'link[url]!' => '',
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
          'group' => 'image-size',
          'name' => 'graphic_image',
          'label' => NULL,
          'selector' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'image',
            'graphic_image[id]!' => '',
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
      'id' => 'section_ribbon',
      'label' => 'Ribbon',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'ribbon_title',
          'type' => 'text',
          'label' => 'Title',
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
        1 =>
        array (
          'key' => 'ribbon_horizontal_position',
          'type' => 'choose',
          'label' => 'Position',
          'default' => NULL,
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'ribbon_title!' => '',
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
    3 =>
    array (
      'id' => 'box_style',
      'label' => 'Box',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'min-height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'alignment',
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
        2 =>
        array (
          'key' => 'vertical_position',
          'type' => 'choose',
          'label' => 'Vertical Position',
          'default' => NULL,
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
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'heading_bg_image_style',
          'type' => 'heading',
          'label' => 'Image',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'bg_image[url]!' => '',
            'skin' => 'classic',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'image_min_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'skin' => 'classic',
            'layout!' => 'above',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'image_min_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'skin' => 'classic',
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
    4 =>
    array (
      'id' => 'graphic_element_style',
      'label' => 'Graphic Element',
      'tab' => 'style',
      'condition' =>
      array (
        'graphic_element!' =>
        array (
          0 => 'none',
          1 => '',
        ),
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'graphic_image_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'image',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'graphic_image_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'image',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'graphic_image_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'image',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
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
          'condition' =>
          array (
            'graphic_element' => 'icon',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'icon_shape',
          'type' => 'select',
          'label' => 'Shape',
          'default' => 'circle',
          'options' =>
          array (
            'circle' => 'Circle',
            'square' => 'Square',
          ),
          'condition' =>
          array (
            'icon_view!' => 'default',
            'graphic_element' => 'icon',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'icon_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'icon',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'icon_primary_color',
          'type' => 'color',
          'label' => 'Primary Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'icon',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'icon_secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'icon',
            'icon_view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'icon_size',
          'type' => 'slider',
          'label' => 'Icon Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'icon',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'icon_padding',
          'type' => 'slider',
          'label' => 'Icon Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'icon',
            'icon_view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'icon_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'icon',
            'icon_view' => 'framed',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'icon_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'graphic_element' => 'icon',
            'icon_view!' => 'default',
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
          'group' => 'border',
          'name' => 'graphic_image_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__image img',
          'condition' =>
          array (
            'graphic_element' => 'image',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    5 =>
    array (
      'id' => 'section_content_style',
      'label' => 'Content',
      'tab' => 'style',
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'title',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'description',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_style_title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'title_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'heading_style_description',
          'type' => 'heading',
          'label' => 'Description',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'description_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'heading_content_colors',
          'type' => 'heading',
          'label' => 'Colors',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'content_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'skin' => 'classic',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Title Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'description_color',
          'type' => 'color',
          'label' => 'Description Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'button_color',
          'type' => 'color',
          'label' => 'Button Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'content_bg_color_hover',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'skin' => 'classic',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'title_color_hover',
          'type' => 'color',
          'label' => 'Title Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'description_color_hover',
          'type' => 'color',
          'label' => 'Description Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'button_color_hover',
          'type' => 'color',
          'label' => 'Button Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button!' => '',
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
          'name' => 'title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__title',
          'condition' =>
          array (
            'title!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'description_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__description',
          'condition' =>
          array (
            'description!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    6 =>
    array (
      'id' => 'button_style',
      'label' => 'Button',
      'tab' => 'style',
      'condition' =>
      array (
        'button!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'button_size',
          'type' => 'select',
          'label' => 'Size',
          'default' => 'sm',
          'options' =>
          array (
            'xs' => 'Extra Small',
            'sm' => 'Small',
            'md' => 'Medium',
            'lg' => 'Large',
            'xl' => 'Extra Large',
          ),
          'condition' =>
          array (
            'button_size!' => 'sm',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'button_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'button_background_color',
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
          'key' => 'button_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'button_hover_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'button_hover_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'button_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'button_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'button_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
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
          'name' => 'button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'button_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'box-shadow',
          'name' => 'button_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    7 =>
    array (
      'id' => 'section_ribbon_style',
      'label' => 'Ribbon',
      'tab' => 'style',
      'condition' =>
      array (
        'ribbon_title!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'ribbon_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'ribbon_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'ribbon_distance',
          'type' => 'slider',
          'label' => 'Distance',
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
          'name' => 'ribbon_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-ribbon-inner',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'box-shadow',
          'name' => 'box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-ribbon-inner',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    8 =>
    array (
      'id' => 'hover_effects',
      'label' => 'Hover Effects',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'content_hover_heading',
          'type' => 'heading',
          'label' => 'Content',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'skin' => 'cover',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'content_animation',
          'type' => 'select',
          'label' => 'Hover Animation',
          'default' => 'grow',
          'options' => NULL,
          'condition' =>
          array (
            'skin' => 'cover',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'animation_class',
          'type' => 'hidden',
          'label' => 'Animation',
          'default' => 'animated-content',
          'options' => NULL,
          'condition' =>
          array (
            'content_animation!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'content_animation_duration',
          'type' => 'slider',
          'label' => 'Animation Duration (ms)',
          'default' =>
          array (
            'size' => 1000,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'content_animation!' => '',
            'skin' => 'cover',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'sequenced_animation',
          'type' => 'switcher',
          'label' => 'Sequenced Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'content_animation!' => '',
            'skin' => 'cover',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'background_hover_heading',
          'type' => 'heading',
          'label' => 'Background',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'skin' => 'cover',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'transformation',
          'type' => 'select',
          'label' => 'Hover Animation',
          'default' => 'zoom-in',
          'options' =>
          array (
            '' => 'None',
            'zoom-in' => 'Zoom In',
            'zoom-out' => 'Zoom Out',
            'move-left' => 'Move Left',
            'move-right' => 'Move Right',
            'move-up' => 'Move Up',
            'move-down' => 'Move Down',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'overlay_color',
          'type' => 'color',
          'label' => 'Overlay Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'overlay_blend_mode',
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
            'color-burn' => 'Color Burn',
            'hue' => 'Hue',
            'saturation' => 'Saturation',
            'color' => 'Color',
            'exclusion' => 'Exclusion',
            'luminosity' => 'Luminosity',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'overlay_color_hover',
          'type' => 'color',
          'label' => 'Overlay Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'effect_duration',
          'type' => 'slider',
          'label' => 'Transition Duration (ms)',
          'default' =>
          array (
            'size' => 1500,
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
          'group' => 'css-filter',
          'name' => 'bg_filters',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta__bg',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'css-filter',
          'name' => 'bg_filters_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-cta:hover .elementor-cta__bg',
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
      'name' => 'bg_image',
      'label' => 'Image Resolution',
      'selector' => NULL,
      'condition' =>
      array (
        'bg_image[id]!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'image-size',
      'name' => 'graphic_image',
      'label' => NULL,
      'selector' => NULL,
      'condition' =>
      array (
        'graphic_element' => 'image',
        'graphic_image[id]!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'border',
      'name' => 'graphic_image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__image img',
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__title',
      'condition' =>
      array (
        'title!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'description_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__description',
      'condition' =>
      array (
        'description!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'typography',
      'name' => 'button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'text-shadow',
      'name' => 'button_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'box-shadow',
      'name' => 'button_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'typography',
      'name' => 'ribbon_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-ribbon-inner',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'box-shadow',
      'name' => 'box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-ribbon-inner',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'css-filter',
      'name' => 'bg_filters',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta__bg',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    13 =>
    array (
      'group' => 'css-filter',
      'name' => 'bg_filters_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-cta:hover .elementor-cta__bg',
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
    'skin' =>
    array (
      'section' => 'section_main_image',
      'type' => 'select',
      'default' => 'classic',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'layout' =>
    array (
      'section' => 'section_main_image',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'skin!' => 'cover',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bg_image' =>
    array (
      'section' => 'section_main_image',
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
    'bg_image_image_size' =>
    array (
      'section' => 'section_main_image',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'bg_image[id]!' => '',
      ),
      'group' => 'image-size',
      'group_prefix' => 'bg_image',
    ),
    'graphic_element' =>
    array (
      'section' => 'section_content',
      'type' => 'choose',
      'default' => 'none',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'graphic_image' =>
    array (
      'section' => 'section_content',
      'type' => 'media',
      'default' =>
      array (
        'url' =>
        array (
          '__unresolved__' => 'Utils::get_placeholder_image_src()',
        ),
      ),
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'selected_icon' =>
    array (
      'section' => 'section_content',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-star',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title' =>
    array (
      'section' => 'section_content',
      'type' => 'text',
      'default' => 'This is the heading',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_tag' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'h2',
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description' =>
    array (
      'section' => 'section_content',
      'type' => 'textarea',
      'default' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_tag' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'div',
      'responsive' => false,
      'condition' =>
      array (
        'description!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button' =>
    array (
      'section' => 'section_content',
      'type' => 'text',
      'default' => 'Click Here',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_content',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_click' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'button',
      'responsive' => false,
      'condition' =>
      array (
        'link[url]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'graphic_image_image_size' =>
    array (
      'section' => 'section_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
        'graphic_image[id]!' => '',
      ),
      'group' => 'image-size',
      'group_prefix' => 'graphic_image',
    ),
    'ribbon_title' =>
    array (
      'section' => 'section_ribbon',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_horizontal_position' =>
    array (
      'section' => 'section_ribbon',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'ribbon_title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'min-height' =>
    array (
      'section' => 'box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alignment' =>
    array (
      'section' => 'box_style',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'vertical_position' =>
    array (
      'section' => 'box_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'padding' =>
    array (
      'section' => 'box_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_bg_image_style' =>
    array (
      'section' => 'box_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'bg_image[url]!' => '',
        'skin' => 'classic',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_min_width' =>
    array (
      'section' => 'box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'skin' => 'classic',
        'layout!' => 'above',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_min_height' =>
    array (
      'section' => 'box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'skin' => 'classic',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'graphic_image_spacing' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'graphic_image_width' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'graphic_image_border_radius' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_view' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_shape' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'select',
      'default' => 'circle',
      'responsive' => false,
      'condition' =>
      array (
        'icon_view!' => 'default',
        'graphic_element' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_spacing' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_primary_color' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_secondary_color' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
        'icon_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_size' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_padding' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
        'icon_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_border_width' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
        'icon_view' => 'framed',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_border_radius' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
        'icon_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'graphic_image_border_border' =>
    array (
      'section' => 'graphic_element_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => 'border',
      'group_prefix' => 'graphic_image_border',
    ),
    'heading_style_title' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_spacing' =>
    array (
      'section' => 'section_content_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_style_description' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_spacing' =>
    array (
      'section' => 'section_content_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'description!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_content_colors' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_bg_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'skin' => 'classic',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_bg_color_hover' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'skin' => 'classic',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color_hover' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_color_hover' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_color_hover' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'title_typography',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'title_text_shadow_text_shadow' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'title_text_shadow',
    ),
    'description_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'description_typography',
    ),
    'button_size' =>
    array (
      'section' => 'button_style',
      'type' => 'select',
      'default' => 'sm',
      'responsive' => false,
      'condition' =>
      array (
        'button_size!' => 'sm',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color' =>
    array (
      'section' => 'button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_background_color' =>
    array (
      'section' => 'button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_color' =>
    array (
      'section' => 'button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_text_color' =>
    array (
      'section' => 'button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_background_color' =>
    array (
      'section' => 'button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_border_color' =>
    array (
      'section' => 'button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_width' =>
    array (
      'section' => 'button_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_radius' =>
    array (
      'section' => 'button_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_padding' =>
    array (
      'section' => 'button_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_typography_typography' =>
    array (
      'section' => 'button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'button_typography',
    ),
    'button_text_shadow_text_shadow' =>
    array (
      'section' => 'button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'button_text_shadow',
    ),
    'button_box_shadow_box_shadow' =>
    array (
      'section' => 'button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'button_box_shadow',
    ),
    'ribbon_bg_color' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_text_color' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_distance' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_typography_typography' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'ribbon_typography',
    ),
    'box_shadow_box_shadow' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'box_shadow',
    ),
    'content_hover_heading' =>
    array (
      'section' => 'hover_effects',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'skin' => 'cover',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_animation' =>
    array (
      'section' => 'hover_effects',
      'type' => 'select',
      'default' => 'grow',
      'responsive' => false,
      'condition' =>
      array (
        'skin' => 'cover',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_class' =>
    array (
      'section' => 'hover_effects',
      'type' => 'hidden',
      'default' => 'animated-content',
      'responsive' => false,
      'condition' =>
      array (
        'content_animation!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_animation_duration' =>
    array (
      'section' => 'hover_effects',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1000,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'content_animation!' => '',
        'skin' => 'cover',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sequenced_animation' =>
    array (
      'section' => 'hover_effects',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'content_animation!' => '',
        'skin' => 'cover',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_hover_heading' =>
    array (
      'section' => 'hover_effects',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'skin' => 'cover',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'transformation' =>
    array (
      'section' => 'hover_effects',
      'type' => 'select',
      'default' => 'zoom-in',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'overlay_color' =>
    array (
      'section' => 'hover_effects',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'overlay_blend_mode' =>
    array (
      'section' => 'hover_effects',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'overlay_color_hover' =>
    array (
      'section' => 'hover_effects',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'effect_duration' =>
    array (
      'section' => 'hover_effects',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1500,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bg_filters_css_filter' =>
    array (
      'section' => 'hover_effects',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'bg_filters',
    ),
    'bg_filters_hover_css_filter' =>
    array (
      'section' => 'hover_effects',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'bg_filters_hover',
    ),
  ),
  'group_activators' =>
  array (
    'bg_image_image_size' => 'custom',
    'graphic_image_image_size' => 'custom',
    'graphic_image_border_border' => 'solid',
    'title_typography_typography' => 'custom',
    'text_stroke_text_stroke' => 'yes',
    'title_text_shadow_text_shadow' => 'yes',
    'description_typography_typography' => 'custom',
    'button_typography_typography' => 'custom',
    'button_text_shadow_text_shadow' => 'yes',
    'button_box_shadow_box_shadow' => 'yes',
    'ribbon_typography_typography' => 'custom',
    'box_shadow_box_shadow' => 'yes',
    'bg_filters_css_filter' => 'custom',
    'bg_filters_hover_css_filter' => 'custom',
  ),
  'required_for_render' =>
  array (
    0 => 'title',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/call-to-action.md',
    1 => 'docs/knowledge/elementor/widgets/call-to-action.md',
    2 => 'docs/knowledge/elementor/widgets/call-to-action-widget.md',
    3 => 'docs/knowledge/elementor/widgets/call-to-action-widget.md',
  ),
  'control_count' => 85,
);
