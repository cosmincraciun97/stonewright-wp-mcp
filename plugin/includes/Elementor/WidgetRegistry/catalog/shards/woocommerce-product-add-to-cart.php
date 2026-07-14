<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-product-add-to-cart',
  'source' => 'wc',
  'widget_type' => 'woocommerce-product-add-to-cart',
  'title' => 'Add To Cart',
  'icon' => 'eicon-product-add-to-cart',
  'categories' =>
  array (
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
  'file' => 'pro-elements/modules/woocommerce/widgets/product-add-to-cart.php',
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
      'tab' => 'style',
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
    1 =>
    array (
      'id' => 'section_atc_button_style',
      'label' => 'Button',
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
          'key' => 'alignment',
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
            'justify' =>
            array (
              'title' => 'Justified',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'button_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'button_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'button_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'button_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'button_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'button_text_color_hover',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'button_bg_color_hover',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'button_border_color_hover',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'button_transition',
          'type' => 'slider',
          'label' => 'Transition Duration (s)',
          'default' =>
          array (
            'size' => 0.2,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'heading_view_cart_style',
          'type' => 'heading',
          'label' => 'View Cart',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'view_cart_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'view_cart_spacing',
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
          'name' => 'button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .cart button, {{WRAPPER}} .cart .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'border',
          'name' => 'button_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .cart button, {{WRAPPER}} .cart .button',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'color',
          ),
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'view_cart_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .added_to_cart',
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
      'id' => 'section_atc_quantity_style',
      'label' => 'Quantity',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'show_quantity',
          'type' => 'switcher',
          'label' => 'Quantity',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_quantity!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'quantity_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_quantity!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'quantity_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_quantity!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'quantity_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'quantity_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'quantity_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'quantity_text_color_focus',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'quantity_bg_color_focus',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'quantity_border_color_focus',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'quantity_transition',
          'type' => 'slider',
          'label' => 'Transition Duration (s)',
          'default' =>
          array (
            'size' => 0.2,
          ),
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
          'name' => 'quantity_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .quantity .qty',
          'condition' =>
          array (
            'show_quantity!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'border',
          'name' => 'quantity_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .quantity .qty',
          'condition' =>
          array (
            'show_quantity!' => '',
          ),
          'exclude' =>
          array (
            0 => 'color',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    3 =>
    array (
      'id' => 'section_atc_variations_style',
      'label' => 'Variations',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'variations_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'variations_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'variations_space_between',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_variations_label_style',
          'type' => 'heading',
          'label' => 'Label',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'variations_label_color_focus',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'heading_variations_select_style',
          'type' => 'heading',
          'label' => 'Select field',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'variations_select_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'variations_select_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'variations_select_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'variations_select_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
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
          'name' => 'variations_label_typography',
          'label' => NULL,
          'selector' => '.woocommerce {{WRAPPER}} form.cart table.variations label',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'variations_select_typography',
          'label' => NULL,
          'selector' => '.woocommerce {{WRAPPER}} form.cart table.variations td.value select, .woocommerce div.product.elementor{{WRAPPER}} form.cart table.variations td.value:before',
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
      'name' => 'button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .cart button, {{WRAPPER}} .cart .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'border',
      'name' => 'button_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .cart button, {{WRAPPER}} .cart .button',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'color',
      ),
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'view_cart_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .added_to_cart',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'quantity_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .quantity .qty',
      'condition' =>
      array (
        'show_quantity!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'border',
      'name' => 'quantity_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .quantity .qty',
      'condition' =>
      array (
        'show_quantity!' => '',
      ),
      'exclude' =>
      array (
        0 => 'color',
      ),
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'variations_label_typography',
      'label' => NULL,
      'selector' => '.woocommerce {{WRAPPER}} form.cart table.variations label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'variations_select_typography',
      'label' => NULL,
      'selector' => '.woocommerce {{WRAPPER}} form.cart table.variations td.value select, .woocommerce div.product.elementor{{WRAPPER}} form.cart table.variations td.value:before',
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
    'wc_style_warning' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alignment' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_radius' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_padding' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_bg_color' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_color' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color_hover' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_bg_color_hover' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_color_hover' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_transition' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0.2,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_view_cart_style' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_color' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_spacing' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_typography_typography' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'button_typography',
    ),
    'button_border_border' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'button_border',
    ),
    'view_cart_typography_typography' =>
    array (
      'section' => 'section_atc_button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'view_cart_typography',
    ),
    'show_quantity' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'spacing' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'show_quantity!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_border_radius' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_quantity!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_padding' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_quantity!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_text_color' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_bg_color' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_border_color' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_text_color_focus' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_bg_color_focus' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_border_color_focus' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_transition' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0.2,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quantity_typography_typography' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_quantity!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'quantity_typography',
    ),
    'quantity_border_border' =>
    array (
      'section' => 'section_atc_quantity_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_quantity!' => '',
      ),
      'group' => 'border',
      'group_prefix' => 'quantity_border',
    ),
    'variations_width' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_spacing' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_space_between' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_variations_label_style' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_label_color_focus' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_variations_select_style' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_select_color' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_select_bg_color' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_select_border_color' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_select_border_radius' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_label_typography_typography' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'variations_label_typography',
    ),
    'variations_select_typography_typography' =>
    array (
      'section' => 'section_atc_variations_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'variations_select_typography',
    ),
  ),
  'group_activators' =>
  array (
    'button_typography_typography' => 'custom',
    'button_border_border' => 'solid',
    'view_cart_typography_typography' => 'custom',
    'quantity_typography_typography' => 'custom',
    'quantity_border_border' => 'solid',
    'variations_label_typography_typography' => 'custom',
    'variations_select_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 43,
);
