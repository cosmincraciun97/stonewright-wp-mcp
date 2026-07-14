<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'accordion',
  'source' => 'free',
  'widget_type' => 'accordion',
  'title' => 'Accordion',
  'icon' => 'eicon-accordion',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'accordion',
    1 => 'tabs',
    2 => 'toggle',
  ),
  'file' => 'elementor/includes/widgets/accordion.php',
  'intent' => 'Toggle to enable or disable the option to use schema. Style tab Accordion Accordion',
  'use_cases' =>
  array (
    0 => 'If you are using nested elements, the Accordion widget can include nested elements',
    1 => 'For details see, Add an Accordion widget below',
    2 => 'With the Toggle widget, however, all items are collapsed when a page is first loaded',
    3 => 'Only one item of an Accordion can be expanded at a time, automatically collapsing the previously opened item',
    4 => 'In Elementor Editor, click +',
    5 => 'All available widgets are displayed',
    6 => 'Click or drag the widget to the canvas',
    7 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Add an Accordion widget – Step-by-step',
    1 => 'Set the HTML tag used for the title (H1 – H6 or DIV).',
    2 => 'Border Width – Set the thickness of the border around the accordion and between each itemBorder Color: Choose the color of the border around the accordion and between each item',
    3 => 'Background – Choose the color of the title’s backgroundColor: Choose the color of the non-active titles’ textActive Color: Choose the color of the active title’s textTypography: Set the typography options for the titles. For more details, see Typography.Text Stroke: Click the 🖋️ icon icon to apply a stroke effect to the title. Learn more about Text Stroke.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about shadows.Padding: Set the padding for the titles',
    4 => 'Alignment – Align the icon to the left or right of the titleColor: Choose the color of the iconsActive Color: Choose the color of the active iconSpacing: Control the spacing between the icon and the title',
    5 => 'Background – Choose the background color of the contentColor: Choose the text color of the contentTypography: Set the typography options for the contentText Shadow: Click the 🖋️ icon to add a shadow to the description. Learn more about shadows.Padding: Set the padding for the content.',
    6 => 'Size – Use the slider to determine the size of the icon next to the tab title. For more details, see Units of measurement.Spacing: Use the slider to determine the amount of room between the tabs’ title and the icon.Color: Choose an icon color: either use the color picker or a global color.You get three styles to choose for:Normal – The default style.Hover – The style when users mouse over the tab.Active – The style when the tab is open.',
    7 => 'Content options – Configure general content, title, tags, and icons.',
    8 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    9 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'The Accordion widget is not compatible with the Loop widget because nested elements do not work with repetitive elements.',
    3 => 'Determines how many tabs can be opened simultaneously.One – Single tab can be opened at a time. Multiple – Unlimited number of tabs can be opened simultaneously.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_title',
      'label' => 'Accordion',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tabs',
          'type' => 'repeater',
          'label' => 'Accordion Items',
          'default' =>
          array (
            0 =>
            array (
              'tab_title' => 'Accordion #1',
              'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
            ),
            1 =>
            array (
              'tab_title' => 'Accordion #2',
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
            'value' => 'fas fa-plus',
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
            'value' => 'fas fa-minus',
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
      'id' => 'section_title_style',
      'label' => 'Accordion',
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
          'selector' => '{{WRAPPER}} .elementor-accordion-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-accordion-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-accordion-title',
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
      'group' => 'typography',
      'name' => 'title_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-accordion-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-accordion-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-accordion-title',
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
          'default' => 'Accordion Title',
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
          'default' => 'Accordion Content',
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
    'tabs' =>
    array (
      'section' => 'section_title',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'tab_title' => 'Accordion #1',
          'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
        ),
        1 =>
        array (
          'tab_title' => 'Accordion #2',
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
      'section' => 'section_title',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-plus',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'selected_active_icon' =>
    array (
      'section' => 'section_title',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-minus',
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
      'section' => 'section_title',
      'type' => 'select',
      'default' => 'div',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'faq_schema' =>
    array (
      'section' => 'section_title',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_width' =>
    array (
      'section' => 'section_title_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_color' =>
    array (
      'section' => 'section_title_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
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
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_toggle_style_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
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
    'title_typography_typography' => 'custom',
    'text_stroke_text_stroke' => 'yes',
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
    0 => 'docs/knowledge/elementor/widgets/accordion-widget.md',
    1 => 'docs/knowledge/elementor/widgets/accordion-widget.md',
    2 => 'docs/knowledge/elementor/widgets/accordion-widget-with-nested-elements.md',
  ),
  'control_count' => 23,
);
