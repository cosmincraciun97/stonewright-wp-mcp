<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-checkout-page',
  'source' => 'wc',
  'widget_type' => 'woocommerce-checkout-page',
  'title' => 'Checkout',
  'icon' => 'eicon-checkout',
  'categories' =>
  array (
    0 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'checkout',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/checkout.php',
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
      'id' => 'section_content',
      'label' => 'General',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'checkout_layout',
          'type' => 'select',
          'label' => 'Layout',
          'default' => 'two-column',
          'options' =>
          array (
            'two-column' => 'Two columns',
            'one-column' => 'One column',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'sticky_right_column',
          'type' => 'switcher',
          'label' => 'Sticky Right Column',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'checkout_layout' => 'two-column',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'The Order Summary and Payment sections will remain in place while scrolling.',
        ),
        2 =>
        array (
          'key' => 'sticky_right_column_offset',
          'type' => 'number',
          'label' => 'Offset',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'sticky_right_column',
                'operator' => '!==',
                'value' => '',
              ),
              1 =>
              array (
                'name' => 'checkout_layout',
                'operator' => '=',
                'value' => 'two-column',
              ),
            ),
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
      'id' => 'returning_customer_heading',
      'label' => 'Returning Customer',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'returning_customer_section_title',
          'type' => 'text',
          'label' => 'Section Title',
          'default' => 'Returning customer?',
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
          'key' => 'returning_customer_link_text',
          'type' => 'text',
          'label' => 'Link Text',
          'default' => 'Click here to login',
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
          'key' => 'returning_customer_title_alignment',
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
        3 =>
        array (
          'key' => 'login_button_title',
          'type' => 'heading',
          'label' => 'Login Button',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'login_button_alignment',
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
            'justify' =>
            array (
              'title' => 'Justify',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'login_button_alignment_note',
          'type' => 'raw_html',
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
    2 =>
    array (
      'id' => 'billing_details_section',
      'label' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
      ),
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'billing_details_section_title',
          'type' => 'text',
          'label' => 'Section Title',
          'default' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
          ),
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
        2 =>
        array (
          'key' => 'billing_details_form_fields',
          'type' => 'repeater',
          'label' => 'Form Items',
          'default' =>
          array (
            '__unresolved__' => '->get_billing_field_defaults()',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    3 =>
    array (
      'id' => 'shipping_details_section',
      'label' => 'Shipping Details',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'shipping_details_section_title',
          'type' => 'text',
          'label' => 'Section Title',
          'default' => 'Ship to a different address?',
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
          'key' => 'shipping_details_form_fields',
          'type' => 'repeater',
          'label' => 'Form Items',
          'default' =>
          array (
            '__unresolved__' => '->get_shipping_field_defaults()',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    4 =>
    array (
      'id' => 'additional_information_section',
      'label' => 'Additional Information',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'additional_information_active',
          'type' => 'switcher',
          'label' => 'Additional Information',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'additional_information_section_title',
          'type' => 'text',
          'label' => 'Section Title',
          'default' => 'Additional Information',
          'options' => NULL,
          'condition' =>
          array (
            'additional_information_active!' => '',
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
          'key' => 'additional_information_alignment',
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
            'additional_information_active!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'additional_information_form_fields',
          'type' => 'repeater',
          'label' => 'Items',
          'default' =>
          array (
            0 =>
            array (
              'field_key' => 'order_comments',
              'field_label' => 'Order Notes',
              'label' => 'Order Notes',
              'placeholder' => 'Notes about your order, e.g. special notes for delivery.',
            ),
          ),
          'options' => NULL,
          'condition' =>
          array (
            'additional_information_active!' => '',
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
    5 =>
    array (
      'id' => 'create_account_section',
      'label' => 'Create an Account',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'create_account_text',
          'type' => 'text',
          'label' => 'Section Title',
          'default' => 'Create an account?',
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
    6 =>
    array (
      'id' => 'order_summary_section',
      'label' => 'Your Order',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'order_summary_section_title',
          'type' => 'text',
          'label' => 'Section Title',
          'default' => 'Your Order',
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
    7 =>
    array (
      'id' => 'coupon_section',
      'label' => 'Coupon',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'coupon_section_display',
          'type' => 'switcher',
          'label' => 'Coupon',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'coupon_section_title_text',
          'type' => 'text',
          'label' => 'Section Title',
          'default' => 'Have a coupon?',
          'options' => NULL,
          'condition' =>
          array (
            'coupon_section_display' => 'yes',
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
          'key' => 'coupon_section_title_link_text',
          'type' => 'text',
          'label' => 'Link Text',
          'default' => 'Click here to enter your coupon code',
          'options' => NULL,
          'condition' =>
          array (
            'coupon_section_display' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'coupon_alignment',
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
            'coupon_section_display' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'coupon_button_title',
          'type' => 'heading',
          'label' => 'Apply Button',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'coupon_section_display' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'coupon_button_alignment',
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
            'justify' =>
            array (
              'title' => 'Justify',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' =>
          array (
            'coupon_section_display' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'coupon_button_alignment_note',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'coupon_section_display' => 'yes',
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
    8 =>
    array (
      'id' => 'payment_section',
      'label' => 'Payment',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'terms_conditions_heading',
          'type' => 'heading',
          'label' => 'Terms & Conditions',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'terms_conditions_message_text',
          'type' => 'text',
          'label' => 'Message',
          'default' => 'I have read and agree to the website',
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
          'key' => 'terms_conditions_link_text',
          'type' => 'text',
          'label' => 'Link Text',
          'default' => 'terms and conditions',
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
          'key' => 'purchase_buttom_heading',
          'type' => 'heading',
          'label' => 'Purchase Button',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'purchase_button_alignment',
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
            'justify' =>
            array (
              'title' => 'Justify',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    9 =>
    array (
      'id' => 'section_checkout_tabs_style',
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
          'key' => 'sections_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
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
          'name' => 'section_normal_box_shadow',
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => '->get_main_woocommerce_sections_selectors()',
          ),
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
      'id' => 'section_checkout_tabs_typography',
      'label' => 'Typography',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'sections_typography',
          'type' => 'heading',
          'label' => 'Titles',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'sections_title_color',
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
          'key' => 'sections_title_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'sections_secondary_typography',
          'type' => 'heading',
          'label' => 'Secondary Titles',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'sections_secondary_title_color',
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
          'key' => 'sections_secondary_title_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'sections_descriptions_title',
          'type' => 'heading',
          'label' => 'Descriptions',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'sections_descriptions_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'sections_descriptions_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'sections_messages_title',
          'type' => 'heading',
          'label' => 'Messages',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'sections_messages_color',
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
          'key' => 'sections_checkboxes_title',
          'type' => 'heading',
          'label' => 'Checkboxes',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'sections_checkboxes_color',
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
          'key' => 'sections_radio_buttons_title',
          'type' => 'heading',
          'label' => 'Radio Buttons',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'sections_radio_buttons_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'sections_links_title',
          'type' => 'heading',
          'label' => 'Links',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'links_normal_color',
          'type' => 'color',
          'label' => 'Link Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'links_hover_color',
          'type' => 'color',
          'label' => 'Link Color',
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
          'name' => 'sections_titles_typography',
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => '->get_main_woocommerce_sections_title_selectors()',
          ),
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'sections_titles_text_shadow',
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => '->get_main_woocommerce_sections_title_selectors()',
          ),
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'sections_secondary_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-checkout-secondary-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'sections_descriptions_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-description',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'sections_messages_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-checkout #payment .payment_box, {{WRAPPER}} .woocommerce-privacy-policy-text p, {{WRAPPER}} .e-checkout-message',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'typography',
          'name' => 'sections_checkboxes_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-form__label-for-checkbox span',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'typography',
          'name' => 'sections_radio_buttons_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .wc_payment_method label, {{WRAPPER}} #shipping_method li label',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    11 =>
    array (
      'id' => 'section_checkout_tabs_forms',
      'label' => 'Forms',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'forms_columns_gap',
          'type' => 'slider',
          'label' => 'Columns Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'forms_rows_gap',
          'type' => 'slider',
          'label' => 'Rows Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'forms_label_title',
          'type' => 'heading',
          'label' => 'Labels',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'forms_label_color',
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
          'key' => 'forms_label_spacing',
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
          'key' => 'forms_field_title',
          'type' => 'heading',
          'label' => 'Fields',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'forms_fields_normal_color',
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
          'key' => 'forms_fields_focus_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'forms_fields_focus_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'forms_fields_border_border!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'forms_fields_focus_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration (ms)',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'forms_fields_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'forms_fields_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'forms_button_title',
          'type' => 'heading',
          'label' => 'Button',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'forms_buttons_normal_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'forms_buttons_hover_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'forms_buttons_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'forms_buttons_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'forms_buttons_hover_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration (ms)',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'forms_buttons_hover_animation',
          'type' => 'hover_animation',
          'label' => 'Hover Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'forms_buttons_border_type',
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
        19 =>
        array (
          'key' => 'forms_buttons_border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'forms_buttons_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        20 =>
        array (
          'key' => 'forms_buttons_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'forms_buttons_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        21 =>
        array (
          'key' => 'forms_buttons_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        22 =>
        array (
          'key' => 'forms_buttons_padding',
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
          'name' => 'forms_label_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-billing-fields .form-row label, {{WRAPPER}} .woocommerce-shipping-fields .form-row label, {{WRAPPER}} .woocommerce-additional-fields .form-row label, {{WRAPPER}} .e-woocommerce-login-anchor .form-row label, {{WRAPPER}} .e-coupon-anchor-description',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'forms_field_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #customer_details .input-text, {{WRAPPER}} #customer_details .form-row textarea, {{WRAPPER}} #customer_details .form-row select, {{WRAPPER}} .e-woocommerce-login-anchor .input-text, {{WRAPPER}} #coupon_code, {{WRAPPER}} ::placeholder, {{WRAPPER}} .select2-container--default .select2-selection--single, .select2-results__option',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'background',
          'name' => 'forms_fields_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce #customer_details .form-row .input-text, {{WRAPPER}}  .woocommerce #customer_details .form-row textarea, {{WRAPPER}} .woocommerce form #customer_details select, {{WRAPPER}} .woocommerce .e-woocommerce-login-anchor .form-row .input-text, {{WRAPPER}} #coupon_code, {{WRAPPER}} .select2-container--default .select2-selection--single, {{WRAPPER}} .woocommerce-checkout #payment .payment_methods .payment_box',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_fields_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #customer_details .input-text, {{WRAPPER}}  #customer_details .form-row textarea, {{WRAPPER}} .woocommerce form #customer_details select, {{WRAPPER}} .e-woocommerce-login-anchor .input-text, {{WRAPPER}} #coupon_code, {{WRAPPER}} .select2-container--default .select2-selection--single',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'background',
          'name' => 'forms_fields_focus_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce #customer_details .form-row .input-text:focus, {{WRAPPER}}  .woocommerce #customer_details .form-row textarea:focus, {{WRAPPER}} #customer_details select:focus, {{WRAPPER}} .woocommerce .e-woocommerce-login-anchor .form-row .input-text:focus, {{WRAPPER}} #coupon_code:focus, {{WRAPPER}} .select2-container--default .select2-selection--single:focus',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_fields_focus_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #customer_details .input-text:focus, {{WRAPPER}} #customer_details textarea:focus, {{WRAPPER}} #customer_details select:focus, {{WRAPPER}} .e-woocommerce-login-anchor .input-text:focus, {{WRAPPER}} #coupon_code:focus, {{WRAPPER}} .select2-container--default .select2-selection--single:focus',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'border',
          'name' => 'forms_fields_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce #customer_details .form-row .input-text, {{WRAPPER}}  .woocommerce #customer_details .form-row textarea, {{WRAPPER}} .woocommerce form #customer_details select, {{WRAPPER}} .woocommerce .e-woocommerce-login-anchor .form-row .input-text, {{WRAPPER}} #coupon_code, {{WRAPPER}} .select2-container--default .select2-selection--single',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        7 =>
        array (
          'group' => 'typography',
          'name' => 'forms_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        8 =>
        array (
          'group' => 'text-shadow',
          'name' => 'forms_button_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        9 =>
        array (
          'group' => 'background',
          'name' => 'forms_buttons_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        10 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_buttons_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        11 =>
        array (
          'group' => 'background',
          'name' => 'forms_buttons_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-button:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        12 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_buttons_focus_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-button:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    12 =>
    array (
      'id' => 'section_checkout_tabs_order_summary',
      'label' => 'Order Summary',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'order_summary_rows_gap',
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
          'key' => 'order_summary_items_title',
          'type' => 'heading',
          'label' => 'Items',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'order_summary_items_color',
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
          'key' => 'order_summary_variations_title',
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
          'key' => 'order_summary_variations_color',
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
          'key' => 'order_summary_items_divider_title',
          'type' => 'heading',
          'label' => 'Dividers',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'order_summary_items_divider_color',
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
          'key' => 'order_summary_items_divider_weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'order_summary_totals_title',
          'type' => 'heading',
          'label' => 'Titles & Totals',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'order_summary_totals_color',
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
          'key' => 'order_summary_dividers_total_title',
          'type' => 'heading',
          'label' => 'Divider Total',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'order_summary_totals_divider_color',
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
          'key' => 'order_summary_totals_divider_weight',
          'type' => 'slider',
          'label' => 'Weight',
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
          'name' => 'order_summary_items_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-checkout-review-order-table .cart_item td',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'order_summary_variations_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .product-name .variation',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'order_summary_totals_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-checkout-review-order-table thead tr th, {{WRAPPER}} .woocommerce-checkout-review-order-table tfoot tr th, {{WRAPPER}} .woocommerce-checkout-review-order-table tfoot tr td',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    13 =>
    array (
      'id' => 'section_checkout_tabs_purchase_button',
      'label' => 'Purchase Button',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'purchase_button_normal_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'purchase_button_hover_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'purchase_button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'purchase_button_border_border!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'purchase_button_hover_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration (ms)',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'purchase_button_hover_animation',
          'type' => 'hover_animation',
          'label' => 'Hover Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'purchase_button_border_radius',
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
          'key' => 'purchase_button_padding',
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
          'name' => 'purchase_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce #payment #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'purchase_button_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce #payment #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'background',
          'name' => 'purchase_button_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #payment #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'purchase_button_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'background',
          'name' => 'purchase_button_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #payment #place_order:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'box-shadow',
          'name' => 'purchase_button_hover_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #place_order:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'border',
          'name' => 'purchase_button_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    14 =>
    array (
      'id' => 'section_checkout_tabs_customize',
      'label' => 'Customize',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'section_checkout_show_customize_elements',
          'type' => 'select2',
          'label' => 'Select sections of the checkout to customize:',
          'default' => NULL,
          'options' =>
          array (
            '__unresolved__' => '$customize_options',
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
    15 =>
    array (
      'id' => 'section_checkout_tabs_customize_returning_customer',
      'label' => 'Customize: Returning Customer',
      'tab' => 'style',
      'condition' =>
      array (
        'section_checkout_show_customize_elements' => 'customize_returning_customer',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'returning_customers_section_title',
          'type' => 'heading',
          'label' => 'Section',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'customize_returning_customer_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'returning_customers_border_type',
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
        3 =>
        array (
          'key' => 'returning_customers_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'returning_customers_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'returning_customers_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'returning_customers_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'returning_customers_border_radius',
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
          'key' => 'returning_customers_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'returning_customers_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'returning_customers_secondary_title',
          'type' => 'heading',
          'label' => 'Secondary Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'returning_customers_secondary_title_color',
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
          'key' => 'returning_customers_description_title',
          'type' => 'heading',
          'label' => 'Description',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'returning_customers_description_color',
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
          'key' => 'returning_customers_checkboxes_title',
          'type' => 'heading',
          'label' => 'Checkbox',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'returning_customers_checkboxes_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'returning_customers_link_title',
          'type' => 'heading',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'returning_customers_normal_links_color',
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
          'key' => 'returning_customers_hover_links_color',
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
          'name' => 'returning_customers_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-woocommerce-login-section',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'returning_customers_content_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-form-login-toggle',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'returning_customers_description_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-woocommerce-login-nudge.e-description',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'returning_customers_checkboxes_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-woocommerce-login-section .woocommerce-form__label-for-checkbox span',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    16 =>
    array (
      'id' => 'section_checkout_tabs_customize_billing_details',
      'label' => 'Customize: Billing Details',
      'tab' => 'style',
      'condition' =>
      array (
        'section_checkout_show_customize_elements' => 'customize_billing_details',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'customize_billing_details_section_title',
          'type' => 'heading',
          'label' => 'Section',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'customize_billing_details_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'billing_details_border_type',
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
        3 =>
        array (
          'key' => 'billing_details_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'billing_details_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'billing_details_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'billing_details_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'billing_details_border_radius',
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
          'key' => 'billing_details_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'billing_details_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'billing_details_titles_title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'billing_details_titles_color',
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
          'key' => 'billing_details_checkboxes_title',
          'type' => 'heading',
          'label' => 'Checkbox',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'billing_details_checkboxes_color',
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
          'name' => 'billing_details_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-checkout__column-start .col2-set .col-1',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'billing_details_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-billing-fields h3',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'billing_details_titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-billing-fields h3',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'billing_details_checkboxes_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .col2-set .col-1 .woocommerce-form__label-for-checkbox span',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    17 =>
    array (
      'id' => 'section_checkout_tabs_customize_additional_info',
      'label' => 'Customize: Additional Information',
      'tab' => 'style',
      'condition' =>
      array (
        'section_checkout_show_customize_elements' => 'customize_additional_info',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'customize_additional_information_section_title',
          'type' => 'heading',
          'label' => 'Section',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'customize_additional_information_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'additional_information_border_type',
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
        3 =>
        array (
          'key' => 'additional_information_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'additional_information_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'additional_information_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'additional_information_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'additional_information_border_radius',
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
          'key' => 'additional_information_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'additional_information_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'additional_information_titles_title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'additional_information_titles_color',
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
          'name' => 'additional_information_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-additional-fields',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'additional_information_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-additional-fields h3',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'additional_information_titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-additional-fields h3',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    18 =>
    array (
      'id' => 'section_checkout_tabs_customize_shipping_address',
      'label' => 'Customize: Shipping Address',
      'tab' => 'style',
      'condition' =>
      array (
        'section_checkout_show_customize_elements' => 'customize_shipping_address',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'shipping_address_section_title',
          'type' => 'heading',
          'label' => 'Section',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'customize_shipping_address_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'shipping_address_border_type',
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
        3 =>
        array (
          'key' => 'shipping_address_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'shipping_address_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'shipping_address_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'shipping_address_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'shipping_address_border_radius',
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
          'key' => 'shipping_address_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'shipping_address_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'shipping_address_checkboxes_title',
          'type' => 'heading',
          'label' => 'Checkboxes',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'shipping_address_checkboxes_color',
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
          'name' => 'shipping_address_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-shipping-fields .shipping_address',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'shipping_address_checkboxes_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-shipping-fields .woocommerce-form__label-for-checkbox span',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    19 =>
    array (
      'id' => 'section_checkout_tabs_customize_coupon',
      'label' => 'Customize: Coupon',
      'tab' => 'style',
      'condition' =>
      array (
        'section_checkout_show_customize_elements' => 'customize_coupon',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'customize_coupon_section_title',
          'type' => 'heading',
          'label' => 'Section',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'customize_coupon_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'coupon_border_type',
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
        3 =>
        array (
          'key' => 'coupon_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'coupon_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'coupon_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'coupon_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'coupon_border_radius',
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
          'key' => 'coupon_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'coupon_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'coupon_secondary_title',
          'type' => 'heading',
          'label' => 'Secondary Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'coupon_secondary_title_color',
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
          'key' => 'coupon_link_title',
          'type' => 'heading',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'coupon_normal_links_color',
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
          'key' => 'coupon_hover_links_color',
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
          'name' => 'coupon_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-coupon-box',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'coupon_content_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-woocommerce-coupon-nudge.e-checkout-secondary-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    20 =>
    array (
      'id' => 'section_checkout_tabs_customize_order_summary',
      'label' => 'Customize: Order Summary',
      'tab' => 'style',
      'condition' =>
      array (
        'section_checkout_show_customize_elements' => 'customize_order_summary',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'customize_order_summary_section_title',
          'type' => 'heading',
          'label' => 'Section',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'customize_order_summary_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'order_summary_border_type',
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
        3 =>
        array (
          'key' => 'order_summary_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_summary_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'order_summary_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_summary_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'order_summary_border_radius',
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
          'key' => 'order_summary_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'order_summary_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'order_summary_titles_title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'order_summary_titles_color',
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
          'key' => 'order_summary_descriptions_title',
          'type' => 'heading',
          'label' => 'Message',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'order_summary_descriptions_color',
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
          'key' => 'order_summary_radios_title',
          'type' => 'heading',
          'label' => 'Radio Button',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'order_summary_radios_color',
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
          'name' => 'order_summary_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-checkout__order_review',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'order_summary_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} h3#order_review_heading',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'order_summary_titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} h3#order_review_heading',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'order_summary_descriptions_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-no-shipping-available-html.e-description, {{WRAPPER}} .woocommerce-no-shipping-available-html.e-checkout-message',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'order_summary_radio_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce .e-checkout__order_review ul#shipping_method li label',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    21 =>
    array (
      'id' => 'section_checkout_tabs_customize_payment',
      'label' => 'Customize: Payment',
      'tab' => 'style',
      'condition' =>
      array (
        'section_checkout_show_customize_elements' => 'customize_payment',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'customize_payment_section_title',
          'type' => 'heading',
          'label' => 'Section',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'customize_payment_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'payment_border_type',
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
        3 =>
        array (
          'key' => 'payment_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'payment_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'payment_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'payment_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'payment_border_radius',
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
          'key' => 'payment_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'payment_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'payment_info_box_title',
          'type' => 'heading',
          'label' => 'Info Box',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'payment_description_title',
          'type' => 'heading',
          'label' => 'Description',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'payment_description_color',
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
          'key' => 'payment_messages_title',
          'type' => 'heading',
          'label' => 'Message',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'payment_messages_color',
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
          'key' => 'payment_checkboxes_title',
          'type' => 'heading',
          'label' => 'Checkbox',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'payment_checkboxes_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'payment_radio_title',
          'type' => 'heading',
          'label' => 'Radio Button',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'payment_radio_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'payment_links_title',
          'type' => 'heading',
          'label' => 'Links',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'payment_normal_color',
          'type' => 'color',
          'label' => 'Link Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'payment_hover_color',
          'type' => 'color',
          'label' => 'Link Color',
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
          'name' => 'payment_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-checkout #payment',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' => 'payment_info_box_title_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-checkout #payment .payment_methods .payment_box',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'payment_description_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-checkout-payment .e-description',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'payment_messages_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-checkout #payment .payment_box, {{WRAPPER}} .woocommerce-privacy-policy-text p',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'payment_checkboxes_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-terms-and-conditions-wrapper .woocommerce-form__label-for-checkbox span',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'typography',
          'name' => 'payment_radio_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-checkout-payment .wc_payment_method label',
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
      'name' => 'section_normal_box_shadow',
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => '->get_main_woocommerce_sections_selectors()',
      ),
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'sections_titles_typography',
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => '->get_main_woocommerce_sections_title_selectors()',
      ),
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'sections_titles_text_shadow',
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => '->get_main_woocommerce_sections_title_selectors()',
      ),
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'sections_secondary_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-checkout-secondary-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'typography',
      'name' => 'sections_descriptions_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-description',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'sections_messages_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-checkout #payment .payment_box, {{WRAPPER}} .woocommerce-privacy-policy-text p, {{WRAPPER}} .e-checkout-message',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'sections_checkboxes_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-form__label-for-checkbox span',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'typography',
      'name' => 'sections_radio_buttons_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .wc_payment_method label, {{WRAPPER}} #shipping_method li label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'typography',
      'name' => 'forms_label_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-billing-fields .form-row label, {{WRAPPER}} .woocommerce-shipping-fields .form-row label, {{WRAPPER}} .woocommerce-additional-fields .form-row label, {{WRAPPER}} .e-woocommerce-login-anchor .form-row label, {{WRAPPER}} .e-coupon-anchor-description',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'typography',
      'name' => 'forms_field_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #customer_details .input-text, {{WRAPPER}} #customer_details .form-row textarea, {{WRAPPER}} #customer_details .form-row select, {{WRAPPER}} .e-woocommerce-login-anchor .input-text, {{WRAPPER}} #coupon_code, {{WRAPPER}} ::placeholder, {{WRAPPER}} .select2-container--default .select2-selection--single, .select2-results__option',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'background',
      'name' => 'forms_fields_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce #customer_details .form-row .input-text, {{WRAPPER}}  .woocommerce #customer_details .form-row textarea, {{WRAPPER}} .woocommerce form #customer_details select, {{WRAPPER}} .woocommerce .e-woocommerce-login-anchor .form-row .input-text, {{WRAPPER}} #coupon_code, {{WRAPPER}} .select2-container--default .select2-selection--single, {{WRAPPER}} .woocommerce-checkout #payment .payment_methods .payment_box',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_fields_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #customer_details .input-text, {{WRAPPER}}  #customer_details .form-row textarea, {{WRAPPER}} .woocommerce form #customer_details select, {{WRAPPER}} .e-woocommerce-login-anchor .input-text, {{WRAPPER}} #coupon_code, {{WRAPPER}} .select2-container--default .select2-selection--single',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'background',
      'name' => 'forms_fields_focus_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce #customer_details .form-row .input-text:focus, {{WRAPPER}}  .woocommerce #customer_details .form-row textarea:focus, {{WRAPPER}} #customer_details select:focus, {{WRAPPER}} .woocommerce .e-woocommerce-login-anchor .form-row .input-text:focus, {{WRAPPER}} #coupon_code:focus, {{WRAPPER}} .select2-container--default .select2-selection--single:focus',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    13 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_fields_focus_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #customer_details .input-text:focus, {{WRAPPER}} #customer_details textarea:focus, {{WRAPPER}} #customer_details select:focus, {{WRAPPER}} .e-woocommerce-login-anchor .input-text:focus, {{WRAPPER}} #coupon_code:focus, {{WRAPPER}} .select2-container--default .select2-selection--single:focus',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    14 =>
    array (
      'group' => 'border',
      'name' => 'forms_fields_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce #customer_details .form-row .input-text, {{WRAPPER}}  .woocommerce #customer_details .form-row textarea, {{WRAPPER}} .woocommerce form #customer_details select, {{WRAPPER}} .woocommerce .e-woocommerce-login-anchor .form-row .input-text, {{WRAPPER}} #coupon_code, {{WRAPPER}} .select2-container--default .select2-selection--single',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    15 =>
    array (
      'group' => 'typography',
      'name' => 'forms_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    16 =>
    array (
      'group' => 'text-shadow',
      'name' => 'forms_button_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    17 =>
    array (
      'group' => 'background',
      'name' => 'forms_buttons_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    18 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_buttons_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    19 =>
    array (
      'group' => 'background',
      'name' => 'forms_buttons_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-button:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    20 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_buttons_focus_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-button:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    21 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_items_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-checkout-review-order-table .cart_item td',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    22 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_variations_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .product-name .variation',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    23 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_totals_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-checkout-review-order-table thead tr th, {{WRAPPER}} .woocommerce-checkout-review-order-table tfoot tr th, {{WRAPPER}} .woocommerce-checkout-review-order-table tfoot tr td',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    24 =>
    array (
      'group' => 'typography',
      'name' => 'purchase_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce #payment #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    25 =>
    array (
      'group' => 'text-shadow',
      'name' => 'purchase_button_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce #payment #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    26 =>
    array (
      'group' => 'background',
      'name' => 'purchase_button_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #payment #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    27 =>
    array (
      'group' => 'box-shadow',
      'name' => 'purchase_button_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    28 =>
    array (
      'group' => 'background',
      'name' => 'purchase_button_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #payment #place_order:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    29 =>
    array (
      'group' => 'box-shadow',
      'name' => 'purchase_button_hover_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #place_order:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    30 =>
    array (
      'group' => 'border',
      'name' => 'purchase_button_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    31 =>
    array (
      'group' => 'box-shadow',
      'name' => 'returning_customers_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-woocommerce-login-section',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    32 =>
    array (
      'group' => 'typography',
      'name' => 'returning_customers_content_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-form-login-toggle',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    33 =>
    array (
      'group' => 'typography',
      'name' => 'returning_customers_description_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-woocommerce-login-nudge.e-description',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    34 =>
    array (
      'group' => 'typography',
      'name' => 'returning_customers_checkboxes_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-woocommerce-login-section .woocommerce-form__label-for-checkbox span',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    35 =>
    array (
      'group' => 'box-shadow',
      'name' => 'billing_details_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-checkout__column-start .col2-set .col-1',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    36 =>
    array (
      'group' => 'typography',
      'name' => 'billing_details_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-billing-fields h3',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    37 =>
    array (
      'group' => 'text-shadow',
      'name' => 'billing_details_titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-billing-fields h3',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    38 =>
    array (
      'group' => 'typography',
      'name' => 'billing_details_checkboxes_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .col2-set .col-1 .woocommerce-form__label-for-checkbox span',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    39 =>
    array (
      'group' => 'box-shadow',
      'name' => 'additional_information_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-additional-fields',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    40 =>
    array (
      'group' => 'typography',
      'name' => 'additional_information_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-additional-fields h3',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    41 =>
    array (
      'group' => 'text-shadow',
      'name' => 'additional_information_titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-additional-fields h3',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    42 =>
    array (
      'group' => 'box-shadow',
      'name' => 'shipping_address_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-shipping-fields .shipping_address',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    43 =>
    array (
      'group' => 'typography',
      'name' => 'shipping_address_checkboxes_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-shipping-fields .woocommerce-form__label-for-checkbox span',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    44 =>
    array (
      'group' => 'box-shadow',
      'name' => 'coupon_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-coupon-box',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    45 =>
    array (
      'group' => 'typography',
      'name' => 'coupon_content_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-woocommerce-coupon-nudge.e-checkout-secondary-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    46 =>
    array (
      'group' => 'box-shadow',
      'name' => 'order_summary_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-checkout__order_review',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    47 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} h3#order_review_heading',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    48 =>
    array (
      'group' => 'text-shadow',
      'name' => 'order_summary_titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} h3#order_review_heading',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    49 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_descriptions_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-no-shipping-available-html.e-description, {{WRAPPER}} .woocommerce-no-shipping-available-html.e-checkout-message',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    50 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_radio_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce .e-checkout__order_review ul#shipping_method li label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    51 =>
    array (
      'group' => 'box-shadow',
      'name' => 'payment_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-checkout #payment',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    52 =>
    array (
      'group' => 'background',
      'name' => 'payment_info_box_title_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-checkout #payment .payment_methods .payment_box',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    53 =>
    array (
      'group' => 'typography',
      'name' => 'payment_description_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-checkout-payment .e-description',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    54 =>
    array (
      'group' => 'typography',
      'name' => 'payment_messages_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-checkout #payment .payment_box, {{WRAPPER}} .woocommerce-privacy-policy-text p',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    55 =>
    array (
      'group' => 'typography',
      'name' => 'payment_checkboxes_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-terms-and-conditions-wrapper .woocommerce-form__label-for-checkbox span',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    56 =>
    array (
      'group' => 'typography',
      'name' => 'payment_radio_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-checkout-payment .wc_payment_method label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
    0 =>
    array (
      'var' => 'repeater',
      'fields' =>
      array (
        0 =>
        array (
          'key' => 'label',
          'type' => 'text',
          'label' => 'Label',
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
        1 =>
        array (
          'key' => 'placeholder',
          'type' => 'text',
          'label' => 'Placeholder',
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
          'key' => 'default',
          'type' => 'text',
          'label' => 'Default Value',
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
    ),
  ),
  'settings_index' =>
  array (
    'checkout_layout' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'two-column',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sticky_right_column' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_layout' => 'two-column',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sticky_right_column_offset' =>
    array (
      'section' => 'section_content',
      'type' => 'number',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'sticky_right_column',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'checkout_layout',
            'operator' => '=',
            'value' => 'two-column',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customer_section_title' =>
    array (
      'section' => 'returning_customer_heading',
      'type' => 'text',
      'default' => 'Returning customer?',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customer_link_text' =>
    array (
      'section' => 'returning_customer_heading',
      'type' => 'text',
      'default' => 'Click here to login',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customer_title_alignment' =>
    array (
      'section' => 'returning_customer_heading',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'login_button_title' =>
    array (
      'section' => 'returning_customer_heading',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'login_button_alignment' =>
    array (
      'section' => 'returning_customer_heading',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'login_button_alignment_note' =>
    array (
      'section' => 'returning_customer_heading',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_section_title' =>
    array (
      'section' => 'billing_details_section',
      'type' => 'text',
      'default' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_alignment' =>
    array (
      'section' => 'billing_details_section',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_form_fields' =>
    array (
      'section' => 'billing_details_section',
      'type' => 'repeater',
      'default' =>
      array (
        '__unresolved__' => '->get_billing_field_defaults()',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_details_section_title' =>
    array (
      'section' => 'shipping_details_section',
      'type' => 'text',
      'default' => 'Ship to a different address?',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_details_form_fields' =>
    array (
      'section' => 'shipping_details_section',
      'type' => 'repeater',
      'default' =>
      array (
        '__unresolved__' => '->get_shipping_field_defaults()',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_active' =>
    array (
      'section' => 'additional_information_section',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_section_title' =>
    array (
      'section' => 'additional_information_section',
      'type' => 'text',
      'default' => 'Additional Information',
      'responsive' => false,
      'condition' =>
      array (
        'additional_information_active!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_alignment' =>
    array (
      'section' => 'additional_information_section',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'additional_information_active!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_form_fields' =>
    array (
      'section' => 'additional_information_section',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'field_key' => 'order_comments',
          'field_label' => 'Order Notes',
          'label' => 'Order Notes',
          'placeholder' => 'Notes about your order, e.g. special notes for delivery.',
        ),
      ),
      'responsive' => false,
      'condition' =>
      array (
        'additional_information_active!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'create_account_text' =>
    array (
      'section' => 'create_account_section',
      'type' => 'text',
      'default' => 'Create an account?',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_title' =>
    array (
      'section' => 'order_summary_section',
      'type' => 'text',
      'default' => 'Your Order',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_alignment' =>
    array (
      'section' => 'order_summary_section',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_section_display' =>
    array (
      'section' => 'coupon_section',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_section_title_text' =>
    array (
      'section' => 'coupon_section',
      'type' => 'text',
      'default' => 'Have a coupon?',
      'responsive' => false,
      'condition' =>
      array (
        'coupon_section_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_section_title_link_text' =>
    array (
      'section' => 'coupon_section',
      'type' => 'text',
      'default' => 'Click here to enter your coupon code',
      'responsive' => false,
      'condition' =>
      array (
        'coupon_section_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_alignment' =>
    array (
      'section' => 'coupon_section',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'coupon_section_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_button_title' =>
    array (
      'section' => 'coupon_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'coupon_section_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_button_alignment' =>
    array (
      'section' => 'coupon_section',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'coupon_section_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_button_alignment_note' =>
    array (
      'section' => 'coupon_section',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'coupon_section_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'terms_conditions_heading' =>
    array (
      'section' => 'payment_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'terms_conditions_message_text' =>
    array (
      'section' => 'payment_section',
      'type' => 'text',
      'default' => 'I have read and agree to the website',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'terms_conditions_link_text' =>
    array (
      'section' => 'payment_section',
      'type' => 'text',
      'default' => 'terms and conditions',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_buttom_heading' =>
    array (
      'section' => 'payment_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_button_alignment' =>
    array (
      'section' => 'payment_section',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_background_color' =>
    array (
      'section' => 'section_checkout_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_type' =>
    array (
      'section' => 'section_checkout_tabs_style',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_width' =>
    array (
      'section' => 'section_checkout_tabs_style',
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
      'section' => 'section_checkout_tabs_style',
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
      'section' => 'section_checkout_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_padding' =>
    array (
      'section' => 'section_checkout_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_margin' =>
    array (
      'section' => 'section_checkout_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'section_normal_box_shadow',
    ),
    'sections_typography' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_title_color' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_title_spacing' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_secondary_typography' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_secondary_title_color' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_secondary_title_spacing' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_descriptions_title' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_descriptions_color' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_descriptions_spacing' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_messages_title' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_messages_color' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_checkboxes_title' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_checkboxes_color' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_radio_buttons_title' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_radio_buttons_color' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_links_title' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'links_normal_color' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'links_hover_color' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_titles_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_titles_typography',
    ),
    'sections_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'sections_titles_text_shadow',
    ),
    'sections_secondary_titles_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_secondary_titles_typography',
    ),
    'sections_descriptions_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_descriptions_typography',
    ),
    'sections_messages_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_messages_typography',
    ),
    'sections_checkboxes_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_checkboxes_typography',
    ),
    'sections_radio_buttons_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_radio_buttons_typography',
    ),
    'forms_columns_gap' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_rows_gap' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_label_title' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_label_color' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_label_spacing' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_field_title' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_normal_color' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_focus_color' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_focus_border_color' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'forms_fields_border_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_focus_transition_duration' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_padding' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_button_title' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_normal_text_color' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_text_color' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_border_color' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'forms_buttons_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_transition_duration' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_animation' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_border_type' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_border_width' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'forms_buttons_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_border_color' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'forms_buttons_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_padding' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_label_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'forms_label_typography',
    ),
    'forms_field_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'forms_field_typography',
    ),
    'forms_fields_normal_background_background' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_fields_normal_background',
    ),
    'forms_fields_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_fields_normal_box_shadow',
    ),
    'forms_fields_focus_background_background' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_fields_focus_background',
    ),
    'forms_fields_focus_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_fields_focus_box_shadow',
    ),
    'forms_fields_border_border' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'forms_fields_border',
    ),
    'forms_button_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'forms_button_typography',
    ),
    'forms_button_text_shadow_text_shadow' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'forms_button_text_shadow',
    ),
    'forms_buttons_normal_background_background' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_buttons_normal_background',
    ),
    'forms_buttons_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_buttons_normal_box_shadow',
    ),
    'forms_buttons_hover_background_background' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_buttons_hover_background',
    ),
    'forms_buttons_focus_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_buttons_focus_box_shadow',
    ),
    'order_summary_rows_gap' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_title' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_color' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_variations_title' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_variations_color' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_divider_title' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_divider_color' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_divider_weight' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_totals_title' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_totals_color' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_dividers_total_title' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_totals_divider_color' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_totals_divider_weight' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_items_typography',
    ),
    'order_summary_variations_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_variations_typography',
    ),
    'order_summary_totals_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_totals_typography',
    ),
    'purchase_button_normal_text_color' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_button_hover_text_color' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_button_hover_border_color' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'purchase_button_border_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_button_hover_transition_duration' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_button_hover_animation' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_button_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_button_padding' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'purchase_button_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'purchase_button_typography',
    ),
    'purchase_button_text_shadow_text_shadow' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'purchase_button_text_shadow',
    ),
    'purchase_button_normal_background_background' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'purchase_button_normal_background',
    ),
    'purchase_button_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'purchase_button_normal_box_shadow',
    ),
    'purchase_button_hover_background_background' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'purchase_button_hover_background',
    ),
    'purchase_button_hover_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'purchase_button_hover_box_shadow',
    ),
    'purchase_button_border_border' =>
    array (
      'section' => 'section_checkout_tabs_purchase_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'purchase_button_border',
    ),
    'section_checkout_show_customize_elements' =>
    array (
      'section' => 'section_checkout_tabs_customize',
      'type' => 'select2',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_section_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_returning_customer_background_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_border_type' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_border_width' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'returning_customers_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_border_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'returning_customers_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_padding' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_margin' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_secondary_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_secondary_title_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_description_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_description_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_checkboxes_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_checkboxes_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_link_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_normal_links_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_hover_links_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'returning_customers_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'returning_customers_section_normal_box_shadow',
    ),
    'returning_customers_content_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'returning_customers_content_typography',
    ),
    'returning_customers_description_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'returning_customers_description_typography',
    ),
    'returning_customers_checkboxes_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_returning_customer',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'returning_customers_checkboxes_typography',
    ),
    'customize_billing_details_section_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_billing_details_background_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_border_type' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_border_width' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'billing_details_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_border_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'billing_details_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_padding' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_margin' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_titles_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_titles_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_checkboxes_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_checkboxes_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'billing_details_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'billing_details_section_normal_box_shadow',
    ),
    'billing_details_titles_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'billing_details_titles_typography',
    ),
    'billing_details_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'billing_details_titles_text_shadow',
    ),
    'billing_details_checkboxes_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_billing_details',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'billing_details_checkboxes_typography',
    ),
    'customize_additional_information_section_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_additional_information_background_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_border_type' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_border_width' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'additional_information_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_border_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'additional_information_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_padding' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_margin' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_titles_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_titles_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_information_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'additional_information_section_normal_box_shadow',
    ),
    'additional_information_titles_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'additional_information_titles_typography',
    ),
    'additional_information_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_additional_info',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'additional_information_titles_text_shadow',
    ),
    'shipping_address_section_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_shipping_address_background_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_border_type' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_border_width' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'shipping_address_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_border_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'shipping_address_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_padding' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_margin' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_checkboxes_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_checkboxes_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'shipping_address_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'shipping_address_section_normal_box_shadow',
    ),
    'shipping_address_checkboxes_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_shipping_address',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'shipping_address_checkboxes_typography',
    ),
    'customize_coupon_section_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_background_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_border_type' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_border_width' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'coupon_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_border_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'coupon_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_padding' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_margin' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_secondary_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_secondary_title_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_link_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_normal_links_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_hover_links_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'coupon_section_normal_box_shadow',
    ),
    'coupon_content_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_coupon',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'coupon_content_typography',
    ),
    'customize_order_summary_section_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_order_summary_background_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_border_type' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_border_width' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'order_summary_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_border_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'order_summary_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_padding' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_margin' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_titles_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_titles_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_descriptions_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_descriptions_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_radios_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_radios_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'order_summary_section_normal_box_shadow',
    ),
    'order_summary_titles_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_titles_typography',
    ),
    'order_summary_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'order_summary_titles_text_shadow',
    ),
    'order_summary_descriptions_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_descriptions_typography',
    ),
    'order_summary_radio_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_radio_typography',
    ),
    'customize_payment_section_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_payment_background_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_border_type' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_border_width' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'payment_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_border_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'payment_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_border_radius' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_padding' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_margin' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_info_box_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_description_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_description_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_messages_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_messages_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_checkboxes_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_checkboxes_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_radio_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_radio_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_links_title' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_normal_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_hover_color' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'payment_section_normal_box_shadow',
    ),
    'payment_info_box_title_background_background' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'payment_info_box_title_background',
    ),
    'payment_description_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'payment_description_typography',
    ),
    'payment_messages_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'payment_messages_typography',
    ),
    'payment_checkboxes_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'payment_checkboxes_typography',
    ),
    'payment_radio_typography_typography' =>
    array (
      'section' => 'section_checkout_tabs_customize_payment',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'payment_radio_typography',
    ),
  ),
  'group_activators' =>
  array (
    'section_normal_box_shadow_box_shadow' => 'yes',
    'sections_titles_typography_typography' => 'custom',
    'sections_titles_text_shadow_text_shadow' => 'yes',
    'sections_secondary_titles_typography_typography' => 'custom',
    'sections_descriptions_typography_typography' => 'custom',
    'sections_messages_typography_typography' => 'custom',
    'sections_checkboxes_typography_typography' => 'custom',
    'sections_radio_buttons_typography_typography' => 'custom',
    'forms_label_typography_typography' => 'custom',
    'forms_field_typography_typography' => 'custom',
    'forms_fields_normal_background_background' => 'classic',
    'forms_fields_normal_box_shadow_box_shadow' => 'yes',
    'forms_fields_focus_background_background' => 'classic',
    'forms_fields_focus_box_shadow_box_shadow' => 'yes',
    'forms_fields_border_border' => 'solid',
    'forms_button_typography_typography' => 'custom',
    'forms_button_text_shadow_text_shadow' => 'yes',
    'forms_buttons_normal_background_background' => 'classic',
    'forms_buttons_normal_box_shadow_box_shadow' => 'yes',
    'forms_buttons_hover_background_background' => 'classic',
    'forms_buttons_focus_box_shadow_box_shadow' => 'yes',
    'order_summary_items_typography_typography' => 'custom',
    'order_summary_variations_typography_typography' => 'custom',
    'order_summary_totals_typography_typography' => 'custom',
    'purchase_button_typography_typography' => 'custom',
    'purchase_button_text_shadow_text_shadow' => 'yes',
    'purchase_button_normal_background_background' => 'classic',
    'purchase_button_normal_box_shadow_box_shadow' => 'yes',
    'purchase_button_hover_background_background' => 'classic',
    'purchase_button_hover_box_shadow_box_shadow' => 'yes',
    'purchase_button_border_border' => 'solid',
    'returning_customers_section_normal_box_shadow_box_shadow' => 'yes',
    'returning_customers_content_typography_typography' => 'custom',
    'returning_customers_description_typography_typography' => 'custom',
    'returning_customers_checkboxes_typography_typography' => 'custom',
    'billing_details_section_normal_box_shadow_box_shadow' => 'yes',
    'billing_details_titles_typography_typography' => 'custom',
    'billing_details_titles_text_shadow_text_shadow' => 'yes',
    'billing_details_checkboxes_typography_typography' => 'custom',
    'additional_information_section_normal_box_shadow_box_shadow' => 'yes',
    'additional_information_titles_typography_typography' => 'custom',
    'additional_information_titles_text_shadow_text_shadow' => 'yes',
    'shipping_address_section_normal_box_shadow_box_shadow' => 'yes',
    'shipping_address_checkboxes_typography_typography' => 'custom',
    'coupon_section_normal_box_shadow_box_shadow' => 'yes',
    'coupon_content_typography_typography' => 'custom',
    'order_summary_section_normal_box_shadow_box_shadow' => 'yes',
    'order_summary_titles_typography_typography' => 'custom',
    'order_summary_titles_text_shadow_text_shadow' => 'yes',
    'order_summary_descriptions_typography_typography' => 'custom',
    'order_summary_radio_typography_typography' => 'custom',
    'payment_section_normal_box_shadow_box_shadow' => 'yes',
    'payment_info_box_title_background_background' => 'classic',
    'payment_description_typography_typography' => 'custom',
    'payment_messages_typography_typography' => 'custom',
    'payment_checkboxes_typography_typography' => 'custom',
    'payment_radio_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 255,
);
