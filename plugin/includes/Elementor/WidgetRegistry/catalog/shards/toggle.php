<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'toggle',
  'source' => 'free',
  'widget_type' => 'toggle',
  'title' => 'Toggle',
  'icon' => 'eicon-toggle',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'tabs',
    1 => 'accordion',
    2 => 'toggle',
  ),
  'file' => 'elementor/includes/widgets/toggle.php',
  'intent' => 'If you are using nested elements, the Accordion widget (Accordion with nested elements) incorporates the capabilities of Toggle widget and replaces it. This means the Toggle widget will no longer be available. Existing toggles are unaffected by this change and they can still be edited.',
  'use_cases' =>
  array (
    0 => 'When a page is loaded, all Toggle widget items are collapsed',
    1 => 'With the Accordion widget, however, the first item is expanded, while all other items remain collapsed',
    2 => 'With the Toggle widget, visitors can expand as many items as they like',
    3 => 'With the Accordion widget they are limited to only expanding one item at a time',
  ),
  'settings_highlights' =>
  array (
    0 => 'Add a Toggle widget – Step-by-step',
    1 => 'Border Width – Set the thickness of the border around the toggle widget and between each item.Border Color: Choose the color of the border around the toggle widget and between each item.Space Between: Set the amount of space between each item.Box Shadow: Set the box shadow around the toggle widget, or around each item if there is space between each. You can adjust the box shadow’s Color, Horizontal position, Vertical position, Blur, and Spread as well as the shadow’s Position, which can be either Inset or Outline.',
    2 => 'Background – Choose the color of the title’s background.Color: Choose the color of the non-active titles’ text.Active Color: Choose the color of the active title’s textTypography: Set the typography options for the titles. For more details, see Typography.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about shadows.Padding: Set the padding for the titles.',
    3 => 'Alignment – Align the icon to the left or right of the title.Color: Choose the color of the icons.Active Color: Choose the color of the active icon.Spacing: Control the spacing between the icon and the title.',
    4 => 'Background – Choose the background color of the content.Color: Choose the text color of the content.Typography: Set the typography options for the content.Text Shadow: Click the 🖋️ icon to add a shadow to the description. Learn more about shadows.Padding: Set the padding for the content.',
  ),
  'limits' =>
  array (
    0 => 'With the Toggle widget, visitors can expand as many items as they like. With the Accordion widget they are limited to only expanding one item at a time.',
    1 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    2 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_toggle',
      'label' => 'Toggle',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tabs',
          'type' => 'repeater',
          'label' => 'Toggle Items',
          'default' =>
          array (
            0 =>
            array (
              'tab_title' => 'Toggle #1',
              'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
            ),
            1 =>
            array (
              'tab_title' => 'Toggle #2',
              'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
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
          'key' => 'selected_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            'value' =>
            array (
              '__unresolved__' => 'PhpParser\\Node\\Expr\\BinaryOp\\Concat',
            ),
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
          'key' => 'selected_active_icon',
          'type' => 'icons',
          'label' => 'Active Icon',
          'default' =>
          array (
            'value' => 'fas fa-caret-up',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'selected_icon[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'title_html_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
          'default' => 'div',
          'options' =>
          array (
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'div' => 'div',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'faq_schema',
          'type' => 'switcher',
          'label' => 'FAQ Schema',
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
    1 =>
    array (
      'id' => 'section_toggle_style',
      'label' => 'Toggle',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
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
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'box-shadow',
          'name' => 'box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-toggle-item',
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
      'id' => 'section_toggle_style_title',
      'label' => 'Title',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title_background',
          'type' => 'color',
          'label' => 'Background',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'title_color',
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
          'key' => 'tab_active_color',
          'type' => 'color',
          'label' => 'Active Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'title_padding',
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
          'name' => 'title_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-toggle-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-toggle-title',
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
      'id' => 'section_toggle_style_icon',
      'label' => 'Icon',
      'tab' => 'style',
      'condition' =>
      array (
        'selected_icon[value]!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon_align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
          ),
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'right' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'icon_color',
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
          'key' => 'icon_active_color',
          'type' => 'color',
          'label' => 'Active Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'icon_space',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    4 =>
    array (
      'id' => 'section_toggle_style_content',
      'label' => 'Content',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'content_background_color',
          'type' => 'color',
          'label' => 'Background',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'content_color',
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
          'key' => 'content_padding',
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
          'name' => 'content_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-tab-content',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'content_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-tab-content',
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
      'name' => 'box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-toggle-item',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-toggle-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-toggle-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'content_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-tab-content',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'text-shadow',
      'name' => 'content_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-tab-content',
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
          'key' => 'tab_title',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Toggle Title',
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
          'key' => 'tab_content',
          'type' => 'wysiwyg',
          'label' => 'Content',
          'default' => 'Toggle Content',
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
    'tabs' =>
    array (
      'section' => 'section_toggle',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'tab_title' => 'Toggle #1',
          'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
        ),
        1 =>
        array (
          'tab_title' => 'Toggle #2',
          'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'selected_icon' =>
    array (
      'section' => 'section_toggle',
      'type' => 'icons',
      'default' =>
      array (
        'value' =>
        array (
          '__unresolved__' => 'PhpParser\\Node\\Expr\\BinaryOp\\Concat',
        ),
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'selected_active_icon' =>
    array (
      'section' => 'section_toggle',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-caret-up',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'selected_icon[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_html_tag' =>
    array (
      'section' => 'section_toggle',
      'type' => 'select',
      'default' => 'div',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'faq_schema' =>
    array (
      'section' => 'section_toggle',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_width' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_color' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'space_between' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_shadow_box_shadow' =>
    array (
      'section' => 'section_toggle_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'box_shadow',
    ),
    'title_background' =>
    array (
      'section' => 'section_toggle_style_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_toggle_style_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tab_active_color' =>
    array (
      'section' => 'section_toggle_style_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_padding' =>
    array (
      'section' => 'section_toggle_style_title',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_typography_typography' =>
    array (
      'section' => 'section_toggle_style_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'title_typography',
    ),
    'title_shadow_text_shadow' =>
    array (
      'section' => 'section_toggle_style_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'title_shadow',
    ),
    'icon_align' =>
    array (
      'section' => 'section_toggle_style_icon',
      'type' => 'choose',
      'default' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_color' =>
    array (
      'section' => 'section_toggle_style_icon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_active_color' =>
    array (
      'section' => 'section_toggle_style_icon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_space' =>
    array (
      'section' => 'section_toggle_style_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_background_color' =>
    array (
      'section' => 'section_toggle_style_content',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_color' =>
    array (
      'section' => 'section_toggle_style_content',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_padding' =>
    array (
      'section' => 'section_toggle_style_content',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_typography_typography' =>
    array (
      'section' => 'section_toggle_style_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'content_typography',
    ),
    'content_shadow_text_shadow' =>
    array (
      'section' => 'section_toggle_style_content',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'content_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'box_shadow_box_shadow' => 'yes',
    'title_typography_typography' => 'custom',
    'title_shadow_text_shadow' => 'yes',
    'content_typography_typography' => 'custom',
    'content_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'tabs',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/toggle-widget.md',
    1 => 'docs/knowledge/elementor/widgets/toggle-widget.md',
  ),
  'control_count' => 24,
);
