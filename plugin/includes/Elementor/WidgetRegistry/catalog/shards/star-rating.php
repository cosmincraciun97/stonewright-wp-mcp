<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'star-rating',
  'source' => 'free',
  'widget_type' => 'star-rating',
  'title' => 'Star Rating',
  'icon' => 'eicon-rating',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'star',
    1 => 'rating',
    2 => 'rate',
    3 => 'review',
  ),
  'file' => 'elementor/includes/widgets/star-rating.php',
  'intent' => 'Display beautiful, customizable star ratings on WordPress sites using CSS styling. The widget generates rich snippets for search engine optimization, improves organic visibility, and enhances user trust through visual product/service ratings. It supports dynamic values pulled from custom fields, making it suitable for programmatic rating displays without hardcoding values.',
  'use_cases' =>
  array (
    0 => 'Showcasing product or service ratings directly on a page or landing section',
    1 => 'Improving search engine rankings with schema markup (rich snippets) for star ratings',
    2 => 'Building review sections that require visual star displays alongside testimonial text',
    3 => 'Pulling dynamic rating values from ACF or other custom field data automatically',
    4 => 'Adding credibility signals to landing pages or product detail pages',
  ),
  'settings_highlights' =>
  array (
    0 => '**Icon library**: Choose between Font Awesome 5 or Unicode star characters as the icon source',
    1 => '**Rating value**: Set the numeric rating (e.g. 4.5 out of 5); accepts decimal values',
    2 => '**Star count**: Configure total number of stars displayed (scale denominator)',
    3 => '**Size**: Adjust star icon dimensions to fit heading, body, or compact contexts',
    4 => '**Color (filled)**: Brand-matched fill color for active/rated stars',
    5 => '**Color (empty)**: Muted or outline color for unrated star slots',
    6 => '**Spacing**: Control gap between individual star icons',
    7 => '**Alignment**: Left / center / right alignment within the container',
    8 => '**Dynamic tags**: Bind rating value to a custom field for programmatic display',
    9 => '**Rich snippets toggle**: Emit schema.org `AggregateRating` markup for SEO',
  ),
  'limits' =>
  array (
    0 => 'Rich snippets require proper schema implementation; incomplete setup may not improve search visibility',
    1 => 'Dynamic tag functionality depends on having properly configured custom fields with numeric rating data',
    2 => 'Star styling limited to Font Awesome 5 or Unicode options; custom SVG icons require CSS overrides or workarounds',
    3 => 'Rating values must be numeric structured data; free-text ratings ("Excellent") will not display as visual stars',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_rating',
      'label' => 'Star Rating',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'rating_scale',
          'type' => 'select',
          'label' => 'Rating Scale',
          'default' => '5',
          'options' =>
          array (
            5 => '0-5',
            10 => '0-10',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'rating',
          'type' => 'number',
          'label' => 'Rating',
          'default' => 5,
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
          'key' => 'star_style',
          'type' => 'select',
          'label' => 'Icon',
          'default' => 'star_fontawesome',
          'options' =>
          array (
            'star_fontawesome' => 'Font Awesome',
            'star_unicode' => 'Unicode',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'unmarked_star_style',
          'type' => 'choose',
          'label' => 'Unmarked Style',
          'default' => 'solid',
          'options' =>
          array (
            'solid' =>
            array (
              'title' => 'Solid',
              'icon' => 'eicon-star',
            ),
            'outline' =>
            array (
              'title' => 'Outline',
              'icon' => 'eicon-star-o',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'title',
          'type' => 'text',
          'label' => 'Title',
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
        5 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Alignment',
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
            'justify' =>
            array (
              'title' => 'Justified',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
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
      'id' => 'section_title_style',
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
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'title_gap',
          'type' => 'slider',
          'label' => 'Gap',
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
          'name' => 'title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-star-rating__title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-star-rating__title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'section_stars_style',
      'label' => 'Stars',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'icon_space',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'stars_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'stars_unmarked_color',
          'type' => 'color',
          'label' => 'Unmarked Color',
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
    0 =>
    array (
      'group' => 'typography',
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-star-rating__title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-star-rating__title',
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
    'rating_scale' =>
    array (
      'section' => 'section_rating',
      'type' => 'select',
      'default' => '5',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'rating' =>
    array (
      'section' => 'section_rating',
      'type' => 'number',
      'default' => 5,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'star_style' =>
    array (
      'section' => 'section_rating',
      'type' => 'select',
      'default' => 'star_fontawesome',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'unmarked_star_style' =>
    array (
      'section' => 'section_rating',
      'type' => 'choose',
      'default' => 'solid',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title' =>
    array (
      'section' => 'section_rating',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_rating',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_title_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_gap' =>
    array (
      'section' => 'section_title_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_typography_typography' =>
    array (
      'section' => 'section_title_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'title_typography',
    ),
    'title_shadow_text_shadow' =>
    array (
      'section' => 'section_title_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'title_shadow',
    ),
    'icon_size' =>
    array (
      'section' => 'section_stars_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_space' =>
    array (
      'section' => 'section_stars_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'stars_color' =>
    array (
      'section' => 'section_stars_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'stars_unmarked_color' =>
    array (
      'section' => 'section_stars_style',
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
    'title_typography_typography' => 'custom',
    'title_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'rating',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/star-rating-widget.md',
    1 => 'docs/knowledge/elementor/widgets/star-rating-widget.md',
    2 => 'docs/knowledge/elementor/widgets/star-rating-intent.md',
    3 => 'docs/knowledge/elementor/widgets/star-rating-intent.md',
  ),
  'control_count' => 14,
);
