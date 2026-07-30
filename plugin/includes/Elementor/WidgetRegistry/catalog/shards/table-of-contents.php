<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'table-of-contents',
  'source' => 'pro',
  'widget_type' => 'table-of-contents',
  'title' => 'Table of Contents',
  'icon' => 'eicon-table-of-contents',
  'categories' =>
  array (
    0 => 'pro-elements',
    1 => 'theme-elements-single',
  ),
  'keywords' =>
  array (
    0 => 'toc',
  ),
  'file' => 'pro-elements/modules/table-of-contents/widgets/table-of-contents.php',
  'intent' => 'Why Web Creators Choose Table of ContentsEASY NAVIGATIONAdd a helpful menu for your contentMake your WordPress posts & pages simpler to navigate with a better user experience OPTIMIZED READABILITYMake it easier to read long form contentReaders can scan and find the most relevant areas of your content with easy scrollingDESIGN CUSTOMIZATIONMatch the TOC to your unique website designEasily apply your website’s design style to your Table of Contents widget – or customize it by editing the HTML code or CSS IMPROVED SEOUse structured data for rich snippetsEarn higher click-through rates on Google and other search engines by using your heading tags in your table of contentsAdvanced Table of Content OptionsGreat for FAQs and long-form contentSimplify your content and make it more user friendlyEliminates Other WordPress pluginsImprove the functionality of your site by removing extra pluginsResponsive and collapsible in mobileLet your table of contents be fully responsive and adaptable to screen dimensions and conditionsSee What Our Users Are Saying"I’m totally blown away by this product - it’s a designers dream.WordPress here I come!" Justin Easthall@EasthallDesignGuide Users With Table of ContentsGet all your plugins in one tool with Elementor Pro GO PRO Learn how to use this widget Explore More Widgets Posts Widget Posts Learn More » Post Comments Widget Post Comments Learn More » Slides Widget Slides Learn More » Nav Menu Widget Nav Menu Learn More » See All Widgets »',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
    3 => 'Creating long-form articles or guides where readers need quick jump-navigation to sections',
    4 => 'Building FAQ pages that benefit from structured, scannable heading-based navigation',
    5 => 'Reducing reliance on separate table-of-contents plugins on content-heavy sites',
    6 => 'Targeting SEO improvements through heading-based structured anchor links',
    7 => 'Designing mobile experiences requiring collapsible navigation panels for long content',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content options – Configure general content, title, tags, and icons.',
    1 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    2 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    3 => '**Heading tags**: Choose which H-tag levels (H2–H6) are included in the TOC',
    4 => '**Minimum headings to show**: Hide the widget automatically if the page has fewer headings than the threshold',
    5 => '**Title**: Label shown above the list (e.g. "Table of Contents")',
    6 => '**Word wrap**: Controls whether heading text truncates or wraps in the list',
    7 => '**Collapse on mobile**: Toggle the list into a collapsible panel on small screens',
    8 => '**Marker**: Bullet, number, or none before each TOC entry',
    9 => '**Indentation**: Visual nesting depth for sub-headings (H3 indented under H2)',
    10 => '**List item spacing**: Gap between TOC entries',
    11 => '**Typography**: Font controls for the TOC title and list items independently',
    12 => '**Sticky scroll behavior**: Pin the TOC in view as the user scrolls (via Sticky advanced option)',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'Requires proper heading hierarchy in the page content; pages without structured H-tags produce an empty or single-item TOC',
    3 => 'Reads headings from the current page only — cannot aggregate headings across multiple posts',
    4 => 'Custom post types must use standard Elementor heading widgets or native WP content headings; headings injected by third-party shortcodes may not be detected',
    5 => 'Mobile collapsible state defaults to expanded; test the collapsed default if UX requires it hidden on load',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'table_of_contents',
      'label' => 'Table of Contents',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Table of Contents',
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
          'key' => 'html_tag',
          'type' => 'select',
          'label' => 'HTML Tag',
          'default' => 'h4',
          'options' =>
          array (
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'headings_by_tags',
          'type' => 'select2',
          'label' => 'Anchors By Tags',
          'default' =>
          array (
            0 => 'h2',
            1 => 'h3',
            2 => 'h4',
            3 => 'h5',
            4 => 'h6',
          ),
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'container',
          'type' => 'text',
          'label' => 'Container',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'This control confines the Table of Contents to heading elements under a specific container',
        ),
        4 =>
        array (
          'key' => 'exclude_headings_by_selector',
          'type' => 'text',
          'label' => 'Anchors By Selector',
          'default' =>
          array (
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'CSS selectors, in a comma-separated list',
        ),
        5 =>
        array (
          'key' => 'marker_view',
          'type' => 'select',
          'label' => 'Marker View',
          'default' => 'numbers',
          'options' =>
          array (
            'numbers' => 'Numbers',
            'bullets' => 'Bullets',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            'value' => 'fas fa-circle',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'marker_view' => 'bullets',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'no_headings_message',
          'type' => 'text',
          'label' => 'No Headings Found Message',
          'default' => 'No headings were found on this page.',
          'options' => NULL,
          'condition' => NULL,
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
      'id' => 'additional_options',
      'label' => 'Additional Options',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'word_wrap',
          'type' => 'switcher',
          'label' => 'Word Wrap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'minimize_box',
          'type' => 'switcher',
          'label' => 'Minimize Box',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'expand_icon',
          'type' => 'icons',
          'label' => 'Expand Icon',
          'default' =>
          array (
            'value' => 'fas fa-chevron-down',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'minimize_box' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'collapse_icon',
          'type' => 'icons',
          'label' => 'Collapse Icon',
          'default' =>
          array (
            'value' => 'fas fa-chevron-up',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'minimize_box' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'minimized_on',
          'type' => 'select',
          'label' => 'Minimized On',
          'default' => 'tablet',
          'options' =>
          array (
            '__unresolved__' => '$minimized_on_options',
          ),
          'condition' =>
          array (
            'minimize_box!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'hierarchical_view',
          'type' => 'switcher',
          'label' => 'Hierarchical View',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'collapse_subitems',
          'type' => 'switcher',
          'label' => 'Collapse Subitems',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'hierarchical_view' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'The "Collapse" option should only be used if the Table of Contents is made sticky',
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
      'id' => 'box_style',
      'label' => 'Box',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'loader_color',
          'type' => 'color',
          'label' => 'Loader Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'header_separator_width',
          'type' => 'slider',
          'label' => 'Separator Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'padding',
          'type' => 'slider',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'min_height',
          'type' => 'slider',
          'label' => 'Min Height',
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
          'group' => 'box-shadow',
          'name' => 'box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}}',
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
      'id' => 'header_style',
      'label' => 'Header',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'header_text_align',
          'type' => 'choose',
          'label' => 'Text Align',
          'default' => 'start',
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
          'key' => 'header_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'header_text_color',
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
          'key' => 'toggle_button_color',
          'type' => 'color',
          'label' => 'Icon Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'minimize_box' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'toggle_button_position',
          'type' => 'choose',
          'label' => 'Icon Position',
          'default' => 'row',
          'options' =>
          array (
            'row-reverse' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'row' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'minimize_box' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'heading_gap',
          'type' => 'slider',
          'label' => 'Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'minimize_box' => 'yes',
          ),
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
          'name' => 'header_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-toc__header, {{WRAPPER}} .elementor-toc__header-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    4 =>
    array (
      'id' => 'list_style',
      'label' => 'List',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'max_height',
          'type' => 'slider',
          'label' => 'Max Height',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'list_indent',
          'type' => 'slider',
          'label' => 'Indent',
          'default' =>
          array (
            'unit' => 'em',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'item_text_color_normal',
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
          'key' => 'item_text_underline_normal',
          'type' => 'switcher',
          'label' => 'Underline',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'item_text_color_hover',
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
          'key' => 'item_text_underline_hover',
          'type' => 'switcher',
          'label' => 'Underline',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'item_text_hover_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 'ms',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'item_text_color_active',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'item_text_underline_active',
          'type' => 'switcher',
          'label' => 'Underline',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'heading_marker',
          'type' => 'heading',
          'label' => 'Marker',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'marker_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'marker_size',
          'type' => 'slider',
          'label' => 'Size',
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
          'name' => 'list_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-toc__list-item',
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
      'group' => 'box-shadow',
      'name' => 'box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}}',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'header_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-toc__header, {{WRAPPER}} .elementor-toc__header-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'list_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-toc__list-item',
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
    'title' =>
    array (
      'section' => 'table_of_contents',
      'type' => 'text',
      'default' => 'Table of Contents',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'html_tag' =>
    array (
      'section' => 'table_of_contents',
      'type' => 'select',
      'default' => 'h4',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'headings_by_tags' =>
    array (
      'section' => 'table_of_contents',
      'type' => 'select2',
      'default' =>
      array (
        0 => 'h2',
        1 => 'h3',
        2 => 'h4',
        3 => 'h5',
        4 => 'h6',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'container' =>
    array (
      'section' => 'table_of_contents',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'exclude_headings_by_selector' =>
    array (
      'section' => 'table_of_contents',
      'type' => 'text',
      'default' =>
      array (
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'marker_view' =>
    array (
      'section' => 'table_of_contents',
      'type' => 'select',
      'default' => 'numbers',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon' =>
    array (
      'section' => 'table_of_contents',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-circle',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'marker_view' => 'bullets',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'no_headings_message' =>
    array (
      'section' => 'table_of_contents',
      'type' => 'text',
      'default' => 'No headings were found on this page.',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'word_wrap' =>
    array (
      'section' => 'additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'minimize_box' =>
    array (
      'section' => 'additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'expand_icon' =>
    array (
      'section' => 'additional_options',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-chevron-down',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'minimize_box' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'collapse_icon' =>
    array (
      'section' => 'additional_options',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-chevron-up',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'minimize_box' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'minimized_on' =>
    array (
      'section' => 'additional_options',
      'type' => 'select',
      'default' => 'tablet',
      'responsive' => false,
      'condition' =>
      array (
        'minimize_box!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hierarchical_view' =>
    array (
      'section' => 'additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'collapse_subitems' =>
    array (
      'section' => 'additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'hierarchical_view' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_color' =>
    array (
      'section' => 'box_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_color' =>
    array (
      'section' => 'box_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'loader_color' =>
    array (
      'section' => 'box_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_width' =>
    array (
      'section' => 'box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius' =>
    array (
      'section' => 'box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'header_separator_width' =>
    array (
      'section' => 'box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'padding' =>
    array (
      'section' => 'box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'min_height' =>
    array (
      'section' => 'box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_shadow_box_shadow' =>
    array (
      'section' => 'box_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'box_shadow',
    ),
    'header_text_align' =>
    array (
      'section' => 'header_style',
      'type' => 'choose',
      'default' => 'start',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'header_background_color' =>
    array (
      'section' => 'header_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'header_text_color' =>
    array (
      'section' => 'header_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_color' =>
    array (
      'section' => 'header_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'minimize_box' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_position' =>
    array (
      'section' => 'header_style',
      'type' => 'choose',
      'default' => 'row',
      'responsive' => true,
      'condition' =>
      array (
        'minimize_box' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_gap' =>
    array (
      'section' => 'header_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'minimize_box' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'header_typography_typography' =>
    array (
      'section' => 'header_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'header_typography',
    ),
    'max_height' =>
    array (
      'section' => 'list_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'list_indent' =>
    array (
      'section' => 'list_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'em',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_text_color_normal' =>
    array (
      'section' => 'list_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_text_underline_normal' =>
    array (
      'section' => 'list_style',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_text_color_hover' =>
    array (
      'section' => 'list_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_text_underline_hover' =>
    array (
      'section' => 'list_style',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_text_hover_transition_duration' =>
    array (
      'section' => 'list_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'ms',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_text_color_active' =>
    array (
      'section' => 'list_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_text_underline_active' =>
    array (
      'section' => 'list_style',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_marker' =>
    array (
      'section' => 'list_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'marker_color' =>
    array (
      'section' => 'list_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'marker_size' =>
    array (
      'section' => 'list_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'list_typography_typography' =>
    array (
      'section' => 'list_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'list_typography',
    ),
  ),
  'group_activators' =>
  array (
    'box_shadow_box_shadow' => 'yes',
    'header_typography_typography' => 'custom',
    'list_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/table-of-contents-widget.md',
    1 => 'docs/knowledge/elementor/widgets/table-of-contents-widget.md',
    2 => 'docs/knowledge/elementor/widgets/table-of-contents-intent.md',
    3 => 'docs/knowledge/elementor/widgets/table-of-contents-intent.md',
  ),
  'control_count' => 44,
);
