<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'image-gallery',
  'source' => 'free',
  'widget_type' => 'image-gallery',
  'title' => 'Basic Gallery',
  'icon' => 'eicon-gallery-grid',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'image',
    1 => 'photo',
    2 => 'visual',
    3 => 'gallery',
  ),
  'file' => 'elementor/includes/widgets/image-gallery.php',
  'intent' => NULL,
  'use_cases' =>
  array (
  ),
  'settings_highlights' =>
  array (
  ),
  'limits' =>
  array (
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_gallery',
      'label' => 'Basic Gallery',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'wp_gallery',
          'type' => 'gallery',
          'label' => 'Add Images',
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
          'key' => 'gallery_columns',
          'type' => 'select',
          'label' => 'Columns',
          'default' => 4,
          'options' =>
          array (
            '__unresolved__' => '$gallery_columns',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'gallery_display_caption',
          'type' => 'select',
          'label' => 'Caption',
          'default' => '',
          'options' =>
          array (
            'none' => 'None',
            '' => 'Attachment Caption',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'gallery_link',
          'type' => 'select',
          'label' => 'Link',
          'default' => 'file',
          'options' =>
          array (
            'file' => 'Media File',
            'attachment' => 'Attachment Page',
            'none' => 'None',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
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
            'gallery_link' => 'file',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' =>
          array (
            '__unresolved__' => 'sprintf()',
          ),
        ),
        5 =>
        array (
          'key' => 'gallery_rand',
          'type' => 'select',
          'label' => 'Order By',
          'default' => '',
          'options' =>
          array (
            '' => 'Default',
            'rand' => 'Random',
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
          'exclude' =>
          array (
            0 => 'custom',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    1 =>
    array (
      'id' => 'section_gallery_images',
      'label' => 'Images',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'image_spacing',
          'type' => 'select',
          'label' => 'Gap',
          'default' => '',
          'options' =>
          array (
            '' => 'Default',
            'custom' => 'Custom',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'image_spacing_custom',
          'type' => 'slider',
          'label' => 'Custom Gap',
          'default' =>
          array (
            'size' => 15,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'image_spacing' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
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
          'selector' => '{{WRAPPER}} .gallery-item img',
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
      'id' => 'section_caption',
      'label' => 'Caption',
      'tab' => 'style',
      'condition' =>
      array (
        'gallery_display_caption' => '',
      ),
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
            'justify' =>
            array (
              'title' => 'Justified',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' =>
          array (
            'gallery_display_caption' => '',
          ),
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
          'condition' =>
          array (
            'gallery_display_caption' => '',
          ),
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
          'condition' =>
          array (
            'gallery_display_caption' => '',
          ),
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
          'name' => 'typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .gallery-item .gallery-caption',
          'condition' =>
          array (
            'gallery_display_caption' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'caption_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .gallery-item .gallery-caption',
          'condition' =>
          array (
            'gallery_display_caption' => '',
          ),
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
      'exclude' =>
      array (
        0 => 'custom',
      ),
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'border',
      'name' => 'image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .gallery-item img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .gallery-item .gallery-caption',
      'condition' =>
      array (
        'gallery_display_caption' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'text-shadow',
      'name' => 'caption_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .gallery-item .gallery-caption',
      'condition' =>
      array (
        'gallery_display_caption' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'wp_gallery' =>
    array (
      'section' => 'section_gallery',
      'type' => 'gallery',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gallery_columns' =>
    array (
      'section' => 'section_gallery',
      'type' => 'select',
      'default' => 4,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gallery_display_caption' =>
    array (
      'section' => 'section_gallery',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gallery_link' =>
    array (
      'section' => 'section_gallery',
      'type' => 'select',
      'default' => 'file',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'open_lightbox' =>
    array (
      'section' => 'section_gallery',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' =>
      array (
        'gallery_link' => 'file',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'gallery_rand' =>
    array (
      'section' => 'section_gallery',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'thumbnail_image_size' =>
    array (
      'section' => 'section_gallery',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'image-size',
      'group_prefix' => 'thumbnail',
    ),
    'image_spacing' =>
    array (
      'section' => 'section_gallery_images',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_spacing_custom' =>
    array (
      'section' => 'section_gallery_images',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 15,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'image_spacing' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_radius' =>
    array (
      'section' => 'section_gallery_images',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_border' =>
    array (
      'section' => 'section_gallery_images',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'image_border',
    ),
    'align' =>
    array (
      'section' => 'section_caption',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => true,
      'condition' =>
      array (
        'gallery_display_caption' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color' =>
    array (
      'section' => 'section_caption',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'gallery_display_caption' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_space' =>
    array (
      'section' => 'section_caption',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'gallery_display_caption' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_typography' =>
    array (
      'section' => 'section_caption',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'gallery_display_caption' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'typography',
    ),
    'caption_shadow_text_shadow' =>
    array (
      'section' => 'section_caption',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'gallery_display_caption' => '',
      ),
      'group' => 'text-shadow',
      'group_prefix' => 'caption_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'thumbnail_image_size' => 'custom',
    'image_border_border' => 'solid',
    'typography_typography' => 'custom',
    'caption_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'wp_gallery',
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 16,
);
