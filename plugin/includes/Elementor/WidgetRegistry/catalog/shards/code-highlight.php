<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'code-highlight',
  'source' => 'pro',
  'widget_type' => 'code-highlight',
  'title' => 'Code Highlight',
  'icon' => 'eicon-code-highlight',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'code',
    1 => 'highlight',
    2 => 'syntax',
    3 => 'highlighter',
    4 => 'javascript',
    5 => 'css',
    6 => 'php',
    7 => 'html',
    8 => 'java',
    9 => 'js',
  ),
  'file' => 'pro-elements/modules/code-highlight/widgets/code-highlight.php',
  'intent' => 'The Code Highlight widget displays formatted code snippets with syntax highlighting for better readability. It is designed to showcase code samples on web pages with proper formatting and visual distinction of different code elements, making it ideal for developer documentation or technical blogs.',
  'use_cases' =>
  array (
    0 => 'Displaying code examples or tutorials on your website',
    1 => 'Creating documentation pages that reference code snippets',
    2 => 'Teaching programming concepts with visual code samples',
    3 => 'Building developer resource pages or technical blogs',
    4 => 'Showcasing API examples or configuration code',
    5 => 'Organizing your layout design and structuring content elements inside Elementor.',
    6 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    7 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Language selection for syntax highlighting (multiple languages supported)',
    1 => 'Code input area for pasting or writing code',
    2 => 'Theme options for code appearance',
    3 => 'Line numbers toggle',
    4 => 'Copy-to-clipboard functionality',
    5 => 'Font size and family customization',
    6 => 'Background color options',
    7 => 'Border and padding controls',
    8 => 'Responsive text scaling',
    9 => 'Language – Select your coding language to properly highlight your code’s syntax. Options include 50+ of supported languages such as Markup, HTML, XML, SVG, Mathml, CSS, Javascript, ActionScript, PHP, Ruby, etc',
    10 => 'Code – Enter the snippet of code that you wish to display on the page in the field. This may also use Dynamic Tag data from ACF or other plugins',
    11 => 'Line Numbers – Choose Show to display line numbers next to each line',
    12 => 'Copy to Clipboard – Choose Show to allow users to copy the code with a single click on the Copy button in the upper right corner of the code box',
    13 => 'Highlight Lines – Designate one or more lines to be highlighted by entering a value or using a Dynamic Tag. Sets of numbers should be separated by a comma. (e.g. 2-7, 10, 13-15 would highlight lines 2 through 7, line 10, and lines 13 through 15.)',
    14 => 'Word Wrap – Toggle to Show/Hide word wrapping',
    15 => 'Theme – Select a theme for the code display. Both light and dark themes are available to choose from',
    16 => 'Height – Enter a height for the code box',
  ),
  'limits' =>
  array (
    0 => 'Very long code blocks may require scrolling on mobile devices',
    1 => 'Some programming languages may have limited syntax highlighting support',
    2 => 'Performance may be affected with multiple large code blocks on one page',
    3 => 'The widget requires proper code formatting for optimal display',
    4 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    5 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_content',
      'label' => 'Code Highlight',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'language',
          'type' => 'select2',
          'label' => 'Language',
          'default' => 'javascript',
          'options' =>
          array (
            '__unresolved__' => '$language_option',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'code',
          'type' => 'code',
          'label' => 'Code',
          'default' => 'console.log( \'Code is Poetry\' );',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' =>
          array (
            'active' => true,
            'categories' =>
            array (
              0 =>
              array (
                '__unresolved__' => 'TagsModule::TEXT_CATEGORY',
              ),
            ),
          ),
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'line_numbers',
          'type' => 'switcher',
          'label' => 'Line Numbers',
          'default' => 'line-numbers',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'copy_to_clipboard',
          'type' => 'switcher',
          'label' => 'Copy to Clipboard',
          'default' => 'copy-to-clipboard',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'highlight_lines',
          'type' => 'text',
          'label' => 'Highlight Lines',
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
        5 =>
        array (
          'key' => 'word_wrap',
          'type' => 'switcher',
          'label' => 'Word Wrap',
          'default' => '',
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'theme',
          'type' => 'select',
          'label' => 'Theme',
          'default' => 'default',
          'options' =>
          array (
            'default' => 'Solid',
            'dark' => 'Dark',
            'okaidia' => 'Okaidia',
            'solarizedlight' => 'Solarizedlight',
            'tomorrow' => 'Tomorrow',
            'twilight' => 'Twilight',
          ),
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => true,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'font_size',
          'type' => 'slider',
          'label' => 'Font Size',
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
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'language' =>
    array (
      'section' => 'section_content',
      'type' => 'select2',
      'default' => 'javascript',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'code' =>
    array (
      'section' => 'section_content',
      'type' => 'code',
      'default' => 'console.log( \'Code is Poetry\' );',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'line_numbers' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => 'line-numbers',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'copy_to_clipboard' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => 'copy-to-clipboard',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'highlight_lines' =>
    array (
      'section' => 'section_content',
      'type' => 'text',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'word_wrap' =>
    array (
      'section' => 'section_content',
      'type' => 'switcher',
      'default' => '',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'theme' =>
    array (
      'section' => 'section_content',
      'type' => 'select',
      'default' => 'default',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'height' =>
    array (
      'section' => 'section_content',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => true,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'font_size' =>
    array (
      'section' => 'section_content',
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
  ),
  'required_for_render' =>
  array (
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/code-highlight.md',
    1 => 'docs/knowledge/elementor/widgets/code-highlight.md',
    2 => 'docs/knowledge/elementor/widgets/code-highlight-pro.md',
  ),
  'control_count' => 9,
);
