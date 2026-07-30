<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-menu-cart',
  'source' => 'wc',
  'widget_type' => 'woocommerce-menu-cart',
  'title' => 'Menu Cart',
  'icon' => 'eicon-cart',
  'categories' =>
  array (
    0 => 'theme-elements',
    1 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/menu-cart.php',
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
      'id' => 'section_menu_icon_content',
      'label' => 'Menu Icon',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon',
          'type' => 'select',
          'label' => 'Icon',
          'default' => 'cart-medium',
          'options' =>
          array (
            'cart-light' => 'Cart Light',
            'cart-medium' => 'Cart Medium',
            'cart-solid' => 'Cart Solid',
            'basket-light' => 'Basket Light',
            'basket-medium' => 'Basket Medium',
            'basket-solid' => 'Basket Solid',
            'bag-light' => 'Bag Light',
            'bag-medium' => 'Bag Medium',
            'bag-solid' => 'Bag Solid',
            'custom' => 'Custom',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'menu_icon_svg',
          'type' => 'icons',
          'label' => 'Custom Icon',
          'default' =>
          array (
            'value' => 'fas fa-shopping-cart',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'icon' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'items_indicator',
          'type' => 'select',
          'label' => 'Items Indicator',
          'default' => 'bubble',
          'options' =>
          array (
            'none' => 'None',
            'bubble' => 'Bubble',
            'plain' => 'Plain',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'hide_empty_indicator',
          'type' => 'switcher',
          'label' => 'Hide Empty',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'items_indicator!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'show_subtotal',
          'type' => 'switcher',
          'label' => 'Subtotal',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
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
          ),
          'condition' => NULL,
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
    1 =>
    array (
      'id' => 'section_cart',
      'label' => 'Cart',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'cart_type',
          'type' => 'select',
          'label' => 'Cart Type',
          'default' => 'side-cart',
          'options' =>
          array (
            'side-cart' => 'Side Cart',
            'mini-cart' => 'Mini Cart',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'open_cart',
          'type' => 'select',
          'label' => 'Open Cart',
          'default' => 'click',
          'options' =>
          array (
            'click' => 'On Click',
            'mouseover' => 'On Hover',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'side_cart_alignment',
          'type' => 'choose',
          'label' => 'Cart Position',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'end' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'cart_type' => 'side-cart',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'mini_cart_alignment',
          'type' => 'choose',
          'label' => 'Cart Position',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-h-align-center',
            ),
            'end' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'cart_type' => 'mini-cart',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'mini_cart_spacing',
          'type' => 'slider',
          'label' => 'Distance',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'cart_type' => 'mini-cart',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'heading_close_cart_button',
          'type' => 'heading',
          'label' => 'Close Cart',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'close_cart_button_show',
          'type' => 'switcher',
          'label' => 'Close Icon',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'close_cart_icon_svg',
          'type' => 'icons',
          'label' => 'Custom Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'close_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'close_cart_button_alignment',
          'type' => 'choose',
          'label' => 'Icon Position',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'end' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'close_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'heading_remove_item_button',
          'type' => 'heading',
          'label' => 'Remove Item',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'show_remove_icon',
          'type' => 'switcher',
          'label' => 'Remove Item Icon',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'remove_item_button_position',
          'type' => 'choose',
          'label' => 'Icon Position',
          'default' => '',
          'options' =>
          array (
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'middle' =>
            array (
              'title' => 'Middle',
              'icon' => 'eicon-v-align-middle',
            ),
            'bottom' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'show_remove_icon!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'heading_price_quantity',
          'type' => 'heading',
          'label' => 'Price and Quantity',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'price_quantity_position',
          'type' => 'choose',
          'label' => 'Position',
          'default' => NULL,
          'options' =>
          array (
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'bottom' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'show_divider',
          'type' => 'switcher',
          'label' => 'Cart Dividers',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'heading_buttons',
          'type' => 'heading',
          'label' => 'Buttons',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'view_cart_button_show',
          'type' => 'switcher',
          'label' => 'View Cart',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'view_cart_button_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'end' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-text-align-right',
            ),
            'justify' =>
            array (
              'title' => 'Justify',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' =>
          array (
            'view_cart_button_show!' => '',
            'checkout_button_show' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'checkout_button_show',
          'type' => 'switcher',
          'label' => 'Checkout',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'checkout_button_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'end' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-text-align-right',
            ),
            'justify' =>
            array (
              'title' => 'Justify',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' =>
          array (
            'checkout_button_show!' => '',
            'view_cart_button_show' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        20 =>
        array (
          'key' => 'checkout_button_display',
          'type' => 'hidden',
          'label' => 'Alignment',
          'default' => '--cart-footer-buttons-alignment-display: none;',
          'options' => NULL,
          'condition' =>
          array (
            'checkout_button_show' => '',
            'view_cart_button_show' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        21 =>
        array (
          'key' => 'buttons_position',
          'type' => 'choose',
          'label' => 'Vertical Position',
          'default' => '',
          'options' =>
          array (
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'bottom' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'cart_type' => 'side-cart',
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
    2 =>
    array (
      'id' => 'section_additional_options',
      'label' => 'Additional Options',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_additional_options',
          'type' => 'heading',
          'label' => 'Cart',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'automatically_open_cart',
          'type' => 'switcher',
          'label' => 'Automatically Open Cart',
          'default' => 'no',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Open the cart every time an item is added.',
        ),
        2 =>
        array (
          'key' => 'automatically_update_cart',
          'type' => 'switcher',
          'label' => 'Automatically Update Cart',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Updates to the cart (e.g., a removed item) via Ajax. The cart will update without refreshing the whole page.',
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
      'id' => 'section_toggle_style',
      'label' => 'Menu Icon',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'toggle_button_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_subtotal!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'toggle_button_icon_color',
          'type' => 'color',
          'label' => 'Icon Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'toggle_button_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'toggle_button_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'toggle_button_hover_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_subtotal!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'toggle_button_hover_icon_color',
          'type' => 'color',
          'label' => 'Icon Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'toggle_button_hover_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'toggle_button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'toggle_button_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'toggle_button_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'heading_icon_style',
          'type' => 'heading',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'toggle_icon_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'toggle_icon_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_subtotal!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'toggle_icon_position',
          'type' => 'choose',
          'label' => 'Position',
          'default' => NULL,
          'options' =>
          array (
            'row-reverse' =>
            array (
              'title' => 'Start',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
            'row' =>
            array (
              'title' => 'End',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
          ),
          'condition' =>
          array (
            'show_subtotal!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'toggle_button_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'items_indicator_style',
          'type' => 'heading',
          'label' => 'Items Indicator',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'items_indicator!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'items_indicator_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'items_indicator!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'items_indicator_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'items_indicator' => 'bubble',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'items_indicator_distance',
          'type' => 'slider',
          'label' => 'Distance',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'items_indicator' => 'bubble',
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
          'group' => 'box-shadow',
          'name' => 'toggle_button_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__toggle .elementor-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'box-shadow',
          'name' => 'toggle_button_hover_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__toggle .elementor-button:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'toggle_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__toggle .elementor-button',
          'condition' =>
          array (
            'show_subtotal!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    4 =>
    array (
      'id' => 'section_cart_style',
      'label' => 'Cart',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'border_type',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => 'none',
          'options' =>
          array (
            'none' => 'None',
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'groove' => 'Groove',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'cart_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'heading_close',
          'type' => 'heading',
          'label' => 'Close Cart',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'close_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'close_cart_icon_size',
          'type' => 'slider',
          'label' => 'Icon Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'close_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'close_cart_icon_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'close_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'close_cart_icon_hover_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'close_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'heading_remove_item_button_style',
          'type' => 'heading',
          'label' => 'Remove Item',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_remove_icon!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'remove_item_button_size',
          'type' => 'slider',
          'label' => 'Icon Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_remove_icon!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'remove_item_button_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_remove_icon!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'remove_item_button_hover_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_remove_icon!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'heading_subtotal_style',
          'type' => 'heading',
          'label' => 'Subtotal',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'subtotal_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'subtotal_alignment',
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
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'subtotal_divider_style',
          'type' => 'select',
          'label' => 'Divider Style',
          'default' => NULL,
          'options' =>
          array (
            '' => 'None',
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'groove' => 'Groove',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'subtotal_divider_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'subtotal_divider_color',
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
          'group' => 'box-shadow',
          'name' => 'cart_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__main',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'subtotal_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__subtotal',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    5 =>
    array (
      'id' => 'section_product_tabs_style',
      'label' => 'Products',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_product_title_style',
          'type' => 'heading',
          'label' => 'Product Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'product_title_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'product_title_hover_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_product_variations_style',
          'type' => 'heading',
          'label' => 'Variations',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'product_variations_color',
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
          'key' => 'heading_product_price_style',
          'type' => 'heading',
          'label' => 'Product Price',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'product_price_color',
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
          'key' => 'heading_quantity_title_style',
          'type' => 'heading',
          'label' => 'Quantity',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'product_quantity_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'heading_product_divider_style',
          'type' => 'heading',
          'label' => 'Divider',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'divider_style',
          'type' => 'select',
          'label' => 'Style',
          'default' => NULL,
          'options' =>
          array (
            '' => 'None',
            'solid' => 'Solid',
            'double' => 'Double',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'groove' => 'Groove',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'divider_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'divider_width',
          'type' => 'slider',
          'label' => 'Weight',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'divider_gap',
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
          'name' => 'product_title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__product-name a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'product_variations_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__product .variation',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'product_price_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__product-price',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'product_quantity_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__product-price .product-quantity',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    6 =>
    array (
      'id' => 'section_style_buttons',
      'label' => 'Buttons',
      'tab' => 'style',
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'view_cart_button_show',
            'operator' => '!=',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'checkout_button_show',
            'operator' => '!=',
            'value' => '',
          ),
        ),
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'buttons_layout',
          'type' => 'select',
          'label' => 'Layout',
          'default' => 'inline',
          'options' =>
          array (
            'inline' => 'Inline',
            'stacked' => 'Stacked',
          ),
          'condition' =>
          array (
            'view_cart_button_show!' => '',
            'checkout_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'space_between_buttons',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view_cart_button_show!' => '',
            'checkout_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'button_border_radius',
          'type' => 'slider',
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
          'key' => 'heading_view_cart_button_style',
          'type' => 'heading',
          'label' => 'View Cart',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'view_cart_button_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'view_cart_button_background_color',
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
          'key' => 'view_cart_button_hover_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'view_cart_button_hover_background',
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
          'key' => 'view_cart_button_border_hover_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view_cart_border_border!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'view_cart_button_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'view_cart_button_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'view_cart_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'heading_checkout_button_style',
          'type' => 'heading',
          'label' => 'Checkout',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'checkout_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'checkout_button_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'checkout_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'checkout_button_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'checkout_button_hover_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'checkout_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'checkout_button_hover_background',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'checkout_button_border_hover_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'checkout_border_border!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'view_checkout_button_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'checkout_button_show!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'view_checkout_button_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'checkout_button_show!' => '',
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
          'name' => 'product_buttons_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__footer-buttons .elementor-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'view_cart_buttons_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__footer-buttons a.elementor-button--view-cart',
          'condition' =>
          array (
            'view_cart_button_show!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'border',
          'name' => 'view_cart_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button--view-cart',
          'condition' =>
          array (
            'view_cart_button_show!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'view_cart_button_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button--view-cart',
          'condition' =>
          array (
            'view_cart_button_show!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'cart_checkout_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-menu-cart__footer-buttons a.elementor-button--checkout',
          'condition' =>
          array (
            'checkout_button_show!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'border',
          'name' => 'checkout_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button--checkout',
          'condition' =>
          array (
            'checkout_button_show!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'box-shadow',
          'name' => 'view_checkout_button_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button--checkout',
          'condition' =>
          array (
            'checkout_button_show!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    7 =>
    array (
      'id' => 'section_style_messages',
      'label' => 'Messages',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'empty_message_color',
          'type' => 'color',
          'label' => 'Empty Cart Message Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'empty_message_alignment',
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
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'cart_empty_message_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-mini-cart__empty-message',
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
      'group' => 'box-shadow',
      'name' => 'toggle_button_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__toggle .elementor-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'box-shadow',
      'name' => 'toggle_button_hover_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__toggle .elementor-button:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'toggle_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__toggle .elementor-button',
      'condition' =>
      array (
        'show_subtotal!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'box-shadow',
      'name' => 'cart_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__main',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'typography',
      'name' => 'subtotal_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__subtotal',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'product_title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__product-name a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'product_variations_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__product .variation',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'typography',
      'name' => 'product_price_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__product-price',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'typography',
      'name' => 'product_quantity_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__product-price .product-quantity',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'typography',
      'name' => 'product_buttons_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__footer-buttons .elementor-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'typography',
      'name' => 'view_cart_buttons_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__footer-buttons a.elementor-button--view-cart',
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'border',
      'name' => 'view_cart_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button--view-cart',
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'box-shadow',
      'name' => 'view_cart_button_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button--view-cart',
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    13 =>
    array (
      'group' => 'typography',
      'name' => 'cart_checkout_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-menu-cart__footer-buttons a.elementor-button--checkout',
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    14 =>
    array (
      'group' => 'border',
      'name' => 'checkout_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button--checkout',
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    15 =>
    array (
      'group' => 'box-shadow',
      'name' => 'view_checkout_button_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button--checkout',
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    16 =>
    array (
      'group' => 'typography',
      'name' => 'cart_empty_message_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-mini-cart__empty-message',
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
    'icon' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'select',
      'default' => 'cart-medium',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'menu_icon_svg' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-shopping-cart',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'icon' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'items_indicator' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'select',
      'default' => 'bubble',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hide_empty_indicator' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'items_indicator!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_subtotal' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alignment' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'cart_type' =>
    array (
      'section' => 'section_cart',
      'type' => 'select',
      'default' => 'side-cart',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'open_cart' =>
    array (
      'section' => 'section_cart',
      'type' => 'select',
      'default' => 'click',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'side_cart_alignment' =>
    array (
      'section' => 'section_cart',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'cart_type' => 'side-cart',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'mini_cart_alignment' =>
    array (
      'section' => 'section_cart',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'cart_type' => 'mini-cart',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'mini_cart_spacing' =>
    array (
      'section' => 'section_cart',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'cart_type' => 'mini-cart',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_close_cart_button' =>
    array (
      'section' => 'section_cart',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'close_cart_button_show' =>
    array (
      'section' => 'section_cart',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'close_cart_icon_svg' =>
    array (
      'section' => 'section_cart',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'close_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'close_cart_button_alignment' =>
    array (
      'section' => 'section_cart',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'close_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_remove_item_button' =>
    array (
      'section' => 'section_cart',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_remove_icon' =>
    array (
      'section' => 'section_cart',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'remove_item_button_position' =>
    array (
      'section' => 'section_cart',
      'type' => 'choose',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'show_remove_icon!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_price_quantity' =>
    array (
      'section' => 'section_cart',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'price_quantity_position' =>
    array (
      'section' => 'section_cart',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_divider' =>
    array (
      'section' => 'section_cart',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_buttons' =>
    array (
      'section' => 'section_cart',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_show' =>
    array (
      'section' => 'section_cart',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_alignment' =>
    array (
      'section' => 'section_cart',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
        'checkout_button_show' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_show' =>
    array (
      'section' => 'section_cart',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_alignment' =>
    array (
      'section' => 'section_cart',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_show!' => '',
        'view_cart_button_show' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_display' =>
    array (
      'section' => 'section_cart',
      'type' => 'hidden',
      'default' => '--cart-footer-buttons-alignment-display: none;',
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_show' => '',
        'view_cart_button_show' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'buttons_position' =>
    array (
      'section' => 'section_cart',
      'type' => 'choose',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'cart_type' => 'side-cart',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_additional_options' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'automatically_open_cart' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'no',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'automatically_update_cart' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_text_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_subtotal!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_icon_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_background_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_border_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_hover_text_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_subtotal!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_hover_icon_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_hover_background_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_hover_border_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_border_width' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_border_radius' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_icon_style' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_icon_size' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_icon_spacing' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'show_subtotal!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_icon_position' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_subtotal!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_padding' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'items_indicator_style' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'items_indicator!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'items_indicator_text_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'items_indicator!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'items_indicator_background_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'items_indicator' => 'bubble',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'items_indicator_distance' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'items_indicator' => 'bubble',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_button_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'toggle_button_normal_box_shadow',
    ),
    'toggle_button_hover_box_shadow_box_shadow' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'toggle_button_hover_box_shadow',
    ),
    'toggle_button_typography_typography' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_subtotal!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'toggle_button_typography',
    ),
    'background_color' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_type' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_width' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_color' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'cart_padding' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_close' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'close_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'close_cart_icon_size' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'close_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'close_cart_icon_color' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'close_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'close_cart_icon_hover_color' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'close_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_remove_item_button_style' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_remove_icon!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'remove_item_button_size' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'show_remove_icon!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'remove_item_button_color' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_remove_icon!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'remove_item_button_hover_color' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_remove_icon!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_subtotal_style' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'subtotal_color' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'subtotal_alignment' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'subtotal_divider_style' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'subtotal_divider_width' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'subtotal_divider_color' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'cart_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'cart_box_shadow',
    ),
    'subtotal_typography_typography' =>
    array (
      'section' => 'section_cart_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'subtotal_typography',
    ),
    'heading_product_title_style' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_title_color' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_title_hover_color' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_product_variations_style' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_variations_color' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_product_price_style' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_price_color' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_quantity_title_style' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_quantity_color' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_product_divider_style' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_style' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_color' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_width' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_gap' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_title_typography_typography' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'product_title_typography',
    ),
    'product_variations_typography_typography' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'product_variations_typography',
    ),
    'product_price_typography_typography' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'product_price_typography',
    ),
    'product_quantity_typography_typography' =>
    array (
      'section' => 'section_product_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'product_quantity_typography',
    ),
    'buttons_layout' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'select',
      'default' => 'inline',
      'responsive' => true,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
        'checkout_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'space_between_buttons' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
        'checkout_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_radius' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_view_cart_button_style' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_text_color' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_background_color' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_hover_text_color' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_hover_background' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_border_hover_color' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view_cart_border_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_border_radius' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_cart_button_padding' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_checkout_button_style' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_text_color' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_background_color' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_hover_text_color' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_hover_background' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_border_hover_color' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_border_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_checkout_button_border_radius' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'view_checkout_button_padding' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_buttons_typography_typography' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'product_buttons_typography',
    ),
    'view_cart_buttons_typography_typography' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'view_cart_buttons_typography',
    ),
    'view_cart_border_border' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'group' => 'border',
      'group_prefix' => 'view_cart_border',
    ),
    'view_cart_button_box_shadow_box_shadow' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'view_cart_button_show!' => '',
      ),
      'group' => 'box-shadow',
      'group_prefix' => 'view_cart_button_box_shadow',
    ),
    'cart_checkout_button_typography_typography' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'cart_checkout_button_typography',
    ),
    'checkout_border_border' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'group' => 'border',
      'group_prefix' => 'checkout_border',
    ),
    'view_checkout_button_box_shadow_box_shadow' =>
    array (
      'section' => 'section_style_buttons',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_show!' => '',
      ),
      'group' => 'box-shadow',
      'group_prefix' => 'view_checkout_button_box_shadow',
    ),
    'empty_message_color' =>
    array (
      'section' => 'section_style_messages',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'empty_message_alignment' =>
    array (
      'section' => 'section_style_messages',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'cart_empty_message_typography_typography' =>
    array (
      'section' => 'section_style_messages',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'cart_empty_message_typography',
    ),
  ),
  'group_activators' =>
  array (
    'toggle_button_normal_box_shadow_box_shadow' => 'yes',
    'toggle_button_hover_box_shadow_box_shadow' => 'yes',
    'toggle_button_typography_typography' => 'custom',
    'cart_box_shadow_box_shadow' => 'yes',
    'subtotal_typography_typography' => 'custom',
    'product_title_typography_typography' => 'custom',
    'product_variations_typography_typography' => 'custom',
    'product_price_typography_typography' => 'custom',
    'product_quantity_typography_typography' => 'custom',
    'product_buttons_typography_typography' => 'custom',
    'view_cart_buttons_typography_typography' => 'custom',
    'view_cart_border_border' => 'solid',
    'view_cart_button_box_shadow_box_shadow' => 'yes',
    'cart_checkout_button_typography_typography' => 'custom',
    'checkout_border_border' => 'solid',
    'view_checkout_button_box_shadow_box_shadow' => 'yes',
    'cart_empty_message_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 122,
);
