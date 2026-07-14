<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'alert',
  'source' => 'free',
  'widget_type' => 'alert',
  'title' => 'Alert',
  'icon' => 'eicon-alert',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'alert',
    1 => 'notice',
    2 => 'message',
  ),
  'file' => 'elementor/includes/widgets/alert.php',
  'intent' => 'If you choose to display the dismiss button, you can opt for the Default icon, Upload SVG, or select an icon from the Icon Library.',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Alert widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Draw attention to limited – time promotions, discounts, or flash sales on your e-commerce site with a prominent alert.',
    2 => 'Add an Alert widget – Step-by-step',
    3 => 'Choose the alert type – Info, Success, Warning, or Danger.',
    4 => 'Background Color – Pick the background color.',
    5 => 'Border Color – Choose the color of the left border',
    6 => 'Left Border Width – Set the width of the left border',
    7 => 'Text Color – Choose the color of the title text.',
  ),
  'limits' =>
  array (
    0 => 'Draw attention to limited-time promotions, discounts, or flash sales on your e-commerce site with a prominent alert.',
    1 => 'In the Content tab, under the Alert section, choose the pre-styled alert box type that best suits your message – options include Info, Success, Warning, and Danger.',
    2 => 'Choose the alert type: Info, Success, Warning, or Danger.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_alert',
      'label' => 'Alert',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'alert_type',
          'type' => 'select',
          'label' => 'Type',
          'default' => 'info',
          'options' =>
          array (
            'info' => 'Info',
            'success' => 'Success',
            'warning' => 'Warning',
            'danger' => 'Danger',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'alert_title',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'This is an Alert',
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
          'key' => 'alert_description',
          'type' => 'textarea',
          'label' => 'Content',
          'default' => 'I am a description. Click the edit button to change this text.',
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
          'key' => 'show_dismiss',
          'type' => 'switcher',
          'label' => 'Dismiss Icon',
          'default' => 'show',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'dismiss_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_dismiss' => 'show',
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
      'id' => 'section_type',
      'label' => 'Alert',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'background',
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
          'key' => 'border_color',
          'type' => 'color',
          'label' => 'Side Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'border_left-width',
          'type' => 'slider',
          'label' => 'Side Border Width',
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
      'id' => 'section_title',
      'label' => 'Title',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Text Color',
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
          'name' => 'alert_title',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-alert-title',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-alert-title',
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
      'id' => 'section_description',
      'label' => 'Description',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'description_color',
          'type' => 'color',
          'label' => 'Text Color',
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
          'name' => 'alert_description',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-alert-description',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'description_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-alert-description',
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
      'id' => 'section_dismiss_icon',
      'label' => 'Dismiss Icon',
      'tab' => 'style',
      'condition' =>
      array (
        'show_dismiss' => 'show',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'dismiss_icon_size',
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
          'key' => 'dismiss_icon_vertical_position',
          'type' => 'slider',
          'label' => 'Vertical Position',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'dismiss_icon_horizontal_position',
          'type' => 'slider',
          'label' => 'Horizontal Position',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'dismiss_icon_normal_color',
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
          'key' => 'dismiss_icon_hover_color',
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
          'key' => 'dismiss_icon_hover_transition_duration',
          'type' => 'slider',
          'label' => 'Transition Duration (s)',
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
      'group' => 'typography',
      'name' => 'alert_title',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-alert-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-alert-title',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'alert_description',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-alert-description',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'text-shadow',
      'name' => 'description_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-alert-description',
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
    'alert_type' =>
    array (
      'section' => 'section_alert',
      'type' => 'select',
      'default' => 'info',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alert_title' =>
    array (
      'section' => 'section_alert',
      'type' => 'text',
      'default' => 'This is an Alert',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alert_description' =>
    array (
      'section' => 'section_alert',
      'type' => 'textarea',
      'default' => 'I am a description. Click the edit button to change this text.',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_dismiss' =>
    array (
      'section' => 'section_alert',
      'type' => 'switcher',
      'default' => 'show',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dismiss_icon' =>
    array (
      'section' => 'section_alert',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_dismiss' => 'show',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background' =>
    array (
      'section' => 'section_type',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_color' =>
    array (
      'section' => 'section_type',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_left-width' =>
    array (
      'section' => 'section_type',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alert_title_typography' =>
    array (
      'section' => 'section_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'alert_title',
    ),
    'title_shadow_text_shadow' =>
    array (
      'section' => 'section_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'title_shadow',
    ),
    'description_color' =>
    array (
      'section' => 'section_description',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'alert_description_typography' =>
    array (
      'section' => 'section_description',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'alert_description',
    ),
    'description_shadow_text_shadow' =>
    array (
      'section' => 'section_description',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'description_shadow',
    ),
    'dismiss_icon_size' =>
    array (
      'section' => 'section_dismiss_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dismiss_icon_vertical_position' =>
    array (
      'section' => 'section_dismiss_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dismiss_icon_horizontal_position' =>
    array (
      'section' => 'section_dismiss_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dismiss_icon_normal_color' =>
    array (
      'section' => 'section_dismiss_icon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dismiss_icon_hover_color' =>
    array (
      'section' => 'section_dismiss_icon',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dismiss_icon_hover_transition_duration' =>
    array (
      'section' => 'section_dismiss_icon',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'alert_title_typography' => 'custom',
    'title_shadow_text_shadow' => 'yes',
    'alert_description_typography' => 'custom',
    'description_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/alert-widget.md',
    1 => 'docs/knowledge/elementor/widgets/alert-widget.md',
  ),
  'control_count' => 20,
);
