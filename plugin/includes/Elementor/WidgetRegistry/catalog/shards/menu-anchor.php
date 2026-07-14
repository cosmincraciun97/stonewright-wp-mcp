<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'menu-anchor',
  'source' => 'free',
  'widget_type' => 'menu-anchor',
  'title' => 'Menu Anchor',
  'icon' => 'eicon-anchor',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'menu',
    1 => 'anchor',
    2 => 'link',
  ),
  'file' => 'elementor/includes/widgets/menu-anchor.php',
  'intent' => 'The Menu Anchor widget allows you to create a page with internal smooth scrolling navigation.',
  'use_cases' =>
  array (
    0 => 'The Menu Anchor widget allows you to create a page with internal smooth scrolling navigation',
    1 => 'Organizing your layout design and structuring content elements inside Elementor.',
    2 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    3 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Give the anchor a name. (ie – contact-us)',
    1 => 'Type ‘#’ + the anchor name (ie – #contact-us) in the URL link field of the menu item or widget element. Please note The ID link ONLY accepts these chars: `A-Z, a-z, 0-9, _ , -`',
    2 => 'Note – The widget takes up no actual space and is invisible to the visitor.',
    3 => 'Content options – Configure general content, title, tags, and icons.',
    4 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    5 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Type ‘#’ + the anchor name (ie: #contact-us) in the URL link field of the menu item or widget element. Please note The ID link ONLY accepts these chars: `A-Z, a-z, 0-9, _ , -`',
    1 => 'The widget takes up no actual space and is invisible to the visitor.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_anchor',
      'label' => 'Menu Anchor',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'anchor',
          'type' => 'text',
          'label' => 'The ID of Menu Anchor.',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => 'This ID will be the CSS ID you will have to use in your own page, Without #.',
        ),
        1 =>
        array (
          'key' => 'anchor_note',
          'type' => 'alert',
          'label' => NULL,
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
    'anchor' =>
    array (
      'section' => 'section_anchor',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'anchor_note' =>
    array (
      'section' => 'section_anchor',
      'type' => 'alert',
      'default' => NULL,
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
    0 => 'docs/knowledge/elementor/widgets/menu-anchor-widget.md',
    1 => 'docs/knowledge/elementor/widgets/menu-anchor-widget.md',
  ),
  'control_count' => 2,
);
