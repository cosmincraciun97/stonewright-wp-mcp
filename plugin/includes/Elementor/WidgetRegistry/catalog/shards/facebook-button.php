<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'facebook-button',
  'source' => 'pro',
  'widget_type' => 'facebook-button',
  'title' => 'Facebook Button',
  'icon' => 'eicon-facebook-like-box',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'facebook',
    1 => 'social',
    2 => 'embed',
    3 => 'button',
    4 => 'like',
    5 => 'share',
    6 => 'recommend',
    7 => 'follow',
  ),
  'file' => 'pro-elements/modules/social/widgets/facebook-button.php',
  'intent' => NULL,
  'use_cases' =>
  array (
  ),
  'settings_highlights' =>
  array (
  ),
  'limits' =>
  array (
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_content',
      'label' => 'Button',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'type',
          'type' => 'select',
          'label' => 'Type',
          'default' => 'like',
          'options' =>
          array (
            'like' => 'Like',
            'recommend' => 'Recommend',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'layout',
          'type' => 'select',
          'label' => 'Layout',
          'default' => 'standard',
          'options' =>
          array (
            'standard' => 'Standard',
            'button' => 'Button',
            'button_count' => 'Button Count',
            'box_count' => 'Box Count',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'size',
          'type' => 'select',
          'label' => 'Size',
          'default' => 'small',
          'options' =>
          array (
            'small' => 'Small',
            'large' => 'Large',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'color_scheme',
          'type' => 'select',
          'label' => 'Color Scheme',
          'default' => 'light',
          'options' =>
          array (
            'light' => 'Light',
            'dark' => 'Dark',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'show_share',
          'type' => 'switcher',
          'label' => 'Share Button',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'type!' => 'follow',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'show_faces',
          'type' => 'switcher',
          'label' => 'Faces',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
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
          'condition' =>
          array (
            'type' =>
            array (
              0 => 'like',
              1 => 'recommend',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
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
        8 =>
        array (
          'key' => 'url',
          'type' => NULL,
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'type' =>
            array (
              0 => 'like',
              1 => 'recommend',
            ),
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
    'type' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'like',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'layout' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'standard',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'size' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'small',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_scheme' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'light',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_share' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'type!' => 'follow',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_faces' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
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
      'condition' =>
      array (
        'type' =>
        array (
          0 => 'like',
          1 => 'recommend',
        ),
      ),
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
        'type' =>
        array (
          0 => 'like',
          1 => 'recommend',
        ),
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
  ),
  'control_count' => 9,
);
