<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'countdown',
  'source' => 'pro',
  'widget_type' => 'countdown',
  'title' => 'Countdown',
  'icon' => 'eicon-countdown',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'countdown',
    1 => 'number',
    2 => 'timer',
    3 => 'time',
    4 => 'date',
    5 => 'evergreen',
  ),
  'file' => 'pro-elements/modules/countdown/widgets/countdown.php',
  'intent' => 'The Countdown widget enables you to add countdowns to your page without the use of 3rd party plugins.',
  'use_cases' =>
  array (
    0 => 'Organizing your layout design and structuring content elements inside Elementor.',
    1 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    2 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Type – Choose either Due Date or Evergreen Timer',
    1 => 'Due Date – If Due Date is the type chosen, this option will appear. Set the destination date and time for your countdown',
    2 => 'Hours / Minutes – If Evergreen Timer is the type chosen, these options will appear. Set the hours and minutes of the countdown by entering the value in the field or by using Dynamic Tags *',
    3 => 'View – Choose between Block or Inline',
    4 => 'Days – Show or Hide the Days display',
    5 => 'Hours – Show or Hide the Hours display',
    6 => 'Minutes – Show or Hide the Minutes display',
    7 => 'Seconds – Show or Hide the Seconds display',
  ),
  'limits' =>
  array (
    0 => '* Note: Evergreen Timer means every single visitor gets the same countdown time frame you set',
    1 => 'Expire message will be displayed only when selecting the After Expire Action > Show Message.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_countdown',
      'label' => 'Countdown',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'countdown_type',
          'type' => 'select',
          'label' => 'Type',
          'default' => 'due_date',
          'options' =>
          array (
            'due_date' => 'Due Date',
            'evergreen' => 'Evergreen Timer',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'due_date',
          'type' => 'date_time',
          'label' => 'Due Date',
          'default' =>
          array (
            '__unresolved__' => 'gmdate()',
          ),
          'options' => NULL,
          'condition' =>
          array (
            'countdown_type' => 'due_date',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' =>
          array (
            '__unresolved__' => 'sprintf()',
          ),
        ),
        2 =>
        array (
          'key' => 'evergreen_counter_hours',
          'type' => 'number',
          'label' => 'Hours',
          'default' => 47,
          'options' => NULL,
          'condition' =>
          array (
            'countdown_type' => 'evergreen',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'evergreen_counter_minutes',
          'type' => 'number',
          'label' => 'Minutes',
          'default' => 59,
          'options' => NULL,
          'condition' =>
          array (
            'countdown_type' => 'evergreen',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'show_days',
          'type' => 'switcher',
          'label' => 'Days',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'show_hours',
          'type' => 'switcher',
          'label' => 'Hours',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'show_minutes',
          'type' => 'switcher',
          'label' => 'Minutes',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'show_seconds',
          'type' => 'switcher',
          'label' => 'Seconds',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'show_labels',
          'type' => 'switcher',
          'label' => 'Show Label',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'custom_labels',
          'type' => 'switcher',
          'label' => 'Custom Label',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_labels!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'label_days',
          'type' => 'text',
          'label' => 'Days',
          'default' => 'Days',
          'options' => NULL,
          'condition' =>
          array (
            'show_labels!' => '',
            'custom_labels!' => '',
            'show_days' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'label_hours',
          'type' => 'text',
          'label' => 'Hours',
          'default' => 'Hours',
          'options' => NULL,
          'condition' =>
          array (
            'show_labels!' => '',
            'custom_labels!' => '',
            'show_hours' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'label_minutes',
          'type' => 'text',
          'label' => 'Minutes',
          'default' => 'Minutes',
          'options' => NULL,
          'condition' =>
          array (
            'show_labels!' => '',
            'custom_labels!' => '',
            'show_minutes' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'label_seconds',
          'type' => 'text',
          'label' => 'Seconds',
          'default' => 'Seconds',
          'options' => NULL,
          'condition' =>
          array (
            'show_labels!' => '',
            'custom_labels!' => '',
            'show_seconds' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'expire_actions',
          'type' => 'select2',
          'label' => 'Actions After Expire',
          'default' => NULL,
          'options' =>
          array (
            'redirect' => 'Redirect',
            'hide' => 'Hide',
            'message' => 'Show Message',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'message_after_expire',
          'type' => 'textarea',
          'label' => 'Message',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'expire_actions' => 'message',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'expire_redirect_url',
          'type' => 'url',
          'label' => 'Redirect URL',
          'default' => NULL,
          'options' => false,
          'condition' =>
          array (
            'expire_actions' => 'redirect',
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
    1 =>
    array (
      'id' => 'section_countdown_style',
      'label' => 'Countdown',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_container',
          'type' => 'heading',
          'label' => 'Container',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'label_display',
          'type' => 'choose',
          'label' => 'Layout',
          'default' => 'block',
          'options' =>
          array (
            'block' =>
            array (
              'title' => 'Block',
              'icon' => 'eicon-grow',
            ),
            'inline' =>
            array (
              'title' => 'Inline',
              'icon' => 'eicon-shrink',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'container_width',
          'type' => 'slider',
          'label' => 'Container Width',
          'default' =>
          array (
            'unit' => '%',
            'size' => 100,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'label_display' => 'block',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'boxes_alignment',
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
            'label_display' => 'inline',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'box_spacing',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' =>
          array (
            'size' => 10,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'heading_boxes',
          'type' => 'heading',
          'label' => 'Boxes',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'box_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
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
        8 =>
        array (
          'key' => 'box_border_radius',
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
        0 =>
        array (
          'group' => 'border',
          'name' => 'box_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-item',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'box-shadow',
          'name' => 'box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-item',
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
      'id' => 'section_content_style',
      'label' => 'Content',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_digits',
          'type' => 'heading',
          'label' => 'Digits',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'digits_color',
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
          'key' => 'heading_label',
          'type' => 'heading',
          'label' => 'Label',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_labels!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'label_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_labels!' => '',
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
          'name' => 'digits_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-digits',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'digits_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-digits',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'label_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-label',
          'condition' =>
          array (
            'show_labels!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'text-shadow',
          'name' => 'label_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-label',
          'condition' =>
          array (
            'show_labels!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        4 =>
        array (
          'group' => 'text-stroke',
          'name' => 'text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-label',
          'condition' =>
          array (
            'show_labels!' => '',
          ),
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
      'id' => 'section_expire_message_style',
      'label' => 'Message',
      'tab' => 'style',
      'condition' =>
      array (
        'expire_actions' => 'message',
      ),
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
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
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
        2 =>
        array (
          'key' => 'message_padding',
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
          'name' => 'typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-expire--message',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'message_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-countdown-expire--message',
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
      'group' => 'border',
      'name' => 'box_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-item',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'box-shadow',
      'name' => 'box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-item',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'digits_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-digits',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'text-shadow',
      'name' => 'digits_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-digits',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'typography',
      'name' => 'label_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-label',
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'text-shadow',
      'name' => 'label_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-label',
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'text-stroke',
      'name' => 'text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-label',
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'typography',
      'name' => 'typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-expire--message',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'text-shadow',
      'name' => 'message_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-countdown-expire--message',
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
    'countdown_type' =>
    array (
      'section' => 'section_countdown',
      'type' => 'select',
      'default' => 'due_date',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'due_date' =>
    array (
      'section' => 'section_countdown',
      'type' => 'date_time',
      'default' =>
      array (
        '__unresolved__' => 'gmdate()',
      ),
      'responsive' => false,
      'condition' =>
      array (
        'countdown_type' => 'due_date',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'evergreen_counter_hours' =>
    array (
      'section' => 'section_countdown',
      'type' => 'number',
      'default' => 47,
      'responsive' => false,
      'condition' =>
      array (
        'countdown_type' => 'evergreen',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'evergreen_counter_minutes' =>
    array (
      'section' => 'section_countdown',
      'type' => 'number',
      'default' => 59,
      'responsive' => false,
      'condition' =>
      array (
        'countdown_type' => 'evergreen',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_days' =>
    array (
      'section' => 'section_countdown',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_hours' =>
    array (
      'section' => 'section_countdown',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_minutes' =>
    array (
      'section' => 'section_countdown',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_seconds' =>
    array (
      'section' => 'section_countdown',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_labels' =>
    array (
      'section' => 'section_countdown',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'custom_labels' =>
    array (
      'section' => 'section_countdown',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_days' =>
    array (
      'section' => 'section_countdown',
      'type' => 'text',
      'default' => 'Days',
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
        'custom_labels!' => '',
        'show_days' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_hours' =>
    array (
      'section' => 'section_countdown',
      'type' => 'text',
      'default' => 'Hours',
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
        'custom_labels!' => '',
        'show_hours' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_minutes' =>
    array (
      'section' => 'section_countdown',
      'type' => 'text',
      'default' => 'Minutes',
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
        'custom_labels!' => '',
        'show_minutes' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_seconds' =>
    array (
      'section' => 'section_countdown',
      'type' => 'text',
      'default' => 'Seconds',
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
        'custom_labels!' => '',
        'show_seconds' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'expire_actions' =>
    array (
      'section' => 'section_countdown',
      'type' => 'select2',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'message_after_expire' =>
    array (
      'section' => 'section_countdown',
      'type' => 'textarea',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'expire_actions' => 'message',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'expire_redirect_url' =>
    array (
      'section' => 'section_countdown',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'expire_actions' => 'redirect',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_container' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_display' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'choose',
      'default' => 'block',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'container_width' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'slider',
      'default' =>
      array (
        'unit' => '%',
        'size' => 100,
      ),
      'responsive' => true,
      'condition' =>
      array (
        'label_display' => 'block',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'boxes_alignment' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'label_display' => 'inline',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_spacing' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 10,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_boxes' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_padding' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_background_color' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_border_radius' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'box_border_border' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'box_border',
    ),
    'box_shadow_box_shadow' =>
    array (
      'section' => 'section_countdown_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'box_shadow',
    ),
    'heading_digits' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'digits_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_label' =>
    array (
      'section' => 'section_content_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_color' =>
    array (
      'section' => 'section_content_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'digits_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'digits_typography',
    ),
    'digits_text_shadow_text_shadow' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'digits_text_shadow',
    ),
    'label_typography_typography' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'label_typography',
    ),
    'label_text_shadow_text_shadow' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'group' => 'text-shadow',
      'group_prefix' => 'label_text_shadow',
    ),
    'text_stroke_text_stroke' =>
    array (
      'section' => 'section_content_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'group' => 'text-stroke',
      'group_prefix' => 'text_stroke',
    ),
    'align' =>
    array (
      'section' => 'section_expire_message_style',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_color' =>
    array (
      'section' => 'section_expire_message_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'message_padding' =>
    array (
      'section' => 'section_expire_message_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_typography' =>
    array (
      'section' => 'section_expire_message_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'typography',
    ),
    'message_text_shadow_text_shadow' =>
    array (
      'section' => 'section_expire_message_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'message_text_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'box_border_border' => 'solid',
    'box_shadow_box_shadow' => 'yes',
    'digits_typography_typography' => 'custom',
    'digits_text_shadow_text_shadow' => 'yes',
    'label_typography_typography' => 'custom',
    'label_text_shadow_text_shadow' => 'yes',
    'text_stroke_text_stroke' => 'yes',
    'typography_typography' => 'custom',
    'message_text_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'due_date',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/countdown-widget-pro.md',
    1 => 'docs/knowledge/elementor/widgets/countdown-widget-pro.md',
  ),
  'control_count' => 42,
);
