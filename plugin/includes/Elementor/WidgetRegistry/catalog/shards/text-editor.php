<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'text-editor',
  'source' => 'free',
  'widget_type' => 'text-editor',
  'title' => 'Text Editor',
  'icon' => 'eicon-text',
  'categories' =>
  array (
    0 => 'basic',
  ),
  'keywords' =>
  array (
    0 => 'text',
    1 => 'editor',
  ),
  'file' => 'elementor/includes/widgets/text-editor.php',
  'intent' => 'Type or copy/paste the text content. You can add text, images, and even WordPress shortcodes. Drop Cap (Optional)',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Text Editor widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Add a Text Editor widget – Step-by-step',
    2 => 'View – Select the view style of the drop cap, choosing from Default, Stacked, or Framed.',
    3 => 'Primary Color – Choose the color of the drop cap.',
    4 => 'Text Shadow – Add a shadow to the drop cap. For more details, see Shadow, Text Shadow and Boxed Shadow.',
    5 => 'Space – Select the exact distance between the drop cap and the rest of the text.',
    6 => 'Border Radius – If Stacked or Framed is chosen as the View, set the border-radius to control corner roundness.',
    7 => 'Typography – Set the typography options, such as font and weight, for the drop cap. For more details, see Typography.',
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
      'id' => 'section_editor',
      'label' => 'Text Editor',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'editor',
          'type' => 'wysiwyg',
          'label' => '',
          'default' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.</p>',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'drop_cap',
          'type' => 'switcher',
          'label' => 'Drop Cap',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'text_columns',
          'type' => 'select',
          'label' => 'Columns',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Default',
            1 => '1',
            2 => '2',
            3 => '3',
            4 => '4',
            5 => '5',
            6 => '6',
            7 => '7',
            8 => '8',
            9 => '9',
            10 => '10',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'column_gap',
          'type' => 'slider',
          'label' => 'Columns Gap',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'text_columns',
                'operator' => '>',
                'value' => 1,
              ),
              1 =>
              array (
                'name' => 'text_columns',
                'operator' => '===',
                'value' => '',
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
      'id' => 'section_style',
      'label' => 'Text Editor',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'align',
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
              'title' => 'Justified',
              'icon' => 'eicon-text-align-justify',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'paragraph_spacing',
          'type' => 'slider',
          'label' => 'Paragraph Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'separator',
          'type' => 'divider',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'link_color',
          'type' => 'color',
          'label' => 'Link Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'link_hover_color',
          'type' => 'color',
          'label' => 'Link Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'link_hover_color_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration',
          'default' =>
          array (
            'unit' => 's',
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
          'name' => 'typography',
          'label' => NULL,
          'selector' => NULL,
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}}',
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
      'id' => 'section_drop_cap',
      'label' => 'Drop Cap',
      'tab' => 'style',
      'condition' =>
      array (
        'drop_cap' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'drop_cap_view',
          'type' => 'select',
          'label' => 'View',
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Default',
            'stacked' => 'Stacked',
            'framed' => 'Framed',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'drop_cap_primary_color',
          'type' => 'color',
          'label' => 'Primary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'drop_cap_secondary_color',
          'type' => 'color',
          'label' => 'Secondary Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'drop_cap_view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'drop_cap_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' =>
          array (
            'size' => 5,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'drop_cap_view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'drop_cap_space',
          'type' => 'slider',
          'label' => 'Space',
          'default' =>
          array (
            'size' => 10,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'drop_cap_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'drop_cap_view!' => 'default',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'drop_cap_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'drop_cap_view' => 'framed',
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
          'group' => 'text-shadow',
          'name' => 'drop_cap_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-drop-cap',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'typography',
          'name' => 'drop_cap_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-drop-cap-letter',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'letter_spacing',
          ),
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
      'name' => 'typography',
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-shadow',
      'name' => 'text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}}',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'drop_cap_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-drop-cap',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'drop_cap_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-drop-cap-letter',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'letter_spacing',
      ),
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'editor' =>
    array (
      'section' => 'section_editor',
      'type' => 'wysiwyg',
      'default' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.</p>',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'drop_cap' =>
    array (
      'section' => 'section_editor',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_columns' =>
    array (
      'section' => 'section_editor',
      'type' => 'select',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'column_gap' =>
    array (
      'section' => 'section_editor',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'text_columns',
            'operator' => '>',
            'value' => 1,
          ),
          1 =>
          array (
            'name' => 'text_columns',
            'operator' => '===',
            'value' => '',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'paragraph_spacing' =>
    array (
      'section' => 'section_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'separator' =>
    array (
      'section' => 'section_style',
      'type' => 'divider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color' =>
    array (
      'section' => 'section_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_color' =>
    array (
      'section' => 'section_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_hover_color' =>
    array (
      'section' => 'section_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'link_hover_color_transition_duration' =>
    array (
      'section' => 'section_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => 's',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_typography' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'typography',
    ),
    'text_shadow_text_shadow' =>
    array (
      'section' => 'section_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'text_shadow',
    ),
    'drop_cap_view' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'drop_cap_primary_color' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'drop_cap_secondary_color' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'drop_cap_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'drop_cap_size' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 5,
      ),
      'responsive' => false,
      'condition' =>
      array (
        'drop_cap_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'drop_cap_space' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 10,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'drop_cap_border_radius' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'drop_cap_view!' => 'default',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'drop_cap_border_width' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'drop_cap_view' => 'framed',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'drop_cap_shadow_text_shadow' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'drop_cap_shadow',
    ),
    'drop_cap_typography_typography' =>
    array (
      'section' => 'section_drop_cap',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'drop_cap_typography',
    ),
  ),
  'group_activators' =>
  array (
    'typography_typography' => 'custom',
    'text_shadow_text_shadow' => 'yes',
    'drop_cap_shadow_text_shadow' => 'yes',
    'drop_cap_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
    0 => 'editor',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/text-editor-widget.md',
    1 => 'docs/knowledge/elementor/widgets/text-editor-widget.md',
  ),
  'control_count' => 22,
);
