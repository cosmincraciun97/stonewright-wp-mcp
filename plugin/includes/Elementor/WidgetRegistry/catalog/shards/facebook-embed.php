<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'facebook-embed',
  'source' => 'pro',
  'widget_type' => 'facebook-embed',
  'title' => 'Facebook Embed',
  'icon' => 'eicon-fb-embed',
  'categories' =>
  array (
    0 => 'pro-elements',
  ),
  'keywords' =>
  array (
    0 => 'facebook',
    1 => 'social',
    2 => 'embed',
    3 => 'video',
    4 => 'post',
    5 => 'comment',
  ),
  'file' => 'pro-elements/modules/social/widgets/facebook-embed.php',
  'intent' => 'The Facebook Embed widget lets you easily embed Facebook posts, videos or comments on your website’s page.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'URL – Choose which URL will be embedded in the page',
    1 => 'Full post – Switch ON to show full text of the post, or OFF to show the shorter post text',
    2 => 'Full Post – Switch ON to show full text of the post, or OFF to show the shorter post text',
    3 => 'Allow Full Screen – Switch ON to allow video in Full Screen',
    4 => 'Autoplay – Switch ON to automatically play the video',
    5 => 'Captions – Switch ON to show the Captions of the video if available (shown only on desktop devices)',
  ),
  'limits' =>
  array (
    0 => 'Autoplay is affected by Google’s Autoplay policy on Chrome browsers.',
    1 => 'If you are developing for European based sites, users must accept cookies, and be currently logged in to Facebook before the like, share, or comment buttons are displayed on the front end.',
    2 => 'This widget will not appear in the Mozilla browser if “Strict Mode” is enabled.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_content',
      'label' => 'Embed',
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
          'key' => 'type',
          'type' => 'select',
          'label' => 'Type',
          'default' => 'post',
          'options' =>
          array (
            'post' => 'Post',
            'video' => 'Video',
            'comment' => 'Comment',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'post_url',
          'type' => NULL,
          'label' => 'URL',
          'default' => 'https://www.facebook.com/elemntor/posts/2624214124556197',
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'post',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => 'Hover over the date next to the post, and copy its link address.',
        ),
        3 =>
        array (
          'key' => 'video_url',
          'type' => NULL,
          'label' => 'URL',
          'default' => 'https://www.facebook.com/elemntor/videos/1683988961912056/',
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'video',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => 'Hover over the date next to the video, and copy its link address.',
        ),
        4 =>
        array (
          'key' => 'comment_url',
          'type' => NULL,
          'label' => 'URL',
          'default' => 'https://www.facebook.com/elemntor/videos/1811703749140576/?comment_id=1812873919023559',
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'comment',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => 'Hover over the date next to the comment, and copy its link address.',
        ),
        5 =>
        array (
          'key' => 'include_parent',
          'type' => 'switcher',
          'label' => 'Parent Comment',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'comment',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Set to include parent comment (if URL is a reply).',
        ),
        6 =>
        array (
          'key' => 'show_text',
          'type' => 'switcher',
          'label' => 'Full Post',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'type' =>
            array (
              0 => 'post',
              1 => 'video',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Show the full text of the post',
        ),
        7 =>
        array (
          'key' => 'video_allowfullscreen',
          'type' => 'switcher',
          'label' => 'Allow Full Screen',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'video',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'video_autoplay',
          'type' => 'switcher',
          'label' => 'Autoplay',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'video',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'video_show_captions',
          'type' => 'switcher',
          'label' => 'Captions',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'video',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Show captions if available (only on desktop).',
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
    'type' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'post',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'post_url' =>
    array (
      'section' => 'section_content',
      'type' => NULL,
      'default' => 'https://www.facebook.com/elemntor/posts/2624214124556197',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'post',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'video_url' =>
    array (
      'section' => 'section_content',
      'type' => NULL,
      'default' => 'https://www.facebook.com/elemntor/videos/1683988961912056/',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'video',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'comment_url' =>
    array (
      'section' => 'section_content',
      'type' => NULL,
      'default' => 'https://www.facebook.com/elemntor/videos/1811703749140576/?comment_id=1812873919023559',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'comment',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'include_parent' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'comment',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_text' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'type' =>
        array (
          0 => 'post',
          1 => 'video',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'video_allowfullscreen' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'video',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'video_autoplay' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'video',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'video_show_captions' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'video',
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
    0 => 'docs/knowledge/elementor/widgets/facebook-embed.md',
    1 => 'docs/knowledge/elementor/widgets/facebook-embed.md',
    2 => 'docs/knowledge/elementor/widgets/facebook-embed-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/facebook-embed-widget-pro.md',
  ),
  'control_count' => 10,
);
