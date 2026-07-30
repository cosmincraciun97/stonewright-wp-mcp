<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'counter',
  'source' => 'free',
  'widget_type' => 'counter',
  'title' => 'Counter',
  'icon' => 'eicon-counter',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'counter',
  ),
  'file' => 'elementor/includes/widgets/counter.php',
  'intent' => 'The Counter widget displays animated numerical statistics that increment from zero to a target number, creating visual emphasis for key metrics like client testimonials, project counts, or business achievements on your website.',
  'use_cases' =>
  array (
    0 => 'Showcasing business statistics or achievement milestones',
    1 => 'Highlighting numerical accomplishments (years in business, projects completed)',
    2 => 'Drawing attention to important performance metrics',
    3 => 'Creating engaging visual elements that animate on page load or scroll',
    4 => 'Building trust through quantifiable business results',
    5 => 'All available widgets are displayed',
    6 => 'Click or drag the widget to the canvas',
    7 => 'For more information, see Add elements to a page',
    8 => 'What is the Counter widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'Target number field to set the final counted value',
    1 => 'Animation duration controls for speed of increment',
    2 => 'Prefix and suffix text options (e.g., "$" or "+")',
    3 => 'Number formatting options (decimals, thousands separator)',
    4 => 'Starting number configuration (typically zero)',
    5 => 'Text color, font size, and typography styling',
    6 => 'Alignment options for layout positioning',
    7 => 'Trigger settings for when animation begins (on load or on scroll)',
    8 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    9 => 'Adding a Counter widget – Step-by-step',
    10 => 'Title – Experts.We changed the Animation length so the counter takes longer to reach its limit. We’ll also make some style changes to help draw attention to this counter.',
    11 => 'Content options – Configure general content, title, tags, and icons.',
    12 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    13 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Animation only triggers once per page load unless refresh occurs',
    1 => 'Very large numbers may display slowly depending on animation duration settings',
    2 => 'Mobile responsiveness requires font size adjustment to prevent text overflow',
    3 => 'Counter animation may not be visible to users with reduced motion preferences enabled',
    4 => 'Title: Experts.We changed the Animation length so the counter takes longer to reach its limit. We’ll also make some style changes to help draw attention to this counter.',
    5 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    6 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_counter',
      'label' => 'Counter',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'starting_number',
          'type' => 'number',
          'label' => 'Starting Number',
          'default' => 0,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'ending_number',
          'type' => 'number',
          'label' => 'Ending Number',
          'default' => 100,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'prefix',
          'type' => 'text',
          'label' => 'Number Prefix',
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
        3 =>
        array (
          'key' => 'suffix',
          'type' => 'text',
          'label' => 'Number Suffix',
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
        4 =>
        array (
          'key' => 'duration',
          'type' => 'number',
          'label' => 'Animation Duration (ms)',
          'default' => 2000,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'thousand_separator',
          'type' => 'switcher',
          'label' => 'Thousand Separator',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'thousand_separator_char',
          'type' => 'select',
          'label' => 'Separator',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Default',
            '.' => 'Dot',
            ' ' => 'Space',
            '_' => 'Underline',
            '\'' => 'Apostrophe',
          ),
          'condition' =>
          array (
            'thousand_separator' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'title',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Cool Number',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'title_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
          'default' => 'div',
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
          'condition' =>
          array (
            'title!' => '',
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
    1 =>
    array (
      'id' => 'section_counter_style',
      'label' => 'Counter',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title_position',
          'type' => 'choose',
          'label' => 'Title Position',
          'default' => NULL,
          'options' =>
          array (
            'before' =>
            array (
              'title' => 'Before',
              'icon' => 'eicon-v-align-top',
            ),
            'after' =>
            array (
              'title' => 'After',
              'icon' => 'eicon-v-align-bottom',
            ),
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'title_horizontal_alignment',
          'type' => 'choose',
          'label' => 'Title Horizontal Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-h-align-center',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'title_vertical_alignment',
          'type' => 'choose',
          'label' => 'Title Vertical Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'center' =>
            array (
              'title' => 'Middle',
              'icon' => 'eicon-v-align-middle',
            ),
            'end' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'title!' => '',
            'title_position' =>
            array (
              0 => 'start',
              1 => 'end',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'title_gap',
          'type' => 'slider',
          'label' => 'Title Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
            'title_position' =>
            array (
              0 => '',
              1 => 'before',
              2 => 'after',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'number_position',
          'type' => 'choose',
          'label' => 'Number Position',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-h-align-center',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
            'stretch' =>
            array (
              'title' => 'Stretch',
              'icon' => 'eicon-grow',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'number_alignment',
          'type' => 'choose',
          'label' => 'Number Alignment',
          'default' => NULL,
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
          'condition' =>
          array (
            'number_position' => 'stretch',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'number_gap',
          'type' => 'slider',
          'label' => 'Number Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'number_position',
                'operator' => '!==',
                'value' => 'stretch',
              ),
              1 =>
              array (
                'relation' => 'or',
                'terms' =>
                array (
                  0 =>
                  array (
                    'name' => 'prefix',
                    'operator' => '!==',
                    'value' => '',
                  ),
                  1 =>
                  array (
                    'name' => 'suffix',
                    'operator' => '!==',
                    'value' => '',
                  ),
                ),
              ),
            ),
          ),
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
    2 =>
    array (
      'id' => 'section_number',
      'label' => 'Number',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'number_color',
          'type' => 'color',
          'label' => 'Text Color',
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
          'group' => 'typography',
          'name' => 'typography_number',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-counter-number-wrapper',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'number_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-counter-number-wrapper',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'number_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-counter-number-wrapper',
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
      'id' => 'section_title',
      'label' => 'Title',
      'tab' => 'style',
      'condition' =>
      array (
        'title!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
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
          'name' => 'typography_title',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-counter-title',
          'condition' =>
          array (
            'title!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'title_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-counter-title',
          'condition' =>
          array (
            'title!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-counter-title',
          'condition' =>
          array (
            'title!' => '',
          ),
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
      'name' => 'typography_number',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-counter-number-wrapper',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-stroke',
      'name' => 'number_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-counter-number-wrapper',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'number_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-counter-number-wrapper',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'typography_title',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-counter-title',
      'condition' =>
      array (
        'title!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'text-stroke',
      'name' => 'title_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-counter-title',
      'condition' =>
      array (
        'title!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-counter-title',
      'condition' =>
      array (
        'title!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'starting_number' =>
    array (
      'section' => 'section_counter',
      'type' => 'number',
      'default' => 0,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ending_number' =>
    array (
      'section' => 'section_counter',
      'type' => 'number',
      'default' => 100,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'prefix' =>
    array (
      'section' => 'section_counter',
      'type' => 'text',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'suffix' =>
    array (
      'section' => 'section_counter',
      'type' => 'text',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'duration' =>
    array (
      'section' => 'section_counter',
      'type' => 'number',
      'default' => 2000,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'thousand_separator' =>
    array (
      'section' => 'section_counter',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'thousand_separator_char' =>
    array (
      'section' => 'section_counter',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'thousand_separator' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title' =>
    array (
      'section' => 'section_counter',
      'type' => 'text',
      'default' => 'Cool Number',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_tag' =>
    array (
      'section' => 'section_counter',
      'type' => 'select',
      'default' => 'div',
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_position' =>
    array (
      'section' => 'section_counter_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_horizontal_alignment' =>
    array (
      'section' => 'section_counter_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_vertical_alignment' =>
    array (
      'section' => 'section_counter_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'title!' => '',
        'title_position' =>
        array (
          0 => 'start',
          1 => 'end',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_gap' =>
    array (
      'section' => 'section_counter_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'title!' => '',
        'title_position' =>
        array (
          0 => '',
          1 => 'before',
          2 => 'after',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'number_position' =>
    array (
      'section' => 'section_counter_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'number_alignment' =>
    array (
      'section' => 'section_counter_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'number_position' => 'stretch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'number_gap' =>
    array (
      'section' => 'section_counter_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'number_position',
            'operator' => '!==',
            'value' => 'stretch',
          ),
          1 =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'prefix',
                'operator' => '!==',
                'value' => '',
              ),
              1 =>
              array (
                'name' => 'suffix',
                'operator' => '!==',
                'value' => '',
              ),
            ),
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'number_color' =>
    array (
      'section' => 'section_number',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_number_typography' =>
    array (
      'section' => 'section_number',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'typography_number',
    ),
    'number_stroke_text_stroke' =>
    array (
      'section' => 'section_number',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'number_stroke',
    ),
    'number_shadow_text_shadow' =>
    array (
      'section' => 'section_number',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'number_shadow',
    ),
    'title_color' =>
    array (
      'section' => 'section_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_title_typography' =>
    array (
      'section' => 'section_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'typography_title',
    ),
    'title_stroke_text_stroke' =>
    array (
      'section' => 'section_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => 'text-stroke',
      'group_prefix' => 'title_stroke',
    ),
    'title_shadow_text_shadow' =>
    array (
      'section' => 'section_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => 'text-shadow',
      'group_prefix' => 'title_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'typography_number_typography' => 'custom',
    'number_stroke_text_stroke' => 'yes',
    'number_shadow_text_shadow' => 'yes',
    'typography_title_typography' => 'custom',
    'title_stroke_text_stroke' => 'yes',
    'title_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/counter.md',
    1 => 'docs/knowledge/elementor/widgets/counter.md',
    2 => 'docs/knowledge/elementor/widgets/counter-widget.md',
    3 => 'docs/knowledge/elementor/widgets/counter-widget.md',
  ),
  'control_count' => 24,
);
