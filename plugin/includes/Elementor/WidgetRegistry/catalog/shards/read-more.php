<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'read-more',
  'source' => 'free',
  'widget_type' => 'read-more',
  'title' => 'Read More',
  'icon' => 'eicon-post-excerpt',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'read',
    1 => 'more',
    2 => 'tag',
    3 => 'excerpt',
  ),
  'file' => 'elementor/includes/widgets/read-more.php',
  'intent' => 'Add the widget Add the widget to the canvas',
  'use_cases' =>
  array (
    0 => 'In Elementor Editor, click +',
    1 => 'All available widgets are displayed',
    2 => 'Click or drag the widget to the canvas',
    3 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Add the Read More widget – Step-by-step',
    1 => 'Content options – Configure general content, title, tags, and icons.',
    2 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    3 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
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
      'id' => 'section_title',
      'label' => 'Read More',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'theme_support',
          'type' => 'alert',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'link_text',
          'type' => NULL,
          'label' => 'Read More Text',
          'default' =>
          array (
            '__unresolved__' => '$default_link_text',
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
    'theme_support' =>
    array (
      'section' => 'section_title',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_text' =>
    array (
      'section' => 'section_title',
      'type' => NULL,
      'default' =>
      array (
        '__unresolved__' => '$default_link_text',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/read-more-widget.md',
    1 => 'docs/knowledge/elementor/widgets/read-more-widget.md',
  ),
  'control_count' => 2,
);
