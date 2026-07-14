<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-purchase-summary',
  'source' => 'wc',
  'widget_type' => 'woocommerce-purchase-summary',
  'title' => 'Purchase Summary',
  'icon' => 'eicon-purchase-summary',
  'categories' =>
  array (
    0 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'summary',
    2 => 'thank you',
    3 => 'confirmation',
    4 => 'purchase',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/purchase-summary.php',
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
      'id' => 'confirmation_message',
      'label' => 'Confirmation Message',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'confirmation_message_active',
          'type' => 'switcher',
          'label' => 'Confirmation Message',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'confirmation_message_text',
          'type' => 'text',
          'label' => 'Message',
          'default' => 'Thank You. Your order has been received.',
          'options' => NULL,
          'condition' =>
          array (
            'confirmation_message_active!' => '',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'confirmation_message_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
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
            'confirmation_message_active!' => '',
          ),
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
      'id' => 'payment_details',
      'label' => 'Payment Details',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'payment_details_number',
          'type' => 'text',
          'label' => 'Number',
          'default' => 'Order Number:',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'payment_details_date',
          'type' => 'text',
          'label' => 'Date:',
          'default' => 'Order Date:',
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
          'key' => 'payment_details_email',
          'type' => 'text',
          'label' => 'Email',
          'default' => 'Order Email:',
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
          'key' => 'payment_details_total',
          'type' => 'text',
          'label' => 'Total',
          'default' => 'Order Total:',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'payment_details_payment',
          'type' => 'text',
          'label' => 'Payment',
          'default' => 'Payment Method:',
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
    2 =>
    array (
      'id' => 'bank_details',
      'label' => 'Bank Details',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'bank_details_text',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Our Bank Details',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'bank_details_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
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
    3 =>
    array (
      'id' => 'downloads',
      'label' => 'Downloads',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'downloads_text',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Downloads',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'downloads_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
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
    4 =>
    array (
      'id' => 'order_summary',
      'label' => 'Purchase Summary',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'order_summary_text',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Order Details',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'order_summary_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
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
    5 =>
    array (
      'id' => 'billing_details',
      'label' => 'Billing Details',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'billing_details_text',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Billing Details',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'billing_details_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
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
    6 =>
    array (
      'id' => 'shipping_details',
      'label' => 'Shipping Address',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'shipping_details_text',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Shipping Details',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'shipping_details_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
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
    7 =>
    array (
      'id' => 'preview_order',
      'label' => 'Preview Settings',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'preview_order_type',
          'type' => 'select',
          'label' => 'Preview order with',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Latest Order',
            'custom-order' => 'Order ID',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'preview_order_custom',
          'type' => 'text',
          'label' => 'Order ID',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'preview_order_type' => 'custom-order',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Note: To find an order ID, go to the WP dashboard: WooCommerce > Orders',
        ),
      ),
      'group_controls' =>
      array (
      ),
      'repeaters' =>
      array (
      ),
    ),
    8 =>
    array (
      'id' => 'sections_tabs_style',
      'label' => 'Sections',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'sections_background_color',
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
          'key' => 'sections_border_type',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => '->get_custom_border_type_options()',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'sections_border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'sections_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'sections_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'sections_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'sections_border_radius',
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
          'key' => 'sections_padding',
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
          'key' => 'sections_spacing',
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
          'group' => 'box-shadow',
          'name' => 'sections_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table, {{WRAPPER}} address',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    9 =>
    array (
      'id' => 'typography_title',
      'label' => 'Typography',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'confirmation_message_title',
          'type' => 'heading',
          'label' => 'Confirmation Message',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'confirmation_message_color',
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
          'key' => 'titles_title',
          'type' => 'heading',
          'label' => 'Titles',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'titles_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'titles_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'general_text_title',
          'type' => 'heading',
          'label' => 'General Text',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'general_text_color',
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
          'name' => 'confirmation_message_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-thankyou-order-received',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'confirmation_message_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-thankyou-order-received',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} h2',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'text-shadow',
          'name' => 'titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} h2',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'general_text_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} address, {{WRAPPER}} .product-purchase-note, {{WRAPPER}} .woocommerce-thankyou-order-details + p',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    10 =>
    array (
      'id' => 'payment_details_title',
      'label' => 'Payment Details',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'payment_details_space_between',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'payment_details_titles_title',
          'type' => 'heading',
          'label' => 'Titles',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'payment_details_titles_color',
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
          'key' => 'payment_details_titles_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'payment_details_items_title',
          'type' => 'heading',
          'label' => 'Items',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'payment_details_items_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'payment_details_dividers_title',
          'type' => 'heading',
          'label' => 'Dividers',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'payment_details_border_type',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => '->get_custom_border_type_options()',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'payment_details_border_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'payment_details_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'payment_details_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'payment_details_border_type!' => 'none',
          ),
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
          'name' => 'payment_details_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-order-overview.order_details li',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'text_decoration',
          ),
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'payment_details_titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-order-overview.order_details li',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'payment_details_items_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-order-overview.order_details li strong',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'text_decoration',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    11 =>
    array (
      'id' => 'bank_details_title',
      'label' => 'Bank Details',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'bank_details_space_between',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'bank_details_account_title',
          'type' => 'heading',
          'label' => 'Account Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'account_title_color',
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
          'key' => 'account_title_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'bank_details_titles_title',
          'type' => 'heading',
          'label' => 'Titles',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'bank_details_titles_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'bank_details_titles_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'bank_details_items_title',
          'type' => 'heading',
          'label' => 'Items',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'bank_details_items_color',
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
          'key' => 'bank_details_dividers_title',
          'type' => 'heading',
          'label' => 'Dividers',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'bank_details_border_type',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => '->get_custom_border_type_options()',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'bank_details_border_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'bank_details_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'bank_details_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'bank_details_border_type!' => 'none',
          ),
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
          'name' => 'account_title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .wc-bacs-bank-details-account-name',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'account_title_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .wc-bacs-bank-details-account-name',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'bank_details_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-bacs-bank-details .wc-bacs-bank-details li',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'text_decoration',
          ),
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'text-shadow',
          'name' => 'bank_details_titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-bacs-bank-details .wc-bacs-bank-details li',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'bank_details_items_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-bacs-bank-details .wc-bacs-bank-details li strong',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'text_decoration',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    12 =>
    array (
      'id' => 'order_details_title',
      'label' => 'Order Details',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'order_details_rows_gap',
          'type' => 'slider',
          'label' => 'Rows Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'order_details_titles_totals',
          'type' => 'heading',
          'label' => 'Titles & Totals',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'order_details_titles_totals_color',
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
          'key' => 'order_details_items_title',
          'type' => 'heading',
          'label' => 'Items',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'order_details_items_color',
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
          'key' => 'order_details_variations_title',
          'type' => 'heading',
          'label' => 'Variations',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'order_details_variations_color',
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
          'key' => 'order_details_product_links_title',
          'type' => 'heading',
          'label' => 'Product Link',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'order_details_product_links_normal_color',
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
          'key' => 'order_details_product_links_hover_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'order_details_dividers_title',
          'type' => 'heading',
          'label' => 'Dividers',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'order_details_border_type',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => '->get_custom_border_type_options()',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'order_details_border_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_details_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'order_details_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_details_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'order_details_button_title',
          'type' => 'heading',
          'label' => 'Buttons',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'order_details_button_normal_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'order_details_button_hover_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'order_details_button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_details_button_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'order_details_button_hover_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration (ms)',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'order_details_button_hover_animation',
          'type' => 'hover_animation',
          'label' => 'Hover Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        20 =>
        array (
          'key' => 'order_details_button_border_type',
          'type' => 'select',
          'label' => 'Border Type',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => '->get_custom_border_type_options()',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        21 =>
        array (
          'key' => 'order_details_button_border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_details_button_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        22 =>
        array (
          'key' => 'order_details_button_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_details_button_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        23 =>
        array (
          'key' => 'order_details_button_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        24 =>
        array (
          'key' => 'order_details_button_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
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
          'name' => 'order_details_titles_totals_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table thead tr th, {{WRAPPER}} .shop_table tfoot th, {{WRAPPER}} .shop_table tfoot tr td, {{WRAPPER}} .shop_table tfoot tr td span, {{WRAPPER}} .woocommerce-table--order-downloads tr td:before',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'order_details_titles_totals_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table thead tr th, {{WRAPPER}} .shop_table tfoot th, {{WRAPPER}} .shop_table tfoot tr td, {{WRAPPER}} .shop_table tfoot tr td span, {{WRAPPER}} .woocommerce-table--order-downloads tr td:before',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'order_details_items_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .product-quantity, {{WRAPPER}} .woocommerce-table--order-details td a, {{WRAPPER}} td.product-total, {{WRAPPER}} td.download-product, {{WRAPPER}} td.download-remaining, {{WRAPPER}} td.download-expires, {{WRAPPER}} td.download-file',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'order_details_variations_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .product-name .wc-item-meta .wc-item-meta-label, {{WRAPPER}} .wc-item-meta li p',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'order_details_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'text-shadow',
          'name' => 'order_details_button_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'background',
          'name' => 'order_details_button_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        7 =>
        array (
          'group' => 'box-shadow',
          'name' => 'order_details_button_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        8 =>
        array (
          'group' => 'background',
          'name' => 'order_details_button_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button:hover, {{WRAPPER}} .order-again .button:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        9 =>
        array (
          'group' => 'box-shadow',
          'name' => 'order_details_button_hover_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button:hover, {{WRAPPER}} .order-again .button:hover',
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
      'name' => 'sections_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table, {{WRAPPER}} address',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'confirmation_message_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-thankyou-order-received',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'confirmation_message_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-thankyou-order-received',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} h2',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'text-shadow',
      'name' => 'titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} h2',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'general_text_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} address, {{WRAPPER}} .product-purchase-note, {{WRAPPER}} .woocommerce-thankyou-order-details + p',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'payment_details_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-order-overview.order_details li',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'text_decoration',
      ),
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'text-shadow',
      'name' => 'payment_details_titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-order-overview.order_details li',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'typography',
      'name' => 'payment_details_items_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-order-overview.order_details li strong',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'text_decoration',
      ),
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'typography',
      'name' => 'account_title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .wc-bacs-bank-details-account-name',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'text-shadow',
      'name' => 'account_title_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .wc-bacs-bank-details-account-name',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'typography',
      'name' => 'bank_details_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-bacs-bank-details .wc-bacs-bank-details li',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'text_decoration',
      ),
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'text-shadow',
      'name' => 'bank_details_titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-bacs-bank-details .wc-bacs-bank-details li',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    13 =>
    array (
      'group' => 'typography',
      'name' => 'bank_details_items_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-bacs-bank-details .wc-bacs-bank-details li strong',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'text_decoration',
      ),
      'include' => NULL,
    ),
    14 =>
    array (
      'group' => 'typography',
      'name' => 'order_details_titles_totals_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table thead tr th, {{WRAPPER}} .shop_table tfoot th, {{WRAPPER}} .shop_table tfoot tr td, {{WRAPPER}} .shop_table tfoot tr td span, {{WRAPPER}} .woocommerce-table--order-downloads tr td:before',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    15 =>
    array (
      'group' => 'text-shadow',
      'name' => 'order_details_titles_totals_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table thead tr th, {{WRAPPER}} .shop_table tfoot th, {{WRAPPER}} .shop_table tfoot tr td, {{WRAPPER}} .shop_table tfoot tr td span, {{WRAPPER}} .woocommerce-table--order-downloads tr td:before',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    16 =>
    array (
      'group' => 'typography',
      'name' => 'order_details_items_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .product-quantity, {{WRAPPER}} .woocommerce-table--order-details td a, {{WRAPPER}} td.product-total, {{WRAPPER}} td.download-product, {{WRAPPER}} td.download-remaining, {{WRAPPER}} td.download-expires, {{WRAPPER}} td.download-file',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    17 =>
    array (
      'group' => 'typography',
      'name' => 'order_details_variations_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .product-name .wc-item-meta .wc-item-meta-label, {{WRAPPER}} .wc-item-meta li p',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    18 =>
    array (
      'group' => 'typography',
      'name' => 'order_details_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    19 =>
    array (
      'group' => 'text-shadow',
      'name' => 'order_details_button_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    20 =>
    array (
      'group' => 'background',
      'name' => 'order_details_button_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    21 =>
    array (
      'group' => 'box-shadow',
      'name' => 'order_details_button_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    22 =>
    array (
      'group' => 'background',
      'name' => 'order_details_button_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button:hover, {{WRAPPER}} .order-again .button:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    23 =>
    array (
      'group' => 'box-shadow',
      'name' => 'order_details_button_hover_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button:hover, {{WRAPPER}} .order-again .button:hover',
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
    'confirmation_message_active' =>
    array (
      'section' => 'confirmation_message',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'confirmation_message_text' =>
    array (
      'section' => 'confirmation_message',
      'type' => 'text',
      'default' => 'Thank You. Your order has been received.',
      'responsive' => false,
      'condition' =>
      array (
        'confirmation_message_active!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'confirmation_message_alignment' =>
    array (
      'section' => 'confirmation_message',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'confirmation_message_active!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_number' =>
    array (
      'section' => 'payment_details',
      'type' => 'text',
      'default' => 'Order Number:',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_date' =>
    array (
      'section' => 'payment_details',
      'type' => 'text',
      'default' => 'Order Date:',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_email' =>
    array (
      'section' => 'payment_details',
      'type' => 'text',
      'default' => 'Order Email:',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_total' =>
    array (
      'section' => 'payment_details',
      'type' => 'text',
      'default' => 'Order Total:',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_payment' =>
    array (
      'section' => 'payment_details',
      'type' => 'text',
      'default' => 'Payment Method:',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_text' =>
    array (
      'section' => 'bank_details',
      'type' => 'text',
      'default' => 'Our Bank Details',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_alignment' =>
    array (
      'section' => 'bank_details',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'downloads_text' =>
    array (
      'section' => 'downloads',
      'type' => 'text',
      'default' => 'Downloads',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'downloads_alignment' =>
    array (
      'section' => 'downloads',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_text' =>
    array (
      'section' => 'order_summary',
      'type' => 'text',
      'default' => 'Order Details',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_alignment' =>
    array (
      'section' => 'order_summary',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_text' =>
    array (
      'section' => 'billing_details',
      'type' => 'text',
      'default' => 'Billing Details',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_alignment' =>
    array (
      'section' => 'billing_details',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_details_text' =>
    array (
      'section' => 'shipping_details',
      'type' => 'text',
      'default' => 'Shipping Details',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_details_alignment' =>
    array (
      'section' => 'shipping_details',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'preview_order_type' =>
    array (
      'section' => 'preview_order',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'preview_order_custom' =>
    array (
      'section' => 'preview_order',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'preview_order_type' => 'custom-order',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_background_color' =>
    array (
      'section' => 'sections_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_type' =>
    array (
      'section' => 'sections_tabs_style',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_width' =>
    array (
      'section' => 'sections_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'sections_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_color' =>
    array (
      'section' => 'sections_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'sections_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_radius' =>
    array (
      'section' => 'sections_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_padding' =>
    array (
      'section' => 'sections_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_spacing' =>
    array (
      'section' => 'sections_tabs_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_box_shadow_box_shadow' =>
    array (
      'section' => 'sections_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'sections_box_shadow',
    ),
    'confirmation_message_title' =>
    array (
      'section' => 'typography_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'confirmation_message_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'titles_title' =>
    array (
      'section' => 'typography_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'titles_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'titles_spacing' =>
    array (
      'section' => 'typography_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'general_text_title' =>
    array (
      'section' => 'typography_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'general_text_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'confirmation_message_typography_typography' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'confirmation_message_typography',
    ),
    'confirmation_message_text_shadow_text_shadow' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'confirmation_message_text_shadow',
    ),
    'titles_typography_typography' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'titles_typography',
    ),
    'titles_text_shadow_text_shadow' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'titles_text_shadow',
    ),
    'general_text_typography_typography' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'general_text_typography',
    ),
    'payment_details_space_between' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_titles_title' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_titles_color' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_titles_spacing' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_items_title' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_items_color' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_dividers_title' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_border_type' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_border_width' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'payment_details_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_border_color' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'payment_details_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_details_titles_typography_typography' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'payment_details_titles_typography',
    ),
    'payment_details_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'payment_details_titles_text_shadow',
    ),
    'payment_details_items_typography_typography' =>
    array (
      'section' => 'payment_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'payment_details_items_typography',
    ),
    'bank_details_space_between' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_account_title' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'account_title_color' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'account_title_spacing' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_titles_title' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_titles_color' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_titles_spacing' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_items_title' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_items_color' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_dividers_title' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_border_type' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_border_width' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'bank_details_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bank_details_border_color' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'bank_details_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'account_title_typography_typography' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'account_title_typography',
    ),
    'account_title_text_shadow_text_shadow' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'account_title_text_shadow',
    ),
    'bank_details_titles_typography_typography' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'bank_details_titles_typography',
    ),
    'bank_details_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'bank_details_titles_text_shadow',
    ),
    'bank_details_items_typography_typography' =>
    array (
      'section' => 'bank_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'bank_details_items_typography',
    ),
    'order_details_rows_gap' =>
    array (
      'section' => 'order_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_titles_totals' =>
    array (
      'section' => 'order_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_titles_totals_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_items_title' =>
    array (
      'section' => 'order_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_items_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_variations_title' =>
    array (
      'section' => 'order_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_variations_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_product_links_title' =>
    array (
      'section' => 'order_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_product_links_normal_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_product_links_hover_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_dividers_title' =>
    array (
      'section' => 'order_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_border_type' =>
    array (
      'section' => 'order_details_title',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_border_width' =>
    array (
      'section' => 'order_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'order_details_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_border_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'order_details_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_title' =>
    array (
      'section' => 'order_details_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_normal_text_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_hover_text_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_hover_border_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'order_details_button_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_hover_transition_duration' =>
    array (
      'section' => 'order_details_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_hover_animation' =>
    array (
      'section' => 'order_details_title',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_border_type' =>
    array (
      'section' => 'order_details_title',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_border_width' =>
    array (
      'section' => 'order_details_title',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'order_details_button_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_border_color' =>
    array (
      'section' => 'order_details_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'order_details_button_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_border_radius' =>
    array (
      'section' => 'order_details_title',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_button_padding' =>
    array (
      'section' => 'order_details_title',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_details_titles_totals_typography_typography' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_details_titles_totals_typography',
    ),
    'order_details_titles_totals_text_shadow_text_shadow' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'order_details_titles_totals_text_shadow',
    ),
    'order_details_items_typography_typography' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_details_items_typography',
    ),
    'order_details_variations_typography_typography' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_details_variations_typography',
    ),
    'order_details_button_typography_typography' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_details_button_typography',
    ),
    'order_details_button_text_shadow_text_shadow' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'order_details_button_text_shadow',
    ),
    'order_details_button_normal_background_background' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'order_details_button_normal_background',
    ),
    'order_details_button_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'order_details_button_normal_box_shadow',
    ),
    'order_details_button_hover_background_background' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'order_details_button_hover_background',
    ),
    'order_details_button_hover_box_shadow_box_shadow' =>
    array (
      'section' => 'order_details_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'order_details_button_hover_box_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'sections_box_shadow_box_shadow' => 'yes',
    'confirmation_message_typography_typography' => 'custom',
    'confirmation_message_text_shadow_text_shadow' => 'yes',
    'titles_typography_typography' => 'custom',
    'titles_text_shadow_text_shadow' => 'yes',
    'general_text_typography_typography' => 'custom',
    'payment_details_titles_typography_typography' => 'custom',
    'payment_details_titles_text_shadow_text_shadow' => 'yes',
    'payment_details_items_typography_typography' => 'custom',
    'account_title_typography_typography' => 'custom',
    'account_title_text_shadow_text_shadow' => 'yes',
    'bank_details_titles_typography_typography' => 'custom',
    'bank_details_titles_text_shadow_text_shadow' => 'yes',
    'bank_details_items_typography_typography' => 'custom',
    'order_details_titles_totals_typography_typography' => 'custom',
    'order_details_titles_totals_text_shadow_text_shadow' => 'yes',
    'order_details_items_typography_typography' => 'custom',
    'order_details_variations_typography_typography' => 'custom',
    'order_details_button_typography_typography' => 'custom',
    'order_details_button_text_shadow_text_shadow' => 'yes',
    'order_details_button_normal_background_background' => 'classic',
    'order_details_button_normal_box_shadow_box_shadow' => 'yes',
    'order_details_button_hover_background_background' => 'classic',
    'order_details_button_hover_box_shadow_box_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 106,
);
