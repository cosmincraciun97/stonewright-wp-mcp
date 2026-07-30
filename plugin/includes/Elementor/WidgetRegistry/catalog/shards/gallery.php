<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'gallery',
  'source' => 'pro',
  'widget_type' => 'gallery',
  'title' => 'Gallery',
  'icon' => 'eicon-gallery-justified',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
  ),
  'file' => 'pro-elements/modules/gallery/widgets/gallery.php',
  'intent' => 'The Image Gallery widget allows you to easily add and style complex and beautiful image galleries on your page.',
  'use_cases' =>
  array (
    0 => 'This is helpful when creating a Single Post or Product template in the Theme builder',
    1 => 'Organizing your layout design and structuring content elements inside Elementor.',
    2 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    3 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Type – Select type of gallery, choosing from Single or Multiple. Multiple allows you to have a filterable portfolio-style gallery of images',
    1 => 'Add Images – Choose multiple images from the media library to insert into your gallery and type a Title for the gallery',
    2 => 'Order By – Choose Default or Random order',
    3 => 'Lazy – Load: Set to Yes to use Lazy Load to improve loading speed.',
    4 => 'Layout – Select from Grid, Justified, or Masonry.The Grid is based on an aspect ratio of your choosing. Justified lets you set the height for each row, and adjusts to different widths per image. Masonry maintains the same image width and adjust to varying heights.',
    5 => 'Columns – Set how many columns will be displayed per row, from 1 to 24. Not available if Justified Layout is chosen.',
    6 => 'Row Height – Set the height of each row, in pixels. Only available if Justified Layout is chosen.',
    7 => 'Spacing – Control the amount of space between each image in a row.',
  ),
  'limits' =>
  array (
    0 => 'Row Height: Set the height of each row, in pixels. Only available if Justified Layout is chosen.',
    1 => 'Aspect Ratio: Choose the Aspect Ratio, selecting from 1:1, 3:2, 4:3, 9:16, 16:9, and 21:9. Only available for Grid layout.',
    2 => 'Filter Bar (only available if Multiple type is chosen)',
    3 => 'Pointer Color: Choose the color of the Filter bar pointer. Only available in Hover or Active states.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'settings',
      'label' => 'Settings',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'gallery_type',
          'type' => 'select',
          'label' => 'Type',
          'default' => 'single',
          'options' =>
          array (
            'single' => 'Single',
            'multiple' => 'Multiple',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'gallery',
          'type' => 'gallery',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'gallery_type' => 'single',
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
          'key' => 'galleries',
          'type' => 'repeater',
          'label' => 'Galleries',
          'default' =>
          array (
            0 =>
            array (
              'gallery_title' => 'New Gallery',
            ),
          ),
          'options' => NULL,
          'condition' =>
          array (
            'gallery_type' => 'multiple',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'order_by',
          'type' => 'select',
          'label' => 'Order By',
          'default' => '',
          'options' =>
          array (
            '' => 'Default',
            'random' => 'Random',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'lazyload',
          'type' => 'switcher',
          'label' => 'Lazy Load',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'gallery_layout',
          'type' => 'select',
          'label' => 'Layout',
          'default' => 'grid',
          'options' =>
          array (
            'grid' => 'Grid',
            'justified' => 'Justified',
            'masonry' => 'Masonry',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'columns',
          'type' => 'number',
          'label' => 'Columns',
          'default' => 4,
          'options' => NULL,
          'condition' =>
          array (
            'gallery_layout!' => 'justified',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'ideal_row_height',
          'type' => 'slider',
          'label' => 'Row Height',
          'default' =>
          array (
            'size' => 200,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'gallery_layout' => 'justified',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'gap',
          'type' => 'slider',
          'label' => 'Spacing',
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
        9 =>
        array (
          'key' => 'link_to',
          'type' => 'select',
          'label' => 'Link',
          'default' => 'file',
          'options' =>
          array (
            '' => 'None',
            'file' => 'Media File',
            'custom' => 'Custom URL',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'url',
          'type' => 'url',
          'label' => 'URL',
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
        11 =>
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
        12 =>
        array (
          'key' => 'aspect_ratio',
          'type' => 'select',
          'label' => 'Aspect Ratio',
          'default' => '3:2',
          'options' =>
          array (
            '1:1' => '1:1',
            '3:2' => '3:2',
            '4:3' => '4:3',
            '9:16' => '9:16',
            '16:9' => '16:9',
            '21:9' => '21:9',
          ),
          'condition' =>
          array (
            'gallery_layout' => 'grid',
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
          'name' => 'thumbnail_image',
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
      'id' => 'section_filter_bar_content',
      'label' => 'Filter Bar',
      'tab' => NULL,
      'condition' =>
      array (
        'gallery_type' => 'multiple',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'show_all_galleries',
          'type' => 'switcher',
          'label' => '"All" Filter',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'show_all_galleries_label',
          'type' => 'text',
          'label' => '"All" Filter Label',
          'default' => 'All',
          'options' => NULL,
          'condition' =>
          array (
            'show_all_galleries' => 'yes',
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
          'key' => 'pointer',
          'type' => 'select',
          'label' => 'Pointer',
          'default' => 'underline',
          'options' =>
          array (
            'none' => 'None',
            'underline' => 'Underline',
            'overline' => 'Overline',
            'double-line' => 'Double Line',
            'framed' => 'Framed',
            'background' => 'Background',
            'text' => 'Text',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'animation_line',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'fade',
          'options' =>
          array (
            'fade' => 'Fade',
            'slide' => 'Slide',
            'grow' => 'Grow',
            'drop-in' => 'Drop In',
            'drop-out' => 'Drop Out',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'pointer' =>
            array (
              0 => 'underline',
              1 => 'overline',
              2 => 'double-line',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'animation_framed',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'fade',
          'options' =>
          array (
            'fade' => 'Fade',
            'grow' => 'Grow',
            'shrink' => 'Shrink',
            'draw' => 'Draw',
            'corners' => 'Corners',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'pointer' => 'framed',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'animation_background',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'fade',
          'options' =>
          array (
            'fade' => 'Fade',
            'grow' => 'Grow',
            'shrink' => 'Shrink',
            'sweep-left' => 'Sweep Left',
            'sweep-right' => 'Sweep Right',
            'sweep-up' => 'Sweep Up',
            'sweep-down' => 'Sweep Down',
            'shutter-in-vertical' => 'Shutter In Vertical',
            'shutter-out-vertical' => 'Shutter Out Vertical',
            'shutter-in-horizontal' => 'Shutter In Horizontal',
            'shutter-out-horizontal' => 'Shutter Out Horizontal',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'pointer' => 'background',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'animation_text',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'grow',
          'options' =>
          array (
            'grow' => 'Grow',
            'shrink' => 'Shrink',
            'sink' => 'Sink',
            'float' => 'Float',
            'skew' => 'Skew',
            'rotate' => 'Rotate',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'pointer' => 'text',
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
      'id' => 'overlay',
      'label' => 'Overlay',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'overlay_background',
          'type' => 'switcher',
          'label' => 'Background',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'overlay_title',
          'type' => 'select',
          'label' => 'Title',
          'default' => '',
          'options' =>
          array (
            '' => 'None',
            'title' => 'Title',
            'caption' => 'Caption',
            'alt' => 'Alt',
            'description' => 'Description',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'overlay_description',
          'type' => 'select',
          'label' => 'Description',
          'default' => '',
          'options' =>
          array (
            '' => 'None',
            'title' => 'Title',
            'caption' => 'Caption',
            'alt' => 'Alt',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    3 =>
    array (
      'id' => 'image_style',
      'label' => 'Image',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'image_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'image_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'image_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'image_border_color_hover',
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
          'key' => 'image_border_radius_hover',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'image_hover_animation',
          'type' => 'select',
          'label' => 'Hover Animation',
          'default' => '',
          'options' =>
          array (
            '' => 'None',
            'grow' => 'Zoom In',
            'shrink-contained' => 'Zoom Out',
            'move-contained-left' => 'Move Left',
            'move-contained-right' => 'Move Right',
            'move-contained-top' => 'Move Up',
            'move-contained-bottom' => 'Move Down',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'image_animation_duration',
          'type' => 'slider',
          'label' => 'Animation Duration (ms)',
          'default' =>
          array (
            'size' => 800,
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
          'name' => 'image_css_filters',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-gallery-image',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'css-filter',
          'name' => 'image_css_filters_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-gallery-item:hover .e-gallery-image',
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
      'id' => 'overlay_style',
      'label' => 'Overlay',
      'tab' => 'style',
      'condition' =>
      array (
        'overlay_background' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'image_blend_mode',
          'type' => 'select',
          'label' => 'Blend Mode',
          'default' => '',
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
        1 =>
        array (
          'key' => 'background_overlay_hover_animation',
          'type' => 'select',
          'label' => 'Hover Animation',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'background_overlay_animation_duration',
          'type' => 'slider',
          'label' => 'Animation Duration (ms)',
          'default' =>
          array (
            'size' => 800,
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
          'group' => 'background',
          'name' => 'overlay_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-gallery-item__overlay',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' => 'overlay_background_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-gallery-item:hover .elementor-gallery-item__overlay, {{WRAPPER}} .e-gallery-item:focus .elementor-gallery-item__overlay',
          'condition' => NULL,
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
    5 =>
    array (
      'id' => 'overlay_content_style',
      'label' => 'Content',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'content_alignment',
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
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'content_vertical_position',
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
        2 =>
        array (
          'key' => 'content_padding',
          'type' => 'slider',
          'label' => 'Padding',
          'default' =>
          array (
            'size' => 20,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'overlay_title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'overlay_title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'title_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'overlay_title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'heading_description',
          'type' => 'heading',
          'label' => 'Description',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'overlay_description!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'description_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'overlay_description!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'content_hover_animation',
          'type' => 'select',
          'label' => 'Hover Animation',
          'default' => 'fade-in',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'content_animation_duration',
          'type' => 'slider',
          'label' => 'Animation Duration (ms)',
          'default' =>
          array (
            'size' => 800,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'content_hover_animation!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'content_sequenced_animation',
          'type' => 'switcher',
          'label' => 'Sequenced Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'content_hover_animation!' => '',
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
          'selector' => '{{WRAPPER}} .elementor-gallery-item__title',
          'condition' =>
          array (
            'overlay_title!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'description_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-gallery-item__description',
          'condition' =>
          array (
            'overlay_description!' => '',
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
      'id' => 'filter_bar_style',
      'label' => 'Filter Bar',
      'tab' => 'style',
      'condition' =>
      array (
        'gallery_type' => 'multiple',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'align_filter_bar_items',
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
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'galleries_title_color_normal',
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
          'key' => 'galleries_title_color_hover',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'pointer!' => 'background',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'galleries_title_color_hover_pointer_bg',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '#fff',
          'options' => NULL,
          'condition' =>
          array (
            'pointer' => 'background',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'galleries_pointer_color_hover',
          'type' => 'color',
          'label' => 'Pointer Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'pointer!' =>
            array (
              0 => 'none',
              1 => 'text',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'galleries_title_color_active',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'galleries_pointer_color_active',
          'type' => 'color',
          'label' => 'Pointer Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'pointer!' =>
            array (
              0 => 'none',
              1 => 'text',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'pointer_width',
          'type' => 'slider',
          'label' => 'Pointer Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'pointer' =>
            array (
              0 => 'underline',
              1 => 'overline',
              2 => 'double-line',
              3 => 'framed',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'galleries_titles_space_between',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'galleries_titles_gap',
          'type' => 'slider',
          'label' => 'Gap',
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
          'group' => 'typography',
          'name' => 'galleries_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-gallery-title',
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
      'name' => 'thumbnail_image',
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'css-filter',
      'name' => 'image_css_filters',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-gallery-image',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'css-filter',
      'name' => 'image_css_filters_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-gallery-item:hover .e-gallery-image',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'background',
      'name' => 'overlay_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-gallery-item__overlay',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'background',
      'name' => 'overlay_background_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-gallery-item:hover .elementor-gallery-item__overlay, {{WRAPPER}} .e-gallery-item:focus .elementor-gallery-item__overlay',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-gallery-item__title',
      'condition' =>
      array (
        'overlay_title!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'description_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-gallery-item__description',
      'condition' =>
      array (
        'overlay_description!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'typography',
      'name' => 'galleries_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-gallery-title',
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
          'key' => 'gallery_title',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'New Gallery',
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
          'key' => 'multiple_gallery',
          'type' => 'gallery',
          'label' => NULL,
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
    'gallery_type' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => 'single',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gallery' =>
    array (
      'section' => 'settings',
      'type' => 'gallery',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'gallery_type' => 'single',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries' =>
    array (
      'section' => 'settings',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'gallery_title' => 'New Gallery',
        ),
      ),
      'responsive' => false,
      'condition' =>
      array (
        'gallery_type' => 'multiple',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_by' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lazyload' =>
    array (
      'section' => 'settings',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gallery_layout' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => 'grid',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'columns' =>
    array (
      'section' => 'settings',
      'type' => 'number',
      'default' => 4,
      'responsive' => true,
      'condition' =>
      array (
        'gallery_layout!' => 'justified',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ideal_row_height' =>
    array (
      'section' => 'settings',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 200,
      ),
      'responsive' => true,
      'condition' =>
      array (
        'gallery_layout' => 'justified',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gap' =>
    array (
      'section' => 'settings',
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
    'link_to' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => 'file',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'url' =>
    array (
      'section' => 'settings',
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
      'section' => 'settings',
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
    'aspect_ratio' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => '3:2',
      'responsive' => false,
      'condition' =>
      array (
        'gallery_layout' => 'grid',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'thumbnail_image_image_size' =>
    array (
      'section' => 'settings',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'image-size',
      'group_prefix' => 'thumbnail_image',
    ),
    'show_all_galleries' =>
    array (
      'section' => 'section_filter_bar_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_all_galleries_label' =>
    array (
      'section' => 'section_filter_bar_content',
      'type' => 'text',
      'default' => 'All',
      'responsive' => false,
      'condition' =>
      array (
        'show_all_galleries' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pointer' =>
    array (
      'section' => 'section_filter_bar_content',
      'type' => 'select',
      'default' => 'underline',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_line' =>
    array (
      'section' => 'section_filter_bar_content',
      'type' => 'select',
      'default' => 'fade',
      'responsive' => false,
      'condition' =>
      array (
        'pointer' =>
        array (
          0 => 'underline',
          1 => 'overline',
          2 => 'double-line',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_framed' =>
    array (
      'section' => 'section_filter_bar_content',
      'type' => 'select',
      'default' => 'fade',
      'responsive' => false,
      'condition' =>
      array (
        'pointer' => 'framed',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_background' =>
    array (
      'section' => 'section_filter_bar_content',
      'type' => 'select',
      'default' => 'fade',
      'responsive' => false,
      'condition' =>
      array (
        'pointer' => 'background',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_text' =>
    array (
      'section' => 'section_filter_bar_content',
      'type' => 'select',
      'default' => 'grow',
      'responsive' => false,
      'condition' =>
      array (
        'pointer' => 'text',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'overlay_background' =>
    array (
      'section' => 'overlay',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'overlay_title' =>
    array (
      'section' => 'overlay',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'overlay_description' =>
    array (
      'section' => 'overlay',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_color' =>
    array (
      'section' => 'image_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_width' =>
    array (
      'section' => 'image_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_radius' =>
    array (
      'section' => 'image_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_color_hover' =>
    array (
      'section' => 'image_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_radius_hover' =>
    array (
      'section' => 'image_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_hover_animation' =>
    array (
      'section' => 'image_style',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_animation_duration' =>
    array (
      'section' => 'image_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 800,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_css_filters_css_filter' =>
    array (
      'section' => 'image_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'image_css_filters',
    ),
    'image_css_filters_hover_css_filter' =>
    array (
      'section' => 'image_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'image_css_filters_hover',
    ),
    'image_blend_mode' =>
    array (
      'section' => 'overlay_style',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_overlay_hover_animation' =>
    array (
      'section' => 'overlay_style',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_overlay_animation_duration' =>
    array (
      'section' => 'overlay_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 800,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'overlay_background_background' =>
    array (
      'section' => 'overlay_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'overlay_background',
    ),
    'overlay_background_hover_background' =>
    array (
      'section' => 'overlay_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'overlay_background_hover',
    ),
    'content_alignment' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_vertical_position' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_padding' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 20,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_title' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'overlay_title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'overlay_title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_spacing' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'overlay_title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_description' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'overlay_description!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_color' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'overlay_description!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_hover_animation' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'select',
      'default' => 'fade-in',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_animation_duration' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 800,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'content_hover_animation!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_sequenced_animation' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'content_hover_animation!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_typography_typography' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'overlay_title!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'title_typography',
    ),
    'description_typography_typography' =>
    array (
      'section' => 'overlay_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'overlay_description!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'description_typography',
    ),
    'align_filter_bar_items' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_title_color_normal' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_title_color_hover' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'pointer!' => 'background',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_title_color_hover_pointer_bg' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'color',
      'default' => '#fff',
      'responsive' => false,
      'condition' =>
      array (
        'pointer' => 'background',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_pointer_color_hover' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'pointer!' =>
        array (
          0 => 'none',
          1 => 'text',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_title_color_active' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_pointer_color_active' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'pointer!' =>
        array (
          0 => 'none',
          1 => 'text',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pointer_width' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'pointer' =>
        array (
          0 => 'underline',
          1 => 'overline',
          2 => 'double-line',
          3 => 'framed',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_titles_space_between' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_titles_gap' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'galleries_titles_typography_typography' =>
    array (
      'section' => 'filter_bar_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'galleries_titles_typography',
    ),
  ),
  'group_activators' =>
  array (
    'thumbnail_image_image_size' => 'custom',
    'image_css_filters_css_filter' => 'custom',
    'image_css_filters_hover_css_filter' => 'custom',
    'overlay_background_background' => 'classic',
    'overlay_background_hover_background' => 'classic',
    'title_typography_typography' => 'custom',
    'description_typography_typography' => 'custom',
    'galleries_titles_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
    0 => 'gallery',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/gallery.md',
    1 => 'docs/knowledge/elementor/widgets/gallery.md',
    2 => 'docs/knowledge/elementor/widgets/gallery-widget.md',
    3 => 'docs/knowledge/elementor/widgets/gallery-widget.md',
  ),
  'control_count' => 62,
);
