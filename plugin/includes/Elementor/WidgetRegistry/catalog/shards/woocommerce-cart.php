<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-cart',
  'source' => 'wc',
  'widget_type' => 'woocommerce-cart',
  'title' => 'Cart',
  'icon' => 'eicon-woo-cart',
  'categories' =>
  array (
    0 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
    0 => 'woocommerce',
    1 => 'cart',
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/cart.php',
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
          'key' => 'cart_layout',
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
            'cart_layout!' => 'one-column',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'This option will allow the right column (e.g, Cart Totals) to be sticky while scrolling.',
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
                'name' => 'cart_layout',
                'operator' => '!==',
                'value' => 'one-column',
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
      'id' => 'section_order_summary',
      'label' => 'Order Summary',
      'tab' => NULL,
      'condition' =>
      array (
        'update_cart_automatically' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'update_cart_button_heading',
          'type' => 'heading',
          'label' => 'Update Cart Button',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'update_cart_button_text',
          'type' => 'text',
          'label' => 'Text',
          'default' => 'Update Cart',
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
          'key' => 'update_cart_button_alignment',
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
    2 =>
    array (
      'id' => 'section_coupon',
      'label' => 'Coupon',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'section_coupon_display',
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
          'key' => 'apply_coupon_heading',
          'type' => 'heading',
          'label' => 'Apply Coupon Button',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'section_coupon_display' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'apply_coupon_button_text',
          'type' => 'text',
          'label' => 'Text',
          'default' => 'Apply coupon',
          'options' => NULL,
          'condition' =>
          array (
            'section_coupon_display' => 'yes',
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
          'key' => 'apply_coupon_button_alignment',
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
            'section_coupon_display' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'coupon_button_alignment_note',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'section_coupon_display' => 'yes',
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
    3 =>
    array (
      'id' => 'section_totals',
      'label' => 'Totals',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'totals_section_title',
          'type' => 'text',
          'label' => 'Section Title',
          'default' => 'Cart Totals',
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
          'key' => 'totals_section_title_alignment',
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
          'key' => 'update_shipping_button_heading',
          'type' => 'heading',
          'label' => 'Update Shipping Button',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'update_shipping_button_text',
          'type' => 'text',
          'label' => 'Text',
          'default' => 'Update',
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
          'key' => 'update_shipping_button_alignment',
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
          'key' => 'checkout_button_heading',
          'type' => 'heading',
          'label' => 'Checkout Button',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'checkout_button_text',
          'type' => 'text',
          'label' => 'Text',
          'default' => 'Proceed to Checkout',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'checkout_button_alignment',
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
    4 =>
    array (
      'id' => 'section_additional_options',
      'label' => 'Additional Options',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'update_cart_automatically',
          'type' => 'switcher',
          'label' => 'Update Cart Automatically',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'update_cart_automatically_description',
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
          'key' => 'additional_template_switch',
          'type' => 'switcher',
          'label' => 'Customize empty cart',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'additional_template_description',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'additional_template_switch' => 'active',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'additional_template_select_heading',
          'type' => 'heading',
          'label' => 'Choose template',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'additional_template_switch' => 'active',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'additional_template_select',
          'type' =>
          array (
            '__unresolved__' => 'QueryControlModule::QUERY_CONTROL_ID',
          ),
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'additional_template_switch' => 'active',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'edit_button',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'additional_template_switch' => 'active',
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
      'id' => 'section_cart_tabs_style',
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
          'selector' => '{{WRAPPER}} .e-cart-section',
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
      'id' => 'section_cart_tabs_typography',
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
        4 =>
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
        5 =>
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
        6 =>
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
        7 =>
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
        8 =>
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
        9 =>
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
        10 =>
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
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'sections_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .cart_totals h2',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'sections_titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .cart_totals h2',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'sections_descriptions_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-cart-content, {{WRAPPER}} .woocommerce-shipping-destination, {{WRAPPER}} .shipping-calculator-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'sections_radio_buttons_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} #shipping_method li label',
          'condition' => NULL,
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
      'id' => 'section_cart_tabs_forms',
      'label' => 'Forms',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
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
        1 =>
        array (
          'key' => 'forms_field_title',
          'type' => 'heading',
          'label' => 'Field',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
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
        3 =>
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
        4 =>
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
        5 =>
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
        6 =>
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
        7 =>
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
        8 =>
        array (
          'key' => 'forms_button_title',
          'type' => 'heading',
          'label' => 'Buttons',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
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
        10 =>
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
        11 =>
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
        12 =>
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
        13 =>
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
        14 =>
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
        15 =>
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
        16 =>
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
        17 =>
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
        18 =>
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
          'name' => 'forms_field_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .coupon .input-text, {{WRAPPER}} .cart-collaterals .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-selection--single',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' => 'forms_fields_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .coupon .input-text, {{WRAPPER}} .e-cart-totals .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-selection--single',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_fields_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .coupon .input-text, {{WRAPPER}} .e-cart-totals .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-selection--single',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'background',
          'name' => 'forms_fields_focus_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .coupon .input-text:focus, {{WRAPPER}} .e-cart-totals .input-text:focus, {{WRAPPER}} select:focus, {{WRAPPER}} .select2-selection--single:focus',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_fields_focus_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .coupon .input-text:focus, {{WRAPPER}} .e-cart-totals .input-text:focus, {{WRAPPER}} select:focus, {{WRAPPER}} .select2-selection--single:focus',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'border',
          'name' => 'forms_fields_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .coupon .input-text, {{WRAPPER}} .cart-collaterals .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-selection--single',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'typography',
          'name' => 'forms_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        7 =>
        array (
          'group' => 'text-shadow',
          'name' => 'forms_button_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        8 =>
        array (
          'group' => 'background',
          'name' => 'forms_buttons_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        9 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_buttons_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        10 =>
        array (
          'group' => 'background',
          'name' => 'forms_buttons_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button:hover, {{WRAPPER}} .shop_table .button:disabled[disabled]:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        11 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_buttons_focus_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    8 =>
    array (
      'id' => 'tabs_order_summary',
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
          'key' => 'order_summary_titles_title',
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
          'key' => 'order_summary_title_color',
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
          'key' => 'order_summary_title_spacing',
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
        5 =>
        array (
          'key' => 'order_summary_color',
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
        7 =>
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
        8 =>
        array (
          'key' => 'order_summary_product_link_title',
          'type' => 'heading',
          'label' => 'Product Link',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'product_link_normal_color',
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
          'key' => 'product_link_hover_color',
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
          'key' => 'order_summary_divider_title',
          'type' => 'heading',
          'label' => 'Dividers',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
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
        13 =>
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
        14 =>
        array (
          'key' => 'order_summary_quantity_border_title',
          'type' => 'heading',
          'label' => 'Quantity Borders',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'order_summary_quantity_border_color',
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
          'key' => 'order_summary_quantity_border_weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'order_summary_remove_icon_title',
          'type' => 'heading',
          'label' => 'Remove icon',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'order_summary_remove_icon_normal_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'order_summary_remove_icon_hover_color',
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
          'name' => 'order_summary_title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-shop-table .cart th, {{WRAPPER}} .e-shop-table .cart td:before',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'order_summary_title_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-shop-table .cart th, {{WRAPPER}} .e-shop-table .cart td:before',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'order_summary_items_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .cart td span, {{WRAPPER}} .cart td, {{WRAPPER}} .input-text.qty',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'order_summary_variations_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .product-name .variation',
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
      'id' => 'section_cart_totals',
      'label' => 'Totals',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'totals_rows_gap',
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
          'key' => 'totals_title',
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
          'key' => 'totals_color',
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
          'key' => 'totals_divider_title',
          'type' => 'heading',
          'label' => 'Divider Total',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'totals_divider_color',
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
          'key' => 'totals_divider_weight',
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
          'name' => 'totals_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .cart_totals .shop_table td:before, {{WRAPPER}} .cart_totals .shop_table td .woocommerce-Price-amount',
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
      'id' => 'section_cart_tabs_checkout_button',
      'label' => 'Checkout Button',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'checkout_button_normal_text_color',
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
          'key' => 'checkout_button_hover_text_color',
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
          'key' => 'checkout_button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'checkout_button_border_border!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'checkout_button_hover_transition_duration',
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
          'key' => 'checkout_button_hover_animation',
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
          'key' => 'checkout_button_border_radius',
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
          'key' => 'checkout_button_padding',
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
          'name' => 'checkout_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .checkout-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'checkout_button_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .checkout-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'background',
          'name' => 'checkout_button_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce .wc-proceed-to-checkout .checkout-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'checkout_button_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .checkout-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'background',
          'name' => 'checkout_button_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce .wc-proceed-to-checkout .checkout-button:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'box-shadow',
          'name' => 'checkout_button_hover_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .checkout-button:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'border',
          'name' => 'checkout_button_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .checkout-button',
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
      'id' => 'section_cart_tabs_customize',
      'label' => 'Customize',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'section_cart_show_customize_elements',
          'type' => 'select2',
          'label' => 'Select sections of the cart to customize:',
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
    12 =>
    array (
      'id' => 'section_cart_tabs_customize_order_summary',
      'label' => 'Customize: Order Summary',
      'tab' => 'style',
      'condition' =>
      array (
        'section_cart_show_customize_elements' => 'customize_order_summary',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'order_summary_section_title',
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
          'key' => 'order_summary_section_background_color',
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
          'key' => 'order_summary_section_border_type',
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
          'key' => 'order_summary_section_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_summary_section_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'order_summary_section_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'order_summary_section_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'order_summary_section_border_radius',
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
          'key' => 'order_summary_section_padding',
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
          'key' => 'order_summary_section_margin',
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
          'name' => 'order_summary_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-shop-table',
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
      'id' => 'section_cart_tabs_customize_totals',
      'label' => 'Customize: Totals',
      'tab' => 'style',
      'condition' =>
      array (
        'section_cart_show_customize_elements' => 'customize_totals',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'customize_totals_section_title',
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
          'key' => 'sections_totals_background_color',
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
          'key' => 'totals_section_border_type',
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
          'key' => 'totals_section_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'totals_section_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'totals_section_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'totals_section_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'totals_section_border_radius',
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
          'key' => 'checkout_sections_padding',
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
          'key' => 'totals_section_margin',
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
          'key' => 'totals_section_titles_title',
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
          'key' => 'totals_section_titles_color',
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
          'key' => 'totals_section_content_title',
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
          'key' => 'totals_section_content_color',
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
          'key' => 'totals_section_link_title',
          'type' => 'heading',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'totals_section_links_normal_color',
          'type' => 'color',
          'label' => 'Link Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'totals_section_links_hover_color',
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
          'name' => 'totals_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-cart-totals',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'totals_section_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .cart_totals h2',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'totals_section_titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .cart_totals h2',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'totals_section_content_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-cart-totals .e-cart-content, {{WRAPPER}} .e-cart-totals .woocommerce-shipping-destination, {{WRAPPER}} .e-cart-totals .shipping-calculator-button',
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
      'id' => 'section_cart_tabs_customize_coupon',
      'label' => 'Customize: Coupon',
      'tab' => 'style',
      'condition' =>
      array (
        'section_cart_show_customize_elements' => 'customize_coupon',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'coupon_section_title',
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
          'key' => 'customize_coupon_section_border_type',
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
          'key' => 'customize_coupon_section_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'customize_coupon_section_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'customize_coupon_section_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'customize_coupon_section_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'customize_coupon_section_border_radius',
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
          'key' => 'customize_coupon_section_padding',
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
          'key' => 'customize_coupon_section_margin',
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
          'name' => 'customize_coupon_section_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .coupon',
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
      'selector' => '{{WRAPPER}} .e-cart-section',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'sections_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .cart_totals h2',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'sections_titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .cart_totals h2',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'sections_descriptions_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-cart-content, {{WRAPPER}} .woocommerce-shipping-destination, {{WRAPPER}} .shipping-calculator-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'typography',
      'name' => 'sections_radio_buttons_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} #shipping_method li label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'forms_field_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .coupon .input-text, {{WRAPPER}} .cart-collaterals .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-selection--single',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'background',
      'name' => 'forms_fields_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .coupon .input-text, {{WRAPPER}} .e-cart-totals .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-selection--single',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_fields_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .coupon .input-text, {{WRAPPER}} .e-cart-totals .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-selection--single',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'background',
      'name' => 'forms_fields_focus_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .coupon .input-text:focus, {{WRAPPER}} .e-cart-totals .input-text:focus, {{WRAPPER}} select:focus, {{WRAPPER}} .select2-selection--single:focus',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_fields_focus_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .coupon .input-text:focus, {{WRAPPER}} .e-cart-totals .input-text:focus, {{WRAPPER}} select:focus, {{WRAPPER}} .select2-selection--single:focus',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'border',
      'name' => 'forms_fields_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .coupon .input-text, {{WRAPPER}} .cart-collaterals .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-selection--single',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'typography',
      'name' => 'forms_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'text-shadow',
      'name' => 'forms_button_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    13 =>
    array (
      'group' => 'background',
      'name' => 'forms_buttons_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    14 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_buttons_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    15 =>
    array (
      'group' => 'background',
      'name' => 'forms_buttons_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button:hover, {{WRAPPER}} .shop_table .button:disabled[disabled]:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    16 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_buttons_focus_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    17 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-shop-table .cart th, {{WRAPPER}} .e-shop-table .cart td:before',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    18 =>
    array (
      'group' => 'text-shadow',
      'name' => 'order_summary_title_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-shop-table .cart th, {{WRAPPER}} .e-shop-table .cart td:before',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    19 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_items_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .cart td span, {{WRAPPER}} .cart td, {{WRAPPER}} .input-text.qty',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    20 =>
    array (
      'group' => 'typography',
      'name' => 'order_summary_variations_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .product-name .variation',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    21 =>
    array (
      'group' => 'typography',
      'name' => 'totals_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .cart_totals .shop_table td:before, {{WRAPPER}} .cart_totals .shop_table td .woocommerce-Price-amount',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    22 =>
    array (
      'group' => 'typography',
      'name' => 'checkout_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .checkout-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    23 =>
    array (
      'group' => 'text-shadow',
      'name' => 'checkout_button_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .checkout-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    24 =>
    array (
      'group' => 'background',
      'name' => 'checkout_button_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce .wc-proceed-to-checkout .checkout-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    25 =>
    array (
      'group' => 'box-shadow',
      'name' => 'checkout_button_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .checkout-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    26 =>
    array (
      'group' => 'background',
      'name' => 'checkout_button_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce .wc-proceed-to-checkout .checkout-button:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    27 =>
    array (
      'group' => 'box-shadow',
      'name' => 'checkout_button_hover_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .checkout-button:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    28 =>
    array (
      'group' => 'border',
      'name' => 'checkout_button_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .checkout-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    29 =>
    array (
      'group' => 'box-shadow',
      'name' => 'order_summary_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-shop-table',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    30 =>
    array (
      'group' => 'box-shadow',
      'name' => 'totals_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-cart-totals',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    31 =>
    array (
      'group' => 'typography',
      'name' => 'totals_section_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .cart_totals h2',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    32 =>
    array (
      'group' => 'text-shadow',
      'name' => 'totals_section_titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .cart_totals h2',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    33 =>
    array (
      'group' => 'typography',
      'name' => 'totals_section_content_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-cart-totals .e-cart-content, {{WRAPPER}} .e-cart-totals .woocommerce-shipping-destination, {{WRAPPER}} .e-cart-totals .shipping-calculator-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    34 =>
    array (
      'group' => 'box-shadow',
      'name' => 'customize_coupon_section_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .coupon',
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
    'cart_layout' =>
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
        'cart_layout!' => 'one-column',
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
            'name' => 'cart_layout',
            'operator' => '!==',
            'value' => 'one-column',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'update_cart_button_heading' =>
    array (
      'section' => 'section_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'update_cart_button_text' =>
    array (
      'section' => 'section_order_summary',
      'type' => 'text',
      'default' => 'Update Cart',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'update_cart_button_alignment' =>
    array (
      'section' => 'section_order_summary',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_coupon_display' =>
    array (
      'section' => 'section_coupon',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'apply_coupon_heading' =>
    array (
      'section' => 'section_coupon',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'section_coupon_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'apply_coupon_button_text' =>
    array (
      'section' => 'section_coupon',
      'type' => 'text',
      'default' => 'Apply coupon',
      'responsive' => false,
      'condition' =>
      array (
        'section_coupon_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'apply_coupon_button_alignment' =>
    array (
      'section' => 'section_coupon',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'section_coupon_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'coupon_button_alignment_note' =>
    array (
      'section' => 'section_coupon',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'section_coupon_display' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_title' =>
    array (
      'section' => 'section_totals',
      'type' => 'text',
      'default' => 'Cart Totals',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_title_alignment' =>
    array (
      'section' => 'section_totals',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'update_shipping_button_heading' =>
    array (
      'section' => 'section_totals',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'update_shipping_button_text' =>
    array (
      'section' => 'section_totals',
      'type' => 'text',
      'default' => 'Update',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'update_shipping_button_alignment' =>
    array (
      'section' => 'section_totals',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_heading' =>
    array (
      'section' => 'section_totals',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_text' =>
    array (
      'section' => 'section_totals',
      'type' => 'text',
      'default' => 'Proceed to Checkout',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_alignment' =>
    array (
      'section' => 'section_totals',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'update_cart_automatically' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'update_cart_automatically_description' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_template_switch' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_template_description' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'additional_template_switch' => 'active',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_template_select_heading' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'additional_template_switch' => 'active',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_template_select' =>
    array (
      'section' => 'section_additional_options',
      'type' =>
      array (
        '__unresolved__' => 'QueryControlModule::QUERY_CONTROL_ID',
      ),
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'additional_template_switch' => 'active',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'edit_button' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'additional_template_switch' => 'active',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_background_color' =>
    array (
      'section' => 'section_cart_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_type' =>
    array (
      'section' => 'section_cart_tabs_style',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_width' =>
    array (
      'section' => 'section_cart_tabs_style',
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
      'section' => 'section_cart_tabs_style',
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
      'section' => 'section_cart_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_padding' =>
    array (
      'section' => 'section_cart_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_margin' =>
    array (
      'section' => 'section_cart_tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'section_normal_box_shadow',
    ),
    'sections_typography' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_title_color' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_title_spacing' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_descriptions_title' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_descriptions_color' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_descriptions_spacing' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_links_title' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'links_normal_color' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'links_hover_color' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_radio_buttons_title' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_radio_buttons_color' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_titles_typography_typography' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_titles_typography',
    ),
    'sections_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'sections_titles_text_shadow',
    ),
    'sections_descriptions_typography_typography' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_descriptions_typography',
    ),
    'sections_radio_buttons_typography_typography' =>
    array (
      'section' => 'section_cart_tabs_typography',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'sections_radio_buttons_typography',
    ),
    'forms_rows_gap' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_field_title' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_normal_color' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_focus_color' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_focus_border_color' =>
    array (
      'section' => 'section_cart_tabs_forms',
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
      'section' => 'section_cart_tabs_forms',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_border_radius' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_padding' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_button_title' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_normal_text_color' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_text_color' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_border_color' =>
    array (
      'section' => 'section_cart_tabs_forms',
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
      'section' => 'section_cart_tabs_forms',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_animation' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_border_type' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_border_width' =>
    array (
      'section' => 'section_cart_tabs_forms',
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
      'section' => 'section_cart_tabs_forms',
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
      'section' => 'section_cart_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_padding' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_field_typography_typography' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'forms_field_typography',
    ),
    'forms_fields_normal_background_background' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_fields_normal_background',
    ),
    'forms_fields_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_fields_normal_box_shadow',
    ),
    'forms_fields_focus_background_background' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_fields_focus_background',
    ),
    'forms_fields_focus_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_fields_focus_box_shadow',
    ),
    'forms_fields_border_border' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'forms_fields_border',
    ),
    'forms_button_typography_typography' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'forms_button_typography',
    ),
    'forms_button_text_shadow_text_shadow' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'forms_button_text_shadow',
    ),
    'forms_buttons_normal_background_background' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_buttons_normal_background',
    ),
    'forms_buttons_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_buttons_normal_box_shadow',
    ),
    'forms_buttons_hover_background_background' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_buttons_hover_background',
    ),
    'forms_buttons_focus_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_forms',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_buttons_focus_box_shadow',
    ),
    'order_summary_rows_gap' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_titles_title' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_title_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_title_spacing' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_title' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_variations_title' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_variations_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_product_link_title' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_link_normal_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'product_link_hover_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_divider_title' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_divider_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_items_divider_weight' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_quantity_border_title' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_quantity_border_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_quantity_border_weight' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_remove_icon_title' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_remove_icon_normal_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_remove_icon_hover_color' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_title_typography_typography' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_title_typography',
    ),
    'order_summary_title_text_shadow_text_shadow' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'order_summary_title_text_shadow',
    ),
    'order_summary_items_typography_typography' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_items_typography',
    ),
    'order_summary_variations_typography_typography' =>
    array (
      'section' => 'tabs_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'order_summary_variations_typography',
    ),
    'totals_rows_gap' =>
    array (
      'section' => 'section_cart_totals',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_title' =>
    array (
      'section' => 'section_cart_totals',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_color' =>
    array (
      'section' => 'section_cart_totals',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_divider_title' =>
    array (
      'section' => 'section_cart_totals',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_divider_color' =>
    array (
      'section' => 'section_cart_totals',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_divider_weight' =>
    array (
      'section' => 'section_cart_totals',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_typography_typography' =>
    array (
      'section' => 'section_cart_totals',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'totals_typography',
    ),
    'checkout_button_normal_text_color' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_hover_text_color' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_hover_border_color' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'checkout_button_border_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_hover_transition_duration' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_hover_animation' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_border_radius' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_padding' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_button_typography_typography' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'checkout_button_typography',
    ),
    'checkout_button_text_shadow_text_shadow' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'checkout_button_text_shadow',
    ),
    'checkout_button_normal_background_background' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'checkout_button_normal_background',
    ),
    'checkout_button_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'checkout_button_normal_box_shadow',
    ),
    'checkout_button_hover_background_background' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'checkout_button_hover_background',
    ),
    'checkout_button_hover_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'checkout_button_hover_box_shadow',
    ),
    'checkout_button_border_border' =>
    array (
      'section' => 'section_cart_tabs_checkout_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'checkout_button_border',
    ),
    'section_cart_show_customize_elements' =>
    array (
      'section' => 'section_cart_tabs_customize',
      'type' => 'select2',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_title' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_background_color' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_border_type' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_border_width' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'order_summary_section_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_border_color' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'order_summary_section_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_border_radius' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_padding' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_margin' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'order_summary_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_customize_order_summary',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'order_summary_section_normal_box_shadow',
    ),
    'customize_totals_section_title' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_totals_background_color' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_border_type' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_border_width' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'totals_section_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_border_color' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'totals_section_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_border_radius' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkout_sections_padding' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_margin' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_titles_title' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_titles_color' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_content_title' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_content_color' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_link_title' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_links_normal_color' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_links_hover_color' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'totals_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'totals_section_normal_box_shadow',
    ),
    'totals_section_titles_typography_typography' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'totals_section_titles_typography',
    ),
    'totals_section_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'totals_section_titles_text_shadow',
    ),
    'totals_section_content_typography_typography' =>
    array (
      'section' => 'section_cart_tabs_customize_totals',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'totals_section_content_typography',
    ),
    'coupon_section_title' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_background_color' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_section_border_type' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_section_border_width' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'customize_coupon_section_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_section_border_color' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'customize_coupon_section_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_section_border_radius' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_section_padding' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_section_margin' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_coupon_section_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_cart_tabs_customize_coupon',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'customize_coupon_section_normal_box_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'section_normal_box_shadow_box_shadow' => 'yes',
    'sections_titles_typography_typography' => 'custom',
    'sections_titles_text_shadow_text_shadow' => 'yes',
    'sections_descriptions_typography_typography' => 'custom',
    'sections_radio_buttons_typography_typography' => 'custom',
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
    'order_summary_title_typography_typography' => 'custom',
    'order_summary_title_text_shadow_text_shadow' => 'yes',
    'order_summary_items_typography_typography' => 'custom',
    'order_summary_variations_typography_typography' => 'custom',
    'totals_typography_typography' => 'custom',
    'checkout_button_typography_typography' => 'custom',
    'checkout_button_text_shadow_text_shadow' => 'yes',
    'checkout_button_normal_background_background' => 'classic',
    'checkout_button_normal_box_shadow_box_shadow' => 'yes',
    'checkout_button_hover_background_background' => 'classic',
    'checkout_button_hover_box_shadow_box_shadow' => 'yes',
    'checkout_button_border_border' => 'solid',
    'order_summary_section_normal_box_shadow_box_shadow' => 'yes',
    'totals_section_normal_box_shadow_box_shadow' => 'yes',
    'totals_section_titles_typography_typography' => 'custom',
    'totals_section_titles_text_shadow_text_shadow' => 'yes',
    'totals_section_content_typography_typography' => 'custom',
    'customize_coupon_section_normal_box_shadow_box_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 163,
);
