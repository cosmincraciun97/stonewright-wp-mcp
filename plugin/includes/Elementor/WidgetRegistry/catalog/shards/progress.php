<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'progress',
  'source' => 'free',
  'widget_type' => 'progress',
  'title' => 'Progress Bar',
  'icon' => 'eicon-skill-bar',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'progress',
    1 => 'bar',
  ),
  'file' => 'elementor/includes/widgets/progress.php',
  'intent' => 'The Progress Bar Widget allows you to add fully styled, animated progress bars to your page.',
  'use_cases' =>
  array (
    0 => 'This helps search engines find and understand the progress bar, boosting SEO',
    1 => 'The title can also be tagged as a paragraph, span or div',
    2 => 'Organizing your layout design and structuring content elements inside Elementor.',
    3 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    4 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Title – Enter the title text that is displayed above the progress bar',
    1 => 'Title HTML Tag – Use the dropdown menu to designate the title of the progress bar as a header (H1-6).This helps search engines find and understand the progress bar, boosting SEO. The title can also be tagged as a paragraph, span or div',
    2 => 'Percentage – Set the completion percentage number',
    3 => 'Display Percentage – Use the switch to show or hide the actual percentage number text at the end of the bar',
    4 => 'Inner Text – Enter the text to be displayed within the bar',
    5 => 'Color – Change the color of the bar',
    6 => 'Background – Choose the color of the background of the bar',
    7 => 'Inner Text Color – Choose the color of the text to be displayed within the bar',
    8 => 'Tracker Type – From the dropdown menu select between Horizontal, or Circular',
    9 => 'Progress Relative To – Select between Entire Page, Post Content, or Selector from the dropdown menu',
    10 => 'Direction – Select the appropriate alignment icon',
    11 => 'Percentage – Use the toggle to choose to hide/show the percentage text of the progress',
    12 => 'Size – Use the slider control or manually enter the value desired',
    13 => 'Color – Use the color picker to set the progress indicator color',
    14 => 'Width – Use the slider control or manually enter the width for the progress indicator (PX)',
    15 => 'Alignment – Use the appropriate icon to set the alignment of the progress indicator',
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
      'id' => 'section_progress',
      'label' => 'Progress Bar',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title',
          'type' => 'text',
          'label' => 'Title',
          'default' => 'My Skill',
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
          'key' => 'title_tag',
          'type' => 'select',
          'label' => 'Title HTML Tag',
          'default' => 'span',
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
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'title_display',
          'type' => 'switcher',
          'label' => 'Display Title',
          'default' => 'yes',
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'progress_type',
          'type' => 'select',
          'label' => 'Type',
          'default' => '',
          'options' =>
          array (
            '' => 'Default',
            'info' => 'Info',
            'success' => 'Success',
            'warning' => 'Warning',
            'danger' => 'Danger',
          ),
          'condition' =>
          array (
            'progress_type!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        4 =>
        array (
          'key' => 'percent',
          'type' => 'slider',
          'label' => 'Percentage',
          'default' =>
          array (
            'size' => 50,
            'unit' => '%',
          ),
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
          'key' => 'display_percentage',
          'type' => 'switcher',
          'label' => 'Display Percentage',
          'default' => 'show',
          'options' => NULL,
          'condition' =>
          array (
            'percent!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'inner_text',
          'type' => 'text',
          'label' => 'Inner Text',
          'default' => 'Web Designer',
          'options' => NULL,
          'condition' => NULL,
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
      'id' => 'section_progress_style',
      'label' => 'Progress Bar',
      'tab' => 'style',
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'title_heading',
          'type' => 'heading',
          'label' => 'Title',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        1 =>
        array (
          'key' => 'title_color',
          'type' => 'color',
          'label' => 'Text Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'title!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        2 =>
        array (
          'key' => 'percentage_heading',
          'type' => 'heading',
          'label' => 'Percentage',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        3 =>
        array (
          'key' => 'bar_color',
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
          'key' => 'bar_bg_color',
          'type' => 'color',
          'label' => 'Background Color',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        5 =>
        array (
          'key' => 'bar_height',
          'type' => 'slider',
          'label' => 'Height',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        6 =>
        array (
          'key' => 'bar_border_radius',
          'type' => 'slider',
          'label' => 'Border Radius',
          'default' => NULL,
          'options' => NULL,
          'condition' => NULL,
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        7 =>
        array (
          'key' => 'inner_text_heading',
          'type' => 'heading',
          'label' => 'Inner Text',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'inner_text!' => '',
          ),
          'dynamic' => NULL,
          'responsive' => false,
          'description' => NULL,
        ),
        8 =>
        array (
          'key' => 'bar_inline_color',
          'type' => 'color',
          'label' => 'Color',
          'default' => NULL,
          'options' => NULL,
          'condition' =>
          array (
            'inner_text!' => '',
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
          'name' => 'typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-title',
          'condition' =>
          array (
            'title!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        1 =>
        array (
          'group' => 'text-shadow',
          'name' => 'title_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-title',
          'condition' =>
          array (
            'title!' => '',
          ),
          'exclude' => NULL,
          'include' => NULL,
        ),
        2 =>
        array (
          'group' => 'typography',
          'name' => 'bar_inner_typography',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-progress-bar',
          'condition' =>
          array (
            'inner_text!' => '',
          ),
          'exclude' =>
          array (
            0 => 'line_height',
          ),
          'include' => NULL,
        ),
        3 =>
        array (
          'group' => 'text-shadow',
          'name' => 'bar_inner_shadow',
          'label' => NULL,
          'selector' => '{{WRAPPER}} .elementor-progress-bar',
          'condition' =>
          array (
            'inner_text!' => '',
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
      'name' => 'typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-title',
      'condition' =>
      array (
        'title!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    1 =>
    array (
      'group' => 'text-shadow',
      'name' => 'title_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-title',
      'condition' =>
      array (
        'title!' => '',
      ),
      'exclude' => NULL,
      'include' => NULL,
    ),
    2 =>
    array (
      'group' => 'typography',
      'name' => 'bar_inner_typography',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-progress-bar',
      'condition' =>
      array (
        'inner_text!' => '',
      ),
      'exclude' =>
      array (
        0 => 'line_height',
      ),
      'include' => NULL,
    ),
    3 =>
    array (
      'group' => 'text-shadow',
      'name' => 'bar_inner_shadow',
      'label' => NULL,
      'selector' => '{{WRAPPER}} .elementor-progress-bar',
      'condition' =>
      array (
        'inner_text!' => '',
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
    'title' =>
    array (
      'section' => 'section_progress',
      'type' => 'text',
      'default' => 'My Skill',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_tag' =>
    array (
      'section' => 'section_progress',
      'type' => 'select',
      'default' => 'span',
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_display' =>
    array (
      'section' => 'section_progress',
      'type' => 'switcher',
      'default' => 'yes',
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'progress_type' =>
    array (
      'section' => 'section_progress',
      'type' => 'select',
      'default' => '',
      'responsive' => false,
      'condition' =>
      array (
        'progress_type!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'percent' =>
    array (
      'section' => 'section_progress',
      'type' => 'slider',
      'default' =>
      array (
        'size' => 50,
        'unit' => '%',
      ),
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'display_percentage' =>
    array (
      'section' => 'section_progress',
      'type' => 'switcher',
      'default' => 'show',
      'responsive' => false,
      'condition' =>
      array (
        'percent!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_text' =>
    array (
      'section' => 'section_progress',
      'type' => 'text',
      'default' => 'Web Designer',
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_heading' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'title_color' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'percentage_heading' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bar_color' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bar_bg_color' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bar_height' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bar_border_radius' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'slider',
      'default' => NULL,
      'responsive' => false,
      'condition' => NULL,
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'inner_text_heading' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'heading',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'inner_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'bar_inline_color' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'color',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'inner_text!' => '',
      ),
      'group' => NULL,
      'group_prefix' => NULL,
    ),
    'typography_typography' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'typography',
    ),
    'title_shadow_text_shadow' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'title!' => '',
      ),
      'group' => 'text-shadow',
      'group_prefix' => 'title_shadow',
    ),
    'bar_inner_typography_typography' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'inner_text!' => '',
      ),
      'group' => 'typography',
      'group_prefix' => 'bar_inner_typography',
    ),
    'bar_inner_shadow_text_shadow' =>
    array (
      'section' => 'section_progress_style',
      'type' => 'group_activator',
      'default' => NULL,
      'responsive' => false,
      'condition' =>
      array (
        'inner_text!' => '',
      ),
      'group' => 'text-shadow',
      'group_prefix' => 'bar_inner_shadow',
    ),
  ),
  'group_activators' =>
  array (
    'typography_typography' => 'custom',
    'title_shadow_text_shadow' => 'yes',
    'bar_inner_typography_typography' => 'custom',
    'bar_inner_shadow_text_shadow' => 'yes',
  ),
  'required_for_render' =>
  array (
    0 => 'percent',
  ),
  'knowledge_sources' =>
  array (
    0 => 'docs/knowledge/elementor/widgets/progress-bar-widget.md',
    1 => 'docs/knowledge/elementor/widgets/progress-tracker.md',
  ),
  'control_count' => 20,
);
