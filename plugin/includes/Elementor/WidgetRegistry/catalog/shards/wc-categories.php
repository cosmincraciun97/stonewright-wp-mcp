<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'wc-categories',
  'source' => 'wc',
  'widget_type' => 'wc-categories',
  'title' => 'Product Categories',
  'icon' => 'eicon-product-categories',
  'categories' =>
  array (
    0 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
    0 => 'woocommerce-elements',
    1 => 'shop',
    2 => 'store',
    3 => 'categories',
    4 => 'product',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/categories.php',
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
      'id' => 'section_layout',
      'label' => 'Layout',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'number',
          'type' => 'number',
          'label' => 'Categories Count',
          'default' => '4',
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
    1 =>
    array (
      'id' => 'section_filter',
      'label' => 'Query',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'source',
          'type' => 'select',
          'label' => 'Source',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Show All',
            'by_id' => 'Manual Selection',
            'by_parent' => 'By Parent',
            'current_subcategories' => 'Current Subcategories',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'categories',
          'type' => 'select2',
          'label' => 'Categories',
          'default' =>
          array (
          ),
          'options' =>
          array (
            '__unresolved__' => '$options',
          ),
          'condition' =>
          array (
            'source' => 'by_id',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'parent',
          'type' => 'select',
          'label' => 'Parent',
          'default' => '0',
          'options' =>
          array (
            '__unresolved__' => '$parent_options',
          ),
          'condition' =>
          array (
            'source' => 'by_parent',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'hide_empty',
          'type' => 'switcher',
          'label' => 'Hide Empty',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'orderby',
          'type' => 'select',
          'label' => 'Order By',
          'default' => 'name',
          'options' =>
          array (
            'name' => 'Name',
            'slug' => 'Slug',
            'description' => 'Description',
            'count' => 'Count',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'order',
          'type' => 'select',
          'label' => 'Order',
          'default' => 'desc',
          'options' =>
          array (
            'asc' => 'ASC',
            'desc' => 'DESC',
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
    2 =>
    array (
      'id' => 'section_products_style',
      'label' => 'Products',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'wc_style_warning',
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
          'key' => 'products_class',
          'type' => 'hidden',
          'label' => NULL,
          'default' => 'wc-products',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'column_gap',
          'type' => 'slider',
          'label' => 'Columns Gap',
          'default' =>
          array (
            'size' => 20,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'row_gap',
          'type' => 'slider',
          'label' => 'Rows Gap',
          'default' =>
          array (
            'size' => 40,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
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
        5 =>
        array (
          'key' => 'heading_image_style',
          'type' => 'heading',
          'label' => 'Image',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'image_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'image_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'heading_title_style',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'heading_count_style',
          'type' => 'heading',
          'label' => 'Count',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'count_color',
          'type' => 'color',
          'label' => 'Color',
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
          'group' => 'border',
          'name' => 'image_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} a > img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce .woocommerce-loop-category__title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'count_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-loop-category__title .count',
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
      'group' => 'border',
      'name' => 'image_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} a > img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce .woocommerce-loop-category__title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'count_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-loop-category__title .count',
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
    'number' =>
    array (
      'section' => 'section_layout',
      'type' => 'number',
      'default' => '4',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'source' =>
    array (
      'section' => 'section_filter',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'categories' =>
    array (
      'section' => 'section_filter',
      'type' => 'select2',
      'default' =>
      array (
      ),
      'responsive' => false,
      'condition' =>
      array (
        'source' => 'by_id',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'parent' =>
    array (
      'section' => 'section_filter',
      'type' => 'select',
      'default' => '0',
      'responsive' => false,
      'condition' =>
      array (
        'source' => 'by_parent',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hide_empty' =>
    array (
      'section' => 'section_filter',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'orderby' =>
    array (
      'section' => 'section_filter',
      'type' => 'select',
      'default' => 'name',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order' =>
    array (
      'section' => 'section_filter',
      'type' => 'select',
      'default' => 'desc',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'wc_style_warning' =>
    array (
      'section' => 'section_products_style',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'products_class' =>
    array (
      'section' => 'section_products_style',
      'type' => 'hidden',
      'default' => 'wc-products',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'column_gap' =>
    array (
      'section' => 'section_products_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 20,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'row_gap' =>
    array (
      'section' => 'section_products_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 40,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_products_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_image_style' =>
    array (
      'section' => 'section_products_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_radius' =>
    array (
      'section' => 'section_products_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_spacing' =>
    array (
      'section' => 'section_products_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_title_style' =>
    array (
      'section' => 'section_products_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_products_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_count_style' =>
    array (
      'section' => 'section_products_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'count_color' =>
    array (
      'section' => 'section_products_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_border' =>
    array (
      'section' => 'section_products_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'image_border',
    ),
    'title_typography_typography' =>
    array (
      'section' => 'section_products_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'title_typography',
    ),
    'count_typography_typography' =>
    array (
      'section' => 'section_products_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'count_typography',
    ),
  ),
  'group_activators' =>
  array (
    'image_border_border' => 'solid',
    'title_typography_typography' => 'custom',
    'count_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 22,
);
