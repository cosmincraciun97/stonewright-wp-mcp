<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'price-table',
  'source' => 'pro',
  'widget_type' => 'price-table',
  'title' => 'Price Table',
  'icon' => 'eicon-price-table',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'pricing',
    1 => 'table',
    2 => 'product',
    3 => 'image',
    4 => 'plan',
    5 => 'button',
  ),
  'file' => 'pro-elements/modules/pricing/widgets/price-table.php',
  'intent' => 'Set the Header’s Title tag, choosing from H2 – H6. Currency Symbol',
  'use_cases' =>
  array (
    0 => 'In Elementor Editor, click +',
    1 => 'All available widgets are displayed',
    2 => 'Click or drag the widget to the canvas',
    3 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Add a Price Table widget – Step-by-step',
    1 => 'Choose the position of the ribbon – top left or top right.',
    2 => 'Color – Set the color of the main title text.Typography: Define the typography options for the main title, including font style, size, weight, and more. Learn more about Typography.',
    3 => 'Color – Set the color of the subtitle text.Typography: Define the typography options for the subtitle, such as font style, size, weight, and more. Learn more about Typography.',
    4 => 'Size – Change the size of the currency symbol.Position: Determine whether the currency symbol appears to the left or right of the price.Vertical Position: Specify the vertical alignment of the currency symbol.',
    5 => 'Size – Adjust the size of the fractional part.Vertical Position: Determine the vertical alignment of the fractional part.',
    6 => 'Color – Set the color of the original price text.Typography: Define the typography options for the original price text. Learn more about Typography.Vertical Position: Specify the vertical alignment of the original price.',
    7 => 'Color – Set the color of the period text.Typography: Define the typography options for the period text. Learn more about Typography.Position: Specify whether the period text appears below or beside the price.',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_header',
      'label' => 'Header',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Enter your title',
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
          'key' => 'sub_heading',
          'type' => 'text',
          'label' => 'Description',
          'default' => 'Enter your description',
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
          'key' => 'heading_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
          'default' => 'h3',
          'options' =>
          array (
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
          ),
          'condition' =>
          array (
            'heading!' => '',
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
      'id' => 'section_pricing',
      'label' => 'Pricing',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'currency_symbol',
          'type' => 'select',
          'label' => 'Currency Symbol',
          'default' => 'dollar',
          'options' =>
          array (
            '' => 'None',
            'dollar' => '&#36; Dollar',
            'euro' => '&#128; Euro',
            'baht' => '&#3647; Baht',
            'franc' => '&#8355; Franc',
            'guilder' => '&fnof; Guilder',
            'krona' => 'kr Krona',
            'lira' => '&#8356; Lira',
            'peseta' => '&#8359 Peseta',
            'peso' => '&#8369; Peso',
            'pound' => '&#163; Pound Sterling',
            'real' => 'R$ Real',
            'ruble' => '&#8381; Ruble',
            'rupee' => '&#8360; Rupee',
            'indian_rupee' => '&#8377; Rupee (Indian)',
            'shekel' => '&#8362; Shekel',
            'yen' => '&#165; Yen/Yuan',
            'won' => '&#8361; Won',
            'custom' => 'Custom',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'currency_symbol_custom',
          'type' => 'text',
          'label' => 'Custom Symbol',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'currency_symbol' => 'custom',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'price',
          'type' => 'text',
          'label' => 'Price',
          'default' => '39.99',
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
          'key' => 'currency_format',
          'type' => 'select',
          'label' => 'Currency Format',
          'default' => NULL,
          'options' =>
          array (
            '' => '1,234.56 (Default)',
            ',' => '1.234,56',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'sale',
          'type' => 'switcher',
          'label' => 'Sale',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'original_price',
          'type' => 'number',
          'label' => 'Original Price',
          'default' => '59',
          'options' => NULL,
          'condition' =>
          array (
            'sale' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'period',
          'type' => 'text',
          'label' => 'Period',
          'default' => 'Monthly',
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
      'id' => 'section_features',
      'label' => 'Features',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'features_list',
          'type' => 'repeater',
          'label' => NULL,
          'default' =>
          array (
            0 =>
            array (
              'item_text' => 'List Item #1',
              'selected_item_icon' =>
              array (
                '__unresolved__' => '$default_icon',
              ),
            ),
            1 =>
            array (
              'item_text' => 'List Item #2',
              'selected_item_icon' =>
              array (
                '__unresolved__' => '$default_icon',
              ),
            ),
            2 =>
            array (
              'item_text' => 'List Item #3',
              'selected_item_icon' =>
              array (
                '__unresolved__' => '$default_icon',
              ),
            ),
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
      'id' => 'section_footer',
      'label' => 'Footer',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'button_text',
          'type' => 'text',
          'label' => 'Button Text',
          'default' => 'Click Here',
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
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' =>
          array (
            'url' => '#',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
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
          'key' => 'footer_additional_info',
          'type' => 'textarea',
          'label' => 'Additional Info',
          'default' => 'This is text element',
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
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
    4 =>
    array (
      'id' => 'section_ribbon',
      'label' => 'Ribbon',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'show_ribbon',
          'type' => 'switcher',
          'label' => 'Show',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'ribbon_title',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Popular',
          'options' => NULL,
          'condition' =>
          array (
            'show_ribbon' => 'yes',
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
          'key' => 'ribbon_horizontal_position',
          'type' => 'choose',
          'label' => 'Position',
          'default' => NULL,
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'show_ribbon' => 'yes',
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
      'id' => 'section_header_style',
      'label' => 'Header',
      'tab' => 'style',
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'heading',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'sub_heading',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'header_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'heading',
                'operator' => '!==',
                'value' => '',
              ),
              1 =>
              array (
                'name' => 'sub_heading',
                'operator' => '!==',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'header_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'heading',
                'operator' => '!==',
                'value' => '',
              ),
              1 =>
              array (
                'name' => 'sub_heading',
                'operator' => '!==',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'heading_heading_style',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'heading!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'heading!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'heading_sub_heading_style',
          'type' => 'heading',
          'label' => 'Sub Title',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'sub_heading!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'sub_heading_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'sub_heading!' => '',
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
          'name' => 'heading_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__heading',
          'condition' =>
          array (
            'heading!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'sub_heading_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__subheading',
          'condition' =>
          array (
            'sub_heading!' => '',
          ),
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
      'id' => 'section_pricing_element_style',
      'label' => 'Pricing',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'pricing_element_bg_color',
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
          'key' => 'pricing_element_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'price_color',
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
          'key' => 'heading_currency_style',
          'type' => 'heading',
          'label' => 'Currency Symbol',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'currency_symbol!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'currency_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'currency_symbol!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'currency_position',
          'type' => 'choose',
          'label' => 'Position',
          'default' => 'before',
          'options' =>
          array (
            'before' =>
            array (
              'title' => 'Before',
              'icon' => 'eicon-h-align-left',
            ),
            'after' =>
            array (
              'title' => 'After',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'currency_vertical_position',
          'type' => 'choose',
          'label' => 'Vertical Position',
          'default' => 'top',
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
            'currency_symbol!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'fractional_part_style',
          'type' => 'heading',
          'label' => 'Fractional Part',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'fractional-part_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'fractional_part_vertical_position',
          'type' => 'choose',
          'label' => 'Vertical Position',
          'default' => 'top',
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
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'heading_original_price_style',
          'type' => 'heading',
          'label' => 'Original Price',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'sale' => 'yes',
            'original_price!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'original_price_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'sale' => 'yes',
            'original_price!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'original_price_vertical_position',
          'type' => 'choose',
          'label' => 'Vertical Position',
          'default' => 'bottom',
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
            'sale' => 'yes',
            'original_price!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'heading_period_style',
          'type' => 'heading',
          'label' => 'Period',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'period!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'period_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'period!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'period_position',
          'type' => 'select',
          'label' => 'Position',
          'default' => 'below',
          'options' =>
          array (
            'below' => 'Below',
            'beside' => 'Beside',
          ),
          'condition' =>
          array (
            'period!' => '',
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
          'name' => 'price_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table .elementor-price-table__price',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'original_price_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__original-price',
          'condition' =>
          array (
            'sale' => 'yes',
            'original_price!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'period_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__period',
          'condition' =>
          array (
            'period!' => '',
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
      'id' => 'section_features_list_style',
      'label' => 'Features',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'features_list_bg_color',
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
          'key' => 'features_list_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'features_list_color',
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
          'key' => 'features_list_alignment',
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
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'item_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'list_divider',
          'type' => 'switcher',
          'label' => 'Divider',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
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
            'list_divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'divider_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '#ddd',
          'options' => NULL,
          'condition' =>
          array (
            'list_divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'divider_weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' =>
          array (
            'size' => 2,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'list_divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'divider_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'list_divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'divider_gap',
          'type' => 'slider',
          'label' => 'Gap',
          'default' =>
          array (
            'size' => 15,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'list_divider' => 'yes',
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
          'name' => 'features_list_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__features-list li',
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
      'id' => 'section_footer_style',
      'label' => 'Footer',
      'tab' => 'style',
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'footer_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'footer_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'heading_footer_button',
          'type' => 'heading',
          'label' => 'Button',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'button_size',
          'type' => 'select',
          'label' => 'Size',
          'default' => 'md',
          'options' =>
          array (
            'xs' => 'Extra Small',
            'sm' => 'Small',
            'md' => 'Medium',
            'lg' => 'Large',
            'xl' => 'Extra Large',
          ),
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'button_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'button_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'button_text_padding',
          'type' => 'dimensions',
          'label' => 'Text Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'button_hover_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'button_hover_animation',
          'type' => 'hover_animation',
          'label' => 'Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'heading_additional_info',
          'type' => 'heading',
          'label' => 'Additional Info',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'button_text',
                'operator' => '!==',
                'value' => '',
              ),
              1 =>
              array (
                'name' => 'footer_additional_info',
                'operator' => '!==',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'additional_info_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'button_text',
                'operator' => '!==',
                'value' => '',
              ),
              1 =>
              array (
                'name' => 'footer_additional_info',
                'operator' => '!==',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'additional_info_margin',
          'type' => 'dimensions',
          'label' => 'Margin',
          'default' =>
          array (
            'top' => 15,
            'right' => 30,
            'bottom' => 0,
            'left' => 30,
            'unit' => 'px',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'button_text',
                'operator' => '!==',
                'value' => '',
              ),
              1 =>
              array (
                'name' => 'footer_additional_info',
                'operator' => '!==',
                'value' => '',
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
        0 =>
        array (
          'group' => 'typography',
          'name' => 'button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__button',
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' => 'button_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__button',
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'border',
          'name' => 'button_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__button',
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'background',
          'name' => 'button_background_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__button:hover',
          'condition' =>
          array (
            'button_text!' => '',
          ),
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'typography',
          'name' => 'additional_info_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-table__additional_info',
          'condition' =>
          array (
            'relation' => 'and',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'button_text',
                'operator' => '!==',
                'value' => '',
              ),
              1 =>
              array (
                'name' => 'footer_additional_info',
                'operator' => '!==',
                'value' => '',
              ),
            ),
          ),
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
      'id' => 'section_ribbon_style',
      'label' => 'Ribbon',
      'tab' => 'style',
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'ribbon_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_ribbon' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'ribbon_distance',
          'type' => 'slider',
          'label' => 'Distance',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_ribbon' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'ribbon_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '#ffffff',
          'options' => NULL,
          'condition' =>
          array (
            'show_ribbon' => 'yes',
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
          'name' => 'ribbon_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-ribbon-inner',
          'condition' =>
          array (
            'show_ribbon' => 'yes',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'box-shadow',
          'name' => 'box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-ribbon-inner',
          'condition' =>
          array (
            'show_ribbon' => 'yes',
          ),
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
      'selector' => '{{WRAPPER}} .elementor-price-table__heading',
      'condition' =>
      array (
        'heading!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'sub_heading_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__subheading',
      'condition' =>
      array (
        'sub_heading!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'price_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table .elementor-price-table__price',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'original_price_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__original-price',
      'condition' =>
      array (
        'sale' => 'yes',
        'original_price!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'typography',
      'name' => 'period_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__period',
      'condition' =>
      array (
        'period!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'features_list_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__features-list li',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__button',
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'background',
      'name' => 'button_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__button',
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'border',
      'name' => 'button_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__button',
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'background',
      'name' => 'button_background_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__button:hover',
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'typography',
      'name' => 'additional_info_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-table__additional_info',
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'button_text',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'footer_additional_info',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'typography',
      'name' => 'ribbon_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-ribbon-inner',
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'box-shadow',
      'name' => 'box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-ribbon-inner',
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
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
          'key' => 'item_text',
          'type' => 'text',
          'label' => 'Text',
          'default' => 'List Item',
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
          'key' => 'selected_item_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            '__unresolved__' => '$default_icon',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'item_icon_color',
          'type' => 'color',
          'label' => 'Icon Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
    ),
  ),
  'settings_index' =>
  array (
    'heading' =>
    array (
      'section' => 'section_header',
      'type' => 'text',
      'default' => 'Enter your title',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sub_heading' =>
    array (
      'section' => 'section_header',
      'type' => 'text',
      'default' => 'Enter your description',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_tag' =>
    array (
      'section' => 'section_header',
      'type' => 'select',
      'default' => 'h3',
      'responsive' => false,
      'condition' =>
      array (
        'heading!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'currency_symbol' =>
    array (
      'section' => 'section_pricing',
      'type' => 'select',
      'default' => 'dollar',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'currency_symbol_custom' =>
    array (
      'section' => 'section_pricing',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'currency_symbol' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'price' =>
    array (
      'section' => 'section_pricing',
      'type' => 'text',
      'default' => '39.99',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'currency_format' =>
    array (
      'section' => 'section_pricing',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sale' =>
    array (
      'section' => 'section_pricing',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'original_price' =>
    array (
      'section' => 'section_pricing',
      'type' => 'number',
      'default' => '59',
      'responsive' => false,
      'condition' =>
      array (
        'sale' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'period' =>
    array (
      'section' => 'section_pricing',
      'type' => 'text',
      'default' => 'Monthly',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'features_list' =>
    array (
      'section' => 'section_features',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'item_text' => 'List Item #1',
          'selected_item_icon' =>
          array (
            '__unresolved__' => '$default_icon',
          ),
        ),
        1 =>
        array (
          'item_text' => 'List Item #2',
          'selected_item_icon' =>
          array (
            '__unresolved__' => '$default_icon',
          ),
        ),
        2 =>
        array (
          'item_text' => 'List Item #3',
          'selected_item_icon' =>
          array (
            '__unresolved__' => '$default_icon',
          ),
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text' =>
    array (
      'section' => 'section_footer',
      'type' => 'text',
      'default' => 'Click Here',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link' =>
    array (
      'section' => 'section_footer',
      'type' => 'url',
      'default' =>
      array (
        'url' => '#',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'footer_additional_info' =>
    array (
      'section' => 'section_footer',
      'type' => 'textarea',
      'default' => 'This is text element',
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_ribbon' =>
    array (
      'section' => 'section_ribbon',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_title' =>
    array (
      'section' => 'section_ribbon',
      'type' => 'text',
      'default' => 'Popular',
      'responsive' => false,
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_horizontal_position' =>
    array (
      'section' => 'section_ribbon',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'header_bg_color' =>
    array (
      'section' => 'section_header_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'heading',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'sub_heading',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'header_padding' =>
    array (
      'section' => 'section_header_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'heading',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'sub_heading',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_heading_style' =>
    array (
      'section' => 'section_header_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'heading!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_color' =>
    array (
      'section' => 'section_header_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'heading!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_sub_heading_style' =>
    array (
      'section' => 'section_header_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'sub_heading!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'sub_heading_color' =>
    array (
      'section' => 'section_header_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'sub_heading!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_typography_typography' =>
    array (
      'section' => 'section_header_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'heading!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'heading_typography',
    ),
    'sub_heading_typography_typography' =>
    array (
      'section' => 'section_header_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'sub_heading!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'sub_heading_typography',
    ),
    'pricing_element_bg_color' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pricing_element_padding' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'price_color' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_currency_style' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'currency_symbol!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'currency_size' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'currency_symbol!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'currency_position' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'choose',
      'default' => 'before',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'currency_vertical_position' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'choose',
      'default' => 'top',
      'responsive' => false,
      'condition' =>
      array (
        'currency_symbol!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'fractional_part_style' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'fractional-part_size' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'fractional_part_vertical_position' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'choose',
      'default' => 'top',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_original_price_style' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'sale' => 'yes',
        'original_price!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'original_price_color' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'sale' => 'yes',
        'original_price!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'original_price_vertical_position' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'choose',
      'default' => 'bottom',
      'responsive' => false,
      'condition' =>
      array (
        'sale' => 'yes',
        'original_price!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_period_style' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'period!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'period_color' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'period!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'period_position' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'select',
      'default' => 'below',
      'responsive' => false,
      'condition' =>
      array (
        'period!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'price_typography_typography' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'price_typography',
    ),
    'original_price_typography_typography' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'sale' => 'yes',
        'original_price!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'original_price_typography',
    ),
    'period_typography_typography' =>
    array (
      'section' => 'section_pricing_element_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'period!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'period_typography',
    ),
    'features_list_bg_color' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'features_list_padding' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'features_list_color' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'features_list_alignment' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_width' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'list_divider' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_style' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'select',
      'default' => 'solid',
      'responsive' => false,
      'condition' =>
      array (
        'list_divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_color' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'color',
      'default' => '#ddd',
      'responsive' => false,
      'condition' =>
      array (
        'list_divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_weight' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 2,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'list_divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_width' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'list_divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_gap' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 15,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'list_divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'features_list_typography_typography' =>
    array (
      'section' => 'section_features_list_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'features_list_typography',
    ),
    'footer_bg_color' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'footer_padding' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_footer_button' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_size' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'select',
      'default' => 'md',
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_radius' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_padding' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_color' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_border_color' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_animation' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_additional_info' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'button_text',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'footer_additional_info',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_info_color' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'button_text',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'footer_additional_info',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'additional_info_margin' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'dimensions',
      'default' =>
      array (
        'top' => 15,
        'right' => 30,
        'bottom' => 0,
        'left' => 30,
        'unit' => 'px',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'button_text',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'footer_additional_info',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_typography_typography' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'button_typography',
    ),
    'button_background_background' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => 'background',
      'group_prefix' => 'button_background',
    ),
    'button_border_border' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => 'border',
      'group_prefix' => 'button_border',
    ),
    'button_background_hover_background' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_text!' => '',
      ),
      'group' => 'background',
      'group_prefix' => 'button_background_hover',
    ),
    'additional_info_typography_typography' =>
    array (
      'section' => 'section_footer_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'and',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'button_text',
            'operator' => '!==',
            'value' => '',
          ),
          1 =>
          array (
            'name' => 'footer_additional_info',
            'operator' => '!==',
            'value' => '',
          ),
        ),
      ),
      'group' => 'typography',
      'group_prefix' => 'additional_info_typography',
    ),
    'ribbon_bg_color' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_distance' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_text_color' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'color',
      'default' => '#ffffff',
      'responsive' => false,
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'ribbon_typography_typography' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'group' => 'typography',
      'group_prefix' => 'ribbon_typography',
    ),
    'box_shadow_box_shadow' =>
    array (
      'section' => 'section_ribbon_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_ribbon' => 'yes',
      ),
      'group' => 'box-shadow',
      'group_prefix' => 'box_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'heading_typography_typography' => 'custom',
    'sub_heading_typography_typography' => 'custom',
    'price_typography_typography' => 'custom',
    'original_price_typography_typography' => 'custom',
    'period_typography_typography' => 'custom',
    'features_list_typography_typography' => 'custom',
    'button_typography_typography' => 'custom',
    'button_background_background' => 'classic',
    'button_border_border' => 'solid',
    'button_background_hover_background' => 'classic',
    'additional_info_typography_typography' => 'custom',
    'ribbon_typography_typography' => 'custom',
    'box_shadow_box_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'features_list',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/price-table-pro.md',
  ),
  'control_count' => 79,
);
