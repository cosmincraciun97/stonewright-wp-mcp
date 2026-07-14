<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'flip-box',
  'source' => 'pro',
  'widget_type' => 'flip-box',
  'title' => 'Flip Box',
  'icon' => 'eicon-flip-box',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
  ),
  'file' => 'pro-elements/modules/flip-box/widgets/flip-box.php',
  'intent' => 'The Flip Box widget creates interactive card elements that reveal different content when users hover over or interact with them. One side displays initial content (typically an image and title), while the reverse side shows additional information, making it useful for showcasing team members, services, or product features in an engaging, space-efficient manner.',
  'use_cases' =>
  array (
    0 => 'Displaying team member profiles with photos on front and bios on back',
    1 => 'Presenting service offerings with icons/images and detailed descriptions',
    2 => 'Creating portfolio galleries where hover reveals project details',
    3 => 'Building interactive feature lists for products or offerings',
    4 => 'Designing testimonial cards that flip to show full customer feedback',
    5 => 'The Flip Box Widget helps you create animated boxes that flip to the other side, when a visitor hovers over them',
    6 => 'For more details, see, Create a Background',
    7 => 'This helps search engines find and understand the price list, boosting SEO',
    8 => 'The title can also be tagged as a paragraph, span or div',
  ),
  'settings_highlights' =>
  array (
    0 => 'Front and back content areas with independent styling options',
    1 => 'Flip animation direction (horizontal, vertical, fade effects)',
    2 => 'Image and text customization for both sides',
    3 => 'Link configuration to make cards clickable',
    4 => 'Border, shadow, and background color controls',
    5 => 'Hover trigger settings for desktop vs. mobile responsiveness',
    6 => 'Icon and button placement options',
    7 => 'Text alignment and spacing adjustments',
    8 => 'Graphic Element – Choose between None, Image or Icon to display a graphical Element in the front of the flip box. For more details, see Adding images and icons.',
    9 => 'Title & Description – Choose the title and description that appears in the front of the flip box',
    10 => 'Background Type – Choose Color, Image or Gradient as the background of the front of the flip box. For more details, see, Create a Background.',
    11 => 'Position – Select the position of the image, such as Top Center, Top Right, Center Center, etc.',
    12 => 'Attachment – Select from Default, Scroll, or Fixed',
    13 => 'Repeat – Choose from Default, No-repeat, Repeat, Repeat-x, or Repeat-y',
    14 => 'Size – Select from Default, Auto, Cover, or Contain',
    15 => 'Background Overlay – Choose a color for the overlay',
  ),
  'limits' =>
  array (
    0 => 'Mobile tap behavior may differ from desktop hover, requiring explicit interaction design',
    1 => 'Performance can degrade with multiple flip boxes on single page due to animation overhead',
    2 => 'Content on reverse side must be carefully sized to avoid overflow or text clipping',
    3 => 'Accessibility considerations needed — screen readers may not properly announce flipped content without additional ARIA labels',
    4 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    5 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_side_a_content',
      'label' => 'Front',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'graphic_element',
          'type' => 'choose',
          'label' => 'Graphic Element',
          'default' => 'icon',
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
          'key' => 'title_text_a',
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
          'key' => 'description_text_a',
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
    1 =>
    array (
      'id' => 'section_side_b_content',
      'label' => 'Back',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title_text_b',
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
        1 =>
        array (
          'key' => 'description_text_b',
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
        2 =>
        array (
          'key' => 'button_text',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'section_box_settings',
      'label' => 'Settings',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title_tag',
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
        1 =>
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
            'p' => 'p',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
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
        3 =>
        array (
          'key' => 'border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'flip_effect',
          'type' => 'select',
          'label' => 'Flip Effect',
          'default' => 'flip',
          'options' =>
          array (
            'flip' => 'Flip',
            'slide' => 'Slide',
            'push' => 'Push',
            'zoom-in' => 'Zoom In',
            'zoom-out' => 'Zoom Out',
            'fade' => 'Fade',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'flip_direction',
          'type' => 'choose',
          'label' => 'Flip Direction',
          'default' => 'up',
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
            'up' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'down' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'flip_effect!' =>
            array (
              0 => 'fade',
              1 => 'zoom-in',
              2 => 'zoom-out',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'flip_3d',
          'type' => 'switcher',
          'label' => '3D Depth',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'flip_effect' => 'flip',
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
      'id' => 'section_style_a',
      'label' => 'Front',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'background_overlay_a',
          'type' => 'color',
          'label' => 'Background Overlay',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'background_a_image[id]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'background_overlay_a_blend_mode',
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
          'condition' =>
          array (
            'background_overlay_a!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'padding_a',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'alignment_a',
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
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'vertical_position_a',
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
        5 =>
        array (
          'key' => 'heading_image_style',
          'type' => 'heading',
          'label' => 'Image',
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
        6 =>
        array (
          'key' => 'image_spacing',
          'type' => 'slider',
          'label' => 'Gap',
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
        7 =>
        array (
          'key' => 'image_width',
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
        8 =>
        array (
          'key' => 'image_opacity',
          'type' => 'slider',
          'label' => 'Opacity',
          'default' =>
          array (
            'size' => 1,
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
        9 =>
        array (
          'key' => 'image_border_radius',
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
        10 =>
        array (
          'key' => 'heading_icon_style',
          'type' => 'heading',
          'label' => 'Icon',
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
        11 =>
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
        12 =>
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
        13 =>
        array (
          'key' => 'icon_spacing',
          'type' => 'slider',
          'label' => 'Gap',
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
        14 =>
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
        15 =>
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
        16 =>
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
        17 =>
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
        18 =>
        array (
          'key' => 'icon_rotate',
          'type' => 'slider',
          'label' => 'Icon Rotate',
          'default' =>
          array (
            'size' => 0,
            'unit' => 'deg',
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
        19 =>
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
        20 =>
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
        21 =>
        array (
          'key' => 'heading_title_style_a',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title_text_a!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        22 =>
        array (
          'key' => 'title_spacing_a',
          'type' => 'slider',
          'label' => 'Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description_text_a!' => '',
            'title_text_a!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        23 =>
        array (
          'key' => 'title_color_a',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title_text_a!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        24 =>
        array (
          'key' => 'heading_description_style_a',
          'type' => 'heading',
          'label' => 'Description',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description_text_a!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        25 =>
        array (
          'key' => 'description_color_a',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description_text_a!' => '',
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
          'group' => 'background',
          'name' => 'background_a',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__front',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'css-filter',
          'name' => 'background_overlay_a_filters',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__front .elementor-flip-box__layer__overlay',
          'condition' =>
          array (
            'background_overlay_a!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'border',
          'name' => 'border_a',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__front',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'shadow_a',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__front',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'border',
          'name' => 'image_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__image img',
          'condition' =>
          array (
            'graphic_element' => 'image',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'typography',
          'name' => 'title_typography_a',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__front .elementor-flip-box__layer__title',
          'condition' =>
          array (
            'title_text_a!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__front .elementor-flip-box__layer__title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        7 =>
        array (
          'group' => 'typography',
          'name' => 'description_typography_a',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__front .elementor-flip-box__layer__description',
          'condition' =>
          array (
            'description_text_a!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    4 =>
    array (
      'id' => 'section_style_b',
      'label' => 'Back',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'background_overlay_b',
          'type' => 'color',
          'label' => 'Background Overlay',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'background_b_image[id]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'background_overlay_b_blend_mode',
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
          'condition' =>
          array (
            'background_overlay_b!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'padding_b',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'alignment_b',
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
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'vertical_position_b',
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
        5 =>
        array (
          'key' => 'heading_title_style_b',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title_text_b!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'title_spacing_b',
          'type' => 'slider',
          'label' => 'Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title_text_b!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'title_color_b',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title_text_b!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'heading_description_style_b',
          'type' => 'heading',
          'label' => 'Description',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description_text_b!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'description_spacing_b',
          'type' => 'slider',
          'label' => 'Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description_text_b!' => '',
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'description_color_b',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'description_text_b!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'heading_button',
          'type' => 'heading',
          'label' => 'Button',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
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
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'button_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'button_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'button_hover_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'button_hover_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 'ms',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'button_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'button_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
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
          'group' => 'background',
          'name' => 'background_b',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__back',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'css-filter',
          'name' => 'background_overlay_b_filters',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__back .elementor-flip-box__layer__overlay',
          'condition' =>
          array (
            'background_overlay_b!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'border',
          'name' => 'border_b',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__back',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'shadow_b',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__back',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'title_typography_b',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__back .elementor-flip-box__layer__title',
          'condition' =>
          array (
            'title_text_b!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke_b',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__back .elementor-flip-box__layer__title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'typography',
          'name' => 'description_typography_b',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__back .elementor-flip-box__layer__description',
          'condition' =>
          array (
            'description_text_b!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        7 =>
        array (
          'group' => 'typography',
          'name' => 'button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__button',
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        8 =>
        array (
          'group' => 'background',
          'name' => 'button_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__button',
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        9 =>
        array (
          'group' => 'background',
          'name' => 'button_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-flip-box__button:hover',
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'exclude' =>
          array (
            0 => 'image',
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
      'group' => 'image-size',
      'name' => 'image',
      'label' => NULL,
      'selector' => NULL,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'background',
      'name' => 'background_a',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__front',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'css-filter',
      'name' => 'background_overlay_a_filters',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__front .elementor-flip-box__layer__overlay',
      'condition' =>
      array (
        'background_overlay_a!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'border',
      'name' => 'border_a',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__front',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'box-shadow',
      'name' => 'shadow_a',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__front',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'border',
      'name' => 'image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__image img',
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'title_typography_a',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__front .elementor-flip-box__layer__title',
      'condition' =>
      array (
        'title_text_a!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__front .elementor-flip-box__layer__title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'typography',
      'name' => 'description_typography_a',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__front .elementor-flip-box__layer__description',
      'condition' =>
      array (
        'description_text_a!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'background',
      'name' => 'background_b',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__back',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'css-filter',
      'name' => 'background_overlay_b_filters',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__back .elementor-flip-box__layer__overlay',
      'condition' =>
      array (
        'background_overlay_b!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'border',
      'name' => 'border_b',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__back',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'box-shadow',
      'name' => 'shadow_b',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__back',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    13 =>
    array (
      'group' => 'typography',
      'name' => 'title_typography_b',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__back .elementor-flip-box__layer__title',
      'condition' =>
      array (
        'title_text_b!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    14 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke_b',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__back .elementor-flip-box__layer__title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    15 =>
    array (
      'group' => 'typography',
      'name' => 'description_typography_b',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__back .elementor-flip-box__layer__description',
      'condition' =>
      array (
        'description_text_b!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    16 =>
    array (
      'group' => 'typography',
      'name' => 'button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__button',
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    17 =>
    array (
      'group' => 'background',
      'name' => 'button_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__button',
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    18 =>
    array (
      'group' => 'background',
      'name' => 'button_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-flip-box__button:hover',
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'graphic_element' =>
    array (
      'section' => 'section_side_a_content',
      'type' => 'choose',
      'default' => 'icon',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image' =>
    array (
      'section' => 'section_side_a_content',
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
      'section' => 'section_side_a_content',
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
    'title_text_a' =>
    array (
      'section' => 'section_side_a_content',
      'type' => 'text',
      'default' => 'This is the heading',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_text_a' =>
    array (
      'section' => 'section_side_a_content',
      'type' => 'textarea',
      'default' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_image_size' =>
    array (
      'section' => 'section_side_a_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => 'image-size',
      'group_prefix' => 'image',
    ),
    'title_text_b' =>
    array (
      'section' => 'section_side_b_content',
      'type' => 'text',
      'default' => 'This is the heading',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_text_b' =>
    array (
      'section' => 'section_side_b_content',
      'type' => 'textarea',
      'default' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text' =>
    array (
      'section' => 'section_side_b_content',
      'type' => 'text',
      'default' => 'Click Here',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_side_b_content',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_click' =>
    array (
      'section' => 'section_side_b_content',
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
    'title_tag' =>
    array (
      'section' => 'section_box_settings',
      'type' => 'select',
      'default' => 'h3',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_tag' =>
    array (
      'section' => 'section_box_settings',
      'type' => 'select',
      'default' => 'div',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'height' =>
    array (
      'section' => 'section_box_settings',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius' =>
    array (
      'section' => 'section_box_settings',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'flip_effect' =>
    array (
      'section' => 'section_box_settings',
      'type' => 'select',
      'default' => 'flip',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'flip_direction' =>
    array (
      'section' => 'section_box_settings',
      'type' => 'choose',
      'default' => 'up',
      'responsive' => false,
      'condition' =>
      array (
        'flip_effect!' =>
        array (
          0 => 'fade',
          1 => 'zoom-in',
          2 => 'zoom-out',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'flip_3d' =>
    array (
      'section' => 'section_box_settings',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'flip_effect' => 'flip',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_overlay_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'background_a_image[id]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_overlay_a_blend_mode' =>
    array (
      'section' => 'section_style_a',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'background_overlay_a!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'padding_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alignment_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'vertical_position_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_image_style' =>
    array (
      'section' => 'section_style_a',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_spacing' =>
    array (
      'section' => 'section_style_a',
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
    'image_width' =>
    array (
      'section' => 'section_style_a',
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
    'image_opacity' =>
    array (
      'section' => 'section_style_a',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_radius' =>
    array (
      'section' => 'section_style_a',
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
    'heading_icon_style' =>
    array (
      'section' => 'section_style_a',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_view' =>
    array (
      'section' => 'section_style_a',
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
      'section' => 'section_style_a',
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
      'section' => 'section_style_a',
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
      'section' => 'section_style_a',
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
      'section' => 'section_style_a',
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
      'section' => 'section_style_a',
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
      'section' => 'section_style_a',
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
    'icon_rotate' =>
    array (
      'section' => 'section_style_a',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0,
        'unit' => 'deg',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_border_width' =>
    array (
      'section' => 'section_style_a',
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
      'section' => 'section_style_a',
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
    'heading_title_style_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title_text_a!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_spacing_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description_text_a!' => '',
        'title_text_a!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title_text_a!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_description_style_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description_text_a!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_color_a' =>
    array (
      'section' => 'section_style_a',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description_text_a!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_a_background' =>
    array (
      'section' => 'section_style_a',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'background_a',
    ),
    'background_overlay_a_filters_css_filter' =>
    array (
      'section' => 'section_style_a',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'background_overlay_a!' => '',
      ),
      'group' => 'css-filter',
      'group_prefix' => 'background_overlay_a_filters',
    ),
    'border_a_border' =>
    array (
      'section' => 'section_style_a',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'border_a',
    ),
    'shadow_a_box_shadow' =>
    array (
      'section' => 'section_style_a',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'shadow_a',
    ),
    'image_border_border' =>
    array (
      'section' => 'section_style_a',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'graphic_element' => 'image',
      ),
      'group' => 'border',
      'group_prefix' => 'image_border',
    ),
    'title_typography_a_typography' =>
    array (
      'section' => 'section_style_a',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title_text_a!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'title_typography_a',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_style_a',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'description_typography_a_typography' =>
    array (
      'section' => 'section_style_a',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description_text_a!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'description_typography_a',
    ),
    'background_overlay_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'background_b_image[id]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_overlay_b_blend_mode' =>
    array (
      'section' => 'section_style_b',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'background_overlay_b!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'padding_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alignment_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'vertical_position_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_title_style_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title_text_b!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_spacing_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title_text_b!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title_text_b!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_description_style_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description_text_b!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_spacing_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description_text_b!' => '',
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_color_b' =>
    array (
      'section' => 'section_style_b',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description_text_b!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_button' =>
    array (
      'section' => 'section_style_b',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_size' =>
    array (
      'section' => 'section_style_b',
      'type' => 'select',
      'default' => 'sm',
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color' =>
    array (
      'section' => 'section_style_b',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_color' =>
    array (
      'section' => 'section_style_b',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_text_color' =>
    array (
      'section' => 'section_style_b',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_border_color' =>
    array (
      'section' => 'section_style_b',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_transition_duration' =>
    array (
      'section' => 'section_style_b',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'ms',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_width' =>
    array (
      'section' => 'section_style_b',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_radius' =>
    array (
      'section' => 'section_style_b',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_b_background' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'background_b',
    ),
    'background_overlay_b_filters_css_filter' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'background_overlay_b!' => '',
      ),
      'group' => 'css-filter',
      'group_prefix' => 'background_overlay_b_filters',
    ),
    'border_b_border' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'border_b',
    ),
    'shadow_b_box_shadow' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'shadow_b',
    ),
    'title_typography_b_typography' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title_text_b!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'title_typography_b',
    ),
    'text_stroke_b_text_stroke' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke_b',
    ),
    'description_typography_b_typography' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'description_text_b!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'description_typography_b',
    ),
    'button_typography_typography' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'button_typography',
    ),
    'button_background_background' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => 'background',
      'group_prefix' => 'button_background',
    ),
    'button_hover_background_background' =>
    array (
      'section' => 'section_style_b',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => 'background',
      'group_prefix' => 'button_hover_background',
    ),
  ),
  'group_activators' =>
  array (
    'image_image_size' => 'custom',
    'background_a_background' => 'classic',
    'background_overlay_a_filters_css_filter' => 'custom',
    'border_a_border' => 'solid',
    'shadow_a_box_shadow' => 'yes',
    'image_border_border' => 'solid',
    'title_typography_a_typography' => 'custom',
    'text_stroke_text_stroke' => 'yes',
    'description_typography_a_typography' => 'custom',
    'background_b_background' => 'classic',
    'background_overlay_b_filters_css_filter' => 'custom',
    'border_b_border' => 'solid',
    'shadow_b_box_shadow' => 'yes',
    'title_typography_b_typography' => 'custom',
    'text_stroke_b_text_stroke' => 'yes',
    'description_typography_b_typography' => 'custom',
    'button_typography_typography' => 'custom',
    'button_background_background' => 'classic',
    'button_hover_background_background' => 'classic',
  ),
  'required_for_render' =>
  array (
    0 => 'title_text_a',
    1 => 'title_text_b',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/flip-box.md',
    1 => 'docs/knowledge/elementor/widgets/flip-box.md',
    2 => 'docs/knowledge/elementor/widgets/flip-box-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/flip-box-widget-pro.md',
  ),
  'control_count' => 82,
);
