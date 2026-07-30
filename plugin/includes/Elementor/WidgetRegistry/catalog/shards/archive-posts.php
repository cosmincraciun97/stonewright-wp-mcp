<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'archive-posts',
  'source' => 'pro',
  'widget_type' => 'archive-posts',
  'title' => 'Archive Posts',
  'icon' => 'eicon-archive-posts',
  'categories' =>
  array (
    0 => 'theme-elements-archive',
  ),
  'keywords' =>
  array (
    0 => 'posts',
    1 => 'cpt',
    2 => 'archive',
    3 => 'loop',
    4 => 'query',
    5 => 'cards',
    6 => 'custom post type',
  ),
  'file' => 'pro-elements/modules/theme-builder/widgets/archive-posts.php',
  'intent' => 'The Archive Posts Widget displays a list of any posts within Archive templates, which are created in Templates > Theme Builder > Archive.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Note – The number of posts per page is controlled from the WordPress Reading settings (Dashboard > Settings > Reading)',
    1 => 'Skin – Select a pre-designed skin, either Classic or Cards',
    2 => 'Columns – Set how many columns will be displayed, from 1 to 6',
    3 => 'Image Position – Set the image position, relative to the content. Options include: Top / left / right / none',
    4 => 'Masonry – Slide on or off',
    5 => 'Image Resolution – Set the size of the image, from thumbnail to full',
    6 => 'Image Ratio – Set the exact ratio of the images',
    7 => 'Image Width – Set the exact width of the images',
  ),
  'limits' =>
  array (
    0 => 'The number of posts per page is controlled from the WordPress Reading settings (Dashboard > Settings > Reading)',
    1 => 'Badge (Only available if Cards skin is selected): Slide to YES to show badge',
    2 => 'Badge Taxonomy (Only available if Cards skin is selected): Select the taxonomy to be used for the badge, choosing from Categories, Tags, etc.',
    3 => 'Avatar (Only available if Cards skin is selected): Show or Hide the Author avatar',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_advanced',
      'label' => 'Advanced',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'nothing_found_message',
          'type' => 'textarea',
          'label' => 'Nothing Found Message',
          'default' => 'It seems we can’t find what you’re looking for.',
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
      'id' => 'section_nothing_found_style',
      'label' => 'Nothing Found Message',
      'tab' => 'style',
      'condition' =>
      array (
        'nothing_found_message!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'nothing_found_color',
          'type' => 'color',
          'label' => 'Color',
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
          'name' => 'nothing_found_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-posts-nothing-found',
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
      'name' => 'nothing_found_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-posts-nothing-found',
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
    'nothing_found_message' =>
    array (
      'section' => 'section_advanced',
      'type' => 'textarea',
      'default' => 'It seems we can’t find what you’re looking for.',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_color' =>
    array (
      'section' => 'section_nothing_found_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_typography_typography' =>
    array (
      'section' => 'section_nothing_found_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'nothing_found_typography',
    ),
  ),
  'group_activators' =>
  array (
    'nothing_found_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/archive-posts-widget-pro.md',
    1 => 'docs/knowledge/elementor/widgets/archive-posts-widget-pro.md',
  ),
  'control_count' => 3,
);
