<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'wc-add-to-cart',
  'source' => 'wc',
  'widget_type' => 'wc-add-to-cart',
  'title' => 'Custom Add To Cart',
  'icon' => 'eicon-woocommerce',
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
    4 => 'product',
    5 => 'button',
    6 => 'add to cart',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/add-to-cart.php',
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
      'label' => 'Product',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'product_id',
          'type' =>
          array (
            '__unresolved__' => 'Module::QUERY_CONTROL_ID',
          ),
          'label' => 'Product',
          'default' => NULL,
          'options' =>
          array (
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'show_quantity',
          'type' => 'switcher',
          'label' => 'Show Quantity',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Please note that switching on this option will disable some of the design controls.',
        ),
        2 =>
        array (
          'key' => 'quantity',
          'type' => 'number',
          'label' => 'Quantity',
          'default' => 1,
          'options' => NULL,
          'condition' =>
          array (
            'show_quantity' => '',
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
      'id' => 'section_layout',
      'label' => 'Layout',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'layout',
          'type' => 'select',
          'label' => 'Layout',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Inline',
            'stacked' => 'Stacked',
            'auto' => 'Auto',
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
  ),
  'group_controls' =>
  array (
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'product_id' =>
    array (
      'section' => 'section_product',
      'type' =>
      array (
        '__unresolved__' => 'Module::QUERY_CONTROL_ID',
      ),
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_quantity' =>
    array (
      'section' => 'section_product',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity' =>
    array (
      'section' => 'section_product',
      'type' => 'number',
      'default' => 1,
      'responsive' => false,
      'condition' =>
      array (
        'show_quantity' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'layout' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
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
  'control_count' => 4,
);
