<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'woocommerce-my-account',
  'source' => 'wc',
  'widget_type' => 'woocommerce-my-account',
  'title' => 'My Account',
  'icon' => 'eicon-my-account',
  'categories' =>
  array (
    0 => 'woocommerce-elements',
  ),
  'keywords' =>
  array (
  ),
  'file' => 'pro-elements/modules/woocommerce/widgets/my-account.php',
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
      'label' => 'Tabs',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tabs_layout',
          'type' => 'select',
          'label' => 'Layout',
          'default' => 'vertical',
          'options' =>
          array (
            'vertical' => 'Vertical',
            'horizontal' => 'Horizontal',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'tabs_content_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'tabs_position',
          'type' => 'choose',
          'label' => 'Tabs Position',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-align-center-h',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
            'stretch' =>
            array (
              'title' => 'Stretch',
              'icon' => 'eicon-align-stretch-h',
            ),
          ),
          'condition' =>
          array (
            'tabs_layout' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'tabs',
          'type' => 'repeater',
          'label' => '',
          'default' =>
          array (
            0 =>
            array (
              'field_key' => 'dashboard',
              'field_label' => 'Dashboard',
              'tab_name' => 'Dashboard',
            ),
            1 =>
            array (
              'field_key' => 'orders',
              'field_label' => 'Orders',
              'tab_name' => 'Orders',
            ),
            2 =>
            array (
              'field_key' => 'downloads',
              'field_label' => 'Downloads',
              'tab_name' => 'Downloads',
            ),
            3 =>
            array (
              'field_key' => 'edit-address',
              'field_label' => 'Addresses',
              'tab_name' => 'Addresses',
            ),
            4 =>
            array (
              'field_key' => 'edit-account',
              'field_label' => 'Account Details',
              'tab_name' => 'Account Details',
            ),
            5 =>
            array (
              'field_key' => 'customer-logout',
              'field_label' => 'Logout',
              'tab_name' => 'Logout',
            ),
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'tabs_alignment',
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
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'tabs_position',
                'operator' => '!==',
                'value' => 'start',
              ),
              1 =>
              array (
                'name' => 'tabs_position',
                'operator' => '!==',
                'value' => 'center',
              ),
              2 =>
              array (
                'name' => 'tabs_position',
                'operator' => '!==',
                'value' => 'end',
              ),
            ),
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
      'id' => 'section_additional_options',
      'label' => 'Additional Options',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'customize_dashboard_check',
          'type' => 'switcher',
          'label' => 'Customize Dashboard',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'customize_dashboard_description',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'customize_dashboard_check' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'customize_dashboard_select_heading',
          'type' => 'heading',
          'label' => 'Choose template',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'customize_dashboard_check' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'customize_dashboard_select',
          'type' =>
          array (
            '__unresolved__' => 'QueryControlModule::QUERY_CONTROL_ID',
          ),
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'customize_dashboard_check' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'edit_button',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'customize_dashboard_check' => 'yes',
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
      'id' => 'tabs_style',
      'label' => 'Tabs',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tabs_normal_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'tabs_hover_color',
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
          'key' => 'tabs_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tabs_border_type!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'tabs_active_color',
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
          'key' => 'tabs_active_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tabs_border_type!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'tabs_border_type',
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
        6 =>
        array (
          'key' => 'tabs_border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tabs_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'tabs_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tabs_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'tabs_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'tabs_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'tabs_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'tabs_divider_title',
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
          'key' => 'tabs_divider_color',
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
          'key' => 'tabs_divider_weight',
          'type' => 'slider',
          'label' => 'Width',
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
          'name' => 'tabs_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' => 'tabs_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li:not(.is-active) a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'box-shadow',
          'name' => 'tabs_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li:not(.is-active) a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'background',
          'name' => 'tabs_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li a:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'box-shadow',
          'name' => 'tabs_hover_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li a:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'background',
          'name' => 'tabs_active_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li.is-active a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'box-shadow',
          'name' => 'tabs_active_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-MyAccount-navigation ul li.is-active a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    3 =>
    array (
      'id' => 'sections_title',
      'label' => 'Sections',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'my_account_sections_background_color',
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
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'box-shadow',
          'name' => 'my_account_sections_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab__dashboard:not(.e-my-account-tab__dashboard--custom) .woocommerce-MyAccount-content-wrapper, {{WRAPPER}} .e-my-account-tab__orders .woocommerce-MyAccount-content-wrapper, {{WRAPPER}} .e-my-account-tab__downloads .woocommerce-MyAccount-content-wrapper, {{WRAPPER}} address, {{WRAPPER}} .e-my-account-tab__edit-account .woocommerce-MyAccount-content-wrapper, {{WRAPPER}} .e-my-account-tab__view-order .order_details, {{WRAPPER}} .woocommerce-form-login, {{WRAPPER}} .woocommerce-form-register, {{WRAPPER}} .woocommerce-ResetPassword, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper',
          'condition' => NULL,
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
      'id' => 'typography_title',
      'label' => 'Typography',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'typography_titles',
          'type' => 'heading',
          'label' => 'Section Titles',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'typography_section_titles_color',
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
          'key' => 'section_title_spacing',
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
          'key' => 'typography_secondary_titles',
          'type' => 'heading',
          'label' => 'General Text',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
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
        5 =>
        array (
          'key' => 'typography_login_messages_title',
          'type' => 'heading',
          'label' => 'Login Messages',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'login_messages_color',
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
          'key' => 'checkboxes_title',
          'type' => 'heading',
          'label' => 'Checkboxes',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'checkboxes_color',
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
          'key' => 'payment_methods_radio_buttons_title',
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
          'key' => 'payment_methods_radio_buttons_color',
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
          'key' => 'links_title',
          'type' => 'heading',
          'label' => 'Links',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'links_normal_color',
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
          'key' => 'links_hover_color',
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
          'name' => 'section_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) h2, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) h3',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'section_titles_typography_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) h2, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) h3',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'general_text_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-MyAccount-content > div > p, {{WRAPPER}} address, {{WRAPPER}} .woocommerce-EditAccountForm fieldset legend, {{WRAPPER}} .woocommerce-ResetPassword p:nth-child(1), {{WRAPPER}} .woocommerce-OrderUpdate',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'login_messages_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .register p:not([class]), {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce em',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'checkboxes_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-form__label-for-checkbox span',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'typography',
          'name' => 'payment_methods_radio_buttons_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .woocommerce-PaymentMethod .input-radio + label',
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
      'id' => 'forms_section',
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
          'label' => 'Buttons',
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
            'forms_buttons_border_border!' => '',
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
        19 =>
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
          'selector' => '{{WRAPPER}} .woocommerce-form-row label, {{WRAPPER}} .woocommerce-address-fields label',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'forms_field_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row .input-text, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row select, {{WRAPPER}} ::placeholder, {{WRAPPER}} .select2-container--default .select2-selection--single, .select2-results__option, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'background',
          'name' => 'forms_fields_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row .input-text, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row select, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default .select2-selection--single, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default, .select2-results__option, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_fields_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-container--default .select2-selection--single, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'background',
          'name' => 'forms_fields_focus_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row .input-text:focus, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row select:focus, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default.select2-container--focus .select2-selection--single, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default.select2-container--focus, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]:focus',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_fields_focus_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .input-text:focus, {{WRAPPER}} select:focus, {{WRAPPER}} .select2-container--default .select2-selection--single:focus, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]:focus',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'border',
          'name' => 'forms_fields_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row .input-text, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row select, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        7 =>
        array (
          'group' => 'typography',
          'name' => 'forms_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} button.button, {{WRAPPER}} #add_payment_method #payment #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        8 =>
        array (
          'group' => 'text-shadow',
          'name' => 'forms_button_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} button.button, {{WRAPPER}} #add_payment_method #payment #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        9 =>
        array (
          'group' => 'background',
          'name' => 'forms_buttons_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .login .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .register .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-ResetPassword .button, {{WRAPPER}} #add_payment_method #payment #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        10 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_buttons_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button, {{WRAPPER}} button.button, {{WRAPPER}} #add_payment_method #payment #place_order',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        11 =>
        array (
          'group' => 'background',
          'name' => 'forms_buttons_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .login .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .register .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-ResetPassword .button:hover, {{WRAPPER}} #add_payment_method #payment #place_order:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        12 =>
        array (
          'group' => 'box-shadow',
          'name' => 'forms_buttons_focus_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button:hover, {{WRAPPER}} button.button:hover, {{WRAPPER}} #add_payment_method #payment #place_order:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        13 =>
        array (
          'group' => 'border',
          'name' => 'forms_buttons_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .login .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .register .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-ResetPassword .button, {{WRAPPER}} #add_payment_method #payment #place_order',
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
      'id' => 'tables_section',
      'label' => 'Order Details',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tables_rows_gap',
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
          'key' => 'tables_titles',
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
          'key' => 'tables_title_color',
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
          'key' => 'tables_items_title',
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
          'key' => 'tables_items_color',
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
          'key' => 'variations_title',
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
          'key' => 'variations_color',
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
          'key' => 'sections_links_title',
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
          'key' => 'tables_links_normal_color',
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
          'key' => 'tables_links_hover_color',
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
          'key' => 'tables_divider_title',
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
          'key' => 'tables_divider_border_type',
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
          'key' => 'tables_divider_border_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tables_divider_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'tables_divider_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tables_divider_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'tables_button_title',
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
          'key' => 'tables_button_normal_text_color',
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
          'key' => 'tables_button_hover_text_color',
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
          'key' => 'tables_button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tables_button_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'tables_button_hover_transition_duration',
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
          'key' => 'tables_button_hover_animation',
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
          'key' => 'tables_button_border_type',
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
          'key' => 'tables_button_border_width',
          'type' => 'dimensions',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tables_button_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        22 =>
        array (
          'key' => 'tables_button_border_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tables_button_border_type!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        23 =>
        array (
          'key' => 'tables_button_border_radius',
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
          'key' => 'tables_button_padding',
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
          'name' => 'tables_titles_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .order_details thead th, {{WRAPPER}} .order_details tfoot td, {{WRAPPER}} .order_details tfoot th, {{WRAPPER}} .nobr',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'tables_titles_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .order_details thead th, {{WRAPPER}} .order_details tfoot td, {{WRAPPER}} .order_details tfoot th, {{WRAPPER}} .nobr',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'tables_items_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab__orders tbody td, {{WRAPPER}} .e-my-account-tab__downloads tbody td, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .product-quantity, {{WRAPPER}} .woocommerce-table--order-downloads tbody td, {{WRAPPER}} .woocommerce-table--order-details td a, {{WRAPPER}} td.product-total, {{WRAPPER}} td.payment-method-method, {{WRAPPER}} td.payment-method-expires',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'variations_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .wc-item-meta',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'tables_button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button, {{WRAPPER}} .woocommerce-pagination .button, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'text-shadow',
          'name' => 'tables_button_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button, {{WRAPPER}} .woocommerce-pagination .button, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'background',
          'name' => 'tables_button_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .shop_table .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .order-again .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-pagination .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom).e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        7 =>
        array (
          'group' => 'box-shadow',
          'name' => 'tables_button_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button, {{WRAPPER}} .woocommerce-pagination .button, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        8 =>
        array (
          'group' => 'background',
          'name' => 'tables_button_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .shop_table .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .order-again .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-pagination .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom).e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        9 =>
        array (
          'group' => 'box-shadow',
          'name' => 'tables_button_hover_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .shop_table .button:hover, {{WRAPPER}} .order-again .button:hover, {{WRAPPER}} .woocommerce-pagination .button:hover, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button:hover',
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
      'name' => 'tabs_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'background',
      'name' => 'tabs_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li:not(.is-active) a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'box-shadow',
      'name' => 'tabs_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li:not(.is-active) a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'background',
      'name' => 'tabs_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li a:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'box-shadow',
      'name' => 'tabs_hover_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li a:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'background',
      'name' => 'tabs_active_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab .woocommerce .woocommerce-MyAccount-navigation ul li.is-active a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'box-shadow',
      'name' => 'tabs_active_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-MyAccount-navigation ul li.is-active a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'box-shadow',
      'name' => 'my_account_sections_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab__dashboard:not(.e-my-account-tab__dashboard--custom) .woocommerce-MyAccount-content-wrapper, {{WRAPPER}} .e-my-account-tab__orders .woocommerce-MyAccount-content-wrapper, {{WRAPPER}} .e-my-account-tab__downloads .woocommerce-MyAccount-content-wrapper, {{WRAPPER}} address, {{WRAPPER}} .e-my-account-tab__edit-account .woocommerce-MyAccount-content-wrapper, {{WRAPPER}} .e-my-account-tab__view-order .order_details, {{WRAPPER}} .woocommerce-form-login, {{WRAPPER}} .woocommerce-form-register, {{WRAPPER}} .woocommerce-ResetPassword, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'typography',
      'name' => 'section_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) h2, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) h3',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'text-shadow',
      'name' => 'section_titles_typography_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) h2, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) h3',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'typography',
      'name' => 'general_text_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-MyAccount-content > div > p, {{WRAPPER}} address, {{WRAPPER}} .woocommerce-EditAccountForm fieldset legend, {{WRAPPER}} .woocommerce-ResetPassword p:nth-child(1), {{WRAPPER}} .woocommerce-OrderUpdate',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'typography',
      'name' => 'login_messages_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .register p:not([class]), {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce em',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'typography',
      'name' => 'checkboxes_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-form__label-for-checkbox span',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    13 =>
    array (
      'group' => 'typography',
      'name' => 'payment_methods_radio_buttons_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-PaymentMethod .input-radio + label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    14 =>
    array (
      'group' => 'typography',
      'name' => 'forms_label_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .woocommerce-form-row label, {{WRAPPER}} .woocommerce-address-fields label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    15 =>
    array (
      'group' => 'typography',
      'name' => 'forms_field_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row .input-text, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row select, {{WRAPPER}} ::placeholder, {{WRAPPER}} .select2-container--default .select2-selection--single, .select2-results__option, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    16 =>
    array (
      'group' => 'background',
      'name' => 'forms_fields_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row .input-text, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row select, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default .select2-selection--single, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default, .select2-results__option, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    17 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_fields_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .input-text, {{WRAPPER}} select, {{WRAPPER}} .select2-container--default .select2-selection--single, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    18 =>
    array (
      'group' => 'background',
      'name' => 'forms_fields_focus_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row .input-text:focus, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row select:focus, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default.select2-container--focus .select2-selection--single, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default.select2-container--focus, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]:focus',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    19 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_fields_focus_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .input-text:focus, {{WRAPPER}} select:focus, {{WRAPPER}} .select2-container--default .select2-selection--single:focus, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]:focus',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    20 =>
    array (
      'group' => 'border',
      'name' => 'forms_fields_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row .input-text, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .form-row select, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .select2-container--default, {{WRAPPER}} .e-my-account-tab__payment-methods input[type=text]',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    21 =>
    array (
      'group' => 'typography',
      'name' => 'forms_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} button.button, {{WRAPPER}} #add_payment_method #payment #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    22 =>
    array (
      'group' => 'text-shadow',
      'name' => 'forms_button_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} button.button, {{WRAPPER}} #add_payment_method #payment #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    23 =>
    array (
      'group' => 'background',
      'name' => 'forms_buttons_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .login .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .register .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-ResetPassword .button, {{WRAPPER}} #add_payment_method #payment #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    24 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_buttons_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button, {{WRAPPER}} button.button, {{WRAPPER}} #add_payment_method #payment #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    25 =>
    array (
      'group' => 'background',
      'name' => 'forms_buttons_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .login .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .register .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-ResetPassword .button:hover, {{WRAPPER}} #add_payment_method #payment #place_order:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    26 =>
    array (
      'group' => 'box-shadow',
      'name' => 'forms_buttons_focus_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button:hover, {{WRAPPER}} button.button:hover, {{WRAPPER}} #add_payment_method #payment #place_order:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    27 =>
    array (
      'group' => 'border',
      'name' => 'forms_buttons_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-EditAccountForm .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce-address-fields .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .login .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .register .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-ResetPassword .button, {{WRAPPER}} #add_payment_method #payment #place_order',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    28 =>
    array (
      'group' => 'typography',
      'name' => 'tables_titles_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .order_details thead th, {{WRAPPER}} .order_details tfoot td, {{WRAPPER}} .order_details tfoot th, {{WRAPPER}} .nobr',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    29 =>
    array (
      'group' => 'text-shadow',
      'name' => 'tables_titles_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .order_details thead th, {{WRAPPER}} .order_details tfoot td, {{WRAPPER}} .order_details tfoot th, {{WRAPPER}} .nobr',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    30 =>
    array (
      'group' => 'typography',
      'name' => 'tables_items_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab__orders tbody td, {{WRAPPER}} .e-my-account-tab__downloads tbody td, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .product-quantity, {{WRAPPER}} .woocommerce-table--order-downloads tbody td, {{WRAPPER}} .woocommerce-table--order-details td a, {{WRAPPER}} td.product-total, {{WRAPPER}} td.payment-method-method, {{WRAPPER}} td.payment-method-expires',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    31 =>
    array (
      'group' => 'typography',
      'name' => 'variations_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .wc-item-meta',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    32 =>
    array (
      'group' => 'typography',
      'name' => 'tables_button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button, {{WRAPPER}} .woocommerce-pagination .button, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    33 =>
    array (
      'group' => 'text-shadow',
      'name' => 'tables_button_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button, {{WRAPPER}} .woocommerce-pagination .button, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    34 =>
    array (
      'group' => 'background',
      'name' => 'tables_button_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .shop_table .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .order-again .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-pagination .button, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom).e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    35 =>
    array (
      'group' => 'box-shadow',
      'name' => 'tables_button_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button, {{WRAPPER}} .order-again .button, {{WRAPPER}} .woocommerce-pagination .button, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    36 =>
    array (
      'group' => 'background',
      'name' => 'tables_button_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .shop_table .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .order-again .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom) .woocommerce .woocommerce-pagination .button:hover, {{WRAPPER}} .e-my-account-tab:not(.e-my-account-tab__dashboard--custom).e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    37 =>
    array (
      'group' => 'box-shadow',
      'name' => 'tables_button_hover_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .shop_table .button:hover, {{WRAPPER}} .order-again .button:hover, {{WRAPPER}} .woocommerce-pagination .button:hover, {{WRAPPER}} .e-my-account-tab__payment-methods .woocommerce-MyAccount-content-wrapper .button:hover',
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
          'key' => 'tab_name',
          'type' => 'text',
          'label' => 'Tab Name',
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
          'key' => 'order_display_description',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'field_key' => 'orders',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
    ),
  ),
  'settings_index' =>
  array (
    'tabs_layout' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'select',
      'default' => 'vertical',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_content_spacing' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_position' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tabs_layout' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'field_key' => 'dashboard',
          'field_label' => 'Dashboard',
          'tab_name' => 'Dashboard',
        ),
        1 =>
        array (
          'field_key' => 'orders',
          'field_label' => 'Orders',
          'tab_name' => 'Orders',
        ),
        2 =>
        array (
          'field_key' => 'downloads',
          'field_label' => 'Downloads',
          'tab_name' => 'Downloads',
        ),
        3 =>
        array (
          'field_key' => 'edit-address',
          'field_label' => 'Addresses',
          'tab_name' => 'Addresses',
        ),
        4 =>
        array (
          'field_key' => 'edit-account',
          'field_label' => 'Account Details',
          'tab_name' => 'Account Details',
        ),
        5 =>
        array (
          'field_key' => 'customer-logout',
          'field_label' => 'Logout',
          'tab_name' => 'Logout',
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_alignment' =>
    array (
      'section' => 'section_menu_icon_content',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'tabs_position',
            'operator' => '!==',
            'value' => 'start',
          ),
          1 =>
          array (
            'name' => 'tabs_position',
            'operator' => '!==',
            'value' => 'center',
          ),
          2 =>
          array (
            'name' => 'tabs_position',
            'operator' => '!==',
            'value' => 'end',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_dashboard_check' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_dashboard_description' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'customize_dashboard_check' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_dashboard_select_heading' =>
    array (
      'section' => 'section_additional_options',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'customize_dashboard_check' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'customize_dashboard_select' =>
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
        'customize_dashboard_check' => 'yes',
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
        'customize_dashboard_check' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_normal_color' =>
    array (
      'section' => 'tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_hover_color' =>
    array (
      'section' => 'tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_hover_border_color' =>
    array (
      'section' => 'tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tabs_border_type!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_active_color' =>
    array (
      'section' => 'tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_active_border_color' =>
    array (
      'section' => 'tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tabs_border_type!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_border_type' =>
    array (
      'section' => 'tabs_style',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_border_width' =>
    array (
      'section' => 'tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'tabs_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_border_color' =>
    array (
      'section' => 'tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tabs_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_border_radius' =>
    array (
      'section' => 'tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_padding' =>
    array (
      'section' => 'tabs_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_spacing' =>
    array (
      'section' => 'tabs_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_divider_title' =>
    array (
      'section' => 'tabs_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_divider_color' =>
    array (
      'section' => 'tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_divider_weight' =>
    array (
      'section' => 'tabs_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_typography_typography' =>
    array (
      'section' => 'tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'tabs_typography',
    ),
    'tabs_normal_background_background' =>
    array (
      'section' => 'tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'tabs_normal_background',
    ),
    'tabs_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'tabs_normal_box_shadow',
    ),
    'tabs_hover_background_background' =>
    array (
      'section' => 'tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'tabs_hover_background',
    ),
    'tabs_hover_box_shadow_box_shadow' =>
    array (
      'section' => 'tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'tabs_hover_box_shadow',
    ),
    'tabs_active_background_background' =>
    array (
      'section' => 'tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'tabs_active_background',
    ),
    'tabs_active_box_shadow_box_shadow' =>
    array (
      'section' => 'tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'tabs_active_box_shadow',
    ),
    'my_account_sections_background_color' =>
    array (
      'section' => 'sections_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_type' =>
    array (
      'section' => 'sections_title',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_border_width' =>
    array (
      'section' => 'sections_title',
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
      'section' => 'sections_title',
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
      'section' => 'sections_title',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_padding' =>
    array (
      'section' => 'sections_title',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'my_account_sections_box_shadow_box_shadow' =>
    array (
      'section' => 'sections_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'my_account_sections_box_shadow',
    ),
    'typography_titles' =>
    array (
      'section' => 'typography_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_section_titles_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_title_spacing' =>
    array (
      'section' => 'typography_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_secondary_titles' =>
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
    'typography_login_messages_title' =>
    array (
      'section' => 'typography_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'login_messages_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkboxes_title' =>
    array (
      'section' => 'typography_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'checkboxes_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_methods_radio_buttons_title' =>
    array (
      'section' => 'typography_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'payment_methods_radio_buttons_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'links_title' =>
    array (
      'section' => 'typography_title',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'links_normal_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'links_hover_color' =>
    array (
      'section' => 'typography_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'section_titles_typography_typography' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'section_titles_typography',
    ),
    'section_titles_typography_text_shadow_text_shadow' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'section_titles_typography_text_shadow',
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
    'login_messages_typography_typography' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'login_messages_typography',
    ),
    'checkboxes_typography_typography' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'checkboxes_typography',
    ),
    'payment_methods_radio_buttons_typography_typography' =>
    array (
      'section' => 'typography_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'payment_methods_radio_buttons_typography',
    ),
    'forms_columns_gap' =>
    array (
      'section' => 'forms_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_rows_gap' =>
    array (
      'section' => 'forms_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_label_title' =>
    array (
      'section' => 'forms_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_label_color' =>
    array (
      'section' => 'forms_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_label_spacing' =>
    array (
      'section' => 'forms_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_field_title' =>
    array (
      'section' => 'forms_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_normal_color' =>
    array (
      'section' => 'forms_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_focus_color' =>
    array (
      'section' => 'forms_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_focus_border_color' =>
    array (
      'section' => 'forms_section',
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
      'section' => 'forms_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_border_radius' =>
    array (
      'section' => 'forms_section',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_fields_padding' =>
    array (
      'section' => 'forms_section',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_button_title' =>
    array (
      'section' => 'forms_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_normal_text_color' =>
    array (
      'section' => 'forms_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_text_color' =>
    array (
      'section' => 'forms_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_border_color' =>
    array (
      'section' => 'forms_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'forms_buttons_border_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_transition_duration' =>
    array (
      'section' => 'forms_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_hover_animation' =>
    array (
      'section' => 'forms_section',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_border_radius' =>
    array (
      'section' => 'forms_section',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_buttons_padding' =>
    array (
      'section' => 'forms_section',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'forms_label_typography_typography' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'forms_label_typography',
    ),
    'forms_field_typography_typography' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'forms_field_typography',
    ),
    'forms_fields_normal_background_background' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_fields_normal_background',
    ),
    'forms_fields_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_fields_normal_box_shadow',
    ),
    'forms_fields_focus_background_background' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_fields_focus_background',
    ),
    'forms_fields_focus_box_shadow_box_shadow' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_fields_focus_box_shadow',
    ),
    'forms_fields_border_border' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'forms_fields_border',
    ),
    'forms_button_typography_typography' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'forms_button_typography',
    ),
    'forms_button_text_shadow_text_shadow' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'forms_button_text_shadow',
    ),
    'forms_buttons_background_background' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_buttons_background',
    ),
    'forms_buttons_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_buttons_normal_box_shadow',
    ),
    'forms_buttons_hover_background_background' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'forms_buttons_hover_background',
    ),
    'forms_buttons_focus_box_shadow_box_shadow' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'forms_buttons_focus_box_shadow',
    ),
    'forms_buttons_border_border' =>
    array (
      'section' => 'forms_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'forms_buttons_border',
    ),
    'tables_rows_gap' =>
    array (
      'section' => 'tables_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_titles' =>
    array (
      'section' => 'tables_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_title_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_items_title' =>
    array (
      'section' => 'tables_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_items_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_title' =>
    array (
      'section' => 'tables_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'variations_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sections_links_title' =>
    array (
      'section' => 'tables_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_links_normal_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_links_hover_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_divider_title' =>
    array (
      'section' => 'tables_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_divider_border_type' =>
    array (
      'section' => 'tables_section',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_divider_border_width' =>
    array (
      'section' => 'tables_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'tables_divider_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_divider_border_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tables_divider_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_title' =>
    array (
      'section' => 'tables_section',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_normal_text_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_hover_text_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_hover_border_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tables_button_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_hover_transition_duration' =>
    array (
      'section' => 'tables_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_hover_animation' =>
    array (
      'section' => 'tables_section',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_border_type' =>
    array (
      'section' => 'tables_section',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_border_width' =>
    array (
      'section' => 'tables_section',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'tables_button_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_border_color' =>
    array (
      'section' => 'tables_section',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tables_button_border_type!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_border_radius' =>
    array (
      'section' => 'tables_section',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_button_padding' =>
    array (
      'section' => 'tables_section',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tables_titles_typography_typography' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'tables_titles_typography',
    ),
    'tables_titles_text_shadow_text_shadow' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'tables_titles_text_shadow',
    ),
    'tables_items_typography_typography' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'tables_items_typography',
    ),
    'variations_typography_typography' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'variations_typography',
    ),
    'tables_button_typography_typography' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'tables_button_typography',
    ),
    'tables_button_text_shadow_text_shadow' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'tables_button_text_shadow',
    ),
    'tables_button_normal_background_background' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'tables_button_normal_background',
    ),
    'tables_button_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'tables_button_normal_box_shadow',
    ),
    'tables_button_hover_background_background' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'tables_button_hover_background',
    ),
    'tables_button_hover_box_shadow_box_shadow' =>
    array (
      'section' => 'tables_section',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'tables_button_hover_box_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'tabs_typography_typography' => 'custom',
    'tabs_normal_background_background' => 'classic',
    'tabs_normal_box_shadow_box_shadow' => 'yes',
    'tabs_hover_background_background' => 'classic',
    'tabs_hover_box_shadow_box_shadow' => 'yes',
    'tabs_active_background_background' => 'classic',
    'tabs_active_box_shadow_box_shadow' => 'yes',
    'my_account_sections_box_shadow_box_shadow' => 'yes',
    'section_titles_typography_typography' => 'custom',
    'section_titles_typography_text_shadow_text_shadow' => 'yes',
    'general_text_typography_typography' => 'custom',
    'login_messages_typography_typography' => 'custom',
    'checkboxes_typography_typography' => 'custom',
    'payment_methods_radio_buttons_typography_typography' => 'custom',
    'forms_label_typography_typography' => 'custom',
    'forms_field_typography_typography' => 'custom',
    'forms_fields_normal_background_background' => 'classic',
    'forms_fields_normal_box_shadow_box_shadow' => 'yes',
    'forms_fields_focus_background_background' => 'classic',
    'forms_fields_focus_box_shadow_box_shadow' => 'yes',
    'forms_fields_border_border' => 'solid',
    'forms_button_typography_typography' => 'custom',
    'forms_button_text_shadow_text_shadow' => 'yes',
    'forms_buttons_background_background' => 'classic',
    'forms_buttons_normal_box_shadow_box_shadow' => 'yes',
    'forms_buttons_hover_background_background' => 'classic',
    'forms_buttons_focus_box_shadow_box_shadow' => 'yes',
    'forms_buttons_border_border' => 'solid',
    'tables_titles_typography_typography' => 'custom',
    'tables_titles_text_shadow_text_shadow' => 'yes',
    'tables_items_typography_typography' => 'custom',
    'variations_typography_typography' => 'custom',
    'tables_button_typography_typography' => 'custom',
    'tables_button_text_shadow_text_shadow' => 'yes',
    'tables_button_normal_background_background' => 'classic',
    'tables_button_normal_box_shadow_box_shadow' => 'yes',
    'tables_button_hover_background_background' => 'classic',
    'tables_button_hover_box_shadow_box_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
  ),
  'control_count' => 127,
);
