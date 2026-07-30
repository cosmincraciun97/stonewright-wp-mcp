<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'sidebar',
  'source' => 'free',
  'widget_type' => 'sidebar',
  'title' => 'Sidebar',
  'icon' => 'eicon-sidebar',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'sidebar',
    1 => 'widget',
  ),
  'file' => 'elementor/includes/widgets/sidebar.php',
  'intent' => 'Select a sidebar to display on the page. Advanced tab The Advanced tab provides options to control widget position, adjust spacing, add custom code, and more. Advanced Advanced',
  'use_cases' =>
  array (
    0 => 'In Elementor Editor, click +',
    1 => 'All available widgets are displayed',
    2 => 'Click or drag the widget to the canvas',
    3 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Add a Sidebar widget – Step-by-step',
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
      'id' => 'section_sidebar',
      'label' => 'Sidebar',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'sidebar',
          'type' => 'select',
          'label' => 'Choose Sidebar',
          'default' =>
          array (
            '__unresolved__' => '$default_key',
          ),
          'options' =>
          array (
            '__unresolved__' => '$options',
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
  ),
  'group_controls' =>
  array (
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'sidebar' =>
    array (
      'section' => 'section_sidebar',
      'type' => 'select',
      'default' =>
      array (
        '__unresolved__' => '$default_key',
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
    0 => 'docs/knowledge/elementor/widgets/sidebar-widget.md',
    1 => 'docs/knowledge/elementor/widgets/sidebar-widget.md',
  ),
  'control_count' => 1,
);
