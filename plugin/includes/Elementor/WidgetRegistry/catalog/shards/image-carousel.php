<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'image-carousel',
  'source' => 'free',
  'widget_type' => 'image-carousel',
  'title' => 'Image Carousel',
  'icon' => 'eicon-slider-push',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'image',
    1 => 'photo',
    2 => 'visual',
    3 => 'carousel',
    4 => 'slider',
  ),
  'file' => 'elementor/includes/widgets/image-carousel.php',
  'intent' => 'Click the star button to replace the arrow with an icon from the icon library. Click the SVG button to replace the arrow with an uploaded SVG image. For more details, see Enable SVG Support in Elementor.',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Image Carousel widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Add an Image Carousel widget – Step-by-step',
    2 => 'Choose the carousel movement – from left or right',
    3 => 'Position – Choose the position of the arrows inside or outside the slider.',
    4 => 'Size – Use the slider to set the size of the arrows. Size can be in PX, EM, REM, or Custom. Learn more about Units of measurement.',
    5 => 'Color – Select the arrow color.',
    6 => 'Position – Choose the position of the dots inside or outside the slider.',
    7 => 'Size – Define the dot size in PX, EM, REM or add custom size. Learn more about Units of measurement.',
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
      'id' => 'section_image_carousel',
      'label' => 'Image Carousel',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'carousel_name',
          'type' => 'text',
          'label' => 'Carousel Name',
          'default' => 'Image Carousel',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'carousel',
          'type' => 'gallery',
          'label' => 'Add Images',
          'default' =>
          array (
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
          'key' => 'slides_to_show',
          'type' => 'select',
          'label' => 'Slides to Show',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\BinaryOp\\Plus',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'slides_to_scroll',
          'type' => 'select',
          'label' => 'Slides to Scroll',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\BinaryOp\\Plus',
          ),
          'condition' =>
          array (
            'slides_to_show!' => '1',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => 'Set how many slides are scrolled per swipe.',
        ),
        4 =>
        array (
          'key' => 'image_stretch',
          'type' => 'select',
          'label' => 'Image Stretch',
          'default' => 'no',
          'options' =>
          array (
            'no' => 'No',
            'yes' => 'Yes',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'navigation',
          'type' => 'select',
          'label' => 'Navigation',
          'default' => 'both',
          'options' =>
          array (
            'both' => 'Arrows and Dots',
            'arrows' => 'Arrows',
            'dots' => 'Dots',
            'none' => 'None',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'navigation_previous_icon',
          'type' => 'icons',
          'label' => 'Previous Arrow Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'navigation',
                'operator' => '=',
                'value' => 'both',
              ),
              1 =>
              array (
                'name' => 'navigation',
                'operator' => '=',
                'value' => 'arrows',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'navigation_next_icon',
          'type' => 'icons',
          'label' => 'Next Arrow Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'navigation',
                'operator' => '=',
                'value' => 'both',
              ),
              1 =>
              array (
                'name' => 'navigation',
                'operator' => '=',
                'value' => 'arrows',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
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
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'link_to' => 'custom',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
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
            'link_to' => 'file',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' =>
          array (
            '__unresolved__' => 'sprintf()',
          ),
        ),
        11 =>
        array (
          'key' => 'caption_type',
          'type' => 'select',
          'label' => 'Caption',
          'default' => '',
          'options' =>
          array (
            '' => 'None',
            'title' => 'Title',
            'caption' => 'Caption',
            'description' => 'Description',
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
          'condition' => NULL,
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
      'id' => 'section_additional_options',
      'label' => 'Additional Options',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'lazyload',
          'type' => 'switcher',
          'label' => 'Lazyload',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'autoplay',
          'type' => 'switcher',
          'label' => 'Autoplay',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'pause_on_hover',
          'type' => 'switcher',
          'label' => 'Pause on Hover',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'autoplay' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'pause_on_interaction',
          'type' => 'switcher',
          'label' => 'Pause on Interaction',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'autoplay' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'autoplay_speed',
          'type' => 'number',
          'label' => 'Autoplay Speed',
          'default' => 5000,
          'options' => NULL,
          'condition' =>
          array (
            'autoplay' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'infinite',
          'type' => 'switcher',
          'label' => 'Infinite Loop',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'effect',
          'type' => 'select',
          'label' => 'Effect',
          'default' => 'slide',
          'options' =>
          array (
            'slide' => 'Slide',
            'fade' => 'Fade',
          ),
          'condition' =>
          array (
            'slides_to_show' => '1',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'speed',
          'type' => 'number',
          'label' => 'Animation Speed',
          'default' => 500,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'direction',
          'type' => 'select',
          'label' => 'Direction',
          'default' => 'ltr',
          'options' =>
          array (
            'ltr' => 'Left',
            'rtl' => 'Right',
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
    2 =>
    array (
      'id' => 'section_style_navigation',
      'label' => 'Navigation',
      'tab' => 'style',
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'dots',
          2 => 'both',
        ),
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_style_arrows',
          'type' => 'heading',
          'label' => 'Arrows',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'arrows',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'arrows_position',
          'type' => 'select',
          'label' => 'Position',
          'default' => 'inside',
          'options' =>
          array (
            'inside' => 'Inside',
            'outside' => 'Outside',
          ),
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'arrows',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'arrows_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'arrows',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'arrows_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'arrows',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'heading_style_dots',
          'type' => 'heading',
          'label' => 'Pagination',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'dots_position',
          'type' => 'select',
          'label' => 'Position',
          'default' => 'outside',
          'options' =>
          array (
            'outside' => 'Outside',
            'inside' => 'Inside',
          ),
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'dots_gap',
          'type' => 'slider',
          'label' => 'Space Between Dots',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'dots_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'dots_inactive_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'dots_color',
          'type' => 'color',
          'label' => 'Active Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
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
      'id' => 'section_style_image',
      'label' => 'Image',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'gallery_vertical_align',
          'type' => 'choose',
          'label' => 'Vertical Align',
          'default' => NULL,
          'options' =>
          array (
            'flex-start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-v-align-top',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-v-align-middle',
            ),
            'flex-end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'slides_to_show!' => '1',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'image_spacing',
          'type' => 'select',
          'label' => 'Spacing',
          'default' => '',
          'options' =>
          array (
            '' => 'Default',
            'custom' => 'Custom',
          ),
          'condition' =>
          array (
            'slides_to_show!' => '1',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'image_spacing_custom',
          'type' => 'slider',
          'label' => 'Image Spacing',
          'default' =>
          array (
            'size' => 20,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'image_spacing' => 'custom',
            'slides_to_show!' => '1',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
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
          'group' => 'border',
          'name' => 'image_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-carousel-wrapper .elementor-image-carousel .swiper-slide-image',
          'condition' => NULL,
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
      'id' => 'section_caption',
      'label' => 'Caption',
      'tab' => 'style',
      'condition' =>
      array (
        'caption_type!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'caption_align',
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
          'key' => 'caption_text_color',
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
          'selector' => '{{WRAPPER}} .elementor-image-carousel-caption',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'caption_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-image-carousel-caption',
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
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'border',
      'name' => 'image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-carousel-wrapper .elementor-image-carousel .swiper-slide-image',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'caption_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-carousel-caption',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'text-shadow',
      'name' => 'caption_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-image-carousel-caption',
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
    'carousel_name' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'text',
      'default' => 'Image Carousel',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'carousel' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'gallery',
      'default' =>
      array (
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_to_show' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'select',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_to_scroll' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'select',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'slides_to_show!' => '1',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_stretch' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'select',
      'default' => 'no',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'navigation' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'select',
      'default' => 'both',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'navigation_previous_icon' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'navigation',
            'operator' => '=',
            'value' => 'both',
          ),
          1 =>
          array (
            'name' => 'navigation',
            'operator' => '=',
            'value' => 'arrows',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'navigation_next_icon' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'navigation',
            'operator' => '=',
            'value' => 'both',
          ),
          1 =>
          array (
            'name' => 'navigation',
            'operator' => '=',
            'value' => 'arrows',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_to' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'link_to' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'open_lightbox' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' =>
      array (
        'link_to' => 'file',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_type' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'thumbnail_image_size' =>
    array (
      'section' => 'section_image_carousel',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'image-size',
      'group_prefix' => 'thumbnail',
    ),
    'lazyload' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'autoplay' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pause_on_hover' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'autoplay' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pause_on_interaction' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'autoplay' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'autoplay_speed' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'number',
      'default' => 5000,
      'responsive' => false,
      'condition' =>
      array (
        'autoplay' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'infinite' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'effect' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'select',
      'default' => 'slide',
      'responsive' => false,
      'condition' =>
      array (
        'slides_to_show' => '1',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'speed' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'number',
      'default' => 500,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'direction' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'select',
      'default' => 'ltr',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_style_arrows' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'arrows_position' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'select',
      'default' => 'inside',
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'arrows_size' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'arrows_color' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_style_dots' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_position' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'select',
      'default' => 'outside',
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_gap' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_size' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_inactive_color' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_color' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gallery_vertical_align' =>
    array (
      'section' => 'section_style_image',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'slides_to_show!' => '1',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_spacing' =>
    array (
      'section' => 'section_style_image',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'slides_to_show!' => '1',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_spacing_custom' =>
    array (
      'section' => 'section_style_image',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 20,
      ),
      'responsive' => true,
      'condition' =>
      array (
        'image_spacing' => 'custom',
        'slides_to_show!' => '1',
      ),
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
    'caption_align' =>
    array (
      'section' => 'section_caption',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_text_color' =>
    array (
      'section' => 'section_caption',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_space' =>
    array (
      'section' => 'section_caption',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_typography_typography' =>
    array (
      'section' => 'section_caption',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'caption_typography',
    ),
    'caption_shadow_text_shadow' =>
    array (
      'section' => 'section_caption',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'caption_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'thumbnail_image_size' => 'custom',
    'image_border_border' => 'solid',
    'caption_typography_typography' => 'custom',
    'caption_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'carousel',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/image-carousel-widget.md',
    1 => 'docs/knowledge/elementor/widgets/image-carousel-widget.md',
  ),
  'control_count' => 42,
);
