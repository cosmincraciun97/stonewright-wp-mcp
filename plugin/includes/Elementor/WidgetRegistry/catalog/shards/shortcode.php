<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'shortcode',
  'source' => 'free',
  'widget_type' => 'shortcode',
  'title' => 'Shortcode',
  'icon' => 'eicon-shortcode',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'shortcode',
    1 => 'code',
  ),
  'file' => 'elementor/includes/widgets/shortcode.php',
  'intent' => 'Click or drag the widget to the canvas. For more information, see Add elements to a page.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Add a Shortcode widget – Step-by-step',
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
      'id' => 'section_shortcode',
      'label' => 'Shortcode',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'shortcode',
          'type' => 'textarea',
          'label' => 'Enter your shortcode',
          'default' => '',
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
    'shortcode' =>
    array (
      'section' => 'section_shortcode',
      'type' => 'textarea',
      'default' => '',
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
    0 => 'docs/knowledge/elementor/widgets/shortcode-widget.md',
    1 => 'docs/knowledge/elementor/widgets/shortcode-widget.md',
  ),
  'control_count' => 1,
);
