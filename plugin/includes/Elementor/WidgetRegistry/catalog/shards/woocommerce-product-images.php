<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-product-images',
  'source' => 'wc',
  'widget_type' => 'woocommerce-product-images',
  'title' => 'Product Images',
  'icon' => 'eicon-product-images',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'shop',
    2 => 'store',
    3 => 'image',
    4 => 'product',
    5 => 'gallery',
    6 => 'lightbox',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/product-images.php',
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
      'id' => 'section_product_gallery_style',
      'label' => 'Style',
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
          'key' => 'sale_flash',
          'type' => 'switcher',
          'label' => 'Sale Flash',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
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
        3 =>
        array (
          'key' => 'spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'heading_thumbs_style',
          'type' => 'heading',
          'label' => 'Thumbnails',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'thumbs_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'spacing_thumbs',
          'type' => 'slider',
          'label' => 'Spacing',
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
          'selector' => '.woocommerce {{WRAPPER}} .woocommerce-product-gallery__trigger + .woocommerce-product-gallery__wrapper,
				.woocommerce {{WRAPPER}} .flex-viewport, .woocommerce {{WRAPPER}} .flex-control-thumbs img',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'border',
          'name' => 'thumbs_border',
          'label' => NULL,
          'selector' => '.woocommerce {{WRAPPER}} .flex-control-thumbs img',
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
      'selector' => '.woocommerce {{WRAPPER}} .woocommerce-product-gallery__trigger + .woocommerce-product-gallery__wrapper,
				.woocommerce {{WRAPPER}} .flex-viewport, .woocommerce {{WRAPPER}} .flex-control-thumbs img',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'border',
      'name' => 'thumbs_border',
      'label' => NULL,
      'selector' => '.woocommerce {{WRAPPER}} .flex-control-thumbs img',
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
    'wc_style_warning' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sale_flash' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_radius' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'spacing' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_thumbs_style' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'thumbs_border_radius' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'spacing_thumbs' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_border_border' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'image_border',
    ),
    'thumbs_border_border' =>
    array (
      'section' => 'section_product_gallery_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'thumbs_border',
    ),
  ),
  'group_activators' =>
  array (
    'image_border_border' => 'solid',
    'thumbs_border_border' => 'solid',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 9,
);
