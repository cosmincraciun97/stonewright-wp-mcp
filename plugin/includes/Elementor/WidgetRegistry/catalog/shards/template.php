<?php
/**
 * Generated Elementor widget schema artifact.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// phpcs:ignoreFile -- generated catalog artifact.
declare( strict_types=1 );
return array (
  'slug' => 'template',
  'source' => 'pro',
  'widget_type' => 'template',
  'title' => 'Template',
  'icon' => 'eicon-document-file',
  'categories' =>
  array (
  ),
  'keywords' =>
  array (
    0 => 'elementor',
    1 => 'template',
    2 => 'library',
    3 => 'block',
    4 => 'page',
  ),
  'file' => 'pro-elements/modules/library/widgets/template.php',
  'intent' => 'Create a Template to Speed Up Your Workflow WidgetsPro WidgetsTemplate Get Elementor Pro Template WidgetUse One Template for Multiple Pages Apply Changes Everywhere Use the template widget to apply changes across your entire website, saving you time and effort. Global changes Insert Your Template Anywhere Streamline your workflow by embedding a shortcode to display your template anywhere. Shortcode embedding Embed Elementor Widgets Add any Elementor widget to a Gutenberg page to elevate the design. Gutenberg integration Get Inspired by Smart Templates Explore exceptional websites that were built using templates, and find new ways to speed up your work without sacrificing the design. Learn How to Utilize Templates to Streamline Your Work Master the Template widget and create new sections, galleries, pages, and more in a few clicks, saving you time and effort. HOW IT WORKS Explore Other Widgets Take your website to the next level using Pro’s powerful widgets. Gallery Widget Gallery Posts Widget Posts Login Widget Login Form Widget Form',
  'use_cases' =>
  array (
    0 => 'Global changes Insert Your Template Anywhere Streamline your workflow by embedding a shortcode to display your template anywhere',
    1 => 'Shortcode embedding Embed Elementor Widgets Add any Elementor widget to a Gutenberg page to elevate the design',
    2 => 'HOW IT WORKS Explore Other Widgets Take your website to the next level using Pro’s powerful widgets',
    3 => 'Gallery Widget Gallery Posts Widget Posts Login Widget Login Form Widget Form',
    4 => 'Organizing your layout design and structuring content elements inside Elementor.',
    5 => 'Enhancing user experience by presenting information in a clean, professional, and accessible layout.',
    6 => 'Customizing specific styles, responsiveness, and display logic for elements across devices.',
  ),
  'settings_highlights' =>
  array (
    0 => 'Content options – Configure general content, title, tags, and icons.',
    1 => 'Style settings – Customize colors, borders, background, padding, and typography.',
    2 => 'Advanced features – Apply custom CSS classes, ID, and responsiveness properties.',
    3 => 'Note – Editing and updating the template in the Template Library will cause all uses of that template widget across the site to update as well.',
  ),
  'limits' =>
  array (
    0 => 'Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.',
    1 => 'Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.',
    2 => 'Editing and updating the template in the Template Library will cause all uses of that template widget across the site to update as well.',
  ),
  'sections' =>
  array (
    0 =>
    array (
      'id' => 'section_template',
      'label' => 'Template',
      'tab' => NULL,
      'condition' => NULL,
      'controls' =>
      array (
        0 =>
        array (
          'key' => 'template_id',
          'type' =>
          array (
            '__unresolved__' => 'QueryControlModule::QUERY_CONTROL_ID',
          ),
          'label' => 'Choose Template',
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
  ),
  'repeaters' =>
  array (
  ),
  'settings_index' =>
  array (
    'template_id' =>
    array (
      'section' => 'section_template',
      'type' =>
      array (
        '__unresolved__' => 'QueryControlModule::QUERY_CONTROL_ID',
      ),
      'default' => NULL,
      'responsive' => false,
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
    0 => 'docs/knowledge/elementor/widgets/template-widget.md',
    1 => 'docs/knowledge/elementor/widgets/template-widget.md',
    2 => 'docs/knowledge/elementor/widgets/template-widget-pro.md',
    3 => 'docs/knowledge/elementor/widgets/template-widget-pro.md',
  ),
  'control_count' => 1,
);
