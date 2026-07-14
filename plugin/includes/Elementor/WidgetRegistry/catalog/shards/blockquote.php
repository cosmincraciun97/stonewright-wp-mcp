<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'blockquote',
  'source' => 'pro',
  'widget_type' => 'blockquote',
  'title' => 'Blockquote',
  'icon' => 'eicon-blockquote',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'blockquote',
    1 => 'quote',
    2 => 'paragraph',
    3 => 'testimonial',
    4 => 'text',
    5 => 'twitter',
    6 => 'tweet',
  ),
  'file' => 'pro-elements/modules/blockquote/widgets/blockquote.php',
  'intent' => 'Choose the Blockquote skin, selecting either Border, Quotation, Boxed, or Clean. Alignment',
  'use_cases' =>
  array (
    0 => 'In Elementor Editor, click +',
    1 => 'All available widgets are displayed',
    2 => 'Click or drag the widget to the canvas',
    3 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Add a Blockquote widget – Step-by-step',
    1 => 'Set the position of the blockquote – to the left centered to the right',
    2 => 'Text Color – Choose the color of the author’s name.Typography: Set the font style for the author’s name. For more details, see Typography.',
    3 => 'Content options – Configure general content, title, tags, and icons.',
    4 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    5 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
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
      'id' => 'section_blockquote_content',
      'label' => 'Blockquote',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'blockquote_skin',
          'type' => 'select',
          'label' => 'Skin',
          'default' => 'border',
          'options' =>
          array (
            'border' => 'Border',
            'quotation' => 'Quotation',
            'boxed' => 'Boxed',
            'clean' => 'Clean',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'blockquote_content',
          'type' => 'textarea',
          'label' => 'Content',
          'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
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
          'key' => 'author_name',
          'type' => 'text',
          'label' => 'Author',
          'default' => 'John Doe',
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
          'key' => 'tweet_button',
          'type' => 'switcher',
          'label' => 'Tweet Button',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'tweet_button_view',
          'type' => 'select',
          'label' => 'View',
          'default' => 'icon-text',
          'options' =>
          array (
            'icon-text' => 'Icon & Text',
            'icon' => 'Icon',
            'text' => 'Text',
          ),
          'condition' =>
          array (
            'tweet_button' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'tweet_button_skin',
          'type' => 'select',
          'label' => 'Skin',
          'default' => 'classic',
          'options' =>
          array (
            'classic' => 'Classic',
            'bubble' => 'Bubble',
            'link' => 'Link',
          ),
          'condition' =>
          array (
            'tweet_button' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'tweet_button_label',
          'type' => 'text',
          'label' => 'Label',
          'default' => 'Tweet',
          'options' => NULL,
          'condition' =>
          array (
            'tweet_button' => 'yes',
            'tweet_button_view!' => 'icon',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'user_name',
          'type' => 'text',
          'label' => 'Username',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tweet_button' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'url_type',
          'type' => 'select',
          'label' => 'Target URL',
          'default' => 'current_page',
          'options' =>
          array (
            'current_page' => 'Current Page',
            'none' => 'None',
            'custom' => 'Custom',
          ),
          'condition' =>
          array (
            'tweet_button' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'url',
          'type' => 'text',
          'label' => 'Link',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tweet_button' => 'yes',
            'url_type' => 'custom',
          ),
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::POST_META_CATEGORY',
              ),
              1 =>
              array (
                '__unresolved__' => 'TagsModule::URL_CATEGORY',
              ),
            ),
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
    1 =>
    array (
      'id' => 'section_content_style',
      'label' => 'Content',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
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
        1 =>
        array (
          'key' => 'heading_content_style',
          'type' => 'heading',
          'label' => 'Content',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'content_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'content_gap',
          'type' => 'slider',
          'label' => 'Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'heading_author_style',
          'type' => 'heading',
          'label' => 'Author',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'author_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'author_gap',
          'type' => 'slider',
          'label' => 'Gap',
          'default' =>
          array (
            'size' => 20,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'alignment' => 'center',
            'tweet_button' => 'yes',
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
          'name' => 'content_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-blockquote__content',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'author_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-blockquote__author',
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
      'id' => 'section_button_style',
      'label' => 'Button',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'button_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'button_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'button_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tweet_button_skin!' => 'link',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
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
        4 =>
        array (
          'key' => 'button_background_color_hover',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tweet_button_skin!' => 'link',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
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
        6 =>
        array (
          'key' => 'button_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 'ms',
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
          'name' => 'button_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-blockquote__tweet-button span, {{WRAPPER}} .elementor-blockquote__tweet-button i',
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
      'id' => 'section_border_style',
      'label' => 'Border',
      'tab' => 'style',
      'condition' =>
      array (
        'blockquote_skin' => 'border',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'border_color',
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
          'key' => 'border_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'border_gap',
          'type' => 'slider',
          'label' => 'Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'border_color_hover',
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
          'key' => 'border_width_hover',
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
          'key' => 'border_gap_hover',
          'type' => 'slider',
          'label' => 'Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'border_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 'ms',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'border_vertical_padding',
          'type' => 'slider',
          'label' => 'Vertical Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'blockquote_skin' => 'border',
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
    4 =>
    array (
      'id' => 'section_box_style',
      'label' => 'Box',
      'tab' => 'style',
      'condition' =>
      array (
        'blockquote_skin!' => 'border',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'box_padding',
          'type' => 'slider',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'box_background_color',
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
          'key' => 'box_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'box_background_color_hover',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'box_border_radius_hover',
          'type' => 'slider',
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
          'key' => 'box_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 'ms',
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
          'group' => 'border',
          'name' => 'box_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-blockquote',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'box-shadow',
          'name' => 'box_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-blockquote',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'box_shadow_position',
          ),
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'border',
          'name' => 'box_border_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-blockquote:hover',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' => 'box_box_shadow_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-blockquote:hover',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'box_shadow_position',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    5 =>
    array (
      'id' => 'section_quote_style',
      'label' => 'Quote',
      'tab' => 'style',
      'condition' =>
      array (
        'blockquote_skin' => 'quotation',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'quote_text_color',
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
          'key' => 'quote_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' =>
          array (
            'size' => 1,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'quote_gap',
          'type' => 'slider',
          'label' => 'Gap',
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
  ),
  'group_controls' =>
  array (
    0 =>
    array (
      'group' => 'typography',
      'name' => 'content_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-blockquote__content',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'author_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-blockquote__author',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-blockquote__tweet-button span, {{WRAPPER}} .elementor-blockquote__tweet-button i',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'border',
      'name' => 'box_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-blockquote',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'box-shadow',
      'name' => 'box_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-blockquote',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'box_shadow_position',
      ),
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'border',
      'name' => 'box_border_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-blockquote:hover',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'box-shadow',
      'name' => 'box_box_shadow_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-blockquote:hover',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'box_shadow_position',
      ),
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'blockquote_skin' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'select',
      'default' => 'border',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'blockquote_content' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'textarea',
      'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'author_name' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'text',
      'default' => 'John Doe',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tweet_button' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tweet_button_view' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'select',
      'default' => 'icon-text',
      'responsive' => false,
      'condition' =>
      array (
        'tweet_button' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tweet_button_skin' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'select',
      'default' => 'classic',
      'responsive' => false,
      'condition' =>
      array (
        'tweet_button' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tweet_button_label' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'text',
      'default' => 'Tweet',
      'responsive' => false,
      'condition' =>
      array (
        'tweet_button' => 'yes',
        'tweet_button_view!' => 'icon',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'user_name' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tweet_button' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'url_type' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'select',
      'default' => 'current_page',
      'responsive' => false,
      'condition' =>
      array (
        'tweet_button' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'url' =>
    array (
      'section' => 'section_blockquote_content',
      'type' => 'text',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tweet_button' => 'yes',
        'url_type' => 'custom',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alignment' =>
    array (
      'section' => 'section_content_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_content_style' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_text_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_gap' =>
    array (
      'section' => 'section_content_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_author_style' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'author_text_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'author_gap' =>
    array (
      'section' => 'section_content_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 20,
      ),
      'responsive' => true,
      'condition' =>
      array (
        'alignment' => 'center',
        'tweet_button' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'content_typography',
    ),
    'author_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'author_typography',
    ),
    'button_size' =>
    array (
      'section' => 'section_button_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_radius' =>
    array (
      'section' => 'section_button_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_background_color' =>
    array (
      'section' => 'section_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tweet_button_skin!' => 'link',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color' =>
    array (
      'section' => 'section_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_background_color_hover' =>
    array (
      'section' => 'section_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tweet_button_skin!' => 'link',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color_hover' =>
    array (
      'section' => 'section_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_transition_duration' =>
    array (
      'section' => 'section_button_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'ms',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_typography_typography' =>
    array (
      'section' => 'section_button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'button_typography',
    ),
    'border_color' =>
    array (
      'section' => 'section_border_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_width' =>
    array (
      'section' => 'section_border_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_gap' =>
    array (
      'section' => 'section_border_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_color_hover' =>
    array (
      'section' => 'section_border_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_width_hover' =>
    array (
      'section' => 'section_border_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_gap_hover' =>
    array (
      'section' => 'section_border_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_transition_duration' =>
    array (
      'section' => 'section_border_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'ms',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_vertical_padding' =>
    array (
      'section' => 'section_border_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'blockquote_skin' => 'border',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_padding' =>
    array (
      'section' => 'section_box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_background_color' =>
    array (
      'section' => 'section_box_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_border_radius' =>
    array (
      'section' => 'section_box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_background_color_hover' =>
    array (
      'section' => 'section_box_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_border_radius_hover' =>
    array (
      'section' => 'section_box_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_transition_duration' =>
    array (
      'section' => 'section_box_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 'ms',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_border_border' =>
    array (
      'section' => 'section_box_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'box_border',
    ),
    'box_box_shadow_box_shadow' =>
    array (
      'section' => 'section_box_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'box_box_shadow',
    ),
    'box_border_hover_border' =>
    array (
      'section' => 'section_box_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'box_border_hover',
    ),
    'box_box_shadow_hover_box_shadow' =>
    array (
      'section' => 'section_box_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'box_box_shadow_hover',
    ),
    'quote_text_color' =>
    array (
      'section' => 'section_quote_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quote_size' =>
    array (
      'section' => 'section_quote_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'quote_gap' =>
    array (
      'section' => 'section_quote_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'content_typography_typography' => 'custom',
    'author_typography_typography' => 'custom',
    'button_typography_typography' => 'custom',
    'box_border_border' => 'solid',
    'box_box_shadow_box_shadow' => 'yes',
    'box_border_hover_border' => 'solid',
    'box_box_shadow_hover_box_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/blockquote-widget-pro.md',
    1 => 'docs/knowledge/elementor/widgets/blockquote-widget-pro.md',
  ),
  'control_count' => 48,
);
