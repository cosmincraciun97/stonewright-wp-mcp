<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-product-additional-information',
  'source' => 'wc',
  'widget_type' => 'woocommerce-product-additional-information',
  'title' => 'Additional Information',
  'icon' => ' eicon-product-info',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/product-additional-information.php',
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
      'id' => 'section_additional_info_style',
      'label' => 'General',
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
          'key' => 'content_color',
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
          'group' => 'typography',
          'name' => 'heading_typography',
          'label' => NULL,
          'selector' => '.woocommerce {{WRAPPER}} h2',
          'condition' =>
          array (
            'show_heading!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'content_typography',
          'label' => NULL,
          'selector' => '.woocommerce {{WRAPPER}} .shop_attributes',
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
      'group' => 'typography',
      'name' => 'heading_typography',
      'label' => NULL,
      'selector' => '.woocommerce {{WRAPPER}} h2',
      'condition' =>
      array (
        'show_heading!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'content_typography',
      'label' => NULL,
      'selector' => '.woocommerce {{WRAPPER}} .shop_attributes',
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
    'show_heading' =>
    array (
      'section' => 'section_additional_info_style',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_color' =>
    array (
      'section' => 'section_additional_info_style',
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
    'content_color' =>
    array (
      'section' => 'section_additional_info_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_typography_typography' =>
    array (
      'section' => 'section_additional_info_style',
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
    'content_typography_typography' =>
    array (
      'section' => 'section_additional_info_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'content_typography',
    ),
  ),
  'group_activators' =>
  array (
    'heading_typography_typography' => 'custom',
    'content_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 5,
);
