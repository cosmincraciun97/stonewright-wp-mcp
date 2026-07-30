<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'lottie',
  'source' => 'pro',
  'widget_type' => 'lottie',
  'title' => 'Lottie',
  'icon' => 'eicon-lottie',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
  ),
  'file' => 'pro-elements/modules/lottie/widgets/lottie.php',
  'intent' => 'Enter text to the text box to add a caption to your animation. If you are using an external URL, this is renamed Custom Caption as it overrides the caption of the external site. Link',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Lottie widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Adding a Lottie widget – Step-by-step​',
    2 => 'External URL – The Lottie animation is located on a different webpage. If you select External URL, you’ll need to enter the animation’s URl in the External URL field.Media File: Click the upload icon to add a json file to your website.',
    3 => 'Width – The minimum size of the animation is from side to side.Maximum Width: The maximum size of the animation is from side to side.',
    4 => 'Example of Width and Maximum Width – You can set the Width of an animation to 100% and the Maximum Width to 600 pixels. This means the animation will take up 100% of the parent container’s width but never be more than 600 pixels wide.',
    5 => 'Normal/HoverNormal – Determine how the animation appears by default. Hover: Determine how the animation appears when moused over. When Hover is selected, you’ll be able to set a Transition Duration. This is the amount of time it takes the animation to change when the user mouses over it.',
  ),
  'limits' =>
  array (
    0 => 'Lottie animations help make a webpage come alive and draw visitors attention.',
    1 => 'A travel website featuring an animated cyclist to attract attention to their bike tours.A web design agency showing a rocket ship taking off to symbolize how they launch their sites.',
    2 => 'You don’t have to play the entire Lottie animation. Use the Start and End Point sliders to limit how much of the animation plays.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'lottie',
      'label' => 'Lottie',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'source',
          'type' => 'select',
          'label' => 'Source',
          'default' => 'media_file',
          'options' =>
          array (
            '__unresolved__' => '->get_source_options()',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'source_external_url',
          'type' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
          ),
          'label' => 'External URL',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'source' => 'external_url',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'source_json',
          'type' => 'media',
          'label' => 'Upload JSON File',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'source' => 'media_file',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => 'center',
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-text-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'caption_source',
          'type' => 'select',
          'label' => 'Caption',
          'default' => 'none',
          'options' =>
          array (
            'none' => 'None',
            'title' => 'Title',
            'caption' => 'Caption',
            'custom' => 'Custom',
          ),
          'condition' =>
          array (
            'source!' => 'external_url',
            'source_json[url]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'caption',
          'type' => 'text',
          'label' => 'Custom Caption',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'caption_source',
                'value' => 'custom',
              ),
              1 =>
              array (
                'name' => 'source',
                'value' => 'external_url',
              ),
            ),
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'link_to',
          'type' => 'select',
          'label' => 'Link',
          'default' => 'none',
          'options' =>
          array (
            'none' => 'None',
            'custom' => 'Custom URL',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'custom_link',
          'type' => 'url',
          'label' => 'Link',
          'default' =>
          array (
            'url' => '',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'link_to' => 'custom',
          ),
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
    1 =>
    array (
      'id' => 'settings',
      'label' => 'Settings',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'trigger',
          'type' => 'select',
          'label' => 'Trigger',
          'default' => 'arriving_to_viewport',
          'options' =>
          array (
            'arriving_to_viewport' => 'Viewport',
            'on_click' => 'On Click',
            'on_hover' => 'On Hover',
            'bind_to_scroll' => 'Scroll',
            'none' => 'None',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'viewport',
          'type' => 'slider',
          'label' => 'Viewport',
          'default' =>
          array (
            'sizes' =>
            array (
              'start' => 0,
              'end' => 100,
            ),
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'trigger',
                'operator' => '===',
                'value' => 'arriving_to_viewport',
              ),
              1 =>
              array (
                'name' => 'trigger',
                'operator' => '===',
                'value' => 'bind_to_scroll',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'effects_relative_to',
          'type' => 'select',
          'label' => 'Effects Relative To',
          'default' => 'viewport',
          'options' =>
          array (
            'viewport' => 'Viewport',
            'page' => 'Entire Page',
          ),
          'condition' =>
          array (
            'trigger' => 'bind_to_scroll',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'loop',
          'type' => 'switcher',
          'label' => 'Loop',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'trigger!' => 'bind_to_scroll',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'number_of_times',
          'type' => 'number',
          'label' => 'Times',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'trigger',
                'operator' => '!==',
                'value' => 'bind_to_scroll',
              ),
              1 =>
              array (
                'name' => 'loop',
                'operator' => '===',
                'value' => 'yes',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'link_timeout',
          'type' => 'number',
          'label' => 'Link Timeout (ms)',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'link_to',
                'operator' => '===',
                'value' => 'custom',
              ),
              1 =>
              array (
                'name' => 'trigger',
                'operator' => '===',
                'value' => 'on_click',
              ),
              2 =>
              array (
                'name' => 'custom_link[url]',
                'operator' => '!==',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Redirect to link after selected timeout',
        ),
        6 =>
        array (
          'key' => 'on_hover_out',
          'type' => 'select',
          'label' => 'On Hover Out',
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Default',
            'reverse' => 'Reverse',
            'pause' => 'Pause',
          ),
          'condition' =>
          array (
            'trigger' => 'on_hover',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'hover_area',
          'type' => 'select',
          'label' => 'Hover Area',
          'default' => 'animation',
          'options' =>
          array (
            'animation' => 'Animation',
            'column' => 'Column',
            'section' => 'Section',
            'container' => 'Container',
          ),
          'condition' =>
          array (
            'trigger' => 'on_hover',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'play_speed',
          'type' => 'slider',
          'label' => 'Play Speed (x)',
          'default' =>
          array (
            'size' => 1,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'trigger!' => 'bind_to_scroll',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'start_point',
          'type' => 'slider',
          'label' => 'Start Point',
          'default' =>
          array (
            'size' => 0,
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'end_point',
          'type' => 'slider',
          'label' => 'End Point',
          'default' =>
          array (
            'size' => 100,
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'reverse_animation',
          'type' => 'switcher',
          'label' => 'Reverse',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'trigger',
                'operator' => '!==',
                'value' => 'bind_to_scroll',
              ),
              1 =>
              array (
                'name' => 'trigger',
                'operator' => '!==',
                'value' => 'on_hover',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'renderer',
          'type' => 'select',
          'label' => 'Renderer',
          'default' => 'svg',
          'options' =>
          array (
            'svg' => 'SVG',
            'canvas' => 'Canvas',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'lazyload',
          'type' => 'switcher',
          'label' => 'Lazy Load',
          'default' => '',
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
    2 =>
    array (
      'id' => 'style',
      'label' => 'Lottie',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'width',
          'type' => 'slider',
          'label' => 'Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'space',
          'type' => 'slider',
          'label' => 'Max Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'opacity',
          'type' => 'slider',
          'label' => 'Opacity',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'opacity_hover',
          'type' => 'slider',
          'label' => 'Opacity',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'background_hover_transition',
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
          'selector' => '{{WRAPPER}} .e-lottie__container',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'css-filter',
          'name' => 'css_filters_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-lottie__container:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    3 =>
    array (
      'id' => 'section_style_caption',
      'label' => 'Caption',
      'tab' => 'style',
      'condition' =>
      array (
        'caption_source!' => 'none',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'caption_align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => 'center',
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-text-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'caption_space',
          'type' => 'slider',
          'label' => 'Spacing',
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
        0 =>
        array (
          'group' => 'typography',
          'name' => 'caption_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-lottie__caption',
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
      'selector' => '{{WRAPPER}} .e-lottie__container',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'css-filter',
      'name' => 'css_filters_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-lottie__container:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'caption_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-lottie__caption',
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
    'source' =>
    array (
      'section' => 'lottie',
      'type' => 'select',
      'default' => 'media_file',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'source_external_url' =>
    array (
      'section' => 'lottie',
      'type' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
      ),
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'source' => 'external_url',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'source_json' =>
    array (
      'section' => 'lottie',
      'type' => 'media',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'source' => 'media_file',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'lottie',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_source' =>
    array (
      'section' => 'lottie',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' =>
      array (
        'source!' => 'external_url',
        'source_json[url]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption' =>
    array (
      'section' => 'lottie',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'caption_source',
            'value' => 'custom',
          ),
          1 =>
          array (
            'name' => 'source',
            'value' => 'external_url',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_to' =>
    array (
      'section' => 'lottie',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'custom_link' =>
    array (
      'section' => 'lottie',
      'type' => 'url',
      'default' =>
      array (
        'url' => '',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'link_to' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'trigger' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => 'arriving_to_viewport',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'viewport' =>
    array (
      'section' => 'settings',
      'type' => 'slider',
      'default' =>
      array (
        'sizes' =>
        array (
          'start' => 0,
          'end' => 100,
        ),
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'trigger',
            'operator' => '===',
            'value' => 'arriving_to_viewport',
          ),
          1 =>
          array (
            'name' => 'trigger',
            'operator' => '===',
            'value' => 'bind_to_scroll',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'effects_relative_to' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => 'viewport',
      'responsive' => false,
      'condition' =>
      array (
        'trigger' => 'bind_to_scroll',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'loop' =>
    array (
      'section' => 'settings',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'trigger!' => 'bind_to_scroll',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'number_of_times' =>
    array (
      'section' => 'settings',
      'type' => 'number',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'trigger',
            'operator' => '!==',
            'value' => 'bind_to_scroll',
          ),
          1 =>
          array (
            'name' => 'loop',
            'operator' => '===',
            'value' => 'yes',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_timeout' =>
    array (
      'section' => 'settings',
      'type' => 'number',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'link_to',
            'operator' => '===',
            'value' => 'custom',
          ),
          1 =>
          array (
            'name' => 'trigger',
            'operator' => '===',
            'value' => 'on_click',
          ),
          2 =>
          array (
            'name' => 'custom_link[url]',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'on_hover_out' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' =>
      array (
        'trigger' => 'on_hover',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hover_area' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => 'animation',
      'responsive' => false,
      'condition' =>
      array (
        'trigger' => 'on_hover',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'play_speed' =>
    array (
      'section' => 'settings',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'trigger!' => 'bind_to_scroll',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'start_point' =>
    array (
      'section' => 'settings',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0,
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'end_point' =>
    array (
      'section' => 'settings',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 100,
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'reverse_animation' =>
    array (
      'section' => 'settings',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'trigger',
            'operator' => '!==',
            'value' => 'bind_to_scroll',
          ),
          1 =>
          array (
            'name' => 'trigger',
            'operator' => '!==',
            'value' => 'on_hover',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'renderer' =>
    array (
      'section' => 'settings',
      'type' => 'select',
      'default' => 'svg',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'lazyload' =>
    array (
      'section' => 'settings',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'width' =>
    array (
      'section' => 'style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'space' =>
    array (
      'section' => 'style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'opacity' =>
    array (
      'section' => 'style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'opacity_hover' =>
    array (
      'section' => 'style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_hover_transition' =>
    array (
      'section' => 'style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'css_filters_css_filter' =>
    array (
      'section' => 'style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'css_filters',
    ),
    'css_filters_hover_css_filter' =>
    array (
      'section' => 'style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'css-filter',
      'group_prefix' => 'css_filters_hover',
    ),
    'caption_align' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_space' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'caption_typography_typography' =>
    array (
      'section' => 'section_style_caption',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'caption_typography',
    ),
  ),
  'group_activators' =>
  array (
    'css_filters_css_filter' => 'custom',
    'css_filters_hover_css_filter' => 'custom',
    'caption_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/lottie-widget.md',
    1 => 'docs/knowledge/elementor/widgets/lottie-widget.md',
  ),
  'control_count' => 33,
);
