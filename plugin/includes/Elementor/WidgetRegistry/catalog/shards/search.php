<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'search',
  'source' => 'pro',
  'widget_type' => 'search',
  'title' => 'Search',
  'icon' => 'eicon-site-search',
  'categories' =>
  array (
    0 => 'pro-elements',
  ),
  'keywords' =>
  array (
    0 => 'search',
  ),
  'file' => 'pro-elements/modules/search/widgets/search.php',
  'intent' => 'If you already have a Loop item template to display the live search results, enter it here.If you don’t have a Loop item template ready, click Create Template to create a Loop item.If you already have a Loop item ready to display the live search results, the Create template button will change to Edit template.If you want to display live search results, you must create a Loop Item to display the results. Query',
  'use_cases' =>
  array (
    0 => 'All available widgets are displayed',
    1 => 'Click or drag the widget to the canvas',
    2 => 'For more information, see Add elements to a page',
    3 => 'What is the Search widget',
    4 => 'What is the Search Form widget',
  ),
  'settings_highlights' =>
  array (
    0 => 'To access and use a widget – In Elementor Editor, click +. All available widgets are displayed.Click or drag the widget to the canvas. For more information, see Add elements to a page.',
    1 => 'Adding a Search widget – Step-by-step',
    2 => 'Placeholder – Text that appears in the search box by default.',
    3 => 'Icon – You have the option of adding an icon to the search box. For details, see Adding images and icons.',
    4 => 'Autocomplete – Toggle to Show if you want your site to offer potential search terms as visitors are entering text into the search box.',
    5 => 'Icon – Select an icon that appears in the search bar. Clicking this icon deletes all text in the search box. You can elect to have no icon. For details, see Adding images and icons',
    6 => 'Trigger – The action that will start the search. Use the dropdown menu to select:',
    7 => 'Text – The text that appears on the Submit button, if you use a submit button.',
    8 => 'Adding a Search Form widget – Step-by-step',
    9 => 'Search forms can have three skins – Classic – A search box with a search buttonMinimal – A search boxFull Screen – a toggle that when clicked turns into a full page search form.',
    10 => 'Content options – Configure general content, title, tags, and icons.',
    11 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    12 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
  ),
  'limits' =>
  array (
    0 => 'Query allows you to limit what type of content the search results will display.',
    1 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    2 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'content_section_search_field',
      'label' => 'Search Field',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_input',
          'type' => 'heading',
          'label' => 'Input',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'search_input_placeholder_text',
          'type' => 'text',
          'label' => 'Placeholder',
          'default' => 'Type to start searching...',
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
          'key' => 'icon_search',
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
          'key' => 'autocomplete',
          'type' => 'switcher',
          'label' => 'Autocomplete',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'heading_clear',
          'type' => 'heading',
          'label' => 'Clear',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'icon_clear',
          'type' => 'icons',
          'label' => 'Icon',
          'default' =>
          array (
            'value' => 'fas fa-times',
            'library' => 'fa-solid',
          ),
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'heading_submit',
          'type' => 'heading',
          'label' => 'Submit',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'submit_trigger',
          'type' => 'select',
          'label' => 'Trigger',
          'default' => 'click_submit',
          'options' =>
          array (
            'click_submit' => 'Submit button',
            'key_enter' => 'Enter key',
            'both' => 'Both',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'submit_button_text',
          'type' => 'text',
          'label' => 'Text',
          'default' => 'Search',
          'options' => NULL,
          'condition' =>
          array (
            'submit_trigger!' => 'key_enter',
          ),
          'dynamic' =>
          array (
            'active' => true,
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'icon_submit',
          'type' => 'icons',
          'label' => 'Icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'submit_trigger!' => 'key_enter',
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
      'id' => 'content_section_results',
      'label' => 'Results',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'live_results',
          'type' => 'switcher',
          'label' => 'Live Results',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'template_id',
          'type' =>
          array (
            '__unresolved__' => 'Template_Query::CONTROL_ID',
          ),
          'label' => 'Choose a template',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'minimum_search_characters',
          'type' => 'number',
          'label' => 'Minimum search characters',
          'default' => 3,
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'number_of_columns',
          'type' => 'number',
          'label' => 'Columns',
          'default' => 1,
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'number_of_items',
          'type' => 'number',
          'label' => 'Items',
          'default' => 6,
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'equal_height',
          'type' => 'switcher',
          'label' => 'Equal Height',
          'default' => '',
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'enable_loader',
          'type' => 'switcher',
          'label' => 'Loader',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'enable_nothing_found_message',
          'type' => 'switcher',
          'label' => 'Nothing Found Message',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'nothing_found_message_description',
          'type' => 'raw_html',
          'label' => NULL,
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => '->get_nothing_found_conditions()',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'nothing_found_message_text',
          'type' => 'textarea',
          'label' => NULL,
          'default' => 'It seems we can’t find what you’re looking for.',
          'options' => NULL,
          'condition' =>
          array (
            '__unresolved__' => '->get_nothing_found_conditions()',
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
          'key' => 'nothing_found_message_html_tag',
          'type' => 'select',
          'label' => 'HTML Tag',
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
          ),
          'condition' =>
          array (
            '__unresolved__' => '->get_nothing_found_conditions()',
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
      'id' => 'content_section_query',
      'label' => 'Query',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
      ),
      'group_controls' =>
      array (
        0 =>
        array (
          'group' => NULL,
          'name' => 'search_query',
          'label' => NULL,
          'selector' => NULL,
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'avoid_duplicates',
            1 => 'offset',
            2 => 'posts_per_page',
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
      'id' => 'content_section_additional_settings',
      'label' => 'Additional Settings',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_pagination',
          'type' => 'heading',
          'label' => 'Pagination',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'pagination_type_options',
          'type' => 'select',
          'label' => 'Type',
          'default' => 'none',
          'options' =>
          array (
            'none' => 'None',
            'numbers' => 'Numbers',
            'previous_next' => 'Previous/Next',
            'numbers_previous_next' => 'Numbers + Previous/Next',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'pagination_prev_label',
          'type' => NULL,
          'label' => 'Previous Label',
          'default' => 'Previous',
          'options' => NULL,
          'condition' =>
          array (
            'pagination_type_options' =>
            array (
              0 => 'previous_next',
              1 => 'numbers_previous_next',
            ),
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
          'key' => 'pagination_next_label',
          'type' => NULL,
          'label' => 'Next Label',
          'default' => 'Next',
          'options' => NULL,
          'condition' =>
          array (
            'pagination_type_options' =>
            array (
              0 => 'previous_next',
              1 => 'numbers_previous_next',
            ),
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
          'key' => 'page_limit_settings',
          'type' => 'number',
          'label' => 'Page Limit',
          'default' => 5,
          'options' => NULL,
          'condition' =>
          array (
            'pagination_type_options' =>
            array (
              0 => 'numbers_previous_next',
              1 => 'numbers',
              2 => 'previous_next',
            ),
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'pagination_shorten_settings',
          'type' => 'switcher',
          'label' => 'Shorten',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'pagination_type_options' =>
            array (
              0 => 'numbers_previous_next',
              1 => 'numbers',
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
    4 =>
    array (
      'id' => 'section_search_field_style',
      'label' => 'Search Field',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'placeholder_color',
          'type' => 'color',
          'label' => 'Placeholder Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'search_field_search_input_transition',
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
        2 =>
        array (
          'key' => 'search_field_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'search_field_padding',
          'type' => 'dimensions',
          'label' => 'Padding',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'search_field_icon_label_size',
          'type' => 'slider',
          'label' => 'Icon Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_search[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'search_field_icon_gap',
          'type' => 'slider',
          'label' => 'Gap between text and icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_search[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'search_field_submit_gap',
          'type' => 'slider',
          'label' => 'Gap between input and button',
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
          'name' => 'search_field_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-search-input',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
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
          'name' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
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
      'id' => 'style_section_clear',
      'label' => 'Clear',
      'tab' => 'style',
      'condition' =>
      array (
        'icon_clear[value]!' => '',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon_clear_size',
          'type' => 'slider',
          'label' => 'Icon Size',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'icon_clear_normal_color',
          'type' => 'color',
          'label' => 'Icon Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'icon_clear_hover_color',
          'type' => 'color',
          'label' => 'Icon Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'icon_clear_hover_transition',
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
      ),
      'repeaters' =>
      array (
      ),
    ),
    6 =>
    array (
      'id' => 'style_section_submit',
      'label' => 'Submit Button',
      'tab' => 'style',
      'condition' =>
      array (
        'submit_trigger!' => 'key_enter',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'icon_submit_position',
          'type' => 'choose',
          'label' => 'Icon Position',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start (before)',
              'icon' => 'eicon-h-align-left',
            ),
            'end' =>
            array (
              'title' => 'End (after)',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'icon_submit[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'icon_submit_size',
          'type' => 'slider',
          'label' => 'Icon Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_submit[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'icon_submit_gap',
          'type' => 'slider',
          'label' => 'Gap between text and icon',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'icon_submit[value]!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'submit_hover_animation',
          'type' => 'hover_animation',
          'label' => 'Hover animation',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'submit_hover_transition',
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
        5 =>
        array (
          'key' => 'submit_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'submit_padding',
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
          'name' => 'submit_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-search-submit span',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'background',
          'name' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
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
          'name' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'box-shadow',
          'name' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'label' => NULL,
          'selector' =>
          array (
            '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
          ),
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    7 =>
    array (
      'id' => 'style_section_results',
      'label' => 'Results',
      'tab' => 'style',
      'condition' =>
      array (
        'live_results' => 'yes',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'results_border_radius',
          'type' => 'dimensions',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'results_padding',
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
          'key' => 'results_distance_from_search_field',
          'type' => 'slider',
          'label' => 'Distance from search field',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'results_is_dropdown_width',
          'type' => 'select',
          'label' => 'Dropdown Width',
          'default' => 'search_field',
          'options' =>
          array (
            'search_field' => 'Search Field',
            'widget_width' => 'Widget Width',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'results_is_custom_width',
          'type' => 'switcher',
          'label' => 'Custom Width',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'results_custom_width',
          'type' => 'slider',
          'label' => 'Width',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'results_is_custom_width' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'results_max_height',
          'type' => 'slider',
          'label' => 'Max height',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'results_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            'start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'center' =>
            array (
              'title' => 'Middle',
              'icon' => 'eicon-h-align-center',
            ),
            'end' =>
            array (
              'title' => 'End',
              'icon' => 'eicon-h-align-right',
            ),
          ),
          'condition' =>
          array (
            'results_is_custom_width' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'search_result_column_gap',
          'type' => 'slider',
          'label' => 'Gap between columns',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'search_result_row_gap',
          'type' => 'slider',
          'label' => 'Gap between rows',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        10 =>
        array (
          'key' => 'heading_search_reasult_loader',
          'type' => 'heading',
          'label' => 'Loader',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
            'enable_loader' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        11 =>
        array (
          'key' => 'search_result_loader_icon_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
            'enable_loader' => 'yes',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        12 =>
        array (
          'key' => 'search_result_loader_icon_size',
          'type' => 'slider',
          'label' => 'Icon Size',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'live_results' => 'yes',
            'template_id!' => '',
            'enable_loader' => 'yes',
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
          'group' => 'background',
          'name' => 'results_background',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-search-results-container',
          'condition' => NULL,
          'exclude' =>
          array (
            0 => 'image',
          ),
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'border',
          'name' => 'results_border_type',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-search-results-container > div',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'box-shadow',
          'name' => 'results_box_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-search-results-container > div',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    8 =>
    array (
      'id' => 'style_additional_settings',
      'label' => 'Additional Settings',
      'tab' => 'style',
      'condition' =>
      array (
        'pagination_type_options' =>
        array (
          0 => 'numbers_previous_next',
          1 => 'previous_next',
          2 => 'numbers',
        ),
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'heading_style_additional_settings',
          'type' => 'heading',
          'label' => 'Pagination',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'style_additional_settings_alignment',
          'type' => 'choose',
          'label' => 'Alignment',
          'default' => NULL,
          'options' =>
          array (
            'flex-start' =>
            array (
              'title' => 'Start',
              'icon' => 'eicon-h-align-left',
            ),
            'center' =>
            array (
              'title' => 'Middle',
              'icon' => 'eicon-h-align-center',
            ),
            'flex-end' =>
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
        2 =>
        array (
          'key' => 'style_additional_settings_vertical_position',
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
        3 =>
        array (
          'key' => 'heading_style_additional_settings_colors',
          'type' => 'heading',
          'label' => 'Colors',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'style_additional_settings_normal_color',
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
          'key' => 'style_additional_settings_hover_color',
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
          'key' => 'style_button_color_icon_active',
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
          'key' => 'page_numbers_space_between',
          'type' => 'slider',
          'label' => 'Space Between',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'top_spacing',
          'type' => 'slider',
          'label' => 'Top Spacing',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        9 =>
        array (
          'key' => 'bottom_spacing',
          'type' => 'slider',
          'label' => 'Bottom Spacing',
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
          'name' => 'typography_title',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-pagination',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
      ),
      'repeaters' =>
      array (
      ),
    ),
    9 =>
    array (
      'id' => 'style_section_nothing_found_message',
      'label' => 'Nothing Found Message',
      'tab' => 'style',
      'condition' =>
      array (
        '__unresolved__' => '->get_nothing_found_conditions()',
      ),
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'nothing_found_message_space_from_top',
          'type' => 'slider',
          'label' => 'Space from top',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'nothing_found_message_space_from_bottom',
          'type' => 'slider',
          'label' => 'Space from bottom',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'nothing_found_message_alignment',
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
              'title' => 'Middle',
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
        3 =>
        array (
          'key' => 'nothing_found_message_color',
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
          'name' => 'nothing_found_message_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-search-nothing-found-message',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'nothing_found_message_text_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-search-nothing-found-message',
          'condition' => NULL,
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'text-stroke',
          'name' => 'nothing_found_message_text_stroke',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .e-search-nothing-found-message',
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
      'group' => NULL,
      'name' => 'search_query',
      'label' => NULL,
      'selector' => NULL,
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'avoid_duplicates',
        1 => 'offset',
        2 => 'posts_per_page',
      ),
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'typography',
      'name' => 'search_field_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-search-input',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'background',
      'name' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'border',
      'name' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    4 =>
    array (
      'group' => 'box-shadow',
      'name' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    5 =>
    array (
      'group' => 'typography',
      'name' => 'submit_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-search-submit span',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    6 =>
    array (
      'group' => 'background',
      'name' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    7 =>
    array (
      'group' => 'border',
      'name' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    8 =>
    array (
      'group' => 'box-shadow',
      'name' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'label' => NULL,
      'selector' =>
      array (
        '__unresolved__' => 'PhpParser\\Node\\Scalar\\InterpolatedString',
      ),
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    9 =>
    array (
      'group' => 'background',
      'name' => 'results_background',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-search-results-container',
      'condition' => NULL,
      'exclude' =>
      array (
        0 => 'image',
      ),
      'include' => NULL,
    ),
    10 =>
    array (
      'group' => 'border',
      'name' => 'results_border_type',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-search-results-container > div',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    11 =>
    array (
      'group' => 'box-shadow',
      'name' => 'results_box_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-search-results-container > div',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    12 =>
    array (
      'group' => 'typography',
      'name' => 'typography_title',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-pagination',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    13 =>
    array (
      'group' => 'typography',
      'name' => 'nothing_found_message_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-search-nothing-found-message',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    14 =>
    array (
      'group' => 'text-shadow',
      'name' => 'nothing_found_message_text_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-search-nothing-found-message',
      'condition' => NULL,
      'exclude' => NULL,
      'include' => NULL,
    ),
    15 =>
    array (
      'group' => 'text-stroke',
      'name' => 'nothing_found_message_text_stroke',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .e-search-nothing-found-message',
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
    'heading_input' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_input_placeholder_text' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'text',
      'default' => 'Type to start searching...',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_search' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'autocomplete' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_clear' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_clear' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'icons',
      'default' =>
      array (
        'value' => 'fas fa-times',
        'library' => 'fa-solid',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_submit' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'submit_trigger' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'select',
      'default' => 'click_submit',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'submit_button_text' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'text',
      'default' => 'Search',
      'responsive' => false,
      'condition' =>
      array (
        'submit_trigger!' => 'key_enter',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_submit' =>
    array (
      'section' => 'content_section_search_field',
      'type' => 'icons',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'submit_trigger!' => 'key_enter',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'live_results' =>
    array (
      'section' => 'content_section_results',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'template_id' =>
    array (
      'section' => 'content_section_results',
      'type' =>
      array (
        '__unresolved__' => 'Template_Query::CONTROL_ID',
      ),
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'live_results' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'minimum_search_characters' =>
    array (
      'section' => 'content_section_results',
      'type' => 'number',
      'default' => 3,
      'responsive' => false,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'number_of_columns' =>
    array (
      'section' => 'content_section_results',
      'type' => 'number',
      'default' => 1,
      'responsive' => true,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'number_of_items' =>
    array (
      'section' => 'content_section_results',
      'type' => 'number',
      'default' => 6,
      'responsive' => true,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'equal_height' =>
    array (
      'section' => 'content_section_results',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'enable_loader' =>
    array (
      'section' => 'content_section_results',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'enable_nothing_found_message' =>
    array (
      'section' => 'content_section_results',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_message_description' =>
    array (
      'section' => 'content_section_results',
      'type' => 'raw_html',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => '->get_nothing_found_conditions()',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_message_text' =>
    array (
      'section' => 'content_section_results',
      'type' => 'textarea',
      'default' => 'It seems we can’t find what you’re looking for.',
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => '->get_nothing_found_conditions()',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_message_html_tag' =>
    array (
      'section' => 'content_section_results',
      'type' => 'select',
      'default' => 'div',
      'responsive' => false,
      'condition' =>
      array (
        '__unresolved__' => '->get_nothing_found_conditions()',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_pagination' =>
    array (
      'section' => 'content_section_additional_settings',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pagination_type_options' =>
    array (
      'section' => 'content_section_additional_settings',
      'type' => 'select',
      'default' => 'none',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pagination_prev_label' =>
    array (
      'section' => 'content_section_additional_settings',
      'type' => NULL,
      'default' => 'Previous',
      'responsive' => false,
      'condition' =>
      array (
        'pagination_type_options' =>
        array (
          0 => 'previous_next',
          1 => 'numbers_previous_next',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pagination_next_label' =>
    array (
      'section' => 'content_section_additional_settings',
      'type' => NULL,
      'default' => 'Next',
      'responsive' => false,
      'condition' =>
      array (
        'pagination_type_options' =>
        array (
          0 => 'previous_next',
          1 => 'numbers_previous_next',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'page_limit_settings' =>
    array (
      'section' => 'content_section_additional_settings',
      'type' => 'number',
      'default' => 5,
      'responsive' => false,
      'condition' =>
      array (
        'pagination_type_options' =>
        array (
          0 => 'numbers_previous_next',
          1 => 'numbers',
          2 => 'previous_next',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'pagination_shorten_settings' =>
    array (
      'section' => 'content_section_additional_settings',
      'type' => 'switcher',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'pagination_type_options' =>
        array (
          0 => 'numbers_previous_next',
          1 => 'numbers',
        ),
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'placeholder_color' =>
    array (
      'section' => 'section_search_field_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_field_search_input_transition' =>
    array (
      'section' => 'section_search_field_style',
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
    'search_field_border_radius' =>
    array (
      'section' => 'section_search_field_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_field_padding' =>
    array (
      'section' => 'section_search_field_style',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_field_icon_label_size' =>
    array (
      'section' => 'section_search_field_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'icon_search[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_field_icon_gap' =>
    array (
      'section' => 'section_search_field_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'icon_search[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_field_submit_gap' =>
    array (
      'section' => 'section_search_field_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_field_typography_typography' =>
    array (
      'section' => 'section_search_field_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'search_field_typography',
    ),
    'icon_clear_size' =>
    array (
      'section' => 'style_section_clear',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_clear_normal_color' =>
    array (
      'section' => 'style_section_clear',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_clear_hover_color' =>
    array (
      'section' => 'style_section_clear',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_clear_hover_transition' =>
    array (
      'section' => 'style_section_clear',
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
    'icon_submit_position' =>
    array (
      'section' => 'style_section_submit',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'icon_submit[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_submit_size' =>
    array (
      'section' => 'style_section_submit',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'icon_submit[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'icon_submit_gap' =>
    array (
      'section' => 'style_section_submit',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'icon_submit[value]!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'submit_hover_animation' =>
    array (
      'section' => 'style_section_submit',
      'type' => 'hover_animation',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'submit_hover_transition' =>
    array (
      'section' => 'style_section_submit',
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
    'submit_border_radius' =>
    array (
      'section' => 'style_section_submit',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'submit_padding' =>
    array (
      'section' => 'style_section_submit',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'submit_typography_typography' =>
    array (
      'section' => 'style_section_submit',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'submit_typography',
    ),
    'results_border_radius' =>
    array (
      'section' => 'style_section_results',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'results_padding' =>
    array (
      'section' => 'style_section_results',
      'type' => 'dimensions',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'results_distance_from_search_field' =>
    array (
      'section' => 'style_section_results',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'results_is_dropdown_width' =>
    array (
      'section' => 'style_section_results',
      'type' => 'select',
      'default' => 'search_field',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'results_is_custom_width' =>
    array (
      'section' => 'style_section_results',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'results_custom_width' =>
    array (
      'section' => 'style_section_results',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'results_is_custom_width' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'results_max_height' =>
    array (
      'section' => 'style_section_results',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'results_alignment' =>
    array (
      'section' => 'style_section_results',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'results_is_custom_width' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_result_column_gap' =>
    array (
      'section' => 'style_section_results',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_result_row_gap' =>
    array (
      'section' => 'style_section_results',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_search_reasult_loader' =>
    array (
      'section' => 'style_section_results',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
        'enable_loader' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_result_loader_icon_color' =>
    array (
      'section' => 'style_section_results',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
        'enable_loader' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'search_result_loader_icon_size' =>
    array (
      'section' => 'style_section_results',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' =>
      array (
        'live_results' => 'yes',
        'template_id!' => '',
        'enable_loader' => 'yes',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'results_background_background' =>
    array (
      'section' => 'style_section_results',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'background',
      'group_prefix' => 'results_background',
    ),
    'results_border_type_border' =>
    array (
      'section' => 'style_section_results',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'border',
      'group_prefix' => 'results_border_type',
    ),
    'results_box_shadow_box_shadow' =>
    array (
      'section' => 'style_section_results',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'box-shadow',
      'group_prefix' => 'results_box_shadow',
    ),
    'heading_style_additional_settings' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_additional_settings_alignment' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_additional_settings_vertical_position' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'heading_style_additional_settings_colors' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_additional_settings_normal_color' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_additional_settings_hover_color' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'style_button_color_icon_active' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'page_numbers_space_between' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'top_spacing' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bottom_spacing' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_title_typography' =>
    array (
      'section' => 'style_additional_settings',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'typography_title',
    ),
    'nothing_found_message_space_from_top' =>
    array (
      'section' => 'style_section_nothing_found_message',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_message_space_from_bottom' =>
    array (
      'section' => 'style_section_nothing_found_message',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_message_alignment' =>
    array (
      'section' => 'style_section_nothing_found_message',
      'type' => 'choose',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_message_color' =>
    array (
      'section' => 'style_section_nothing_found_message',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'nothing_found_message_typography_typography' =>
    array (
      'section' => 'style_section_nothing_found_message',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'typography',
      'group_prefix' => 'nothing_found_message_typography',
    ),
    'nothing_found_message_text_shadow_text_shadow' =>
    array (
      'section' => 'style_section_nothing_found_message',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-shadow',
      'group_prefix' => 'nothing_found_message_text_shadow',
    ),
    'nothing_found_message_text_stroke_text_stroke' =>
    array (
      'section' => 'style_section_nothing_found_message',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => 'text-stroke',
      'group_prefix' => 'nothing_found_message_text_stroke',
    ),
  ),
  'group_activators' =>
  array (
    'search_field_typography_typography' => 'custom',
    'submit_typography_typography' => 'custom',
    'results_background_background' => 'classic',
    'results_border_type_border' => 'solid',
    'results_box_shadow_box_shadow' => 'yes',
    'typography_title_typography' => 'custom',
    'nothing_found_message_typography_typography' => 'custom',
    'nothing_found_message_text_shadow_text_shadow' => 'yes',
    'nothing_found_message_text_stroke_text_stroke' => 'yes',
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/search-widget.md',
    1 => 'docs/knowledge/elementor/widgets/search-widget.md',
    2 => 'docs/knowledge/elementor/widgets/search-form-widget.md',
  ),
  'control_count' => 81,
);
