<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'icon-list',
  'source' => 'free',
  'widget_type' => 'icon-list',
  'title' => 'Icon List',
  'icon' => 'eicon-bullet-list',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'icon list',
    1 => 'icon',
    2 => 'list',
  ),
  'file' => 'elementor/includes/widgets/icon-list.php',
  'intent' => 'Choose Default or Inline. Default displays items in a vertical list, while Inline displays items horizontally. Items',
  'use_cases' =>
  array (
    0 => 'In Elementor Editor, click +',
    1 => 'All available widgets are displayed',
    2 => 'Click or drag the widget to the canvas',
    3 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Display product features on an e – commerce website.Highlight key services offered by a business.Showcase amenities at a hotel or resort.Present benefits of a subscription or membership.',
    1 => 'Add an Icon List widget – Step-by-step',
    2 => 'Content options – Configure general content, title, tags, and icons.',
    3 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    4 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
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
      'id' => 'section_icon',
      'label' => 'Icon List',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'view',
          'type' => 'choose',
          'label' => 'Layout',
          'default' => 'traditional',
          'options' =>
          array (
            'traditional' =>
            array (
              'title' => 'Default',
              'icon' => 'eicon-editor-list-ul',
            ),
            'inline' =>
            array (
              'title' => 'Inline',
              'icon' => 'eicon-ellipsis-h',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'icon_list',
          'type' => 'repeater',
          'label' => 'Items',
          'default' =>
          array (
            0 =>
            array (
              'text' => 'List Item #1',
              'selected_icon' =>
              array (
                'value' => 'fas fa-check',
                'library' => 'fa-solid',
              ),
            ),
            1 =>
            array (
              'text' => 'List Item #2',
              'selected_icon' =>
              array (
                'value' => 'fas fa-times',
                'library' => 'fa-solid',
              ),
            ),
            2 =>
            array (
              'text' => 'List Item #3',
              'selected_icon' =>
              array (
                'value' => 'fas fa-dot-circle',
                'library' => 'fa-solid',
              ),
            ),
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'link_click',
          'type' => 'select',
          'label' => 'Apply Link On',
          'default' => 'full_width',
          'options' =>
          array (
            'full_width' => 'Full Width',
            'inline' => 'Inline',
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
      'id' => 'section_icon_list',
      'label' => 'List',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'space_between',
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
          'key' => 'icon_align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-h-align-center',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'divider',
          'type' => 'switcher',
          'label' => 'Divider',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
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
            'divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'divider_weight',
          'type' => 'slider',
          'label' => 'Weight',
          'default' =>
          array (
            'size' => 1,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'divider' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'divider_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'divider' => 'yes',
            'view!' => 'inline',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'divider_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'divider' => 'yes',
            'view' => 'inline',
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
            'divider' => 'yes',
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
      'id' => 'section_icon_style',
      'label' => 'Icon',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'icon_color_hover',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'icon_color_hover_transition',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 's',
            'size' => 0.3,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'icon_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' =>
          array (
            'size' => 14,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'text_indent',
          'type' => 'slider',
          'label' => 'Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'icon_self_align',
          'type' => 'choose',
          'label' => 'Horizontal Alignment',
          'default' => '',
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-h-align-center',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'icon_self_vertical_align',
          'type' => 'choose',
          'label' => 'Vertical Alignment',
          'default' => '',
          'options' =>
          array (
            'flex-start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-v-align-top',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-v-align-middle',
            ),
            'flex-end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'icon_vertical_offset',
          'type' => 'slider',
          'label' => 'Adjust Vertical Position',
          'default' =>
          array (
            'size' => 0,
          ),
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
    3 =>
    array (
      'id' => 'section_text_style',
      'label' => 'Text',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'text_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'text_color_hover',
          'type' => 'color',
          'label' => 'Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'text_color_hover_transition',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 's',
            'size' => 0.3,
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
          'name' => 'icon_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-icon-list-item > .elementor-icon-list-text, {{WRAPPER}} .elementor-icon-list-item > a',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-icon-list-text',
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
      'name' => 'icon_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-icon-list-item > .elementor-icon-list-text, {{WRAPPER}} .elementor-icon-list-item > a',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-shadow',
      'name' => 'text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-icon-list-text',
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
          'key' => 'text',
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
          'key' => 'selected_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            'value' => 'fas fa-check',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'link',
          'type' => 'url',
          'label' => 'Link',
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
    'view' =>
    array (
      'section' => 'section_icon',
      'type' => 'choose',
      'default' => 'traditional',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_list' =>
    array (
      'section' => 'section_icon',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'text' => 'List Item #1',
          'selected_icon' =>
          array (
            'value' => 'fas fa-check',
            'library' => 'fa-solid',
          ),
        ),
        1 =>
        array (
          'text' => 'List Item #2',
          'selected_icon' =>
          array (
            'value' => 'fas fa-times',
            'library' => 'fa-solid',
          ),
        ),
        2 =>
        array (
          'text' => 'List Item #3',
          'selected_icon' =>
          array (
            'value' => 'fas fa-dot-circle',
            'library' => 'fa-solid',
          ),
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_click' =>
    array (
      'section' => 'section_icon',
      'type' => 'select',
      'default' => 'full_width',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'space_between' =>
    array (
      'section' => 'section_icon_list',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_align' =>
    array (
      'section' => 'section_icon_list',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider' =>
    array (
      'section' => 'section_icon_list',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_style' =>
    array (
      'section' => 'section_icon_list',
      'type' => 'select',
      'default' => 'solid',
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_weight' =>
    array (
      'section' => 'section_icon_list',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_width' =>
    array (
      'section' => 'section_icon_list',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
        'view!' => 'inline',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_height' =>
    array (
      'section' => 'section_icon_list',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
        'view' => 'inline',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'divider_color' =>
    array (
      'section' => 'section_icon_list',
      'type' => 'color',
      'default' => '#ddd',
      'responsive' => false,
      'condition' =>
      array (
        'divider' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_color' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_color_hover' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_color_hover_transition' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 's',
        'size' => 0.3,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_size' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 14,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_indent' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_self_align' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'choose',
      'default' => '',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_self_vertical_align' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'choose',
      'default' => '',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_vertical_offset' =>
    array (
      'section' => 'section_icon_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color' =>
    array (
      'section' => 'section_text_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color_hover' =>
    array (
      'section' => 'section_text_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color_hover_transition' =>
    array (
      'section' => 'section_text_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 's',
        'size' => 0.3,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_typography_typography' =>
    array (
      'section' => 'section_text_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'icon_typography',
    ),
    'text_shadow_text_shadow' =>
    array (
      'section' => 'section_text_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'text_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'icon_typography_typography' => 'custom',
    'text_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'icon_list',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/icon-list-widget.md',
    1 => 'docs/knowledge/elementor/widgets/icon-list-widget.md',
  ),
  'control_count' => 24,
);
