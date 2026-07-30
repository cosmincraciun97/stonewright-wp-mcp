<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'slides',
  'source' => 'pro',
  'widget_type' => 'slides',
  'title' => 'Slides',
  'icon' => 'eicon-slides',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'slides',
    1 => 'carousel',
    2 => 'image',
    3 => 'title',
    4 => 'slider',
  ),
  'file' => 'pro-elements/modules/slides/widgets/slides.php',
  'intent' => 'Create Slides That Engage Visitors WidgetsPro WidgetsSlides Get Elementor Pro Watch video Slides WidgetDisplay More Information In Less Space Build Responsive Slides Create full-width and full-screen slides and control how they look on every desktop, mobile, and tablet device. responsive editing Bring YourSlides to Life Add animations and special effects in a few clicks to create sophisticated slides that stand out. Sophisticated Visuals Get Inspired by Stunning Slides Explore exceptionally designed websites and get inspired by how they use slides in new and innovative ways. Learn How to Build Professional Slides Follow along while using Elementor’s intuitive panel to customize slide alignment, position and elevate the overall experience. HOW IT WORKS How to Use the Slides Widget in Elementor Pro Explore Other Widgets Take your website to the next level using Pro’s powerful widgets. Gallery Widget Gallery Posts Widget Posts Login Widget Login Form Widget Form',
  'use_cases' =>
  array (
    0 => 'responsive editing Bring YourSlides to Life Add animations and special effects in a few clicks to create sophisticated slides that stand out',
    1 => 'HOW IT WORKS How to Use the Slides Widget in Elementor Pro Explore Other Widgets Take your website to the next level using Pro’s powerful widgets',
    2 => 'Gallery Widget Gallery Posts Widget Posts Login Widget Login Form Widget Form',
    3 => 'All available widgets are displayed',
    4 => 'Click or drag the widget to the canvas',
    5 => 'For more information, see Add elements to a page',
    6 => 'What is the Slides widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content options – Configure general content, title, tags, and icons.',
    1 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    2 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    3 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    4 => 'Add a Slides widget – Step-by-step',
    5 => 'Note – By default, each tab inherits global styles from the widget’s main Style tab. However, if you want to customize a specific slide differently, toggle the Custom switch to ON. This allows you to personalize the following slide attributes:',
    6 => 'Horizontal Position – Align the content to the left, right, or center.Vertical Position: Adjust the content’s placement to the top, middle, or bottom.Text Align: Determine the text alignment as left, center, or right.Content Color: Select the desired color for the slide content.Text Shadow: Click the 🖋️ icon to add a shadow to the title. Learn more about shadows.',
    7 => 'Text Color – Set the text color.Background Type: Choose the background type of the button. Choose a Solid color or create a Gradient effect. Learn more about Gradient Backgrounds. Color: Set the background color of the button.Border Color: Set the border color of the button.Hover mode lets you set a transition duration. This is the length of time it takes for the element to change its appearance.',
    8 => 'Position – Set the position of the arrows inside or outside the slider.Size: Set the exact size of the arrows. Size can be in PX, EM, or REM. For more details about units of measurement, see Units of measurement. Color: Set the color of the arrows.',
    9 => 'Position – Set the position of the dots inside or outside the slider.Size: Set the exact size of the dots. Size can be in PX, EM, or REM. For more details about units of measurement, see Units of measurement. Color: Set the color of the dots.Active Color: Set the color for the currently active dot.',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'By default, each tab inherits global styles from the widget’s main Style tab. However, if you want to customize a specific slide differently, toggle the Custom switch to ON. This allows you to personalize the following slide attributes:',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_slides',
      'label' => 'Slides',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'slides_name',
          'type' => 'text',
          'label' => 'Slides Name',
          'default' => 'Slides',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'slides',
          'type' => 'repeater',
          'label' => 'Slides',
          'default' =>
          array (
            0 =>
            array (
              'heading' => 'Slide 1 Heading',
              'description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
              'button_text' => 'Click Here',
              'background_color' => '#833ca3',
              'background_image' =>
              array (
                'url' => '',
              ),
            ),
            1 =>
            array (
              'heading' => 'Slide 2 Heading',
              'description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
              'button_text' => 'Click Here',
              'background_color' => '#4054b2',
              'background_image' =>
              array (
                'url' => '',
              ),
            ),
            2 =>
            array (
              'heading' => 'Slide 3 Heading',
              'description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
              'button_text' => 'Click Here',
              'background_color' => '#1abc9c',
              'background_image' =>
              array (
                'url' => '',
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
          'key' => 'slides_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' =>
          array (
            'size' => 400,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'slides_title_tag',
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
            'span' => 'span',
            'p' => 'p',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'slides_description_tag',
          'type' => 'select',
          'label' => 'Description HTML Tag',
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
            'span' => 'span',
            'p' => 'p',
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
      'id' => 'section_slider_options',
      'label' => 'Slider Options',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'navigation',
          'type' => 'select',
          'label' => 'Navigation',
          'default' => 'both',
          'options' =>
          array (
            'both' => 'Arrows and Dots',
            'arrows' => 'Arrows',
            'dots' => 'Dots',
            'none' => 'None',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'autoplay',
          'type' => 'switcher',
          'label' => 'Autoplay',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'pause_on_hover',
          'type' => 'switcher',
          'label' => 'Pause on Hover',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'autoplay!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'pause_on_interaction',
          'type' => 'switcher',
          'label' => 'Pause on Interaction',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'autoplay!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'autoplay_speed',
          'type' => 'number',
          'label' => 'Autoplay Speed',
          'default' => 5000,
          'options' => NULL,
          'condition' =>
          array (
            'autoplay' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'infinite',
          'type' => 'switcher',
          'label' => 'Infinite Loop',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'transition',
          'type' => 'select',
          'label' => 'Transition',
          'default' => 'slide',
          'options' =>
          array (
            'slide' => 'Slide',
            'fade' => 'Fade',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'transition_speed',
          'type' => 'number',
          'label' => 'Transition Speed (ms)',
          'default' => 500,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'content_animation',
          'type' =>
          array (
            '__unresolved__' => 'Control_Slides_Animation::TYPE',
          ),
          'label' => 'Content Animation',
          'default' => 'fadeInUp',
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
      'id' => 'section_style_slides',
      'label' => 'Slides',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'content_max_width',
          'type' => 'slider',
          'label' => 'Content Width',
          'default' =>
          array (
            'size' => 66,
            'unit' => '%',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'slides_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'slides_horizontal_position',
          'type' => 'choose',
          'label' => 'Horizontal Position',
          'default' => 'center',
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
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'slides_vertical_position',
          'type' => 'choose',
          'label' => 'Vertical Position',
          'default' => 'middle',
          'options' =>
          array (
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'middle' =>
            array (
              'title' => 'Middle',
              'icon' => 'eicon-v-align-middle',
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
        4 =>
        array (
          'key' => 'slides_text_align',
          'type' => 'choose',
          'label' => 'Text Align',
          'default' => 'center',
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
          'responsive' => false,
          'description' => NULL,
        ),
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => 'text-shadow',
          'name' => 'text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .swiper-slide-contents',
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
      'id' => 'section_style_title',
      'label' => 'Title',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'heading_color',
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
          'name' => 'heading_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-slide-heading',
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
      'id' => 'section_style_description',
      'label' => 'Description',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'description_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
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
          'name' => 'description_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-slide-description',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    5 =>
    array (
      'id' => 'section_style_button',
      'label' => 'Button',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'button_size',
          'type' => 'select',
          'label' => 'Size',
          'default' => 'sm',
          'options' =>
          array (
            '__unresolved__' => 'self::get_button_sizes()',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'button_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'button_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
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
          'key' => 'button_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'button_hover_text_color',
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
          'key' => 'button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'button_hover_transition_duration',
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
          'selector' => '{{WRAPPER}} .elementor-slide-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' => 'button_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-slide-button',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'background',
          'name' => 'button_hover_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-slide-button:hover',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    6 =>
    array (
      'id' => 'section_style_navigation',
      'label' => 'Navigation',
      'tab' => 'style',
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'dots',
          2 => 'both',
        ),
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_style_arrows',
          'type' => 'heading',
          'label' => 'Arrows',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'arrows',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'arrows_position',
          'type' => 'select',
          'label' => 'Position',
          'default' => 'inside',
          'options' =>
          array (
            'inside' => 'Inside',
            'outside' => 'Outside',
          ),
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'arrows',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'arrows_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'arrows',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'arrows_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'arrows',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'heading_style_dots',
          'type' => 'heading',
          'label' => 'Pagination',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'dots_position',
          'type' => 'select',
          'label' => 'Position',
          'default' => 'inside',
          'options' =>
          array (
            'outside' => 'Outside',
            'inside' => 'Inside',
          ),
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'dots_gap',
          'type' => 'slider',
          'label' => 'Space Between Dots',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'dots_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'dots_color_inactive',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'dots_color',
          'type' => 'color',
          'label' => 'Active Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'navigation' =>
            array (
              0 => 'dots',
              1 => 'both',
            ),
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
  ),
  'group_controls' =>
  array (
    0 =>
    array (
      'group' => 'text-shadow',
      'name' => 'text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .swiper-slide-contents',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'heading_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-slide-heading',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'description_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-slide-description',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'typography',
      'name' => 'button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-slide-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'background',
      'name' => 'button_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-slide-button',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'background',
      'name' => 'button_hover_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-slide-button:hover',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
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
          'key' => 'background_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => '#bbbbbb',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'background_image',
          'type' => 'media',
          'label' => 'Image',
          'default' =>
          array (
            'url' => '',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'background_size',
          'type' => 'select',
          'label' => 'Size',
          'default' => 'cover',
          'options' =>
          array (
            'cover' => 'Cover',
            'contain' => 'Contain',
            'auto' => 'Auto',
          ),
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'background_image[url]',
                'operator' => '!=',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'background_ken_burns',
          'type' => 'switcher',
          'label' => 'Ken Burns Effect',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'background_image[url]',
                'operator' => '!=',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'zoom_direction',
          'type' => 'select',
          'label' => 'Zoom Direction',
          'default' => 'in',
          'options' =>
          array (
            'in' => 'In',
            'out' => 'Out',
          ),
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'background_ken_burns',
                'operator' => '!=',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'background_overlay',
          'type' => 'switcher',
          'label' => 'Background Overlay',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'background_image[url]',
                'operator' => '!=',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'background_overlay_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => 'rgba(0,0,0,0.5)',
          'options' => NULL,
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'background_overlay',
                'value' => 'yes',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'background_overlay_blend_mode',
          'type' => 'select',
          'label' => 'Blend Mode',
          'default' => NULL,
          'options' =>
          array (
            '' => 'Normal',
            'multiply' => 'Multiply',
            'screen' => 'Screen',
            'overlay' => 'Overlay',
            'darken' => 'Darken',
            'lighten' => 'Lighten',
            'color-dodge' => 'Color Dodge',
            'color-burn' => 'Color Burn',
            'hue' => 'Hue',
            'saturation' => 'Saturation',
            'color' => 'Color',
            'exclusion' => 'Exclusion',
            'luminosity' => 'Luminosity',
          ),
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'background_overlay',
                'value' => 'yes',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'heading',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'Slide Heading',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'description',
          'type' => 'textarea',
          'label' => 'Description',
          'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'button_text',
          'type' => 'text',
          'label' => 'Button Text',
          'default' => 'Click Here',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
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
        12 =>
        array (
          'key' => 'link_click',
          'type' => 'select',
          'label' => 'Apply Link On',
          'default' => 'slide',
          'options' =>
          array (
            'slide' => 'Whole Slide',
            'button' => 'Button Only',
          ),
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'link[url]',
                'operator' => '!=',
                'value' => '',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'custom_style',
          'type' => 'switcher',
          'label' => 'Custom',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Set custom style that will only affect this specific slide.',
        ),
        14 =>
        array (
          'key' => 'horizontal_position',
          'type' => 'choose',
          'label' => 'Horizontal Position',
          'default' => NULL,
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
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'custom_style',
                'value' => 'yes',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'vertical_position',
          'type' => 'choose',
          'label' => 'Vertical Position',
          'default' => NULL,
          'options' =>
          array (
            'top' =>
            array (
              'title' => 'Top',
              'icon' => 'eicon-v-align-top',
            ),
            'middle' =>
            array (
              'title' => 'Middle',
              'icon' => 'eicon-v-align-middle',
            ),
            'bottom' =>
            array (
              'title' => 'Bottom',
              'icon' => 'eicon-v-align-bottom',
            ),
          ),
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'custom_style',
                'value' => 'yes',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'text_align',
          'type' => 'choose',
          'label' => 'Text Align',
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
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'custom_style',
                'value' => 'yes',
              ),
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'content_color',
          'type' => 'color',
          'label' => 'Content Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'custom_style',
                'value' => 'yes',
              ),
            ),
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
    'slides_name' =>
    array (
      'section' => 'section_slides',
      'type' => 'text',
      'default' => 'Slides',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides' =>
    array (
      'section' => 'section_slides',
      'type' => 'repeater',
      'default' =>
      array (
        0 =>
        array (
          'heading' => 'Slide 1 Heading',
          'description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
          'button_text' => 'Click Here',
          'background_color' => '#833ca3',
          'background_image' =>
          array (
            'url' => '',
          ),
        ),
        1 =>
        array (
          'heading' => 'Slide 2 Heading',
          'description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
          'button_text' => 'Click Here',
          'background_color' => '#4054b2',
          'background_image' =>
          array (
            'url' => '',
          ),
        ),
        2 =>
        array (
          'heading' => 'Slide 3 Heading',
          'description' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit dolor',
          'button_text' => 'Click Here',
          'background_color' => '#1abc9c',
          'background_image' =>
          array (
            'url' => '',
          ),
        ),
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_height' =>
    array (
      'section' => 'section_slides',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 400,
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_title_tag' =>
    array (
      'section' => 'section_slides',
      'type' => 'select',
      'default' => 'div',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_description_tag' =>
    array (
      'section' => 'section_slides',
      'type' => 'select',
      'default' => 'div',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'navigation' =>
    array (
      'section' => 'section_slider_options',
      'type' => 'select',
      'default' => 'both',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'autoplay' =>
    array (
      'section' => 'section_slider_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pause_on_hover' =>
    array (
      'section' => 'section_slider_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'autoplay!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pause_on_interaction' =>
    array (
      'section' => 'section_slider_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'autoplay!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'autoplay_speed' =>
    array (
      'section' => 'section_slider_options',
      'type' => 'number',
      'default' => 5000,
      'responsive' => false,
      'condition' =>
      array (
        'autoplay' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'infinite' =>
    array (
      'section' => 'section_slider_options',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'transition' =>
    array (
      'section' => 'section_slider_options',
      'type' => 'select',
      'default' => 'slide',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'transition_speed' =>
    array (
      'section' => 'section_slider_options',
      'type' => 'number',
      'default' => 500,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_animation' =>
    array (
      'section' => 'section_slider_options',
      'type' =>
      array (
        '__unresolved__' => 'Control_Slides_Animation::TYPE',
      ),
      'default' => 'fadeInUp',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'content_max_width' =>
    array (
      'section' => 'section_style_slides',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 66,
        'unit' => '%',
      ),
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_padding' =>
    array (
      'section' => 'section_style_slides',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_horizontal_position' =>
    array (
      'section' => 'section_style_slides',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_vertical_position' =>
    array (
      'section' => 'section_style_slides',
      'type' => 'choose',
      'default' => 'middle',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'slides_text_align' =>
    array (
      'section' => 'section_style_slides',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_shadow_text_shadow' =>
    array (
      'section' => 'section_style_slides',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'text_shadow',
    ),
    'heading_spacing' =>
    array (
      'section' => 'section_style_title',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_color' =>
    array (
      'section' => 'section_style_title',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_typography_typography' =>
    array (
      'section' => 'section_style_title',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'heading_typography',
    ),
    'description_spacing' =>
    array (
      'section' => 'section_style_description',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_color' =>
    array (
      'section' => 'section_style_description',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'description_typography_typography' =>
    array (
      'section' => 'section_style_description',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'description_typography',
    ),
    'button_size' =>
    array (
      'section' => 'section_style_button',
      'type' => 'select',
      'default' => 'sm',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_width' =>
    array (
      'section' => 'section_style_button',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_radius' =>
    array (
      'section' => 'section_style_button',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_color' =>
    array (
      'section' => 'section_style_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_color' =>
    array (
      'section' => 'section_style_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_text_color' =>
    array (
      'section' => 'section_style_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_border_color' =>
    array (
      'section' => 'section_style_button',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_transition_duration' =>
    array (
      'section' => 'section_style_button',
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
      'section' => 'section_style_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'button_typography',
    ),
    'button_background_background' =>
    array (
      'section' => 'section_style_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'button_background',
    ),
    'button_hover_background_background' =>
    array (
      'section' => 'section_style_button',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'button_hover_background',
    ),
    'heading_style_arrows' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'arrows_position' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'select',
      'default' => 'inside',
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'arrows_size' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'arrows_color' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'arrows',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_style_dots' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_position' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'select',
      'default' => 'inside',
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_gap' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_size' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_color_inactive' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dots_color' =>
    array (
      'section' => 'section_style_navigation',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'navigation' =>
        array (
          0 => 'dots',
          1 => 'both',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'text_shadow_text_shadow' => 'yes',
    'heading_typography_typography' => 'custom',
    'description_typography_typography' => 'custom',
    'button_typography_typography' => 'custom',
    'button_background_background' => 'classic',
    'button_hover_background_background' => 'classic',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/slides-widget.md',
    1 => 'docs/knowledge/elementor/widgets/slides-widget.md',
    2 => 'docs/knowledge/elementor/widgets/slides-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/slides-widget-pro.md',
  ),
  'control_count' => 47,
);
