<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'html',
  'source' => 'free',
  'widget_type' => 'html',
  'title' => 'HTML',
  'icon' => 'eicon-code',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'html',
    1 => 'code',
    2 => 'embed',
    3 => 'script',
  ),
  'file' => 'elementor/includes/widgets/html.php',
  'intent' => 'Inside the HTML Widget you can embed HTML, CSS, Shortcodes, and also include JS scripts.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Note – Elementor Pro subscribers can use Elementor AI to create Custom CSS and Custom Code.',
    1 => 'Select Code with AI.Note – After your first use of Elementor AI, the Code with AI text will be replaced with the Elementor AI icon.',
    2 => 'Content options – Configure general content, title, tags, and icons.',
    3 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    4 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Elementor Pro subscribers can use Elementor AI to create Custom CSS and Custom Code.',
    1 => 'Select Code with AI.Note: After your first use of Elementor AI, the Code with AI text will be replaced with the Elementor AI icon.',
    2 => 'Select the prompt “Write a embed code for Google ads as HTML widget”Note: You could also enter your own request in the input box.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_title',
      'label' => 'HTML Code',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'html',
          'type' => 'code',
          'label' => 'HTML Code',
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
    'html' =>
    array (
      'section' => 'section_title',
      'type' => 'code',
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
    0 => 'docs/knowledge/elementor/widgets/html-widget.md',
    1 => 'docs/knowledge/elementor/widgets/html-widget.md',
  ),
  'control_count' => 1,
);
