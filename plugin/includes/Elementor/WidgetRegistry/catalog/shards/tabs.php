<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'tabs',
  'source' => 'free',
  'widget_type' => 'tabs',
  'title' => 'Tabs',
  'icon' => 'eicon-tabs',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'tabs',
    1 => 'accordion',
    2 => 'toggle',
  ),
  'file' => 'elementor/includes/widgets/tabs.php',
  'intent' => 'Choose between displaying the tabs horizontally or vertically. Alignment',
  'use_cases' =>
  array (
    0 => 'In Elementor Editor, click +',
    1 => 'All available widgets are displayed',
    2 => 'Click or drag the widget to the canvas',
    3 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Provide step – by-step guides or tutorials with each step contained within a separate tab for clarity and organization.Present different services offered by a company in a clean and accessible format on their website.Display various categories of blog posts or articles for easy navigation on a content-heavy website.',
    1 => 'Add a Tabs widget – Step-by-step',
    2 => 'Border Width – Set the thickness of the border around the tabsBodre Color: Choose a color for the borderBackground Color: Choose a background color for the tabs',
    3 => 'Color – Choose the color for the title of the tabsActive Color: Choose the color for the title of the tab that is currently selectedTypography: Set the typography options for the titles. For more details, see TypographyText Stroke: Click the 🖋️ icon icon to apply a stroke effect to the title. Learn more about Text StrokeText Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about shadows',
    4 => 'Color – Choose the text color of the contentTypography: Set the typography options for the contentText Shadow: Click the 🖋️ icon to add a shadow to the description. Learn more about shadows.',
    5 => 'Choose a color – either use the color picker or a global color.The titles’ color can have three different styles:Normal – The default style.Hover – The style when users mouse over the tab.Active – The style when the tab is open.',
    6 => 'Choose an icon color – either use the color picker or a global color.You get three styles to choose for:Normal – The default style.Hover – The style when users mouse over the tab.Active – The style when the tab is open.',
    7 => 'Content options – Configure general content, title, tags, and icons.',
    8 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    9 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'Set the direction of the tabs to either column or row.Note: If you set the direction to row, a slider appears, allowing you to adjust the width of the tabs in either PX or %.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_tabs',
      'label' => 'Tabs',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tabs',
          'type' => 'repeater',
          'label' => 'Tabs Items',
          'default' =>
          array (
            0 =>
            array (
              'tab_title' => 'Tab #1',
              'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
            ),
            1 =>
            array (
              'tab_title' => 'Tab #2',
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
          'key' => 'type',
          'type' => 'choose',
          'label' => 'Position',
          'default' => 'horizontal',
          'options' =>
          array (
            'vertical' =>
            array (
              'title' => 'Vertical',
              'icon' => 'eicon-h-align-left',
            ),
            'horizontal' =>
            array (
              'title' => 'Horizontal',
              'icon' => 'eicon-v-align-top',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'tabs_align_horizontal',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            '' =>
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
            'type' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'tabs_align_vertical',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            '' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-align-start-v',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-align-center-v',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-align-end-v',
            ),
            'stretch' =>
            array (
              'title' => 'Stretch',
              'icon' => 'eicon-align-stretch-v',
            ),
          ),
          'condition' =>
          array (
            'type' => 'vertical',
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
      'id' => 'section_tabs_style',
      'label' => 'Tabs',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'navigation_width',
          'type' => 'slider',
          'label' => 'Navigation Width',
          'default' =>
          array (
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'type' => 'vertical',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' =>
          array (
            'size' => 1,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
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
        3 =>
        array (
          'key' => 'background_color',
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
          'key' => 'heading_title',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'tab_color',
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
        7 =>
        array (
          'key' => 'title_align',
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
            'tabs_align' => 'stretch',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'heading_content',
          'type' => 'heading',
          'label' => 'Content',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
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
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'typography',
          'name' => 'tab_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-tab-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-tab-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-tab-title',
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
      ),
    ),
  ),
  'group_controls' =>
  array (
    0 =>
    array (
      'group' => 'typography',
      'name' => 'tab_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-tab-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-tab-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-tab-title',
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
          'default' => 'Tab Title',
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
          'default' => 'Tab Content',
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
      'section' => 'section_tabs',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'tab_title' => 'Tab #1',
          'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
        ),
        1 =>
        array (
          'tab_title' => 'Tab #2',
          'tab_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'type' =>
    array (
      'section' => 'section_tabs',
      'type' => 'choose',
      'default' => 'horizontal',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_align_horizontal' =>
    array (
      'section' => 'section_tabs',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tabs_align_vertical' =>
    array (
      'section' => 'section_tabs',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'vertical',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'navigation_width' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'type' => 'vertical',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_width' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 1,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_color' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_color' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_title' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tab_color' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tab_active_color' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_align' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tabs_align' => 'stretch',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_content' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_color' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tab_typography_typography' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'tab_typography',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'title_shadow_text_shadow' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'title_shadow',
    ),
    'content_typography_typography' =>
    array (
      'section' => 'section_tabs_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'content_typography',
    ),
    'content_shadow_text_shadow' =>
    array (
      'section' => 'section_tabs_style',
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
    'tab_typography_typography' => 'custom',
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
    0 => 'docs/knowledge/elementor/widgets/tabs-widget.md',
    1 => 'docs/knowledge/elementor/widgets/tabs-widget.md',
    2 => 'docs/knowledge/elementor/widgets/tabs-with-nested-containers.md',
  ),
  'control_count' => 19,
);
