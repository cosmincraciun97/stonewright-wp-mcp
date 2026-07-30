<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'nav-menu',
  'source' => 'pro',
  'widget_type' => 'nav-menu',
  'title' => 'WordPress Menu',
  'icon' => 'eicon-nav-menu',
  'categories' =>
  array (
    0 => 'pro-elements',
    1 => 'theme-elements',
  ),
  'keywords' =>
  array (
    0 => 'menu',
    1 => 'nav',
    2 => 'button',
    3 => 'nav menu',
  ),
  'file' => 'pro-elements/modules/nav-menu/widgets/nav-menu.php',
  'intent' => 'Create an Intuitive Navigation Experience WidgetsPro WidgetsNav Menu Get Elementor Pro Watch video Nav Menu WidgetImprove the User Experience With a Custom Menu Choose a Menu to Match Your Style Customize every detail of your navigation menu: menu-type, direction, alignment, sub-menu indicators, and more. Customizable Layouts Fit It to Any Device or Layout Choose to display menus horizontally, vertically, and more. Make it responsive with dropdown & hamburger options. Responsive design Bring the Menu to Life With Animations Animate your menu content with hover navigation indicators and pointer interactions. Interactive Menu Get Inspired by Creative Menus Explore exceptionally designed websites that are creating menus that look amazing and are intuitive to use. Learn How to Design Menus That Improve Your User Interface Master the Nav Menu widget: change the colors of the menu items, adjust it to fit any device, customize the submenu, and more. HOW IT WORKS Introducing Menu Widget: The Most Powerful Menu Builder For WordPress Explore Other Widgets Take your website to the next level using Pro’s powerful widgets. Gallery Widget Gallery Posts Widget Posts Login Widget Login Asset 325 Form',
  'use_cases' =>
  array (
    0 => 'Customizable Layouts Fit It to Any Device or Layout Choose to display menus horizontally, vertically, and more',
    1 => 'Make it responsive with dropdown & hamburger options',
    2 => 'Responsive design Bring the Menu to Life With Animations Animate your menu content with hover navigation indicators and pointer interactions',
    3 => 'Gallery Widget Gallery Posts Widget Posts Login Widget Login Asset 325 Form',
    4 => 'In Elementor Editor, click +',
    5 => 'All available widgets are displayed',
    6 => 'Click or drag the widget to the canvas',
    7 => 'For more information, see Add elements to a page',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content options – Configure general content, title, tags, and icons.',
    1 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    2 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    3 => 'Add a WordPress Menu widget – Step-by-step',
    4 => 'Border Type – Select a border type for the divider, choosing either None, Solid, Double, Dotted, Dashed, or GrooveBorder Color: If divider border is chosen, choose the color of the divider borderBorder Width: If divider border is chosen, set the width of the divider borderDistance: Set the amount of space between the toggle and the dropdown menu',
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
      'id' => 'section_layout',
      'label' => 'Layout',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'menu_name',
          'type' => 'text',
          'label' => 'Menu Name',
          'default' => 'Menu',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'menu',
          'type' => 'select',
          'label' => 'Menu',
          'default' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Expr\\ArrayDimFetch',
          ),
          'options' =>
          array (
            '__unresolved__' => '$menus',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' =>
          array (
            '__unresolved__' => 'sprintf()',
          ),
        ),
        2 =>
        array (
          'key' => 'menu',
          'type' => 'alert',
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
          'key' => 'layout',
          'type' => 'select',
          'label' => 'Layout',
          'default' => 'horizontal',
          'options' =>
          array (
            'horizontal' => 'Horizontal',
            'vertical' => 'Vertical',
            'dropdown' => 'Dropdown',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'align_items',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
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
            'justify' =>
            array (
              'title' => 'Stretch',
              'icon' => 'eicon-align-stretch-h',
            ),
          ),
          'condition' =>
          array (
            'layout!' => 'dropdown',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'pointer',
          'type' => 'select',
          'label' => 'Pointer',
          'default' => 'underline',
          'options' =>
          array (
            'none' => 'None',
            'underline' => 'Underline',
            'overline' => 'Overline',
            'double-line' => 'Double Line',
            'framed' => 'Framed',
            'background' => 'Background',
            'text' => 'Text',
          ),
          'condition' =>
          array (
            'layout!' => 'dropdown',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'animation_line',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'fade',
          'options' =>
          array (
            'fade' => 'Fade',
            'slide' => 'Slide',
            'grow' => 'Grow',
            'drop-in' => 'Drop In',
            'drop-out' => 'Drop Out',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'layout!' => 'dropdown',
            'pointer' =>
            array (
              0 => 'underline',
              1 => 'overline',
              2 => 'double-line',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'animation_framed',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'fade',
          'options' =>
          array (
            'fade' => 'Fade',
            'grow' => 'Grow',
            'shrink' => 'Shrink',
            'draw' => 'Draw',
            'corners' => 'Corners',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'layout!' => 'dropdown',
            'pointer' => 'framed',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'animation_background',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'fade',
          'options' =>
          array (
            'fade' => 'Fade',
            'grow' => 'Grow',
            'shrink' => 'Shrink',
            'sweep-left' => 'Sweep Left',
            'sweep-right' => 'Sweep Right',
            'sweep-up' => 'Sweep Up',
            'sweep-down' => 'Sweep Down',
            'shutter-in-vertical' => 'Shutter In Vertical',
            'shutter-out-vertical' => 'Shutter Out Vertical',
            'shutter-in-horizontal' => 'Shutter In Horizontal',
            'shutter-out-horizontal' => 'Shutter Out Horizontal',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'layout!' => 'dropdown',
            'pointer' => 'background',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'animation_text',
          'type' => 'select',
          'label' => 'Animation',
          'default' => 'grow',
          'options' =>
          array (
            'grow' => 'Grow',
            'shrink' => 'Shrink',
            'sink' => 'Sink',
            'float' => 'Float',
            'skew' => 'Skew',
            'rotate' => 'Rotate',
            'none' => 'None',
          ),
          'condition' =>
          array (
            'layout!' => 'dropdown',
            'pointer' => 'text',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'submenu_icon',
          'type' => 'icons',
          'label' => 'Submenu Indicator',
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
        11 =>
        array (
          'key' => 'heading_mobile_dropdown',
          'type' => 'heading',
          'label' => 'Mobile Dropdown',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'layout!' => 'dropdown',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'dropdown',
          'type' => 'select',
          'label' => 'Breakpoint',
          'default' => 'tablet',
          'options' =>
          array (
            '__unresolved__' => '$dropdown_options',
          ),
          'condition' =>
          array (
            'layout!' => 'dropdown',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'full_width',
          'type' => 'switcher',
          'label' => 'Full Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'dropdown!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => 'Stretch the dropdown of the menu to full width.',
        ),
        14 =>
        array (
          'key' => 'text_align',
          'type' => 'select',
          'label' => 'Text Align',
          'default' => 'aside',
          'options' =>
          array (
            'aside' => 'Aside',
            'center' => 'Center',
          ),
          'condition' =>
          array (
            'dropdown!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'toggle',
          'type' => 'select',
          'label' => 'Toggle Button',
          'default' => 'burger',
          'options' =>
          array (
            '' => 'None',
            'burger' => 'Hamburger',
          ),
          'condition' =>
          array (
            'dropdown!' => 'none',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'toggle_icon_normal',
          'type' => 'icons',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'toggle' => 'burger',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        17 =>
        array (
          'key' => 'toggle_icon_hover_animation',
          'type' => 'hover_animation',
          'label' => 'Hover Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'toggle' => 'burger',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        18 =>
        array (
          'key' => 'toggle_icon_active',
          'type' => 'icons',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'toggle' => 'burger',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        19 =>
        array (
          'key' => 'toggle_align',
          'type' => 'choose',
          'label' => 'Toggle Align',
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
          'condition' =>
          array (
            'toggle!' => '',
            'dropdown!' => 'none',
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
      'id' => 'section_style_main-menu',
      'label' => 'Main Menu',
      'tab' => 'style',
      'condition' =>
      array (
        'layout!' => 'dropdown',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'color_menu_item',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'color_menu_item_hover',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'pointer!' => 'background',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'color_menu_item_hover_pointer_bg',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '#fff',
          'options' => NULL,
          'condition' =>
          array (
            'pointer' => 'background',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'pointer_color_menu_item_hover',
          'type' => 'color',
          'label' => 'Pointer Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'pointer!' =>
            array (
              0 => 'none',
              1 => 'text',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'color_menu_item_active',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'pointer_color_menu_item_active',
          'type' => 'color',
          'label' => 'Pointer Color',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'pointer!' =>
            array (
              0 => 'none',
              1 => 'text',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'nav_menu_divider',
          'type' => 'switcher',
          'label' => 'Divider',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'layout' => 'horizontal',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'nav_menu_divider_style',
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
            '__unresolved__' => '$divider_condition',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'nav_menu_divider_weight',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => '$divider_condition',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'nav_menu_divider_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => '$divider_condition',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'nav_menu_divider_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => '$divider_condition',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'hr',
          'type' => 'divider',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'pointer_width',
          'type' => 'slider',
          'label' => 'Pointer Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'pointer' =>
            array (
              0 => 'underline',
              1 => 'overline',
              2 => 'double-line',
              3 => 'framed',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        13 =>
        array (
          'key' => 'padding_horizontal_menu_item',
          'type' => 'slider',
          'label' => 'Horizontal Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        14 =>
        array (
          'key' => 'padding_vertical_menu_item',
          'type' => 'slider',
          'label' => 'Vertical Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        15 =>
        array (
          'key' => 'menu_space_between',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        16 =>
        array (
          'key' => 'border_radius_menu_item',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'pointer' => 'background',
          ),
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
          'name' => 'menu_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-nav-menu .elementor-item',
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
      'id' => 'section_style_dropdown',
      'label' => 'Dropdown',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'dropdown_description',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'color_dropdown_item',
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
          'key' => 'background_color_dropdown_item',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'color_dropdown_item_hover',
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
          'key' => 'background_color_dropdown_item_hover',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'color_dropdown_item_active',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'background_color_dropdown_item_active',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'dropdown_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'padding_horizontal_dropdown_item',
          'type' => 'slider',
          'label' => 'Horizontal Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'padding_vertical_dropdown_item',
          'type' => 'slider',
          'label' => 'Vertical Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'heading_dropdown_divider',
          'type' => 'heading',
          'label' => 'Divider',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'dropdown_divider_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'dropdown_divider_border!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'dropdown_top_distance',
          'type' => 'slider',
          'label' => 'Distance',
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
          'name' => 'dropdown_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-nav-menu--dropdown .elementor-item, {{WRAPPER}} .elementor-nav-menu--dropdown  .elementor-sub-item',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'line_height',
          ),
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'border',
          'name' => 'dropdown_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-nav-menu--dropdown',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'box-shadow',
          'name' => 'dropdown_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-nav-menu--main .elementor-nav-menu--dropdown, {{WRAPPER}} .elementor-nav-menu__container.elementor-nav-menu--dropdown',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'box_shadow_position',
          ),
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'border',
          'name' => 'dropdown_divider',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-nav-menu--dropdown li:not(:last-child)',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'width',
          ),
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    3 =>
    array (
      'id' => 'style_toggle',
      'label' => 'Toggle Button',
      'tab' => 'style',
      'condition' =>
      array (
        'toggle!' => '',
        'dropdown!' => 'none',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'toggle_color',
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
          'key' => 'toggle_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'toggle_color_hover',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'toggle_background_color_hover',
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
          'key' => 'toggle_size',
          'type' => 'slider',
          'label' => 'Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'toggle_border_width',
          'type' => 'slider',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'toggle_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
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
  ),
  'group_controls' =>
  array (
    0 =>
    array (
      'group' => 'typography',
      'name' => 'menu_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-nav-menu .elementor-item',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'dropdown_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-nav-menu--dropdown .elementor-item, {{WRAPPER}} .elementor-nav-menu--dropdown  .elementor-sub-item',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'line_height',
      ),
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'border',
      'name' => 'dropdown_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-nav-menu--dropdown',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'box-shadow',
      'name' => 'dropdown_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-nav-menu--main .elementor-nav-menu--dropdown, {{WRAPPER}} .elementor-nav-menu__container.elementor-nav-menu--dropdown',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'box_shadow_position',
      ),
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'border',
      'name' => 'dropdown_divider',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-nav-menu--dropdown li:not(:last-child)',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'width',
      ),
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'menu_name' =>
    array (
      'section' => 'section_layout',
      'type' => 'text',
      'default' => 'Menu',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'menu' =>
    array (
      'section' => 'section_layout',
      'type' => 'alert',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'layout' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'horizontal',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align_items' =>
    array (
      'section' => 'section_layout',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'layout!' => 'dropdown',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pointer' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'underline',
      'responsive' => false,
      'condition' =>
      array (
        'layout!' => 'dropdown',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_line' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'fade',
      'responsive' => false,
      'condition' =>
      array (
        'layout!' => 'dropdown',
        'pointer' =>
        array (
          0 => 'underline',
          1 => 'overline',
          2 => 'double-line',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_framed' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'fade',
      'responsive' => false,
      'condition' =>
      array (
        'layout!' => 'dropdown',
        'pointer' => 'framed',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_background' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'fade',
      'responsive' => false,
      'condition' =>
      array (
        'layout!' => 'dropdown',
        'pointer' => 'background',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'animation_text' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'grow',
      'responsive' => false,
      'condition' =>
      array (
        'layout!' => 'dropdown',
        'pointer' => 'text',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'submenu_icon' =>
    array (
      'section' => 'section_layout',
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
    'heading_mobile_dropdown' =>
    array (
      'section' => 'section_layout',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'layout!' => 'dropdown',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dropdown' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'tablet',
      'responsive' => false,
      'condition' =>
      array (
        'layout!' => 'dropdown',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'full_width' =>
    array (
      'section' => 'section_layout',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'dropdown!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'text_align' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'aside',
      'responsive' => false,
      'condition' =>
      array (
        'dropdown!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle' =>
    array (
      'section' => 'section_layout',
      'type' => 'select',
      'default' => 'burger',
      'responsive' => false,
      'condition' =>
      array (
        'dropdown!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_icon_normal' =>
    array (
      'section' => 'section_layout',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'toggle' => 'burger',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_icon_hover_animation' =>
    array (
      'section' => 'section_layout',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'toggle' => 'burger',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_icon_active' =>
    array (
      'section' => 'section_layout',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'toggle' => 'burger',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_align' =>
    array (
      'section' => 'section_layout',
      'type' => 'choose',
      'default' => 'center',
      'responsive' => false,
      'condition' =>
      array (
        'toggle!' => '',
        'dropdown!' => 'none',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_menu_item' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_menu_item_hover' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'pointer!' => 'background',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_menu_item_hover_pointer_bg' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'color',
      'default' => '#fff',
      'responsive' => false,
      'condition' =>
      array (
        'pointer' => 'background',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pointer_color_menu_item_hover' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'pointer!' =>
        array (
          0 => 'none',
          1 => 'text',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_menu_item_active' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pointer_color_menu_item_active' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'pointer!' =>
        array (
          0 => 'none',
          1 => 'text',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nav_menu_divider' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'layout' => 'horizontal',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nav_menu_divider_style' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'select',
      'default' => 'solid',
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => '$divider_condition',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nav_menu_divider_weight' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => '$divider_condition',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nav_menu_divider_height' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => '$divider_condition',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nav_menu_divider_color' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => '$divider_condition',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'hr' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'divider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pointer_width' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'pointer' =>
        array (
          0 => 'underline',
          1 => 'overline',
          2 => 'double-line',
          3 => 'framed',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'padding_horizontal_menu_item' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'padding_vertical_menu_item' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'menu_space_between' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'border_radius_menu_item' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'pointer' => 'background',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'menu_typography_typography' =>
    array (
      'section' => 'section_style_main-menu',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'menu_typography',
    ),
    'dropdown_description' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_dropdown_item' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_color_dropdown_item' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_dropdown_item_hover' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_color_dropdown_item_hover' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'color_dropdown_item_active' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'background_color_dropdown_item_active' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dropdown_border_radius' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'padding_horizontal_dropdown_item' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'padding_vertical_dropdown_item' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_dropdown_divider' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dropdown_divider_width' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'dropdown_divider_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dropdown_top_distance' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'dropdown_typography_typography' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'dropdown_typography',
    ),
    'dropdown_border_border' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'dropdown_border',
    ),
    'dropdown_box_shadow_box_shadow' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'dropdown_box_shadow',
    ),
    'dropdown_divider_border' =>
    array (
      'section' => 'section_style_dropdown',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'dropdown_divider',
    ),
    'toggle_color' =>
    array (
      'section' => 'style_toggle',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_background_color' =>
    array (
      'section' => 'style_toggle',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_color_hover' =>
    array (
      'section' => 'style_toggle',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_background_color_hover' =>
    array (
      'section' => 'style_toggle',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_size' =>
    array (
      'section' => 'style_toggle',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_border_width' =>
    array (
      'section' => 'style_toggle',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'toggle_border_radius' =>
    array (
      'section' => 'style_toggle',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
  ),
  'group_activators' =>
  array (
    'menu_typography_typography' => 'custom',
    'dropdown_typography_typography' => 'custom',
    'dropdown_border_border' => 'solid',
    'dropdown_box_shadow_box_shadow' => 'yes',
    'dropdown_divider_border' => 'solid',
  ),
  'required_for_render' =>
  array (
    0 => 'menu',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/nav-menu-widget.md',
    1 => 'docs/knowledge/elementor/widgets/nav-menu-widget.md',
    2 => 'docs/knowledge/elementor/widgets/nav-menu-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/nav-menu-widget-pro.md',
  ),
  'control_count' => 61,
);
