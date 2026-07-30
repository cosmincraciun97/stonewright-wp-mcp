<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'hotspot',
  'source' => 'pro',
  'widget_type' => 'hotspot',
  'title' => 'Hotspot',
  'icon' => 'eicon-image-hotspot',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'image',
    1 => 'tooltip',
    2 => 'CTA',
    3 => 'dot',
  ),
  'file' => 'pro-elements/modules/hotspot/widgets/hotspot.php',
  'intent' => 'The Hotspot Widget focuses on helping you create interactive images that can help you highlight relevant information, boost engagement and increase conversions for your website.',
  'use_cases' =>
  array (
    0 => 'Offset – Use the slider to adjust the horizontal position of the hotspot in %',
    1 => 'Offset – Use the slider to adjust the vertical position of the hotspot in %',
    2 => 'Color – Use the color picker to choose your hotspot color',
    3 => 'Box Color – Use the color picker to choose the label background color',
  ),
  'settings_highlights' =>
  array (
    0 => 'Tip – You can use the Dynamic Options in the Link to open an Elementor Popup rather than a tooltip.',
    1 => 'Note – If the Custom Tooltip Position is enabled, the Custom Box Position will be used, rather than the general Tooltip Position.',
    2 => 'Content options – Configure general content, title, tags, and icons.',
    3 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    4 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'If the Custom Tooltip Position is enabled, the Custom Box Position will be used, rather than the general Tooltip Position.',
    1 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    2 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'hotspot_section',
      'label' => 'Hotspot',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'hotspot',
          'type' => 'repeater',
          'label' => 'Hotspot',
          'default' =>
          array (
            0 =>
            array (
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
          'key' => 'hotspot_animation',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'e-hotspot--expand',
          'options' =>
          array (
            'e-hotspot--soft-beat' => 'Soft Beat',
            'e-hotspot--expand' => 'Expand',
            'e-hotspot--overlay' => 'Overlay',
            '' => 'None',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'hotspot_sequenced_animation',
          'type' => 'switcher',
          'label' => 'Sequenced Animation',
          'default' => 'no',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'hotspot_sequenced_animation_duration',
          'type' => 'slider',
          'label' => 'Sequence Duration (ms)',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'hotspot_sequenced_animation' => 'yes',
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
      'id' => 'tooltip_section',
      'label' => 'Tooltip',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'tooltip_position',
          'type' => 'choose',
          'label' => 'Position',
          'default' => 'top',
          'options' =>
          array (
            'right' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'bottom' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'left' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
            'top' =>
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
          'key' => 'tooltip_trigger',
          'type' => 'select',
          'label' => 'Trigger',
          'default' => 'click',
          'options' =>
          array (
            'mouseenter' => 'Hover',
            'click' => 'Click',
            'none' => 'None',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'tooltip_animation',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'e-hotspot--fade-in-out',
          'options' =>
          array (
            'e-hotspot--fade-in-out' => 'Fade In/Out',
            'e-hotspot--fade-grow' => 'Fade Grow',
            'e-hotspot--fade-direction' => 'Fade By Direction',
            'e-hotspot--slide-direction' => 'Slide By Direction',
          ),
          'condition' =>
          array (
            'tooltip_trigger!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'tooltip_animation_duration',
          'type' => 'slider',
          'label' => 'Animation Duration (ms)',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'tooltip_trigger!' => 'none',
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
      'id' => 'section_style_hotspot',
      'label' => 'Hotspot',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'style_hotspot_color',
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
          'key' => 'style_hotspot_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'style_hotspot_width',
          'type' => 'slider',
          'label' => 'Min Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'style_hotspot_height',
          'type' => 'slider',
          'label' => 'Min Height',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'style_hotspot_box_color',
          'type' => 'color',
          'label' => 'Box Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'style_hotspot_padding',
          'type' => 'slider',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'style_hotspot_border_radius',
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
          'group' => 'typography',
          'name' => 'style_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-hotspot__label',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'box-shadow',
          'name' => 'style_hotspot_box_shadow',
          'label' => NULL,
          'selector' => '
					{{WRAPPER}} .e-hotspot:not(.e-hotspot--circle) .e-hotspot__button,
					{{WRAPPER}} .e-hotspot.e-hotspot--circle .e-hotspot__button .e-hotspot__outer-circle
				',
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
      'id' => 'section_style_tooltip',
      'label' => 'Tooltip',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'style_tooltip_text_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'style_tooltip_align',
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
        2 =>
        array (
          'key' => 'style_tooltip_heading',
          'type' => 'heading',
          'label' => 'Box',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'style_tooltip_width',
          'type' => 'slider',
          'label' => 'Min Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'style_tooltip_max_width',
          'type' => 'slider',
          'label' => 'Max Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'style_tooltip_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'style_tooltip_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'style_tooltip_border_radius',
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
          'group' => 'typography',
          'name' => 'style_tooltip_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-hotspot__tooltip',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'box-shadow',
          'name' => 'style_tooltip_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-hotspot__tooltip',
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
      'name' => 'style_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-hotspot__label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'box-shadow',
      'name' => 'style_hotspot_box_shadow',
      'label' => NULL,
      'selector' => '
					{{WRAPPER}} .e-hotspot:not(.e-hotspot--circle) .e-hotspot__button,
					{{WRAPPER}} .e-hotspot.e-hotspot--circle .e-hotspot__button .e-hotspot__outer-circle
				',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'style_tooltip_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-hotspot__tooltip',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'box-shadow',
      'name' => 'style_tooltip_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-hotspot__tooltip',
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
          'key' => 'hotspot_label',
          'type' => 'text',
          'label' => 'Label',
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
        1 =>
        array (
          'key' => 'hotspot_link',
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
        2 =>
        array (
          'key' => 'hotspot_icon',
          'type' => 'icons',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'hotspot_icon_position',
          'type' => 'choose',
          'label' => 'Icon Position',
          'default' => 'start',
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' =>
              array (
                '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
              ),
            ),
          ),
          'condition' =>
          array (
            'hotspot_icon[value]!' => '',
            'hotspot_label[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'hotspot_icon_spacing',
          'type' => 'slider',
          'label' => 'Icon Spacing',
          'default' =>
          array (
            'size' => 5,
          ),
          'options' => NULL,
          'condition' =>
          array (
            'hotspot_icon[value]!' => '',
            'hotspot_label[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'hotspot_custom_size',
          'type' => 'switcher',
          'label' => 'Custom Hotspot Size',
          'default' => 'no',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Set custom Hotspot size that will only affect this specific hotspot.',
        ),
        6 =>
        array (
          'key' => 'hotspot_width',
          'type' => 'slider',
          'label' => 'Min Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'hotspot_custom_size' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'hotspot_height',
          'type' => 'slider',
          'label' => 'Min Height',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'hotspot_custom_size' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'hotspot_tooltip_content',
          'type' => 'wysiwyg',
          'label' => 'Tooltip Content',
          'default' => 'Add Your Tooltip Text Here',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'hotspot_horizontal',
          'type' => 'choose',
          'label' => 'Horizontal Orientation',
          'default' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\Ternary',
          ),
          'options' =>
          array (
            'left' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-h-align-left',
            ),
            'right' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'hotspot_vertical',
          'type' => 'choose',
          'label' => 'Vertical Orientation',
          'default' => 'top',
          'options' =>
          array (
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'bottom' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'hotspot_tooltip_position',
          'type' => 'switcher',
          'label' => 'Custom Tooltip Properties',
          'default' => 'no',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Set custom Tooltip opening that will only affect this specific hotspot.',
        ),
        12 =>
        array (
          'key' => 'hotspot_heading',
          'type' => 'heading',
          'label' => 'Box',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'hotspot_tooltip_position' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'hotspot_tooltip_text_wrap',
          'type' => 'switcher',
          'label' => 'Text Wrap',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'hotspot_tooltip_position' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
      ),
    ),
  ),
  'settings_index' =>
  array (
    'hotspot' =>
    array (
      'section' => 'hotspot_section',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hotspot_animation' =>
    array (
      'section' => 'hotspot_section',
      'type' => 'select',
      'default' => 'e-hotspot--expand',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hotspot_sequenced_animation' =>
    array (
      'section' => 'hotspot_section',
      'type' => 'switcher',
      'default' => 'no',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hotspot_sequenced_animation_duration' =>
    array (
      'section' => 'hotspot_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'hotspot_sequenced_animation' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tooltip_position' =>
    array (
      'section' => 'tooltip_section',
      'type' => 'choose',
      'default' => 'top',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tooltip_trigger' =>
    array (
      'section' => 'tooltip_section',
      'type' => 'select',
      'default' => 'click',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tooltip_animation' =>
    array (
      'section' => 'tooltip_section',
      'type' => 'select',
      'default' => 'e-hotspot--fade-in-out',
      'responsive' => false,
      'condition' =>
      array (
        'tooltip_trigger!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'tooltip_animation_duration' =>
    array (
      'section' => 'tooltip_section',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'tooltip_trigger!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_hotspot_color' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_hotspot_size' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_hotspot_width' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_hotspot_height' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_hotspot_box_color' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_hotspot_padding' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_hotspot_border_radius' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_typography_typography' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'style_typography',
    ),
    'style_hotspot_box_shadow_box_shadow' =>
    array (
      'section' => 'section_style_hotspot',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'style_hotspot_box_shadow',
    ),
    'style_tooltip_text_color' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_tooltip_align' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_tooltip_heading' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_tooltip_width' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_tooltip_max_width' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_tooltip_padding' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_tooltip_color' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_tooltip_border_radius' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_tooltip_typography_typography' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'style_tooltip_typography',
    ),
    'style_tooltip_box_shadow_box_shadow' =>
    array (
      'section' => 'section_style_tooltip',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'style_tooltip_box_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'style_typography_typography' => 'custom',
    'style_hotspot_box_shadow_box_shadow' => 'yes',
    'style_tooltip_typography_typography' => 'custom',
    'style_tooltip_box_shadow_box_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/hotspot.md',
    1 => 'docs/knowledge/elementor/widgets/hotspot.md',
    2 => 'docs/knowledge/elementor/widgets/hotspot-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/hotspot-widget-pro.md',
  ),
  'control_count' => 27,
);
