<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'google_maps',
  'source' => 'free',
  'widget_type' => 'google_maps',
  'title' => 'Google Maps',
  'icon' => 'eicon-google-maps',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
    0 => 'google',
    1 => 'map',
    2 => 'embed',
    3 => 'location',
  ),
  'file' => 'elementor/includes/widgets/google-maps.php',
  'intent' => 'The XXXX widget is an experimental feature that XXX In order to use XXX widget, you need to activate it. For details, see activating Elementor features. Add the widget Add the widget to the canvas',
  'use_cases' =>
  array (
    0 => 'See all the options available with the Google Maps widget',
    1 => 'Common use case',
    2 => 'The want to add a digital map to the front page to help guide new customers to the restaurant',
    3 => 'A vibrant oasis of plant-based delights, our vegan restaurant offers a culinary journey that nourishes both body and soul',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'A vibrant oasis of plant – based delights, our vegan restaurant offers a culinary journey that nourishes both body and soul. Savor a harmonious blend of flavors, textures, and creativity, all crafted with the finest organic ingredients and a commitment to sustainable practices.',
    2 => 'Adding a Google Maps widget – Step-by-step',
    3 => 'Normal – Determine how the map appears by default.',
    4 => 'Hover – Determine how the map appears when moused over.',
    5 => 'Blur – Applies a soft focus effect by blurring elements based on pixel radius.',
    6 => 'Brightness – Adjusts the brightness of an element by modifying its light intensity.',
    7 => 'Contrast – Enhances or reduces image and element color contrast for better visual distinction.',
  ),
  'limits' =>
  array (
    0 => 'In order to use the Google Maps Widget, you must first create an API key. This requires setting up a Google account. For more details, refer to Google’s documentation.',
    1 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    2 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_map',
      'label' => 'Google Maps',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'api_key_notification',
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
          'key' => 'address',
          'type' => 'text',
          'label' => 'Location',
          'default' =>
          array (
            '__unresolved__' => '$default_address',
          ),
          'options' => NULL,
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
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'zoom',
          'type' => 'slider',
          'label' => 'Zoom',
          'default' =>
          array (
            'size' => 10,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
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
    1 =>
    array (
      'id' => 'section_map_style',
      'label' => 'Google Maps',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'hover_transition',
          'type' => 'slider',
          'label' => 'Transition Duration (s)',
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
        0 =>
        array (
          'group' => 'css-filter',
          'name' => 'css_filters',
          'label' => NULL,
          'selector' => '{{WRAPPER}} iframe',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'css-filter',
          'name' => 'css_filters_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}}:hover iframe',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
  ),
  'group_controls' =>
  array (
    0 =>
    array (
      'group' => 'css-filter',
      'name' => 'css_filters',
      'label' => NULL,
      'selector' => '{{WRAPPER}} iframe',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'css-filter',
      'name' => 'css_filters_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}}:hover iframe',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'api_key_notification' =>
    array (
      'section' => 'section_map',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'address' =>
    array (
      'section' => 'section_map',
      'type' => 'text',
      'default' =>
      array (
        '__unresolved__' => '$default_address',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'zoom' =>
    array (
      'section' => 'section_map',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 10,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'height' =>
    array (
      'section' => 'section_map',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_transition' =>
    array (
      'section' => 'section_map_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'css_filters_css_filter' =>
    array (
      'section' => 'section_map_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'css_filters',
    ),
    'css_filters_hover_css_filter' =>
    array (
      'section' => 'section_map_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'css_filters_hover',
    ),
  ),
  'group_activators' =>
  array (
    'css_filters_css_filter' => 'custom',
    'css_filters_hover_css_filter' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/google-maps.md',
    1 => 'docs/knowledge/elementor/widgets/google-maps-widget.md',
  ),
  'control_count' => 7,
);
