<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'facebook-page',
  'source' => 'pro',
  'widget_type' => 'facebook-page',
  'title' => 'Facebook Page',
  'icon' => 'eicon-fb-feed',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'facebook',
    1 => 'social',
    2 => 'embed',
    3 => 'page',
  ),
  'file' => 'pro-elements/modules/social/widgets/facebook-page.php',
  'intent' => 'Add your Facebook Page Feed (previously named Like box) to your site.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'URL – Type your Facbook Page URL',
    1 => 'Layout – Choose between Timeline, Events and Messages',
    2 => 'Small header – Displays a Facebook Header in a narrow view',
    3 => 'Cover – Show / hide the cover image',
    4 => 'Profile photos – Show photos of people who liked your page',
    5 => 'Custom CTA button – Choose between the page’s official CTA button, or the default Share Button',
    6 => 'Height – Set the height of the box (desktop, tablet and mobile)',
    7 => 'Note – If you are developing for European based sites, users must accept cookies, and be currently logged in to Facebook before the like, share, or comment buttons are displayed on the front end.',
  ),
  'limits' =>
  array (
    0 => 'If you are developing for European based sites, users must accept cookies, and be currently logged in to Facebook before the like, share, or comment buttons are displayed on the front end.',
    1 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    2 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_content',
      'label' => 'Page',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'widget_exclusively_web',
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
          'key' => 'url',
          'type' => NULL,
          'label' => 'Link',
          'default' => 'https://www.facebook.com/elemntor/',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Paste the URL of the Facebook page.',
        ),
        2 =>
        array (
          'key' => 'tabs',
          'type' => 'select2',
          'label' => 'Layout',
          'default' =>
          array (
            0 => 'timeline',
          ),
          'options' =>
          array (
            'timeline' => 'Timeline',
            'events' => 'Events',
            'messages' => 'Messages',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'small_header',
          'type' => 'switcher',
          'label' => 'Small Header',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'show_cover',
          'type' => 'switcher',
          'label' => 'Cover Photo',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'show_facepile',
          'type' => 'switcher',
          'label' => 'Profile Photos',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'show_cta',
          'type' => 'switcher',
          'label' => 'Custom CTA Button',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'height',
          'type' => 'slider',
          'label' => 'Height',
          'default' =>
          array (
            'size' => 500,
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
    'widget_exclusively_web' =>
    array (
      'section' => 'section_content',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'url' =>
    array (
      'section' => 'section_content',
      'type' => NULL,
      'default' => 'https://www.facebook.com/elemntor/',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs' =>
    array (
      'section' => 'section_content',
      'type' => 'select2',
      'default' =>
      array (
        0 => 'timeline',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'small_header' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_cover' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_facepile' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_cta' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'height' =>
    array (
      'section' => 'section_content',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 500,
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
    0 => 'docs/knowledge/elementor/widgets/facebook-page-feed-pro.md',
    1 => 'docs/knowledge/elementor/widgets/facebook-page-feed.md',
  ),
  'control_count' => 8,
);
