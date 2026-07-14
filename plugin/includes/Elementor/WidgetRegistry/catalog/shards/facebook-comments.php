<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'facebook-comments',
  'source' => 'pro',
  'widget_type' => 'facebook-comments',
  'title' => 'Facebook Comments',
  'icon' => 'eicon-facebook-comments',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'facebook',
    1 => 'comments',
    2 => 'embed',
  ),
  'file' => 'pro-elements/modules/social/widgets/facebook-comments.php',
  'intent' => 'Add Facebook Comments to the end of your posts to allow your readers to easily comment using their Facebook account.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Comment Count – Set the number of comments displayed in the page. You may enter a numeric value or use Dynamic Tags in the field',
    1 => 'Order By – Sort the comments order according to the Facebook Social factor, Time or Reverse Time',
    2 => 'Target URL – Get the Comments of the Current Page, or for a Custom URL',
    3 => 'URL Format – Select Plain Permalink or Pretty Permalink',
    4 => 'Note – Set your Facebook App ID in the Elementor Settings > Integrations tab.',
    5 => 'Note – If you are developing for European based sites, users must accept cookies, and be currently logged in to Facebook before the like, share, or comment buttons are displayed on the front end.',
  ),
  'limits' =>
  array (
    0 => 'Set your Facebook App ID in the Elementor Settings > Integrations tab.',
    1 => 'If you are developing for European based sites, users must accept cookies, and be currently logged in to Facebook before the like, share, or comment buttons are displayed on the front end.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_content',
      'label' => 'Comments Box',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'comments_number',
          'type' => 'number',
          'label' => 'Comment Count',
          'default' => '10',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => 'Minimum number of comments: 5',
        ),
        1 =>
        array (
          'key' => 'order_by',
          'type' => 'select',
          'label' => 'Order By',
          'default' => 'social',
          'options' =>
          array (
            'social' => 'Social',
            'reverse_time' => 'Reverse Time',
            'time' => 'Time',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'url_type',
          'type' => 'select',
          'label' => 'Target URL',
          'default' =>
          array (
            '__unresolved__' => 'Module::URL_TYPE_CURRENT_PAGE',
          ),
          'options' =>
          array (
            0 => 'Current Page',
            1 => 'Custom',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'url_format',
          'type' => 'select',
          'label' => 'URL Format',
          'default' =>
          array (
            '__unresolved__' => 'Module::URL_FORMAT_PLAIN',
          ),
          'options' =>
          array (
            0 => 'Plain Permalink',
            1 => 'Pretty Permalink',
          ),
          'condition' =>
          array (
            'url_type' =>
            array (
              '__unresolved__' => 'Module::URL_TYPE_CURRENT_PAGE',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'url',
          'type' => NULL,
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'url_type' =>
            array (
              '__unresolved__' => 'Module::URL_TYPE_CUSTOM',
            ),
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
  ),
  'group_controls' =>
  array (
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'comments_number' =>
    array (
      'section' => 'section_content',
      'type' => 'number',
      'default' => '10',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_by' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'social',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'url_type' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' =>
      array (
        '__unresolved__' => 'Module::URL_TYPE_CURRENT_PAGE',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'url_format' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' =>
      array (
        '__unresolved__' => 'Module::URL_FORMAT_PLAIN',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'url_type' =>
        array (
          '__unresolved__' => 'Module::URL_TYPE_CURRENT_PAGE',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'url' =>
    array (
      'section' => 'section_content',
      'type' => NULL,
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'url_type' =>
        array (
          '__unresolved__' => 'Module::URL_TYPE_CUSTOM',
        ),
      ),
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
    0 => 'docs/knowledge/elementor/widgets/facebook-comments.md',
    1 => 'docs/knowledge/elementor/widgets/facebook-comments.md',
    2 => 'docs/knowledge/elementor/widgets/facebook-comments-widget.md',
    3 => 'docs/knowledge/elementor/widgets/facebook-comments-widget.md',
  ),
  'control_count' => 5,
);
