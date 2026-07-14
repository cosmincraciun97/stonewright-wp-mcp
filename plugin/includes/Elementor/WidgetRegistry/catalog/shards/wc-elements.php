<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'wc-elements',
  'source' => 'wc',
  'widget_type' => 'wc-elements',
  'title' => 'WooCommerce Pages',
  'icon' => 'eicon-product-pages',
  'categories' =>
  array (
    0 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'shop',
    2 => 'store',
    3 => 'cart',
    4 => 'checkout',
    5 => 'account',
    6 => 'order tracking',
    7 => 'shortcode',
    8 => 'product',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/elements.php',
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
      'id' => 'section_product',
      'label' => 'Element',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'element',
          'type' => 'select',
          'label' => 'Page',
          'default' => NULL,
          'options' =>
          array (
            '' => '— Select —',
            'woocommerce_cart' => 'Cart Page',
            'product_page' => 'Single Product Page',
            'woocommerce_checkout' => 'Checkout Page',
            'woocommerce_order_tracking' => 'Order Tracking Form',
            'woocommerce_my_account' => 'My Account',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'product_id',
          'type' =>
          array (
            '__unresolved__' => 'QueryModule::QUERY_CONTROL_ID',
          ),
          'label' => 'Product',
          'default' => NULL,
          'options' =>
          array (
          ),
          'condition' =>
          array (
            'element' =>
            array (
              0 => 'product_page',
            ),
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
  ),
  'group_controls' =>
  array (
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'element' =>
    array (
      'section' => 'section_product',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_id' =>
    array (
      'section' => 'section_product',
      'type' =>
      array (
        '__unresolved__' => 'QueryModule::QUERY_CONTROL_ID',
      ),
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'element' =>
        array (
          0 => 'product_page',
        ),
      ),
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
  'control_count' => 2,
);
