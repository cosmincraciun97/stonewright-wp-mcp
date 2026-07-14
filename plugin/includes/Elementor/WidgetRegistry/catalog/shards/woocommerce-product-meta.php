<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-product-meta',
  'source' => 'wc',
  'widget_type' => 'woocommerce-product-meta',
  'title' => 'Product Meta',
  'icon' => 'eicon-product-meta',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'shop',
    2 => 'store',
    3 => 'meta',
    4 => 'data',
    5 => 'product',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/product-meta.php',
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
      'id' => 'section_product_meta_style',
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
          'key' => 'view',
          'type' => 'select',
          'label' => 'View',
          'default' => 'inline',
          'options' =>
          array (
            'table' => 'Table',
            'stacked' => 'Stacked',
            'inline' => 'Inline',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'space_between',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'divider',
          'type' => 'switcher',
          'label' => 'Divider',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'divider_style',
          'type' => 'select',
          'label' => 'Style',
          'default' => 'solid',
          'options' =>
          array (
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
          ),
          'condition' =>
          array (
            'divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'divider_weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' =>
          array (
            'size' => 1,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'divider_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'divider' => 'yes',
            'view!' => 'inline',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'divider_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'divider' => 'yes',
            'view' => 'inline',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'divider_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '#ddd',
          'options' => NULL,
          'condition' =>
          array (
            'divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'heading_text_style',
          'type' => 'heading',
          'label' => 'Text',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'text_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'heading_link_style',
          'type' => 'heading',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'link_color',
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
          'name' => 'text_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}}',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'link_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    1 =>
    array (
      'id' => 'section_product_meta_captions',
      'label' => 'Captions',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_category_caption',
          'type' => 'heading',
          'label' => 'Category',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'category_caption_single',
          'type' => 'text',
          'label' => 'Singular',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'category_caption_plural',
          'type' => 'text',
          'label' => 'Plural',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_tag_caption',
          'type' => 'heading',
          'label' => 'Tag',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'tag_caption_single',
          'type' => 'text',
          'label' => 'Singular',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'tag_caption_plural',
          'type' => 'text',
          'label' => 'Plural',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'heading_sku_caption',
          'type' => 'heading',
          'label' => 'SKU',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'sku_caption',
          'type' => 'text',
          'label' => 'SKU',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'sku_missing_caption',
          'type' => 'text',
          'label' => 'Missing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
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
    0 =>
    array (
      'group' => 'typography',
      'name' => 'text_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}}',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'link_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} a',
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
      'section' => 'section_product_meta_style',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'select',
      'default' => 'inline',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'space_between' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_style' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'select',
      'default' => 'solid',
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_weight' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_width' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => true,
      'condition' =>
      array (
        'divider' => 'yes',
        'view!' => 'inline',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_height' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
        'view' => 'inline',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_color' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'color',
      'default' => '#ddd',
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_text_style' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_link_style' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_color' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_typography_typography' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'text_typography',
    ),
    'link_typography_typography' =>
    array (
      'section' => 'section_product_meta_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'link_typography',
    ),
    'heading_category_caption' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'category_caption_single' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'category_caption_plural' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_tag_caption' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tag_caption_single' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tag_caption_plural' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_sku_caption' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sku_caption' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sku_missing_caption' =>
    array (
      'section' => 'section_product_meta_captions',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'text_typography_typography' => 'custom',
    'link_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 24,
);
