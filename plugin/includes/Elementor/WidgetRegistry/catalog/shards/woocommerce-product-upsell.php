<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-product-upsell',
  'source' => 'wc',
  'widget_type' => 'woocommerce-product-upsell',
  'title' => 'Upsells',
  'icon' => 'eicon-product-upsell',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'shop',
    2 => 'store',
    3 => 'upsell',
    4 => 'product',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/product-upsell.php',
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
      'id' => 'section_upsell_content',
      'label' => 'Upsells',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'orderby',
          'type' => 'select',
          'label' => 'Order By',
          'default' => 'date',
          'options' =>
          array (
            'date' => 'Date',
            'title' => 'Title',
            'price' => 'Price',
            'popularity' => 'Popularity',
            'rating' => 'Rating',
            'rand' => 'Random',
            'menu_order' => 'Menu Order',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
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
    1 =>
    array (
      'id' => 'section_heading_style',
      'label' => 'Heading',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'show_heading',
          'type' => 'switcher',
          'label' => 'Heading',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'heading_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_heading!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'heading_text_align',
          'type' => 'choose',
          'label' => 'Text Align',
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
            'show_heading!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_heading!' => '',
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
          'name' => 'heading_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}}.elementor-wc-products .products > h2',
          'condition' =>
          array (
            'show_heading!' => '',
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
      'name' => 'heading_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}}.elementor-wc-products .products > h2',
      'condition' =>
      array (
        'show_heading!' => '',
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
    'orderby' =>
    array (
      'section' => 'section_upsell_content',
      'type' => 'select',
      'default' => 'date',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order' =>
    array (
      'section' => 'section_upsell_content',
      'type' => 'select',
      'default' => 'desc',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_heading' =>
    array (
      'section' => 'section_heading_style',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_color' =>
    array (
      'section' => 'section_heading_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_heading!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_text_align' =>
    array (
      'section' => 'section_heading_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'show_heading!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_spacing' =>
    array (
      'section' => 'section_heading_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'show_heading!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_typography_typography' =>
    array (
      'section' => 'section_heading_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_heading!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'heading_typography',
    ),
  ),
  'group_activators' =>
  array (
    'heading_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 7,
);
