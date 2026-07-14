<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'animated-headline',
  'source' => 'pro',
  'widget_type' => 'animated-headline',
  'title' => 'Animated Headline',
  'icon' => 'eicon-animated-headline',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'headline',
    1 => 'heading',
    2 => 'animation',
    3 => 'title',
    4 => 'text',
  ),
  'file' => 'pro-elements/modules/animated-headline/widgets/animated-headline.php',
  'intent' => 'Design Headlines That Capture Attention WidgetsPro WidgetsAnimated Headline Get Elementor Pro Animated HeadlineCreate Standout Headlines That Engage Your Users Create Your Own Animations Highlight parts of your headline with animation effects to focus your user’s attention on a specific message. highlighted headlines Bring Your Pages To Life Condense three messages into one using a rotating headline, and highlight your central values in a single place. Rotating headlines Control the Timing of Your Animations Set the duration and delay between each animation loop, add dynamic capabilities, and customize your colors. Customized effects Get Inspired by Unique Headlines Explore exceptionally designed websites and get inspired by how they use animations in their headlines to make them stand out. Learn How to Design Animated Headlines That Elevate Your Website Discover how to add an animated headline widget to your website page, master the styling options, create eye-catching efects, and more! HOW IT WORKS Explore Other Widgets Take your website to the next level using Pro’s powerful widgets. Gallery Widget Gallery Posts Widget Posts Login Widget Login Form Widget Form',
  'use_cases' =>
  array (
    0 => 'HOW IT WORKS Explore Other Widgets Take your website to the next level using Pro’s powerful widgets',
    1 => 'Gallery Widget Gallery Posts Widget Posts Login Widget Login Form Widget Form',
    2 => 'Organizing your layout design and structuring content elements inside Elementor.',
    3 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content options – Configure general content, title, tags, and icons.',
    1 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    2 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
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
      'id' => 'text_elements',
      'label' => 'Headline',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'headline_style',
          'type' => 'select',
          'label' => 'Animation Style',
          'default' => 'highlight',
          'options' =>
          array (
            'highlight' => 'Highlighted Text',
            'rotate' => 'Rotating Text',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'animation_type',
          'type' => 'select',
          'label' => 'Animation Type',
          'default' => 'typing',
          'options' =>
          array (
            'typing' => 'Typing',
            'clip' => 'Clip',
            'flip' => 'Flip',
            'swirl' => 'Swirl',
            'blinds' => 'Blinds',
            'drop-in' => 'Drop-in',
            'wave' => 'Wave',
            'slide' => 'Slide',
            'slide-down' => 'Slide Down',
          ),
          'condition' =>
          array (
            'headline_style' => 'rotate',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'marker',
          'type' => 'select',
          'label' => 'Animation Shape',
          'default' => 'circle',
          'options' =>
          array (
            'circle' => 'Circle',
            'curly' => 'Curly',
            'underline' => 'Underline',
            'double' => 'Double',
            'double_underline' => 'Double Underline',
            'underline_zigzag' => 'Underline Zigzag',
            'diagonal' => 'Diagonal',
            'strikethrough' => 'Strikethrough',
            'x' => 'X',
          ),
          'condition' =>
          array (
            'headline_style' => 'highlight',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'before_text',
          'type' => 'text',
          'label' => 'Before Text',
          'default' => 'This page is',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::TEXT_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'highlighted_text',
          'type' => 'text',
          'label' => 'Highlighted Text',
          'default' => 'Amazing',
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'highlight',
          ),
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::TEXT_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'rotating_text',
          'type' => 'textarea',
          'label' => 'Rotating Text',
          'default' => 'Better
Bigger
Faster',
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'rotate',
          ),
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::TEXT_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'after_text',
          'type' => 'text',
          'label' => 'After Text',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::TEXT_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'loop',
          'type' => 'switcher',
          'label' => 'Infinite Loop',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'highlight_animation_duration',
          'type' => 'number',
          'label' => 'Duration (ms)',
          'default' => 1200,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'highlight',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'highlight_iteration_delay',
          'type' => 'number',
          'label' => 'Delay (ms)',
          'default' => 8000,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'highlight',
            'loop' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'rotate_iteration_delay',
          'type' => 'number',
          'label' => 'Duration (ms)',
          'default' => 2500,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'rotate',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'tag',
          'type' => 'select',
          'label' => 'HTML Tag',
          'default' => 'h3',
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
            'span' => 'span',
            'p' => 'p',
          ),
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
    1 =>
    array (
      'id' => 'section_style_text',
      'label' => 'Headline',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'alignment',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => 'center',
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-text-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'heading_style',
          'type' => 'heading',
          'label' => 'Text',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_words_style',
          'type' => 'heading',
          'label' => 'Animated Text',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'words_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'typing_animation_highlight_colors',
          'type' => 'heading',
          'label' => 'Selected Text',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'rotate',
            'animation_type' => 'typing',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'highlighted_text_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'rotate',
            'animation_type' => 'typing',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'highlighted_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'rotate',
            'animation_type' => 'typing',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'highlight_animation_shape_colors',
          'type' => 'heading',
          'label' => 'Highlighted Shape',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'highlight',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'marker_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'highlight',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'stroke_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'highlight',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'above_content',
          'type' => 'switcher',
          'label' => 'Bring to Front',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'highlight',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'rounded_edges',
          'type' => 'switcher',
          'label' => 'Rounded Edges',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'headline_style' => 'highlight',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-headline',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-headline .elementor-headline-plain-text',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-headline .elementor-headline-plain-text',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'words_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-headline-dynamic-text',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'font_size',
          ),
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'text-stroke',
          'name' => 'animated_text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-headline .elementor-headline-dynamic-wrapper',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'text-shadow',
          'name' => 'animated_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-headline .elementor-headline-dynamic-wrapper',
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
      'group' => 'typography',
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-headline',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-headline .elementor-headline-plain-text',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-headline .elementor-headline-plain-text',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'words_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-headline-dynamic-text',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'font_size',
      ),
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'text-stroke',
      'name' => 'animated_text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-headline .elementor-headline-dynamic-wrapper',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'text-shadow',
      'name' => 'animated_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-headline .elementor-headline-dynamic-wrapper',
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
    'headline_style' =>
    array (
      'section' => 'text_elements',
      'type' => 'select',
      'default' => 'highlight',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_type' =>
    array (
      'section' => 'text_elements',
      'type' => 'select',
      'default' => 'typing',
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'rotate',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'marker' =>
    array (
      'section' => 'text_elements',
      'type' => 'select',
      'default' => 'circle',
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'before_text' =>
    array (
      'section' => 'text_elements',
      'type' => 'text',
      'default' => 'This page is',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'highlighted_text' =>
    array (
      'section' => 'text_elements',
      'type' => 'text',
      'default' => 'Amazing',
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rotating_text' =>
    array (
      'section' => 'text_elements',
      'type' => 'textarea',
      'default' => 'Better
Bigger
Faster',
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'rotate',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'after_text' =>
    array (
      'section' => 'text_elements',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'loop' =>
    array (
      'section' => 'text_elements',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'highlight_animation_duration' =>
    array (
      'section' => 'text_elements',
      'type' => 'number',
      'default' => 1200,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'highlight_iteration_delay' =>
    array (
      'section' => 'text_elements',
      'type' => 'number',
      'default' => 8000,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
        'loop' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rotate_iteration_delay' =>
    array (
      'section' => 'text_elements',
      'type' => 'number',
      'default' => 2500,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'rotate',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'text_elements',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tag' =>
    array (
      'section' => 'text_elements',
      'type' => 'select',
      'default' => 'h3',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alignment' =>
    array (
      'section' => 'section_style_text',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_style' =>
    array (
      'section' => 'section_style_text',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_style_text',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_words_style' =>
    array (
      'section' => 'section_style_text',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'words_color' =>
    array (
      'section' => 'section_style_text',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typing_animation_highlight_colors' =>
    array (
      'section' => 'section_style_text',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'rotate',
        'animation_type' => 'typing',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'highlighted_text_background_color' =>
    array (
      'section' => 'section_style_text',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'rotate',
        'animation_type' => 'typing',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'highlighted_text_color' =>
    array (
      'section' => 'section_style_text',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'rotate',
        'animation_type' => 'typing',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'highlight_animation_shape_colors' =>
    array (
      'section' => 'section_style_text',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'marker_color' =>
    array (
      'section' => 'section_style_text',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'stroke_width' =>
    array (
      'section' => 'section_style_text',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'above_content' =>
    array (
      'section' => 'section_style_text',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rounded_edges' =>
    array (
      'section' => 'section_style_text',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'headline_style' => 'highlight',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_typography_typography' =>
    array (
      'section' => 'section_style_text',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'title_typography',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_style_text',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'title_text_shadow_text_shadow' =>
    array (
      'section' => 'section_style_text',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'title_text_shadow',
    ),
    'words_typography_typography' =>
    array (
      'section' => 'section_style_text',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'words_typography',
    ),
    'animated_text_stroke_text_stroke' =>
    array (
      'section' => 'section_style_text',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'animated_text_stroke',
    ),
    'animated_text_shadow_text_shadow' =>
    array (
      'section' => 'section_style_text',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'animated_text_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'title_typography_typography' => 'custom',
    'text_stroke_text_stroke' => 'yes',
    'title_text_shadow_text_shadow' => 'yes',
    'words_typography_typography' => 'custom',
    'animated_text_stroke_text_stroke' => 'yes',
    'animated_text_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'before_text',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/animated-headline.md',
    1 => 'docs/knowledge/elementor/widgets/animated-headline.md',
    2 => 'docs/knowledge/elementor/widgets/animated-headline-intent.md',
    3 => 'docs/knowledge/elementor/widgets/animated-headline-intent.md',
  ),
  'control_count' => 32,
);
