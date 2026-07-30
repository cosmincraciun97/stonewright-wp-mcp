<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'login',
  'source' => 'pro',
  'widget_type' => 'login',
  'title' => 'Login',
  'icon' => 'eicon-lock-user',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'login',
    1 => 'user',
    2 => 'form',
  ),
  'file' => 'pro-elements/modules/forms/widgets/login.php',
  'intent' => 'Create a Login Experience Users Will Love WidgetsPro WidgetsLogin Get Elementor Pro Login WidgetProvide a Login on Any Page of Your Website Design Every Part of the Login Match your website’s design by customizing everything from the button to the background. Customization Options Engage Users in the Login Form Include a custom image, color background, or take it to the next level with an engaging login to a popup. Advanced capabilities Control the User Journey Send users directly to a specific page after they’ve completed their login. Redirection Options Get Inspired by Engaging Logins Explore exceptionally designed websites and get inspired by how they use login forms in new and innovative ways. Learn How to Build the Perfect Login Form Popup Discover how to create, add, and customize a unique login form using Elementor’s Login widget. HOW IT WORKS Explore Other Widgets Take your website to the next level using Pro’s powerful widgets. Animated Headline Animated Headline Slides Widget Slides Nav Menu Widget Nav Menu Call To Action Widget Call to Action',
  'use_cases' =>
  array (
    0 => 'Advanced capabilities Control the User Journey Send users directly to a specific page after they’ve completed their login',
    1 => 'Learn How to Build the Perfect Login Form Popup Discover how to create, add, and customize a unique login form using Elementor’s Login widget',
    2 => 'HOW IT WORKS Explore Other Widgets Take your website to the next level using Pro’s powerful widgets',
    3 => 'Animated Headline Animated Headline Slides Widget Slides Nav Menu Widget Nav Menu Call To Action Widget Call to Action',
    4 => 'The Login Widget makes it easy to create a custom login page, instead of the default WordPress login page',
    5 => 'Create a New Page, and add the Login Widget',
    6 => 'You may also use the Dynamic Tags feature to call from the site’s metadata or custom field',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content options – Configure general content, title, tags, and icons.',
    1 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    2 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    3 => 'Label – Show or Hide the form Label',
    4 => 'Input size – Choose the Form Fields input size.',
    5 => 'Text – Type the button’s text. You may also use the Dynamic Tags feature to call from the site’s metadata or custom field',
    6 => 'Size – Choose the size of the button',
    7 => 'Alignment – Set the button’s alignment',
    8 => 'Redirect After Login – Set to ON or OFF.',
    9 => 'Lost Your Password – Choose whether or not to display the “Lost Your Password” link',
    10 => 'Remember me – Choose whether or not to display the “Remember Me” checkbox',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'Use your regular WordPress login credentials with the Login Widget',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_fields_content',
      'label' => 'Form Fields',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'show_labels',
          'type' => 'switcher',
          'label' => 'Label',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'input_size',
          'type' => 'select',
          'label' => 'Input Size',
          'default' => 'sm',
          'options' =>
          array (
            'xs' => 'Extra Small',
            'sm' => 'Small',
            'md' => 'Medium',
            'lg' => 'Large',
            'xl' => 'Extra Large',
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
      'id' => 'section_button_content',
      'label' => 'Button',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'button_text',
          'type' => 'text',
          'label' => 'Text',
          'default' => 'Log In',
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
          'key' => 'button_size',
          'type' => 'select',
          'label' => 'Size',
          'default' => 'sm',
          'options' =>
          array (
            'xs' => 'Extra Small',
            'sm' => 'Small',
            'md' => 'Medium',
            'lg' => 'Large',
            'xl' => 'Extra Large',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'align',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => '',
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Left',
              'icon' => 'eicon-text-align-left',
            ),
            'center' =>
            array (
              'title' => 'Center',
              'icon' => 'eicon-text-align-center',
            ),
            'end' =>
            array (
              'title' => 'Right',
              'icon' => 'eicon-text-align-right',
            ),
            'stretch' =>
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
      'id' => 'section_login_content',
      'label' => 'Additional Options',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'redirect_after_login',
          'type' => 'switcher',
          'label' => 'Redirect After Login',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'redirect_url',
          'type' => 'url',
          'label' => NULL,
          'default' => NULL,
          'options' => false,
          'condition' =>
          array (
            'redirect_after_login' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => 'Note: Because of security reasons, you can ONLY use your current domain here.',
        ),
        2 =>
        array (
          'key' => 'redirect_after_logout',
          'type' => 'switcher',
          'label' => 'Redirect After Logout',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'redirect_logout_url',
          'type' => 'url',
          'label' => NULL,
          'default' => NULL,
          'options' => false,
          'condition' =>
          array (
            'redirect_after_logout' => 'yes',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => 'Note: Because of security reasons, you can ONLY use your current domain here.',
        ),
        4 =>
        array (
          'key' => 'show_lost_password',
          'type' => 'switcher',
          'label' => 'Lost your password?',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'show_register',
          'type' => 'switcher',
          'label' => 'Register',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'show_remember_me',
          'type' => 'switcher',
          'label' => 'Remember Me',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'show_logged_in_message',
          'type' => 'switcher',
          'label' => 'Logged in Message',
          'default' => 'yes',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'custom_labels',
          'type' => 'switcher',
          'label' => 'Custom Label',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'user_label',
          'type' => 'text',
          'label' => 'Username Label',
          'default' => 'Username or Email Address',
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'show_labels',
                'operator' => '===',
                'value' => 'yes',
              ),
              1 =>
              array (
                'name' => 'custom_labels',
                'operator' => '===',
                'value' => 'yes',
              ),
            ),
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'user_placeholder',
          'type' => 'text',
          'label' => 'Username Placeholder',
          'default' => 'Username or Email Address',
          'options' => NULL,
          'condition' =>
          array (
            'custom_labels' => 'yes',
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
          'key' => 'password_label',
          'type' => 'text',
          'label' => 'Password Label',
          'default' => 'Password',
          'options' => NULL,
          'condition' =>
          array (
            'relation' => 'or',
            'terms' =>
            array (
              0 =>
              array (
                'name' => 'show_labels',
                'operator' => '===',
                'value' => 'yes',
              ),
              1 =>
              array (
                'name' => 'custom_labels',
                'operator' => '===',
                'value' => 'yes',
              ),
            ),
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
          'key' => 'password_placeholder',
          'type' => 'text',
          'label' => 'Password Placeholder',
          'default' => 'Password',
          'options' => NULL,
          'condition' =>
          array (
            'custom_labels' => 'yes',
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
    3 =>
    array (
      'id' => 'section_style',
      'label' => 'Form',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'row_gap',
          'type' => 'slider',
          'label' => 'Rows Gap',
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
        1 =>
        array (
          'key' => 'links_color',
          'type' => 'color',
          'label' => 'Links Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'links_hover_color',
          'type' => 'color',
          'label' => 'Links Hover Color',
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
    4 =>
    array (
      'id' => 'section_style_labels',
      'label' => 'Label',
      'tab' => 'style',
      'condition' =>
      array (
        'show_labels!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'label_spacing',
          'type' => 'slider',
          'label' => 'Spacing',
          'default' =>
          array (
            'size' => 0,
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'label_color',
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
          'name' => 'label_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-form-fields-wrapper label',
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
      'id' => 'section_field_style',
      'label' => 'Fields',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'field_text_color',
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
          'key' => 'field_background_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => '#ffffff',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'field_border_color',
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
          'key' => 'field_border_width',
          'type' => 'dimensions',
          'label' => 'Border Width',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'field_border_radius',
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
          'name' => 'field_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-field-group .elementor-field, {{WRAPPER}} .elementor-field-subgroup label',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    6 =>
    array (
      'id' => 'section_button_style',
      'label' => 'Button',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'button_text_color',
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
          'key' => 'button_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'button_text_padding',
          'type' => 'dimensions',
          'label' => 'Text Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'button_hover_color',
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
          'key' => 'button_hover_border_color',
          'type' => 'color',
          'label' => 'Border Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'button_border_border!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'button_hover_animation',
          'type' => 'hover_animation',
          'label' => 'Animation',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
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
          'selector' => '{{WRAPPER}} .elementor-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' => 'button_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'border',
          'name' => 'button_border',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'background',
          'name' => 'button_background_hover',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-button:hover',
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
    7 =>
    array (
      'id' => 'section_style_message',
      'label' => 'Logged in Message',
      'tab' => 'style',
      'condition' =>
      array (
        'show_logged_in_message' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'message_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'show_logged_in_message' => 'yes',
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
          'name' => 'message_typography',
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'condition' =>
          array (
            'show_logged_in_message' => 'yes',
          ),
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
      'name' => 'label_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-form-fields-wrapper label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'field_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-field-group .elementor-field, {{WRAPPER}} .elementor-field-subgroup label',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'button_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'background',
      'name' => 'button_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'border',
      'name' => 'button_border',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'background',
      'name' => 'button_background_hover',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-button:hover',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'typography',
      'name' => 'message_typography',
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'condition' =>
      array (
        'show_logged_in_message' => 'yes',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'show_labels' =>
    array (
      'section' => 'section_fields_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'input_size' =>
    array (
      'section' => 'section_fields_content',
      'type' => 'select',
      'default' => 'sm',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text' =>
    array (
      'section' => 'section_button_content',
      'type' => 'text',
      'default' => 'Log In',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_size' =>
    array (
      'section' => 'section_button_content',
      'type' => 'select',
      'default' => 'sm',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'align' =>
    array (
      'section' => 'section_button_content',
      'type' => 'choose',
      'default' => '',
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'redirect_after_login' =>
    array (
      'section' => 'section_login_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'redirect_url' =>
    array (
      'section' => 'section_login_content',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'redirect_after_login' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'redirect_after_logout' =>
    array (
      'section' => 'section_login_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'redirect_logout_url' =>
    array (
      'section' => 'section_login_content',
      'type' => 'url',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'redirect_after_logout' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_lost_password' =>
    array (
      'section' => 'section_login_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_register' =>
    array (
      'section' => 'section_login_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_remember_me' =>
    array (
      'section' => 'section_login_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'show_logged_in_message' =>
    array (
      'section' => 'section_login_content',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'custom_labels' =>
    array (
      'section' => 'section_login_content',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'user_label' =>
    array (
      'section' => 'section_login_content',
      'type' => 'text',
      'default' => 'Username or Email Address',
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'show_labels',
            'operator' => '===',
            'value' => 'yes',
          ),
          1 =>
          array (
            'name' => 'custom_labels',
            'operator' => '===',
            'value' => 'yes',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'user_placeholder' =>
    array (
      'section' => 'section_login_content',
      'type' => 'text',
      'default' => 'Username or Email Address',
      'responsive' => false,
      'condition' =>
      array (
        'custom_labels' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'password_label' =>
    array (
      'section' => 'section_login_content',
      'type' => 'text',
      'default' => 'Password',
      'responsive' => false,
      'condition' =>
      array (
        'relation' => 'or',
        'terms' =>
        array (
          0 =>
          array (
            'name' => 'show_labels',
            'operator' => '===',
            'value' => 'yes',
          ),
          1 =>
          array (
            'name' => 'custom_labels',
            'operator' => '===',
            'value' => 'yes',
          ),
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'password_placeholder' =>
    array (
      'section' => 'section_login_content',
      'type' => 'text',
      'default' => 'Password',
      'responsive' => false,
      'condition' =>
      array (
        'custom_labels' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'row_gap' =>
    array (
      'section' => 'section_style',
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
    'links_color' =>
    array (
      'section' => 'section_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'links_hover_color' =>
    array (
      'section' => 'section_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_spacing' =>
    array (
      'section' => 'section_style_labels',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 0,
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_color' =>
    array (
      'section' => 'section_style_labels',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'label_typography_typography' =>
    array (
      'section' => 'section_style_labels',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'label_typography',
    ),
    'field_text_color' =>
    array (
      'section' => 'section_field_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'field_background_color' =>
    array (
      'section' => 'section_field_style',
      'type' => 'color',
      'default' => '#ffffff',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'field_border_color' =>
    array (
      'section' => 'section_field_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'field_border_width' =>
    array (
      'section' => 'section_field_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'field_border_radius' =>
    array (
      'section' => 'section_field_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'field_typography_typography' =>
    array (
      'section' => 'section_field_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'field_typography',
    ),
    'button_text_color' =>
    array (
      'section' => 'section_button_style',
      'type' => 'color',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_border_radius' =>
    array (
      'section' => 'section_button_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_text_padding' =>
    array (
      'section' => 'section_button_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_color' =>
    array (
      'section' => 'section_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_border_color' =>
    array (
      'section' => 'section_button_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'button_border_border!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_animation' =>
    array (
      'section' => 'section_button_style',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'button_hover_transition_duration' =>
    array (
      'section' => 'section_button_style',
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
      'section' => 'section_button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'button_typography',
    ),
    'button_background_background' =>
    array (
      'section' => 'section_button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'button_background',
    ),
    'button_border_border' =>
    array (
      'section' => 'section_button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'button_border',
    ),
    'button_background_hover_background' =>
    array (
      'section' => 'section_button_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'button_background_hover',
    ),
    'message_color' =>
    array (
      'section' => 'section_style_message',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_logged_in_message' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'message_typography_typography' =>
    array (
      'section' => 'section_style_message',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'show_logged_in_message' => 'yes',
      ),
      'group' => 'typography',
      'group_prefix' => 'message_typography',
    ),
  ),
  'group_activators' =>
  array (
    'label_typography_typography' => 'custom',
    'field_typography_typography' => 'custom',
    'button_typography_typography' => 'custom',
    'button_background_background' => 'classic',
    'button_border_border' => 'solid',
    'button_background_hover_background' => 'classic',
    'message_typography_typography' => 'custom',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/login-widget.md',
    1 => 'docs/knowledge/elementor/widgets/login-widget.md',
    2 => 'docs/knowledge/elementor/widgets/login-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/login-widget-pro.md',
  ),
  'control_count' => 43,
);
