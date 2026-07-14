<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-notices',
  'source' => 'wc',
  'widget_type' => 'woocommerce-notices',
  'title' => 'WooCommerce Notices',
  'icon' => 'eicon-woocommerce-notices',
  'categories' =>
  array (
    0 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'notices',
    2 => 'notifications',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/notices.php',
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
      'id' => 'section',
      'label' => 'WooCommerce Notices',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'where_to_appear_notice',
          'type' => 'raw_html',
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
          'key' => 'site_settings_notice',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'one_per_page_notice',
          'type' => 'alert',
          'label' => NULL,
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
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'where_to_appear_notice' =>
    array (
      'section' => 'section',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'site_settings_notice' =>
    array (
      'section' => 'section',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'one_per_page_notice' =>
    array (
      'section' => 'section',
      'type' => 'alert',
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
  'control_count' => 3,
);
