<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-products',
  'source' => 'wc',
  'widget_type' => 'woocommerce-products',
  'title' => 'Products',
  'icon' => 'eicon-products',
  'categories' =>
  array (
    0 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'shop',
    2 => 'store',
    3 => 'product',
    4 => 'archive',
    5 => 'upsells',
    6 => 'cross-sells',
    7 => 'cross sells',
    8 => 'related',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/products.php',
  'intent' => 'Configures the standard Products widget to showcase custom selections of products on any site page.',
  'use_cases' =>
  array (
    0 => 'Featuring specific products, sales, or collections on a homepage or landing page.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Columns count setting (1 to 12)',
    1 => 'Query source (Latest, Sale, Featured, Manual, Related)',
    2 => 'Order By setting (Date, Title, Price, Popularity, Rating, Random)',
    3 => 'Order direction setting (ASC, DESC)',
    4 => 'Pagination visibility toggle',
    5 => 'Column Gap / Row Gap style sliders (in pixels)',
    6 => 'Image, Title, Price, Rating, Button style states (Normal, Hover)',
  ),
  'limits' =>
  array (
    0 => 'Selecting \'Related\' source requires placement inside a single product template.',
    1 => 'Requires active WooCommerce plugin.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_content',
      'label' => 'Content',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'rows',
          'type' => 'number',
          'label' => 'Rows',
          'default' =>
          array (
            '__unresolved__' => 'Products_Renderer::DEFAULT_COLUMNS_AND_ROWS',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'paginate',
          'type' => 'switcher',
          'label' => 'Pagination',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            0 =>
            array (
              0 => 'related_products',
              1 => 'upsells',
              2 => 'cross_sells',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'allow_order',
          'type' => 'switcher',
          'label' => 'Allow Order',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'paginate' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'wc_notice_frontpage',
          'type' => 'alert',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'paginate' => 'yes',
            'allow_order' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'show_result_count',
          'type' => 'switcher',
          'label' => 'Show Result Count',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'paginate' => 'yes',
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
      'id' => 'section_query',
      'label' => 'Query',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'related_products_note',
          'type' => 'alert',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            0 => 'related_products',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'upsells_products_note',
          'type' => 'alert',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            0 => 'upsells',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'cross_sells_products_note',
          'type' => 'alert',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            0 => 'cross_sells',
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
          'group' => NULL,
          'name' => NULL,
          'label' => NULL,
          'selector' => NULL,
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
      'id' => 'section_products_title',
      'label' => 'Title',
      'tab' => NULL,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' =>
            array (
              '__unresolved__' => 'PhpParser\\Node\\Expr\\BinaryOp\\Concat',
            ),
            'operator' => '=',
            'value' => 'related_products',
          ),
          1 =>
          array (
            'name' =>
            array (
              '__unresolved__' => 'PhpParser\\Node\\Expr\\BinaryOp\\Concat',
            ),
            'operator' => '=',
            'value' => 'upsells',
          ),
          2 =>
          array (
            'name' =>
            array (
              '__unresolved__' => 'PhpParser\\Node\\Expr\\BinaryOp\\Concat',
            ),
            'operator' => '=',
            'value' => 'cross_sells',
          ),
        ),
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'products_title_show',
          'type' => 'switcher',
          'label' => 'Title',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'products_title_alignment',
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
          ),
          'condition' =>
          array (
            'products_title_show!' => '',
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
    3 =>
    array (
      'id' => 'products_title_style',
      'label' => 'Title',
      'tab' => 'style',
      'condition' =>
      array (
        'products_title_show!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'products_title_color',
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
          'key' => 'products_title_spacing',
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
          'name' => 'products_title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}}.products-heading-show .related-products > h2, {{WRAPPER}}.products-heading-show .upsells > h2, {{WRAPPER}}.products-heading-show .cross-sells > h2',
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
      'group' => NULL,
      'name' => NULL,
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'products_title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}}.products-heading-show .related-products > h2, {{WRAPPER}}.products-heading-show .upsells > h2, {{WRAPPER}}.products-heading-show .cross-sells > h2',
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
    'rows' =>
    array (
      'section' => 'section_content',
      'type' => 'number',
      'default' =>
      array (
        '__unresolved__' => 'Products_Renderer::DEFAULT_COLUMNS_AND_ROWS',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'paginate' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        0 =>
        array (
          0 => 'related_products',
          1 => 'upsells',
          2 => 'cross_sells',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'allow_order' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'paginate' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'wc_notice_frontpage' =>
    array (
      'section' => 'section_content',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'paginate' => 'yes',
        'allow_order' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_result_count' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'paginate' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'related_products_note' =>
    array (
      'section' => 'section_query',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        0 => 'related_products',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'upsells_products_note' =>
    array (
      'section' => 'section_query',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        0 => 'upsells',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'cross_sells_products_note' =>
    array (
      'section' => 'section_query',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        0 => 'cross_sells',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'products_title_show' =>
    array (
      'section' => 'section_products_title',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'products_title_alignment' =>
    array (
      'section' => 'section_products_title',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'products_title_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'products_title_color' =>
    array (
      'section' => 'products_title_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'products_title_spacing' =>
    array (
      'section' => 'products_title_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'products_title_typography_typography' =>
    array (
      'section' => 'products_title_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'products_title_typography',
    ),
  ),
  'group_activators' =>
  array (
    'products_title_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/woocommerce-products-pro.md',
  ),
  'control_count' => 13,
);
