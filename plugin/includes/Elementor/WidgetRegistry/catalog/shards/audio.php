<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'audio',
  'source' => 'free',
  'widget_type' => 'audio',
  'title' => 'SoundCloud',
  'icon' => 'eicon-headphones',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'audio',
    1 => 'player',
    2 => 'soundcloud',
    3 => 'embed',
  ),
  'file' => 'elementor/includes/widgets/audio.php',
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
      'id' => 'section_audio',
      'label' => 'SoundCloud',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' =>
          array (
            'url' => 'https://soundcloud.com/shchxango/john-coltrane-1963-my-favorite',
          ),
          'options' => false,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::POST_META_CATEGORY',
              ),
              1 =>
              array (
                '__unresolved__' => 'TagsModule::URL_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'visual',
          'type' => 'select',
          'label' => 'Visual Player',
          'default' => 'no',
          'options' =>
          array (
            'yes' => 'Yes',
            'no' => 'No',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'sc_options',
          'type' => 'heading',
          'label' => 'Additional Options',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'sc_auto_play',
          'type' => 'switcher',
          'label' => 'Autoplay',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'sc_buying',
          'type' => 'switcher',
          'label' => 'Buy Button',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'sc_liking',
          'type' => 'switcher',
          'label' => 'Like Button',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'sc_download',
          'type' => 'switcher',
          'label' => 'Download Button',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'sc_show_artwork',
          'type' => 'switcher',
          'label' => 'Artwork',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'visual' => 'no',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'sc_sharing',
          'type' => 'switcher',
          'label' => 'Share Button',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'sc_show_comments',
          'type' => 'switcher',
          'label' => 'Comments',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'sc_show_playcount',
          'type' => 'switcher',
          'label' => 'Play Counts',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'sc_show_user',
          'type' => 'switcher',
          'label' => 'Username',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'sc_color',
          'type' => 'color',
          'label' => 'Controls Color',
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
    'link' =>
    array (
      'section' => 'section_audio',
      'type' => 'url',
      'default' =>
      array (
        'url' => 'https://soundcloud.com/shchxango/john-coltrane-1963-my-favorite',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'visual' =>
    array (
      'section' => 'section_audio',
      'type' => 'select',
      'default' => 'no',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_options' =>
    array (
      'section' => 'section_audio',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_auto_play' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_buying' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_liking' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_download' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_show_artwork' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'visual' => 'no',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_sharing' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_show_comments' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_show_playcount' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_show_user' =>
    array (
      'section' => 'section_audio',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sc_color' =>
    array (
      'section' => 'section_audio',
      'type' => 'color',
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
  ),
  'control_count' => 13,
);
