<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'price-list',
  'source' => 'pro',
  'widget_type' => 'price-list',
  'title' => 'Price List',
  'icon' => 'eicon-price-list',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'pricing',
    1 => 'list',
    2 => 'product',
    3 => 'image',
    4 => 'menu',
  ),
  'file' => 'pro-elements/modules/pricing/widgets/price-list.php',
  'intent' => 'The Price List widget lets you create fully styled catalogs and menus that include pricing.',
  'use_cases' =>
  array (
    0 => 'This helps search engines find and understand the price list, boosting SEO',
    1 => 'The title can also be tagged as a paragraph, span or div',
    2 => 'This helps search engines find and understand the description, boosting SEO',
    3 => 'The description can also be tagged as a paragraph, span or div',
  ),
  'settings_highlights' =>
  array (
    0 => 'Tip – Quickly duplicate or delete items by clicking an item’s Duplicate or Delete icon',
    1 => 'Price – Add the price of the item',
    2 => 'Title & Description – Set the title and description of the item',
    3 => 'Title HTML Tag – Use the dropdown menu to designate the title of the price list as a header (H1-6).This helps search engines find and understand the price list, boosting SEO. The title can also be tagged as a paragraph, span or div',
    4 => 'Description HTML Tag – Use the dropdown menu to designate the description of the price list as a header (H1-6).This helps search engines find and understand the description, boosting SEO. The description can also be tagged as a paragraph, span or div',
    5 => 'Image – Choose an image from the media library',
    6 => 'Link – Enter the URL for the item’s link. Click the Link Options cog to either add rel=nofollow to the link or to open the link in a new window',
    7 => 'Color – Choose a color for the title text',
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
      'id' => 'section_list',
      'label' => 'List',
      'tab' => 'content',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'price_list',
          'type' => 'repeater',
          'label' => 'List Items',
          'default' =>
          array (
            0 =>
            array (
              'title' => 'First item on the list',
              'item_description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
              'price' => '$20',
              'link' =>
              array (
                'url' => '#',
              ),
            ),
            1 =>
            array (
              'title' => 'Second item on the list',
              'item_description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
              'price' => '$9',
              'link' =>
              array (
                'url' => '#',
              ),
            ),
            2 =>
            array (
              'title' => 'Third item on the list',
              'item_description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
              'price' => '$32',
              'link' =>
              array (
                'url' => '#',
              ),
            ),
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'title_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
          'default' => 'span',
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
            'span' => 'span',
            'p' => 'p',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'description_tag',
          'type' => 'select',
          'label' => 'Description HTML Tag',
          'default' => 'p',
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
            'span' => 'span',
            'p' => 'p',
          ),
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
          'group' => 'image-size',
          'name' => 'image_size',
          'label' => NULL,
          'selector' => NULL,
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
      'id' => 'section_list_style',
      'label' => 'List',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'vertical_align',
          'type' => 'choose',
          'label' => 'Vertical Align',
          'default' => 'top',
          'options' =>
          array (
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'center' =>
            array (
              'title' => 'Center',
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
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'row_gap',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' =>
          array (
            'size' => 20,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'image_spacing',
          'type' => 'slider',
          'label' => 'Image Spacing',
          'default' =>
          array (
            'size' => 20,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'content_spacing',
          'type' => 'slider',
          'label' => 'Content Spacing',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    2 =>
    array (
      'id' => 'section_content_style',
      'label' => 'Content',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading__title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'heading_color',
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
          'key' => 'price_title',
          'type' => 'heading',
          'label' => 'Price',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
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
        4 =>
        array (
          'key' => 'heading_item_description',
          'type' => 'heading',
          'label' => 'Description',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'description_color',
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
          'key' => 'heading_separator',
          'type' => 'heading',
          'label' => 'Separator',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'separator_style',
          'type' => 'select',
          'label' => 'Style',
          'default' => 'dotted',
          'options' =>
          array (
            'none' => 'None',
            'solid' => 'Solid',
            'dotted' => 'Dotted',
            'dashed' => 'Dashed',
            'double' => 'Double',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'separator_weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' =>
          array (
            'size' => 2,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'separator_style!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'separator_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'separator_style!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'separator_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'separator_style!' => 'none',
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
          'selector' => '{{WRAPPER}} .elementor-price-list-header',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-list-header',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'price_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-list-price',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'typography',
          'name' => 'description_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-price-list-description',
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
      'id' => 'section_image_style',
      'label' => 'Image',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'border_radius',
          'type' => 'dimensions',
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
      'group' => 'image-size',
      'name' => 'image_size',
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'heading_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-list-header',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-list-header',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'price_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-list-price',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'typography',
      'name' => 'description_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-price-list-description',
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
          'key' => 'price',
          'type' => 'text',
          'label' => 'Price',
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
          'key' => 'title',
          'type' => 'text',
          'label' => 'Title',
          'default' => '',
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
          'key' => 'item_description',
          'type' => 'textarea',
          'label' => 'Description',
          'default' => '',
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
          'key' => 'image',
          'type' => 'media',
          'label' => 'Image',
          'default' =>
          array (
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
        4 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
          'default' =>
          array (
            'url' => '#',
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
      ),
    ),
  ),
  'settings_index' =>
  array (
    'price_list' =>
    array (
      'section' => 'section_list',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'title' => 'First item on the list',
          'item_description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
          'price' => '$20',
          'link' =>
          array (
            'url' => '#',
          ),
        ),
        1 =>
        array (
          'title' => 'Second item on the list',
          'item_description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
          'price' => '$9',
          'link' =>
          array (
            'url' => '#',
          ),
        ),
        2 =>
        array (
          'title' => 'Third item on the list',
          'item_description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
          'price' => '$32',
          'link' =>
          array (
            'url' => '#',
          ),
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_tag' =>
    array (
      'section' => 'section_list',
      'type' => 'select',
      'default' => 'span',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_tag' =>
    array (
      'section' => 'section_list',
      'type' => 'select',
      'default' => 'p',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_size_image_size' =>
    array (
      'section' => 'section_list',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'image-size',
      'group_prefix' => 'image_size',
    ),
    'vertical_align' =>
    array (
      'section' => 'section_list_style',
      'type' => 'choose',
      'default' => 'top',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'row_gap' =>
    array (
      'section' => 'section_list_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 20,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'image_spacing' =>
    array (
      'section' => 'section_list_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 20,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_spacing' =>
    array (
      'section' => 'section_list_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading__title' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'price_title' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'price_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_item_description' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_separator' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'separator_style' =>
    array (
      'section' => 'section_content_style',
      'type' => 'select',
      'default' => 'dotted',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'separator_weight' =>
    array (
      'section' => 'section_content_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 2,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'separator_style!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'separator_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'separator_style!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'separator_spacing' =>
    array (
      'section' => 'section_content_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'separator_style!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'heading_typography',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'price_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'price_typography',
    ),
    'description_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'description_typography',
    ),
    'border_radius' =>
    array (
      'section' => 'section_image_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'image_size_image_size' => 'custom',
    'heading_typography_typography' => 'custom',
    'text_stroke_text_stroke' => 'yes',
    'price_typography_typography' => 'custom',
    'description_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
    0 => 'price_list',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/price-list-widget-pro.md',
    1 => 'docs/knowledge/elementor/widgets/price-list-widget-pro.md',
  ),
  'control_count' => 24,
);
