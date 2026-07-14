<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'portfolio',
  'source' => 'pro',
  'widget_type' => 'portfolio',
  'title' => 'Portfolio',
  'icon' => 'eicon-gallery-grid',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'posts',
    1 => 'cpt',
    2 => 'item',
    3 => 'loop',
    4 => 'query',
    5 => 'portfolio',
    6 => 'custom post type',
  ),
  'file' => 'pro-elements/modules/posts/widgets/portfolio.php',
  'intent' => 'Build a Portfolio to Show Off Your Work WidgetsPro WidgetsPortfolio Get Elementor Pro Watch video Portfolio WidgetPreview Any Project on One Screen Create Filterable Grids Allow visitors to filter posts by category, tag, and a variety of other taxonomies. grid layout Choose A Responsive Layout Adjust the columns, post per page, layout style, item ratio and more. Customization Add Overlays to Featured Images Select an overlay style that engages users as they browse and hover throughout your post or archive page. Interactive effects Get Inspired by Impressive Portfolios Explore exceptionally designed portfolios and get inspired by how they present their work in an elevated way. Learn How to Build A Professional Portfolio Master the steps in creating a portfolio with Elementor. Style and customize the portfolio widget, adjust the query and filter options, and more! HOW IT WORKS How to Make a Portfolio Website in WordPress with Elementor Explore Other Widgets Take your website to the next level using Pro’s powerful widgets. Animated Headline Animated Headline Slides Widget Slides Nav Menu Widget Nav Menu Call To Action Widget Call to Action',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
    3 => 'Options include posts, pages, custom post types if available, manual selection, current query, and related',
    4 => 'Depending upon which source you’ve chosen for the query, you’ll be given options which allow you to filter the results',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content options – Configure general content, title, tags, and icons.',
    1 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    2 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    3 => 'Note – The Portfolio widget ONLY works with posts, pages, and custom post types. The Portfolio widget does NOT work with Galleries.',
    4 => 'Columns – Set the number of columns displayed in the widget',
    5 => 'Posts count – Set the exact amount of posts to be displayed in the widget',
    6 => 'Image Resolution – Set the size of the images',
    7 => 'Item ratio – Set the ratio of the items',
    8 => 'Show title – Choose whether to show or hide the title. The title will display while hovering over the images',
    9 => 'Title HTML tag – Wrap the title with a tag, either H1…H6, span, div or paragraph',
    10 => 'Source – Select the source from which the widget will display the content. Options include posts, pages, custom post types if available, manual selection, current query, and related. Depending upon which source you’ve chosen for the query, you’ll be given options which allow you to filter the results.',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'The Portfolio widget ONLY works with posts, pages, and custom post types. The Portfolio widget does NOT work with Galleries.',
    3 => 'Featured images must be used in your posts for any images to show up in the Portfolio widget.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_layout',
      'label' => 'Layout',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'columns',
          'type' => 'select',
          'label' => 'Columns',
          'default' => '3',
          'options' =>
          array (
            1 => '1',
            2 => '2',
            3 => '3',
            4 => '4',
            5 => '5',
            6 => '6',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'posts_per_page',
          'type' => 'number',
          'label' => 'Posts Per Page',
          'default' => 6,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'masonry',
          'type' => 'switcher',
          'label' => 'Masonry',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'columns!' => '1',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'item_ratio',
          'type' => 'slider',
          'label' => 'Item Ratio',
          'default' =>
          array (
            'size' => 0.66,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'masonry' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'show_title',
          'type' => 'switcher',
          'label' => 'Show Title',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'title_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
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
          'condition' =>
          array (
            'show_title' => 'yes',
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
          'group' => 'image-size',
          'name' => 'thumbnail_size',
          'label' => NULL,
          'selector' => NULL,
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'custom',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    1 =>
    array (
      'id' => 'section_query',
      'label' => 'Query',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => NULL,
          'name' => 'posts',
          'label' => NULL,
          'selector' => NULL,
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'posts_per_page',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'filter_bar',
      'label' => 'Filter Bar',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'show_filter_bar',
          'type' => 'switcher',
          'label' => 'Show',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'taxonomy',
          'type' => 'select2',
          'label' => 'Taxonomy',
          'default' =>
          array (
          ),
          'options' =>
          array (
            '__unresolved__' => '->get_taxonomies()',
          ),
          'condition' =>
          array (
            'show_filter_bar' => 'yes',
            'posts_post_type!' => 'by_id',
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
    3 =>
    array (
      'id' => 'section_design_layout',
      'label' => 'Items',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'item_gap',
          'type' => 'slider',
          'label' => 'Item Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'column_gap',
          'type' => 'slider',
          'label' => 'Columns Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'row_gap',
          'type' => 'slider',
          'label' => 'Rows Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
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
    4 =>
    array (
      'id' => 'section_design_overlay',
      'label' => 'Item Overlay',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'color_background',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'color_title',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_title' => 'yes',
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
          'selector' => '{{WRAPPER}} .elementor-portfolio-item__title',
          'condition' =>
          array (
            'show_title' => 'yes',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    5 =>
    array (
      'id' => 'section_design_filter',
      'label' => 'Filter Bar',
      'tab' => 'style',
      'condition' =>
      array (
        'show_filter_bar' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'color_filter',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'color_filter_active',
          'type' => 'color',
          'label' => 'Active Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'filter_item_spacing',
          'type' => 'slider',
          'label' => 'Space Between',
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
          'key' => 'filter_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
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
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'typography_filter',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-portfolio__filter',
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
      'group' => 'image-size',
      'name' => 'thumbnail_size',
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'custom',
      ),
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => NULL,
      'name' => 'posts',
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'posts_per_page',
      ),
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'typography_title',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-portfolio-item__title',
      'condition' =>
      array (
        'show_title' => 'yes',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'typography_filter',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-portfolio__filter',
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
    'columns' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => '3',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'posts_per_page' =>
    array (
      'section' => 'section_layout',
      'type' => 'number',
      'default' => 6,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'masonry' =>
    array (
      'section' => 'section_layout',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'columns!' => '1',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_ratio' =>
    array (
      'section' => 'section_layout',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0.66,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'masonry' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_title' =>
    array (
      'section' => 'section_layout',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_tag' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'h3',
      'responsive' => false,
      'condition' =>
      array (
        'show_title' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'thumbnail_size_image_size' =>
    array (
      'section' => 'section_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'image-size',
      'group_prefix' => 'thumbnail_size',
    ),
    'show_filter_bar' =>
    array (
      'section' => 'filter_bar',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy' =>
    array (
      'section' => 'filter_bar',
      'type' => 'select2',
      'default' =>
      array (
      ),
      'responsive' => false,
      'condition' =>
      array (
        'show_filter_bar' => 'yes',
        'posts_post_type!' => 'by_id',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_gap' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'column_gap' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'row_gap' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_background' =>
    array (
      'section' => 'section_design_overlay',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_title' =>
    array (
      'section' => 'section_design_overlay',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_title' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_title_typography' =>
    array (
      'section' => 'section_design_overlay',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_title' => 'yes',
      ),
      'group' => 'typography',
      'group_prefix' => 'typography_title',
    ),
    'color_filter' =>
    array (
      'section' => 'section_design_filter',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_filter_active' =>
    array (
      'section' => 'section_design_filter',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'filter_item_spacing' =>
    array (
      'section' => 'section_design_filter',
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
    'filter_spacing' =>
    array (
      'section' => 'section_design_filter',
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
    'typography_filter_typography' =>
    array (
      'section' => 'section_design_filter',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'typography_filter',
    ),
  ),
  'group_activators' =>
  array (
    'thumbnail_size_image_size' => 'custom',
    'typography_title_typography' => 'custom',
    'typography_filter_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/portfolio-widget.md',
    1 => 'docs/knowledge/elementor/widgets/portfolio-widget.md',
    2 => 'docs/knowledge/elementor/widgets/portfolio-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/portfolio-widget-pro.md',
  ),
  'control_count' => 21,
);
