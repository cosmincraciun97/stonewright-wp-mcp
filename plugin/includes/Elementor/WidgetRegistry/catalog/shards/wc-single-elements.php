<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'wc-single-elements',
  'source' => 'wc',
  'widget_type' => 'wc-single-elements',
  'title' => 'Woo - Single Elements',
  'icon' => 'eicon-woocommerce',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/single-elements.php',
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
          'label' => 'Element',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\PropertyFetch',
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
  'control_count' => 1,
);
