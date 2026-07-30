<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'spacer',
  'source' => 'free',
  'widget_type' => 'spacer',
  'title' => 'Spacer',
  'icon' => 'eicon-spacer',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
    0 => 'space',
  ),
  'file' => 'elementor/includes/widgets/spacer.php',
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
    0 => 'Add the Spacer widget – Step-by-step',
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
      'id' => 'section_spacer',
      'label' => 'Spacer',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'space',
          'type' => 'slider',
          'label' => 'Space',
          'default' =>
          array (
            'size' => 50,
          ),
          'options' => NULL,
          'condition' => NULL,
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
  ),
  'group_controls' =>
  array (
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'space' =>
    array (
      'section' => 'section_spacer',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 50,
      ),
      'responsive' => true,
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
    0 => 'space',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/spacer-widget.md',
    1 => 'docs/knowledge/elementor/widgets/spacer-widget.md',
  ),
  'control_count' => 1,
);
