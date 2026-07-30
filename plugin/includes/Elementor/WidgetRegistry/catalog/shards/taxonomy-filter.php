<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'taxonomy-filter',
  'source' => 'pro',
  'widget_type' => 'taxonomy-filter',
  'title' => 'Taxonomy Filter',
  'icon' => 'eicon-taxonomy-filter',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'filter',
    1 => 'loop',
    2 => 'filter bar',
    3 => 'taxonomy',
    4 => 'categories',
    5 => 'tags',
  ),
  'file' => 'pro-elements/modules/loop-filter/widgets/taxonomy-filter.php',
  'intent' => 'The Taxonomy widget gives you a powerful way to control the items appearing in a Loop Grid, filtering them by categories and tags. This increases website usability and visitor engagement.',
  'use_cases' =>
  array (
    0 => 'The Taxonomy widget can only be used as part of a Loop Grid',
    1 => 'Create a Taxonomy filter',
    2 => 'For more details, see Build a Loop Grid',
    3 => 'Use the slider to set the distance between the text in the taxonomy menu',
  ),
  'settings_highlights' =>
  array (
    0 => 'Center – Appears in the middle of the Loop Grid',
    1 => 'Disable – This forces items to wrap to the next line.',
    2 => 'Enable – Visitors will need to scroll horizontally to see all items.',
    3 => 'Content options – Configure general content, title, tags, and icons.',
    4 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    5 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Limit the number of taxonomy items shown in the menu by entering a number in the text box. For example, entering 4 will display 4 out of the 25 items.',
    1 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    2 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_taxonomy_filter',
      'label' => 'Layout',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'selected_element',
          'type' => 'select',
          'label' => 'Selected loop grid',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Select a widget',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'taxonomy',
          'type' => 'select',
          'label' => 'Taxonomy',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Select a taxonomy',
          ),
          'condition' =>
          array (
            'selected_element!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'direction',
          'type' => 'select',
          'label' => 'Direction',
          'default' => 'horizontal',
          'options' =>
          array (
            'horizontal' => 'Horizontal',
            'vertical' => 'Vertical',
          ),
          'condition' =>
          array (
            'selected_element!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'item_alignment_horizontal',
          'type' => 'choose',
          'label' => 'Item Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-align-start-h',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-align-center-h',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-align-end-h',
            ),
            'stretch' =>
            array (
              'title' => 'Stretch',
              'icon' => 'eicon-align-stretch-h',
            ),
          ),
          'condition' =>
          array (
            'direction' => 'horizontal',
            'selected_element!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'title_alignment_horizontal',
          'type' => 'choose',
          'label' => 'Title Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-align-start-h',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-align-center-h',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-align-end-h',
            ),
          ),
          'condition' =>
          array (
            'direction' => 'horizontal',
            'selected_element!' => '',
            'item_alignment_horizontal' => 'stretch',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'item_alignment_vertical',
          'type' => 'choose',
          'label' => 'Item Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-align-start-h',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-align-center-h',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-align-end-h',
            ),
            'stretch' =>
            array (
              'title' => 'Stretch',
              'icon' => 'eicon-align-stretch-h',
            ),
          ),
          'condition' =>
          array (
            'direction' => 'vertical',
            'selected_element!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'title_alignment_vertical',
          'type' => 'choose',
          'label' => 'Title Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-align-start-h',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-align-center-h',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-align-end-h',
            ),
          ),
          'condition' =>
          array (
            'direction' => 'vertical',
            'selected_element!' => '',
            'item_alignment_vertical' => 'stretch',
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
      'id' => 'section_settings',
      'label' => 'Settings',
      'tab' => NULL,
      'condition' =>
      array (
        'selected_element!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_filter_logic',
          'type' => 'heading',
          'label' => 'Filter Logic',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'multiple_selection',
          'type' => 'switcher',
          'label' => 'Multiple Selection',
          'default' => 'no',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'logical_combination',
          'type' => 'select',
          'label' => 'Logical Combination',
          'default' => 'AND',
          'options' =>
          array (
            'AND' => 'AND',
            'OR' => 'OR',
          ),
          'condition' =>
          array (
            'multiple_selection' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'heading_displayed_elements',
          'type' => 'heading',
          'label' => 'Displayed Elements',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'show_empty_items',
          'type' => 'switcher',
          'label' => 'Empty Items',
          'default' => 'no',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'show_child_taxonomy',
          'type' => 'switcher',
          'label' => 'Taxonomy Children',
          'default' => 'no',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'child_taxonomy_depth',
          'type' => 'select',
          'label' => 'Depth',
          'default' => '1',
          'options' =>
          array (
            1 => '1',
            2 => '2',
            3 => '3',
            4 => '4',
            5 => '5',
            6 => '6',
          ),
          'condition' =>
          array (
            'show_child_taxonomy' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'show_first_item',
          'type' => 'switcher',
          'label' => 'First Item',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'first_item_title',
          'type' => 'text',
          'label' => 'First Item Title',
          'default' => 'All',
          'options' => NULL,
          'condition' =>
          array (
            'show_first_item' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'number_of_taxonomies',
          'type' => 'number',
          'label' => 'Number of taxonomies',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'horizontal_scroll',
          'type' => 'select',
          'label' => 'Horizontal Scroll',
          'default' => 'disable',
          'options' =>
          array (
            'disable' => 'Disable',
            'enable' => 'Enable',
          ),
          'condition' =>
          array (
            'direction' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => 'Scroll items if they don’t fit into their parent container',
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
      'id' => 'section_design_layout',
      'label' => 'Items',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'taxonomy_filter_items_space_between',
          'type' => 'slider',
          'label' => 'Space between Items',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'taxonomy_filter_normal_text_color',
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
          'key' => 'taxonomy_filter_hover_text_color',
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
          'key' => 'taxonomy_filter_active_text_color',
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
          'key' => 'taxonomy_filter_border_radius',
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
          'key' => 'taxonomy_filter_padding',
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
          'name' => 'taxonomy_filter_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'taxonomy_filter_normal_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item:not( [aria-pressed=true] ):not( :hover )',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'background',
          'name' => 'taxonomy_filter_normal_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item:not( [aria-pressed=true] ):not( :hover )',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'image',
            1 => 'video',
          ),
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'border',
          'name' => 'taxonomy_filter_normal_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item:not( [aria-pressed=true] ):not( :hover )',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'box-shadow',
          'name' => 'taxonomy_filter_normal_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item:not( [aria-pressed=true] ):not( :hover )',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        5 =>
        array (
          'group' => 'text-shadow',
          'name' => 'taxonomy_filter_hover_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item:hover:not( [aria-pressed=true] )',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        6 =>
        array (
          'group' => 'background',
          'name' => 'taxonomy_filter_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item:hover:not( [aria-pressed=true] )',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'image',
            1 => 'video',
          ),
          'include' => NULL,
        ),
        7 =>
        array (
          'group' => 'border',
          'name' => 'taxonomy_filter_hover_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item:hover:not( [aria-pressed=true] )',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        8 =>
        array (
          'group' => 'box-shadow',
          'name' => 'taxonomy_filter_hover_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item:hover:not( [aria-pressed=true] )',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        9 =>
        array (
          'group' => 'text-shadow',
          'name' => 'taxonomy_filter_active_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item[aria-pressed="true"]',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        10 =>
        array (
          'group' => 'background',
          'name' => 'taxonomy_filter_active_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item[aria-pressed="true"]',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'image',
            1 => 'video',
          ),
          'include' => NULL,
        ),
        11 =>
        array (
          'group' => 'border',
          'name' => 'taxonomy_filter_active_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item[aria-pressed="true"]',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        12 =>
        array (
          'group' => 'box-shadow',
          'name' => 'taxonomy_filter_active_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-filter-item[aria-pressed="true"]',
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
      'name' => 'taxonomy_filter_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-shadow',
      'name' => 'taxonomy_filter_normal_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item:not( [aria-pressed=true] ):not( :hover )',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'background',
      'name' => 'taxonomy_filter_normal_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item:not( [aria-pressed=true] ):not( :hover )',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
        1 => 'video',
      ),
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'border',
      'name' => 'taxonomy_filter_normal_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item:not( [aria-pressed=true] ):not( :hover )',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'box-shadow',
      'name' => 'taxonomy_filter_normal_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item:not( [aria-pressed=true] ):not( :hover )',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'text-shadow',
      'name' => 'taxonomy_filter_hover_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item:hover:not( [aria-pressed=true] )',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'background',
      'name' => 'taxonomy_filter_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item:hover:not( [aria-pressed=true] )',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
        1 => 'video',
      ),
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'border',
      'name' => 'taxonomy_filter_hover_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item:hover:not( [aria-pressed=true] )',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'box-shadow',
      'name' => 'taxonomy_filter_hover_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item:hover:not( [aria-pressed=true] )',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'text-shadow',
      'name' => 'taxonomy_filter_active_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item[aria-pressed="true"]',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'background',
      'name' => 'taxonomy_filter_active_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item[aria-pressed="true"]',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
        1 => 'video',
      ),
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'border',
      'name' => 'taxonomy_filter_active_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item[aria-pressed="true"]',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'box-shadow',
      'name' => 'taxonomy_filter_active_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-filter-item[aria-pressed="true"]',
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
    'selected_element' =>
    array (
      'section' => 'section_taxonomy_filter',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy' =>
    array (
      'section' => 'section_taxonomy_filter',
      'type' => 'select',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'selected_element!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'direction' =>
    array (
      'section' => 'section_taxonomy_filter',
      'type' => 'select',
      'default' => 'horizontal',
      'responsive' => true,
      'condition' =>
      array (
        'selected_element!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_alignment_horizontal' =>
    array (
      'section' => 'section_taxonomy_filter',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'direction' => 'horizontal',
        'selected_element!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_alignment_horizontal' =>
    array (
      'section' => 'section_taxonomy_filter',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'direction' => 'horizontal',
        'selected_element!' => '',
        'item_alignment_horizontal' => 'stretch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'item_alignment_vertical' =>
    array (
      'section' => 'section_taxonomy_filter',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'direction' => 'vertical',
        'selected_element!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_alignment_vertical' =>
    array (
      'section' => 'section_taxonomy_filter',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'direction' => 'vertical',
        'selected_element!' => '',
        'item_alignment_vertical' => 'stretch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_filter_logic' =>
    array (
      'section' => 'section_settings',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'multiple_selection' =>
    array (
      'section' => 'section_settings',
      'type' => 'switcher',
      'default' => 'no',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'logical_combination' =>
    array (
      'section' => 'section_settings',
      'type' => 'select',
      'default' => 'AND',
      'responsive' => false,
      'condition' =>
      array (
        'multiple_selection' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_displayed_elements' =>
    array (
      'section' => 'section_settings',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_empty_items' =>
    array (
      'section' => 'section_settings',
      'type' => 'switcher',
      'default' => 'no',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_child_taxonomy' =>
    array (
      'section' => 'section_settings',
      'type' => 'switcher',
      'default' => 'no',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'child_taxonomy_depth' =>
    array (
      'section' => 'section_settings',
      'type' => 'select',
      'default' => '1',
      'responsive' => false,
      'condition' =>
      array (
        'show_child_taxonomy' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_first_item' =>
    array (
      'section' => 'section_settings',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'first_item_title' =>
    array (
      'section' => 'section_settings',
      'type' => 'text',
      'default' => 'All',
      'responsive' => false,
      'condition' =>
      array (
        'show_first_item' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'number_of_taxonomies' =>
    array (
      'section' => 'section_settings',
      'type' => 'number',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'horizontal_scroll' =>
    array (
      'section' => 'section_settings',
      'type' => 'select',
      'default' => 'disable',
      'responsive' => true,
      'condition' =>
      array (
        'direction' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy_filter_items_space_between' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy_filter_normal_text_color' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy_filter_hover_text_color' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy_filter_active_text_color' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy_filter_border_radius' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy_filter_padding' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'taxonomy_filter_typography_typography' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'taxonomy_filter_typography',
    ),
    'taxonomy_filter_normal_text_shadow_text_shadow' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'taxonomy_filter_normal_text_shadow',
    ),
    'taxonomy_filter_normal_background_background' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'taxonomy_filter_normal_background',
    ),
    'taxonomy_filter_normal_border_border' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'taxonomy_filter_normal_border',
    ),
    'taxonomy_filter_normal_box_shadow_box_shadow' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'taxonomy_filter_normal_box_shadow',
    ),
    'taxonomy_filter_hover_text_shadow_text_shadow' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'taxonomy_filter_hover_text_shadow',
    ),
    'taxonomy_filter_hover_background_background' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'taxonomy_filter_hover_background',
    ),
    'taxonomy_filter_hover_border_border' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'taxonomy_filter_hover_border',
    ),
    'taxonomy_filter_hover_box_shadow_box_shadow' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'taxonomy_filter_hover_box_shadow',
    ),
    'taxonomy_filter_active_text_shadow_text_shadow' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'taxonomy_filter_active_text_shadow',
    ),
    'taxonomy_filter_active_background_background' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'taxonomy_filter_active_background',
    ),
    'taxonomy_filter_active_border_border' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'taxonomy_filter_active_border',
    ),
    'taxonomy_filter_active_box_shadow_box_shadow' =>
    array (
      'section' => 'section_design_layout',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'taxonomy_filter_active_box_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'taxonomy_filter_typography_typography' => 'custom',
    'taxonomy_filter_normal_text_shadow_text_shadow' => 'yes',
    'taxonomy_filter_normal_background_background' => 'classic',
    'taxonomy_filter_normal_border_border' => 'solid',
    'taxonomy_filter_normal_box_shadow_box_shadow' => 'yes',
    'taxonomy_filter_hover_text_shadow_text_shadow' => 'yes',
    'taxonomy_filter_hover_background_background' => 'classic',
    'taxonomy_filter_hover_border_border' => 'solid',
    'taxonomy_filter_hover_box_shadow_box_shadow' => 'yes',
    'taxonomy_filter_active_text_shadow_text_shadow' => 'yes',
    'taxonomy_filter_active_background_background' => 'classic',
    'taxonomy_filter_active_border_border' => 'solid',
    'taxonomy_filter_active_box_shadow_box_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/taxonomy-filter-widget.md',
    1 => 'docs/knowledge/elementor/widgets/taxonomy-filter-widget.md',
  ),
  'control_count' => 37,
);
